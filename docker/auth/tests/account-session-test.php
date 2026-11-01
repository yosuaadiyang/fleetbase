<?php
require '/fleetbase/api/vendor/autoload.php';
require __DIR__ . '/fixtures/container.php';
require __DIR__ . '/fixtures/login-bootstrap.php';
if (getenv('CONTRUST_ACCOUNT_SESSION_SOURCE')) require getenv('CONTRUST_ACCOUNT_SESSION_SOURCE');

use App\Support\ActiveAccountSession;
use Fleetbase\Models\User;

Illuminate\Container\Container::setInstance(new FleetbaseTestContainer());
app()->instance('session', new Illuminate\Session\Store('qa', new Illuminate\Session\ArraySessionHandler(60)));
$capsule = auth_controller_login_bootstrap_database();
$db = $capsule->getConnection();
$company = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$foreign = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$uuid = '11111111-1111-4111-8111-111111111111';
auth_controller_login_insert_user($capsule, ['company_uuid' => $company, 'type' => 'user']);
$db->table('companies')->insert([['uuid' => $company, 'owner_uuid' => $uuid], ['uuid' => $foreign, 'owner_uuid' => '22222222-2222-4222-8222-222222222222']]);
$db->table('company_users')->insert(['uuid' => 'membership-one', 'user_uuid' => $uuid, 'company_uuid' => $company, 'status' => 'active']);
$user = User::findOrFail($uuid);
$checks = 0;
function accountCheck(bool $ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}
function accountAllowed(User $user, $company, bool $requireMembership = false): bool {
    $db = $user->getConnection();
    $db->statement('PRAGMA query_only = ON');
    try { return ActiveAccountSession::allows($user, $company, $requireMembership); }
    finally { $db->statement('PRAGMA query_only = OFF'); }
}
accountCheck(accountAllowed($user, $company), 'active member retains the current organization');
accountCheck(!accountAllowed($user, $foreign), 'active user cannot borrow another company');
foreach ([null, '', 'invalid', [], 42] as $bad) accountCheck(!accountAllowed($user, $bad), 'malformed company denied');
foreach ([['status' => 'inactive'], ['deleted_at' => '2026-11-01 00:00:00']] as $change) {
    $db->table('company_users')->where('uuid', 'membership-one')->update($change);
    accountCheck(!accountAllowed($user, $company), 'inactive or removed membership denies cached bearer actor');
    $db->table('company_users')->where('uuid', 'membership-one')->update(['status' => 'active', 'deleted_at' => null]);
}
foreach ([['status' => 'inactive'], ['deleted_at' => '2026-11-01 00:00:00']] as $change) {
    $db->table('users')->where('uuid', $uuid)->update($change);
    accountCheck(!accountAllowed($user, $company), 'fresh account state beats cached user properties');
    $db->table('users')->where('uuid', $uuid)->update(['status' => 'active', 'deleted_at' => null]);
}
$db->table('companies')->where('uuid', $company)->update(['deleted_at' => '2026-11-01 00:00:00']);
accountCheck(!accountAllowed($user, $company), 'removed company does not retain member access');
$db->table('companies')->where('uuid', $company)->update(['deleted_at' => null]);
$db->table('company_users')->where('uuid', 'membership-one')->update(['status' => 'inactive']);
$db->table('users')->where('uuid', $uuid)->update(['type' => 'admin']);
accountCheck(accountAllowed($user, $company), 'existing admin-owner exception retains its own company');
accountCheck(!accountAllowed($user, $company, true), 'driver-profile session never inherits admin-owner membership exception');
accountCheck(!accountAllowed($user, $foreign), 'global admin property does not grant a foreign company');
$user->type = 'admin';
$db->table('users')->where('uuid', $uuid)->update(['type' => 'user']);
accountCheck(!accountAllowed($user, $company), 'stale cached admin type cannot preserve owner exception');
$db->table('company_users')->insert(['uuid' => 'membership-two', 'user_uuid' => $uuid, 'company_uuid' => $foreign, 'status' => 'active']);
accountCheck(accountAllowed($user, $foreign), 'a separately active membership can serve a company-scoped key');
$db->table('company_users')->where('uuid', 'membership-two')->update(['status' => 'inactive']);
accountCheck(!accountAllowed($user, $foreign), 'that company-scoped access stops when revoked');
echo json_encode(['passed' => $checks, 'failed' => 0, 'database' => 'synthetic-sqlite-memory', 'query_only_enforced' => true, 'network_used' => false]), PHP_EOL;
