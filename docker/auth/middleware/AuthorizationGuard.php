<?php

namespace Fleetbase\Http\Middleware;

use Fleetbase\Support\Auth;
use Fleetbase\Traits\CustomMiddleware;
use Fleetbase\Models\User;

class AuthorizationGuard
{
    use CustomMiddleware;

    public function handle($request, \Closure $next)
    {
        $user = Auth::getUserFromSession($request);
        if (!$user instanceof User || $user->status !== 'active') {
            return response()->json(['errors' => ['Authentication required.']], 401);
        }
        if (\App\Support\ActiveAccountSession::driverProfile($user, $user->currentAccessToken())) {
            return response()->json(['errors' => ['Use the assigned driver API.']], 403);
        }
        if (!\App\Support\ActiveAccountSession::allows($user, session('company'))) {
            return response()->json(['errors' => ['Organization access unavailable.']], 403);
        }
        $requiredPermissions = Auth::resolvePermissionsFromRequest($request);
        if ($user->isNotAdmin() && $requiredPermissions->isNotEmpty() && $user->doesntHavePermissions($requiredPermissions)) {
            // The session is authenticated; missing permission must not look like expiry.
            return response()->authorizationError()->setStatusCode(403);
        }

        $proceed = function () use ($request, $user, $next) {
            if ($response = \App\Support\InternalChatAccess::check($request, $user)) return $response;
            return $next($request);
        };
        if (\App\Support\ChatSafety::enabled() && \App\Support\InternalChatAccess::kind($request)
            && !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && is_string(session('company'))) {
            return \App\Support\ChatSafety::synchronized(session('company'), $proceed);
        }
        return $proceed();
    }
}
