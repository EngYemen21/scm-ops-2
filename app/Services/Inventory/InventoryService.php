<?php

namespace App\Services\Inventory;

use App\Models\Batch;
use App\Models\Bin;
use App\Models\InventoryAllocation;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\SalesOrderLine;
use App\Models\Warehouse;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * INVENTORY ENGINE — the only code allowed to change `inventory_balances`.
 *
 * Model: every quantity lives in a bin (real rack bins + the virtual bins STG-IN / STG-OUT / PACK / QTN-01 / RET-01 /
 * DMG-01). Every change is an append-only `inventory_movements` row with explicit source/destination
 * (warehouse / zone / rack / bin), before/after quantities, reference document, user and transaction id. Balances are
 * derived state and are verified against the ledger by reconcile().
 *
 * Available = on_hand − reserved on allocatable rows (not quarantined/blocked, not expired, not in a
 * quarantine/staging/returns/damaged zone). Reservation (per order line) ≠ Allocation (batch + bin, FEFO).
 * Picking consumes allocations.
 *
 * Concurrency: every mutation locks the balance rows it touches (SELECT … FOR UPDATE) inside the caller's
 * DB::transaction, so two users reserving 80 and 50 out of 100 can never end with reserved = 130.
 * Every mutating method therefore REQUIRES an open transaction and throws when there is none.
 */
class InventoryService
{
    /** Movement types that do not change quantities (excluded from ledger sums). */
    public const NON_STOCK_TYPES = ['alloc'];

    /** Zone types whose stock is never allocatable / never a final storage location. */
    public const NON_STORAGE_ZONES = ['quarantine', 'staging', 'returns', 'damaged'];

    public const SPECIAL_BINS = ['STG-IN', 'STG-OUT', 'PACK', 'QTN-01', 'RET-01', 'DMG-01'];

    public function __construct(private readonly NumberingService $numbering) {}

    /** Groups all movements of one business operation (one GRN post, one pick confirmation …). */
    public function newTxId(): string
    {
        return (string) Str::uuid();
    }

    // ───────────── lookups ─────────────

    public function bin(string $warehouseId, string $code): Bin
    {
        $bin = Bin::with(['zone', 'rack'])->where('warehouse_id', $warehouseId)->where('code', mb_strtoupper(trim($code)))->first();
        if (! $bin) {
            throw AppError::notFound('BIN_NOT_FOUND', "الموقع {$code} غير موجود في هذا المستودع", "Bin {$code} not found");
        }

        return $bin;
    }

    /** One of SPECIAL_BINS in the given warehouse. */
    public function specialBin(string $warehouseId, string $code): Bin
    {
        return $this->bin($warehouseId, $code);
    }

    /**
     * Central location validation: storage class × zone type × quarantine × staging × fixed product.
     *
     * @return array{ok:bool, why?:string, whyEn?:string}
     */
    public function locCheck(string $productId, string $binId): array
    {
        $p = Product::findOrFail($productId);
        $b = Bin::with('zone')->findOrFail($binId);
        if ($b->status !== 'active') {
            $label = ['blocked' => 'محظور', 'full' => 'ممتلئ'][$b->status] ?? 'غير نشط';

            return ['ok' => false, 'why' => "الموقع {$b->code} {$label}", 'whyEn' => "Bin {$b->code} is {$b->status}"];
        }
        $z = $b->zone->type;
        if ($z === 'quarantine') {
            return ['ok' => false, 'why' => 'موقع حجر — لا يقبل تخزينًا عاديًا', 'whyEn' => 'Quarantine location'];
        }
        if ($z === 'staging') {
            return ['ok' => false, 'why' => 'موقع Staging — ليس موقع تخزين نهائي', 'whyEn' => 'Staging location is not final storage'];
        }
        if ($z === 'returns' || $z === 'damaged') {
            return ['ok' => false, 'why' => 'موقع مرتجعات/تالف — ليس موقع تخزين نهائي', 'whyEn' => 'Returns/damaged area is not storage'];
        }
        if ($b->fixed_product_id && $b->fixed_product_id !== $productId) {
            return ['ok' => false, 'why' => "الموقع {$b->code} مخصص لمنتج آخر", 'whyEn' => 'Bin is fixed to another product'];
        }
        $need = $p->storage_class;
        $zoneClass = $z === 'hazmat' ? 'ambient' : $z;
        if ($need !== $zoneClass) {
            $names = ['frozen' => 'FZ (مجمد −18°)', 'chilled' => 'CH (مبرد +4°)', 'ambient' => 'A/B (عادي)'];

            return ['ok' => false, 'why' => "{$p->name_ar} يتطلب منطقة {$names[$need]} وليس {$b->zone->code}", 'whyEn' => "{$p->name_en} requires {$need} zone, not {$b->zone->code}"];
        }

        return ['ok' => true];
    }

    // ───────────── movements ─────────────

    /**
     * Posts one stock movement atomically and returns the ledger row.
     *
     * @param  array{
     *   type:string, productId:string, batchId?:?string, qty:int,
     *   from?:?array{warehouseId:string, binId:string}, to?:?array{warehouseId:string, binId:string},
     *   reference:array{type:string, id?:?string, number:string}, note?:?string,
     *   consumeReserved?:int, quarantine?:bool
     * }  $m  `consumeReserved`: when moving out of a row, also release this much reserved/allocated on it.
     *        `quarantine`: flag for the destination row.
     */
    public function post(?AuthUser $user, ?string $txId, array $m): InventoryMovement
    {
        $this->requireTransaction();
        $qty = $m['qty'] ?? null;
        if (! is_int($qty) || $qty <= 0) {
            throw AppError::validation('BAD_QTY', 'الكمية يجب أن تكون عددًا صحيحًا أكبر من صفر', 'Quantity must be a positive integer');
        }
        $from = $m['from'] ?? null;
        $to = $m['to'] ?? null;
        if (! $from && ! $to) {
            throw AppError::validation('BAD_MOVE', 'حركة بلا مصدر ولا وجهة', 'Movement needs a source or destination');
        }
        $batchId = $m['batchId'] ?? null;
        $before = $after = null;
        $meta = [];

        if ($from) {
            $row = $this->lockRow($m['productId'], $from['binId'], $batchId, false);
            if (! $row) {
                throw AppError::rule('NO_STOCK', 'لا رصيد في موقع المصدر لهذه الدفعة', 'No stock at source bin for this batch');
            }
            $consume = (int) ($m['consumeReserved'] ?? 0);
            $free = $row->on_hand - $row->reserved + $consume; // movable without touching other orders' reservations
            if ($qty > $free) {
                throw AppError::rule('INSUFFICIENT_STOCK', "الرصيد المتاح في الموقع ({$free}) أقل من {$qty} — لا رصيد سالب", "Available at bin ({$free}) is less than {$qty}");
            }
            $before = $row->on_hand;
            $after = $row->on_hand - $qty;
            $row->update(['on_hand' => $after, 'reserved' => max(0, $row->reserved - $consume), 'allocated' => max(0, $row->allocated - $consume), 'version' => $row->version + 1]);
            $bin = Bin::findOrFail($from['binId']);
            $meta += ['src_warehouse_id' => $bin->warehouse_id, 'src_zone_id' => $bin->zone_id, 'src_rack_id' => $bin->rack_id, 'src_bin_id' => $bin->id];
        }
        if ($to) {
            $quarantine = (bool) ($m['quarantine'] ?? false);
            $row = $this->lockRow($m['productId'], $to['binId'], $batchId, true, $to['warehouseId'], $quarantine);
            $b = $row->on_hand;
            $a = $b + $qty;
            $row->update(['on_hand' => $a, 'quarantine' => $quarantine ? true : $row->quarantine, 'version' => $row->version + 1]);
            if (! $from) {
                $before = $b;
                $after = $a;
            }
            $bin = Bin::findOrFail($to['binId']);
            $meta += ['dst_warehouse_id' => $bin->warehouse_id, 'dst_zone_id' => $bin->zone_id, 'dst_rack_id' => $bin->rack_id, 'dst_bin_id' => $bin->id];
        }
        $batch = $batchId ? Batch::find($batchId) : null;

        return InventoryMovement::create($meta + [
            'number' => $this->numbering->next('TX'), 'type' => $m['type'], 'product_id' => $m['productId'], 'batch_id' => $batchId,
            'batch_no' => $batch?->batch_no, 'qty' => $qty, 'before_qty' => $before, 'after_qty' => $after,
            'reference_type' => $m['reference']['type'], 'reference_id' => $m['reference']['id'] ?? null, 'reference_number' => $m['reference']['number'],
            'note' => $m['note'] ?? null, 'user_id' => $user?->id, 'username' => $user?->username ?? 'system', 'transaction_id' => $txId,
        ]);
    }

    // ───────────── flag-only changes (no quantity moves) ─────────────

    /**
     * Quarantine on/off for one balance row. A FLAG change, not a stock movement: quantities do not move, so NO
     * inventory_movements row is written (it would break ledger = balance). Callers record it in audit + activity.
     *
     * @return array{rowId:string, onHand:int, before:bool, after:bool}
     */
    public function setQuarantine(string $productId, string $binId, ?string $batchId, bool $quarantine): array
    {
        $this->requireTransaction();
        $row = $this->lockRow($productId, $binId, $batchId, false);
        if (! $row) {
            throw AppError::notFound('BALANCE_NOT_FOUND', 'لا يوجد رصيد لهذا المنتج/الدفعة في الموقع', 'No balance row for this product/batch at bin');
        }
        if ((bool) $row->quarantine === $quarantine) {
            throw AppError::rule('QTN_NOOP', $quarantine ? 'الرصيد في الحجر مسبقًا' : 'الرصيد ليس في الحجر', $quarantine ? 'Already quarantined' : 'Not quarantined');
        }
        $before = (bool) $row->quarantine;
        $row->update(['quarantine' => $quarantine, 'version' => $row->version + 1]);

        return ['rowId' => $row->id, 'onHand' => $row->on_hand, 'before' => $before, 'after' => $quarantine];
    }

    /** Blocks/unblocks balance rows (count freeze). Blocked rows are excluded from allocation and manual moves. */
    public function setBlocked(array $rowIds, bool $blocked): int
    {
        $this->requireTransaction();
        if (! $rowIds) {
            return 0;
        }

        return InventoryBalance::whereIn('id', $rowIds)->update(['blocked' => $blocked, 'version' => DB::raw('version + 1')]);
    }

    // ───────────── availability / allocation (FEFO) ─────────────

    /**
     * Allocatable rows of a product in a warehouse, FEFO-sorted. Expired, quarantined, blocked and non-storage rows
     * are excluded BEFORE sorting, so an expired batch can never win FEFO. Each row carries `batch` (or null).
     *
     * @return Collection<int, InventoryBalance>
     */
    public function allocRows(string $productId, string $warehouseId, bool $lock = false): Collection
    {
        $query = InventoryBalance::query()->from('inventory_balances as b')->select('b.*')
            ->join('bins as bn', 'bn.id', '=', 'b.bin_id')->join('zones as z', 'z.id', '=', 'bn.zone_id')
            ->where('b.product_id', $productId)->where('b.warehouse_id', $warehouseId)
            ->where('b.quarantine', false)->where('b.blocked', false)
            ->whereNotIn('z.type', self::NON_STORAGE_ZONES)->whereRaw('b.on_hand - b.reserved > 0');
        if ($lock) {
            $this->requireTransaction();
            $query->lock('for update of b');
        }
        $rows = $query->get();
        $batches = Batch::whereIn('id', $rows->pluck('batch_id')->filter()->unique()->all())->get()->keyBy('id');
        $today = Carbon::today();

        return $rows->each(fn ($r) => $r->setRelation('batch', $r->batch_id ? $batches->get($r->batch_id) : null))
            ->reject(fn ($r) => $r->batch?->expiry_date && $r->batch->expiry_date->lt($today))
            ->sortBy(fn ($r) => $r->batch?->expiry_date?->getTimestamp() ?? PHP_INT_MAX)
            ->values();
    }

    /** @return array{total:int, perWarehouse:array<string,int>} */
    public function availability(string $productId, ?string $warehouseId = null): array
    {
        $warehouses = $warehouseId ? [$warehouseId] : Warehouse::where('active', true)->pluck('id')->all();
        $per = [];
        foreach ($warehouses as $wh) {
            $per[$wh] = (int) $this->allocRows($productId, $wh)->sum(fn ($r) => $r->on_hand - $r->reserved);
        }

        return ['total' => array_sum($per), 'perWarehouse' => $per];
    }

    /**
     * Reserve + allocate (FEFO) for one sales-order line. Locks the candidate rows; throws when insufficient.
     *
     * @param  array{soId:string, soLineId:string, productId:string, warehouseId:string, qty:int, referenceNumber:string,
     *               preferBinId?:?string, preferBatchId?:?string}  $p
     * @return array{reservation:InventoryReservation, allocations:InventoryAllocation[]}
     */
    public function reserve(?AuthUser $user, ?string $txId, array $p): array
    {
        $this->requireTransaction();
        $rows = $this->allocRows($p['productId'], $p['warehouseId'], true);
        $available = (int) $rows->sum(fn ($r) => $r->on_hand - $r->reserved);
        if ($available < $p['qty']) {
            $name = Product::find($p['productId'])?->name_ar ?? '';
            throw AppError::rule('INSUFFICIENT_AVAILABLE', "مرفوض: المتاح لـ {$name} ({$available}) أقل من {$p['qty']} — لا Overselling", "Available ({$available}) is less than {$p['qty']}", ['available' => $available, 'requested' => $p['qty']]);
        }
        if (! empty($p['preferBinId'])) {
            $preferBatch = $p['preferBatchId'] ?? null;
            [$preferred, $others] = $rows->partition(fn ($r) => $r->bin_id === $p['preferBinId'] && $r->batch_id === $preferBatch);
            $rows = $preferred->concat($others)->values();
        }
        $reservation = InventoryReservation::create([
            'number' => $this->numbering->next('RSV'), 'so_id' => $p['soId'], 'so_line_id' => $p['soLineId'],
            'product_id' => $p['productId'], 'warehouse_id' => $p['warehouseId'], 'qty' => $p['qty'],
        ]);
        $need = $p['qty'];
        $allocations = [];
        foreach ($rows as $r) {
            if ($need <= 0) {
                break;
            }
            $take = min($need, $r->on_hand - $r->reserved);
            InventoryBalance::whereKey($r->id)->update(['reserved' => DB::raw("reserved + {$take}"), 'allocated' => DB::raw("allocated + {$take}"), 'version' => DB::raw('version + 1')]);
            $allocations[] = InventoryAllocation::create([
                'reservation_id' => $reservation->id, 'so_line_id' => $p['soLineId'], 'product_id' => $p['productId'],
                'warehouse_id' => $p['warehouseId'], 'bin_id' => $r->bin_id, 'batch_id' => $r->batch_id, 'qty' => $take,
            ]);
            $bin = Bin::findOrFail($r->bin_id);
            InventoryMovement::create([
                'number' => $this->numbering->next('TX'), 'type' => 'alloc', 'product_id' => $p['productId'], 'batch_id' => $r->batch_id,
                'batch_no' => $r->batch?->batch_no, 'qty' => $take, 'before_qty' => $r->on_hand, 'after_qty' => $r->on_hand,
                'src_warehouse_id' => $bin->warehouse_id, 'src_zone_id' => $bin->zone_id, 'src_rack_id' => $bin->rack_id, 'src_bin_id' => $bin->id,
                'reference_type' => 'SalesOrder', 'reference_id' => $p['soId'], 'reference_number' => $p['referenceNumber'], 'note' => 'حجز FEFO',
                'user_id' => $user?->id, 'username' => $user?->username ?? 'system', 'transaction_id' => $txId,
            ]);
            $need -= $take;
        }
        SalesOrderLine::whereKey($p['soLineId'])->update(['reserved_qty' => DB::raw("reserved_qty + {$p['qty']}"), 'allocated_qty' => DB::raw("allocated_qty + {$p['qty']}")]);

        return ['reservation' => $reservation, 'allocations' => $allocations];
    }

    /** Releases all active reservations of a sales order (cancellation). Returns how many were released. */
    public function release(string $soId): int
    {
        $this->requireTransaction();
        $reservations = InventoryReservation::with(['allocations' => fn ($q) => $q->where('status', 'active')])->where('so_id', $soId)->where('status', 'active')->get();
        foreach ($reservations as $r) {
            foreach ($r->allocations as $al) {
                $remaining = $al->qty - $al->picked_qty;
                if ($remaining > 0) {
                    $row = $this->lockRow($al->product_id, $al->bin_id, $al->batch_id, false);
                    $row?->update(['reserved' => max(0, $row->reserved - $remaining), 'allocated' => max(0, $row->allocated - $remaining), 'version' => $row->version + 1]);
                }
                $al->update(['status' => 'released']);
            }
            $r->update(['status' => 'released', 'released_at' => now()]);
            SalesOrderLine::whereKey($r->so_line_id)->update(['reserved_qty' => 0, 'allocated_qty' => 0]);
        }

        return $reservations->count();
    }

    /**
     * Gives back part of a reservation on ONE balance row (short pick: the goods are not where the allocation says).
     * Only reserved / allocated change — on-hand and the ledger are untouched, so ledger = balance still holds. The
     * caller marks the matching allocation `released`, which keeps reserved = Σ open allocations.
     */
    public function unreserve(string $productId, string $binId, ?string $batchId, int $qty): void
    {
        $this->requireTransaction();
        if ($qty <= 0) {
            throw AppError::validation('BAD_QTY', 'الكمية يجب أن تكون عددًا صحيحًا أكبر من صفر', 'Quantity must be a positive integer');
        }
        $row = $this->lockRow($productId, $binId, $batchId, false);
        $row?->update(['reserved' => max(0, $row->reserved - $qty), 'allocated' => max(0, $row->allocated - $qty), 'version' => $row->version + 1]);
    }

    // ───────────── reconciliation ─────────────

    /**
     * Σ ledger (stock types, destination − source) per (product, bin, batch) must equal the balance row, and
     * reserved must equal Σ active allocations (qty − picked).
     *
     * @return array{ok:bool, checked:int, mismatches:array<int,array<string,mixed>>}
     */
    public function reconcile(?string $warehouseId = null): array
    {
        $balances = InventoryBalance::with(['product', 'bin', 'batch'])->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->get();
        $key = fn ($product, $bin, $batch) => $product.'|'.$bin.'|'.($batch ?? '');
        $sum = fn (string $binCol) => InventoryMovement::query()->whereNotNull($binCol)->whereNotIn('type', self::NON_STOCK_TYPES)
            ->groupBy('product_id', $binCol, 'batch_id')->selectRaw("product_id, {$binCol} as bin_id, batch_id, SUM(qty) as total")->get()
            ->mapWithKeys(fn ($m) => [$key($m->product_id, $m->bin_id, $m->batch_id) => (int) $m->total]);
        $in = $sum('dst_bin_id');
        $out = $sum('src_bin_id');
        $allocated = InventoryAllocation::where('status', 'active')->groupBy('product_id', 'bin_id', 'batch_id')
            ->selectRaw('product_id, bin_id, batch_id, SUM(qty) - SUM(picked_qty) as open_qty')->get()
            ->mapWithKeys(fn ($a) => [$key($a->product_id, $a->bin_id, $a->batch_id) => (int) $a->open_qty]);

        $rows = $balances->map(function ($b) use ($key, $in, $out, $allocated) {
            $k = $key($b->product_id, $b->bin_id, $b->batch_id);
            $ledger = ($in[$k] ?? 0) - ($out[$k] ?? 0);
            $expected = $allocated[$k] ?? 0;

            return [
                'sku' => $b->product->sku, 'name' => $b->product->name_ar, 'bin' => $b->bin->code, 'batch' => $b->batch?->batch_no ?? '—',
                'onHand' => $b->on_hand, 'reserved' => $b->reserved, 'ledger' => $ledger, 'reservedExpected' => $expected,
                'ok' => $ledger === $b->on_hand && $b->reserved >= 0 && $b->reserved <= $b->on_hand && $expected === $b->reserved,
            ];
        });
        $mismatches = $rows->reject(fn ($r) => $r['ok'])->values()->all();

        return ['ok' => count($mismatches) === 0, 'checked' => $rows->count(), 'mismatches' => $mismatches];
    }

    // ───────────── internals ─────────────

    /** Locks (and optionally creates) the balance row of (product, bin, batch). */
    private function lockRow(string $productId, string $binId, ?string $batchId, bool $create, ?string $warehouseId = null, bool $quarantine = false): ?InventoryBalance
    {
        $find = fn () => InventoryBalance::where('product_id', $productId)->where('bin_id', $binId)->where('batch_key', $batchId ?? '')->lockForUpdate()->first();
        $row = $find();
        if ($row || ! $create) {
            return $row;
        }
        InventoryBalance::create([
            'product_id' => $productId, 'warehouse_id' => $warehouseId ?? Bin::findOrFail($binId)->warehouse_id, 'bin_id' => $binId,
            'batch_id' => $batchId, 'batch_key' => $batchId ?? '', 'on_hand' => 0, 'reserved' => 0, 'allocated' => 0, 'quarantine' => $quarantine,
        ]);

        return $find();
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('InventoryService mutations must run inside DB::transaction() so row locks and the ledger stay atomic.');
        }
    }
}
