<?php
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Fleetbase\Models\{ChatChannel, User, File};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
if (getenv('CONTRUST_RELEASE_QA') !== 'synthetic-only' || DB::connection()->getDatabaseName() !== 'contrust_release_qa' || config('mail.default') !== 'log' || config('broadcasting.default') !== 'log') throw new RuntimeException('Unsafe web history QA');
$seed = json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')), true);
$checks = []; $tokens = []; $tokenIds = []; $rooms = []; $members = []; $users = []; $file = null;
$run = bin2hex(random_bytes(5));
function historyCheck($name, $passed, $http = null) { global $checks; $checks[] = ['name' => $name, 'passed' => (bool) $passed, 'http' => $http]; }
function historyCall($path, $actor = 'owner_a') {
    global $tokens;
    $raw = @file_get_contents('http://127.0.0.1:8000/int/v1/' . $path, false, stream_context_create(['http' => ['timeout' => 25, 'ignore_errors' => true, 'header' => "Accept: application/json\r\nConnection: close\r\nAuthorization: Bearer " . $tokens[$actor]]]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int) ($match[1] ?? 0), json_decode($raw ?: '{}', true)];
}
try {
    foreach (['owner_a', 'owner_b', 'dispatcher_a'] as $name) {
        $user = User::findOrFail($seed['users'][$name]['uuid']); $token = $user->createToken($user->uuid);
        $tokens[$name] = $token->plainTextToken; $tokenIds[] = $token->accessToken->id;
    }
    $owner = User::findOrFail($seed['users']['owner_a']['uuid']);
    foreach ([0, 1] as $i) {
        $room = new ChatChannel(); $room->forceFill(['uuid' => (string) Str::uuid(), 'public_id' => 'chat_webpage_' . $run . $i, 'company_uuid' => $owner->company_uuid, 'created_by_uuid' => $owner->uuid, 'name' => 'Synthetic paging ' . $run . $i])->saveQuietly(); $rooms[] = $room->uuid;
        DB::table('chat_participants')->insert(['uuid' => (string) Str::uuid(), 'public_id' => 'chat_participant_webpage_' . $run . $i, 'company_uuid' => $owner->company_uuid, 'chat_channel_uuid' => $room->uuid, 'user_uuid' => $owner->uuid, 'created_at' => now(), 'updated_at' => now()]);
    }
    $room = ChatChannel::findOrFail($rooms[0]); $sender = $room->participants()->firstOrFail();
    $file = new File(); $file->forceFill(['uuid' => (string) Str::uuid(), 'public_id' => 'file_webpage_' . $run, 'company_uuid' => $owner->company_uuid, 'uploader_uuid' => $owner->uuid, 'disk' => 'local', 'path' => 'synthetic-only/not-an-upload.txt', 'original_filename' => 'synthetic.txt', 'content_type' => 'text/plain'])->saveQuietly();
    $expected = [];
    foreach (['message' => 'chat_messages', 'log' => 'chat_logs', 'attachment' => 'chat_attachments'] as $type => $table) {
        $rows = [];
        for ($i = 0; $i < 83; $i++) {
            $id = (string) Str::uuid(); $expected[] = $type . ':' . $id;
            $row = ['uuid' => $id, 'public_id' => 'chat_' . $type . '_page_' . $run . $i, 'company_uuid' => $owner->company_uuid, 'chat_channel_uuid' => $room->uuid, 'created_at' => $i < 80 ? '2026-10-01 12:00:00' : null, 'updated_at' => '2026-10-01 12:00:00'];
            if ($type === 'message') $row += ['sender_uuid' => $sender->uuid, 'content' => 'Synthetic history'];
            if ($type === 'log') $row += ['initiator_uuid' => $sender->uuid, 'content' => 'Synthetic activity', 'event_type' => 'synthetic_history', 'subjects' => '[]'];
            if ($type === 'attachment') $row += ['sender_uuid' => $sender->uuid, 'file_uuid' => $file->uuid];
            $rows[] = $row;
        }
        DB::table($table)->insert($rows);
    }
    [$code, $initial] = historyCall('chat-channels/' . $room->uuid);
    $initial = $initial['chatChannel'] ?? $initial['chat_channel'] ?? $initial;
    historyCheck('channel initial feed bounded to fifty with continuation', $code === 200 && count($initial['feed'] ?? []) === 50 && ($initial['feed_has_more'] ?? false) && is_string($initial['feed_next_cursor'] ?? null), $code);
    [$code, $data] = historyCall('chat-channels/' . $room->uuid . '/feed?limit=37'); $first = $data;
    historyCheck('combined feed begins with bounded current page', $code === 200 && count($data['feed'] ?? []) === 37 && ($data['feed_has_more'] ?? false), $code);
    // A new socket-era message after page one must not shift the older cursor boundary.
    $live = (string) Str::uuid(); DB::table('chat_messages')->insert(['uuid' => $live, 'public_id' => 'chat_message_live_' . $run, 'company_uuid' => $owner->company_uuid, 'chat_channel_uuid' => $room->uuid, 'sender_uuid' => $sender->uuid, 'content' => 'Synthetic new message', 'created_at' => '2026-10-02 12:00:00', 'updated_at' => '2026-10-02 12:00:00']);
    $seen = []; $cursor = null;
    for ($page = 0; $page < 15; $page++) {
        if ($page > 0) [$code, $data] = historyCall('chat-channels/' . $room->uuid . '/feed?limit=37&cursor=' . rawurlencode($cursor));
        historyCheck('combined page ' . $page . ' has finite successful response', $code === 200 && count($data['feed'] ?? []) <= 37, $code);
        foreach ($data['feed'] ?? [] as $entry) $seen[] = $entry['type'] . ':' . ($entry['data']['uuid'] ?? 'missing');
        $cursor = $data['feed_next_cursor'] ?? null; if (!$cursor) break;
    }
    sort($expected); $actual = $seen; sort($actual);
    historyCheck('all three types and null timestamps traverse without loss or duplication', $actual === $expected && count(array_unique($seen)) === 249);
    historyCheck('pagination terminates with no continuation', $cursor === null && !($data['feed_has_more'] ?? true));
    historyCheck('legacy attachments without message links serialize without500', count(array_filter($seen, fn ($id) => str_starts_with($id, 'attachment:'))) === 83);
    [$code, $fresh] = historyCall('chat-channels/' . $room->uuid . '/feed?limit=1');
    historyCheck('newer message remains available on a fresh page', $code === 200 && ($fresh['feed'][0]['data']['uuid'] ?? null) === $live, $code);
    foreach (['owner_b', 'dispatcher_a'] as $actor) { [$code] = historyCall('chat-channels/' . $room->uuid . '/feed?cursor=' . rawurlencode($first['feed_next_cursor'] ?? ''), $actor); historyCheck($actor . ' cannot page a nonmember room', $code === 403, $code); }
    [$code] = historyCall('chat-channels/' . $rooms[1] . '/feed?cursor=' . rawurlencode($first['feed_next_cursor'] ?? '')); historyCheck('cursor cannot move between rooms', $code === 422, $code);
    foreach (['0', '101', '-1', '1.5', '50&limit[]=5'] as $i => $bad) { [$code] = historyCall('chat-channels/' . $room->uuid . '/feed?limit=' . $bad); historyCheck('invalid bounded limit ' . $i . ' rejected', $code === 422, $code); }
    foreach (['not-valid-json', str_repeat('a', 1025), '%5B%5D&cursor[]=x'] as $i => $bad) { [$code] = historyCall('chat-channels/' . $room->uuid . '/feed?cursor=' . $bad); historyCheck('malformed cursor ' . $i . ' rejected', $code === 422, $code); }
    $participant = DB::table('chat_participants')->where('uuid', $sender->uuid)->first(); DB::table('chat_participants')->where('uuid', $sender->uuid)->update(['user_uuid' => $seed['users']['dispatcher_a']['uuid']]);
    try { [$code] = historyCall('chat-channels/' . $room->uuid . '/feed?cursor=' . rawurlencode($first['feed_next_cursor'] ?? '')); historyCheck('membership revocation blocks next history page immediately', $code === 403, $code); } finally { DB::table('chat_participants')->where('uuid', $sender->uuid)->update(['user_uuid' => $participant->user_uuid]); }
    for ($i = 0; $i < 84; $i++) {
        $id = (string) Str::uuid(); $mid = (string) Str::uuid(); $users[] = $id; $members[] = $mid;
        DB::table('users')->insert(['uuid' => $id, 'public_id' => 'user_webpage_' . $run . sprintf('%03d', $i), 'company_uuid' => $owner->company_uuid, 'name' => 'Paging ' . $run, 'email' => $run . $i . '@contrust.example.test', 'status' => $i === 82 ? 'inactive' : 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_users')->insert(['uuid' => $mid, 'company_uuid' => $owner->company_uuid, 'user_uuid' => $id, 'status' => $i === 83 ? 'inactive' : 'active', 'created_at' => now(), 'updated_at' => now()]);
    }
    $seen = []; $cursor = null; $firstDirectoryCursor = null;
    for ($page = 0; $page < 10; $page++) {
        [$code, $data] = historyCall('chat-channels/available-participants?query=' . rawurlencode('Paging ' . $run) . '&limit=25' . ($cursor ? '&cursor=' . rawurlencode($cursor) : ''));
        historyCheck('organization directory page ' . $page . ' is bounded', $code === 200 && count($data['users'] ?? []) <= 25, $code);
        foreach ($data['users'] ?? [] as $person) $seen[] = $person['uuid'];
        $cursor = $data['meta']['next_cursor'] ?? null; $firstDirectoryCursor ??= $cursor; if (!$cursor) break;
    }
    historyCheck('directory reaches all eighty-two active members with identical names', count($seen) === 82 && count(array_unique($seen)) === 82 && !in_array($users[82], $seen) && !in_array($users[83], $seen));
    [$code] = historyCall('chat-channels/available-participants?query=changed&cursor=' . rawurlencode($firstDirectoryCursor ?? '')); historyCheck('directory cursor tied to search scope', $code === 422, $code);
    [$code] = historyCall('chat-channels/available-participants?query=' . rawurlencode('Paging ' . $run) . '&cursor=' . rawurlencode($firstDirectoryCursor ?? ''), 'owner_b'); historyCheck('directory cursor tied to organization', $code === 422, $code);
    DB::table('chat_participants')->insert(['uuid' => (string) Str::uuid(), 'public_id' => 'chat_participant_reader_' . $run, 'company_uuid' => $owner->company_uuid, 'chat_channel_uuid' => $room->uuid, 'user_uuid' => $seed['users']['dispatcher_a']['uuid'], 'created_at' => now(), 'updated_at' => now()]);
    DB::table('chat_messages')->where('uuid', $live)->update(['sender_uuid' => null]);
    try {
        [$code, $orphaned] = historyCall('chat-channels/' . $room->uuid . '/feed?limit=1', 'dispatcher_a');
        historyCheck('historical message with missing sender serializes as null without500', $code === 200 && array_key_exists('sender', $orphaned['feed'][0]['data'] ?? []) && $orphaned['feed'][0]['data']['sender'] === null, $code);
    } finally { DB::table('chat_messages')->where('uuid', $live)->update(['sender_uuid' => $sender->uuid]); }

} finally {
    foreach ($rooms as $id) { $messageIds = DB::table('chat_messages')->where('chat_channel_uuid', $id)->pluck('uuid'); DB::table('chat_receipts')->whereIn('chat_message_uuid', $messageIds)->delete(); foreach (['chat_attachments', 'chat_messages', 'chat_logs', 'chat_participants'] as $table) DB::table($table)->where('chat_channel_uuid', $id)->delete(); DB::table('chat_channels')->where('uuid', $id)->delete(); }
    if ($file) DB::table('files')->where('uuid', $file->uuid)->delete();
    if ($members) DB::table('company_users')->whereIn('uuid', $members)->delete(); if ($users) DB::table('users')->whereIn('uuid', $users)->delete(); if ($tokenIds) DB::table('personal_access_tokens')->whereIn('id', $tokenIds)->delete();
}
$failed = count(array_filter($checks, fn ($c) => !$c['passed']));
echo json_encode(['environment' => 'isolated-synthetic-mysql', 'checks' => $checks, 'passed' => count($checks) - $failed, 'failed' => $failed, 'production_data_used' => false, 'notifications_sent' => false, 'credentials_printed' => false], JSON_PRETTY_PRINT), PHP_EOL;
exit($failed ? 1 : 0);
