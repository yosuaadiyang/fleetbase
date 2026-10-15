<?php
require '/fleetbase/api/vendor/autoload.php';
require_once '/fleetbase/api/app/Support/ProofImageInput.php';
use App\Support\ProofImageInput;
$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6KpQAAAAASUVORK5CYII=';
$cases = [
    'valid PNG base64 accepted' => ProofImageInput::decode($png)['mime'] === 'image/png',
    'signature data URL accepted' => ProofImageInput::decode('data:image/png;base64,'.$png, true)['extension'] === 'png',
    'array input rejected' => ProofImageInput::decode([$png]) === null,
    'integer input rejected' => ProofImageInput::decode(123) === null,
    'empty image rejected' => ProofImageInput::decode('') === null,
    'invalid base64 rejected' => ProofImageInput::decode('!!not-base64!!') === null,
    'base64 text rejected' => ProofImageInput::decode(base64_encode('not an image')) === null,
    'SVG rejected' => ProofImageInput::decode(base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>')) === null,
    'oversized base64 rejected before decoding' => ProofImageInput::decode(str_repeat('A', 14 * 1024 * 1024)) === null,
];
$path = tempnam(sys_get_temp_dir(), 'contrust-proof-');
try {
    file_put_contents($path, base64_decode($png));
    $upload = new Illuminate\Http\UploadedFile($path, 'untrusted.php', 'text/html', null, true);
    $image = ProofImageInput::decode($upload);
    $cases['upload extension derives from PNG bytes, not executable filename'] = $image['extension'] === 'png' && $image['mime'] === 'image/png';
} finally {
    unlink($path);
}
foreach ($cases as $name => $passed) {
    if (!$passed) throw new RuntimeException($name);
    echo 'PASS proof image: '.$name.PHP_EOL;
}
echo count($cases).' proof image checks passed; no network or database.'.PHP_EOL;
