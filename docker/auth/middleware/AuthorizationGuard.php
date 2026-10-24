<?php

namespace Fleetbase\Http\Middleware;

use Fleetbase\Support\Auth;
use Fleetbase\Traits\CustomMiddleware;

class AuthorizationGuard
{
    use CustomMiddleware;

    public function handle($request, \Closure $next)
    {
        $requiredPermissions = Auth::resolvePermissionsFromRequest($request);
        $user = Auth::getUserFromSession($request);
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
