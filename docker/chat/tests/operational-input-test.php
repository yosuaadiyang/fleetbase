<?php
require getenv('CONTRUST_TEST_AUTOLOAD') ?: '/fleetbase/api/vendor/autoload.php';
$source = getenv('CONTRUST_TEST_SAFETY_SOURCE_DIR') ?: '/fleetbase/api/app/Support';
foreach (['ChatSafety', 'ChatSafetyInput', 'OperationalReportContext', 'OperationalReports', 'OperationalReportInput'] as $class) require_once $source . '/' . $class . '.php';
use App\Support\{ChatSafety, ChatSafetyInput, OperationalReports, OperationalReportInput as Input};
$checks = 0;
function operationalInputCheck(bool $ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}
$preview = ['type' => 'order_comment', 'target' => 'comment_demo'];
$report = $preview + ['request_id' => '11111111-1111-4111-8111-111111111111', 'preview_receipt' => str_repeat('a', 100),
    'reason' => 'other', 'share_context' => true, 'policy_version' => OperationalReports::POLICY_VERSION];
foreach (['order_comment', 'issue', 'fuel_report', 'proof'] as $type) {
    operationalInputCheck(Input::preview(array_replace($preview, ['type' => $type])), 'known record type can request a preview');
    operationalInputCheck(Input::report(array_replace($report, ['type' => $type])), 'known record type requires explicit confirmation');
}
foreach (['company_uuid', 'user_uuid', 'driver_uuid', 'staff_targets', 'author_uuid', 'location', 'file_url', 'content'] as $field) {
    operationalInputCheck(!Input::preview($preview + [$field => 'injected']), 'HTTP caller cannot select trusted scope or context');
    operationalInputCheck(!Input::report($report + [$field => 'injected']), 'submission cannot supply hidden context or attribution');
}
foreach (['location', 'file', 'user', '', null, []] as $type) operationalInputCheck(!Input::preview(array_replace($preview, ['type' => $type])), 'unknown context type denied');
foreach (['bad/id', "comment\n", str_repeat('x', 151), null, []] as $target) operationalInputCheck(!Input::preview(array_replace($preview, ['target' => $target])), 'malformed target denied');
foreach ([false, 1, 'true', null, []] as $share) operationalInputCheck(!Input::report(array_replace($report, ['share_context' => $share])), 'sharing choice cannot be coerced');
foreach (['old', ChatSafety::VERSION, null, []] as $version) operationalInputCheck(!Input::report(array_replace($report, ['policy_version' => $version])), 'prior chat policy cannot authorize expanded context');
foreach (['', str_repeat('a', 9), str_repeat('a', 1025), [], null] as $receipt) operationalInputCheck(!Input::report(array_replace($report, ['preview_receipt' => $receipt])), 'missing or unbounded receipt denied');
operationalInputCheck(Input::report($report + ['details' => str_repeat('界', 2000)]), 'Unicode detail limit uses characters');
foreach ([str_repeat('界', 2001), [], null] as $details) operationalInputCheck(!Input::report($report + ['details' => $details]), 'invalid details denied');
foreach (['invalid', [], null] as $id) operationalInputCheck(!Input::report(array_replace($report, ['request_id' => $id])), 'stable request ID must be UUID');
foreach (array_keys($report) as $key) {
    $missing = $report; unset($missing[$key]);
    operationalInputCheck(!Input::report($missing), 'required confirmation field cannot be omitted');
}
$consent = ['policy_version' => OperationalReports::POLICY_VERSION, 'accepted' => true];
operationalInputCheck(ChatSafetyInput::consent($consent, OperationalReports::POLICY_VERSION), 'explicit new policy consent accepted');
operationalInputCheck(!ChatSafetyInput::consent($consent), 'legacy default cannot silently accept expanded policy');
operationalInputCheck(!ChatSafetyInput::consent(['policy_version' => ChatSafety::VERSION, 'accepted' => true], OperationalReports::POLICY_VERSION), 'expanded policy requires renewed consent');
echo json_encode(['checks' => $checks, 'passed' => $checks, 'failed' => 0, 'database_used' => false, 'network_used' => false]), PHP_EOL;
