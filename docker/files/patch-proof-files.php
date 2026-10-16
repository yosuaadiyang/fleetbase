<?php
// Exact vendor artifacts, reviewed after the existing driver/POD input patch.
$root = '/fleetbase/api/vendor/fleetbase/';
$hashes = [
    'core-api/src/Models/File.php' => '36486bb0c80f62c3523a5b29b4a0b60048e83c493d50b392e9c28210d31a244e',
    'core-api/src/Http/Controllers/Api/v1/FileController.php' => 'efb33ef1db6e13671eba79caa3e1d794767d9d7f3fbfd42f243d801edc609016',
    'core-api/src/Http/Controllers/Internal/v1/FileController.php' => 'd9141bc1b0e21b48afcd51e8864ea5f7f795a6c31b1d4d6c8deed508ac164bfc',
    'fleetops-api/server/src/Http/Controllers/Api/v1/OrderController.php' => 'd03293e7bcbbab18cf937afb0d33952e7c7c42773991d51db9ace747327b700d',
];
foreach ($hashes as $path => $hash) {
    if (hash_file('sha256', $root . $path) !== $hash) throw new RuntimeException('Unexpected proof-file vendor base: ' . $path);
}
function replaceProofSource(string $source, string $before, string $after, int $expected = 1): string {
    $source = str_replace($before, $after, $source, $count);
    if ($count !== $expected) throw new RuntimeException('Proof-file patch boundary did not match.');
    return $source;
}
$path = $root . 'core-api/src/Models/File.php';
$source = replaceProofSource(file_get_contents($path), "    public function getUrlAttribute()\n    {", <<<'PHP'
    public function getUrlAttribute()
    {
        if (\App\Support\ProofFileAccess::isProtected($this)) {
            return \App\Support\ProofFileAccess::url($this);
        }
PHP);
file_put_contents($path, $source);
foreach (['Api', 'Internal'] as $namespace) {
    $path = $root . 'core-api/src/Http/Controllers/' . $namespace . '/v1/FileController.php';
    $source = replaceProofSource(file_get_contents($path), '        $filesystem = Storage::disk($disk);', <<<'PHP'
        if (!\App\Support\ProofFileAccess::allowDownload($request, $file)) {
            abort(404, 'File unavailable.');
        }
        // A request parameter must never select a different backing filesystem.
        $disk = $file->getDisk();
        $filesystem = Storage::disk($disk);
        if (\App\Support\ProofFileAccess::isProtected($file)) {
            return $filesystem->download($file->path, basename($file->original_filename ?: $file->path), [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'no-referrer',
            ]);
        }
PHP);
    file_put_contents($path, $source);
}
$path = $root . 'fleetops-api/server/src/Http/Controllers/Api/v1/OrderController.php';
$source = file_get_contents($path);
foreach ([9, 8] as $spaces) {
    $source = replaceProofSource($source,
        '$disk' . str_repeat(' ', $spaces) . "= \$request->input('disk', config('filesystems.default'));",
        '$disk' . str_repeat(' ', $spaces) . '= \\App\\Support\\ProofFileAccess::DISK;');
}
$source = replaceProofSource($source, "'bucket'            => \$bucket,", "'bucket'            => null,\n            'disk'              => \\App\\Support\\ProofFileAccess::DISK,", 2);
file_put_contents($path, $source);
echo "PASS pinned proof-file storage and download patch\n";
