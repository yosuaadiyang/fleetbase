<?php
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Support\{ProofFileAccess, SocketAccessTickets};
use Fleetbase\FleetOps\Models\{Driver, Order, Proof};
use Fleetbase\Models\{CompanyUser, File, User};
use Illuminate\Support\Facades\{DB, Storage};
use Laravel\Sanctum\PersonalAccessToken;
if (getenv('CONTRUST_RELEASE_QA') !== 'synthetic-only' || DB::connection()->getDatabaseName() !== 'contrust_release_qa' || config('mail.default') !== 'log') throw new RuntimeException('Unsafe proof-file QA environment.');
$state = json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')), true, 512, JSON_THROW_ON_ERROR);
$checks = []; $tokens = []; $tokenIds = [];
function proofCheck($name, $ok) { global $checks; $checks[] = ['name' => $name, 'passed' => (bool) $ok]; }
function proofCall($method, $path, $body = null, $actor = null) {
    global $tokens;
    // Signed capabilities stay in memory and never enter error messages/reports.
    if (str_starts_with($path, 'http')) $path = parse_url($path, PHP_URL_PATH) . '?' . (parse_url($path, PHP_URL_QUERY) ?? '');
    $headers = ['Accept: application/json', 'Content-Type: application/json', 'Connection: close'];
    if ($actor !== null) $headers[] = 'Authorization: Bearer ' . $tokens[$actor];
    $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 15, 'header' => implode("\r\n", $headers)];
    if ($body !== null) $options['content'] = json_encode($body, JSON_THROW_ON_ERROR);
    $raw = @file_get_contents('http://127.0.0.1:8000' . $path, false, stream_context_create(['http' => $options]));
    if ($raw === false) throw new RuntimeException('Proof-file QA transport failed.');
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), json_decode($raw, true), $raw, implode("\n", $http_response_header)];
}
function proofUrl($file, $actor, $expires = null) {
    global $tokens;
    $token = PersonalAccessToken::findToken($tokens[$actor]); $expires ??= now()->timestamp + 300;
    return '/contrust/private-files/' . $file->uuid . '?' . http_build_query(['token_id' => $token->id, 'expires' => $expires, 'signature' => ProofFileAccess::signature($file, $token->id, $expires)]);
}
try {
    foreach (['owner_a', 'owner_b', 'viewer_a', 'driver_a', 'driver_a_other', 'driver_b'] as $name) {
        $user = User::findOrFail($state['users'][$name]['uuid']);
        $driver = Driver::where('user_uuid', $user->uuid)->first();
        $token = $user->createToken(str_starts_with($name, 'driver') ? $driver->uuid : $user->uuid);
        $tokens[$name] = $token->plainTextToken; $tokenIds[] = $token->accessToken->id;
    }
    $driver = Driver::where('public_id', $state['users']['driver_a']['driver_public_id'])->firstOrFail();
    $order = Order::where('company_uuid', $driver->company_uuid)->where('driver_assigned_uuid', $driver->uuid)->whereHas('proofs')->latest()->firstOrFail();
    $proof = Proof::where('order_uuid', $order->uuid)->whereNotNull('file_uuid')->latest()->firstOrFail();
    $file = File::findOrFail($proof->file_uuid);
    proofCheck('captured photo uses private disk', $file->getDisk() === ProofFileAccess::DISK);
    proofCheck('private file exists outside public root', Storage::disk(ProofFileAccess::DISK)->exists($file->path) && !Storage::disk('public')->exists($file->path));
    [$code, , $raw] = proofCall('GET', '/storage/' . $file->path);
    proofCheck('anonymous original raw POD path does not return bytes', in_array($code, [403, 404]) && $raw !== Storage::disk(ProofFileAccess::DISK)->get($file->path));
    foreach (['driver_a', 'viewer_a', 'owner_a'] as $name) {
        [$code, $data] = proofCall('GET', '/v1/orders/' . $order->public_id . '/proofs', null, $name);
        $items = $data['proofs'] ?? $data;
        $urls = is_array($items) ? array_column($items, 'url') : [];
        $url = current(array_filter($urls, fn ($value) => is_string($value) && str_contains($value, '/contrust/private-files/')));
        proofCheck($name . ' authorized proof read mints private image URL', $code === 200 && is_string($url));
        if (!is_string($url)) continue;
        [$imageCode, , $image, $headers] = proofCall('GET', $url);
        proofCheck($name . ' image renders without bearer header', $imageCode === 200 && @getimagesizefromstring($image) !== false);
        proofCheck($name . ' image response is private no-store nosniff', str_contains(strtolower($headers), 'no-store') && str_contains(strtolower($headers), 'nosniff'));
    }
    foreach (['owner_b', 'driver_a_other', 'driver_b'] as $name) {
        [$code] = proofCall('GET', proofUrl($file, $name));
        proofCheck($name . ' even a correctly signed foreign capability is denied', $code === 404);
    }
    [$code] = proofCall('GET', '/contrust/private-files/' . $file->uuid);
    proofCheck('unsigned direct file request denied', $code === 404);
    $url = proofUrl($file, 'driver_a');
    [$code] = proofCall('GET', preg_replace('/signature=[a-f0-9]{64}/', 'signature=' . str_repeat('0', 64), $url));
    proofCheck('tampered capability denied', $code === 404);
    [$code] = proofCall('GET', proofUrl($file, 'driver_a', now()->timestamp - 1));
    proofCheck('expired capability denied', $code === 404);
    [$code] = proofCall('GET', proofUrl($file, 'driver_a', now()->timestamp + 3600));
    proofCheck('overlong capability denied', $code === 404);
    [$code] = proofCall('GET', '/contrust/private-files/' . $file->uuid . '?token_id[]=1&expires[]=1&signature[]=a');
    proofCheck('array capability inputs are controlled denial', $code === 404);
    $oldDriver = $order->driver_assigned_uuid;
    try {
        $order->forceFill(['driver_assigned_uuid' => null])->saveQuietly();
        [$code] = proofCall('GET', $url);
        proofCheck('driver reassignment revokes already minted image URL', $code === 404);
    } finally { $order->forceFill(['driver_assigned_uuid' => $oldDriver])->saveQuietly(); }
    $member = CompanyUser::where('company_uuid', $driver->company_uuid)->where('user_uuid', $state['users']['viewer_a']['uuid'])->firstOrFail();
    $oldStatus = $member->status;
    try {
        $member->forceFill(['status' => 'inactive'])->saveQuietly();
        [$code] = proofCall('GET', proofUrl($file, 'viewer_a'));
        proofCheck('membership removal revokes image capability', $code === 404);
    } finally { $member->forceFill(['status' => $oldStatus])->saveQuietly(); }
    $roles = $member->roles()->pluck('id')->all();
    try {
        $member->roles()->sync([]);
        [$code] = proofCall('GET', proofUrl($file, 'viewer_a'));
        proofCheck('role removal revokes image capability', $code === 404);
    } finally { $member->roles()->sync($roles); }
    $driverToken = PersonalAccessToken::findToken($tokens['driver_a']);
    $expiry = $driverToken->expires_at;
    try {
        $driverToken->expires_at = now()->subMinute(); $driverToken->save();
        [$code] = proofCall('GET', $url);
        proofCheck('token expiry revokes image capability before URL expiry', $code === 404);
    } finally { $driverToken->expires_at = $expiry; $driverToken->save(); }
    $path = $file->path;
    try {
        $ownerUrl = proofUrl($file, 'owner_a');
        $file->forceFill(['path' => $path . '.changed'])->saveQuietly();
        [$code] = proofCall('GET', $ownerUrl);
        proofCheck('storage metadata change invalidates existing signature', $code === 404);
    } finally { $file->forceFill(['path' => $path])->saveQuietly(); }
    // Driver tokens stay excluded from the generic file API, even if a UUID is known.
    [$code] = proofCall('GET', '/v1/files/' . $file->public_id . '/download?disk=contrust_private', null, 'driver_a_other');
    proofCheck('generic file route cannot bypass driver order scope', in_array($code, [403, 404]));
    foreach (['/v1/files/' . $file->public_id . '/download?disk=public', '/int/v1/files/download/' . $file->uuid] as $index => $download) {
        [$code, , $bytes] = proofCall('GET', $download, null, 'owner_a');
        proofCheck('permitted generic download uses stored private disk ' . $index, $code === 200 && @getimagesizefromstring($bytes) !== false);
        [$code] = proofCall('GET', $download, null, 'owner_b');
        proofCheck('generic download rejects foreign organization ' . $index, in_array($code, [403, 404]));
    }
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6KpQAAAAASUVORK5CYII=';
    [$code, $data] = proofCall('POST', '/v1/orders/' . $order->public_id . '/capture-signature', ['signature' => 'data:image/png;base64,' . $png], 'driver_a');
    $item = $data['proof'] ?? $data; $id = $item['id'] ?? null;
    $signatureProof = is_string($id) ? Proof::where('public_id', $id)->first() : null;
    proofCheck('signature capture succeeds on private storage', in_array($code, [200, 201]) && $signatureProof?->file?->getDisk() === ProofFileAccess::DISK);
    [$code, $data] = proofCall('POST', '/v1/orders/' . $order->public_id . '/capture-photo', ['photos' => [$png], 'disk' => 'public'], 'owner_a');
    $item = $data['proof'] ?? $data; $id = $item['id'] ?? null;
    $forcedProof = is_string($id) ? Proof::where('public_id', $id)->first() : null;
    proofCheck('staff disk override cannot publish proof image', in_array($code, [200, 201]) && $forcedProof?->file?->getDisk() === ProofFileAccess::DISK);
    // Metadata URL lookup outside an authenticated request must never fall back to public.
    proofCheck('CLI or unauthenticated serialization emits no private URL', $file->url === null);
    $token = PersonalAccessToken::findToken($tokens['driver_a']); $savedUrl = proofUrl($file, 'driver_a');
    $token->delete();
    [$code] = proofCall('GET', $savedUrl);
    proofCheck('token revocation immediately invalidates image URL', $code === 404);
} finally {
    PersonalAccessToken::whereIn('id', $tokenIds)->delete();
}
$failed = count(array_filter($checks, fn ($check) => !$check['passed']));
echo json_encode(['environment' => 'isolated-synthetic-mysql', 'checks' => $checks, 'passed' => count($checks) - $failed, 'failed' => $failed, 'credentials_printed' => false, 'production_data_used' => false], JSON_PRETTY_PRINT), PHP_EOL;
exit($failed ? 1 : 0);
