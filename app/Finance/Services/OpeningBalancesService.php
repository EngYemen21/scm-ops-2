<?php

namespace App\Finance\Services;

use App\Finance\Models\Account;
use App\Finance\Models\Journal;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cut-over: value the stock that existed before the ledger (products with on-hand quantity and no average cost yet)
 * at the unit cost the CFO approves — by default the product's purchase price — with ONE opening journal
 * (inventory per warehouse ↔ opening balance equity). Dry-run by default; nothing posts without `confirm`.
 * Runs once per product (a product already valued is skipped), so re-running is safe.
 */
class OpeningBalancesService
{
    public function __construct(private readonly LedgerService $ledger, private readonly AccountMap $map, private readonly AuditService $audit) {}

    /** @return array{asOf: string, rows: array, total: float, skipped: array, journal?: string, posted: bool} */
    public function valueInventory(?AuthUser $user, ?string $asOf, bool $confirm): array
    {
        $date = $asOf ? Carbon::parse($asOf)->startOfDay() : now()->startOfDay();
        $rows = [];
        $skipped = [];
        $perWarehouse = [];
        $sums = InventoryBalance::query()->join('products', 'products.id', '=', 'inventory_balances.product_id')->whereNull('products.avg_cost')->where('inventory_balances.on_hand', '>', 0)
            ->selectRaw('inventory_balances.product_id, inventory_balances.warehouse_id, products.sku, products.name_ar, products.purchase_price, coalesce(sum(inventory_balances.on_hand), 0) as qty')
            ->groupBy('inventory_balances.product_id', 'inventory_balances.warehouse_id', 'products.sku', 'products.name_ar', 'products.purchase_price')->get();
        foreach ($sums as $r) {
            $price = $r->purchase_price !== null ? (float) $r->purchase_price : null;
            if ($price === null || $price <= 0) {
                $skipped[] = ['sku' => $r->sku, 'nameAr' => $r->name_ar, 'qty' => (int) $r->qty, 'reason' => 'no purchase price'];

                continue;
            }
            $value = round($price * (int) $r->qty, 2);
            $rows[] = ['productId' => $r->product_id, 'warehouseId' => $r->warehouse_id, 'sku' => $r->sku, 'nameAr' => $r->name_ar, 'qty' => (int) $r->qty, 'unitCost' => $price, 'value' => $value];
            $perWarehouse[$r->warehouse_id] = round(($perWarehouse[$r->warehouse_id] ?? 0) + $value, 2);
        }
        $total = round(array_sum(array_column($rows, 'value')), 2);
        $out = ['asOf' => $date->toDateString(), 'rows' => $rows, 'total' => $total, 'perWarehouse' => $perWarehouse, 'skipped' => $skipped, 'posted' => false];
        if (! $confirm || ! $rows) {
            return $out;
        }

        return DB::transaction(function () use ($user, $date, $rows, $perWarehouse, $total, $out) {
            if (Journal::where('event_key', 'opening:inventory:'.$date->toDateString())->exists()) {
                throw AppError::conflict('OPENING_EXISTS', "قيد افتتاحي للمخزون بتاريخ {$date->toDateString()} موجود — اختر تاريخًا آخر أو راجعه", 'Opening journal exists for that date');
            }
            $lines = [];
            foreach ($perWarehouse as $whId => $value) {
                $wh = Warehouse::find($whId);
                $lines[] = ['role' => 'inventory', 'debit' => $value, 'description' => 'رصيد مخزون افتتاحي — '.($wh?->code ?? $whId), 'warehouseId' => $whId, 'costCenterId' => $this->map->forWarehouse($whId)?->id];
            }
            $lines[] = ['role' => 'opening_balance_equity', 'credit' => $total, 'description' => 'تقييم المخزون القائم عند بدء الدفاتر'];
            $j = $this->ledger->post($user, ['eventKey' => 'opening:inventory:'.$date->toDateString(), 'date' => $date, 'kind' => 'opening', 'source' => ['type' => 'OpeningBalance', 'number' => 'INVENTORY'], 'memo' => 'رصيد افتتاحي للمخزون بسعر الشراء المعتمد', 'lines' => $lines]);
            foreach (collect($rows)->groupBy('productId') as $productId => $g) {
                Product::whereKey($productId)->update(['avg_cost' => $g->first()['unitCost'], 'avg_cost_at' => now()]);
            }
            $this->audit->log($user, ['action' => 'OPENING.INVENTORY', 'entityType' => 'Journal', 'entityId' => $j->id, 'entityNumber' => $j->number, 'newValue' => ['products' => count(collect($rows)->groupBy('productId')), 'total' => $total, 'asOf' => $date->toDateString()]]);

            return $out + ['journal' => $j->number, 'posted' => true];
        });
    }

    /** Opening balances of other accounts: one approved manual-style journal against opening balance equity. */
    public function postBalances(AuthUser $user, string $date, array $lines, string $memo = 'أرصدة افتتاحية'): array
    {
        return DB::transaction(function () use ($user, $date, $lines, $memo) {
            $equity = $this->map->role('opening_balance_equity');
            $sumD = 0.0;
            $sumC = 0.0;
            $out = [];
            foreach ($lines as $l) {
                $out[] = $l;
                $sumD += (float) ($l['debit'] ?? 0);
                $sumC += (float) ($l['credit'] ?? 0);
            }
            $diff = round($sumD - $sumC, 2);
            if ($diff > 0) {
                $out[] = ['account' => $equity->id, 'credit' => $diff, 'description' => 'موازنة الأرصدة الافتتاحية'];
            } elseif ($diff < 0) {
                $out[] = ['account' => $equity->id, 'debit' => -$diff, 'description' => 'موازنة الأرصدة الافتتاحية'];
            }
            $j = $this->ledger->post($user, ['date' => $date, 'kind' => 'opening', 'source' => ['type' => 'OpeningBalance', 'number' => 'ACCOUNTS'], 'memo' => $memo, 'lines' => $out]);

            return $this->ledger->get($j->id);
        });
    }

    public static function equityAccount(): ?Account
    {
        return Account::where('role', 'opening_balance_equity')->first();
    }
}
