<?php

declare(strict_types=1);

// Operator-only CLI. Copy this directory outside the public web root. The sole
// argument is a private JSON selector file, not an email/token in a shell command.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 2) {
    fwrite(STDERR, "Usage: php account-inventory.php /private/account-selector.json\n");
    exit(2);
}

require __DIR__ . '/AccountDataInventory.php';

try {
    $selector = realpath($argv[1]);
    if ($selector === false || !is_file($selector) || is_link($argv[1]) || (fileperms($selector) & 0077) !== 0 || filesize($selector) > 4096) {
        throw new RuntimeException('Selector must be a small private regular file.');
    }
    if (function_exists('posix_geteuid') && fileowner($selector) !== posix_geteuid()) {
        throw new RuntimeException('Selector must belong to the current operator.');
    }
    $input = json_decode(file_get_contents($selector), true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_keys($input) !== ['user_uuid'] || !is_string($input['user_uuid'])) {
        throw new RuntimeException('Selector requires only user_uuid.');
    }
    require '/fleetbase/api/vendor/autoload.php';
    $app = require '/fleetbase/api/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
    $result = (new Contrust\Privacy\AccountDataInventory($pdo))->summarize($input['user_uuid']);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    // PDO/framework exceptions can contain SQL parameters or credentials. Never
    // reflect them to a release log or claim an incomplete scan found no data.
    fwrite(STDERR, "PRIVACY_INVENTORY_INCOMPLETE: verify the private selector, schema, access and bounded scope. No erasure was performed.\n");
    exit(1);
}
