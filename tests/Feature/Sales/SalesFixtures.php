<?php

namespace Tests\Feature\Sales;

use App\Models\Batch;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\FulfillmentOrder;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Trip;
use App\Models\TripOrder;
use App\Models\TripStop;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * Fixtures of the sales / fulfillment tests. Master data, stock, fleet and trips are owned by other domains whose
 * APIs may not exist yet, so they are built through the models and the inventory engine, always with unique codes.
 */
trait SalesFixtures
{
    private static int $fixtureSeq = 0;

    protected static function code(string $prefix): string
    {
        return $prefix.'-'.self::uid().'-'.(++self::$fixtureSeq);
    }

    protected function inventory(): InventoryService
    {
        return $this->app->make(InventoryService::class);
    }

    protected function ryd(): Warehouse
    {
        return Warehouse::where('code', 'RYD')->firstOrFail();
    }

    protected function makeCustomer(float $creditLimit = 0, float $balance = 0, string $zone = 'شمال الرياض'): Customer
    {
        $code = self::code('CUS-T');

        return Customer::create(['code' => $code, 'name_ar' => "عميل اختبار {$code}", 'name_en' => "Test customer {$code}", 'city' => 'الرياض', 'zone' => $zone, 'terms' => 'آجل 30 يومًا', 'credit_limit' => $creditLimit, 'balance' => $balance]);
    }

    protected function makeProduct(string $storageClass = 'ambient', float $weightKg = 1): Product
    {
        $sku = self::code('SKU-T');

        return Product::create(['sku' => $sku, 'name_ar' => "منتج اختبار {$sku}", 'name_en' => "Test product {$sku}", 'storage_class' => $storageClass, 'weight_kg' => $weightKg, 'tracks_expiry' => true, 'purchase_price' => 10]);
    }

    /** Opening stock through the inventory engine (ledger + balance). $expiryDays null = no batch. */
    protected function stock(Product $product, string $binCode, int $qty, ?int $expiryDays = 180, bool $quarantine = false): ?Batch
    {
        $wh = $this->ryd();
        $batch = $expiryDays === null ? null : Batch::create(['product_id' => $product->id, 'batch_no' => self::code('B'), 'expiry_date' => now()->addDays($expiryDays)]);
        $bin = $this->inventory()->bin($wh->id, $binCode);
        DB::transaction(fn () => $this->inventory()->post(null, null, [
            'type' => 'opening', 'productId' => $product->id, 'batchId' => $batch?->id, 'qty' => $qty, 'to' => ['warehouseId' => $wh->id, 'binId' => $bin->id],
            'reference' => ['type' => 'Test', 'number' => 'T-SALES'], 'quarantine' => $quarantine,
        ]));

        return $batch;
    }

    protected function makeVehicle(string $kind = 'dry', float $maxKg = 3000, float $maxCbm = 16, string $state = 'available'): Vehicle
    {
        $code = self::code('V-T');

        return Vehicle::create(['code' => $code, 'plate_ar' => "ق ب ل {$code}", 'plate_en' => "QBL {$code}", 'kind' => $kind, 'max_kg' => $maxKg, 'max_cbm' => $maxCbm, 'pallets' => 8, 'warehouse_id' => $this->ryd()->id, 'state' => $state]);
    }

    protected function makeDriver(array $overrides = []): Driver
    {
        $code = self::code('DRV-T');

        return Driver::create($overrides + ['code' => $code, 'name_ar' => "سائق اختبار {$code}", 'name_en' => 'Test driver', 'license_expiry' => now()->addYear(), 'state' => 'available']);
    }

    /**
     * A planned trip whose stops follow the given fulfillment-order numbers (stop 1 = first number).
     *
     * @param  string[]  $foNumbers
     */
    protected function makeTrip(array $foNumbers, ?Vehicle $vehicle, ?Driver $driver, string $status = 'dassigned'): Trip
    {
        $trip = Trip::create(['number' => self::code('TRP-T'), 'date' => now(), 'warehouse_id' => $this->ryd()->id, 'vehicle_id' => $vehicle?->id, 'driver_id' => $driver?->id, 'route_ar' => 'مسار اختبار', 'status' => $status, 'temp_need' => 'dry']);
        foreach (array_values($foNumbers) as $i => $number) {
            $fo = FulfillmentOrder::with('customer')->where('number', $number)->firstOrFail();
            $stop = TripStop::create(['trip_id' => $trip->id, 'seq' => $i + 1, 'customer_id' => $fo->customer_id, 'customer_ar' => $fo->customer->name_ar, 'so_id' => $fo->so_id, 'fo_id' => $fo->id]);
            TripOrder::create(['trip_id' => $trip->id, 'fo_id' => $fo->id, 'stop_id' => $stop->id]);
            $fo->update(['trip_id' => $trip->id, 'seq' => $i + 1]);
        }

        return $trip;
    }

    /** POST /api/sales/orders as the sales user; returns the raw response. */
    protected function orderFor(Customer $customer, array $lines, string $user = 'sales')
    {
        return $this->postAs($user, '/api/sales/orders', ['customerCode' => $customer->code, 'warehouseCode' => 'RYD', 'dueDate' => now()->addDays(3)->toDateString(), 'lines' => $lines]);
    }

    /** Fulfil + pick every task + pack; returns the fulfillment-order number. */
    protected function packedOrder(string $soNumber, float $weightKg = 50): string
    {
        $fo = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$soNumber}/fulfill"));
        $full = $this->expectOk($this->getAs('worker', "/api/fulfillment/orders/{$fo['number']}"));
        foreach ($full['pickLists'][0]['tasks'] as $t) {
            $this->expectOk($this->postAs('worker', "/api/fulfillment/pick-tasks/{$t['id']}/confirm", ['scannedBin' => $t['bin']['code'], 'scannedProduct' => $t['product']['sku']]));
        }
        $this->expectOk($this->postAs('worker', "/api/fulfillment/orders/{$fo['number']}/pack", ['cartons' => 2, 'weightKg' => $weightKg]));

        return $fo['number'];
    }

    protected function reserved(Product $product): int
    {
        return (int) InventoryBalance::where('product_id', $product->id)->sum('reserved');
    }

    protected function onHand(Product $product, string $binCode): int
    {
        return (int) InventoryBalance::where('product_id', $product->id)->where('bin_id', $this->inventory()->bin($this->ryd()->id, $binCode)->id)->sum('on_hand');
    }

    protected function assertReconciles(): void
    {
        $result = $this->inventory()->reconcile();
        $this->assertTrue($result['ok'], json_encode($result['mismatches'], JSON_UNESCAPED_UNICODE));
    }
}
