<?php

namespace App\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Models\Permission;

/** Fail closed outside the tested Fleet-Ops and self-notification channels. */
final class SocketChannelAccess
{
    public static function allows(array $actor, string $channel): bool
    {
        if ($channel === '@session') return true;
        if (!preg_match('/^(driver|vehicle|order|company|user|chat)\.([a-zA-Z0-9_-]{1,100})(\.orders)?$/D', $channel, $parts)) return false;
        [, $type, $id] = $parts;
        $suffix = $parts[3] ?? '';
        $user = $actor['user']; $company = $actor['company']; $driver = $actor['driver'];
        if ($suffix !== '' && $type !== 'company') return false;
        if ($type === 'chat') return OrganizationChat::channel($user, $id) !== null;
        if ($type === 'user') return in_array($id, [$user->uuid, $user->public_id], true);
        if ($type === 'company') {
            if ($driver || !in_array($id, [$company->uuid, $company->public_id], true)) return false;
            return $suffix === '.orders' ? self::permission($actor, 'order') : self::administrator($actor);
        }
        if ($type === 'driver') {
            if ($driver) return in_array($id, [$driver->uuid, $driver->public_id], true);
            return self::permission($actor, 'driver') && self::resource(Driver::class, $id, $company->uuid)->exists();
        }
        if ($type === 'order') {
            $query = self::resource(Order::class, $id, $company->uuid);
            return $driver ? $query->where('driver_assigned_uuid', $driver->uuid)->exists()
                : self::permission($actor, 'order') && $query->exists();
        }
        $query = self::resource(Vehicle::class, $id, $company->uuid);
        return $driver ? (is_string($driver->vehicle_uuid) && $query->where('uuid', $driver->vehicle_uuid)->exists())
            : self::permission($actor, 'vehicle') && $query->exists();
    }

    private static function resource(string $model, string $id, string $company)
    {
        return $model::where('company_uuid', $company)->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id));
    }

    private static function administrator(array $actor): bool
    {
        return ($actor['user']->isAdmin() && $actor['company']->owner_uuid === $actor['user']->uuid)
            || ($actor['member'] && $actor['member']->hasRole('Administrator'));
    }

    private static function permission(array $actor, string $resource): bool
    {
        if (self::administrator($actor)) return true;
        $permissions = Permission::findByNames(['fleet-ops view ' . $resource, 'fleet-ops * ' . $resource, 'fleet-ops *']);
        return $actor['member'] && $permissions->isNotEmpty() && $actor['member']->hasAnyPermission($permissions);
    }
}
