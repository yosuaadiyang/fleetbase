<?php

namespace App\Http\Controllers;

use App\Support\SocketAccessTickets;
use App\Support\SocketChannelAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

final class SocketAuthorizationController
{
    public function session(Request $request)
    {
        $bearer = $request->bearerToken();
        $token = is_string($bearer) && str_contains($bearer, '|') ? PersonalAccessToken::findToken($bearer) : null;
        return SocketAccessTickets::actor($token) ? response()->json(['status' => 'ok'])->header('Cache-Control', 'no-store, private')
            : response()->json(['errors' => ['Authentication required.']], 401);
    }

    public function authorize(Request $request)
    {
        $secret = config('app.key');
        $expected = is_string($secret) && $secret !== '' ? hash_hmac('sha256', 'contrust.socket.publisher.v1', $secret) : '';
        $provided = $request->header('X-Contrust-Socket-Publisher');
        if ($expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
            return response()->json(['allowed' => false], 403);
        }
        $bucket = 'contrust:socket-authorize:' . hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($bucket, 1200)) return response()->json(['allowed' => false], 429);
        RateLimiter::hit($bucket, 60);
        $ticket = $request->input('ticket'); $channel = $request->input('channel');
        if (!is_string($ticket) || strlen($ticket) !== 64 || !is_string($channel) || strlen($channel) > 150) {
            return response()->json(['allowed' => false], 422);
        }
        try {
            $actor = SocketAccessTickets::resolve($ticket);
            $allowed = $actor && SocketChannelAccess::allows($actor, $channel);
        } catch (\Throwable $exception) {
            // Fail closed without leaking tickets, channel names or model data.
            $allowed = false;
        }
        return response()->json(['allowed' => (bool) $allowed], $allowed ? 200 : 403);
    }
}
