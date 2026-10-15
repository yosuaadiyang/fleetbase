<?php

namespace App\Http\Middleware;

use App\Support\SocketAccessTickets;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class SocketSessionCookie
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) return $response;
        try {
            $bearer = $request->bearerToken();
            $route = $request->route();
            $class = $route ? $route->getActionName() : '';
            // Login/change-password mints a new token; the bootstrap/old bearer is
            // deliberately not a browser socket credential.
            if (is_string($class) && (str_contains($class, 'AuthController@') || str_contains($class, 'DriverController@'))
                && method_exists($response, 'getData')) {
                $body = $response->getData(true);
                $minted = $body['token'] ?? $body['driver']['token'] ?? null;
                if (is_string($minted)) $bearer = $minted;
            }
            if (!is_string($bearer) || !str_contains($bearer, '|')) return $response;
            $token = PersonalAccessToken::findToken($bearer);
            if (!$token) return $response;
            preg_match('/(?:^|;\s*)' . SocketAccessTickets::COOKIE . '=([a-f0-9]{64})(?:;|$)/', $request->header('Cookie', ''), $match);
            $ticket = SocketAccessTickets::issue($token, $match[1] ?? null);
            if ($ticket) {
                // Root path is required by Android's WebSocket cookie lookup. The
                // opaque ticket authorizes no HTTP API action and contains no bearer.
                $response->headers->setCookie(cookie(SocketAccessTickets::COOKIE, $ticket,
                    SocketAccessTickets::TTL_MINUTES, '/', null, true, true, false, 'strict'));
            }
        } catch (\Throwable $exception) {
            // Cookie issuance is optional for HTTP. Socket authorization still
            // fails closed if the cache/database is unavailable.
        }
        return $response;
    }
}
