<?php

namespace App\Services\Integrations\Adapters;

/** Default: no telematics provider — every lookup is `integration_pending`. */
class PendingGps implements GpsAdapter
{
    public function configured(): bool
    {
        return false;
    }

    public function getVehiclePosition(string $vehicleCode): array
    {
        return ['status' => Pending::STATUS, 'vehicleCode' => $vehicleCode, 'detail' => Pending::DETAIL_EN];
    }

    public function getVehicleTemperature(string $vehicleCode): array
    {
        return ['status' => Pending::STATUS, 'vehicleCode' => $vehicleCode, 'detail' => Pending::DETAIL_EN];
    }
}
