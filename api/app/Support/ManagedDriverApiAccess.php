<?php

namespace App\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Scope Navigator sessions to their own profile and assigned delivery work. */
final class ManagedDriverApiAccess
{
    public static function check(Request $request, User $user): ?JsonResponse
    {
        $route = $request->route();
        $controller = $route ? $route->getController() : null;
        $class = $controller ? get_class($controller) : '';
        $action = $route ? $route->getActionMethod() : '';
        $fleetOpsApi = 'Fleetbase\\FleetOps\\Http\\Controllers\\Api\\v1\\';

        if ($class === $fleetOpsApi . 'DriverController') {
            if ($response = DriverAuthentication::check($request, $action)) {
                return $response;
            }
        }

        // The app's embedded bootstrap key must never act as its admin creator.
        if ($request->attributes->get('contrust.navigator_bootstrap') === true) {
            return $class === $fleetOpsApi . 'DriverController'
                && in_array($action, ['login', 'loginWithPhone', 'verifyCode', 'forgotPassword', 'resetPassword'], true)
                ? null : self::denied();
        }

        $tokenName = $request->attributes->get('contrust.token_name');
        $driver = is_string($tokenName) ? Driver::where([
            'uuid' => $tokenName,
            'user_uuid' => $user->uuid,
            'company_uuid' => $user->company_uuid,
        ])->first() : null;
        // Staff-linked driver tokens are scoped too. Normal staff sessions keep IAM.
        // Console tokens use the user's own UUID; driver app tokens use a
        // different profile UUID. Missing/deleted/stale profiles must not turn
        // a staff-linked driver token back into a normal staff session.
        $driverToken = is_string($tokenName) && Str::isUuid($tokenName) && $tokenName !== $user->uuid;
        if (!$driver && !$user->isType('driver') && !$driverToken) {
            return null;
        }
        if (!$driver || $user->status !== 'active') {
            return self::denied();
        }
        $request->attributes->set('contrust.driver_uuid', $driver->uuid);
        session()->put('driver', $driver->uuid);

        if ($class === $fleetOpsApi . 'DriverController') {
            $id = $route->parameter('id');
            if (!is_string($id) || !in_array($id, [$driver->uuid, $driver->public_id], true)) {
                return self::denied();
            }
            $allowed = ['find', 'track', 'toggleOnline', 'registerDevice', 'listOrganizations', 'currentOrganization', 'switchOrganization', 'changePassword', 'update'];
            if (!in_array($action, $allowed, true)) {
                return self::denied();
            }
            if ($action === 'update') {
                // Operational assignment and identity ownership stay with dispatch.
                $profileFields = ['name', 'email', 'phone', 'timezone', 'photo', 'meta'];
                if (array_diff(array_keys($request->all()), $profileFields)) {
                    return self::denied();
                }
            }
            if ($action === 'switchOrganization') {
                $next = $request->input('next');
                if (!is_string($next) || !Driver::where('user_uuid', $user->uuid)->whereHas('company', fn ($q) => $q->where('public_id', $next))->exists()) {
                    return self::denied();
                }
            }
            if ($action === 'track') {
                foreach (['latitude' => 90, 'longitude' => 180] as $field => $max) {
                    $value = $request->input($field);
                    if ($value !== null && (!is_numeric($value) || !is_finite((float) $value) || abs((float) $value) > $max)) {
                        return response()->json(['errors' => ['Invalid driver coordinates.']], 422);
                    }
                }
                if ($request->has('latitude') !== $request->has('longitude')) {
                    return response()->json(['errors' => ['Both latitude and longitude are required.']], 422);
                }
            }
            return null;
        }

        if ($class === $fleetOpsApi . 'OrderController') {
            // The query callback adds a mandatory SQL predicate from this attribute.
            if ($action === 'query') {
                return null;
            }
            $allowed = ['find', 'startOrder', 'updateActivity', 'completeOrder', 'getNextActivity', 'getDistanceMatrix', 'trackerData', 'etaData', 'orderComments', 'captureSignature', 'captureQrScan', 'capturePhoto', 'proofs', 'getEditableEntityFields'];
            if (!in_array($action, $allowed, true)) {
                return self::denied();
            }
            $id = $route->parameter('id');
            $order = is_string($id) ? Order::where('company_uuid', $driver->company_uuid)
                ->where('driver_assigned_uuid', $driver->uuid)
                ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))->first() : null;
            if (!$order) {
                return self::denied();
            }
            if ($request->hasAny(['skip_dispatch', 'skipDispatch', 'assign', 'disk', 'bucket'])) {
                return self::denied();
            }
            if (in_array($action, ['updateActivity', 'completeOrder'], true)) {
                return self::validateActivity($request, $order, $action);
            }
            return null;
        }

        // Additional resource families need explicit ownership contracts before use.
        return self::denied();
    }

    private static function denied(): JsonResponse
    {
        return response()->json(['errors' => ['You do not have permission to perform this action.']], 403);
    }

    private static function validateActivity(Request $request, Order $order, string $action): ?JsonResponse
    {
        $config = $order->config();
        $next = $config ? $config->nextActivity($order) : collect();
        $code = $action === 'completeOrder' ? $config?->getCompletedActivity()?->code : $request->input('activity.code');
        $activity = is_string($code) ? $next->firstWhere('code', $code) : null;
        if (!$activity || in_array($code, ['created', 'dispatched'], true)) {
            return response()->json(['errors' => ['Activity is not an allowed next step.']], 422);
        }
        $proofId = $request->input('proof');
        $proof = null;
        if ($proofId !== null) {
            $proof = is_string($proofId) ? Proof::where('company_uuid', $order->company_uuid)->where('order_uuid', $order->uuid)
                ->where(fn ($q) => $q->where('uuid', $proofId)->orWhere('public_id', $proofId))->first() : null;
            if (!$proof) {
                return response()->json(['errors' => ['Proof must belong to this order.']], 422);
            }
        }
        if (($activity->get('require_pod') || ($activity->completesOrder() && $order->pod_required)) && !$proof) {
            return response()->json(['errors' => ['Proof of delivery is required.']], 422);
        }
        // Use trusted flow metadata, never client-supplied events/completion flags.
        if ($action === 'updateActivity') {
            $request->merge(['activity' => $activity->serialize()]);
        }
        return null;
    }
}
