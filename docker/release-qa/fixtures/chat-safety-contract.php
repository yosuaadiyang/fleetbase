<?php
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\ChatSafety;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\{ChatChannel, ChatMessage, CompanyUser, User};
use Illuminate\Support\Facades\{DB, Crypt};
use Illuminate\Support\Str;

if (getenv('CONTRUST_RELEASE_QA') !== 'synthetic-only' || DB::connection()->getDatabaseName() !== 'contrust_release_qa'
    || config('mail.default') !== 'log' || config('broadcasting.default') !== 'log') throw new RuntimeException('Unsafe chat safety QA');
$state = json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')), true, 512, JSON_THROW_ON_ERROR);
$checks = []; $tokens = []; $tokenIds = []; $rooms = []; $process = null;
$companyIds = [$state['companies']['a']['uuid'], $state['companies']['b']['uuid']];
$version = ChatSafety::VERSION; $run = bin2hex(random_bytes(6));
function safetyCheck($name, $ok, $http = null) { global $checks; $checks[] = ['name' => $name, 'passed' => (bool) $ok, 'http' => $http]; }
function safetyCall($method, $path, $body = null, $name = 'owner_a', $port = 18001) {
    global $tokens;
    $headers = "Accept: application/json\r\nContent-Type: application/json\r\nConnection: close";
    if ($name !== null) $headers .= "\r\nAuthorization: Bearer " . $tokens[$name];
    $options = ['method' => $method, 'timeout' => 20, 'ignore_errors' => true, 'header' => $headers];
    if ($body !== null) $options['content'] = json_encode($body, JSON_THROW_ON_ERROR);
    $raw = @file_get_contents('http://127.0.0.1:' . $port . '/' . $path, false, stream_context_create(['http' => $options]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int) ($match[1] ?? 0), json_decode($raw ?: '{}', true), $raw ?: '', $http_response_header ?? []];
}
$base = 'int/v1/contrust/chat-safety';
try {
    // A dedicated loopback HTTP process exercises the real middleware/routes
    // with the feature enabled. The main QA server stays disabled for regressions.
    $busy = @stream_socket_client('tcp://127.0.0.1:18001', $errno, $error, 1);
    if ($busy) { fclose($busy); throw new RuntimeException('Synthetic safety port already in use'); }
    putenv('CONTRUST_CHAT_SAFETY_ENABLED=true');
    $process = proc_open(['php', '-S', '127.0.0.1:18001', '-t', 'public', 'public/index.php'],
        [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, '/fleetbase/api');
    putenv('CONTRUST_CHAT_SAFETY_ENABLED');
    if (!is_resource($process)) throw new RuntimeException('Synthetic safety server unavailable');
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        if (@file_get_contents('http://127.0.0.1:18001/health') !== false) { $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('Synthetic safety server not ready');
    foreach (['owner_a', 'owner_b', 'dispatcher_a', 'driver_a', 'driver_a_other'] as $name) {
        $user = User::findOrFail($state['users'][$name]['uuid']);
        $driver = Driver::where('user_uuid', $user->uuid)->first();
        $token = $user->createToken(str_starts_with($name, 'driver') ? $driver->uuid : $user->uuid);
        $tokens[$name] = $token->plainTextToken; $tokenIds[] = $token->accessToken->id;
    }
    $owner = User::findOrFail($state['users']['owner_a']['uuid']);
    foreach (['read_only' => ['chat:read'], 'unrelated' => ['orders:read']] as $name => $abilities) {
        $token = $owner->createToken($owner->uuid, $abilities); $tokens[$name] = $token->plainTextToken; $tokenIds[] = $token->accessToken->id;
    }
    $tokens['bootstrap'] = $state['bootstrap']['a'];
    [$code, $disabled] = safetyCall('GET', $base, null, 'owner_a', 8000);
    safetyCheck('default-disabled server does not advertise active safety controls', $code === 200 && $disabled === ['enabled' => false], $code);
    [$code] = safetyCall('POST', $base . '/blocks', ['user' => $state['users']['driver_a']['public_id']], 'owner_a', 8000);
    safetyCheck('default-disabled server cannot mutate block state', $code === 404 && DB::table('contrust_chat_blocks')->count() === 0, $code);
    [$code] = safetyCall('GET', $base, null, null); safetyCheck('anonymous cannot read safety state', $code === 401, $code);
    [$code] = safetyCall('GET', $base, null, 'bootstrap'); safetyCheck('bootstrap key is not a safety session', $code === 401, $code);
    [$code] = safetyCall('GET', $base, null, 'unrelated'); safetyCheck('unrelated limited PAT cannot read safety state', $code === 403, $code);
    DB::table('personal_access_tokens')->where('id', $tokenIds[4])->update(['expires_at' => now()->subMinute()]);
    [$code] = safetyCall('GET', $base, null, 'driver_a_other'); safetyCheck('expired PAT cannot read safety state', $code === 401, $code);
    DB::table('personal_access_tokens')->where('id', $tokenIds[4])->update(['expires_at' => null]);
    [$code, $status, , $headers] = safetyCall('GET', $base);
    safetyCheck('enabled status advertises current policy and absent consent', $code === 200 && ($status['enabled'] ?? null) === true && ($status['consented'] ?? null) === false && ($status['policy_version'] ?? null) === $version, $code);
    safetyCheck('safety response prohibits shared caching', str_contains(strtolower(implode(' ', $headers)), 'no-store'));
    [$code] = safetyCall('GET', $base . '?user=foreign'); safetyCheck('status rejects identity overrides', $code === 422, $code);
    $rollback = false;
    try {
        ChatSafety::synchronized($companyIds[0], function () use ($state, $companyIds) {
            DB::table('contrust_chat_consents')->insert(['company_uuid' => $companyIds[0], 'user_uuid' => $state['users']['driver_a_other']['uuid'], 'policy_version' => 'rollback-only', 'accepted_at' => now()]);
            throw new RuntimeException('synthetic rollback');
        });
    } catch (RuntimeException $expected) { $rollback = $expected->getMessage() === 'synthetic rollback'; }
    safetyCheck('company-scoped transaction rolls back a failed safety write', $rollback && DB::table('contrust_chat_consents')->where('policy_version', 'rollback-only')->count() === 0);
    [$code, $data] = safetyCall('POST', 'int/v1/chat-channels', ['chatChannel' => ['name' => 'Policy missing', 'participants' => []]]);
    safetyCheck('web contribution requires current consent', $code === 428 && ($data['code'] ?? null) === 'CHAT_POLICY_REQUIRED', $code);
    [$code] = safetyCall('POST', 'v1/chat-channels', ['name' => 'Policy missing', 'participants' => [$owner->public_id]], 'driver_a');
    safetyCheck('driver contribution requires current consent', $code === 428, $code);
    foreach ([['policy_version' => $version, 'accepted' => false], ['policy_version' => 'old', 'accepted' => true], ['policy_version' => $version, 'accepted' => true, 'user' => 'foreign']] as $i => $invalid) {
        [$code] = safetyCall('POST', $base . '/consent', $invalid); safetyCheck('invalid consent ' . $i . ' cannot be recorded', $code === 422, $code);
    }
    [$code] = safetyCall('POST', $base . '/consent', ['policy_version' => $version, 'accepted' => true], 'read_only'); safetyCheck('read-only PAT cannot consent on behalf of user', $code === 403, $code);
    foreach (['owner_a', 'dispatcher_a', 'driver_a'] as $name) {
        [$code] = safetyCall('POST', $base . '/consent', ['policy_version' => $version, 'accepted' => true], $name);
        safetyCheck($name . ' may accept current policy explicitly', $code === 200, $code);
    }
    $accepted = DB::table('contrust_chat_consents')->where('user_uuid', $owner->uuid)->value('accepted_at');
    [$code] = safetyCall('POST', $base . '/consent', ['policy_version' => $version, 'accepted' => true]);
    safetyCheck('repeated consent preserves one record and first accepted time', $code === 200 && DB::table('contrust_chat_consents')->where('user_uuid', $owner->uuid)->count() === 1 && DB::table('contrust_chat_consents')->where('user_uuid', $owner->uuid)->value('accepted_at') === $accepted, $code);
    [$code] = safetyCall('POST', 'int/v1/chat-channels', ['chatChannel' => ['name' => 'Safety direct ' . $run, 'participants' => [$state['users']['driver_a']['public_id']]]]);
    $room = ChatChannel::where('company_uuid', $owner->company_uuid)->where('name', 'Safety direct ' . $run)->first();
    safetyCheck('consented web actor creates real direct room', in_array($code, [200, 201], true) && $room && $room->participants()->count() === 2, $code);
    if (!$room) throw new RuntimeException('Synthetic safety room missing'); $rooms[] = $room->uuid;
    [$code] = safetyCall('POST', 'int/v1/chat-channels', ['chatChannel' => ['name' => 'Safety group ' . $run, 'participants' => [$state['users']['driver_a']['public_id'], $state['users']['dispatcher_a']['public_id']]]]);
    $group = ChatChannel::where('company_uuid', $owner->company_uuid)->where('name', 'Safety group ' . $run)->first();
    if (!$group) throw new RuntimeException('Synthetic safety group missing'); $rooms[] = $group->uuid;
    safetyCheck('group boundary fixture contains three active members', $group->participants()->count() === 3, $code);
    [$code] = safetyCall('POST', 'int/v1/chat-messages', ['chatMessage' => ['chat_channel_uuid' => $room->uuid, 'content' => 'Selected synthetic report message']]);
    $message = ChatMessage::where('chat_channel_uuid', $room->uuid)->firstOrFail();
    safetyCheck('consented actor can send before block', in_array($code, [200, 201], true), $code);
    $report = ['request_id' => (string) Str::uuid(), 'type' => 'message', 'target' => $message->public_id, 'reason' => 'harassment', 'details' => 'Synthetic review context', 'share_context' => true];
    [$code] = safetyCall('POST', $base . '/reports', $report + ['company_uuid' => $companyIds[1]], 'driver_a'); safetyCheck('report cannot override organization', $code === 422, $code);
    $withoutShare = $report; $withoutShare['share_context'] = false;
    [$code] = safetyCall('POST', $base . '/reports', $withoutShare, 'driver_a'); safetyCheck('report requires explicit selected-context sharing', $code === 422, $code);
    [$code] = safetyCall('POST', $base . '/reports', $report, 'driver_a_other'); safetyCheck('same-organization nonparticipant cannot report private message content', $code === 403, $code);
    [$code] = safetyCall('POST', $base . '/reports', $report, 'owner_b'); safetyCheck('foreign actor cannot report private message content', $code === 403, $code);
    [$code, $created] = safetyCall('POST', $base . '/reports', $report, 'driver_a'); $reportId = $created['id'] ?? null;
    safetyCheck('room participant reports selected other-user message', $code === 201 && is_string($reportId), $code);
    $stored = DB::table('contrust_chat_reports')->where('uuid', $reportId)->first();
    safetyCheck('report context encrypted and no plaintext content in row', $stored && !str_contains($stored->encrypted_context, 'Selected synthetic') && !str_contains($stored->encrypted_context, 'Synthetic review'));
    [$code, $duplicate] = safetyCall('POST', $base . '/reports', $report, 'driver_a');
    safetyCheck('identical retry returns same report without duplicate', $code === 200 && ($duplicate['id'] ?? null) === $reportId && DB::table('contrust_chat_reports')->where('request_id', $report['request_id'])->count() === 1, $code);
    $different = $report; $different['reason'] = 'spam';
    [$code] = safetyCall('POST', $base . '/reports', $different, 'driver_a'); safetyCheck('reusing request ID for changed content returns conflict', $code === 409, $code);
    $userReport = ['request_id' => (string) Str::uuid(), 'type' => 'user', 'target' => $state['users']['driver_a_other']['public_id'], 'reason' => 'spam', 'share_context' => true];
    [$code] = safetyCall('POST', $base . '/reports', $userReport); safetyCheck('active own-organization user may be reported without room access', $code === 201, $code);
    $userReport['request_id'] = (string) Str::uuid(); $userReport['target'] = $state['users']['owner_b']['public_id'];
    [$code] = safetyCall('POST', $base . '/reports', $userReport); safetyCheck('user report cannot select foreign member', $code === 403, $code);
    foreach (['driver_a', 'dispatcher_a', 'read_only'] as $name) {
        [$code, , $raw] = safetyCall('GET', $base . '/reports', null, $name);
        safetyCheck($name . ' cannot read private moderation queue', $code === 403 && !str_contains($raw, 'Selected synthetic'), $code);
    }
    [$code, $foreign] = safetyCall('GET', $base . '/reports', null, 'owner_b'); safetyCheck('foreign owner sees only empty own-organization reports', $code === 200 && ($foreign['reports'] ?? null) === [], $code);
    [$code, $queue] = safetyCall('GET', $base . '/reports');
    $selected = collect($queue['reports'] ?? [])->firstWhere('id', $reportId);
    safetyCheck('owner review exposes only selected reported message context', $code === 200 && ($selected['context']['message']['content'] ?? null) === 'Selected synthetic report message', $code);
    safetyCheck('report snapshot excludes surrounding history contact and coordinates', $selected && array_keys($selected['context']) === ['type', 'target', 'message', 'details'] && array_keys($selected['context']['target']) === ['id', 'name']);
    [$code] = safetyCall('GET', $base . '/reports?limit=1000'); safetyCheck('moderation list rejects unbounded paging', $code === 422, $code);
    [$code] = safetyCall('PATCH', $base . '/reports/' . $reportId, ['status' => 'resolved', 'resolution' => 'support_followup'], 'driver_a'); safetyCheck('ordinary actor cannot mark report resolved', $code === 403, $code);
    [$code] = safetyCall('PATCH', $base . '/reports/' . $reportId, ['status' => 'resolved', 'resolution' => 'support_followup'], 'owner_b'); safetyCheck('foreign owner cannot resolve known report ID', $code === 403, $code);
    [$code] = safetyCall('PATCH', $base . '/reports/' . $reportId, ['status' => 'resolved', 'resolution' => 'support_followup']); safetyCheck('own owner records explicit review outcome', $code === 200, $code);
    safetyCheck('review keeps report and records reviewer without deleting content', DB::table('contrust_chat_reports')->where('uuid', $reportId)->where('reviewer_uuid', $owner->uuid)->where('status', 'resolved')->exists() && ChatMessage::where('uuid', $message->uuid)->exists());
    $block = ['user' => $owner->public_id];
    [$code] = safetyCall('POST', $base . '/blocks', $block, 'driver_a'); safetyCheck('actor blocks own-organization member', $code === 200, $code);
    [$code] = safetyCall('POST', $base . '/blocks', $block, 'driver_a'); safetyCheck('repeated block stays one row', $code === 200 && DB::table('contrust_chat_blocks')->where('user_uuid', $state['users']['driver_a']['uuid'])->count() === 1, $code);
    [$code, $status] = safetyCall('GET', $base, null, 'driver_a'); safetyCheck('server read retains own block and explains group boundary', $code === 200 && ($status['blocked_users'][0]['id'] ?? null) === $owner->public_id && ($status['shared_groups_remain_visible'] ?? null) === true, $code);
    [$code, $status] = safetyCall('GET', $base); safetyCheck('target cannot read who blocked them in status', $code === 200 && ($status['blocked_users'] ?? null) === [], $code);
    [$code] = safetyCall('POST', $base . '/blocks', ['user' => $state['users']['driver_a']['public_id']], 'driver_a'); safetyCheck('self block rejected', $code === 403, $code);
    [$code] = safetyCall('POST', $base . '/blocks', ['user' => $state['users']['owner_b']['public_id']], 'driver_a'); safetyCheck('foreign member block rejected', $code === 403, $code);
    [$code] = safetyCall('POST', 'int/v1/chat-messages', ['chatMessage' => ['chat_channel_uuid' => $room->uuid, 'content' => 'Blocked web send']]); safetyCheck('web sender cannot bypass recipient block', $code === 403, $code);
    [$code] = safetyCall('POST', 'v1/chat-channels/' . $room->public_id . '/send-message', ['content' => 'Blocked driver send'], 'driver_a'); safetyCheck('driver sender also cannot message blocked pair', $code === 403, $code);
    safetyCheck('blocked sends do not persist any new direct message', ChatMessage::where('chat_channel_uuid', $room->uuid)->count() === 1);
    [$code] = safetyCall('POST', 'v1/chat-channels', ['name' => 'Blocked new room', 'participants' => [$owner->public_id]], 'driver_a'); safetyCheck('new direct room cannot bypass block', $code === 403, $code);
    [$code] = safetyCall('POST', 'int/v1/chat-channels', ['chatChannel' => ['name' => 'Blocked group invitation', 'participants' => [$state['users']['driver_a']['public_id'], $state['users']['dispatcher_a']['public_id']]]]); safetyCheck('new group invitation cannot bypass block', $code === 403, $code);
    [$code] = safetyCall('POST', 'v1/chat-channels/' . $group->public_id . '/send-message', ['content' => 'Existing operational group boundary'], 'driver_a'); safetyCheck('existing operational group matches disclosed block boundary', $code === 201, $code);
    [$code] = safetyCall('GET', 'v1/chat-channels/' . $room->public_id, null, 'driver_a'); safetyCheck('block preserves previous history access', $code === 200, $code);
    [$code] = safetyCall('DELETE', $base . '/blocks', $block, 'driver_a'); safetyCheck('explicit unblock succeeds', $code === 200, $code);
    [$code] = safetyCall('POST', 'v1/chat-channels/' . $room->public_id . '/send-message', ['content' => 'Allowed after explicit unblock'], 'driver_a'); safetyCheck('direct send works after unblock', $code === 201, $code);
    DB::table('contrust_chat_consents')->where('company_uuid', $owner->company_uuid)->where('user_uuid', $owner->uuid)->update(['policy_version' => 'stale']);
    [$code] = safetyCall('POST', 'int/v1/chat-messages', ['chatMessage' => ['chat_channel_uuid' => $room->uuid, 'content' => 'Stale consent send']]); safetyCheck('policy version change requires consent again', $code === 428, $code);
    [$code] = safetyCall('GET', 'int/v1/chat-channels/' . $room->uuid); safetyCheck('stale consent does not disable reading', $code === 200, $code);
    [$code] = safetyCall('POST', $base . '/blocks', ['user' => $state['users']['driver_a']['public_id']]); safetyCheck('privacy block remains usable without current consent', $code === 200, $code);
    $membership = CompanyUser::where('company_uuid', $owner->company_uuid)->where('user_uuid', $state['users']['driver_a']['uuid'])->firstOrFail();
    try {
        $membership->forceFill(['status' => 'inactive'])->saveQuietly();
        [$code] = safetyCall('GET', $base, null, 'driver_a'); safetyCheck('revoked membership loses safety access despite driver profile', $code === 403, $code);
        [$code] = safetyCall('POST', $base . '/reports', $report, 'driver_a'); safetyCheck('revoked membership cannot retrieve prior idempotent report', $code === 403, $code);
    } finally { $membership->forceFill(['status' => 'active'])->saveQuietly(); }
    DB::table('personal_access_tokens')->where('id', $tokenIds[3])->delete();
    [$code] = safetyCall('GET', $base, null, 'driver_a'); safetyCheck('revoked token cannot read safety state', $code === 401, $code);
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    foreach (['contrust_chat_reports', 'contrust_chat_blocks', 'contrust_chat_consents'] as $table) DB::table($table)->whereIn('company_uuid', $companyIds)->delete();
    foreach ($rooms as $id) {
        $messages = DB::table('chat_messages')->where('chat_channel_uuid', $id)->pluck('uuid');
        DB::table('chat_receipts')->whereIn('chat_message_uuid', $messages)->delete();
        foreach (['chat_attachments', 'chat_logs', 'chat_messages', 'chat_participants'] as $table) DB::table($table)->where('chat_channel_uuid', $id)->delete();
        DB::table('chat_channels')->where('uuid', $id)->delete();
    }
    if ($tokenIds) DB::table('personal_access_tokens')->whereIn('id', $tokenIds)->delete();
}
$failed = count(array_filter($checks, fn ($check) => !$check['passed']));
echo json_encode(['environment' => 'isolated-synthetic-mysql', 'checks' => $checks, 'passed' => count($checks) - $failed, 'failed' => $failed,
    'external_notifications_sent' => false, 'credentials_or_message_content_printed' => false], JSON_PRETTY_PRINT), PHP_EOL;
exit($failed ? 1 : 0);
