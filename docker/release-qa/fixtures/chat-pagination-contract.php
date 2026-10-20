<?php
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Support\ChatPagination;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\{ChatChannel, ChatMessage, CompanyUser, User};
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

if (getenv('CONTRUST_RELEASE_QA') !== 'synthetic-only' || DB::connection()->getDatabaseName() !== 'contrust_release_qa'
    || config('mail.default') !== 'log' || config('broadcasting.default') !== 'log') throw new RuntimeException('Unsafe pagination QA environment');
$state = json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')), true, 512, JSON_THROW_ON_ERROR);
$checks = []; $tokens = []; $tokenIds = []; $rooms = []; $messages = []; $people = []; $memberships = [];
function pageCheck(string $name, bool $ok): void { global $checks; $checks[] = ['name' => $name, 'passed' => $ok]; }
function pageCall(string $method, string $path, ?array $body = null, string $actor = 'driver_a'): array {
    global $tokens;
    $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 30,
        'header' => "Accept: application/json\r\nContent-Type: application/json\r\nConnection: close\r\nAuthorization: Bearer " . $tokens[$actor]];
    if ($body !== null) $options['content'] = json_encode($body);
    $raw = @file_get_contents('http://127.0.0.1:8000/v1/' . $path, false, stream_context_create(['http' => $options]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), json_decode($raw ?: '{}', true)];
}
try {
    foreach (['driver_a', 'driver_b', 'viewer_a'] as $name) {
        $user = User::findOrFail($state['users'][$name]['uuid']);
        $driver = Driver::where('user_uuid', $user->uuid)->first();
        $token = $user->createToken($driver?->uuid ?? $user->uuid);
        $tokens[$name] = $token->plainTextToken; $tokenIds[] = $token->accessToken->id;
    }
    [$code, $record] = pageCall('POST', 'chat-channels', ['chatChannel' => ['name' => 'Pagination QA', 'participants' => [$state['users']['viewer_a']['public_id']]]]);
    pageCheck('Ember resource wrapper creates a scoped room', $code === 201 && is_string($record['id'] ?? null));
    $room = ChatChannel::where('public_id', $record['id'])->firstOrFail(); $rooms[] = $room->uuid;
    [$code] = pageCall('POST', 'chat-channels', ['chatChannel' => ['name' => 'Spoofed', 'participants' => [$state['users']['viewer_a']['public_id']], 'company_uuid' => $state['companies']['b']['uuid']]]);
    pageCheck('wrapped tenant override is rejected', $code === 403);
    foreach ([null, true, 1, 'wrong', [], [['name' => 'wrong']]] as $index => $wrapper) {
        [$code] = pageCall('POST', 'chat-channels', ['chatChannel' => $wrapper]);
        pageCheck('malformed wrapper ' . $index . ' is controlled', in_array($code, [403, 422], true));
    }
    [$code, $sent] = pageCall('POST', 'chat-channels/' . $room->public_id . '/send-message', ['content' => 'Pagination seed']);
    pageCheck('seed message persists', $code === 201 && is_string($sent['id'] ?? null));
    $message = ChatMessage::where('public_id', $sent['id'])->firstOrFail(); $messages[] = $message->uuid;
    DB::table($message->getTable())->where('uuid', $message->uuid)->update(['created_at' => '2026-01-02 12:00:00']);
    $template = $message->fresh()->getAttributes(); unset($template['id']); $batch = []; $expected = [$message->public_id];
    for ($i = 1; $i <= 1004; $i++) {
        $row = $template; $row['uuid'] = (string) Str::uuid(); $row['public_id'] = 'msgqap_' . sprintf('%08d', $i);
        $row['created_at'] = $i <= 3 ? null : '2026-01-02 12:00:00'; $row['updated_at'] = '2026-01-02 12:00:00';
        $batch[] = $row; $messages[] = $row['uuid']; $expected[] = $row['public_id'];
    }
    foreach (array_chunk($batch, 100) as $chunk) DB::table($message->getTable())->insert($chunk);
    [$code, $first] = pageCall('GET', 'chat-channels/' . $room->public_id . '?limit=37');
    pageCheck('history page has exactly37 messages', $code === 200 && count($first['feed'] ?? []) === 37);
    pageCheck('history announces older data with opaque cursor', ($first['history_has_more'] ?? false) && is_string($first['history_next_cursor'] ?? null));
    $new = $template; $new['uuid'] = (string) Str::uuid(); $new['public_id'] = 'msgqap_newer'; $new['created_at'] = '2026-01-03 12:00:00';
    DB::table($message->getTable())->insert($new); $messages[] = $new['uuid'];
    $seen = array_column(array_column($first['feed'], 'data'), 'id'); $cursor = $first['history_next_cursor']; $pages = 1; $latestRetained = true;
    while ($cursor !== null && $pages < 40) {
        [$code, $data] = pageCall('GET', 'chat-channels/' . $room->public_id . '?limit=37&cursor=' . rawurlencode($cursor));
        if ($code !== 200) break;
        $seen = array_merge($seen, array_column(array_column($data['feed'], 'data'), 'id'));
        $latestRetained = $latestRetained && ($data['last_message']['id'] ?? null) === $new['public_id'];
        $cursor = $data['history_next_cursor'] ?? null; $pages++;
    }
    pageCheck('1005 original messages are all reachable', count($seen) === 1005 && !array_diff($expected, $seen));
    pageCheck('same-timestamp pages contain no duplicates', count(array_unique($seen)) === count($seen));
    pageCheck('new insertion does not shift older pages', !in_array($new['public_id'], $seen, true));
    pageCheck('older page retains actual latest message summary', $latestRetained);
    pageCheck('legacy null-timestamp tail remains reachable', !array_diff(array_slice($expected, 1, 3), $seen));
    [$code] = pageCall('GET', 'chat-channels/' . $room->public_id . '?cursor=' . $first['history_next_cursor'], null, 'driver_b');
    pageCheck('history cursor cannot cross tenant boundary', $code === 403);
    $badCursors = ['bad', str_repeat('a', 2049), ChatPagination::cursor('channels', '2026-01-02 12:00:00', 'room_qa'),
        ChatPagination::cursor('messages:' . $room->public_id, "2026-01-02\0 12:00:00", 'message_qa'),
        ChatPagination::cursor('messages:' . $room->public_id, '2026-99-99 12:00:00', 'message_qa')];
    foreach ($badCursors as $index => $bad) {
        [$code] = pageCall('GET', 'chat-channels/' . $room->public_id . '?cursor=' . rawurlencode($bad));
        pageCheck('malformed or wrong-scope cursor ' . $index . ' is422', $code === 422);
    }
    foreach (['0', '-1', '101', '100000000000000000000', 'x', ''] as $index => $limit) {
        [$code] = pageCall('GET', 'chat-channels/' . $room->public_id . '?limit=' . $limit);
        pageCheck('invalid page size ' . $index . ' is422', $code === 422);
    }
    // More rooms than the old fixed100 response, sharing a timestamp.
    $channelTemplate = $room->getAttributes(); unset($channelTemplate['id']);
    $participantTemplate = $room->participants()->where('user_uuid', $state['users']['driver_a']['uuid'])->firstOrFail()->getAttributes();
    unset($participantTemplate['id']);
    $channelRows = []; $participantRows = []; $expectedRooms = [];
    for ($i = 0; $i < 105; $i++) {
        $row = $channelTemplate; $row['uuid'] = (string) Str::uuid(); $row['public_id'] = 'chatqap_' . sprintf('%08d', $i);
        $row['slug'] = 'chat-pagination-' . $i; $row['created_at'] = '2026-01-02 12:00:00';
        $channelRows[] = $row; $rooms[] = $row['uuid']; $expectedRooms[] = $row['public_id'];
        $pivot = $participantTemplate; $pivot['uuid'] = (string) Str::uuid(); $pivot['public_id'] = 'participantqap_' . sprintf('%08d', $i);
        $pivot['chat_channel_uuid'] = $row['uuid']; $participantRows[] = $pivot;
    }
    foreach (array_chunk($channelRows, 100) as $chunk) DB::table('chat_channels')->insert($chunk);
    foreach (array_chunk($participantRows, 100) as $chunk) DB::table('chat_participants')->insert($chunk);
    $channelIds = []; $cursor = null; $loops = 0;
    do {
        [$code, $items] = pageCall('GET', 'chat-channels?limit=17' . ($cursor ? '&cursor=' . rawurlencode($cursor) : ''));
        if ($code !== 200 || !is_array($items)) break;
        $channelIds = array_merge($channelIds, array_column($items, 'id'));
        $cursor = count($items) === 17 ? end($items)['cursor'] : null;
    } while ($cursor !== null && ++$loops < 20);
    pageCheck('channel pages reach105 rooms beyond legacy100 cap', !array_diff($expectedRooms, $channelIds));
    pageCheck('same-timestamp channel pages contain no duplicates', count($channelIds) === count(array_unique($channelIds)));
    [$code, $foreignItems] = pageCall('GET', 'chat-channels?limit=100', null, 'driver_b');
    pageCheck('foreign organization cannot list pagination rooms', $code === 200 && !array_intersect($expectedRooms, array_column($foreignItems, 'id')));

    // Batch synthetic identities, avoiding password generation/network/model side effects.
    $person = User::findOrFail($state['users']['viewer_a']['uuid']);
    $member = CompanyUser::where('user_uuid', $person->uuid)->where('company_uuid', $room->company_uuid)->firstOrFail();
    $userRows = []; $memberRows = [];
    for ($i = 0; $i < 511; $i++) {
        $row = $person->getAttributes(); unset($row['id']); $row['uuid'] = (string) Str::uuid(); $row['public_id'] = 'userqap_' . sprintf('%08d', $i);
        $row['name'] = 'QA Directory ' . sprintf('%04d', $i); $row['email'] = 'qapage' . $i . '@contrust.example.test';
        if (array_key_exists('username', $row)) $row['username'] = 'qapage' . $i;
        if (array_key_exists('slug', $row)) $row['slug'] = 'qapage-' . $i;
        if (array_key_exists('phone', $row)) $row['phone'] = null;
        $userRows[] = $row; $people[] = $row['uuid'];
        $pivot = $member->getAttributes(); unset($pivot['id']); $pivot['uuid'] = (string) Str::uuid(); $pivot['user_uuid'] = $row['uuid'];
        if (array_key_exists('public_id', $pivot)) $pivot['public_id'] = 'memberqap_' . sprintf('%08d', $i);
        $memberRows[] = $pivot; $memberships[] = $pivot['uuid'];
    }
    foreach (array_chunk($userRows, 100) as $chunk) DB::table($person->getTable())->insert($chunk);
    foreach (array_chunk($memberRows, 100) as $chunk) DB::table($member->getTable())->insert($chunk);
    $directory = []; $cursor = null; $loops = 0;
    do {
        [$code, $items] = pageCall('GET', 'chat-channels/available-participants?limit=100' . ($cursor ? '&cursor=' . rawurlencode($cursor) : ''));
        if ($code !== 200 || !is_array($items)) break;
        $directory = array_merge($directory, array_column($items, 'id')); $cursor = count($items) === 100 ? end($items)['cursor'] : null;
    } while ($cursor !== null && ++$loops < 10);
    $expectedPeople = array_column($userRows, 'public_id');
    pageCheck('directory reaches all511 synthetic members beyond legacy500 cap', !array_diff($expectedPeople, $directory));
    pageCheck('directory pages contain no duplicates', count($directory) === count(array_unique($directory)));
    pageCheck('directory never includes foreign member', !in_array($state['users']['driver_b']['public_id'], $directory, true));
} finally {
    // Only UUIDs created above are removed; the production database is unreachable.
    if ($messages) {
        foreach (['chat_receipts', 'chat_attachments'] as $table) if (Schema::hasTable($table) && Schema::hasColumn($table, 'chat_message_uuid')) DB::table($table)->whereIn('chat_message_uuid', $messages)->delete();
        DB::table('chat_messages')->whereIn('uuid', $messages)->delete();
    }
    if ($rooms) {
        foreach (['chat_logs', 'chat_participants'] as $table) if (Schema::hasTable($table) && Schema::hasColumn($table, 'chat_channel_uuid')) DB::table($table)->whereIn('chat_channel_uuid', $rooms)->delete();
        DB::table('chat_channels')->whereIn('uuid', $rooms)->delete();
    }
    if ($memberships) DB::table('company_users')->whereIn('uuid', $memberships)->delete();
    if ($people) DB::table('users')->whereIn('uuid', $people)->delete();
    if ($tokenIds) \Laravel\Sanctum\PersonalAccessToken::whereIn('id', $tokenIds)->delete();
}
$failed = count(array_filter($checks, fn ($check) => !$check['passed']));
echo json_encode(['environment' => 'isolated-synthetic-mysql', 'checks' => $checks, 'passed' => count($checks) - $failed,
    'failed' => $failed, 'production_data_used' => false, 'notifications_sent' => false, 'credentials_printed' => false], JSON_PRETTY_PRINT), PHP_EOL;
exit($failed ? 1 : 0);
