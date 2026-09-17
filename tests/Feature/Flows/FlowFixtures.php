<?php

namespace Tests\Feature\Flows;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\FoLine;
use App\Models\FulfillmentOrder;
use App\Models\InboundShipment;
use App\Models\InventoryBalance;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\ShipmentLine;
use App\Models\Supplier;
use App\Models\Trip;
use App\Models\TripStop;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * Upstream documents for the flow tests, written exactly as the modules that normally create them would
 * (procurement → PO + expected shipment, fulfillment/transport → FO + dispatched trip with stops).
 */
trait FlowFixtures
{
    private static int $fixtureSeq = 0;

    /** Unique, human-readable code: uid() alone can repeat inside the same millisecond. */
    protected static function code(string $prefix): string
    {
        return $prefix.'-'.self::uid().'-'.(++self::$fixtureSeq);
    }

    protected function inv(): InventoryService
    {
        return $this->app->make(InventoryService::class);
    }

    protected function assertReconciles(): void
    {
        $result = $this->inv()->reconcile();
        $this->assertTrue($result['ok'], json_encode($result['mismatches'], JSON_UNESCAPED_UNICODE));
    }

    protected function ryd(): Warehouse
    {
        return Warehouse::where('code', 'RYD')->firstOrFail();
    }

    protected function product(string $prefix, bool $tracksExpiry = false, string $storageClass = 'ambient'): Product
    {
        return Product::create(['sku' => self::code($prefix), 'name_ar' => 'صنف اختبار التدفقات', 'name_en' => 'Flow test item', 'storage_class' => $storageClass, 'tracks_expiry' => $tracksExpiry]);
    }

    /** On-hand of a product in one bin (all batches). */
    protected function onHand(Product $product, string $binCode, ?Warehouse $wh = null): int
    {
        $bin = $this->inv()->bin(($wh ?? $this->ryd())->id, $binCode);

        return (int) InventoryBalance::where('product_id', $product->id)->where('bin_id', $bin->id)->sum('on_hand');
    }

    protected function totalOnHand(Product $product): int
    {
        return (int) InventoryBalance::where('product_id', $product->id)->sum('on_hand');
    }

    protected function stock(Product $product, string $binCode, int $qty): void
    {
        $wh = $this->ryd();
        $bin = $this->inv()->bin($wh->id, $binCode);
        DB::transaction(fn () => $this->inv()->post(null, null, ['type' => 'opening', 'productId' => $product->id, 'qty' => $qty,
            'to' => ['warehouseId' => $wh->id, 'binId' => $bin->id], 'reference' => ['type' => 'Test', 'number' => 'T-OPEN']]));
    }

    /**
     * A sent PO with its expected inbound shipment, one shipment line per [product, orderedQty] pair.
     *
     * @param  array<int, array{0:Product, 1:int}>  $lines
     * @return array{0:InboundShipment, 1:PurchaseOrder}
     */
    protected function expectedShipment(array $lines): array
    {
        $wh = $this->ryd();
        $supplier = Supplier::firstOrFail();
        $po = PurchaseOrder::create(['number' => self::code('PO-T'), 'supplier_id' => $supplier->id, 'warehouse_id' => $wh->id, 'status' => 'sent',
            'total' => 0, 'open_qty' => array_sum(array_column($lines, 1)), 'sent_at' => now()]);
        $shipment = InboundShipment::create(['number' => self::code('SHP-T'), 'po_id' => $po->id, 'supplier_id' => $supplier->id, 'warehouse_id' => $wh->id, 'status' => 'expected', 'eta' => now()->addDay()]);
        foreach ($lines as $i => [$product, $qty]) {
            $poLine = PoLine::create(['po_id' => $po->id, 'line_no' => $i + 1, 'product_id' => $product->id, 'qty' => $qty, 'price' => 10]);
            ShipmentLine::create(['shipment_id' => $shipment->id, 'line_no' => $i + 1, 'po_line_id' => $poLine->id, 'product_id' => $product->id, 'ordered_qty' => $qty]);
        }

        return [$shipment, $po];
    }

    protected function demoDriver(): Driver
    {
        return Driver::where('code', 'DRV-04')->firstOrFail(); // linked to the demo user `driver`
    }

    /**
     * A trip (default: dispatched, on route) with one stop per entry; every stop carries an FO (from an SO) whose
     * lines are the given [product, qty] pairs.
     *
     * @param  array<int, array<int, array{0:Product, 1:int}>>  $stops
     * @return array{0:Trip, 1:TripStop[], 2:FulfillmentOrder[]}
     */
    protected function dispatchedTrip(Driver $driver, array $stops, string $status = 'onroute'): array
    {
        $wh = $this->ryd();
        $customer = Customer::firstOrFail();
        $trip = Trip::create(['number' => self::code('TRP-T'), 'date' => now(), 'warehouse_id' => $wh->id, 'vehicle_id' => Vehicle::firstOrFail()->id, 'driver_id' => $driver->id,
            'status' => $status, 'dispatched_at' => $status === 'onroute' ? now() : null]);
        $tripStops = $fos = [];
        foreach ($stops as $i => $lines) {
            $so = SalesOrder::create(['number' => self::code('SO-T'), 'customer_id' => $customer->id, 'warehouse_id' => $wh->id, 'status' => 'onroute', 'trip_id' => $trip->id]);
            $fo = FulfillmentOrder::create(['number' => self::code('FO-T'), 'so_id' => $so->id, 'customer_id' => $customer->id, 'warehouse_id' => $wh->id,
                'status' => 'onroute', 'trip_id' => $trip->id, 'loaded' => true, 'dispatched_at' => now()]);
            foreach ($lines as $n => [$product, $qty]) {
                $soLine = SalesOrderLine::create(['so_id' => $so->id, 'line_no' => $n + 1, 'product_id' => $product->id, 'qty' => $qty, 'price' => 10, 'picked_qty' => $qty]);
                FoLine::create(['fo_id' => $fo->id, 'line_no' => $n + 1, 'so_line_id' => $soLine->id, 'product_id' => $product->id, 'qty' => $qty, 'picked_qty' => $qty, 'packed_qty' => $qty]);
            }
            $tripStops[] = TripStop::create(['trip_id' => $trip->id, 'seq' => $i + 1, 'customer_id' => $customer->id, 'customer_ar' => $customer->name_ar, 'customer_en' => $customer->name_en,
                'so_id' => $so->id, 'fo_id' => $fo->id, 'items' => array_sum(array_column($lines, 1)), 'status' => 'pending']);
            $fos[] = $fo;
        }

        return [$trip, $tripStops, $fos];
    }
}
