<?php

namespace Fleetbase\FleetOps\Support\Metrics;

use Fleetbase\FleetOps\Models\Driver;

class DriversOnlineMetric extends AbstractMetric
{
    public static function slug(): string
    {
        return 'drivers_online';
    }

    public function format(): string
    {
        return 'count';
    }

    protected function query(?\DateTimeInterface $start, ?\DateTimeInterface $end)
    {
        return Driver::withoutCache()->where('company_uuid', $this->company->uuid)
            ->where('online', true);
    }

    protected function aggregate($query): int
    {
        return (int) $query->count();
    }
}
