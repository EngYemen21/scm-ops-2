<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Map coordinates for the DEMO data set (the prototype snapshot has none): the three warehouses, the seven customers
 * and the stops of the five demo trips, placed in the district their name / route describes. Demo values only — real
 * warehouses and customers get their pin from the map picker in their form.
 *
 * Idempotent and non-destructive: a row that already has coordinates is never touched. On an existing database run it
 * alone with `php artisan db:seed --class=Database\\Seeders\\DemoCoordinatesSeeder --force`.
 */
class DemoCoordinatesSeeder extends Seeder
{
    private const WAREHOUSES = [
        'RYD' => [24.6408, 46.8440], // السلي — جنوب شرق الرياض
        'JED' => [21.4200, 39.2400], // جنوب جدة
        'DMM' => [26.3790, 50.1480], // الصناعية الأولى — الدمام
    ];

    private const CUSTOMERS = [
        'CUS-1001' => [24.6905, 46.6853], // العليا
        'CUS-1002' => [26.4580, 50.1180], // كورنيش الدمام
        'CUS-1003' => [24.6790, 46.6560], // التخصصي
        'CUS-1004' => [24.8080, 46.6110], // الملقا
        'CUS-1005' => [24.6530, 46.7150], // وسط الرياض
        'CUS-1006' => [24.8150, 46.6250], // الملقا
        'CUS-1007' => [26.2890, 50.2130], // الخبر
    ];

    /** trip number → seq → [lat, lng] */
    private const STOPS = [
        'TRP-2026-0031' => [1 => [24.6905, 46.6853], 2 => [24.7120, 46.6750], 3 => [24.6790, 46.6560], 4 => [24.8150, 46.6250], 5 => [24.6400, 46.6000]],
        'TRP-2026-0029' => [1 => [26.4300, 50.1000], 2 => [26.4440, 50.1150], 3 => [26.2950, 50.2080], 4 => [26.2800, 50.2000], 5 => [26.3050, 50.1700]],
        'TRP-2026-0028' => [1 => [24.5900, 46.7700], 2 => [24.1550, 47.3100], 3 => [24.5600, 46.7000], 4 => [24.5750, 46.7350], 5 => [24.1700, 47.3300], 6 => [24.1480, 47.3050]],
        'TRP-2026-0027' => [1 => [21.6200, 39.1100], 2 => [21.7100, 39.0950], 3 => [21.7350, 39.0800], 4 => [21.6600, 39.1050]],
        'TRP-2026-0032' => [1 => [24.7350, 46.7700], 2 => [24.7300, 46.8300], 3 => [24.7050, 46.8100], 4 => [24.7500, 46.7950]],
    ];

    public function run(): void
    {
        $n = 0;
        foreach (['warehouses' => self::WAREHOUSES, 'customers' => self::CUSTOMERS] as $table => $rows) {
            foreach ($rows as $code => [$lat, $lng]) {
                $n += DB::table($table)->where('code', $code)->whereNull('lat')->update(['lat' => $lat, 'lng' => $lng]);
            }
        }
        foreach (self::STOPS as $number => $stops) {
            $tripId = DB::table('trips')->where('number', $number)->value('id');
            foreach ($tripId ? $stops : [] as $seq => [$lat, $lng]) {
                $n += DB::table('trip_stops')->where('trip_id', $tripId)->where('seq', $seq)->whereNull('lat')->update(['lat' => $lat, 'lng' => $lng]);
            }
        }
        $this->command?->info("[seed] demo map coordinates set on {$n} rows");
    }
}
