<?php

declare(strict_types=1);

require getenv('CONTRUST_OPERATIONAL_CONTEXT_SOURCE') ?: dirname(__DIR__, 3) . '/api/app/Support/OperationalReportContext.php';

use App\Support\OperationalReportContext;

$checks = 0;
function operationalCheck(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}
function testUuid(int $number): string { return sprintf('%08d-1111-4111-8111-111111111111', $number); }
$db = $GLOBALS['operational_test_pdo'] ?? new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$engine = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($engine !== 'sqlite' && ($engine !== 'mysql'
    || preg_match('/\Acontrust_content_context_qa_[a-f0-9]{12}\z/D', (string) $db->query('SELECT DATABASE()')->fetchColumn()) !== 1)) {
    throw new RuntimeException('Only a newly created synthetic context QA database is allowed.');
}
function operationalQueryOnly(PDO $db, bool $enabled): void
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $db->exec('PRAGMA query_only = ' . ($enabled ? 'ON' : 'OFF'));
    } else {
        if ($db->inTransaction()) $db->commit();
        $db->exec('SET SESSION TRANSACTION READ ' . ($enabled ? 'ONLY' : 'WRITE'));
        if ($enabled) $db->beginTransaction();
    }
}
foreach ([
    'users' => 'uuid TEXT, company_uuid TEXT, status TEXT, deleted_at TEXT',
    'company_users' => 'uuid TEXT, user_uuid TEXT, company_uuid TEXT, status TEXT, deleted_at TEXT',
    'drivers' => 'uuid TEXT, user_uuid TEXT, company_uuid TEXT, deleted_at TEXT',
    'orders' => 'uuid TEXT, public_id TEXT, company_uuid TEXT, driver_assigned_uuid TEXT, deleted_at TEXT',
    'comments' => 'uuid TEXT, public_id TEXT, company_uuid TEXT, subject_uuid TEXT, subject_type TEXT, author_uuid TEXT, content TEXT, deleted_at TEXT',
    'issues' => 'uuid TEXT, public_id TEXT, company_uuid TEXT, driver_uuid TEXT, reported_by_uuid TEXT, report TEXT, location TEXT, deleted_at TEXT',
    'fuel_reports' => 'uuid TEXT, public_id TEXT, company_uuid TEXT, driver_uuid TEXT, reported_by_uuid TEXT, amount REAL, location TEXT, deleted_at TEXT',
    // Real POD records need not have a deleted_at column. Their parent order
    // still has to be live. No current assignee is treated as proof uploader.
    'proofs' => 'uuid TEXT, public_id TEXT, company_uuid TEXT, order_uuid TEXT, file_uuid TEXT, data TEXT',
] as $table => $columns) $db->exec('CREATE TABLE ' . $table . '(' . $columns . ')');
function insertOperational(PDO $db, string $table, array $row): void
{
    $db->prepare('INSERT INTO ' . $table . '(' . implode(',', array_keys($row)) . ') VALUES ('
        . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
}
$company = testUuid(1); $foreign = testUuid(2); $user = testUuid(3); $other = testUuid(4); $outsider = testUuid(5);
$driver = testUuid(6); $otherDriver = testUuid(7); $foreignDriver = testUuid(8);
foreach ([[$user, $company, $driver], [$other, $company, $otherDriver], [$outsider, $foreign, $foreignDriver]] as $index => [$u, $c, $d]) {
    insertOperational($db, 'users', ['uuid' => $u, 'company_uuid' => $c, 'status' => 'active']);
    insertOperational($db, 'company_users', ['uuid' => testUuid(100 + $index), 'user_uuid' => $u, 'company_uuid' => $c, 'status' => 'active']);
    insertOperational($db, 'drivers', ['uuid' => $d, 'user_uuid' => $u, 'company_uuid' => $c]);
}
$orderType = 'Fleetbase\\FleetOps\\Models\\Order';
foreach ([['own', $company, $driver, $other], ['other', $company, $otherDriver, $user], ['foreign', $foreign, $foreignDriver, $outsider]] as $index => [$suffix, $c, $d, $author]) {
    $order = testUuid(10 + $index);
    insertOperational($db, 'orders', ['uuid' => $order, 'public_id' => 'order_' . $suffix, 'company_uuid' => $c, 'driver_assigned_uuid' => $d]);
    insertOperational($db, 'comments', ['uuid' => testUuid(20 + $index), 'public_id' => 'comment_' . $suffix, 'company_uuid' => $c,
        'subject_uuid' => $order, 'subject_type' => $orderType, 'author_uuid' => $author, 'content' => 'Selected synthetic comment']);
    insertOperational($db, 'issues', ['uuid' => testUuid(30 + $index), 'public_id' => 'issue_' . $suffix, 'company_uuid' => $c,
        'driver_uuid' => $d, 'reported_by_uuid' => [$user, $other, $outsider][$index], 'report' => 'Selected synthetic issue', 'location' => 'DO NOT COPY LOCATION']);
    insertOperational($db, 'fuel_reports', ['uuid' => testUuid(40 + $index), 'public_id' => 'fuel_' . $suffix, 'company_uuid' => $c,
        'driver_uuid' => $d, 'reported_by_uuid' => [$user, $other, $outsider][$index], 'amount' => 123456789, 'location' => 'DO NOT COPY LOCATION']);
    insertOperational($db, 'proofs', ['uuid' => testUuid(50 + $index), 'public_id' => 'proof_' . $suffix, 'company_uuid' => $c,
        'order_uuid' => $order, 'file_uuid' => testUuid(60 + $index), 'data' => '{"url":"DO NOT COPY FILE CAPABILITY"}']);
}
$scope = ['company_uuid' => $company, 'user_uuid' => $user, 'driver_uuid' => $driver, 'staff_targets' => []];
$resolver = new OperationalReportContext($db);
operationalQueryOnly($db, true);
foreach (['order_comment' => 'comment', 'issue' => 'issue', 'fuel_report' => 'fuel', 'proof' => 'proof'] as $type => $prefix) {
    $row = $resolver->resolve($scope, $type, $prefix . '_own');
    operationalCheck(($row['id'] ?? null) === $prefix . '_own', $type . ': own assigned record available');
    $json = json_encode($row, JSON_THROW_ON_ERROR);
    operationalCheck(!str_contains($json, 'DO NOT COPY') && !str_contains($json, '123456789') && !str_contains($json, 'file_uuid'), $type . ': unrelated sensitive fields excluded');
    operationalCheck($resolver->resolve($scope, $type, $prefix . '_other') === null, $type . ': same-company other driver denied');
    operationalCheck($resolver->resolve($scope, $type, $prefix . '_foreign') === null, $type . ': foreign company denied');
    operationalCheck($resolver->resolve($scope, $type, $prefix . '_missing') === null, $type . ': nonexistent indistinguishable from denied');
    operationalCheck($resolver->resolve(array_replace($scope, ['staff_targets' => [$type => [$prefix . '_other']]]), $type, $prefix . '_other') === null, $type . ': staff-linked driver cannot inherit staff scope');
    $staff = array_replace($scope, ['driver_uuid' => null, 'staff_targets' => [$type => [$prefix . '_other']]]);
    operationalCheck($resolver->resolve($staff, $type, $prefix . '_other') !== null, $type . ': exact pre-authorized staff target is available within organization');
    operationalCheck($resolver->resolve($staff, $type, $prefix . '_foreign') === null, $type . ': pre-authorized staff target never crosses organizations');
    operationalCheck($resolver->resolve($staff, $type, $prefix . '_own') === null, $type . ': permission for one record does not authorize another record');
    operationalCheck($resolver->resolve(array_replace($staff, ['staff_targets' => []]), $type, $prefix . '_own') === null, $type . ': unverified staff target denied');
}
operationalCheck($resolver->resolve($scope, 'proof', 'proof_own')['author_uuid'] === null, 'POD subject or driver assignment is never guessed to be uploader');
operationalCheck($resolver->resolve($scope, 'order_comment', 'comment_own')['selected_text'] === 'Selected synthetic comment', 'only chosen comment text included');
foreach (['', '../comment_own', "comment_own\n", "comment_own' OR 1=1--", str_repeat('x', 151)] as $id) {
    operationalCheck($resolver->resolve($scope, 'order_comment', $id) === null, 'unsafe selectors rejected');
}
foreach ([['company_uuid' => $foreign], ['user_uuid' => $other], ['driver_uuid' => $otherDriver], ['driver_uuid' => testUuid(999)], ['user_uuid' => 'invalid'], ['staff_targets' => ['all' => ['comment_own']]]] as $change) {
    operationalCheck($resolver->resolve(array_replace($scope, $change), 'order_comment', 'comment_own') === null, 'unmatched actor/assignment/scope denied');
}
foreach ([null, 'all', [true], [[]], [new stdClass()], ['order_comment' => ['*']], ['order_comment' => array_fill(0, 101, 'comment_own')]] as $reads) {
    operationalCheck($resolver->resolve(array_replace($scope, ['staff_targets' => $reads]), 'order_comment', 'comment_own') === null, 'malformed staff permission cannot be coerced');
}
operationalQueryOnly($db, false);
foreach (['issue' => 'issues', 'fuel_report' => 'fuel_reports'] as $type => $table) {
    $id = $type === 'issue' ? 'issue_own' : 'fuel_own';
    $db->prepare('UPDATE ' . $table . ' SET reported_by_uuid = ? WHERE public_id = ?')->execute([$other, $id]);
    operationalQueryOnly($db, true);
    operationalCheck($resolver->resolve($scope, $type, $id) === null, $type . ': dispatch-assigned report is not a driver-owned report');
    operationalQueryOnly($db, false);
    $db->prepare('UPDATE ' . $table . ' SET reported_by_uuid = ? WHERE public_id = ?')->execute([$user, $id]);
}
$db->prepare('UPDATE comments SET content = ? WHERE public_id = ?')->execute([str_repeat('界', 4001), 'comment_own']);
operationalQueryOnly($db, true);
operationalCheck(mb_strlen($resolver->resolve($scope, 'order_comment', 'comment_own')['selected_text']) === 4000, 'selected snapshot is bounded by Unicode characters');
operationalQueryOnly($db, false);
$db->exec("UPDATE comments SET content = 'foreign hidden content', author_uuid = '" . $outsider . "' WHERE public_id = 'comment_own'");
operationalQueryOnly($db, true);
operationalCheck($resolver->resolve($scope, 'order_comment', 'comment_own')['author_uuid'] === null, 'legacy foreign author not expanded');
foreach ([['company_users', "user_uuid = '" . $user . "'", "status = 'inactive'", "status = 'active'"],
    ['users', "uuid = '" . $user . "'", "deleted_at = 'removed'", 'deleted_at = NULL'],
    ['drivers', "uuid = '" . $driver . "'", "deleted_at = 'removed'", 'deleted_at = NULL'],
    ['orders', "public_id = 'order_own'", "deleted_at = 'removed'", 'deleted_at = NULL'],
    ['comments', "public_id = 'comment_own'", "deleted_at = 'removed'", 'deleted_at = NULL']] as [$table, $where, $change, $restore]) {
    operationalQueryOnly($db, false); $db->exec('UPDATE ' . $table . ' SET ' . $change . ' WHERE ' . $where); operationalQueryOnly($db, true);
    operationalCheck($resolver->resolve($scope, 'order_comment', 'comment_own') === null, 'revoked/deleted relationship fails closed: ' . $table);
    operationalQueryOnly($db, false); $db->exec('UPDATE ' . $table . ' SET ' . $restore . ' WHERE ' . $where); operationalQueryOnly($db, true);
}
operationalQueryOnly($db, false);
echo json_encode(['checks' => $checks, 'passed' => $checks, 'failed' => 0, 'read_only_query_only_enforced' => true, 'synthetic_database_engine' => $engine, 'production_used' => false]), PHP_EOL;
