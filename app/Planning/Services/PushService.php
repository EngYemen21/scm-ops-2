<?php

namespace App\Planning\Services;

use App\Planning\Models\Item;
use App\Planning\Models\Push;
use App\Planning\Models\Run;
use App\Planning\Support\Owned;
use App\Services\Master\ProductsService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

/**
 * Writes a run's suggested minimum / maximum to the products' reorder settings — only when a person asks for it,
 * only through the products service (`ProductsService::update`: its own validation, its audit of every field, with a
 * reason that names the run). OPS keeps one reorder minimum / maximum per product, so the value written for a product
 * is the sum of the suggestions of its warehouses in the run (the items chosen). What stood there before and what was
 * written are kept (planning_pushes), one row per product and push. Pushing does not change the run: a frozen run may
 * be pushed.
 */
class PushService
{
    public function __construct(private readonly ProductsService $products) {}

    /** @param string[] $itemIds stock items of the run (none = every stock item whose suggestion differs from the product) */
    public function push(AuthUser $user, Run $run, array $itemIds): array
    {
        Owned::refuse($run, 'run');
        if (! in_array($run->status, ['computed', 'frozen'], true)) {
            throw AppError::rule('PLANNING_RUN_NOT_COMPUTED', 'احسب التشغيل أولًا', 'Compute the run first');
        }
        $q = Item::where('run_id', $run->id)->where('kind', 'stock');
        $itemIds ? $q->whereIn('id', $itemIds) : $q->where('orderpoint_state', '!=', 'matches');
        $items = $q->orderBy('sku')->orderBy('warehouse_code')->orderBy('id')->get();
        if ($itemIds && $items->count() !== count(array_unique($itemIds))) {
            throw AppError::validation('PLANNING_ITEM_NOT_FOUND', 'بند غير موجود في هذا التشغيل أو ليس بند مخزون', 'An item is not a stock item of this run');
        }
        $out = [];
        foreach ($items->groupBy('product_id') as $productId => $group) {
            $min = (int) round($group->sum('suggested_min_qty'));
            $max = (int) round($group->sum('suggested_max_qty'));
            DB::transaction(function () use ($user, $run, $productId, $group, $min, $max, &$out) {
                $p = DB::table('products')->where('id', $productId)->lockForUpdate()->first(['id', 'sku', 'reorder_min', 'reorder_max']);
                if (! $p) {
                    return;
                }
                $warehouses = $group->pluck('warehouse_code')->filter()->implode('، ');
                $res = $this->products->update($user, $p->sku, ['reorderMin' => $min, 'reorderMax' => $max,
                    'reason' => "اقتراح تشغيل التوقع {$run->number} ({$warehouses}) · Suggested by forecast run {$run->number}"]);
                Push::create(['run_id' => $run->id, 'product_id' => $p->id, 'sku' => $p->sku, 'old_min' => $p->reorder_min, 'old_max' => $p->reorder_max, 'new_min' => $min, 'new_max' => $max,
                    'item_ids' => $group->pluck('id')->values()->all(), 'at' => now(), 'by' => $user->username, 'changed' => (int) ($res['changed'] ?? 0)]);
                $out[] = ['productId' => $p->id, 'sku' => $p->sku, 'oldMin' => $p->reorder_min, 'oldMax' => $p->reorder_max, 'newMin' => $min, 'newMax' => $max, 'changed' => (int) ($res['changed'] ?? 0)];
            });
        }

        return ['pushed' => count($out), 'products' => $out];
    }
}
