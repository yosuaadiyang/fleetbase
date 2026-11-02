<?php

namespace App\Support;

use Fleetbase\Models\User;
use Illuminate\Http\Request;

/** Consent boundary for the four record types covered by operational reports. */
final class OperationalContribution
{
    public static function kind(string $controller, string $action, string $method): ?string
    {
        if (!in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) return null;
        $resources = [
            'Fleetbase\\Http\\Controllers\\Api\\v1\\CommentController' => 'order_comment',
            'Fleetbase\\Http\\Controllers\\Internal\\v1\\CommentController' => 'order_comment',
            'Fleetbase\\FleetOps\\Http\\Controllers\\Api\\v1\\IssueController' => 'issue',
            'Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\IssueController' => 'issue',
            'Fleetbase\\FleetOps\\Http\\Controllers\\Api\\v1\\FuelReportController' => 'fuel_report',
            'Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\FuelReportController' => 'fuel_report',
        ];
        if (isset($resources[$controller]) && in_array($action, ['create', 'update', 'createRecord', 'updateRecord'], true)) return $resources[$controller];
        if (isset($resources[$controller]) && in_array($resources[$controller], ['issue', 'fuel_report'], true) && $action === 'import') return $resources[$controller];
        if ($controller === 'Fleetbase\\FleetOps\\Http\\Controllers\\Api\\v1\\OrderController'
            && in_array($action, ['capturePhoto', 'captureSignature', 'captureQrScan'], true)) return 'proof';
        if ($controller === 'Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\OrderController' && $action === 'capturePhoto') return 'proof';
        return null;
    }

    public static function handle(Request $request, User $user, \Closure $next)
    {
        if (!OperationalReports::enabled()) return $next($request);
        $route = $request->route();
        $controller = $route?->getController();
        if (!$controller || !self::kind(get_class($controller), $route->getActionMethod(), $request->method())) return $next($request);
        $company = session('company');
        if (!is_string($company)) return ChatSafety::denied();
        return ChatSafety::synchronized($company, function () use ($request, $user, $next, $company) {
            if ($response = ChatSafety::contribution($user, $company)) return $response;
            return $next($request);
        });
    }
}
