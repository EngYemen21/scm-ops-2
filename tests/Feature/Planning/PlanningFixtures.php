<?php

namespace Tests\Feature\Planning;

use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Services\ExternalRefs;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Pricing\Models\SupplierPrice;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * What the planning stories build for themselves (docs/odoo/TEAM-BRIEFS.md rule 13): their own products, customers,
 * supplier and prices, their own records of the Odoo copy (ids from 970000 up, a warehouse of their own mapped to RYD)
 * and their own history. Assertions are made on these records only.
 */
trait PlanningFixtures
{
    private static int $seq = 0;

    protected static function code(string $prefix): string
    {
        return $prefix.'-'.self::uid().'-'.(++self::$seq);
    }

    protected function ryd(): Warehouse
    {
        return Warehouse::where('code', 'RYD')->firstOrFail();
    }

    protected function product(string $name, array $more = []): Product
    {
        return Product::create($more + ['sku' => self::code('PLN'), 'name_ar' => $name, 'name_en' => $name, 'storage_class' => 'ambient', 'tracks_expiry' => false, 'active' => true]);
    }

    protected function customer(string $name): Customer
    {
        return Customer::create(['code' => self::code('CUS-PL'), 'name_ar' => $name, 'name_en' => $name, 'zone' => 'الرياض', 'contact' => '0550000000', 'terms' => 'نقدي / محفظة', 'active' => true]);
    }

    protected function supplier(string $name, int $leadDays = 9): Supplier
    {
        return Supplier::create(['code' => self::code('SUP-PL'), 'name_ar' => $name, 'name_en' => $name, 'lead_days' => $leadDays, 'score' => 80, 'status' => 'active', 'active' => true]);
    }

    protected function supplierPrice(Supplier $s, Product $p, float $price, ?int $leadDays, float $minQty = 0): void
    {
        SupplierPrice::create(['supplier_id' => $s->id, 'product_id' => $p->id, 'price' => $price, 'currency' => 'SAR', 'discount_pct' => 0, 'min_qty' => $minQty, 'lead_days' => $leadDays, 'sequence' => 1, 'active' => true]);
    }

    /** Stock on the shelf through the inventory service (an opening balance), so the ledger still reconciles. */
    protected function stock(Product $p, int $qty): void
    {
        $inv = $this->app->make(InventoryService::class);
        $wh = $this->ryd();
        $bin = $inv->bin($wh->id, 'A-07-1-B1');
        DB::transaction(fn () => $inv->post(null, null, ['type' => 'opening', 'productId' => $p->id, 'qty' => $qty, 'to' => ['warehouseId' => $wh->id, 'binId' => $bin->id],
            'reference' => ['type' => 'Test', 'number' => 'T-PLAN']]));
    }

    /** An OPS order of a customer (completed, so nothing is booked) on a day, written as sales would keep it. */
    protected function opsOrder(Customer $c, Product $p, string $day, int $qty, string $status = 'completed'): SalesOrder
    {
        $so = SalesOrder::create(['number' => self::code('SO-PL'), 'customer_id' => $c->id, 'warehouse_id' => $this->ryd()->id, 'date' => "{$day} 09:00:00", 'due_date' => "{$day} 09:00:00", 'status' => $status]);
        SalesOrderLine::create(['so_id' => $so->id, 'line_no' => 1, 'product_id' => $p->id, 'qty' => $qty, 'price' => 10, 'delivered_qty' => $status === 'completed' ? $qty : 0]);

        return $so;
    }

    protected function link(string $entity, int $odooId, string $opsId, ?string $code = null): void
    {
        $this->app->make(ExternalRefs::class)->link('odoo', $entity, (string) $odooId, $opsId, $code, null, 'test');
    }

    protected function rec(string $model, int $id, array $data, ?bool $active = null): void
    {
        $data = ($active === null ? [] : ['active' => $active]) + $data + ['write_date' => '2026-09-01 08:00:00'];
        OdooRecord::updateOrCreate(['model' => $model, 'odoo_id' => $id], ['display_name' => is_string($data['name'] ?? null) ? $data['name'] : null, 'write_date' => $data['write_date'], 'active' => $active,
            'data' => $data, 'hash' => sha1(json_encode($data)), 'first_seen_at' => now(), 'changed_at' => now(), 'deleted_at' => null]);
    }

    protected function odooModel(string $model): void
    {
        OdooModel::updateOrCreate(['model' => $model], ['status' => 'active', 'full_state' => 'done', 'has_active' => false]);
    }

    /** The Odoo warehouse of the stories (970001) with its stock location, and the customers' location. */
    protected function odooWarehouse(): void
    {
        $this->rec('stock.warehouse', 970001, ['name' => 'مستودع اختبار التخطيط', 'code' => 'PLW', 'lot_stock_id' => [970011, 'PLW/Stock']], true);
        $this->rec('stock.location', 970011, ['name' => 'Stock', 'usage' => 'internal', 'warehouse_id' => [970001, 'مستودع اختبار التخطيط']], true);
        $this->rec('stock.location', 970012, ['name' => 'Customers', 'usage' => 'customer', 'warehouse_id' => false], true);
    }

    /** A done move line of the Odoo copy: to the customer (delivery) or back from him (return). */
    protected function odooMove(int $id, int $odooProduct, int $partner, string $at, float $qty, bool $return = false): void
    {
        $this->rec('stock.picking', $id, ['name' => "PLW/OUT/{$id}", 'state' => 'done', 'partner_id' => [$partner, 'عميل'], 'date_done' => $at]);
        $this->rec('stock.move.line', $id, ['state' => 'done', 'picking_id' => [$id, "PLW/OUT/{$id}"], 'product_id' => [$odooProduct, 'منتج'], 'quantity' => $qty, 'quantity_product_uom' => $qty,
            'product_uom_id' => [1, 'Units'], 'date' => $at, 'location_id' => $return ? [970012, 'Customers'] : [970011, 'PLW/Stock'], 'location_dest_id' => $return ? [970011, 'PLW/Stock'] : [970012, 'Customers']]);
    }

    /** Waits for a computation the way the page does: pushes it on until it is computed. */
    protected function computed(string $user, string $runId): array
    {
        $run = $this->expectOk($this->postAs($user, "/api/planning/runs/{$runId}/compute"));
        for ($i = 0; $i < 30 && $run['status'] === 'computing'; $i++) {
            $run = $this->expectOk($this->postAs($user, "/api/planning/runs/{$runId}/advance"));
        }
        $this->assertSame('computed', $run['status'], json_encode($run['lastError'] ?? null));

        return $run;
    }

    /** The stock item of a product in a run. */
    protected function itemOf(string $user, string $runId, Product $p): array
    {
        $list = $this->expectOk($this->getAs($user, "/api/planning/runs/{$runId}/items?q=".urlencode($p->sku)));
        $this->assertCount(1, $list['items'], "one stock item for {$p->sku}");

        return $this->expectOk($this->getAs($user, "/api/planning/runs/{$runId}/items/{$list['items'][0]['id']}"));
    }
}
