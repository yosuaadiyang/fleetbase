<?php

namespace App\Http\Middleware;

use App\Support\PublicOrderTrackingPayload;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sanitize the public lookup across route prefixes, preserving other endpoints. */
final class PublicOrderTracking
{
    public function handle(Request $request, Closure $next)
    {
        // Reject malformed public lookup input before the typed controller runs.
        if ($request->isMethod('GET') && $request->is('*/fleet-ops/lookup', 'fleet-ops/lookup')) {
            $tracking = $request->input('tracking');
            if (!is_string($tracking) || trim($tracking) === '') {
                return new JsonResponse(['errors' => ['A tracking number is required.']], 422);
            }
        }
        $response = $next($request);
        $action = $request->route() ? $request->route()->getActionName() : null;
        if ($action !== 'Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\OrderController@lookup'
            || $response->getStatusCode() !== 200) {
            return $response;
        }

        if (!$response instanceof JsonResponse) {
            return new JsonResponse(['errors' => ['Public tracking response unavailable.']], 502);
        }

        $data = $response->getData(true);
        $response->setData(PublicOrderTrackingPayload::fromArray(is_array($data) ? $data : []));
        $response->headers->remove('ETag');
        $response->headers->remove('Content-Length');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
