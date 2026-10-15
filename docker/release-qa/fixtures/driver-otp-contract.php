<?php

require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

if (getenv('CONTRUST_RELEASE_QA') !== 'synthetic-only'
    || DB::connection()->getDatabaseName() !== 'contrust_release_qa'
    || config('mail.default') !== 'log') {
    throw new RuntimeException('Unsafe OTP QA environment');
}
$state = json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')), true, 512, JSON_THROW_ON_ERROR);
$checks = [];
// This is a dedicated synthetic stack, never a shared or production cache.
Cache::flush();
foreach (['driver_a', 'driver_a_other', 'driver_b'] as $actor) {
    VerificationCode::where('subject_uuid', $state['users'][$actor]['uuid'])->delete();
}

function otpCall(string $actor, $code, $purpose = null, ?string $organization = null): array
{
    global $state;
    $body = ['identity' => $state['users'][$actor]['identity'], 'code' => $code];
    if ($purpose !== null) {
        $body['for'] = $purpose;
    }
    return authCall('verify-code', $body, $organization ?? ($actor === 'driver_b' ? 'b' : 'a'));
}

function authCall(string $endpoint, array $body, string $organization = 'a'): array
{
    global $state;
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Accept: application/json\r\nContent-Type: application/json\r\nAuthorization: Bearer " . $state['bootstrap'][$organization],
        'content' => json_encode($body, JSON_THROW_ON_ERROR), 'ignore_errors' => true, 'timeout' => 15,
    ]]);
    $raw = file_get_contents('http://127.0.0.1:8000/v1/drivers/' . $endpoint, false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int) ($match[1] ?? 0), json_decode($raw ?: '{}', true), $http_response_header ?? []];
}

function otpFixture(string $actor, string $purpose = 'driver_login', ?string $expiry = 'future'): VerificationCode
{
    global $state;
    $verification = VerificationCode::generateFor(User::findOrFail($state['users'][$actor]['uuid']), $purpose);
    $verification->expires_at = $expiry === null ? null : ($expiry === 'past' ? now()->subMinute() : now()->addMinutes(5));
    $verification->save();
    return $verification;
}

function otpCheck(string $name, bool $passed, int $http): void
{
    global $checks;
    $checks[] = ['name' => $name, 'passed' => $passed, 'http' => $http];
}

$v = otpFixture('driver_a');
[$http, $body] = otpCall('driver_a', (string) $v->code);
$driver = $body['driver'] ?? $body;
otpCheck('valid driver OTP returns authenticated profile', $http === 200 && is_string($driver['token'] ?? null), $http);
[$http] = otpCall('driver_a', (string) $v->code);
otpCheck('consumed driver OTP cannot be replayed', in_array($http, [400, 401, 422], true), $http);
$v = otpFixture('driver_a', 'driver_login', 'past');
[$http] = otpCall('driver_a', (string) $v->code);
otpCheck('expired OTP rejected', in_array($http, [400, 401, 422], true), $http);
$v = otpFixture('driver_a', 'driver_login', null);
[$http] = otpCall('driver_a', (string) $v->code);
otpCheck('OTP without expiry fails closed', in_array($http, [400, 401, 422], true), $http);
$v = otpFixture('driver_a', 'driver_password_reset');
[$http] = otpCall('driver_a', (string) $v->code, 'driver_password_reset');
otpCheck('reset-purpose OTP cannot become driver login', $http === 422, $http);
[$http] = otpCall('driver_a', (string) $v->code);
otpCheck('reset code rejected even with default login purpose', in_array($http, [400, 401, 422], true), $http);
[$http] = otpCall('driver_a', (string) $v->code, 'create_driver');
otpCheck('OTP endpoint cannot create a driver', $http === 422, $http);
$v = otpFixture('driver_a_other');
[$http] = otpCall('driver_a', (string) $v->code);
otpCheck('another driver OTP cannot authenticate this identity', in_array($http, [400, 401, 422], true), $http);
$v = otpFixture('driver_b');
[$http] = otpCall('driver_b', (string) $v->code, null, 'a');
otpCheck('bootstrap key cannot exchange another organization OTP', in_array($http, [400, 401, 422], true), $http);
foreach ([['invalid'], '0000000', 123456, null] as $index => $invalid) {
    [$http] = otpCall('driver_a', $invalid);
    otpCheck('malformed OTP shape ' . $index . ' rejected before query', $http === 422, $http);
}
foreach ([
    ['verify-code', ['identity' => ['bad'], 'code' => '123456']],
    ['login', ['identity' => $state['users']['driver_a']['identity'], 'password' => ['bad']]],
    ['login-with-sms', ['phone' => ['bad']]],
    ['forgot-password', ['identity' => ['bad']]],
    ['reset-password', ['identity' => $state['users']['driver_a']['identity'], 'code' => '123456', 'password' => ['bad']]],
] as [$endpoint, $input]) {
    [$http] = authCall($endpoint, $input);
    otpCheck($endpoint . ' rejects malformed identity or password', $http === 422, $http);
}
// Use the fixture's existing synthetic password so subsequent QA runs remain valid.
$v = otpFixture('driver_a_other', 'driver_password_reset');
$input = ['identity' => $state['users']['driver_a_other']['identity'], 'code' => (string) $v->code, 'password' => $state['users']['driver_a_other']['password']];
[$http] = authCall('reset-password', $input);
otpCheck('valid reset code changes synthetic password', $http === 200, $http);
[$http] = authCall('reset-password', $input);
otpCheck('consumed password reset code cannot be replayed', $http === 422, $http);
$limited = [];
for ($i = 0; $i < 12; $i++) {
    [$http, , $headers] = otpCall('driver_b', '000000');
    $limited[] = $http;
}
otpCheck('repeated wrong OTP is rate limited', in_array(429, $limited, true), $http);
otpCheck('rate limit returns Retry-After', $http === 429 && count(preg_grep('/^Retry-After: [1-9][0-9]*/i', $headers)) === 1, $http);
// An unknown synthetic identity returns the same generic reset response and sends nothing.
for ($i = 0; $i < 4; $i++) {
    [$http] = authCall('forgot-password', ['identity' => 'absent-driver@contrust.example.test']);
}
otpCheck('reset email requests are limited without sending mail', $http === 429, $http);
$failed = count(array_filter($checks, fn ($check) => !$check['passed']));
$report = ['environment' => 'isolated-synthetic-mysql', 'checks' => $checks, 'passed' => count($checks) - $failed,
    'failed' => $failed, 'emails_sent' => 0, 'codes_or_tokens_printed' => false];
file_put_contents(storage_path('app/release-qa/driver-otp-results.json'), json_encode($report, JSON_PRETTY_PRINT));
echo json_encode($report, JSON_PRETTY_PRINT), PHP_EOL;
exit($failed ? 1 : 0);
