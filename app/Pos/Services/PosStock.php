<?php

namespace App\Pos\Services;

use App\Models\Product;
use App\Pos\Models\PosOrder;
use App\Pos\Models\PosOrderLine;
use App\Pos\Models\PosOrderLineLot;
use App\Pos\Support\Money;
use App\Services\Inventory\InventoryService;
use App\Support\AppError;
use App\Support\AuthUser;

/**
 * The goods of the counter, moved ONLY through the inventory engine (`InventoryService::post`) — balances are never
 * touched here.
 *
 * A SALE takes stock the way fulfilment hands goods to a customer: from the allocatable rows of the register's
 * warehouse (no quarantine, no blocked row, no expired batch, no staging / returns / damaged area), first-expiry first,
 * each row locked, and leaves with a `load` movement. The cost engine values it at the moving average and moves that
 * value from inventory to goods-in-transit (Dr 1140 / Cr 1130), exactly as for a delivery; the sale's cost-of-sales
 * journal then takes it from goods-in-transit to cost of goods sold, as the delivery invoice does.
 *
 * A REFUND brings the goods back with a `ret` movement at the cost they left at (`customer_return`: Dr inventory /
 * Cr cost of goods sold), into the bin and batch they came from or into the returns area RET-01, as the register says.
 */
class PosStock
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * What each product of a sale can take from the warehouse right now (rows locked until the sale commits).
     *
     * @param  array<string, int>  $wanted  product id → quantity of the whole sale
     * @return array<string, int> product id → available
     */
    public function lockAvailable(string $warehouseId, array $wanted): array
    {
        $out = [];
        ksort($wanted); // always the same order: two sales of the same products cannot lock each other's rows crosswise
        foreach ($wanted as $productId => $qty) {
            $out[$productId] = (int) $this->inventory->allocRows($productId, $warehouseId, true)->sum(fn ($r) => $r->on_hand - $r->reserved);
        }

        return $out;
    }

    /**
     * Refuses a sale the shelf cannot cover, unless the register allows selling ahead of stock.
     *
     * @param  array<string, int>  $wanted
     * @param  array<string, int>  $available
     */
    public function assertCovered(array $wanted, array $available, bool $allowNegative): void
    {
        $short = [];
        foreach ($wanted as $productId => $qty) {
            if ($qty > ($available[$productId] ?? 0)) {
                $p = Product::find($productId, ['sku', 'name_ar', 'name_en']);
                $short[] = ['productId' => $productId, 'sku' => $p?->sku, 'nameAr' => $p?->name_ar, 'nameEn' => $p?->name_en, 'requested' => $qty, 'available' => $available[$productId] ?? 0];
            }
        }
        if ($short && ! $allowNegative) {
            $list = implode('، ', array_map(fn ($s) => "{$s['sku']} (المتاح {$s['available']} من {$s['requested']})", $short));
            throw AppError::rule('POS_STOCK_SHORT', "المخزون لا يكفي: {$list} — هذا الصندوق لا يسمح بالبيع بأكثر من الرصيد", 'Not enough stock for: '.implode(', ', array_map(fn ($s) => "{$s['sku']} ({$s['available']} of {$s['requested']})", $short)).' — this register does not sell beyond stock', ['lines' => $short]);
        }
    }

    /**
     * Takes up to `$qty` of the line's product out of the warehouse (FEFO), writing one `out` lot per movement.
     *
     * @return array{issued:int, cost:int} quantity issued and its cost in halalas (as the cost engine valued it)
     */
    public function issue(?AuthUser $user, string $txId, PosOrder $order, PosOrderLine $line, string $warehouseId, int $qty): array
    {
        $issued = 0;
        $cost = 0;
        if ($qty <= 0) {
            return ['issued' => 0, 'cost' => 0];
        }
        foreach ($this->inventory->allocRows($line->product_id, $warehouseId, true) as $row) {
            $take = min($qty - $issued, $row->on_hand - $row->reserved);
            if ($take <= 0) {
                continue;
            }
            $mv = $this->inventory->post($user, $txId, [
                'type' => 'load', 'productId' => $line->product_id, 'batchId' => $row->batch_id, 'qty' => $take,
                'from' => ['warehouseId' => $warehouseId, 'binId' => $row->bin_id],
                'reference' => ['type' => 'PosOrder', 'id' => $order->id, 'number' => $order->number],
                'note' => "بيع نقطة البيع {$order->number} · POS sale",
            ]);
            $mv->refresh();
            $lotCost = $mv->total_cost !== null ? Money::cents($mv->total_cost) : 0;
            PosOrderLineLot::create([
                'order_id' => $order->id, 'line_id' => $line->id, 'kind' => 'out', 'movement_id' => $mv->id, 'movement_number' => $mv->number, 'warehouse_id' => $warehouseId,
                'bin_id' => $row->bin_id, 'batch_id' => $row->batch_id, 'qty' => $take, 'returned_qty' => 0,
                'unit_cost' => $mv->unit_cost ?? 0, 'total_cost' => Money::str($lotCost),
            ]);
            $issued += $take;
            $cost += $lotCost;
            if ($issued >= $qty) {
                break;
            }
        }

        return ['issued' => $issued, 'cost' => $cost];
    }

    /**
     * Brings `$qty` of a sold line back: newest movement first, each unit into the bin and batch it left (restock
     * `shelf`) or into RET-01 (restock `returns`), at the cost it left at.
     *
     * @return int the cost given back, in halalas
     */
    public function giveBack(?AuthUser $user, string $txId, PosOrder $refund, PosOrderLine $refundLine, PosOrderLine $soldLine, int $qty, string $restock): int
    {
        $back = 0;
        $cost = 0;
        $lots = PosOrderLineLot::where('line_id', $soldLine->id)->where('kind', 'out')->whereColumn('returned_qty', '<', 'qty')
            ->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->get();
        foreach ($lots as $lot) {
            $take = min($qty - $back, $lot->qty - $lot->returned_qty);
            if ($take <= 0) {
                continue;
            }
            $binId = $restock === 'shelf' ? $lot->bin_id : $this->inventory->specialBin($lot->warehouse_id, 'RET-01')->id;
            $unitCost = (float) $lot->unit_cost;
            $mv = $this->inventory->post($user, $txId, [
                'type' => 'ret', 'productId' => $soldLine->product_id, 'batchId' => $lot->batch_id, 'qty' => $take,
                'to' => ['warehouseId' => $lot->warehouse_id, 'binId' => $binId], 'unitCost' => $unitCost, 'costOrigin' => 'customer_return',
                'reference' => ['type' => 'PosOrder', 'id' => $refund->id, 'number' => $refund->number],
                'note' => "مرتجع نقطة البيع {$refund->number} عن {$lot->movement_number} · POS refund",
            ]);
            $mv->refresh();
            $lotCost = $mv->total_cost !== null ? Money::cents($mv->total_cost) : 0;
            $lot->update(['returned_qty' => $lot->returned_qty + $take]);
            PosOrderLineLot::create([
                'order_id' => $refund->id, 'line_id' => $refundLine->id, 'kind' => 'back', 'movement_id' => $mv->id, 'movement_number' => $mv->number, 'warehouse_id' => $lot->warehouse_id,
                'bin_id' => $binId, 'batch_id' => $lot->batch_id, 'qty' => $take, 'returned_qty' => 0, 'unit_cost' => $unitCost, 'total_cost' => Money::str($lotCost), 'from_lot_id' => $lot->id,
            ]);
            $back += $take;
            $cost += $lotCost;
            if ($back >= $qty) {
                break;
            }
        }
        if ($back < $qty) {
            // the sold line's movements do not cover what is being returned: refuse rather than invent stock
            throw AppError::rule('POS_REFUND_STOCK_TRAIL', "لا تغطي حركات البيع الكمية المرتجعة ({$qty}) — راجع البند", "The sale's stock movements do not cover the returned quantity ({$qty})");
        }

        return $cost;
    }
}
