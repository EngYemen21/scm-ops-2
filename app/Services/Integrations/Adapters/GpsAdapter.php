<?php

namespace App\Services\Integrations\Adapters;

/** GPS / telematics. */
interface GpsAdapter
{
    public function configured(): bool;

    /** @return array{status:string, vehicleCode:string, lat?:float, lng?:float, speedKph?:?float, at?:string, detail?:string} */
    public function getVehiclePosition(string $vehicleCode): array;

    /** @return array{status:string, vehicleCode:string, celsius?:float, at?:string, detail?:string} */
    public function getVehicleTemperature(string $vehicleCode): array;
}
