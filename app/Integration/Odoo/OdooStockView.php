<?php

namespace App\Integration\Odoo;

use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Models\Product;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Odoo's stock as Odoo holds it, read from the mirror: on hand per warehouse and product, with the lots and the
 * nearest expiry date. A VIEW of Odoo's figures — it is not the OPS inventory ledger and moves nothing in it.
 *
 * Only quants in a warehouse's internal locations count (the others are Odoo's books of what is at customers, at
 * suppliers or written off). Odoo keeps this stock per warehouse and lot; it has no shelf positions.
 */
class OdooStockView
{
    private const NEEDS = ['stock.quant', 'stock.location', 'stock.warehouse', 'stock.lot', 'product.product'];

    public function ready(): bool
    {
        return OdooModel::whereIn('model', self::NEEDS)->where('status', 'active')->where('full_state', 'done')->count() === count(self::NEEDS);
    }

    /** @param  array{warehouse?:?string, expiry?:?string}  $f  expiry: expired | soon (within 30 days) */
    public function list(Paging $page, array $f): array
    {
        if (! $this->ready()) {
            return $page->wrap([], 0) + ['ready' => false, 'warehouses' => [], 'totals' => null, 'asOf' => null]; // half a copy would show wrong totals
        }
        $data = $this->build();
        $rows = $data['rows'];
        if (! empty($f['warehouse'])) {
            $rows = array_filter($rows, fn ($r) => (string) $r['warehouseId'] === (string) $f['warehouse']);
        }
        if (($f['expiry'] ?? null) === 'expired') {
            $rows = array_filter($rows, fn ($r) => $r['expiredQty'] > 0);
        } elseif (($f['expiry'] ?? null) === 'soon') {
            $rows = array_filter($rows, fn ($r) => $r['soonQty'] > 0);
        }
        if ($page->q) {
            $q = mb_strtolower($page->q);
            $rows = array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['code'].' '.$r['name']), $q));
        }
        $rows = array_values($rows);
        $key = in_array($page->sort, ['code', 'name', 'warehouse', 'qty', 'reserved', 'available', 'nearestExpiry', 'lots', 'expiredQty', 'soonQty'], true) ? $page->sort : 'qty';
        $dir = $key === $page->sort && $page->order === 'asc' ? 1 : -1;
        usort($rows, function ($a, $b) use ($key, $dir) {
            [$x, $y] = [$a[$key] ?? null, $b[$key] ?? null];
            if ($x === null || $y === null) {
                return (($x === null) <=> ($y === null)) ?: strcmp($a['code'], $b['code']); // no value: last, either way
            }

            return ($dir * ($x <=> $y)) ?: strcmp($a['code'], $b['code']);
        });

        // "as of" is the last time a run asked Odoo about its stock — also when it found nothing new and the picture was kept
        return $page->wrap(array_slice($rows, ($page->page - 1) * $page->pageSize, $page->pageSize), count($rows))
            + ['ready' => true, 'warehouses' => $data['warehouses'], 'totals' => $data['totals'], 'asOf' => OdooModel::where('model', 'stock.quant')->first()?->last_synced_at?->toJSON()];
    }

    /**
     * The whole picture (a few thousand rows at most), rebuilt when a run changed any of the models it is made of or
     * when the OPS product list changed; otherwise the last one is served.
     */
    private function build(): array
    {
        $stamp = OdooModel::whereIn('model', self::NEEDS)->max('last_changed_at').'|'.Product::count();

        return Cache::remember('odoo:stock-view:'.sha1($stamp), now()->addMinutes(10), function () {
            $get = fn (string $model, array $columns = ['odoo_id', 'display_name', 'data', 'active']) => OdooRecord::where('model', $model)->whereNull('deleted_at')->get($columns);
            $warehouses = [];
            foreach ($get('stock.warehouse') as $w) {
                $warehouses[$w->odoo_id] = ['id' => $w->odoo_id, 'name' => (string) ($w->data['name'] ?? $w->display_name), 'code' => $w->data['code'] ?? null, 'qty' => 0.0, 'products' => 0];
            }
            $locations = [];
            foreach ($get('stock.location') as $l) {
                if (($l->data['usage'] ?? null) === 'internal' && ! empty($l->data['warehouse_id'][0])) {
                    $locations[$l->odoo_id] = (int) $l->data['warehouse_id'][0];
                }
            }
            $expiry = [];
            foreach ($get('stock.lot', ['odoo_id', 'data']) as $lot) {
                if (! empty($lot->data['expiration_date'])) {
                    $expiry[$lot->odoo_id] = substr((string) $lot->data['expiration_date'], 0, 10);
                }
            }
            $codes = [];
            foreach ($get('product.product', ['odoo_id', 'data']) as $p) {
                $codes[$p->odoo_id] = trim((string) ($p->data['default_code'] ?? ''));
            }
            $ops = Product::pluck('id', 'sku')->mapWithKeys(fn ($id, $sku) => [mb_strtoupper($sku) => $id])->all();
            $today = Carbon::today()->toDateString();
            $soon = Carbon::today()->addDays(30)->toDateString();
            $rows = [];
            foreach (OdooRecord::where('model', 'stock.quant')->whereNull('deleted_at')->lazy(2000) as $q) {
                $d = $q->data;
                $wh = $locations[$d['location_id'][0] ?? 0] ?? null;
                $pid = (int) ($d['product_id'][0] ?? 0);
                $qty = (float) ($d['quantity'] ?? 0);
                if (! $wh || ! $pid || $qty == 0.0) {
                    continue;
                }
                $k = "{$wh}:{$pid}";
                $rows[$k] ??= ['warehouseId' => $wh, 'warehouse' => $warehouses[$wh]['name'] ?? (string) $wh, 'productId' => $pid, 'code' => $codes[$pid] ?? '',
                    'name' => trim(preg_replace('/^\s*\[[^\]]+\]\s*/u', '', (string) ($d['product_id'][1] ?? ''))), 'qty' => 0.0, 'reserved' => 0.0, 'lots' => 0,
                    'nearestExpiry' => null, 'expiredQty' => 0.0, 'soonQty' => 0.0, 'opsProductId' => null, 'inOps' => false];
                $r = &$rows[$k];
                $r['qty'] += $qty;
                $r['reserved'] += (float) ($d['reserved_quantity'] ?? 0);
                $lot = (int) ($d['lot_id'][0] ?? 0);
                if ($lot) {
                    $r['lots']++;
                    $date = $expiry[$lot] ?? null;
                    if ($date && $qty > 0) {
                        $r['nearestExpiry'] = $r['nearestExpiry'] === null || $date < $r['nearestExpiry'] ? $date : $r['nearestExpiry'];
                        $r['expiredQty'] += $date < $today ? $qty : 0;
                        $r['soonQty'] += $date >= $today && $date <= $soon ? $qty : 0;
                    }
                }
                unset($r);
            }
            $totals = ['qty' => 0.0, 'products' => 0, 'expiredQty' => 0.0, 'soonQty' => 0.0, 'negative' => 0, 'notInOps' => 0];
            $seen = [];
            foreach ($rows as &$r) {
                $r['qty'] = round($r['qty'], 3);
                $r['reserved'] = round($r['reserved'], 3);
                $r['available'] = round($r['qty'] - $r['reserved'], 3);
                $r['expiredQty'] = round($r['expiredQty'], 3);
                $r['soonQty'] = round($r['soonQty'], 3);
                $r['opsProductId'] = $r['code'] !== '' ? ($ops[mb_strtoupper($r['code'])] ?? null) : null;
                $r['inOps'] = $r['opsProductId'] !== null;
                if (isset($warehouses[$r['warehouseId']])) {
                    $warehouses[$r['warehouseId']]['qty'] = round($warehouses[$r['warehouseId']]['qty'] + $r['qty'], 3);
                    $warehouses[$r['warehouseId']]['products']++;
                }
                $totals['qty'] += $r['qty'];
                $totals['expiredQty'] += $r['expiredQty'];
                $totals['soonQty'] += $r['soonQty'];
                $totals['negative'] += $r['qty'] < 0 ? 1 : 0;
                if (! isset($seen[$r['productId']])) {
                    $seen[$r['productId']] = true;
                    $totals['products']++;
                    $totals['notInOps'] += $r['inOps'] ? 0 : 1;
                }
            }
            unset($r);
            $totals = array_map(fn ($v) => is_float($v) ? round($v, 3) : $v, $totals);

            return ['rows' => array_values($rows), 'warehouses' => array_values($warehouses), 'totals' => $totals];
        });
    }
}
