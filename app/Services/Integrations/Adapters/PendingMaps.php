<?php

namespace App\Services\Integrations\Adapters;

/** Default: no maps provider. ETA is pending; route optimisation returns the input order unchanged. */
class PendingMaps implements MapsAdapter
{
    public function configured(): bool
    {
        return false;
    }

    public function eta(array|string $from, array|string $to): array
    {
        return Pending::result();
    }

    public function route(array $points, bool $geometry = true): array
    {
        return Pending::result();
    }

    public function optimizeRoute(array $stops): array
    {
        return ['status' => Pending::STATUS, 'order' => array_values(array_column($stops, 'id')), 'detail' => Pending::DETAIL_EN];
    }
}
