<?php
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Support\{DriverPushDevices, NotificationChannelPolicy, PrivateFcmChannel, SocketAccessTickets};
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\User;
use Illuminate\Notifications\{Notification, Events\NotificationSending};
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use NotificationChannels\Fcm\FcmChannel;

if (getenv('CONTRUST_RELEASE_QA') !== 'synthetic-only' || DB::connection()->getDatabaseName() !== 'contrust_release_qa'
    || config('mail.default') !== 'log' || config('broadcasting.default') !== 'log') throw new RuntimeException('Unsafe push QA environment');
$state = json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')), true, 512, JSON_THROW_ON_ERROR);
$checks = []; $tokens = []; $ids = []; $users = []; $prefix = 'synthetic-push-' . bin2hex(random_bytes(12));
function pushCheck(string $name, bool $passed): void { global $checks; $checks[] = compact('name', 'passed'); }
function pushCall(string $method, ?array $body = null, ?string $actor = null): array {
    global $tokens;
    $headers = "Accept: application/json\r\nContent-Type: application/json\r\nConnection: close";
    if ($actor !== null) $headers .= "\r\nAuthorization: Bearer " . $tokens[$actor];
    $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 20, 'header' => $headers];
    if ($body !== null) $options['content'] = json_encode($body);
    $raw = @file_get_contents('http://127.0.0.1:8000/int/v1/contrust/push-devices', false, stream_context_create(['http' => $options]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int) ($match[1] ?? 0), json_decode($raw ?: '{}', true), $raw];
}
try {
    foreach (['driver_a', 'driver_a_other', 'driver_b', 'viewer_a'] as $name) {
        $user = User::findOrFail($state['users'][$name]['uuid']); $users[$name] = $user;
        $driver = Driver::where('user_uuid', $user->uuid)->first();
        $token = $user->createToken($driver?->uuid ?? $user->uuid);
        $tokens[$name] = $token->plainTextToken; $ids[$name] = $token->accessToken->id;
    }
    $input = ['token' => $prefix . '-first', 'platform' => 'android', 'app_identifier' => DriverPushDevices::APPS[0]];
    [$code] = pushCall('POST', $input); pushCheck('anonymous device registration denied', $code === 401);
    [$code] = pushCall('POST', $input, 'viewer_a'); pushCheck('staff token is not a driver installation', $code === 401);
    [$code] = pushCall('POST', $input + ['user_uuid' => $users['driver_b']->uuid], 'driver_a'); pushCheck('identity overrides are rejected', $code === 422);
    [$code] = pushCall('POST', array_replace($input, ['installation_id' => ['invalid']]), 'driver_a'); pushCheck('malformed installation gets controlled422', $code === 422);
    [$code, $data, $raw] = pushCall('POST', $input, 'driver_a'); $installation = $data['installation_id'] ?? '';
    pushCheck('authenticated Android registration returns a server installation ID', $code === 200 && Str::isUuid($installation));
    pushCheck('registration response never echoes device token', !str_contains($raw ?: '', $input['token']) && array_keys($data) === ['status', 'installation_id']);
    $row = DB::table(DriverPushDevices::TABLE)->where('token_hash', hash('sha256', $input['token']))->first();
    pushCheck('registration uses server session user and organization', $row && $row->user_uuid === $users['driver_a']->uuid && (int) $row->token_id === $ids['driver_a'] && $row->company_uuid === $users['driver_a']->company_uuid);
    pushCheck('device token is encrypted at rest', $row && !str_contains($row->encrypted_token, $input['token']) && Crypt::decryptString($row->encrypted_token) === $input['token']);
    pushCheck('current bound session is eligible', DriverPushDevices::eligible($users['driver_a']) === [$input['token']]);
    [$code, $data] = pushCall('POST', $input, 'driver_a');
    pushCheck('retry without installation reuses binding without duplication', $code === 200 && ($data['installation_id'] ?? '') === $installation && DB::table(DriverPushDevices::TABLE)->where('token_id', $ids['driver_a'])->count() === 1);
    $rotated = array_replace($input, ['token' => $prefix . '-rotated', 'installation_id' => $installation]);
    [$code] = pushCall('POST', $rotated, 'driver_a');
    pushCheck('FCM rotation replaces old token for one installation', $code === 200 && DriverPushDevices::eligible($users['driver_a']) === [$rotated['token']]);
    pushCheck('rotated token leaves no old token hash', !DB::table(DriverPushDevices::TABLE)->where('token_hash', hash('sha256', $input['token']))->exists());
    [$code] = pushCall('POST', $rotated, 'driver_a_other');
    pushCheck('account switch moves installation to new session', $code === 200 && DriverPushDevices::eligible($users['driver_a_other']) === [$rotated['token']]);
    pushCheck('old account immediately loses push route', DriverPushDevices::eligible($users['driver_a']) === []);
    [$code] = pushCall('POST', $rotated, 'driver_b');
    pushCheck('cross organization login rebinds only to authenticated identity', $code === 200 && DriverPushDevices::eligible($users['driver_b']) === [$rotated['token']] && DriverPushDevices::eligible($users['driver_a_other']) === []);
    $notification = new class extends Notification { public function toFcm($user) { throw new RuntimeException('Private payload must not be built'); } };
    config(['contrust.mobile_push_enabled' => true]);
    pushCheck('current bound user passes policy without building private payload', NotificationChannelPolicy::handle(new NotificationSending($users['driver_b'], $notification, FcmChannel::class)) === null);
    pushCheck('old user fails policy despite legacy devices', NotificationChannelPolicy::handle(new NotificationSending($users['driver_a'], $notification, FcmChannel::class)) === false);
    PersonalAccessToken::where('id', $ids['driver_b'])->update(['expires_at' => now()->subMinute()]);
    pushCheck('expired PAT revokes eligibility immediately', DriverPushDevices::eligible($users['driver_b']) === []);
    [$code] = pushCall('POST', $rotated, 'driver_b'); pushCheck('expired PAT cannot re-register', $code === 401);
    PersonalAccessToken::where('id', $ids['driver_b'])->update(['expires_at' => null]);
    DB::table(DriverPushDevices::TABLE)->where('token_id', $ids['driver_b'])->update(['last_seen_at' => now()->subDays(31)]);
    pushCheck('stale devices stop receiving push after30days', DriverPushDevices::eligible($users['driver_b']) === []);
    DB::table(DriverPushDevices::TABLE)->where('token_id', $ids['driver_b'])->update(['last_seen_at' => now()]);
    $status = $users['driver_b']->status;
    try { $users['driver_b']->forceFill(['status' => 'disabled'])->saveQuietly(); pushCheck('disabled user has no eligible push route', DriverPushDevices::eligible($users['driver_b']) === []); }
    finally { $users['driver_b']->forceFill(['status' => $status])->saveQuietly(); }
    DB::table(DriverPushDevices::TABLE)->where('token_id', $ids['driver_b'])->update(['encrypted_token' => 'corrupted-qa-ciphertext']);
    pushCheck('corrupt ciphertext fails closed without leaking token', DriverPushDevices::eligible($users['driver_b']) === []);
    [$code] = pushCall('POST', $rotated, 'driver_b'); pushCheck('fresh authenticated registration repairs corrupted row', $code === 200 && DriverPushDevices::eligible($users['driver_b']) === [$rotated['token']]);
    foreach (['platform' => 'ios', 'app_identifier' => 'foreign.application', 'token' => "bad\r\nheader"] as $field => $invalid) {
        pushCheck('invalid ' . $field . ' is rejected', !DriverPushDevices::validInput(array_replace($input, [$field => $invalid])));
    }
    $actor = SocketAccessTickets::actor(PersonalAccessToken::find($ids['driver_a']));
    for ($i = 0; $i < 7; $i++) DriverPushDevices::register($actor, array_replace($input, ['token' => $prefix . '-cap-' . $i, 'installation_id' => (string) Str::uuid()]));
    pushCheck('one user has at most5 registered installations', DB::table(DriverPushDevices::TABLE)->where('user_uuid', $users['driver_a']->uuid)->count() === 5);
    pushCheck('eligible token fanout is bounded to5', count(DriverPushDevices::eligible($users['driver_a'])) === 5);
    [$code] = pushCall('DELETE', ['user_uuid' => $users['driver_b']->uuid], 'driver_a'); pushCheck('logout rejects account override', $code === 422);
    [$code] = pushCall('DELETE', null, 'driver_a'); pushCheck('online logout revokes current PAT', $code === 200 && PersonalAccessToken::find($ids['driver_a']) === null);
    pushCheck('logout removes all bindings for the revoked session', !DB::table(DriverPushDevices::TABLE)->where('token_id', $ids['driver_a'])->exists());
    pushCheck('logout never revokes another account session', count(DriverPushDevices::eligible($users['driver_b'])) === 1 && PersonalAccessToken::find($ids['driver_b']) !== null);
    [$code] = pushCall('POST', $input, 'driver_a'); pushCheck('revoked token cannot register again', $code === 401);
    $payload = PrivateFcmChannel::payload();
    $message = Kreait\Firebase\Messaging\CloudMessage::fromArray($payload)->withChangedTarget('token', $rotated['token']);
    pushCheck('installed Firebase SDK accepts generic Android payload', $message instanceof Kreait\Firebase\Messaging\CloudMessage);
    pushCheck('push data has no chat content or work identity', $payload['data'] === ['type' => 'contrust_update'] && array_keys($payload) === ['notification', 'data', 'android']);
    pushCheck('push lifetime and collapse key bound late delivery', $payload['android']['ttl'] === '60s' && $payload['android']['collapse_key'] === 'contrust_update');
    pushCheck('FCM channel binding resolves to privacy preserving transport', $app->getBindings()[FcmChannel::class]['concrete'] instanceof Closure);
} finally {
    if ($ids) { DB::table(DriverPushDevices::TABLE)->whereIn('token_id', array_values($ids))->delete(); PersonalAccessToken::whereIn('id', array_values($ids))->delete(); }
    config(['contrust.mobile_push_enabled' => false]);
}
$failed = count(array_filter($checks, fn ($check) => !$check['passed']));
echo json_encode(['environment' => 'isolated-synthetic-mysql', 'checks' => $checks, 'passed' => count($checks) - $failed, 'failed' => $failed,
    'notifications_sent' => false, 'production_data_used' => false, 'credentials_printed' => false], JSON_PRETTY_PRINT), PHP_EOL;
exit($failed ? 1 : 0);
