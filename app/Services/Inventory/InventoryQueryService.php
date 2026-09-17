<?php

namespace App\Services\Inventory;

use App\Models\Batch;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StagingEntry;
use App\Models\TransferLine;
use App\Models\Warehouse;
use App\Services\Core\SettingsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Read-only inventory views (balances, ledger, batches, staging, traceability). Never mutates — the engine does. */
class InventoryQueryService
{
    public const STAGING_BINS = ['STG-IN', 'STG-OUT', 'PACK'];

    private const BALANCE_RELATIONS = ['product.category', 'warehouse', 'bin.zone', 'bin.rack', 'batch'];

    private const LEDGER_RELATIONS = ['product', 'batch', 'srcBin.zone', 'srcBin.warehouse', 'dstBin.zone', 'dstBin.warehouse'];

    public function __construct(private readonly InventoryService $inv, private readonly SettingsService $settings) {}

    private function expiringDays(): int
    {
        return (int) $this->settings->get('inventory.expiringSoonDays') ?: 30;
    }

    /** Whole days from today to the expiry date (negative = expired); null when the batch has no expiry. */
    public static function daysTo(?Carbon $expiry, ?Carbon $today = null): ?int
    {
        $today ??= Carbon::today();

        return $expiry ? (int) floor(($expiry->getTimestamp() - $today->getTimestamp()) / 86400) : null;
    }

    /** Availability of one row as the engine sees it (0 for quarantine/blocked/expired/non-storage rows). */
    public function rowAvailable(InventoryBalance $r, ?Carbon $today = null): int
    {
        $today ??= Carbon::today();
        if ($r->quarantine || $r->blocked) {
            return 0;
        }
        if (in_array($r->bin->zone->type, InventoryService::NON_STORAGE_ZONES, true)) {
            return 0;
        }
        if ($r->batch?->expiry_date && $r->batch->expiry_date->lt($today)) {
            return 0;
        }

        return max(0, $r->on_hand - $r->reserved);
    }

    /** @return array{daysToExpiry:?int, flags:string[], status:string} */
    private function balanceStatus(InventoryBalance $r, int $expDays, Carbon $today): array
    {
        $d = self::daysTo($r->batch?->expiry_date, $today);
        $zoneType = $r->bin->zone->type;
        $flags = [];
        if ($r->quarantine || $zoneType === 'quarantine') {
            $flags[] = 'quarantine';
        }
        if ($r->blocked) {
            $flags[] = 'blocked';
        }
        if ($d !== null && $d < 0) {
            $flags[] = 'expired';
        } elseif ($d !== null && $d <= $expDays) {
            $flags[] = 'expiring';
        }
        if (in_array($zoneType, InventoryService::NON_STORAGE_ZONES, true) && $zoneType !== 'quarantine') {
            $flags[] = $zoneType;
        }
        if ($r->on_hand === 0) {
            $flags[] = 'zero';
        }
        $status = match (true) {
            in_array('expired', $flags, true) => 'expired',
            in_array('quarantine', $flags, true) => 'quarantine',
            in_array('expiring', $flags, true) => 'expiring',
            $r->on_hand === 0 => 'zero',
            default => 'available',
        };

        return ['daysToExpiry' => $d, 'flags' => $flags, 'status' => $status];
    }

    private function mapBalance(InventoryBalance $r, int $expDays, Carbon $today): array
    {
        $s = $this->balanceStatus($r, $expDays, $today);
        $category = $r->product->category;

        return [
            'id' => $r->id, 'productId' => $r->product_id, 'sku' => $r->product->sku, 'nameAr' => $r->product->name_ar, 'nameEn' => $r->product->name_en,
            'storageClass' => $r->product->storage_class,
            'category' => $category ? ['code' => $category->code, 'nameAr' => $category->name_ar, 'nameEn' => $category->name_en] : null,
            'warehouse' => $r->warehouse->code, 'warehouseId' => $r->warehouse_id, 'zone' => $r->bin->zone->code, 'zoneType' => $r->bin->zone->type,
            'rack' => $r->bin->rack?->code ?: null, 'bin' => $r->bin->code, 'binId' => $r->bin_id, 'binStatus' => $r->bin->status,
            'batchId' => $r->batch_id, 'batch' => $r->batch?->batch_no ?: null, 'expiry' => $r->batch?->expiry_date?->toJSON(), 'daysToExpiry' => $s['daysToExpiry'],
            'onHand' => $r->on_hand, 'reserved' => $r->reserved, 'allocated' => $r->allocated, 'available' => $this->rowAvailable($r, $today),
            'quarantine' => $r->quarantine, 'blocked' => $r->blocked, 'flags' => $s['flags'], 'status' => $s['status'], 'version' => $r->version,
            'updatedAt' => $r->updated_at?->toJSON(),
        ];
    }

    // ───────────── balances ─────────────

    /** @param  array{warehouse?:?string, sku?:?string, zone?:?string, category?:?string, storageClass?:?string, status?:?string}  $f */
    public function balances(Paging $page, array $f): array
    {
        $today = Carbon::today();
        $expDays = $this->expiringDays();
        $query = InventoryBalance::query()->from('inventory_balances as b')->select('b.*')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->join('bins as bn', 'bn.id', '=', 'b.bin_id')
            ->join('zones as z', 'z.id', '=', 'bn.zone_id')
            ->leftJoin('batches as bt', 'bt.id', '=', 'b.batch_id')
            ->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id');
        if (! empty($f['warehouse'])) {
            $query->where('w.code', mb_strtoupper($f['warehouse']));
        }
        if (! empty($f['zone'])) {
            $query->where('z.code', mb_strtoupper($f['zone']));
        }
        if (! empty($f['category'])) {
            $query->where('c.code', $f['category']);
        }
        if (! empty($f['storageClass'])) {
            $query->where('p.storage_class', $f['storageClass']);
        }
        if (! empty($f['sku'])) {
            $query->where('p.sku', $f['sku']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $query->where(fn ($w) => $w->where('p.sku', 'like', $like)->orWhere('p.name_ar', 'like', $like)->orWhere('p.name_en', 'like', $like)
                ->orWhere('bn.code', 'like', $like)->orWhere('bt.batch_no', 'like', $like));
        }
        switch ($f['status'] ?? null) {
            case 'available':
                $query->where('b.quarantine', false)->where('b.blocked', false)->whereNotIn('z.type', InventoryService::NON_STORAGE_ZONES)
                    ->where(fn ($w) => $w->whereNull('b.batch_id')->orWhereNull('bt.expiry_date')->orWhere('bt.expiry_date', '>=', $today))
                    ->where('b.on_hand', '>', 0);
                break;
            case 'quarantine':
                $query->where(fn ($w) => $w->where('b.quarantine', true)->orWhere('z.type', 'quarantine'));
                break;
            case 'expired':
                $query->where('bt.expiry_date', '<', $today);
                break;
            case 'expiring':
                $query->whereBetween('bt.expiry_date', [$today, $today->copy()->addDays($expDays)]);
                break;
            case 'zero':
                $query->where('b.on_hand', 0);
                break;
        }
        match ($page->sort) {
            'onHand' => $query->orderBy('b.on_hand', $page->order),
            // PostgreSQL semantics of the reference: NULLs last when ascending, first when descending
            'expiry' => $query->orderByRaw('bt.expiry_date IS NULL '.$page->order)->orderBy('bt.expiry_date', $page->order),
            'bin' => $query->orderBy('bn.code', $page->order),
            default => $query->orderBy('p.sku')->orderBy('bn.code'),
        };
        $query->orderBy('b.id');

        $total = (clone $query)->toBase()->getCountForPagination();
        $rows = $query->with(self::BALANCE_RELATIONS)->forPage($page->page, $page->pageSize)->get()
            ->map(fn (InventoryBalance $r) => $this->mapBalance($r, $expDays, $today))
            // "available" is a row-level rule (reserved may equal onHand) — refine after the DB pre-filter
            ->filter(fn (array $r) => ($f['status'] ?? null) !== 'available' || $r['available'] > 0);

        return $page->wrap($rows->values()->all(), $total);
    }

    /** Per-warehouse totals. `value` (Σ onHand × purchasePrice) is included only for users holding inventory.view_cost. */
    public function summary(AuthUser $user, ?string $warehouse = null): array
    {
        $today = Carbon::today()->format('Y-m-d H:i:s');
        $zones = "'".implode("','", InventoryService::NON_STORAGE_ZONES)."'";
        $rows = DB::table('warehouses as w')
            ->leftJoin('inventory_balances as b', 'b.warehouse_id', '=', 'w.id')
            ->leftJoin('bins as bn', 'bn.id', '=', 'b.bin_id')
            ->leftJoin('zones as z', 'z.id', '=', 'bn.zone_id')
            ->leftJoin('products as p', 'p.id', '=', 'b.product_id')
            ->leftJoin('batches as bt', 'bt.id', '=', 'b.batch_id')
            ->where('w.active', true)
            ->when($warehouse, fn ($q) => $q->where('w.code', mb_strtoupper($warehouse)))
            ->groupBy('w.id', 'w.code', 'w.name_ar', 'w.name_en')->orderBy('w.code')
            ->selectRaw('w.id AS warehouse_id, w.code, w.name_ar, w.name_en')
            ->selectRaw('COALESCE(SUM(b.on_hand),0) AS on_hand, COALESCE(SUM(b.reserved),0) AS reserved')
            ->selectRaw("COALESCE(SUM(CASE WHEN b.quarantine = 0 AND b.blocked = 0 AND z.type NOT IN ({$zones}) AND (bt.expiry_date IS NULL OR bt.expiry_date >= ?) THEN GREATEST(b.on_hand - b.reserved, 0) ELSE 0 END),0) AS available_qty", [$today])
            ->selectRaw("COALESCE(SUM(CASE WHEN b.quarantine = 1 OR z.type = 'quarantine' THEN b.on_hand ELSE 0 END),0) AS quarantine_qty")
            ->selectRaw("COALESCE(SUM(CASE WHEN z.type = 'staging' THEN b.on_hand ELSE 0 END),0) AS staging_qty")
            ->selectRaw("COALESCE(SUM(CASE WHEN z.type = 'damaged' THEN b.on_hand ELSE 0 END),0) AS damaged_qty")
            ->selectRaw("COALESCE(SUM(CASE WHEN z.type = 'returns' THEN b.on_hand ELSE 0 END),0) AS returns_qty")
            ->selectRaw('COALESCE(SUM(CASE WHEN bt.expiry_date IS NOT NULL AND bt.expiry_date < ? THEN b.on_hand ELSE 0 END),0) AS expired_qty', [$today])
            ->selectRaw('COALESCE(SUM(b.on_hand * COALESCE(p.purchase_price,0)),0) AS stock_value')
            ->selectRaw('COUNT(b.id) AS row_count, COUNT(DISTINCT b.product_id) AS sku_count')
            ->get();

        $out = $in = [];
        $transit = TransferLine::with('transfer:id,from_warehouse_id,to_warehouse_id')->whereHas('transfer', fn ($q) => $q->where('status', 'transit'))->get();
        foreach ($transit as $l) {
            $qty = $l->qty - ($l->received_qty ?? 0);
            $out[$l->transfer->from_warehouse_id] = ($out[$l->transfer->from_warehouse_id] ?? 0) + $qty;
            $in[$l->transfer->to_warehouse_id] = ($in[$l->transfer->to_warehouse_id] ?? 0) + $qty;
        }

        $canCost = $user->can('inventory.view_cost');
        $totals = ['onHand' => 0, 'reserved' => 0, 'available' => 0, 'quarantine' => 0, 'staging' => 0, 'damaged' => 0, 'expired' => 0, 'inTransit' => 0] + ($canCost ? ['value' => 0.0] : []);
        $items = [];
        foreach ($rows as $r) {
            $item = [
                'warehouseId' => $r->warehouse_id, 'code' => $r->code, 'nameAr' => $r->name_ar, 'nameEn' => $r->name_en,
                'onHand' => (int) $r->on_hand, 'reserved' => (int) $r->reserved, 'available' => (int) $r->available_qty, 'quarantine' => (int) $r->quarantine_qty,
                'staging' => (int) $r->staging_qty, 'damaged' => (int) $r->damaged_qty, 'returns' => (int) $r->returns_qty, 'expired' => (int) $r->expired_qty,
            ];
            if ($canCost) {
                $item['value'] = (float) $r->stock_value;
            }
            $item += [
                'rows' => (int) $r->row_count, 'skus' => (int) $r->sku_count,
                'inTransitOut' => $out[$r->warehouse_id] ?? 0, 'inTransitIn' => $in[$r->warehouse_id] ?? 0,
                'inTransit' => ($out[$r->warehouse_id] ?? 0) + ($in[$r->warehouse_id] ?? 0),
            ];
            foreach (['onHand', 'reserved', 'available', 'quarantine', 'staging', 'damaged', 'expired'] as $k) {
                $totals[$k] += $item[$k];
            }
            $totals['inTransit'] += $item['inTransitOut'];
            if ($canCost) {
                $totals['value'] += $item['value'];
            }
            $items[] = $item;
        }

        return ['warehouses' => $items, 'totals' => $totals, 'costVisible' => $canCost];
    }

    /** Stock of one product across warehouses + engine availability (FEFO-allocatable). */
    public function productStock(string $sku): array
    {
        $product = Product::with('category')->where('sku', $sku)->first()
            ?? throw AppError::notFound('PRODUCT_NOT_FOUND', "المنتج {$sku} غير موجود", "Product {$sku} not found");
        $today = Carbon::today();
        $expDays = $this->expiringDays();
        $rows = InventoryBalance::with(self::BALANCE_RELATIONS)->where('product_id', $product->id)->get()
            ->sortBy(fn (InventoryBalance $r) => [$r->warehouse->code, $r->bin->code, $r->id])->values();
        $availability = $this->inv->availability($product->id);
        $perWarehouse = Warehouse::where('active', true)->orderBy('code')->get()->map(function (Warehouse $w) use ($rows, $availability) {
            $mine = $rows->where('warehouse_id', $w->id);

            return [
                'warehouseId' => $w->id, 'code' => $w->code, 'nameAr' => $w->name_ar,
                'onHand' => (int) $mine->sum('on_hand'), 'reserved' => (int) $mine->sum('reserved'),
                'available' => $availability['perWarehouse'][$w->id] ?? 0,
                'quarantine' => (int) $mine->filter(fn ($r) => $r->quarantine || $r->bin->zone->type === 'quarantine')->sum('on_hand'),
            ];
        })->values()->all();

        return [
            'product' => [
                'id' => $product->id, 'sku' => $product->sku, 'nameAr' => $product->name_ar, 'nameEn' => $product->name_en, 'storageClass' => $product->storage_class,
                'tracksExpiry' => $product->tracks_expiry, 'reorderMin' => $product->reorder_min, 'reorderMax' => $product->reorder_max,
                'category' => $product->category?->code ?: null,
            ],
            'totalAvailable' => $availability['total'],
            'perWarehouse' => $perWarehouse,
            'rows' => $rows->map(fn (InventoryBalance $r) => $this->mapBalance($r, $expDays, $today))->all(),
        ];
    }

    // ───────────── ledger ─────────────

    /** @param  array<string,?string>  $f  sku, warehouse, bin, type, referenceNumber, transactionId, from, to, user */
    private function ledgerQuery(array $f, ?string $q): Builder
    {
        $query = InventoryMovement::query();
        if (! empty($f['sku'])) {
            $query->whereHas('product', fn ($p) => $p->where('sku', $f['sku']));
        }
        if (! empty($f['warehouse'])) {
            $code = mb_strtoupper($f['warehouse']);
            $query->where(fn ($w) => $w->whereHas('srcBin.warehouse', fn ($x) => $x->where('code', $code))->orWhereHas('dstBin.warehouse', fn ($x) => $x->where('code', $code)));
        }
        if (! empty($f['bin'])) {
            $code = mb_strtoupper($f['bin']);
            $query->where(fn ($w) => $w->whereHas('srcBin', fn ($x) => $x->where('code', $code))->orWhereHas('dstBin', fn ($x) => $x->where('code', $code)));
        }
        if (! empty($f['type'])) {
            $query->whereIn('type', array_values(array_filter(array_map('trim', explode(',', $f['type'])), fn ($t) => $t !== '')));
        }
        if (! empty($f['referenceNumber'])) {
            $query->where('reference_number', $f['referenceNumber']);
        }
        if (! empty($f['transactionId'])) {
            $query->where('transaction_id', $f['transactionId']);
        }
        if (! empty($f['user'])) {
            $query->where(fn ($w) => $w->where('username', $f['user'])->orWhere('user_id', $f['user']));
        }
        if (! empty($f['from'])) {
            $query->where('created_at', '>=', Carbon::parse($f['from']));
        }
        if (! empty($f['to'])) {
            $to = Carbon::parse($f['to']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) {
                $to = $to->endOfDay();
            }
            $query->where('created_at', '<=', $to);
        }
        if ($q) {
            $like = "%{$q}%";
            $query->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('reference_number', 'like', $like)
                ->orWhereHas('product', fn ($p) => $p->where('sku', 'like', $like)->orWhere('name_ar', 'like', $like))
                ->orWhere('batch_no', 'like', $like)->orWhere('note', 'like', $like));
        }

        return $query;
    }

    private function mapMovement(InventoryMovement $m): array
    {
        $loc = fn ($bin) => $bin ? ['warehouse' => $bin->warehouse?->code, 'zone' => $bin->zone?->code, 'zoneType' => $bin->zone?->type, 'bin' => $bin->code] : null;
        $signed = $m->src_bin_id && ! $m->dst_bin_id ? -$m->qty : $m->qty;

        return [
            'product' => Shapes::product($m->product), 'batch' => Shapes::batch($m->batch),
            'srcBin' => Shapes::ledgerBin($m->srcBin), 'dstBin' => Shapes::ledgerBin($m->dstBin),
            'signedQty' => $signed, 'src' => $loc($m->srcBin), 'dst' => $loc($m->dstBin),
        ] + $m->toArray();
    }

    public function ledger(Paging $page, array $f): array
    {
        $query = $this->ledgerQuery($f, $page->q)->with(self::LEDGER_RELATIONS)->orderBy('created_at', $page->order)->orderBy('number', $page->order);

        return $page->paginate($query, fn (InventoryMovement $m) => $this->mapMovement($m));
    }

    public function ledgerEntry(string $number): array
    {
        $m = InventoryMovement::with(self::LEDGER_RELATIONS)->where(fn ($w) => $w->where('number', $number)->orWhere('id', $number))->first()
            ?? throw AppError::notFound('MOVEMENT_NOT_FOUND', "الحركة {$number} غير موجودة", "Movement {$number} not found");
        $siblings = $m->transaction_id
            ? InventoryMovement::with(self::LEDGER_RELATIONS)->where('transaction_id', $m->transaction_id)->where('id', '!=', $m->id)->orderBy('created_at')->orderBy('number')->get()
            : collect();

        return $this->mapMovement($m) + ['sameTransaction' => $siblings->map(fn (InventoryMovement $s) => $this->mapMovement($s))->all()];
    }

    /** Every movement that carries a document number (GRN / SO / TRF / CNT / …) — end-to-end traceability. */
    public function trace(string $referenceNumber): array
    {
        $movements = InventoryMovement::with(self::LEDGER_RELATIONS)
            ->where(fn ($w) => $w->where('reference_number', $referenceNumber)->orWhere('transaction_id', $referenceNumber))
            ->orderBy('created_at')->orderBy('number')->get();
        $byType = [];
        $inQty = $outQty = 0;
        $products = [];
        foreach ($movements as $m) {
            $byType[$m->type] = ($byType[$m->type] ?? 0) + $m->qty;
            if ($m->dst_bin_id) {
                $inQty += $m->qty;
            }
            if ($m->src_bin_id) {
                $outQty += $m->qty;
            }
            $products[$m->product_id] = Shapes::product($m->product);
        }
        $first = $movements->first();

        return [
            'referenceNumber' => $referenceNumber, 'referenceType' => $first?->reference_type ?: null, 'referenceId' => $first?->reference_id ?: null,
            'count' => $movements->count(), 'inQty' => $inQty, 'outQty' => $outQty, 'byType' => (object) $byType, 'products' => array_values($products),
            'movements' => $movements->map(fn (InventoryMovement $m) => $this->mapMovement($m))->all(),
        ];
    }

    // ───────────── batches ─────────────

    /** @param  array{sku?:?string, warehouse?:?string, status?:?string}  $f */
    public function batches(Paging $page, array $f): array
    {
        $today = Carbon::today();
        $expDays = $this->expiringDays();
        $limit = $today->copy()->addDays($expDays);
        $query = Batch::query();
        if (! empty($f['sku'])) {
            $query->whereHas('product', fn ($p) => $p->where('sku', $f['sku']));
        }
        if (! empty($f['warehouse'])) {
            $code = mb_strtoupper($f['warehouse']);
            $query->whereHas('balances', fn ($b) => $b->where('on_hand', '>', 0)->whereHas('warehouse', fn ($w) => $w->where('code', $code)));
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $query->where(fn ($w) => $w->where('batch_no', 'like', $like)->orWhereHas('product', fn ($p) => $p->where('sku', 'like', $like)->orWhere('name_ar', 'like', $like)));
        }
        switch ($f['status'] ?? null) {
            case 'expired':
                $query->where('expiry_date', '<', $today);
                break;
            case 'expiring':
                $query->whereBetween('expiry_date', [$today, $limit]);
                break;
            case 'ok':
                $query->where(fn ($w) => $w->whereNull('expiry_date')->orWhere('expiry_date', '>', $limit));
                break;
            case 'quarantine':
                $query->whereHas('balances', fn ($b) => $b->where('quarantine', true)->where('on_hand', '>', 0));
                break;
        }
        if ($page->sort === 'batchNo') {
            $query->orderBy('batch_no', $page->order);
        } else {
            $query->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date', $page->sort === 'expiry' ? $page->order : 'asc')->orderBy('batch_no');
        }
        $query->orderBy('id')->with(['product', 'supplier', 'balances.warehouse', 'balances.bin.zone']);

        return $page->paginate($query, function (Batch $b) use ($today, $expDays) {
            $d = self::daysTo($b->expiry_date, $today);
            $perWarehouse = [];
            $quarantine = 0;
            foreach ($b->balances as $r) {
                $code = $r->warehouse->code;
                $perWarehouse[$code] ??= ['onHand' => 0, 'reserved' => 0, 'quarantine' => 0, 'bins' => []];
                $perWarehouse[$code]['onHand'] += $r->on_hand;
                $perWarehouse[$code]['reserved'] += $r->reserved;
                if ($r->quarantine || $r->bin->zone->type === 'quarantine') {
                    $perWarehouse[$code]['quarantine'] += $r->on_hand;
                    $quarantine += $r->on_hand;
                }
                if ($r->on_hand > 0) {
                    $perWarehouse[$code]['bins'][] = $r->bin->code;
                }
            }

            return [
                'id' => $b->id, 'batchNo' => $b->batch_no, 'product' => Shapes::product($b->product, ['storage_class', 'tracks_expiry']),
                'supplier' => $b->supplier ? ['code' => $b->supplier->code, 'nameAr' => $b->supplier->name_ar] : null,
                'mfgDate' => $b->mfg_date?->toJSON(), 'expiry' => $b->expiry_date?->toJSON(), 'daysLeft' => $d,
                'status' => $d === null ? 'ok' : ($d < 0 ? 'expired' : ($d <= $expDays ? 'expiring' : 'ok')),
                'onHand' => (int) $b->balances->sum('on_hand'), 'reserved' => (int) $b->balances->sum('reserved'), 'quarantine' => $quarantine,
                'anyQuarantine' => $quarantine > 0, 'perWarehouse' => (object) $perWarehouse, 'createdAt' => $b->created_at?->toJSON(),
            ];
        });
    }

    // ───────────── reconciliation / staging ─────────────

    public function reconciliation(?string $warehouse = null): array
    {
        $warehouseId = null;
        if ($warehouse) {
            $w = Warehouse::where('code', mb_strtoupper($warehouse))->first()
                ?? throw AppError::notFound('WAREHOUSE_NOT_FOUND', "المستودع {$warehouse} غير موجود", 'Warehouse not found');
            $warehouseId = $w->id;
        }

        return $this->inv->reconcile($warehouseId) + ['warehouse' => $warehouse ? mb_strtoupper($warehouse) : 'ALL', 'at' => now()->toJSON()];
    }

    /** Stock sitting in virtual staging bins (STG-IN / STG-OUT / PACK), each row tagged with the document that put it there. */
    public function staging(?string $warehouse = null): array
    {
        $code = $warehouse ? mb_strtoupper($warehouse) : null;
        $rows = InventoryBalance::with(['product', 'warehouse', 'bin', 'batch'])->where('on_hand', '>', 0)
            ->whereHas('bin', fn ($b) => $b->whereIn('code', self::STAGING_BINS))
            ->when($code, fn ($q) => $q->whereHas('warehouse', fn ($w) => $w->where('code', $code)))
            ->get()->sortBy(fn (InventoryBalance $r) => [$r->warehouse->code, $r->bin->code, $r->id])->values();
        $items = $rows->map(function (InventoryBalance $r) {
            $last = InventoryMovement::where('product_id', $r->product_id)->where('dst_bin_id', $r->bin_id)
                ->when($r->batch_id, fn ($q) => $q->where('batch_id', $r->batch_id), fn ($q) => $q->whereNull('batch_id'))
                ->orderByDesc('created_at')->orderByDesc('number')->first();

            return [
                'id' => $r->id, 'sku' => $r->product->sku, 'nameAr' => $r->product->name_ar, 'nameEn' => $r->product->name_en, 'warehouse' => $r->warehouse->code,
                'bin' => $r->bin->code, 'direction' => $r->bin->code === 'STG-IN' ? 'in' : 'out', 'batch' => $r->batch?->batch_no ?: null,
                'expiry' => $r->batch?->expiry_date?->toJSON(), 'onHand' => $r->on_hand, 'reserved' => $r->reserved,
                'reference' => $last ? [
                    'number' => $last->reference_number, 'type' => $last->reference_type, 'id' => $last->reference_id, 'movement' => $last->number,
                    'movementType' => $last->type, 'by' => $last->username, 'at' => $last->created_at?->toJSON(),
                ] : null,
                'ageHours' => $last ? (int) round((now()->getTimestamp() - $last->created_at->getTimestamp()) / 3600) : null,
            ];
        })->all();
        $entries = StagingEntry::with('bin.warehouse')->where('status', 'waiting')
            ->when($code, fn ($q) => $q->whereHas('bin.warehouse', fn ($w) => $w->where('code', $code)))
            ->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (StagingEntry $e) => [
                'id' => $e->id, 'direction' => $e->direction, 'warehouse' => $e->bin->warehouse->code, 'bin' => $e->bin->code, 'referenceType' => $e->reference_type,
                'referenceId' => $e->reference_id, 'referenceNumber' => $e->reference_number, 'qty' => $e->qty, 'status' => $e->status, 'createdAt' => $e->created_at?->toJSON(),
            ])->all();

        return ['items' => $items, 'entries' => $entries];
    }
}
