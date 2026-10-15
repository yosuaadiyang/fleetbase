<?php
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Fleetbase\FleetOps\Models\Place;
if (getenv('CONTRUST_RELEASE_QA') !== 'synthetic-only' || DB::connection()->getDatabaseName() !== 'contrust_release_qa' || config('mail.default') !== 'log') {
    throw new RuntimeException('Unsafe Place QA environment');
}
$state = json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')), true, 512, JSON_THROW_ON_ERROR);
$checks = []; $token = null;
function placeCall(string $method, string $path, array $body): array {
    global $token;
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    $raw = @file_get_contents('http://127.0.0.1:8000' . $path, false, stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => json_encode($body), 'ignore_errors' => true, 'timeout' => 20,
    ]]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int) ($match[1] ?? 0), json_decode($raw ?: '{}', true)];
}
function placeCheck(string $name, bool $ok, int $http): void {global $checks; $checks[] = ['name' => $name, 'passed' => $ok, 'http' => $http];}
[$http, $body] = placeCall('POST', '/int/v1/auth/login', array_intersect_key($state['users']['owner_a'], array_flip(['identity', 'password'])));
$token = $body['token'] ?? null;
placeCheck('owner authenticates for Place contract', $http === 200 && is_string($token), $http);
$ids = [];
foreach ([
    ['latitude' => -6.1754, 'longitude' => 106.8272],
    ['name' => 'Coordinate-only QA', 'location' => ['type' => 'Point', 'coordinates' => [106.8272, -6.1754]]],
    ['name' => 'Coordinate-only QA', 'location' => ['type' => 'Point', 'coordinates' => [106.8456, -6.2088]]],
] as $i => $input) {
    [$http, $body] = placeCall('POST', '/v1/places', $input);
    $resource = $body['place'] ?? $body;
    $id = $resource['id'] ?? null;
    $place = is_string($id) ? Place::where('public_id', $id)->first() : null;
    $expected = $input['location']['coordinates'] ?? [$input['longitude'], $input['latitude']];
    placeCheck('coordinate-only Place ' . $i . ' persists exact coordinates without geocoder', in_array($http, [200, 201], true) && $place
        && abs($place->location->getLat() - $expected[1]) < 0.000001 && abs($place->location->getLng() - $expected[0]) < 0.000001, $http);
    $ids[] = $id;
}
placeCheck('equal labels at different points do not overwrite previous Place', count(array_unique(array_filter($ids))) === 3, $http);
$first = isset($ids[1]) ? Place::where('public_id', $ids[1])->first() : null;
placeCheck('first coordinate-only Place remains unchanged', $first && abs($first->location->getLng() - 106.8272) < 0.000001, $http);
foreach ([
    'array name' => ['name' => ['bad']],
    'array street' => ['street1' => ['bad']],
    'array address' => ['address' => ['bad']],
    'array city' => ['city' => ['bad']],
    'array owner' => ['owner' => ['bad']],
    'array vendor' => ['vendor' => ['bad']],
    'array customer' => ['customer' => ['bad']],
    'array contact' => ['contact' => ['bad']],
    'malformed coordinate pair' => ['location' => [['bad'], []]],
    'oversized name' => ['name' => str_repeat('a', 256)],
    'invalid latitude type' => ['latitude' => ['bad']],
    'latitude outside globe' => ['latitude' => 91],
    'longitude outside globe' => ['longitude' => 181],
    'invalid GeoJSON longitude' => ['location' => ['type' => 'Point', 'coordinates' => [181, -6.1754]]],
    'invalid GeoJSON latitude' => ['location' => ['type' => 'Point', 'coordinates' => [106.8272, -91]]],
    'malformed GeoJSON' => ['location' => ['type' => 'Point', 'coordinates' => [['bad'], []]]],
    'invalid metadata shape' => ['meta' => 'bad'],
] as $name => $bad) {
    $input = ['name' => 'Invalid synthetic Place', 'latitude' => -6.1754, 'longitude' => 106.8272];
    if (array_key_exists('location', $bad)) unset($input['latitude'], $input['longitude']);
    [$http] = placeCall('POST', '/v1/places', array_replace($input, $bad));
    placeCheck($name . ' receives validation error', $http === 422, $http);
}
[$http] = placeCall('POST', '/v1/places', ['address' => 'Synthetic address with no geocoder provider']);
placeCheck('unavailable forward geocoder returns controlled validation error', $http === 422, $http);
[$http] = placeCall('PUT', '/v1/places/' . ($ids[0] ?? 'missing'), ['latitude' => -91, 'longitude' => 106.8272]);
placeCheck('Place update inherits coordinate validation', $http === 422, $http);
$failed = count(array_filter($checks, fn ($check) => !$check['passed']));
$report = ['environment' => 'isolated-synthetic-mysql', 'checks' => $checks, 'passed' => count($checks) - $failed, 'failed' => $failed,
    'external_egress_enabled' => false, 'production_data_used' => false, 'credentials_printed' => false];
file_put_contents(storage_path('app/release-qa/place-input-results.json'), json_encode($report, JSON_PRETTY_PRINT));
echo json_encode($report, JSON_PRETTY_PRINT), PHP_EOL;
exit($failed ? 1 : 0);
