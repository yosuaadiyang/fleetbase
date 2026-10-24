<?php
require getenv('CONTRUST_TEST_AUTOLOAD') ?: '/fleetbase/api/vendor/autoload.php';
foreach (['ChatSafety', 'ChatSafetyInput'] as $class) {
    $source = getenv('CONTRUST_TEST_SAFETY_SOURCE_DIR') ?: '/fleetbase/api/app/Support';
    require_once $source . '/' . $class . '.php';
}
use App\Support\{ChatSafety, ChatSafetyInput as Input};
$checks = 0;
function safetyInputCheck(bool $ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}
$uuid = '11111111-1111-4111-8111-111111111111';
foreach (['user_member', 'message-123', str_repeat('x', 150)] as $id) safetyInputCheck(Input::publicId($id), 'bounded public ID');
foreach (['', str_repeat('x', 151), 'bad/id', "user\0bad", "user\n", true, 42, [], null] as $id) safetyInputCheck(!Input::publicId($id), 'invalid public ID fails');
safetyInputCheck(Input::status([]) && Input::status(['blocked_after' => 'user_qa']), 'optional bounded block cursor');
foreach ([['blocked_after' => null], ['blocked_after' => []], ['user' => 'other'], ['blocked_after' => 'bad/id']] as $input) safetyInputCheck(!Input::status($input), 'invalid status scope');
$consent = ['policy_version' => ChatSafety::VERSION, 'accepted' => true];
safetyInputCheck(Input::consent($consent), 'explicit current consent');
foreach ([false, 1, 'true', null, []] as $accepted) safetyInputCheck(!Input::consent(['policy_version' => ChatSafety::VERSION, 'accepted' => $accepted]), 'consent is not coerced');
safetyInputCheck(!Input::consent(['policy_version' => 'old', 'accepted' => true]), 'stale consent rejected');
safetyInputCheck(!Input::consent($consent + ['user' => 'other']), 'consent cannot pick another identity');
safetyInputCheck(Input::block(['user' => 'user_qa']), 'single bounded block target');
foreach ([[], ['user' => ''], ['user' => []], ['user' => 'user_qa', 'company' => 'other']] as $input) safetyInputCheck(!Input::block($input), 'invalid block payload');
$report = ['request_id' => $uuid, 'type' => 'message', 'target' => 'message_qa', 'reason' => 'harassment', 'share_context' => true];
safetyInputCheck(Input::report($report), 'report accepts selected context with omitted optional details');
safetyInputCheck(Input::report($report + ['details' => str_repeat('界', 2000)]), 'UTF8 detail boundary counts characters');
foreach ([['details' => str_repeat('界', 2001)], ['details' => null], ['details' => []], ['request_id' => 'bad'], ['request_id' => []], ['type' => 'location'], ['type' => []], ['target' => ''], ['target' => 'bad/id'], ['target' => []], ['reason' => 'unknown'], ['reason' => []], ['share_context' => false], ['share_context' => 1], ['share_context' => 'true']] as $override) {
    safetyInputCheck(!Input::report(array_replace($report, $override)), 'invalid report cannot reach persistence');
}
foreach (['company_uuid', 'reporter', 'status', 'encrypted_context'] as $field) safetyInputCheck(!Input::report($report + [$field => 'spoofed']), 'report rejects server-owned field');
foreach (array_keys($report) as $field) { $missing = $report; unset($missing[$field]); safetyInputCheck(!Input::report($missing), 'missing required report field'); }
$review = ['status' => 'resolved', 'resolution' => 'support_followup'];
safetyInputCheck(Input::review($uuid, $review), 'bounded review outcome');
foreach ([['status' => 'delete'], ['status' => []], ['resolution' => 'ban_everyone'], ['resolution' => []], ['reviewer' => 'other']] as $override) safetyInputCheck(!Input::review($uuid, array_replace($review, $override)), 'invalid moderation mutation');
safetyInputCheck(!Input::review('bad', $review), 'review ID must be UUID');
safetyInputCheck(Input::reports([]) && Input::reports(['before' => $uuid]), 'optional bounded review cursor');
foreach ([['before' => null], ['before' => []], ['before' => 'bad'], ['limit' => 100000], ['company' => 'foreign']] as $input) safetyInputCheck(!Input::reports($input), 'invalid review list scope');
echo json_encode(['checks' => $checks, 'passed' => $checks, 'failed' => 0, 'database_used' => false, 'network_used' => false]), PHP_EOL;
