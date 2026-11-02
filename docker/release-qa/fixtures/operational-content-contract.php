<?php
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Support\OperationalReports;
use Fleetbase\Models\{User, CompanyUser, Role, Permission, Directive};
use Fleetbase\FleetOps\Models\{Driver, Order, Proof, Issue};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
if (getenv('CONTRUST_RELEASE_QA') !== 'synthetic-only' || DB::connection()->getDatabaseName() !== 'contrust_release_qa'
    || config('mail.default') !== 'log' || config('broadcasting.default') !== 'log') throw new RuntimeException('Unsafe operational-content QA');
$state = json_decode(file_get_contents(storage_path('app/release-qa/credentials.json')), true, 512, JSON_THROW_ON_ERROR);
$private = storage_path('app/release-qa/operational-fixtures.json'); $checks = []; $tokens = [];
$base = '/int/v1/contrust/content-safety'; $chat = '/int/v1/contrust/chat-safety';
function ocCheck(string $name, bool $ok, ?int $http = null): void {
    global $checks; $checks[] = ['name' => $name, 'passed' => $ok, 'http' => $http];
}
function ocCall(string $method, string $path, ?array $body = null, ?string $actor = 'driver_a'): array {
    global $tokens;
    $headers = ['Accept: application/json', 'Content-Type: application/json', 'Connection: close'];
    if ($actor !== null) $headers[] = 'Authorization: Bearer ' . $tokens[$actor];
    $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 15, 'header' => implode("\r\n", $headers)];
    if ($body !== null) $options['content'] = json_encode($body, JSON_THROW_ON_ERROR);
    $raw = @file_get_contents('http://127.0.0.1:8000' . $path, false, stream_context_create(['http' => $options]));
    if ($raw === false) throw new RuntimeException('Synthetic operational transport unavailable');
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int) ($match[1] ?? 0), json_decode($raw, true), $http_response_header];
}
function ocFinish(): void {
    global $checks;
    $failed = count(array_filter($checks, fn ($r) => !$r['passed']));
    echo json_encode(['environment' => 'isolated-synthetic-mysql', 'checks' => $checks, 'passed' => count($checks)-$failed,
        'failed' => $failed, 'production_used' => false, 'credentials_or_selected_content_printed' => false], JSON_PRETTY_PRINT), PHP_EOL;
    exit($failed ? 1 : 0);
}
if (($argv[1] ?? '') === 'prepare') {
    if (OperationalReports::enabled()) throw new RuntimeException('Preparation requires the feature disabled');
    $fixtures = ['tokens' => [], 'targets' => [], 'orders' => []];
    foreach (['owner_a', 'owner_b', 'viewer_a', 'driver_a', 'driver_a_other', 'driver_b', 'staff_driver_a'] as $actor) {
        $user = User::findOrFail($state['users'][$actor]['uuid']);
        $profile = Driver::where('user_uuid', $user->uuid)->first();
        $token = $user->createToken($profile ? $profile->uuid : $user->uuid);
        $tokens[$actor] = $fixtures['tokens'][$actor] = $token->plainTextToken;
    }
    $tokens['bootstrap'] = $fixtures['tokens']['bootstrap'] = $state['bootstrap']['a'];
    $user = User::findOrFail($state['users']['driver_a']['uuid']);
    $driver = Driver::where('user_uuid', $user->uuid)->firstOrFail();
    $tokens['narrow'] = $fixtures['tokens']['narrow'] = $user->createToken($driver->uuid, ['chat:read', 'chat:write'])->plainTextToken;
    [$code, $body] = ocCall('GET', $base); ocCheck('disabled driver status is minimal', $code === 200 && $body === ['enabled' => false], $code);
    foreach (['owner_a', 'viewer_a'] as $actor) {
        [$code, $body] = ocCall('GET', $base, null, $actor); ocCheck('disabled ' . $actor . ' status preserves normal staff middleware', $code === 200 && $body === ['enabled' => false], $code);
    }
    [$code] = ocCall('GET', $base . '/preview?type=issue&target=issue_missing'); ocCheck('disabled preview reveals no object state', $code === 404, $code);
    foreach (['driver_a', 'driver_a_other', 'driver_b'] as $actor) {
        $profile = Driver::where('user_uuid', $state['users'][$actor]['uuid'])->firstOrFail();
        $order = Order::where('company_uuid', $profile->company_uuid)->where('driver_assigned_uuid', $profile->uuid)->latest()->firstOrFail();
        $fixtures['orders'][$actor] = $order->public_id;
        $payloads = ['issue' => ['type' => 'security', 'category' => 'Synthetic content report', 'report' => 'Synthetic selected issue text', 'priority' => 'low'],
            'fuel_report' => ['odometer' => 1, 'volume' => 1, 'metric_unit' => 'L', 'amount' => 10000, 'currency' => 'IDR', 'status' => 'draft']];
        foreach ($payloads as $type => $payload) {
            $payload['location'] = ['type' => 'Point', 'coordinates' => [106.8272, -6.1754]];
            [$code, $data] = ocCall('POST', '/v1/' . ($type === 'issue' ? 'issues' : 'fuel-reports'), $payload, $actor);
            ocCheck($actor . ' prepares ' . $type, $code === 201 && is_string($data['id'] ?? null), $code);
            if ($code !== 201 || !isset($data['id'])) ocFinish();
            $fixtures['targets'][$actor][$type] = $data['id'];
        }
        [$code, $data] = ocCall('POST', '/v1/comments', ['content' => 'Synthetic selected order comment', 'subject' => ['type' => 'order', 'id' => $order->public_id]], $actor);
        ocCheck($actor . ' prepares comment', $code === 201 && is_string($data['id'] ?? null), $code);
        if ($code !== 201 || !isset($data['id'])) ocFinish();
        $fixtures['targets'][$actor]['order_comment'] = $data['id'];
        $proof = Proof::where('company_uuid', $profile->company_uuid)->where('order_uuid', $order->uuid)->first();
        if (!$proof) {
            $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6KpQAAAAASUVORK5CYII=';
            [$code] = ocCall('POST', '/v1/orders/' . $order->public_id . '/capture-photo', ['photos' => [$png]], $actor);
            $proof = Proof::where('company_uuid', $profile->company_uuid)->where('order_uuid', $order->uuid)->first();
            ocCheck($actor . ' prepares synthetic proof', in_array($code, [200, 201]) && (bool) $proof, $code);
        }
        if (!$proof) ocFinish();
        $fixtures['targets'][$actor]['proof'] = $proof->public_id;
    }
    umask(0077); file_put_contents($private, json_encode($fixtures, JSON_THROW_ON_ERROR)); chmod($private, 0600); ocFinish();
}
if (!OperationalReports::enabled()) throw new RuntimeException('Enabled contract requires private feature flags');
$fixtures = json_decode(file_get_contents($private), true, 512, JSON_THROW_ON_ERROR); $tokens = $fixtures['tokens'];
[$code] = ocCall('GET', $base, null, null); ocCheck('anonymous status denied', $code === 401, $code);
foreach (['bootstrap', 'narrow'] as $actor) { [$code] = ocCall('GET', $base, null, $actor); ocCheck($actor . ' cannot gain operational reads', $code === 403, $code); }
[$code, $data] = ocCall('GET', $base); ocCheck('fresh driver sees exact active policy', $code === 200 && ($data['policy_version'] ?? null) === OperationalReports::POLICY_VERSION, $code);
foreach (['issues' => ['type' => 'security'], 'fuel-reports' => ['status' => 'draft'], 'comments' => ['content' => 'Unaccepted']] as $path => $payload) {
    [$code] = ocCall('POST', '/v1/' . $path, $payload); ocCheck($path . ' contribution requires expanded consent before writing', $code === 428, $code);
}
[$code] = ocCall('POST', '/v1/orders/' . $fixtures['orders']['driver_a'] . '/capture-photo', ['photos' => []]); ocCheck('POD contribution requires expanded consent', $code === 428, $code);
[$code] = ocCall('POST', $chat . '/consent', ['policy_version' => '2026-10-23.1', 'accepted' => true]); ocCheck('prior chat consent cannot authorize new content scope', $code === 422, $code);
[$code] = ocCall('POST', $chat . '/consent', ['policy_version' => OperationalReports::POLICY_VERSION, 'accepted' => true]); ocCheck('synthetic driver can explicitly consent', $code === 200, $code);
$previews = [];
foreach ($fixtures['targets']['driver_a'] as $type => $id) {
    $query = '?type=' . $type . '&target=' . $id;
    [$code, $data, $headers] = ocCall('GET', $base . '/preview' . $query);
    ocCheck($type . ' assigned preview authorized', $code === 200 && is_string($data['preview_receipt'] ?? null), $code);
    if ($code === 200) $previews[$type] = $data;
    $encoded = json_encode($data);
    ocCheck($type . ' preview excludes private operational fields', !preg_match('/"(?:company_uuid|user_uuid|author_uuid|driver_uuid|location|latitude|longitude|amount|odometer|file_url|file_uuid|email|phone)"/', $encoded));
    ocCheck($type . ' preview never uses shared caching', str_contains(strtolower(implode(' ', $headers)), 'no-store'));
    foreach (['driver_a_other', 'driver_b', 'staff_driver_a'] as $actor) { [$code] = ocCall('GET', $base . '/preview' . $query, null, $actor); ocCheck($actor . ' cannot inherit ' . $type . ' access', $code === 403, $code); }
    [$code] = ocCall('GET', $base . '/preview' . $query, null, 'owner_a'); ocCheck('own organization owner can review permitted ' . $type . ' selection', $code === 200, $code);
    [$code] = ocCall('GET', $base . '/preview' . $query, null, 'owner_b'); ocCheck('foreign owner cannot select ' . $type, $code === 403, $code);
}
foreach (['company_uuid', 'staff_targets', 'location'] as $field) { [$code] = ocCall('GET', $base . '/preview?type=issue&target=' . $fixtures['targets']['driver_a']['issue'] . '&' . $field . '=injected'); ocCheck('preview rejects injected ' . $field, $code === 422, $code); }
foreach (['issue' => 'issues', 'fuel_report' => 'fuel-reports', 'order_comment' => 'comments'] as $type => $path) {
    $id = $fixtures['targets']['driver_a'][$type];
    [$original] = ocCall('GET', '/int/v1/' . $path . '/' . $id, null, 'viewer_a');
    [$previewCode] = ocCall('GET', $base . '/preview?type=' . $type . '&target=' . $id, null, 'viewer_a');
    ocCheck('viewer ' . $type . ' preview matches original resource permission', in_array($original, [200, 403, 404]) && $previewCode === ($original === 200 ? 200 : 403), $previewCode);
}
foreach (['issue' => 'issues', 'fuel_report' => 'fuel_reports'] as $type => $table) {
    $id = $fixtures['targets']['driver_a'][$type];
    try {
        DB::table($table)->where('public_id', $id)->update(['reported_by_uuid' => $state['users']['driver_a_other']['uuid']]);
        [$code] = ocCall('GET', $base . '/preview?type=' . $type . '&target=' . $id); ocCheck('assigned but other-authored ' . $type . ' cannot bypass driver report reads', $code === 403, $code);
    } finally { DB::table($table)->where('public_id', $id)->update(['reported_by_uuid' => $state['users']['driver_a']['uuid']]); }
}
$viewerRole = Role::where('name', 'Release QA Viewer')->firstOrFail();
$oldPermissions = $viewerRole->permissions()->get();
$permission = Permission::where('name', 'fleet-ops view issue')->firstOrFail();
$selectedIssue = $fixtures['targets']['driver_a']['issue']; $directive = null;
try {
    $viewerRole->givePermissionTo($permission);
    $directive = Directive::create(['company_uuid' => $state['companies']['a']['uuid'], 'permission_uuid' => $permission->getKey(),
        'subject_type' => Role::class, 'subject_uuid' => $viewerRole->getKey(), 'key' => 'synthetic-own-reporter',
        'rules' => ['where', 'reported_by_uuid', '=', 'self.uuid']]);
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    [$original] = ocCall('GET', '/int/v1/issues/' . $selectedIssue, null, 'viewer_a');
    [$code] = ocCall('GET', $base . '/preview?type=issue&target=' . $selectedIssue, null, 'viewer_a');
    ocCheck('staff self directive denies another author despite view permission', in_array($original, [403, 404]) && $code === 403, $code);
    DB::table('issues')->where('public_id', $selectedIssue)->update(['reported_by_uuid' => $state['users']['viewer_a']['uuid']]);
    [$original] = ocCall('GET', '/int/v1/issues/' . $selectedIssue, null, 'viewer_a');
    [$code] = ocCall('GET', $base . '/preview?type=issue&target=' . $selectedIssue, null, 'viewer_a');
    ocCheck('staff self directive binds the same real actor as the original route', $original === 200 && $code === 200, $code);
} finally {
    DB::table('issues')->where('public_id', $selectedIssue)->update(['reported_by_uuid' => $state['users']['driver_a']['uuid']]);
    if ($directive) $directive->delete();
    $viewerRole->syncPermissions($oldPermissions);
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
}
if (count($previews) !== 4) ocFinish();
$issue = $fixtures['targets']['driver_a']['issue'];
$report = ['type' => 'issue', 'target' => $issue, 'request_id' => (string) Str::uuid(), 'reason' => 'other', 'details' => 'Synthetic selected report',
    'share_context' => true, 'policy_version' => OperationalReports::POLICY_VERSION, 'preview_receipt' => $previews['issue']['preview_receipt']];
[$code] = ocCall('POST', $base . '/reports', array_replace($report, ['share_context' => false])); ocCheck('context sharing requires a literal positive choice', $code === 422, $code);
Issue::withoutCache()->where('public_id', $issue)->firstOrFail()->forceFill(['report' => 'Changed synthetic selected text'])->saveQuietly();
$before = DB::table('contrust_chat_reports')->count();
[$code, $data] = ocCall('POST', $base . '/reports', $report); ocCheck('changed content requires a new explicit preview', $code === 409 && ($data['code'] ?? null) === 'CONTENT_REPORT_CHANGED', $code);
ocCheck('stale preview cannot insert a report', DB::table('contrust_chat_reports')->count() === $before);
[$code, $fresh] = ocCall('GET', $base . '/preview?type=issue&target=' . $issue); ocCheck('changed own issue can be previewed again', $code === 200, $code);
$report['preview_receipt'] = $fresh['preview_receipt'] ?? '';
[$code, $created] = ocCall('POST', $base . '/reports', $report); ocCheck('confirmed selected context persists once', $code === 201 && is_string($created['id'] ?? null), $code);
[$code, $again] = ocCall('POST', $base . '/reports', $report); ocCheck('uncertain retry returns the original report', $code === 200 && ($again['id'] ?? null) === ($created['id'] ?? 'missing'), $code);
ocCheck('retry does not duplicate selected context', DB::table('contrust_chat_reports')->count() === $before+1);
[$code] = ocCall('POST', $base . '/reports', array_replace($report, ['details' => 'Different synthetic detail'])); ocCheck('changed reuse of request ID conflicts', $code === 409, $code);
$member = CompanyUser::where('user_uuid', $state['users']['driver_a']['uuid'])->where('company_uuid', $state['companies']['a']['uuid'])->firstOrFail();
try {
    $member->forceFill(['status' => 'inactive'])->saveQuietly();
    [$code] = ocCall('POST', $base . '/reports', $report); ocCheck('revoked membership cannot replay an old report', $code === 403, $code);
} finally { $member->forceFill(['status' => 'active'])->saveQuietly(); }
ocFinish();
