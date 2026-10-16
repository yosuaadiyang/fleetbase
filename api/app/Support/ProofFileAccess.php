<?php

namespace App\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\Models\File;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/** Short lived image capabilities, reauthorized against the live order on every read. */
final class ProofFileAccess
{
    public const DISK = 'contrust_private';
    public const TTL_SECONDS = 600;

    public static function configureDisk(): void
    {
        config(['filesystems.disks.' . self::DISK => [
            'driver' => 'local', 'root' => storage_path('app/contrust-private'),
            'visibility' => 'private', 'throw' => true,
        ]]);
    }

    public static function isProtected(File $file): bool
    {
        return $file->getDisk() === self::DISK
            || in_array(strtolower(class_basename((string) $file->subject_type)), ['proof', 'fleet-ops:proof'], true)
            || preg_match('~^uploads/[^/]+/(photos|signatures)/proof_[^/]+$~D', (string) $file->path) === 1;
    }

    public static function requestActor(Request $request): ?array
    {
        $bearer = $request->bearerToken();
        return is_string($bearer) && strlen($bearer) <= 4096 && str_contains($bearer, '|')
            ? SocketAccessTickets::actor(PersonalAccessToken::findToken($bearer)) : null;
    }

    public static function allows(?array $actor, File $file): bool
    {
        if (!$actor || $file->company_uuid !== $actor['company']->uuid) return false;
        $proof = Proof::where('file_uuid', $file->uuid)->where('company_uuid', $file->company_uuid)->first();
        if (!$proof) return false;
        $order = Order::where('uuid', $proof->order_uuid)->where('company_uuid', $file->company_uuid)->first();
        return $order && SocketChannelAccess::allows($actor, 'order.' . $order->public_id);
    }

    public static function allowDownload(Request $request, File $file): bool
    {
        return !self::isProtected($file) || self::allows(self::requestActor($request), $file);
    }

    public static function safePath(File $file): bool
    {
        $path = $file->path;
        return is_string($path) && str_starts_with($path, 'uploads/' . $file->company_uuid . '/')
            && !str_contains($path, '..') && !str_contains($path, '\\') && !str_contains($path, "\0");
    }

    public static function signature(File $file, int $tokenId, int $expires): string
    {
        $key = config('app.key');
        if (!is_string($key) || $key === '') throw new \RuntimeException('File signing unavailable.');
        // File ownership/storage changes also invalidate a previously minted URL.
        $message = json_encode(['contrust.proof-file.v1', $file->uuid, $file->company_uuid,
            $file->getDisk(), $file->path, $tokenId, $expires], JSON_THROW_ON_ERROR);
        return hash_hmac('sha256', $message, $key);
    }

    public static function url(File $file): ?string
    {
        try {
            if ($file->getDisk() !== self::DISK || !self::safePath($file)) return null;
            $actor = self::requestActor(request());
            if (!self::allows($actor, $file)) return null;
            $tokenId = (int) $actor['token']->getKey();
            $expires = now()->timestamp + self::TTL_SECONDS;
            return rtrim(config('app.url'), '/') . '/contrust/private-files/' . $file->uuid . '?' . http_build_query([
                'token_id' => $tokenId, 'expires' => $expires,
                'signature' => self::signature($file, $tokenId, $expires),
            ]);
        } catch (\Throwable $exception) {
            // Never fall back to a public storage URL or log request capabilities.
            return null;
        }
    }
}
