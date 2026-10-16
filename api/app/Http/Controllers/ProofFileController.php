<?php

namespace App\Http\Controllers;

use App\Support\ProofFileAccess;
use App\Support\SocketAccessTickets;
use Fleetbase\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

final class ProofFileController
{
    public function show(Request $request, string $id)
    {
        $denied = fn () => response()->json(['errors' => ['File unavailable.']], 404)
            ->header('Cache-Control', 'private, no-store');
        $bucket = 'contrust:proof-file:' . hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($bucket, 240)) {
            return response()->json(['errors' => ['Too many requests.']], 429)->header('Cache-Control', 'private, no-store');
        }
        RateLimiter::hit($bucket, 60);
        $tokenId = $request->query('token_id'); $expires = $request->query('expires'); $signature = $request->query('signature');
        if (!Str::isUuid($id) || !is_string($tokenId) || !preg_match('/^[1-9][0-9]{0,17}$/D', $tokenId)
            || !is_string($expires) || !preg_match('/^[0-9]{10}$/D', $expires)
            || !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature)
            || (int) $expires <= now()->timestamp || (int) $expires > now()->timestamp + ProofFileAccess::TTL_SECONDS) return $denied();
        try {
            $file = File::where('uuid', $id)->first();
            if (!$file || $file->getDisk() !== ProofFileAccess::DISK || !ProofFileAccess::safePath($file)
                || !hash_equals(ProofFileAccess::signature($file, (int) $tokenId, (int) $expires), $signature)) return $denied();
            $actor = SocketAccessTickets::actor(PersonalAccessToken::find((int) $tokenId));
            if (!ProofFileAccess::allows($actor, $file)) return $denied();
            $storage = Storage::disk(ProofFileAccess::DISK);
            if (!$storage->exists($file->path)) return $denied();
            $mime = in_array($file->content_type, ['image/png', 'image/jpeg', 'image/gif'], true)
                ? $file->content_type : 'application/octet-stream';
            return $storage->response($file->path, basename($file->original_filename ?: $file->path), [
                'Content-Type' => $mime, 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ]);
        } catch (\Throwable $exception) {
            return response()->json(['errors' => ['File temporarily unavailable.']], 503)
                ->header('Cache-Control', 'private, no-store');
        }
    }
}
