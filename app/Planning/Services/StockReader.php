<?php

namespace App\Planning\Services;

use App\Planning\Support\Clock;
use App\Services\Procurement\ProcurementHelpers;
use Illuminate\Support\Facades\DB;

/**
 * What OPS holds and has booked for a set of products, read the way the inventory and procurement sections count it:
 *
 *   on hand     the balances of the warehouse that may be used (not in quarantine, not blocked) —
 *               `InventoryBalance` as procurement's own replenishment reads it;
 *   booked in   the open quantity of each line of an open purchase order (approved, sent, confirmed, partly received),
 *               `ProcurementHelpers::openQty` (ordered − received − damaged, rejected per policy), due on the order's
 *               due date;
 *   booked out  what open sales orders still have to take from the warehouse: ordered − delivered on orders that are
 *               confirmed and not loaded yet (from the moment an order is loaded its goods have left the shelves and
 *               are no longer on hand), due on the order's due date (or its date).
 * A booked document whose day is before the forecast starts counts in the first period (it is late, it is expected
 * now); one after the forecast ends is outside the run.
 */
class StockReader
{
    /** Sales order states whose goods are still on the shelves of the warehouse. */
    public const OUT_STATES = ['confirmed', 'reserved', 'allocated', 'preparing', 'picking', 'picked', 'packed', 'readydisp'];

    public const IN_STATES = ['approved', 'sent', 'confirmed', 'partial'];

    /** @return array<string, array<string, float>> product → warehouse → on hand */
    public function onHand(array $products, array $warehouses): array
    {
        $out = [];
        $rows = DB::table('inventory_balances')->whereIn('product_id', $products)->whereIn('warehouse_id', $warehouses)->where('quarantine', false)->where('blocked', false)
            ->groupBy('product_id', 'warehouse_id')->selectRaw('product_id, warehouse_id, COALESCE(SUM(on_hand), 0) AS qty')->get();
        foreach ($rows as $r) {
            $out[(string) $r->product_id][(string) $r->warehouse_id] = (float) $r->qty;
        }

        return $out;
    }

    /**
     * @return array{in:array<string, array<string, array<int, array{day:string, qty:float, ref:string}>>>, out:array<string, array<string, array<int, array{day:string, qty:float, ref:string}>>>}
     */
    public function booked(array $products, array $warehouses, string $anchor): array
    {
        $in = $out = [];
        $staysOpen = ProcurementHelpers::rejectedStaysOpen();
        $lines = DB::table('po_lines as l')->join('purchase_orders as po', 'po.id', '=', 'l.po_id')->whereIn('l.product_id', $products)->whereIn('po.warehouse_id', $warehouses)
            ->whereIn('po.status', self::IN_STATES)->orderBy('po.number')->orderBy('l.line_no')
            ->get(['l.product_id', 'po.warehouse_id', 'po.number', 'po.due_date', 'l.qty', 'l.received_qty', 'l.damaged_qty', 'l.rejected_qty']);
        foreach ($lines as $l) {
            $open = ProcurementHelpers::openQty($l, $staysOpen);
            if ($open > 0) {
                $in[(string) $l->product_id][(string) $l->warehouse_id][] = ['day' => Clock::day($l->due_date ? substr((string) $l->due_date, 0, 19) : null) ?? $anchor, 'qty' => (float) $open, 'ref' => (string) $l->number];
            }
        }
        $lines = DB::table('sales_order_lines as l')->join('sales_orders as so', 'so.id', '=', 'l.so_id')->whereIn('l.product_id', $products)->whereIn('so.warehouse_id', $warehouses)
            ->whereIn('so.status', self::OUT_STATES)->orderBy('so.number')->orderBy('l.line_no')
            ->get(['l.product_id', 'so.warehouse_id', 'so.number', 'so.due_date', 'so.date', 'l.qty', 'l.delivered_qty']);
        foreach ($lines as $l) {
            $open = (float) $l->qty - (float) $l->delivered_qty;
            if ($open > 0) {
                $at = $l->due_date ?? $l->date;
                $out[(string) $l->product_id][(string) $l->warehouse_id][] = ['day' => Clock::day($at ? substr((string) $at, 0, 19) : null) ?? $anchor, 'qty' => $open, 'ref' => (string) $l->number];
            }
        }

        return ['in' => $in, 'out' => $out];
    }

    /** @return array<string, object> product id → its master data (names, category, reorder settings, pack) */
    public function products(array $ids): array
    {
        return DB::table('products')->whereIn('id', $ids)->get(['id', 'sku', 'name_ar', 'name_en', 'category_id', 'reorder_min', 'reorder_max', 'pack_qty', 'purchase_price', 'active'])
            ->keyBy(fn ($p) => (string) $p->id)->all();
    }

    /** @return array<string, string> product / customer id → its criticality (planning's own attribute; no row = normal) */
    public function criticality(string $entity, array $ids): array
    {
        return DB::table('planning_attributes')->where('entity', $entity)->whereIn('entity_id', $ids)->pluck('criticality', 'entity_id')->map(fn ($v) => (string) $v)->all();
    }
}
