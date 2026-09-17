<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Bin;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Support\AppError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\ApiTestCase;

/** The inventory engine: ledger = balances, no negative stock, FEFO, no overselling under concurrency. */
class InventoryEngineTest extends ApiTestCase
{
    private InventoryService $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inv = $this->app->make(InventoryService::class);
    }

    public function test_seeded_data_reconciles(): void
    {
        $result = $this->inv->reconcile();
        $this->assertTrue($result['ok'], json_encode($result['mismatches'], JSON_UNESCAPED_UNICODE));
        $this->assertGreaterThan(0, $result['checked']);
    }

    public function test_mutations_refuse_to_run_outside_a_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->inv->post(null, null, ['type' => 'adj', 'productId' => 'x', 'qty' => 1, 'to' => ['warehouseId' => 'w', 'binId' => 'b'], 'reference' => ['type' => 'Test', 'number' => 'T']]);
    }

    public function test_no_negative_stock_and_the_ledger_follows_every_move(): void
    {
        [$product, $wh, $bin] = $this->stockedProduct('ENG-NEG', 40);
        $other = $this->inv->bin($wh->id, 'A-07-1-B2');

        try {
            DB::transaction(fn () => $this->inv->post(null, null, ['type' => 'move', 'productId' => $product->id, 'qty' => 41, 'from' => ['warehouseId' => $wh->id, 'binId' => $bin->id], 'to' => ['warehouseId' => $wh->id, 'binId' => $other->id], 'reference' => ['type' => 'Test', 'number' => 'T-NEG']]));
            $this->fail('moving more than on hand must be rejected');
        } catch (AppError $e) {
            $this->assertSame('INSUFFICIENT_STOCK', $e->errorCode);
        }
        $this->assertSame(40, (int) InventoryBalance::where('product_id', $product->id)->sum('on_hand'), 'a rejected move changes nothing');

        $move = DB::transaction(fn () => $this->inv->post(null, 'tx-1', ['type' => 'move', 'productId' => $product->id, 'qty' => 15, 'from' => ['warehouseId' => $wh->id, 'binId' => $bin->id], 'to' => ['warehouseId' => $wh->id, 'binId' => $other->id], 'reference' => ['type' => 'Test', 'number' => 'T-MOVE']]));
        $this->assertSame([40, 25], [$move->before_qty, $move->after_qty]);
        $this->assertSame($bin->id, $move->src_bin_id);
        $this->assertSame($other->id, $move->dst_bin_id);
        $this->assertSame('tx-1', $move->transaction_id);
        $this->assertTrue($this->inv->reconcile()['ok']);
    }

    public function test_fefo_skips_expired_and_quarantined_batches(): void
    {
        $sku = 'ENG-FEFO-'.self::uid();
        $wh = Warehouse::where('code', 'RYD')->firstOrFail();
        $product = Product::create(['sku' => $sku, 'name_ar' => 'اختبار FEFO', 'name_en' => 'FEFO test', 'storage_class' => 'ambient', 'tracks_expiry' => true]);
        $stock = function (string $binCode, string $batchNo, int $days, int $qty, bool $quarantine = false) use ($product, $wh) {
            $batch = Batch::create(['product_id' => $product->id, 'batch_no' => $batchNo, 'expiry_date' => now()->addDays($days)]);
            $bin = $this->inv->bin($wh->id, $binCode);
            DB::transaction(fn () => $this->inv->post(null, null, ['type' => 'opening', 'productId' => $product->id, 'batchId' => $batch->id, 'qty' => $qty, 'to' => ['warehouseId' => $wh->id, 'binId' => $bin->id], 'reference' => ['type' => 'Test', 'number' => 'T-FEFO'], 'quarantine' => $quarantine]));

            return $batch;
        };
        $stock('A-07-1-B1', 'EXPIRED', -2, 50);
        $stock('A-07-1-B2', 'QUARANTINED', 5, 50, true);
        $late = $stock('A-07-1-B3', 'LATE', 200, 50);
        $soon = $stock('A-07-2-B1', 'SOON', 20, 30);

        $this->assertSame(80, $this->inv->availability($product->id, $wh->id)['total'], 'expired and quarantined stock is not available');
        [, $line] = $this->salesOrderLine($product, $wh, 40);
        $result = DB::transaction(fn () => $this->inv->reserve(null, null, ['soId' => $line->so_id, 'soLineId' => $line->id, 'productId' => $product->id, 'warehouseId' => $wh->id, 'qty' => 40, 'referenceNumber' => 'SO-TEST']));
        $taken = collect($result['allocations'])->map(fn ($a) => [$a->batch_id, $a->qty])->all();
        $this->assertSame([[$soon->id, 30], [$late->id, 10]], $taken, 'earliest valid expiry first');
        $this->assertTrue($this->inv->reconcile()['ok']);
    }

    /** Mandatory: 100 on hand, two users reserve 80 and 50 at the same time -> never 130 reserved. */
    public function test_concurrent_reservations_cannot_oversell(): void
    {
        [$product, $wh] = $this->stockedProduct('ENG-RACE', 100);
        [, $lineA] = $this->salesOrderLine($product, $wh, 80);
        [, $lineB] = $this->salesOrderLine($product, $wh, 50);

        // Second database session = second user.
        Config::set('database.connections.mysql_b', config('database.connections.'.config('database.default')));
        $default = DB::getDefaultConnection();

        DB::connection($default)->beginTransaction();            // user A: reserve 80, not committed yet
        $this->inv->reserve(null, null, ['soId' => $lineA->so_id, 'soLineId' => $lineA->id, 'productId' => $product->id, 'warehouseId' => $wh->id, 'qty' => 80, 'referenceNumber' => 'SO-A']);

        DB::setDefaultConnection('mysql_b');                     // user B: reserve 50 while A still holds the row lock
        DB::statement('SET SESSION innodb_lock_wait_timeout = 2');
        try {
            DB::transaction(fn () => $this->inv->reserve(null, null, ['soId' => $lineB->so_id, 'soLineId' => $lineB->id, 'productId' => $product->id, 'warehouseId' => $wh->id, 'qty' => 50, 'referenceNumber' => 'SO-B']));
            $this->fail('B must wait for A: the balance row is locked');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
        }

        DB::setDefaultConnection($default);
        DB::connection($default)->commit();                      // A commits 80

        DB::setDefaultConnection('mysql_b');                     // B retries and now sees only 20 available
        try {
            DB::transaction(fn () => $this->inv->reserve(null, null, ['soId' => $lineB->so_id, 'soLineId' => $lineB->id, 'productId' => $product->id, 'warehouseId' => $wh->id, 'qty' => 50, 'referenceNumber' => 'SO-B']));
            $this->fail('50 of the remaining 20 must be rejected');
        } catch (AppError $e) {
            $this->assertSame('INSUFFICIENT_AVAILABLE', $e->errorCode);
            $this->assertSame(['available' => 20, 'requested' => 50], $e->details);
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('mysql_b');
        }

        $this->assertSame(80, (int) InventoryBalance::where('product_id', $product->id)->sum('reserved'), 'never 130');
        $this->assertTrue($this->inv->reconcile()['ok']);
    }

    // ───────────── fixtures ─────────────

    /** @return array{0:Product, 1:Warehouse, 2:Bin} */
    private function stockedProduct(string $prefix, int $qty): array
    {
        $wh = Warehouse::where('code', 'RYD')->firstOrFail();
        $product = Product::create(['sku' => $prefix.'-'.self::uid(), 'name_ar' => 'اختبار المحرك', 'name_en' => 'Engine test', 'storage_class' => 'ambient']);
        $bin = $this->inv->bin($wh->id, 'A-07-1-B1');
        DB::transaction(fn () => $this->inv->post(null, null, ['type' => 'opening', 'productId' => $product->id, 'qty' => $qty, 'to' => ['warehouseId' => $wh->id, 'binId' => $bin->id], 'reference' => ['type' => 'Test', 'number' => 'T-OPEN']]));

        return [$product, $wh, $bin];
    }

    /** @return array{0:SalesOrder, 1:SalesOrderLine} */
    private function salesOrderLine(Product $product, Warehouse $wh, int $qty): array
    {
        $so = SalesOrder::create(['number' => 'SO-T-'.self::uid().random_int(10, 99), 'customer_id' => Customer::firstOrFail()->id, 'warehouse_id' => $wh->id]);
        $line = SalesOrderLine::create(['so_id' => $so->id, 'line_no' => 1, 'product_id' => $product->id, 'qty' => $qty, 'price' => 10]);

        return [$so, $line];
    }
}
