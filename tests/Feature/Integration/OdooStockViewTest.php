<?php

namespace Tests\Feature\Integration;

use App\Integration\Odoo\OdooSync;
use App\Models\InventoryBalance;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * Odoo's stock as people see it in OPS (OdooStockView): what Odoo holds per warehouse and product, with lots and
 * expiry — read from the mirror, never written to the OPS inventory ledger.
 */
class OdooStockViewTest extends ApiTestCase
{
    private FakeOdoo $odoo;

    private Product $opsProduct;

    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['odoo_runs', 'odoo_changes', 'odoo_records', 'odoo_models'] as $table) {
            DB::table($table)->delete();
        }
        Cache::flush();
        config(['odoo' => FakeOdoo::config(['priority' => ['stock.warehouse', 'stock.location', 'product.product', 'stock.lot', 'stock.quant']])]);
        $this->odoo = (new FakeOdoo)->install();
        $this->tag = $tag = self::uid();
        $this->opsProduct = Product::create(['sku' => "PS{$tag}A", 'name_ar' => 'طحين', 'name_en' => 'Flour', 'storage_class' => 'ambient', 'weight_kg' => 20,
            'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 10, 'tracks_expiry' => true, 'active' => true]);

        $m2o = fn (string $relation) => ['type' => 'many2one', 'relation' => $relation];
        $this->odoo->model('stock.warehouse', ['name' => 'char', 'code' => 'char', 'active' => 'boolean'], 'Warehouse');
        $this->odoo->model('stock.location', ['name' => 'char', 'usage' => 'selection', 'warehouse_id' => $m2o('stock.warehouse'), 'active' => 'boolean'], 'Inventory Locations');
        $this->odoo->model('product.product', ['default_code' => 'char', 'active' => 'boolean'], 'Product Variant');
        $this->odoo->model('stock.lot', ['name' => 'char', 'product_id' => $m2o('product.product'), 'expiration_date' => 'datetime'], 'Lot/Serial');
        $this->odoo->model('stock.quant', ['product_id' => $m2o('product.product'), 'location_id' => $m2o('stock.location'), 'lot_id' => $m2o('stock.lot'),
            'quantity' => 'float', 'reserved_quantity' => 'float'], 'Quants');

        $this->odoo->create('stock.warehouse', ['id' => 1, 'name' => 'Riyadh warehouse', 'code' => 'WH']);
        $this->odoo->create('stock.warehouse', ['id' => 2, 'name' => 'Jeddah warehouse', 'code' => 'jedah']);
        $this->odoo->create('stock.location', ['id' => 8, 'name' => 'WH/رياض', 'usage' => 'internal', 'warehouse_id' => [1, 'Riyadh warehouse']]);
        $this->odoo->create('stock.location', ['id' => 18, 'name' => 'jedah/جدة', 'usage' => 'internal', 'warehouse_id' => [2, 'Jeddah warehouse']]);
        $this->odoo->create('stock.location', ['id' => 5, 'name' => 'Partners/Customers', 'usage' => 'customer']);
        $this->odoo->create('stock.location', ['id' => 14, 'name' => 'Virtual/Inventory adjustment', 'usage' => 'inventory']);
        $this->odoo->create('product.product', ['id' => 100, 'default_code' => strtolower("PS{$tag}A")]); // the code, whatever its letter case
        $this->odoo->create('product.product', ['id' => 101, 'default_code' => "PS{$tag}B"]);
        $this->odoo->create('product.product', ['id' => 102]);
        $day = fn (int $days) => Carbon::today()->addDays($days)->format('Y-m-d').' 09:00:00';
        $this->odoo->create('stock.lot', ['id' => 1, 'name' => 'L-OLD', 'product_id' => [100, 'x'], 'expiration_date' => $day(-10)]);
        $this->odoo->create('stock.lot', ['id' => 2, 'name' => 'L-SOON', 'product_id' => [100, 'x'], 'expiration_date' => $day(12)]);
        $this->odoo->create('stock.lot', ['id' => 3, 'name' => 'L-FAR', 'product_id' => [100, 'x'], 'expiration_date' => $day(200)]);
        $this->odoo->create('stock.lot', ['id' => 4, 'name' => 'L-B', 'product_id' => [101, 'x'], 'expiration_date' => $day(90)]);

        $flour = [100, '['.strtolower("PS{$tag}A").'] طحين Flour (5kg*4pcs)'];
        $other = [101, "[PS{$tag}B] زيت Oil (1L*12pcs)"];
        $quant = fn (array $product, int $location, ?int $lot, float $qty, float $reserved = 0) => $this->odoo->create('stock.quant',
            ['product_id' => $product, 'location_id' => [$location, 'x'], 'lot_id' => $lot ? [$lot, 'x'] : false, 'quantity' => $qty, 'reserved_quantity' => $reserved]);
        $quant($flour, 8, 1, 4);            // 1: expired, in Riyadh
        $quant($flour, 8, 2, 10, 3);        // 2: expires within a month, 3 reserved
        $quant($flour, 8, 3, 20);           // 3
        $quant($flour, 18, 3, 7);           // 4: the same lot in Jeddah
        $quant($other, 8, 4, 5.5);          // 5
        $quant($flour, 5, 3, -40);          // 6: what went to customers is Odoo's bookkeeping, not stock
        $quant([102, 'صنف بلا رمز'], 18, null, 2); // 7: a product with no code
        $quant($other, 8, 4, 0);            // 8: an empty quant
        $quant($other, 18, null, -1);       // 9: a negative balance in a warehouse is shown, not hidden
        $quant($flour, 14, 3, 15);          // 10: an inventory adjustment's counterpart
    }

    private function sync(): array
    {
        return $this->app->make(OdooSync::class)->run(30, 'test');
    }

    private function stock(string $query = '', string $as = 'gm'): array
    {
        return $this->expectOk($this->getAs($as, '/api/integration/odoo/stock'.($query ? "?{$query}" : '')));
    }

    public function test_odoo_stock_is_shown_per_warehouse_and_product_with_lots_and_expiry(): void
    {
        $ledger = InventoryBalance::count();
        // before the copy is complete the screen says so instead of showing half a picture
        $this->expectRejected($this->getAs('worker', '/api/integration/odoo/stock'), 'FORBIDDEN', [403]);
        $empty = $this->stock();
        $this->assertSame([false, 0, [], null], [$empty['ready'], $empty['total'], $empty['items'], $empty['totals']]);

        $this->sync();
        $all = $this->stock();
        $this->assertTrue($all['ready']);
        $this->assertSame(5, $all['total']);
        $this->assertNotNull($all['asOf']);
        $this->assertEquals(['qty' => 47.5, 'products' => 3, 'expiredQty' => 4, 'soonQty' => 10, 'negative' => 1, 'notInOps' => 2], $all['totals']);
        $this->assertEquals([['id' => 1, 'name' => 'Riyadh warehouse', 'code' => 'WH', 'qty' => 39.5, 'products' => 2], ['id' => 2, 'name' => 'Jeddah warehouse', 'code' => 'jedah', 'qty' => 8, 'products' => 3]], $all['warehouses']);

        // the largest first; one row per warehouse and product, its lots added up
        $top = $all['items'][0];
        $this->assertEquals([1, 'Riyadh warehouse', 100, strtolower("PS{$this->tag}A"), 'طحين Flour (5kg*4pcs)', 34, 3, 31, 3, Carbon::today()->subDays(10)->toDateString(), 4, 10, $this->opsProduct->id, true],
            [$top['warehouseId'], $top['warehouse'], $top['productId'], $top['code'], $top['name'], $top['qty'], $top['reserved'], $top['available'], $top['lots'],
                $top['nearestExpiry'], $top['expiredQty'], $top['soonQty'], $top['opsProductId'], $top['inOps']]);

        // filters: a warehouse, what has expired, what expires within a month, a word of the name or the code
        $jeddah = $this->stock('warehouse=2');
        $this->assertEquals([[100, 7], [102, 2], [101, -1]], array_map(fn ($r) => [$r['productId'], $r['qty']], $jeddah['items']));
        $this->assertSame(['', false, 0, null], [$jeddah['items'][1]['code'], $jeddah['items'][1]['inOps'], $jeddah['items'][1]['lots'], $jeddah['items'][1]['nearestExpiry']]);
        $this->assertSame([[1, 100]], array_map(fn ($r) => [$r['warehouseId'], $r['productId']], $this->stock('expiry=expired')['items']));
        $this->assertSame([[1, 100]], array_map(fn ($r) => [$r['warehouseId'], $r['productId']], $this->stock('expiry=soon')['items']));
        $this->assertSame(2, $this->stock('q=flour')['total']);
        $this->assertSame(2, $this->stock('q='.strtoupper("ps{$this->tag}b"))['total']);
        // nearest expiry first; rows with no date go last, whichever way the list is sorted
        $byDate = fn (string $order) => array_map(fn ($r) => $r['nearestExpiry'], $this->stock("sort=nearestExpiry&order={$order}")['items']);
        $this->assertSame([Carbon::today()->subDays(10)->toDateString(), Carbon::today()->addDays(90)->toDateString(), Carbon::today()->addDays(200)->toDateString(), null, null], $byDate('asc'));
        $this->assertSame([null, null], array_slice($byDate('desc'), 3));
        $paged = $this->stock('pageSize=2&page=3');
        $this->assertSame([1, 3, 5], [count($paged['items']), $paged['pages'], $paged['total']]);

        // a movement in Odoo shows after the next run
        $this->odoo->write('stock.quant', 3, ['quantity' => 5.0]);
        $this->travel(2)->minutes();
        $this->sync();
        $after = $this->stock();
        $this->assertEquals([19, 32.5], [collect($after['items'])->firstWhere(fn ($r) => $r['warehouseId'] === 1 && $r['productId'] === 100)['qty'], $after['totals']['qty']]);

        // a view: the OPS inventory ledger was not touched, and nothing was written to Odoo
        $this->assertSame($ledger, InventoryBalance::count());
        $this->assertSame([], $this->odoo->writes);
    }
}
