<?php

namespace App\Services\Integrations\Adapters;

/** Maps / routing. A place is either an address string or `['lat' => .., 'lng' => ..]`. */
interface MapsAdapter
{
    public function configured(): bool;

    /** @return array{status:string, minutes?:int, distanceKm?:float, detail?:string} */
    public function eta(array|string $from, array|string $to): array;

    /**
     * The drivable line through the points, in order (coordinates only).
     *
     * @param  list<array{lat:float, lng:float}>  $points
     * @return array{status:string, minutes?:int, distanceKm?:float, legs?:list<array{minutes:int, distanceKm:float}>, geometry?:array|null, detail?:string} geometry is a GeoJSON LineString
     */
    public function route(array $points, bool $geometry = true): array;

    /**
     * @param  list<array{id:string, lat?:float, lng?:float, address?:string}>  $stops
     * @return array{status:string, order:string[], totalKm?:float, totalMinutes?:int, detail?:string} when pending, `order` is the input order unchanged
     */
    public function optimizeRoute(array $stops): array;

    /**
     * Snaps a recorded GPS trace (oldest first) to the road network, so a trail follows the streets instead of cutting
     * straight between fixes.
     *
     * @param  list<array{lat:float, lng:float, accuracy?:float|null, at:string|\DateTimeInterface}>  $points
     * @return array{status:string, lines?:list<list<array{0:float, 1:float}>>, detail?:string} lines are `[lng, lat]` runs; a gap in the trace splits them
     */
    public function matchTrace(array $points): array;
}
