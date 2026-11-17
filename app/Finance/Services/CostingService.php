<?php

namespace App\Finance\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

/**
 * Inventory costing on the existing quantity ledger (moving weighted average per product, company level):
 *  - called by InventoryService::post() right after the movement row is written, in the same transaction;
 *  - stamps `unit_cost` / `total_cost` on every movement (valued ones) so cost of sales, returns and transfers can be
 *    traced per movement;
 *  - updates `products.avg_cost` on priced receipts (GRN at the PO price, opening balances) and on goods that come
 *    back at the cost they left at when the average moved meanwhile (transfer arrival, unload, delivery return) — the
 *    invariant is: stock on hand × average = the inventory account (FinanceDashboardService::reconciliation);
 *  - posts the journal of every movement that changes the company's stock position (receipt, issue, transfer legs);
 *    moves between bins of the same warehouse carry the cost for information and post nothing.
 * The movement → account mapping is the posting-rules table in docs/finance/ARCHITECTURE.ar.md §3.3.
 */
class CostingService
{
    /** Movement types that never post (internal relocation inside a warehouse, or not stock at all). */
    private const INTERNAL = ['putaway', 'pick', 'pack', 'move', 'restock', 'qtn', 'alloc', 'stg'];

    /** Outbound movement types valued at the `unitCost` the caller passes instead of the current average. */
    private const ISSUES_AT_GIVEN_COST = ['grnvoid'];

    public function __construct(private readonly LedgerService $ledger, private readonly AccountMap $map) {}

    /**
     * @param  array  $m  the post() payload; optional costing hints: `unitCost` (priced receipts), `costOrigin`
     *                    ('pod_return' | 'customer_return' | 'supplier_return' | 'internal'), `partner` {type,id}, `costCenter`
     */
    public function onMovement(?AuthUser $user, InventoryMovement $mv, array $m): void
    {
        $type = $mv->type;
        $inbound = $mv->dst_bin_id && ! $mv->src_bin_id;
        $outbound = $mv->src_bin_id && ! $mv->dst_bin_id;
        $internal = in_array($type, self::INTERNAL, true) || (! $inbound && ! $outbound);
        if ($type === 'dmg' && ! $inbound) {
            $internal = true; // return decision RET-01 → DMG-01 stays in stock
        }

        $product = Product::whereKey($mv->product_id)->lockForUpdate()->first();
        $qty = (int) $mv->qty;
        $unitCost = null;
        // a cost hint is honoured on receipts, and on the one issue that must leave at the cost it came in at (receipt void)
        $given = ($inbound || in_array($type, self::ISSUES_AT_GIVEN_COST, true)) && isset($m['unitCost']);
        if ($given) {
            $unitCost = round((float) $m['unitCost'], 4);
        }
        $avg = $product->avg_cost !== null ? (float) $product->avg_cost : null;
        $fallback = $avg ?? ($product->purchase_price !== null ? (float) $product->purchase_price : 0.0);

        if ($internal) {
            $mv->update(['unit_cost' => $fallback, 'total_cost' => null]);

            return;
        }
        if ($unitCost === null) {
            $unitCost = $fallback;
        }
        $total = round($unitCost * $qty, 2);

        // Moving average. Priced receipts (GRN, opening) re-average against the stock on hand before this receipt — and so
        // do goods that come back at the cost they LEFT at (transfer arrival, unload, delivery / customer return) when the
        // average moved while they were away: otherwise stock on hand × average stops equalling the inventory account.
        $backAtOwnCost = $inbound && $given && $avg !== null && abs($unitCost - $avg) >= 0.00005;
        $carried = null; // grnvoid only: what the inventory account can give back when it is not the receipt's own value
        if ($inbound && (in_array($type, ['grn', 'dmg', 'opening'], true) || $backAtOwnCost)) {
            $onHandAfter = (int) InventoryBalance::where('product_id', $product->id)->sum('on_hand');
            $before = max(0, $onHandAfter - $qty);
            $newAvg = $before > 0 && $avg !== null ? round((($before * $avg) + $total) / ($before + $qty), 4) : $unitCost;
            $product->update(['avg_cost' => $newAvg, 'avg_cost_at' => now()]);
        } elseif ($avg === null && $inbound) {
            $product->update(['avg_cost' => $unitCost, 'avg_cost_at' => now()]); // first valued receipt of any kind seeds the average
        } elseif ($type === 'grnvoid' && $avg !== null) {
            // a voided receipt leaves the average as if it had never been blended in (while stock remains to carry it)
            $onHandAfter = (int) InventoryBalance::where('product_id', $product->id)->sum('on_hand');
            $valueAfter = (($onHandAfter + $qty) * $avg) - $total;
            if ($onHandAfter > 0 && $valueAfter > 0) {
                $product->update(['avg_cost' => round($valueAfter / $onHandAfter, 4), 'avg_cost_at' => now()]);
            } else {
                // nothing (or not enough value) is left to carry the gap between the receipt's cost and the average: other
                // units were issued at the blended average meanwhile. The stock leaves at the average it is carried at and
                // the gap is a purchase price variance, so the inventory account still equals stock on hand × average.
                $carried = round($avg * $qty, 2);
            }
        }

        $mv->update(['unit_cost' => $unitCost, 'total_cost' => $total]);
        if ($total <= 0) {
            return; // nothing to post (zero-cost item): quantities are still tracked by the inventory ledger
        }
        $journal = $this->ledger->post($user, [
            'eventKey' => "mv:{$mv->id}",
            'source' => ['type' => $mv->reference_type ?: 'InventoryMovement', 'id' => $mv->reference_id, 'number' => $mv->reference_number ?: $mv->number],
            'memo' => $this->memo($mv, $product),
            'lines' => $this->lines($mv, $m, $product, $total, $inbound, $carried),
        ]);
        $mv->update(['journal_id' => $journal->id]);
    }

    /** @return array<int, array> debit / credit lines of the movement (see the posting-rules table) */
    private function lines(InventoryMovement $mv, array $m, Product $product, float $total, bool $inbound, ?float $carried = null): array
    {
        $wh = $inbound ? $mv->dst_warehouse_id : $mv->src_warehouse_id;
        $dims = ['productId' => $product->id, 'warehouseId' => $wh, 'costCenterId' => $this->map->forWarehouse($wh)?->id, 'referenceType' => 'InventoryMovement', 'referenceId' => $mv->id];
        $desc = "{$product->sku} × {$mv->qty} · {$mv->number}";
        $inv = fn (string $side) => [$side => $total, 'role' => 'inventory', 'description' => $desc] + $dims;
        $other = fn (string $side, string $role, array $extra = []) => [$side => $total, 'role' => $role, 'description' => $desc] + $extra + $dims;
        $partner = $m['partner'] ?? null;
        $origin = $m['costOrigin'] ?? null;

        return match ($mv->type) {
            'grn', 'dmg' => [$inv('debit'), $other('credit', 'grni')],
            'opening' => [$inv('debit'), $other('credit', 'opening_balance_equity')],
            'trf' => [$inv('debit'), $other('credit', 'inventory_in_transit')],
            'transit' => [$other('debit', 'inventory_in_transit'), $inv('credit')],
            'load' => [$other('debit', 'goods_in_transit'), $inv('credit')],
            'unload' => [$inv('debit'), $other('credit', 'goods_in_transit')],   // taken off the truck before dispatch: back at its load cost
            // a voided receipt reverses its accrual at the receipt cost; when the stock can no longer carry that cost
            // (see onMovement) inventory gives back what it holds and the rest is a purchase price variance
            'grnvoid' => $carried === null || abs($total - $carried) < 0.005
                ? [$other('debit', 'grni'), $inv('credit')]
                : [$other('debit', 'grni'), ['credit' => $carried] + $inv('credit'),
                    [($total > $carried ? 'credit' : 'debit') => round(abs($total - $carried), 2), 'role' => 'ppv', 'description' => "{$desc} · فرق تكلفة الاستلام الملغى عن المتوسط"] + $dims],
            'ret' => [$inv('debit'), $other('credit', match ($origin) {
                'pod_return' => 'goods_in_transit',      // undelivered goods back from the truck (never invoiced)
                'customer_return' => 'cogs',             // post-delivery return: reverse the cost of sales
                default => 'inventory_adjustment',       // supplier / internal returns created from nothing in RET-01
            })],
            'scrap' => [$other('debit', 'inventory_loss'), $inv('credit')],
            'supret' => [$partner && ($partner['type'] ?? null) === 'supplier'
                ? $other('debit', 'ap', ['partnerType' => 'supplier', 'partnerId' => $partner['id']])
                : $other('debit', 'inventory_loss'), $inv('credit')],
            'adj' => $inbound ? [$inv('debit'), $other('credit', 'inventory_adjustment')] : [$other('debit', 'inventory_adjustment'), $inv('credit')],
            default => $inbound ? [$inv('debit'), $other('credit', 'inventory_adjustment')] : [$other('debit', 'inventory_adjustment'), $inv('credit')],
        };
    }

    private function memo(InventoryMovement $mv, Product $product): string
    {
        $label = config("scm.MOVEMENT_LABELS.{$mv->type}.ar") ?? $mv->type;

        return "{$label} · {$product->name_ar} × {$mv->qty}".($mv->reference_number ? " · {$mv->reference_number}" : '');
    }

    /** Cost per unit the goods of a fulfilment order left the warehouse at (weighted over its load movements). */
    public function loadCost(string $foId, string $productId): ?float
    {
        $r = InventoryMovement::where('type', 'load')->where('reference_type', 'FulfillmentOrder')->where('reference_id', $foId)->where('product_id', $productId)
            ->selectRaw('coalesce(sum(total_cost), 0) as total, coalesce(sum(qty), 0) as qty')->first();

        return $r && (int) $r->qty > 0 ? round((float) $r->total / (int) $r->qty, 4) : null;
    }

    /**
     * Stock value per warehouse (Σ on_hand × avg_cost) — the sub-ledger the GL inventory account must equal. Products that
     * were never valued (stock from before the ledger, awaiting the cut-over command) count as 0 here and are reported apart.
     */
    public function valuation(?string $warehouseId = null): array
    {
        $q = InventoryBalance::query()->join('products', 'products.id', '=', 'inventory_balances.product_id')
            ->selectRaw('inventory_balances.warehouse_id, coalesce(sum(inventory_balances.on_hand), 0) as qty, coalesce(sum(inventory_balances.on_hand * coalesce(products.avg_cost, 0)), 0) as value, coalesce(sum(case when products.avg_cost is null then inventory_balances.on_hand else 0 end), 0) as unvalued_qty')
            ->groupBy('inventory_balances.warehouse_id');
        if ($warehouseId) {
            $q->where('inventory_balances.warehouse_id', $warehouseId);
        }

        return $q->get()->map(fn ($r) => ['warehouseId' => $r->warehouse_id, 'qty' => (int) $r->qty, 'value' => round((float) $r->value, 2), 'unvaluedQty' => (int) $r->unvalued_qty])->all();
    }

    public static function round4(float $n): float
    {
        return round($n, 4);
    }

    /** True when the current request is already inside a transaction (the costing hook relies on the caller's). */
    public static function inTransaction(): bool
    {
        return DB::transactionLevel() > 0;
    }
}
