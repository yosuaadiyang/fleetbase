<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\LiveCacheService;

class VehicleObserver
{
    public function creating(Vehicle $vehicle): void
    {
        // The database column is non-nullable and the public API makes it optional.
        // New vehicles start offline unless the caller explicitly supplies a value.
        if ($vehicle->online === null) {
            $vehicle->online = false;
        }
    }

    /**
     * Handle the Vehicle "created" event.
     *
     * @return void
     */
    public function created(Vehicle $vehicle)
    {
        // assign this vehicle to a driver if the driver has been set
        $identifier = $this->getDriverIdentifier();

        if ($identifier) {
            $driver = $this->findDriver($identifier, $vehicle->company_uuid);

            if ($driver) {
                // assign this vehicle to driver
                $driver->assignVehicle($vehicle);

                // set driver to vehicle
                $vehicle->setRelation('driver', $driver);
            }
        }

        $this->invalidateLiveCache();
    }

    /**
     * Handle the Vehicle "updated" event.
     *
     * @return void
     */
    public function updating(Vehicle $vehicle)
    {
        // assign this vehicle to a driver if the driver has been set
        $identifier = $this->getDriverIdentifier();

        if ($identifier) {
            $driver = $this->findDriver($identifier, $vehicle->company_uuid);

            if ($driver) {
                // assign this vehicle to driver
                $driver->assignVehicle($vehicle, false);

                // set driver to vehicle
                $vehicle->setRelation('driver', $driver);
            }
        }

        $this->invalidateLiveCache();
    }

    /**
     * Handle the Vehicle "deleted" event.
     *
     * @return void
     */
    public function deleted(Vehicle $vehicle)
    {
        // Unassign the deleted vehicle from matching driver/(s)
        $this->unassignDriversAssignedTo($vehicle);

        $this->invalidateLiveCache();
    }

    protected function getDriverIdentifier(): ?string
    {
        return request()->or(['driver_uuid', 'vehicle.driver_uuid', 'vehicle.driver.uuid']);
    }

    protected function findDriver(string $identifier, ?string $companyUuid): ?Driver
    {
        return Driver::withoutGlobalScopes()->where('uuid', $identifier)
            ->where('company_uuid', $companyUuid)->whereNull('deleted_at')->first();
    }

    protected function unassignDriversAssignedTo(Vehicle $vehicle): mixed
    {
        return Driver::where('company_uuid', $vehicle->company_uuid)
            ->where('vehicle_uuid', $vehicle->uuid)->update(['vehicle_uuid' => null]);
    }

    protected function invalidateLiveCache(): void
    {
        LiveCacheService::invalidateMultiple(['vehicles', 'drivers', 'operations-monitor']);
    }
}
