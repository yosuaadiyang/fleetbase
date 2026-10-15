<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/** Bounded image decoding; browser filenames and Content-Type are not trusted. */
final class ProofImageInput
{
    public static function decode(mixed $input, bool $signature = false): ?array
    {
        $limit = ($signature ? 2 : 10) * 1024 * 1024;
        if ($input instanceof UploadedFile) {
            if (!$input->isValid() || !$input->getSize() || $input->getSize() > $limit) {
                return null;
            }
            $bytes = file_get_contents($input->getRealPath());
        } elseif (is_string($input)) {
            if ($signature && str_starts_with($input, 'data:image/png;base64,')) {
                $input = substr($input, strlen('data:image/png;base64,'));
            }
            if (strlen($input) > 4 * (int) ceil($limit / 3)) {
                return null;
            }
            $bytes = base64_decode($input, true);
        } else {
            return null;
        }
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > $limit) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        $types = [IMAGETYPE_PNG => ['png', 'image/png'], IMAGETYPE_JPEG => ['jpg', 'image/jpeg'], IMAGETYPE_GIF => ['gif', 'image/gif']];
        if (!$info || !isset($types[$info[2]]) || ($signature && $info[2] !== IMAGETYPE_PNG)
            || $info[0] < 1 || $info[1] < 1 || $info[0] > 8192 || $info[1] > 8192) {
            return null;
        }
        return ['bytes' => $bytes, 'extension' => $types[$info[2]][0], 'mime' => $types[$info[2]][1]];
    }
}
