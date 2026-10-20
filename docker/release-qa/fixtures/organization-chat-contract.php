<?php
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Support\{SocketAccessTickets, SocketChannelAccess};
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\{ChatChannel, ChatMessage, ChatParticipant, ChatReceipt, CompanyUser, User};
use Illuminate\Support\Facades\DB;
if (getenv('CONTRUST_RELEASE_QA') !== 'synthetic-only' || DB::connection()->getDatabaseName() !== 'contrust_release_qa'
    || config('mail.default') !== 'log' || config('broadcasting.default') !== 'log') throw new RuntimeException('Unsafe chat QA environment');
$state = json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')), true, 512, JSON_THROW_ON_ERROR);
$checks = []; $tokens = []; $tokenIds = []; $channels = []; $actors = [];
function chatCheck($name, $ok, $code = null) { global $checks; $checks[] = ['name' => $name, 'passed' => (bool) $ok, 'http' => $code]; }
function chatCall($method, $path, $body = null, $actor = null) {
    global $tokens;
    $headers = ['Accept: application/json', 'Content-Type: application/json', 'Connection: close'];
    if ($actor !== null) $headers[] = 'Authorization: Bearer ' . $tokens[$actor];
    $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 20, 'header' => implode("\r\n", $headers)];
    if ($body !== null) $options['content'] = json_encode($body);
    $raw = @file_get_contents('http://127.0.0.1:8000/v1/' . $path, false, stream_context_create(['http' => $options]));
    if ($raw === false) throw new RuntimeException('Synthetic chat transport failed');
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int) ($match[1] ?? 0), json_decode($raw, true), $http_response_header];
}
try {
    foreach (['owner_a', 'viewer_a', 'dispatcher_a', 'driver_a', 'driver_a_other', 'driver_b'] as $name) {
        $user = User::findOrFail($state['users'][$name]['uuid']);
        $driver = Driver::where('user_uuid', $user->uuid)->first();
        $token = $user->createToken(str_starts_with($name, 'driver') ? $driver->uuid : $user->uuid);
        $tokens[$name] = $token->plainTextToken; $tokenIds[] = $token->accessToken->id;
        $actors[$name] = SocketAccessTickets::actor($token->accessToken);
    }
    $tokens['bootstrap'] = $state['bootstrap']['a'];
    [$code] = chatCall('GET', 'chat-channels/available-participants'); chatCheck('anonymous participant directory denied', $code === 401, $code);
    [$code] = chatCall('GET', 'chat-channels/available-participants', null, 'bootstrap'); chatCheck('embedded bootstrap cannot read directory', $code === 403, $code);
    [$code, $people, $headers] = chatCall('GET', 'chat-channels/available-participants', null, 'driver_a');
    chatCheck('driver directory reads active own-organization users', $code === 200 && is_array($people), $code);
    $ids = array_column($people, 'id');
    chatCheck('directory includes organization owner', in_array($state['users']['owner_a']['public_id'], $ids, true));
    chatCheck('directory includes organization staff', in_array($state['users']['viewer_a']['public_id'], $ids, true));
    chatCheck('directory includes other own-organization driver', in_array($state['users']['driver_a_other']['public_id'], $ids, true));
    chatCheck('directory omits foreign user and actor', !in_array($state['users']['driver_b']['public_id'], $ids, true) && !in_array($state['users']['driver_a']['public_id'], $ids, true));
    chatCheck('directory contains no contact or tenant fields', !array_filter($people, fn ($p) => array_diff(array_keys($p), ['id', 'name', 'avatar_url', 'cursor'])));
    chatCheck('directory disables shared caching', str_contains(strtolower(implode(' ', $headers)), 'no-store'));
    $body = ['name' => 'Synthetic Organization Chat', 'participants' => [$state['users']['driver_a']['public_id'], $state['users']['viewer_a']['public_id'], $state['users']['viewer_a']['public_id']]];
    [$code, $channel] = chatCall('POST', 'chat-channels', $body, 'driver_a');
    $id = $channel['id'] ?? null;
    chatCheck('driver creates confirmed room with active member', $code === 201 && is_string($id), $code);
    if (!$id) throw new RuntimeException('Synthetic chat room missing');
    $room = ChatChannel::where('public_id', $id)->firstOrFail(); $channels[] = $room->uuid;
    chatCheck('room and participants persisted with no duplicate self', $room->participants()->count() === 2);
    chatCheck('creator is server-resolved', $room->created_by_uuid === $state['users']['driver_a']['uuid']);
    $self = collect($channel['participants'])->firstWhere('user', $state['users']['driver_a']['public_id']);
    $viewer = collect($channel['participants'])->firstWhere('user', $state['users']['viewer_a']['public_id']);
    chatCheck('participant serializer omits email phone and tenant', !array_intersect(['email', 'phone', 'company_uuid', 'user_uuid'], array_keys($self)));
    [$code, $list] = chatCall('GET', 'chat-channels?sort=-created_at', null, 'viewer_a'); chatCheck('staff member lists shared room', $code === 200 && in_array($id, array_column($list, 'id'), true), $code);
    [$code, $list] = chatCall('GET', 'chat-channels', null, 'driver_a_other'); chatCheck('same-organization nonmember cannot list private room', $code === 200 && !in_array($id, array_column($list, 'id'), true), $code);
    foreach (['driver_a_other', 'driver_b'] as $name) {
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            [$code] = chatCall($method, 'chat-channels/' . $id, $method === 'PUT' ? ['name' => 'Unauthorized rename'] : null, $name);
            chatCheck($name . ' cannot ' . $method . ' room by known ID', $code === 403, $code);
        }
        [$code] = chatCall('GET', 'chat-channels/available-participants?channel=' . $id, null, $name); chatCheck($name . ' cannot use room directory filter', $code === 403, $code);
        [$code] = chatCall('POST', 'chat-channels/' . $id . '/send-message', ['content' => 'not permitted', 'sender' => $self['id']], $name); chatCheck($name . ' cannot send through known sender ID', $code === 403, $code);
    }
    $before = ChatChannel::where('company_uuid', $room->company_uuid)->count();
    [$code] = chatCall('POST', 'chat-channels', ['name' => 'Foreign member', 'participants' => [$state['users']['driver_b']['public_id']]], 'driver_a');
    chatCheck('foreign recipient rejected atomically', $code === 403 && ChatChannel::where('company_uuid', $room->company_uuid)->count() === $before, $code);
    [$code] = chatCall('POST', 'chat-channels', $body + ['company_uuid' => $state['companies']['b']['uuid']], 'driver_a'); chatCheck('client cannot select room tenant', $code === 403, $code);
    [$code] = chatCall('GET', 'chat-channels?company_uuid=' . $state['companies']['b']['uuid'], null, 'driver_a'); chatCheck('list query cannot widen tenant scope', $code === 403, $code);
    [$code] = chatCall('POST', 'chat-channels/' . $id . '/send-message', ['content' => 'spoofed', 'sender' => $viewer['id']], 'driver_a'); chatCheck('member cannot impersonate another room sender', $code === 403, $code);
    [$code] = chatCall('POST', 'chat-channels/' . $id . '/send-message', ['content' => 'unsafe attachment', 'files' => ['file_foreign']], 'driver_a'); chatCheck('uncontracted file attachment rejected before write', $code === 422, $code);
    foreach (['', str_repeat('a', 4001), ['array']] as $n => $invalid) {
        [$code] = chatCall('POST', 'chat-channels/' . $id . '/send-message', ['content' => $invalid], 'driver_a'); chatCheck('invalid message case ' . $n . ' rejected', $code === 422, $code);
    }
    [$code, $message] = chatCall('POST', 'chat-channels/' . $id . '/send-message', ['content' => '<b>Persisted synthetic message</b>', 'sender' => $self['id']], 'driver_a');
    $messageId = $message['id'] ?? null;
    chatCheck('message saved with confirmed ID and plain text', $code === 201 && is_string($messageId) && $message['content'] === 'Persisted synthetic message', $code);
    if (!$messageId) throw new RuntimeException('Synthetic message missing');
    chatCheck('message sender and company derive from authenticated actor', ChatMessage::where('public_id', $messageId)->where('company_uuid', $room->company_uuid)->where('sender_uuid', $room->participants()->where('user_uuid', $state['users']['driver_a']['uuid'])->value('uuid'))->exists());
    [$code, $reloaded] = chatCall('GET', 'chat-channels/' . $id, null, 'viewer_a'); chatCheck('fresh member read retains sent message', $code === 200 && in_array($messageId, array_column(array_column($reloaded['feed'], 'data'), 'id'), true), $code);
    [$code] = chatCall('POST', 'chat-channels/read-message/' . $messageId, ['participant' => $self['id']], 'viewer_a'); chatCheck('reader cannot spoof another receipt participant', $code === 403, $code);
    [$code, $receipt] = chatCall('POST', 'chat-channels/read-message/' . $messageId, ['participant' => $viewer['id']], 'viewer_a'); chatCheck('member can mark message read', $code === 200 && ($receipt['participant'] ?? null) === $viewer['id'], $code);
    [$code, $again] = chatCall('POST', 'chat-channels/read-message/' . $messageId, ['participant' => $viewer['id']], 'viewer_a'); chatCheck('repeated read receipt is idempotent', $code === 200 && ($again['id'] ?? null) === ($receipt['id'] ?? 'NEVER'), $code);
    foreach (['driver_a_other', 'driver_b'] as $name) {
        [$code] = chatCall('POST', 'chat-channels/read-message/' . $messageId, ['participant' => $viewer['id']], $name); chatCheck($name . ' cannot read-receipt foreign message', $code === 403, $code);
        [$code] = chatCall('DELETE', 'chat-channels/delete-message/' . $messageId, null, $name); chatCheck($name . ' cannot delete foreign message', $code === 403, $code);
        [$code] = chatCall('DELETE', 'chat-channels/remove-participant/' . $viewer['id'], null, $name); chatCheck($name . ' cannot remove room participant', $code === 403, $code);
    }
    [$code] = chatCall('DELETE', 'chat-channels/delete-message/' . $messageId, null, 'viewer_a'); chatCheck('member cannot delete another sender message', $code === 403, $code);
    [$code] = chatCall('PUT', 'chat-channels/' . $id, ['name' => 'Member rename'], 'viewer_a'); chatCheck('only creator may rename room', $code === 403, $code);
    [$code] = chatCall('DELETE', 'chat-channels/' . $id, null, 'viewer_a'); chatCheck('only creator may delete room', $code === 403, $code);
    [$code] = chatCall('POST', 'chat-channels/' . $id . '/add-participant', ['user' => $state['users']['driver_a_other']['public_id']], 'viewer_a'); chatCheck('ordinary member cannot invite users', $code === 403, $code);
    [$code] = chatCall('POST', 'chat-channels/' . $id . '/add-participant', ['user' => $state['users']['driver_b']['public_id']], 'driver_a'); chatCheck('creator cannot add foreign organization user', $code === 403, $code);
    [$code, $added] = chatCall('POST', 'chat-channels/' . $id . '/add-participant', ['user' => $state['users']['driver_a_other']['public_id']], 'driver_a'); chatCheck('creator may add own-organization active member', $code === 201 && is_string($added['id'] ?? null), $code);
    [$code, $same] = chatCall('POST', 'chat-channels/' . $id . '/add-participant', ['user' => $state['users']['driver_a_other']['public_id']], 'driver_a'); chatCheck('repeated invitation retains one participant', $code === 201 && ($same['id'] ?? null) === $added['id'] && $room->participants()->count() === 3, $code);
    chatCheck('member chat public-ID socket allowed', SocketChannelAccess::allows($actors['driver_a'], 'chat.' . $id));
    chatCheck('member chat UUID socket allowed', SocketChannelAccess::allows($actors['viewer_a'], 'chat.' . $room->uuid));
    chatCheck('foreign chat socket rejected', !SocketChannelAccess::allows($actors['driver_b'], 'chat.' . $id));
    chatCheck('nonmember chat socket rejected', !SocketChannelAccess::allows($actors['dispatcher_a'], 'chat.' . $id));
    $membership = CompanyUser::where('company_uuid', $room->company_uuid)->where('user_uuid', $state['users']['driver_a_other']['uuid'])->firstOrFail();
    try {
        $membership->status = 'inactive'; $membership->saveQuietly();
        [$code] = chatCall('GET', 'chat-channels/' . $id, null, 'driver_a_other'); chatCheck('inactive membership revokes room access despite driver profile', $code === 403, $code);
        chatCheck('inactive membership revokes existing driver socket actor', !SocketChannelAccess::allows($actors['driver_a_other'], 'chat.' . $id));
        [$code, $people] = chatCall('GET', 'chat-channels/available-participants', null, 'driver_a'); chatCheck('inactive member disappears from recipient directory', $code === 200 && !in_array($state['users']['driver_a_other']['public_id'], array_column($people, 'id'), true), $code);
    } finally { $membership->status = 'active'; $membership->saveQuietly(); }
    $inactive = User::findOrFail($state['users']['viewer_a']['uuid']);
    try {
        $inactive->status = 'inactive'; $inactive->saveQuietly();
        [$code] = chatCall('GET', 'chat-channels/' . $id, null, 'viewer_a'); chatCheck('inactive user cannot read retained room membership', $code === 403, $code);
    } finally { $inactive->status = 'active'; $inactive->saveQuietly(); }
    [$code] = chatCall('DELETE', 'chat-channels/remove-participant/' . $added['id'], null, 'driver_a_other'); chatCheck('member may leave own room', $code === 200, $code);
    [$code] = chatCall('GET', 'chat-channels/' . $id, null, 'driver_a_other'); chatCheck('leaving room immediately revokes API access', $code === 403, $code);
    chatCheck('leaving room immediately revokes outbound socket access', !SocketChannelAccess::allows($actors['driver_a_other'], 'chat.' . $id));
    [$code] = chatCall('DELETE', 'chat-channels/delete-message/' . $messageId, null, 'driver_a'); chatCheck('sender may delete own message', $code === 200, $code);
    [$code] = chatCall('PUT', 'chat-channels/' . $id, ['name' => 'Synthetic renamed'], 'driver_a'); chatCheck('creator may rename own room', $code === 200, $code);
    [$code] = chatCall('DELETE', 'chat-channels/' . $id, null, 'driver_a'); chatCheck('creator may delete own room', $code === 200, $code);
    [$code] = chatCall('GET', 'chat-channels/' . $id, null, 'viewer_a'); chatCheck('deleted room cannot be read by former member', $code === 403, $code);
    chatCheck('deleted room socket rejected immediately', !SocketChannelAccess::allows($actors['viewer_a'], 'chat.' . $id));
} finally {
    foreach ($channels as $uuid) {
        $messageUuids = DB::table('chat_messages')->where('chat_channel_uuid', $uuid)->pluck('uuid');
        DB::table('chat_receipts')->whereIn('chat_message_uuid', $messageUuids)->delete();
        foreach (['chat_attachments', 'chat_logs', 'chat_messages', 'chat_participants'] as $table) DB::table($table)->where('chat_channel_uuid', $uuid)->delete();
        DB::table('chat_channels')->where('uuid', $uuid)->delete();
    }
    if ($tokenIds) DB::table('personal_access_tokens')->whereIn('id', $tokenIds)->delete();
}
$failed = count(array_filter($checks, fn ($c) => !$c['passed']));
echo json_encode(['environment' => 'isolated-synthetic-mysql', 'checks' => $checks, 'passed' => count($checks) - $failed, 'failed' => $failed,
    'credentials_or_message_content_printed' => false, 'external_notifications_sent' => false], JSON_PRETTY_PRINT), PHP_EOL;
exit($failed ? 1 : 0);
