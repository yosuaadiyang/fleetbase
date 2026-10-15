<?php
// Build-time patch against an exact vendor artifact, not a runtime rewrite.
$path = '/fleetbase/api/vendor/fleetbase/fleetops-api/server/src/Http/Controllers/Api/v1/OrderController.php';
if (hash_file('sha256', $path) !== '23134f77483943b49fa1e792fd2f9135b4656ce705ededd97c378387331671cb') {
    throw new RuntimeException('Unexpected OrderController base; review the driver scope patch before upgrading.');
}
$needle = "            \$query->where('company_uuid', session('company'));";
$replacement = $needle . <<<'PHP'

            // Mandatory server-resolved scope; request filters cannot widen it.
            if ($driverUuid = $request->attributes->get('contrust.driver_uuid')) {
                $query->where('driver_assigned_uuid', $driverUuid);
            }
PHP;
$source = str_replace($needle, $replacement, file_get_contents($path), $count);
if ($count !== 1) {
    throw new RuntimeException('Driver order scope patch did not apply exactly once.');
}
// The vendor query cache keys company/query parameters, not the actor or the
// server callback's mandatory assignment predicate. Never share those results
// between a dispatcher and a driver, in either request order.
$source = str_replace('Order::queryWithRequest($request, function (&$query, $request)',
    'Order::withoutCache()->queryFromRequest($request, function (&$query, $request)', $source, $cacheCount);
if ($cacheCount !== 1) {
    throw new RuntimeException('Driver order cache isolation patch did not apply exactly once.');
}
$replacements = [
    <<<'PHP'
            'entity' => Entity::withoutGlobalScopes()
                ->where('public_id', $subjectId)
PHP => <<<'PHP'
            'entity' => Entity::withoutGlobalScopes()
                ->where('company_uuid', $order->company_uuid)
                ->where('payload_uuid', $order->payload_uuid)
                ->where('public_id', $subjectId)
PHP,
    'public function captureSignature(Request $request, string $id, ?string $subjectId = null)' . "\n    {" => <<<'PHP'
public function captureSignature(Request $request, string $id, ?string $subjectId = null)
    {
        if (\App\Support\ProofImageInput::decode($request->input('signature'), true) === null) {
            return $this->apiError('Signature must be a PNG image of at most 2 MB.', 422);
        }
PHP,
    'public function capturePhoto(Request $request, string $id, ?string $subjectId = null)' . "\n    {" => <<<'PHP'
public function capturePhoto(Request $request, string $id, ?string $subjectId = null)
    {
        $proofImages = $request->all()['photos'] ?? null;
        if (!is_array($proofImages) || count($proofImages) < 1 || count($proofImages) > 5) {
            return $this->apiError('Provide one to five proof images.', 422);
        }
        foreach ($proofImages as $proofImage) {
            if (\App\Support\ProofImageInput::decode($proofImage) === null) {
                return $this->apiError('Proof must be a PNG, JPEG or GIF image of at most 10 MB.', 422);
            }
        }
PHP,
    'string $disk, string $bucket): File' => 'string $disk, ?string $bucket): File',
    <<<'PHP'
        $isFile      = $photo instanceof UploadedFile;
        $contents    = $isFile
            ? file_get_contents($photo->getRealPath())
            : base64_decode($photo);
        $extension   = $isFile
            ? $photo->getClientOriginalExtension()
            : 'png';
        $contentType = $isFile
            ? $photo->getClientMimeType()
            : 'image/png';
PHP => <<<'PHP'
        $image = \App\Support\ProofImageInput::decode($photo);
        if ($image === null) {
            throw ValidationException::withMessages(['photos' => 'Invalid proof image.']);
        }
        $contents = $image['bytes'];
        $extension = $image['extension'];
        $contentType = $image['mime'];
PHP,
];
foreach ($replacements as $before => $after) {
    $source = str_replace($before, $after, $source, $matches);
    if ($matches !== 1) {
        throw new RuntimeException('POD image patch did not apply exactly once.');
    }
}
if (file_put_contents($path, $source) === false) {
    throw new RuntimeException('Could not write reviewed FleetOps API patch.');
}
