<?php

namespace Tests\Feature\Inventory;

use App\Models\Batch;
use App\Models\Bin;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/** Private zones / bins / products / stock for the inventory tests, so they never touch seeded rows. */
trait InventoryFixtures
{
    protected function engine(): InventoryService
    {
        return $this->app->make(InventoryService::class);
    }

    /**
     * A private zone + bins in a warehouse.
     *
     * @param  string[]  $binCodes
     * @return array{wh:Warehouse, zone:Zone, bins:array<string,Bin>}
     */
    protected function makeZone(string $warehouseCode, string $zoneCode, array $binCodes, string $type = 'ambient'): array
    {
        $wh = Warehouse::where('code', $warehouseCode)->firstOrFail();
        $zone = Zone::create(['warehouse_id' => $wh->id, 'code' => $zoneCode, 'name_ar' => "منطقة اختبار {$zoneCode}", 'name_en' => "Test zone {$zoneCode}", 'type' => $type, 'capacity_units' => 1000, 'max_kg' => 1000]);
        $bins = [];
        foreach ($binCodes as $code) {
            $bins[$code] = Bin::create(['warehouse_id' => $wh->id, 'zone_id' => $zone->id, 'code' => $code, 'type' => 'shelf', 'capacity_units' => 400, 'max_kg' => 800]);
        }

        return ['wh' => $wh, 'zone' => $zone, 'bins' => $bins];
    }

    /** @return array{product:Product, batch:Batch} */
    protected function makeProduct(string $tag, string $storageClass = 'ambient', int $expiresInDays = 200): array
    {
        $product = Product::create(['sku' => "TST-{$tag}", 'name_ar' => "منتج اختبار {$tag}", 'name_en' => "Test product {$tag}", 'storage_class' => $storageClass, 'weight_kg' => 1, 'purchase_price' => 10, 'tracks_expiry' => true]);
        $batch = Batch::create(['product_id' => $product->id, 'batch_no' => "TB-{$tag}", 'expiry_date' => now()->startOfDay()->addDays($expiresInDays)]);

        return ['product' => $product, 'batch' => $batch];
    }

    protected function stock(Product $product, ?Batch $batch, Warehouse $wh, Bin $bin, int $qty, string $reference): void
    {
        DB::transaction(fn () => $this->engine()->post(null, $this->engine()->newTxId(), [
            'type' => 'opening', 'productId' => $product->id, 'batchId' => $batch?->id, 'qty' => $qty,
            'to' => ['warehouseId' => $wh->id, 'binId' => $bin->id], 'reference' => ['type' => 'OpeningBalance', 'number' => $reference],
        ]));
    }

    protected function balance(string $productId, string $binId, ?string $batchId): ?InventoryBalance
    {
        return InventoryBalance::where('product_id', $productId)->where('bin_id', $binId)->where('batch_key', $batchId ?? '')->first();
    }

    protected function assertReconciled(): void
    {
        $result = $this->engine()->reconcile();
        Assert::assertTrue($result['ok'], 'ledger ≠ balances: '.json_encode($result['mismatches'], JSON_UNESCAPED_UNICODE));
    }
}
