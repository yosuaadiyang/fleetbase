<?php

declare(strict_types=1);

require dirname(__DIR__) . '/AccountDataInventory.php';

use Contrust\Privacy\AccountDataInventory;

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $label);
    }
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$user = '11111111-1111-4111-8111-111111111111';
$other = '22222222-2222-4222-8222-222222222222';
foreach ([
    'users' => 'uuid TEXT PRIMARY KEY, email TEXT',
    'company_users' => 'uuid TEXT, user_uuid TEXT',
    'drivers' => 'uuid TEXT, user_uuid TEXT',
    'contacts' => 'uuid TEXT, user_uuid TEXT',
    'personal_access_tokens' => 'tokenable_id TEXT, token TEXT',
    'user_devices' => 'user_uuid TEXT, token TEXT',
    'positions' => 'subject_uuid TEXT, coordinates TEXT',
    'orders' => 'uuid TEXT, driver_assigned_uuid TEXT, customer_uuid TEXT, created_by_uuid TEXT',
    'issues' => 'uuid TEXT, driver_uuid TEXT, reported_by_uuid TEXT',
    'fuel_reports' => 'uuid TEXT, driver_uuid TEXT, reported_by_uuid TEXT',
    'proofs' => 'uuid TEXT, order_uuid TEXT',
    'chat_participants' => 'uuid TEXT, user_uuid TEXT',
    'chat_messages' => 'uuid TEXT, sender_uuid TEXT, content TEXT',
    'chat_receipts' => 'participant_uuid TEXT',
    'chat_attachments' => 'chat_message_uuid TEXT',
    'comments' => 'author_uuid TEXT, subject_uuid TEXT, content TEXT',
    'files' => 'uploader_uuid TEXT, subject_uuid TEXT, path TEXT',
    'activity_log' => 'causer_id TEXT, subject_id TEXT',
] as $table => $definition) {
    $db->exec('CREATE TABLE ' . $table . ' (' . $definition . ')');
}
function insert(PDO $db, string $table, array $values): void
{
    $query = $db->prepare('INSERT INTO ' . $table . ' VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
    $query->execute($values);
}
insert($db, 'users', [$user, 'synthetic-private@example.invalid']);
insert($db, 'users', [$other, 'other@example.invalid']);
insert($db, 'company_users', ['membership-1', $user]);
insert($db, 'drivers', ['driver-1', $user]);
insert($db, 'drivers', ['driver-2', $other]);
insert($db, 'personal_access_tokens', [$user, 'synthetic-secret-token']);
insert($db, 'user_devices', [$user, 'synthetic-push-token']);
insert($db, 'positions', ['driver-1', 'synthetic-private-location']);
insert($db, 'positions', ['driver-2', 'other-private-location']);
insert($db, 'orders', ['order-1', 'driver-1', null, $user]);
insert($db, 'orders', ['order-2', 'driver-2', null, $other]);
insert($db, 'issues', ['issue-1', 'driver-1', $user]);
insert($db, 'fuel_reports', ['fuel-1', 'driver-1', $user]);
insert($db, 'proofs', ['proof-1', 'order-1']);
insert($db, 'chat_participants', ['participant-1', $user]);
insert($db, 'chat_participants', ['participant-2', $other]);
insert($db, 'chat_messages', ['message-1', 'participant-1', 'synthetic-private-chat']);
insert($db, 'chat_messages', ['message-2', 'participant-2', 'other-private-chat']);
insert($db, 'chat_attachments', ['message-1']);
insert($db, 'chat_receipts', ['participant-1']);
insert($db, 'comments', ['membership-1', 'order-1', 'synthetic-private-comment']);
insert($db, 'files', [$user, 'proof-1', 'synthetic/private/path.jpg']);
insert($db, 'files', [$other, 'issue-1', 'shared/private/path.jpg']);
insert($db, 'files', [$other, 'other-subject', 'unrelated/private/path.jpg']);
insert($db, 'activity_log', [$user, 'order-1']);
$changes = (int) $db->query('SELECT total_changes()')->fetchColumn();
$db->exec('PRAGMA query_only = ON');
$result = (new AccountDataInventory($db))->summarize($user);
check((int) $db->query('SELECT total_changes()')->fetchColumn() === $changes, 'no database mutation');
check($result['execution_supported'] === false && $result['complete_erasure_verified'] === false, 'never authorizes deletion or completion');
foreach (['users', 'drivers', 'positions', 'orders', 'issues', 'fuel_reports', 'proofs', 'personal_access_tokens', 'user_devices', 'chat_messages', 'chat_receipts', 'chat_attachments', 'comments', 'activity_log'] as $table) {
    check($result['tables'][$table]['candidate_rows'] === 1, 'scoped relationship count for ' . $table);
}
check($result['tables']['files']['candidate_rows'] === 2, 'finds attached shared file without equating relation with ownership');
check(count($result['required_reviews']) >= 5, 'unstructured content, storage, providers and backups remain review gates');
$encoded = json_encode($result, JSON_THROW_ON_ERROR);
foreach ([$user, $other, 'synthetic-private', 'synthetic-secret', 'private/path', 'membership-1', 'driver-1', 'participant-1'] as $secret) {
    check(!str_contains($encoded, $secret), 'output excludes private values');
}
foreach (["' OR 1=1 --", '', '11111111-1111-4111-8111-111111111111;DROP TABLE users'] as $invalid) {
    try {
        (new AccountDataInventory($db))->summarize($invalid);
        throw new RuntimeException('Invalid UUID accepted');
    } catch (InvalidArgumentException $expected) {
    }
}
try {
    (new AccountDataInventory($db))->summarize('33333333-3333-4333-8333-333333333333');
    throw new LogicException('Missing account accepted');
} catch (RuntimeException $expected) {
    check($expected->getMessage() === 'One existing account must be selected.', 'missing account fails closed');
}
$db->exec('PRAGMA query_only = OFF');
$db->exec('DROP TABLE user_devices');
$db->exec('DROP TABLE positions');
$db->exec('CREATE TABLE positions (unknown_subject TEXT)');
$db->exec('DROP TABLE chat_participants');
$db->exec('CREATE TABLE chat_participants (uuid TEXT, unknown_user_link TEXT)');
$schema = (new AccountDataInventory($db))->summarize($user);
check($schema['tables']['user_devices']['candidate_rows'] === null && $schema['tables']['user_devices']['coverage'] === 'table_not_found', 'missing table is not zero-data proof');
check($schema['tables']['positions']['candidate_rows'] === null && $schema['tables']['positions']['coverage'] === 'unrecognized_schema', 'changed schema is not zero-data proof');
check(in_array('chat_participants', $schema['relationship_gaps'], true), 'missing upstream relationship remains visible even when downstream result is empty');
for ($i = 0; $i < 1001; ++$i) {
    insert($db, 'drivers', ['extra-driver-' . $i, $user]);
}
try {
    (new AccountDataInventory($db))->summarize($user);
    throw new LogicException('Truncated relationship accepted');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(), 'bounded review limit'), 'large scope fails instead of silently truncating');
}

// The deployed wrapper must reject unsafe input before framework/database boot,
// and never reflect private paths, account selectors or exception details.
$directory = sys_get_temp_dir() . '/contrust-privacy-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$selector = $directory . '/selector.json';
try {
    file_put_contents($selector, '{"user_uuid":42,"password":"synthetic-private-secret"}');
    chmod($selector, 0600);
    $cases = [$selector];
    symlink($selector, $directory . '/linked.json');
    $cases[] = $directory . '/linked.json';
    file_put_contents($directory . '/public.json', json_encode(['user_uuid' => $user]));
    chmod($directory . '/public.json', 0644);
    $cases[] = $directory . '/public.json';
    foreach ($cases as $path) {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/account-inventory.php', $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($process), 'CLI process starts');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === 1 && $stdout === '', 'unsafe selector fails closed');
        check(str_starts_with($stderr, 'PRIVACY_INVENTORY_INCOMPLETE:'), 'generic failure code');
        foreach ([$path, $user, 'synthetic-private-secret'] as $private) {
            check(!str_contains($stderr, $private), 'CLI failure excludes private values');
        }
    }
} finally {
    foreach ([$directory . '/linked.json', $directory . '/public.json', $selector] as $file) {
        if (file_exists($file) || is_link($file)) {
            unlink($file);
        }
    }
    rmdir($directory);
}
echo "PASS: scoped graph counts, two-hop chat and POD/file links, read-only execution, output redaction, invalid/missing accounts, unknown schema, bounded scope and private CLI selectors\n";
