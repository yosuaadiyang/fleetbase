<?php
declare(strict_types=1);

require getenv('CONTRUST_OPERATIONAL_PREVIEW_SOURCE') ?: dirname(__DIR__, 3) . '/api/app/Support/OperationalReportPreview.php';
use App\Support\OperationalReportPreview;
$checks = 0;
function previewCheck(bool $ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}
$key = str_repeat('synthetic-only-key-', 3);
$preview = new OperationalReportPreview($key);
$now = 1800000000;
$actor = 'synthetic-company:synthetic-user:synthetic-driver';
$context = ['type' => 'order_comment', 'id' => 'comment_synthetic', 'selected_text' => 'Selected synthetic text'];
$receipt = $preview->issue($actor, $context, $now);
previewCheck($preview->matches($receipt, $actor, $context, $now), 'current identical preview matches');
previewCheck($preview->matches($receipt, $actor, $context, $now + OperationalReportPreview::TTL - 1), 'receipt valid before exact expiry');
previewCheck(!$preview->matches($receipt, $actor, $context, $now + OperationalReportPreview::TTL), 'receipt expires at exact boundary');
previewCheck(!$preview->matches($receipt, $actor, $context, $now - 1), 'clock rollback cannot extend maximum lifetime');
previewCheck(!$preview->matches($receipt, 'other-user', $context, $now), 'actor binding cannot be replayed by another account');
foreach (['type' => 'issue', 'id' => 'comment_other', 'selected_text' => 'Content changed after preview'] as $field => $value) {
    previewCheck(!$preview->matches($receipt, $actor, array_replace($context, [$field => $value]), $now), 'changed selected field invalidates preview');
}
previewCheck(!$preview->matches($receipt, $actor, $context + ['author_uuid' => 'changed-attribution'], $now), 'new attribution also needs a new preview');
previewCheck(!(new OperationalReportPreview(str_repeat('different-key-', 4)))->matches($receipt, $actor, $context, $now), 'different signing key cannot accept preview');
foreach (['', '.', 'invalid.receipt', $receipt . '.', str_repeat('x', 1025), str_replace('.', 'A.', $receipt), substr($receipt, 0, -1) . 'z'] as $bad) {
    previewCheck(!$preview->matches($bad, $actor, $context, $now), 'malformed or tampered receipt rejected');
}
foreach (['Selected synthetic text', 'comment_synthetic', $actor, $key] as $value) {
    previewCheck(!str_contains(base64_decode(strtr(explode('.', $receipt)[0], '-_', '+/')), $value), 'receipt body excludes content/identifiers/key');
}
try { new OperationalReportPreview('short'); throw new LogicException('Short key accepted'); }
catch (RuntimeException $expected) { previewCheck($expected->getMessage() === 'Report preview unavailable.', 'missing secure key fails generically'); }
echo json_encode(['passed' => $checks, 'failed' => 0, 'database_used' => false, 'network_used' => false]), PHP_EOL;
