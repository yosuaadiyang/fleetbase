<?php
// Ten synthetic drivers against the real isolated Octane HTTP runtime.
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Fleetbase\FleetOps\Models\{Driver, Order, Place};
use Fleetbase\Models\{User, CompanyUser};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (getenv('CONTRUST_RELEASE_QA') !== 'synthetic-only' || DB::connection()->getDatabaseName() !== 'contrust_release_qa'
    || config('mail.default') !== 'log' || config('broadcasting.default') !== 'log' || config('contrust.mobile_push_enabled')) {
    throw new RuntimeException('Unsafe pilot environment');
}
$state = json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')), true, 512, JSON_THROW_ON_ERROR);
$users = []; $drivers = []; $tokens = []; $createdTokens = []; $orders = []; $active = []; $samples = ['gps' => [], 'read' => [], 'order' => []]; $errors = [];
$run = bin2hex(random_bytes(6)); $duration = 600; $multi = curl_multi_init();
$owner = User::findOrFail($state['users']['owner_a']['uuid']);
$templateUser = User::findOrFail($state['users']['driver_a']['uuid']);
$templateDriver = Driver::where('user_uuid', $templateUser->uuid)->firstOrFail();
$templateMember = CompanyUser::where('user_uuid', $templateUser->uuid)->where('company_uuid', $owner->company_uuid)->firstOrFail();
$place = Place::where('company_uuid', $owner->company_uuid)->firstOrFail();
$ownerToken = $owner->createToken($owner->uuid); $createdTokens[] = $ownerToken->accessToken->id;
function pilotStart(string $kind, string $method, string $path, string $token, ?array $body = null): void {
    global $multi, $active;
    $handle = curl_init('http://127.0.0.1:8000/v1/' . $path);
    curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $token]]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body));
    $active[spl_object_id($handle)] = ['handle' => $handle, 'kind' => $kind, 'started' => microtime(true)];
    curl_multi_add_handle($multi, $handle);
}
function pilotDrain(): void {
    global $multi, $active, $samples, $errors, $orders;
    do { $result = curl_multi_exec($multi, $running); } while ($result === CURLM_CALL_MULTI_PERFORM);
    while ($info = curl_multi_info_read($multi)) {
        $handle = $info['handle']; $entry = $active[spl_object_id($handle)]; $kind = $entry['kind'];
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $body = json_decode(curl_multi_getcontent($handle), true);
        $ok = $info['result'] === CURLE_OK && ($kind === 'order' ? in_array($status, [200, 201], true) : $status === 200) && is_array($body);
        if ($kind === 'order' && $ok) { $id = $body['id'] ?? $body['order']['id'] ?? null; $ok = is_string($id); if ($ok) $orders[] = $id; }
        if (!$ok) $errors[] = ['kind' => $kind, 'http' => $status, 'transport' => $info['result']];
        $samples[$kind][] = round((microtime(true) - $entry['started']) * 1000, 2);
        curl_multi_remove_handle($multi, $handle); curl_close($handle); unset($active[spl_object_id($handle)]);
    }
}
try {
    Model::withoutEvents(function () use (&$users, &$drivers, &$tokens, &$createdTokens, $templateUser, $templateDriver, $templateMember, $run) {
        for ($i = 0; $i < 10; $i++) {
            $user = $templateUser->replicate(['id', 'uuid']);
            $user->forceFill(['uuid' => (string) Str::uuid(), 'public_id' => 'user_pilot_' . $run . '_' . $i,
                'username' => 'pilot_' . $run . '_' . $i, 'slug' => 'pilot-' . $run . '-' . $i,
                'email' => 'pilot-' . $run . '-' . $i . '@contrust.example.test', 'phone' => null, 'name' => 'Synthetic pilot driver ' . $i])->saveQuietly();
            $users[] = $user;
            $driver = $templateDriver->replicate(['id', 'uuid']);
            $driver->forceFill(['uuid' => (string) Str::uuid(), 'public_id' => 'driver_pilot_' . $run . '_' . $i,
                'user_uuid' => $user->uuid, 'online' => true, 'status' => 'available'])->saveQuietly(); $drivers[] = $driver;
            $member = $templateMember->replicate(['id', 'uuid']); $member->forceFill(['uuid' => (string) Str::uuid(), 'user_uuid' => $user->uuid])->saveQuietly();
            $token = $user->createToken($driver->uuid); $tokens[] = $token->plainTextToken; $createdTokens[] = $token->accessToken->id;
        }
    });
    $start = microtime(true); $tick = 0;
    while (microtime(true) - $start < $duration || $active) {
        $elapsed = microtime(true) - $start;
        while ($tick <= min((int) floor($elapsed), $duration - 1)) {
            foreach ($drivers as $i => $driver) {
                if ($tick % 10 === $i) pilotStart('gps', 'POST', 'drivers/' . $driver->public_id . '/track', $tokens[$i], [
                    'latitude' => -6.1754 + (($tick % 60) * 0.000001), 'longitude' => 106.8272, 'heading' => 0, 'speed' => 0]);
                if ($tick % 30 === $i) pilotStart('read', 'GET', 'orders?limit=10', $tokens[$i]);
            }
            if ($tick % 60 === 15) {
                $index = intdiv($tick, 60);
                pilotStart('order', 'POST', 'orders', $ownerToken->plainTextToken, ['internal_id' => 'Pilot-' . $run . '-' . $index,
                    'type' => 'transport', 'order_config' => $state['order_configs']['a'], 'driver' => $drivers[$index]->public_id,
                    'pickup' => $place->public_id, 'dropoff' => $place->public_id, 'dispatch' => false, 'pod_required' => false]);
            }
            $tick++;
        }
        pilotDrain();
        if ($active) curl_multi_select($multi, 0.1); else usleep(100000);
        if (count($errors) >= 5) break;
    }
    $summary = [];
    foreach ($samples as $kind => $values) { sort($values); $count = count($values); $summary[$kind] = ['requests' => $count,
        'p95_ms' => $count ? $values[(int) ceil($count * 0.95) - 1] : null, 'max_ms' => $count ? max($values) : null]; }
    $databaseOrders = $orders ? Order::whereIn('public_id', $orders)->where('company_uuid', $owner->company_uuid)->count() : 0;
    $passed = !$errors && count($users) === 10 && count($orders) === 10 && count(array_unique($orders)) === 10 && $databaseOrders === 10
        && count($samples['gps']) === 600 && count($samples['read']) === 200 && $summary['gps']['p95_ms'] <= 1500
        && $summary['read']['p95_ms'] <= 1000 && $summary['order']['p95_ms'] <= 2000;
    $report = ['environment' => 'isolated-synthetic-octane', 'drivers' => count($users), 'duration_seconds' => round(microtime(true) - $start),
        'samples' => $summary, 'unexpected_errors' => $errors, 'database_order_count' => $databaseOrders, 'passed' => $passed,
        'notifications_sent' => false, 'production_data_used' => false, 'orders_dispatched' => false, 'credentials_printed' => false];
    file_put_contents(storage_path('app/release-qa/pilot-result.json'), json_encode($report, JSON_PRETTY_PRINT));
    echo json_encode($report), PHP_EOL;
} finally {
    foreach ($active as $entry) { curl_multi_remove_handle($multi, $entry['handle']); curl_close($entry['handle']); }
    curl_multi_close($multi);
    // Retain only synthetic records for investigation, with all pilot PATs revoked.
    if ($createdTokens) \Laravel\Sanctum\PersonalAccessToken::whereIn('id', $createdTokens)->delete();
    if ($drivers) Driver::whereIn('uuid', array_map(fn ($driver) => $driver->uuid, $drivers))->update(['online' => false]);
}
exit(($passed ?? false) ? 0 : 1);
