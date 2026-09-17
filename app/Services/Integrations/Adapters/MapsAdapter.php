<?php

namespace App\Services\Integrations\Adapters;

/** Maps / routing. A place is either an address string or `['lat' => .., 'lng' => ..]`. */
interface MapsAdapter
{
    public function configured(): bool;

    /** @return array{status:string, minutes?:int, distanceKm?:float, detail?:string} */
    public function eta(array|string $from, array|string $to): array;

    /**
     * @param  list<array{id:string, lat?:float, lng?:float, address?:string}>  $stops
     * @return array{status:string, order:string[], totalKm?:float, totalMinutes?:int, detail?:string} when pending, `order` is the input order unchanged
     */
    public function optimizeRoute(array $stops): array;
}
