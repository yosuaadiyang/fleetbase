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
    'users' => 'uuid TEXT PRIMARY KEY, email TEXT, avatar_uuid TEXT',
    'company_users' => 'uuid TEXT, user_uuid TEXT',
    'drivers' => 'uuid TEXT, user_uuid TEXT',
    'contacts' => 'uuid TEXT, user_uuid TEXT, photo_uuid TEXT',
    'personal_access_tokens' => 'id INTEGER, tokenable_id TEXT, token TEXT',
    'notifications' => 'notifiable_id TEXT, data TEXT',
    'user_devices' => 'user_uuid TEXT, token TEXT',
    'contrust_push_devices' => 'user_uuid TEXT, encrypted_token TEXT',
    'contrust_chat_consents' => 'user_uuid TEXT, policy_version TEXT',
    'contrust_chat_blocks' => 'user_uuid TEXT, blocked_user_uuid TEXT',
    'contrust_chat_reports' => 'reporter_uuid TEXT, target_user_uuid TEXT, reviewer_uuid TEXT, encrypted_context TEXT',
    'positions' => 'subject_uuid TEXT, coordinates TEXT',
    'orders' => 'uuid TEXT, driver_assigned_uuid TEXT, customer_uuid TEXT, created_by_uuid TEXT',
    'issues' => 'uuid TEXT, driver_uuid TEXT, reported_by_uuid TEXT',
    'fuel_reports' => 'uuid TEXT, driver_uuid TEXT, reported_by_uuid TEXT',
    'proofs' => 'uuid TEXT, order_uuid TEXT, file_uuid TEXT',
    'chat_participants' => 'uuid TEXT, user_uuid TEXT',
    'chat_messages' => 'uuid TEXT, sender_uuid TEXT, content TEXT',
    'chat_receipts' => 'participant_uuid TEXT',
    'chat_attachments' => 'uuid TEXT, chat_message_uuid TEXT, sender_uuid TEXT, file_uuid TEXT',
    'chat_logs' => 'initiator_uuid TEXT, content TEXT',
    'comments' => 'author_uuid TEXT, subject_uuid TEXT, content TEXT',
    'files' => 'uuid TEXT, uploader_uuid TEXT, subject_uuid TEXT, path TEXT',
    'activity_log' => 'causer_id TEXT, subject_id TEXT',
    'activity' => 'causer_id TEXT, subject_id TEXT, properties TEXT',
    'ai_admin_access_logs' => 'viewed_by_uuid TEXT, metadata TEXT',
    'report_audit_logs' => 'user_uuid TEXT, metadata TEXT',
    'geofence_events_log' => 'driver_uuid TEXT, subject_uuid TEXT, latitude TEXT',
    'api_request_logs' => 'access_token_id INTEGER, request_body TEXT',
    'webhook_request_logs' => 'access_token_id INTEGER, response TEXT',
] as $table => $definition) {
    $db->exec('CREATE TABLE ' . $table . ' (' . $definition . ')');
}
function insert(PDO $db, string $table, array $values): void
{
    $query = $db->prepare('INSERT INTO ' . $table . ' VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
    $query->execute($values);
}
insert($db, 'users', [$user, 'synthetic-private@example.invalid', 'avatar-file-1']);
insert($db, 'users', [$other, 'other@example.invalid', 'other-avatar']);
insert($db, 'company_users', ['membership-1', $user]);
insert($db, 'drivers', ['driver-1', $user]);
insert($db, 'drivers', ['driver-2', $other]);
insert($db, 'contacts', ['contact-1', $user, 'contact-photo-1']);
insert($db, 'contacts', ['contact-2', $other, 'other-avatar']);
insert($db, 'personal_access_tokens', [1, $user, 'synthetic-secret-token']);
insert($db, 'personal_access_tokens', [2, $other, 'other-secret-token']);
insert($db, 'notifications', [$user, 'synthetic-private-notification']);
insert($db, 'notifications', [$other, 'other-private-notification']);
insert($db, 'user_devices', [$user, 'synthetic-push-token']);
insert($db, 'contrust_push_devices', [$user, 'synthetic-encrypted-token']);
insert($db, 'contrust_push_devices', [$other, 'other-encrypted-token']);
insert($db, 'contrust_chat_consents', [$user, 'policy-version']);
insert($db, 'contrust_chat_consents', [$other, 'other-policy']);
insert($db, 'contrust_chat_blocks', [$user, $other]);
insert($db, 'contrust_chat_blocks', [$other, $user]);
insert($db, 'contrust_chat_blocks', [$other, $other]);
insert($db, 'contrust_chat_reports', [$user, $other, null, 'synthetic-encrypted-report']);
insert($db, 'contrust_chat_reports', [$other, $user, null, 'synthetic-encrypted-target-report']);
insert($db, 'contrust_chat_reports', [$other, $other, $user, 'synthetic-encrypted-review']);
insert($db, 'contrust_chat_reports', [$other, $other, $other, 'other-encrypted-report']);
insert($db, 'positions', ['driver-1', 'synthetic-private-location']);
insert($db, 'positions', ['driver-2', 'other-private-location']);
insert($db, 'orders', ['order-1', 'driver-1', null, $user]);
insert($db, 'orders', ['order-2', 'driver-2', null, $other]);
insert($db, 'issues', ['issue-1', 'driver-1', $user]);
insert($db, 'fuel_reports', ['fuel-1', 'driver-1', $user]);
insert($db, 'proofs', ['proof-1', 'order-1', 'proof-file-1']);
insert($db, 'chat_participants', ['participant-1', $user]);
insert($db, 'chat_participants', ['participant-2', $other]);
insert($db, 'chat_messages', ['message-1', 'participant-1', 'synthetic-private-chat']);
insert($db, 'chat_messages', ['message-2', 'participant-2', 'other-private-chat']);
insert($db, 'chat_attachments', ['attachment-1', 'message-1', 'participant-1', 'chat-file-1']);
insert($db, 'chat_attachments', ['attachment-2', 'message-2', 'participant-2', 'unrelated-file-1']);
insert($db, 'chat_logs', ['participant-1', 'synthetic-private-chat-log']);
insert($db, 'chat_logs', ['participant-2', 'other-private-chat-log']);
insert($db, 'chat_receipts', ['participant-1']);
insert($db, 'comments', ['membership-1', 'order-1', 'synthetic-private-comment']);
insert($db, 'files', ['upload-file-1', $user, 'proof-1', 'synthetic/private/path.jpg']);
insert($db, 'files', ['shared-file-1', $other, 'issue-1', 'shared/private/path.jpg']);
insert($db, 'files', ['unrelated-file-1', $other, 'other-subject', 'unrelated/private/path.jpg']);
insert($db, 'files', ['proof-file-1', $other, null, 'synthetic/private/pod-uploaded-by-other.jpg']);
insert($db, 'files', ['avatar-file-1', $other, null, 'synthetic/private/avatar-uploaded-by-other.jpg']);
insert($db, 'files', ['contact-photo-1', $other, null, 'synthetic/private/contact-uploaded-by-other.jpg']);
insert($db, 'files', ['chat-file-1', $other, null, 'synthetic/private/chat-uploaded-by-other.jpg']);
insert($db, 'files', ['other-avatar', $other, null, 'unrelated/private/avatar.jpg']);
insert($db, 'activity_log', [$user, 'order-1']);
insert($db, 'activity', [$user, 'order-1', 'synthetic-private-activity']);
insert($db, 'activity', [$other, 'order-2', 'other-private-activity']);
insert($db, 'ai_admin_access_logs', [$user, 'synthetic-private-access-log']);
insert($db, 'ai_admin_access_logs', [$other, 'other-private-access-log']);
insert($db, 'report_audit_logs', [$user, 'synthetic-private-report-audit']);
insert($db, 'report_audit_logs', [$other, 'other-private-report-audit']);
insert($db, 'geofence_events_log', ['driver-1', 'driver-1', 'synthetic-private-latitude']);
insert($db, 'geofence_events_log', ['driver-2', 'driver-2', 'other-private-latitude']);
insert($db, 'api_request_logs', [1, 'synthetic-private-api-body']);
insert($db, 'api_request_logs', [2, 'other-private-api-body']);
insert($db, 'webhook_request_logs', [1, 'synthetic-private-webhook']);
insert($db, 'webhook_request_logs', [2, 'other-private-webhook']);
$changes = (int) $db->query('SELECT total_changes()')->fetchColumn();
$db->exec('PRAGMA query_only = ON');
$result = (new AccountDataInventory($db))->summarize($user);
check((int) $db->query('SELECT total_changes()')->fetchColumn() === $changes, 'no database mutation');
check($result['execution_supported'] === false && $result['complete_erasure_verified'] === false, 'never authorizes deletion or completion');
foreach (['users', 'drivers', 'contacts', 'positions', 'orders', 'issues', 'fuel_reports', 'proofs', 'personal_access_tokens', 'notifications', 'user_devices', 'contrust_push_devices', 'chat_messages', 'chat_receipts', 'chat_attachments', 'chat_logs', 'comments', 'activity_log', 'activity', 'ai_admin_access_logs', 'report_audit_logs', 'geofence_events_log', 'api_request_logs', 'webhook_request_logs'] as $table) {
    check($result['tables'][$table]['candidate_rows'] === 1, 'scoped relationship count for ' . $table);
}
check($result['tables']['files']['candidate_rows'] === 6, 'finds explicit POD/avatar/contact/attachment links uploaded by another person without counting unrelated files');
check($result['tables']['contrust_chat_consents']['candidate_rows'] === 1, 'consent inventory is scoped to the account');
check($result['tables']['contrust_chat_blocks']['candidate_rows'] === 2, 'block inventory covers both directions without duplicates');
check($result['tables']['contrust_chat_reports']['candidate_rows'] === 3, 'report inventory covers reporter, target and reviewer relationships');
check(count($result['required_reviews']) >= 5, 'unstructured content, storage, providers and backups remain review gates');
$encoded = json_encode($result, JSON_THROW_ON_ERROR);
foreach ([$user, $other, 'synthetic-private', 'synthetic-secret', 'other-private', 'other-secret', 'private/path', 'membership-1', 'driver-1', 'participant-1', 'proof-file-1', 'avatar-file-1', 'contact-photo-1', 'chat-file-1'] as $secret) {
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
$db->exec('ALTER TABLE proofs DROP COLUMN file_uuid');
$withoutFileLink = (new AccountDataInventory($db))->summarize($user);
check(in_array('proofs.file_uuid', $withoutFileLink['relationship_gaps'], true), 'missing direct POD-file relationship stays an explicit inventory gap');
check($withoutFileLink['tables']['files']['candidate_rows'] === 4, 'avatar/contact and other known files remain counted despite unavailable POD and participant links');
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
check(@mkdir($directory, 0700), 'private CLI fixture directory can be created');
$selector = $directory . '/selector.json';
try {
    check(file_put_contents($selector, '{"user_uuid":42,"password":"synthetic-private-secret"}') !== false, 'invalid selector fixture exists');
    check(chmod($selector, 0600), 'invalid selector fixture is private');
    $cases = [$selector];
    check(symlink($selector, $directory . '/linked.json'), 'symlink selector fixture exists');
    $cases[] = $directory . '/linked.json';
    check(file_put_contents($directory . '/public.json', json_encode(['user_uuid' => $user])) !== false, 'public selector fixture exists');
    check(chmod($directory . '/public.json', 0644), 'public selector fixture has unsafe permissions');
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
