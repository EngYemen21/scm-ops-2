<?php

namespace Tests\Feature\Transport;

use App\Models\Customer;
use App\Models\FoLine;
use App\Models\FulfillmentOrder;
use App\Models\Product;
use App\Models\Warehouse;

/**
 * Builders shared by the transport tests. Sales / fulfillment documents are created straight through Eloquent
 * (their own APIs belong to other domains); fleet master data and trips always go through the transport API.
 */
trait TransportFixtures
{
    private static int $fixtureSeq = 0;

    protected const FAR = '2029-12-31';

    /** Unique, short, upper-case suffix (several per millisecond are safe). */
    protected static function code(string $prefix): string
    {
        return $prefix.self::uid().(++self::$fixtureSeq);
    }

    protected function mkFo(float $weightKg, int $cartons = 8, ?string $storageClass = null, string $status = 'packed', string $warehouse = 'RYD'): FulfillmentOrder
    {
        $fo = FulfillmentOrder::create([
            'number' => self::code('FO-T'), 'customer_id' => Customer::orderBy('code')->firstOrFail()->id, 'warehouse_id' => Warehouse::where('code', $warehouse)->firstOrFail()->id,
            'status' => $status, 'cartons' => $cartons, 'weight_kg' => $weightKg, 'cbm' => round($cartons * 0.06, 1), 'packed_at' => now(),
        ]);
        if ($storageClass) {
            FoLine::create(['fo_id' => $fo->id, 'line_no' => 1, 'product_id' => Product::where('storage_class', $storageClass)->firstOrFail()->id, 'qty' => 5]);
        }

        return $fo;
    }

    /** @return array the vehicle as returned by POST /api/transport/vehicles */
    protected function mkVehicle(string $kind = 'dry', float $maxKg = 3000, float $maxCbm = 16, int $pallets = 8, array $extra = []): array
    {
        $code = self::code($kind === 'dry' ? 'TV-D' : ($kind === 'chill' ? 'TV-C' : 'TV-R'));

        return $this->expectOk($this->postAs('disp', '/api/transport/vehicles', $extra + [
            'code' => $code, 'plateAr' => "ت ج {$code}", 'vin' => "VIN{$code}", 'brand' => 'Isuzu', 'kind' => $kind, 'ownership' => 'owned', 'maxKg' => $maxKg, 'maxCbm' => $maxCbm, 'pallets' => $pallets,
            'warehouseCode' => 'RYD', 'odometer' => 1000, 'regExpiry' => self::FAR, 'insuranceExpiry' => self::FAR, 'inspectionExpiry' => self::FAR, 'opCardExpiry' => self::FAR,
        ]));
    }

    /** @return array the driver as returned by POST /api/transport/drivers */
    protected function mkDriver(array $extra = []): array
    {
        $code = self::code('TD-');

        return $this->expectOk($this->postAs('disp', '/api/transport/drivers', $extra + [
            'code' => $code, 'nameAr' => "سائق اختبار {$code}", 'employeeNo' => "EMP-{$code}", 'mobile' => '0500000000', 'licenseNo' => "L-{$code}",
            'licenseExpiry' => self::FAR, 'iqamaExpiry' => self::FAR, 'shift' => 'am',
        ]));
    }

    /** @param  string[]  $foNumbers */
    protected function mkTrip(array $foNumbers, array $extra = []): array
    {
        return $this->expectOk($this->postAs('disp', '/api/transport/trips', $extra + [
            'warehouseCode' => 'RYD', 'date' => '2026-09-20', 'plannedStart' => '08:00', 'routeAr' => 'مسار اختبار '.self::uid(), 'foNumbers' => $foNumbers,
        ]));
    }
}
