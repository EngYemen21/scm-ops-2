<?php

namespace App\Services\Inventory;

use App\Finance\Models\Journal;
use App\Finance\Models\JournalLine;
use App\Finance\Services\AccountMap;
use App\Finance\Services\LedgerService;
use App\Models\Batch;
use App\Models\Bin;
use App\Models\Driver;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\OpsException;
use App\Models\Product;
use App\Models\StatusHistory;
use App\Models\TransferLine;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Services\Exceptions\ExceptionsService;
use App\Services\Sales\WaitingOrders;
use App\Services\Transport\TransferTripService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Inter-warehouse transfers (§11): draft → requested → approved → picking → ready → transit → partial* → received → done.
 *
 *  - approve   holds every line in its source bin (InventoryService::hold, owner WarehouseTransfer): from that moment no
 *              sales order, move or other transfer can take the quantity. reject / cancel give it back.
 *  - ship      moves each hold out of the source as a `transit` movement and keeps the cost it left at on the line.
 *  - receive   (transit | partial, as many times as goods arrive) posts `trf` movements into the destination bin AT THE
 *              SHIP COST, so the in-transit account clears. The destination bin is chosen — or confirmed — at receipt.
 *  - what never arrives is a variance that must be resolved before the transfer can close: written off as a loss
 *              (journal Dr inventory loss / Cr inventory in transit) or received back into the source warehouse.
 */
class TransfersService
{
    /** Controller action → target status. */
    public const ACTIONS = [
        'submit' => 'requested', 'approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled',
        'start-picking' => 'picking', 'picking-done' => 'ready', 'ship' => 'transit', 'close' => 'done',
    ];

    /** Owner type of the stock holds of a transfer. */
    public const HOLD_OWNER = 'WarehouseTransfer';

    /** States in which goods of the transfer may still be on the road. */
    public const ON_THE_ROAD = ['transit', 'partial'];

    public const RESOLUTIONS = ['loss', 'returned'];

    private const STATUS_AR = [
        'draft' => 'مسودة', 'requested' => 'بانتظار الاعتماد', 'approved' => 'معتمد — جاهز للتجهيز', 'picking' => 'قيد التجهيز', 'ready' => 'جاهز للشحن',
        'transit' => 'In Transit — في العبور', 'received' => 'مستلم — بانتظار الإقفال', 'partial' => 'مستلم جزئيًا', 'done' => 'مكتمل ✓', 'rejected' => 'مرفوض', 'cancelled' => 'ملغى',
    ];

    private const REASONS = [
        'shortage' => ['تغطية نقص', 'Cover shortage'], 'rebalance' => ['إعادة توازن المخزون', 'Rebalance stock'], 'season' => ['موسم / طلب متوقع', 'Seasonal demand'],
        'surplus' => ['إرجاع فائض', 'Return surplus'], 'other' => ['أخرى', 'Other'],
    ];

    private const RELATIONS = ['fromWarehouse', 'toWarehouse', 'lines.product', 'lines.batch', 'lines.fromBin.zone', 'lines.toBin.zone', 'vehicle', 'driver', 'trip'];

    public function __construct(
        private readonly InventoryService $inv,
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly ExceptionsService $exceptions,
        private readonly SettingsService $settings,
        private readonly LedgerService $ledger,
        private readonly AccountMap $map,
        private readonly TransferTripService $transferTrips,
    ) {}

    /** `$lock`: take the row FOR UPDATE — every change of a transfer starts with it, so two users cannot approve / ship / receive it twice. */
    private function load(string $id, bool $lock = false): WarehouseTransfer
    {
        $query = WarehouseTransfer::with(self::RELATIONS)->where(fn ($w) => $w->where('id', $id)->orWhere('number', $id));
        $t = ($lock ? $query->lockForUpdate() : $query)->first()
            ?? throw AppError::notFound('TRANSFER_NOT_FOUND', "التحويل {$id} غير موجود", "Transfer {$id} not found");
        $t->setRelation('lines', $t->lines->sortBy('line_no')->values());

        return $t;
    }

    /** Shipped quantity of a line that has neither been received nor accounted for as a variance. */
    private function remaining(TransferLine $l): int
    {
        return max(0, $l->qty - (int) $l->received_qty - (int) $l->variance_qty);
    }

    /** Units of the transfer that are still on the road (0 before shipping and once everything is accounted for). */
    private function onTheRoad(WarehouseTransfer $t): int
    {
        return in_array($t->status, self::ON_THE_ROAD, true) ? (int) $t->lines->sum(fn (TransferLine $l) => $this->remaining($l)) : 0;
    }

    private function seesCost(?AuthUser $user): bool
    {
        return (bool) $user?->can('inventory.view_cost');
    }

    /**
     * The transfer with the reference's include shape (warehouses + lines with product / batch / bins) plus the carrier.
     * The cost a line left the source at is shown only to users who may see costs.
     */
    private function present(WarehouseTransfer $t, bool $cost = false): array
    {
        return [
            'fromWarehouse' => Shapes::warehouse($t->fromWarehouse), 'toWarehouse' => Shapes::warehouse($t->toWarehouse),
            'vehicle' => $t->vehicle ? ['id' => $t->vehicle->id, 'code' => $t->vehicle->code, 'plateAr' => $t->vehicle->plate_ar, 'plateEn' => $t->vehicle->plate_en] : null,
            'driver' => $t->driver ? ['id' => $t->driver->id, 'code' => $t->driver->code, 'nameAr' => $t->driver->name_ar, 'nameEn' => $t->driver->name_en] : null,
            'trip' => $t->trip ? ['id' => $t->trip->id, 'number' => $t->trip->number, 'status' => $t->trip->status] : null,
            'lines' => $t->lines->map(function (TransferLine $l) use ($cost) {
                $row = $l->toArray();
                if (! $cost) {
                    unset($row['unitCost']);
                }

                return [
                    'product' => Shapes::product($l->product), 'batch' => Shapes::batch($l->batch), 'fromBin' => Shapes::bin($l->fromBin), 'toBin' => Shapes::bin($l->toBin),
                    'remainingQty' => $this->remaining($l),
                ] + $row;
            })->all(),
        ] + $t->toArray();
    }

    /** Quantity figures shared by the list and the detail. */
    private function figures(WarehouseTransfer $t): array
    {
        return [
            'totalQty' => (int) $t->lines->sum('qty'),
            'receivedQty' => (int) $t->lines->sum(fn (TransferLine $l) => (int) $l->received_qty),
            'varianceQty' => (int) $t->lines->sum(fn (TransferLine $l) => (int) $l->variance_qty),
            'inTransitQty' => $this->onTheRoad($t),
        ];
    }

    private function statusLabel(string $status): string
    {
        return self::STATUS_AR[$status] ?? $status;
    }

    private function assertTransition(WarehouseTransfer $t, string $to): void
    {
        if (! Sm::can('TRANSFER_TRANSITIONS', $t->status, $to)) {
            throw AppError::rule('TRANSFER_TRANSITION', "انتقال غير مسموح: {$this->statusLabel($t->status)} ← {$this->statusLabel($to)}", "Invalid transition {$t->status} → {$to}");
        }
    }

    /** Validates the transition, updates the row (+ $extra columns), writes audit / history / activity. Returns the fresh transfer. */
    private function setStatus(AuthUser $user, WarehouseTransfer $t, string $to, ?string $note = null, array $extra = []): WarehouseTransfer
    {
        $from = $t->status;
        $this->assertTransition($t, $to);
        $t->update(['status' => $to] + $extra);
        $this->audit->status($user, 'WarehouseTransfer', $t->id, $t->number, $from, $to, $note);
        $roles = match ($to) {
            'requested' => ['super', 'wm'], 'transit' => ['wm', 'super'], default => [],
        };
        $suffix = $note ? ' — '.$note : '';
        $this->notify->activity($user, 'WarehouseTransfer', $t->id, $t->number, "التحويل {$t->number} → {$this->statusLabel($to)}{$suffix}", "Transfer {$t->number} → {$to}{$suffix}", $roles);

        return $this->load($t->id);
    }

    private function balanceRow(string $productId, string $binId, ?string $batchId): ?InventoryBalance
    {
        return InventoryBalance::where('product_id', $productId)->where('bin_id', $binId)->where('batch_key', $batchId ?? '')->first();
    }

    // ───────────── create / read ─────────────

    /**
     * The destination bin is optional here: it is chosen (or confirmed) by whoever receives the goods. When it is
     * given it is checked now, so a wrong class is caught early — and checked again at receipt.
     *
     * @param  array{fromWarehouseCode:string, toWarehouseCode:string, date:string, eta?:?string, reasonCode:string, notes?:?string, submit:bool,
     *               lines:array<int,array{sku:string, qty:int, batchNo?:?string, fromBin:string, toBin?:?string}>}  $dto
     */
    public function create(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $from = Warehouse::where('code', mb_strtoupper($dto['fromWarehouseCode']))->first()
                ?? throw AppError::notFound('WAREHOUSE_NOT_FOUND', "المستودع {$dto['fromWarehouseCode']} غير موجود", 'Source warehouse not found');
            $to = Warehouse::where('code', mb_strtoupper($dto['toWarehouseCode']))->first()
                ?? throw AppError::notFound('WAREHOUSE_NOT_FOUND', "المستودع {$dto['toWarehouseCode']} غير موجود", 'Destination warehouse not found');
            $lines = [];
            foreach (array_values($dto['lines']) as $i => $l) {
                $no = $i + 1;
                $qty = (int) $l['qty'];
                $product = Product::where('sku', $l['sku'])->first()
                    ?? throw AppError::notFound('PRODUCT_NOT_FOUND', "المنتج {$l['sku']} غير موجود", "Product {$l['sku']} not found");
                $batchNo = $l['batchNo'] ?? null;
                $batch = null;
                if ($batchNo !== null && $batchNo !== '' && $batchNo !== '—') {
                    $batch = Batch::where('product_id', $product->id)->where('batch_no', $batchNo)->first()
                        ?? throw AppError::notFound('BATCH_NOT_FOUND', "الدفعة {$batchNo} غير موجودة للمنتج {$product->sku}", "Batch {$batchNo} not found");
                }
                $fromBin = $this->inv->bin($from->id, $l['fromBin']); // bin must belong to the source warehouse (lookup is per warehouse)
                $toCode = trim((string) ($l['toBin'] ?? ''));
                $toBin = null;
                if ($toCode !== '') {
                    $toBin = $this->inv->bin($to->id, $toCode);
                    $chk = $this->inv->locCheck($product->id, $toBin->id);
                    if (! $chk['ok']) {
                        throw AppError::rule('LOC_RULE', "سطر {$no}: {$chk['why']}", "Line {$no}: ".($chk['whyEn'] ?? ''));
                    }
                }
                $row = $this->balanceRow($product->id, $fromBin->id, $batch?->id);
                $available = $row && ! $row->quarantine && ! $row->blocked ? $row->on_hand - $row->reserved : 0;
                if ($qty > $available) {
                    throw AppError::rule('INSUFFICIENT_AVAILABLE', "سطر {$no}: المتاح في {$fromBin->code} ({$available}) أقل من {$qty}", "Line {$no}: available at {$fromBin->code} ({$available}) is less than {$qty}", ['line' => $no, 'available' => $available, 'requested' => $qty]);
                }
                $lines[] = ['line_no' => $no, 'qty' => $qty, 'batch_no' => $batch?->batch_no, 'product_id' => $product->id, 'batch_id' => $batch?->id, 'from_bin_id' => $fromBin->id, 'to_bin_id' => $toBin?->id];
            }
            $number = $this->numbering->next('TRF');
            $reason = self::REASONS[$dto['reasonCode']] ?? self::REASONS['other'];
            $t = WarehouseTransfer::create([
                'number' => $number, 'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'status' => 'draft', 'reason_code' => $dto['reasonCode'],
                'reason_ar' => $reason[0], 'reason_en' => $reason[1], 'notes' => ($dto['notes'] ?? null) ?: null, 'requested_by_id' => $user->id,
                'requested_by' => $user->nameAr ?: $user->username, 'date' => Carbon::parse($dto['date']), 'eta' => ! empty($dto['eta']) ? Carbon::parse($dto['eta']) : null,
            ]);
            foreach ($lines as $line) {
                TransferLine::create(['transfer_id' => $t->id] + $line);
            }
            $count = count($lines);
            $this->audit->log($user, ['action' => 'TRANSFER.CREATE', 'entityType' => 'WarehouseTransfer', 'entityId' => $t->id, 'entityNumber' => $number,
                'newValue' => ['from' => $from->code, 'to' => $to->code, 'lines' => $count, 'reason' => $dto['reasonCode']]]);
            $this->audit->status($user, 'WarehouseTransfer', $t->id, $number, null, 'draft');
            $this->notify->activity($user, 'WarehouseTransfer', $t->id, $number, "أُنشئ تحويل {$number}: {$from->code} → {$to->code} ({$count} سطر)", "Transfer {$number} created: {$from->code} → {$to->code} ({$count} lines)");

            return $this->present($dto['submit'] ? $this->setStatus($user, $t, 'requested') : $this->load($t->id), $this->seesCost($user));
        });
    }

    /** @param  array{status?:?string, warehouse?:?string, from?:?string, to?:?string}  $f */
    public function list(Paging $page, array $f, ?AuthUser $user = null): array
    {
        $query = WarehouseTransfer::with(self::RELATIONS);
        if (! empty($f['status'])) {
            $query->whereIn('status', explode(',', $f['status']));
        }
        if (! empty($f['warehouse'])) {
            $code = mb_strtoupper($f['warehouse']);
            $query->where(fn ($w) => $w->whereHas('fromWarehouse', fn ($x) => $x->where('code', $code))->orWhereHas('toWarehouse', fn ($x) => $x->where('code', $code)));
        }
        if (! empty($f['from'])) {
            $query->whereHas('fromWarehouse', fn ($x) => $x->where('code', mb_strtoupper($f['from'])));
        }
        if (! empty($f['to'])) {
            $query->whereHas('toWarehouse', fn ($x) => $x->where('code', mb_strtoupper($f['to'])));
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $query->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('reason_ar', 'like', $like)->orWhere('notes', 'like', $like)
                ->orWhereHas('lines.product', fn ($p) => $p->where('sku', 'like', $like)));
        }
        $query->orderBy('created_at', $page->order)->orderBy('id', $page->order);
        $cost = $this->seesCost($user);

        return $page->paginate($query, function (WarehouseTransfer $t) use ($cost) {
            $t->setRelation('lines', $t->lines->sortBy('line_no')->values());

            // `inTransitQty` counts a partially received transfer too: its remainder is still on the road
            return $this->present($t, $cost) + $this->figures($t);
        });
    }

    public function get(string $id, ?AuthUser $user = null): array
    {
        $t = $this->load($id);
        $cost = $this->seesCost($user);
        $history = StatusHistory::where('entity_type', 'WarehouseTransfer')->where('entity_id', $t->id)->orderBy('at')->orderBy('id')->get();
        $movements = InventoryMovement::where('reference_type', 'WarehouseTransfer')->where('reference_id', $t->id)->orderBy('created_at')->orderBy('number')->get(Shapes::DOC_MOVEMENT_COLUMNS);
        $exceptions = OpsException::where('entity_type', 'WarehouseTransfer')->where('entity_id', $t->id)->orderBy('created_at')->get();
        $held = $this->inv->activeHolds(self::HOLD_OWNER, $t->id)->groupBy('owner_line_id')->map(fn ($g) => (int) $g->sum('qty'));
        $receiving = in_array($t->status, self::ON_THE_ROAD, true);

        $out = $this->present($t, $cost);
        foreach ($t->lines as $i => $l) {
            $out['lines'][$i]['heldQty'] = $held[$l->id] ?? 0;
            // what the receipt form proposes when the line has no destination bin yet (or its bin stopped being usable)
            $out['lines'][$i]['suggestedToBin'] = $receiving && $this->remaining($l) > 0 ? $this->suggestBin($l->product_id, $t->to_warehouse_id) : null;
        }
        // partial → done is in the state table, but a transfer with goods still on the road cannot close: do not offer it
        $allowed = Sm::table('TRANSFER_TRANSITIONS')[$t->status] ?? [];
        if ($t->status === 'partial') {
            $allowed = array_values(array_diff($allowed, ['done']));
        }
        $open = $t->status === 'partial' ? $this->onTheRoad($t) : 0;
        $journal = $t->variance_journal_id ? Journal::find($t->variance_journal_id) : null;
        $variance = [
            'openQty' => $open, 'qty' => (int) $t->lines->sum(fn (TransferLine $l) => (int) $l->variance_qty),
            'resolution' => $t->variance_resolution, 'note' => $t->variance_note,
            'journal' => $journal ? ['id' => $journal->id, 'number' => $journal->number] : null,
        ];
        if ($cost) {
            // value still on the road (what a write-off would post), then the value that was written off
            $variance['openValue'] = round((float) $t->lines->sum(fn (TransferLine $l) => $open > 0 ? $this->remaining($l) * (float) ($this->lineCost($t, $l) ?? 0) : 0), 2);
            $variance['lossValue'] = $journal ? (float) $journal->total_debit : 0.0;
        }

        return $out + [
            'history' => $history->map->toArray()->all(),
            'movements' => $movements->map(fn (InventoryMovement $m) => Shapes::docMovement($m))->all(),
            'exceptions' => $exceptions->map(fn (OpsException $e) => ['id' => $e->id, 'number' => $e->number, 'kind' => $e->kind, 'status' => $e->status, 'textAr' => $e->text_ar, 'textEn' => $e->text_en, 'createdAt' => $e->created_at?->toJSON()])->all(),
            'allowed' => $allowed,
            'heldQty' => (int) $held->sum(),
            'variance' => $variance,
            'policy' => ['requireCarrier' => (bool) $this->settings->get('transfers.requireCarrier')],
        ] + $this->figures($t);
    }

    /**
     * Where to put a product arriving in a warehouse: the bin that already holds it, else the first free bin of the
     * right storage class (same idea as the putaway suggestion of receiving). Null when nothing fits.
     *
     * @return array{code:string, reasonAr:string, reasonEn:string}|null
     */
    public function suggestBin(string $productId, string $warehouseId): ?array
    {
        $product = Product::find($productId);
        if (! $product) {
            return null;
        }
        $usable = fn ($bin) => $bin->where('status', 'active')->whereHas('zone', fn ($z) => $z->where('type', $product->storage_class))
            ->where(fn ($w) => $w->whereNull('fixed_product_id')->orWhere('fixed_product_id', $productId));
        $existing = InventoryBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->where('on_hand', '>', 0)
            ->where('quarantine', false)->where('blocked', false)->whereHas('bin', $usable)->orderByDesc('on_hand')->orderBy('id')->first();
        if ($existing && $this->inv->locCheck($productId, $existing->bin_id)['ok']) {
            return ['code' => Bin::whereKey($existing->bin_id)->value('code'), 'reasonAr' => 'الموقع الذي يحوي المنتج حاليًا', 'reasonEn' => 'The bin that already holds this product'];
        }
        $empty = $usable(Bin::where('warehouse_id', $warehouseId)->where('type', '!=', 'virtual'))
            ->whereNotIn('id', InventoryBalance::select('bin_id')->where('warehouse_id', $warehouseId)->where('on_hand', '>', 0))
            ->orderBy('code')->first();
        if ($empty && $this->inv->locCheck($productId, $empty->id)['ok']) {
            return ['code' => $empty->code, 'reasonAr' => 'أول موقع شاغر من فئة التخزين المطابقة', 'reasonEn' => 'First free bin of the matching storage class'];
        }

        return null;
    }

    // ───────────── transitions ─────────────

    /** @param  array  $opts  ship only — see ship() */
    public function act(AuthUser $user, string $id, string $action, ?string $note = null, array $opts = []): array
    {
        $to = self::ACTIONS[$action] ?? throw AppError::validation('BAD_ACTION', "إجراء غير معروف: {$action}", "Unknown action {$action}");
        if ($action === 'ship') {
            return $this->ship($user, $id, $note, $opts);
        }

        return DB::transaction(function () use ($user, $id, $action, $to, $note) {
            $t = $this->load($id, true);
            if (in_array($action, ['cancel', 'reject'], true) && in_array($t->status, ['transit', 'received', 'partial', 'done'], true)) {
                throw AppError::rule('TRANSFER_SHIPPED', 'لا يمكن الإلغاء بعد الشحن — استخدم تحويلًا عكسيًا', 'Cannot cancel/reject after shipping — create a reverse transfer');
            }
            if ($action === 'close' && $t->status === 'partial' && ($open = $this->onTheRoad($t)) > 0) {
                throw AppError::rule('TRANSFER_VARIANCE_OPEN', "لا يُقفل التحويل {$t->number} وفيه {$open} وحدة لم تُستلم ولم تُسوَّ — استلم الباقي أو سوِّ الفرق (خسارة / أُعيد للمصدر) أولًا",
                    "Transfer {$t->number} cannot close with {$open} unit(s) neither received nor resolved — receive the rest or resolve the variance (loss / returned to source) first", ['openQty' => $open]);
            }
            $this->assertTransition($t, $to); // before anything is held or released
            $extra = match ($action) {
                'approve' => ['approved_by_id' => $user->id, 'approved_at' => now()], 'close' => ['closed_at' => now()], default => [],
            };
            if ($action === 'approve') {
                $held = $this->holdLines($t);
                $this->audit->log($user, ['action' => 'TRANSFER.RESERVE', 'entityType' => 'WarehouseTransfer', 'entityId' => $t->id, 'entityNumber' => $t->number,
                    'newValue' => ['lines' => $t->lines->count(), 'heldQty' => $held]]);
            }
            if (in_array($action, ['cancel', 'reject'], true)) {
                $released = $this->inv->releaseHolds(self::HOLD_OWNER, $t->id);
                if ($released > 0) {
                    $this->audit->log($user, ['action' => 'TRANSFER.RELEASE', 'entityType' => 'WarehouseTransfer', 'entityId' => $t->id, 'entityNumber' => $t->number,
                        'newValue' => ['releasedQty' => $released, 'by' => $action]]);
                }
            }

            return $this->present($this->setStatus($user, $t, $to, $note, $extra), $this->seesCost($user));
        });
    }

    /**
     * Holds every line that is not held yet in its source bin. A line the bin cannot cover refuses the whole call,
     * naming the line and the reason (short / quarantined / frozen by a count). Returns the quantity newly held.
     */
    private function holdLines(WarehouseTransfer $t): int
    {
        $held = 0;
        foreach ($t->lines as $l) {
            if ($this->inv->activeHolds(self::HOLD_OWNER, $t->id, $l->id)->isNotEmpty()) {
                continue;
            }
            try {
                $this->inv->hold([
                    'ownerType' => self::HOLD_OWNER, 'ownerId' => $t->id, 'ownerNumber' => $t->number, 'ownerLineId' => $l->id,
                    'productId' => $l->product_id, 'binId' => $l->from_bin_id, 'batchId' => $l->batch_id, 'qty' => $l->qty,
                ]);
            } catch (AppError $e) {
                if ($e->errorCode !== 'INSUFFICIENT_AVAILABLE') {
                    throw $e;
                }
                $row = $this->balanceRow($l->product_id, $l->from_bin_id, $l->batch_id);
                [$why, $whyAr, $whyEn] = match (true) {
                    (bool) $row?->blocked => ['blocked', ' (الموقع مجمد لجرد جارٍ)', ' (the bin is frozen by an open count)'],
                    (bool) $row?->quarantine => ['quarantine', ' (الرصيد في الحجر)', ' (the stock is quarantined)'],
                    default => ['short', '', ''],
                };
                $available = (int) (((array) $e->details)['available'] ?? 0);
                $code = $l->fromBin?->code;
                throw AppError::rule('INSUFFICIENT_AVAILABLE',
                    "سطر {$l->line_no} ({$l->product?->sku}): المتاح في {$code} ({$available}) أقل من {$l->qty}{$whyAr} — لا يمكن حجز التحويل",
                    "Line {$l->line_no} ({$l->product?->sku}): available at {$code} ({$available}) is less than {$l->qty}{$whyEn} — the transfer cannot be reserved",
                    ['line' => $l->line_no, 'sku' => $l->product?->sku, 'bin' => $code, 'available' => $available, 'requested' => $l->qty, 'reason' => $why]);
            }
            $held += $l->qty;
        }

        return $held;
    }

    /**
     * ready → transit: every held line leaves its source bin as a `transit` movement, and the line keeps the cost it
     * left at (the receipt is valued at it). All-or-nothing.
     *
     * `$opts` says who carries the goods and is stored as given. `tripId` (id or number) is there for the trip link:
     * the transfer then takes the trip's vehicle and driver unless they are named explicitly.
     *
     * @param  array{carrier?:?string, waybill?:?string, vehicleCode?:?string, driverCode?:?string, tripId?:?string}  $opts
     */
    public function ship(AuthUser $user, string $id, ?string $note = null, array $opts = []): array
    {
        return DB::transaction(function () use ($user, $id, $note, $opts) {
            $t = $this->load($id, true);
            $this->assertTransition($t, 'transit');
            $by = $this->carrier($opts);
            $carrier = $by['columns'];
            // a transfer approved before holds existed is held now — with the same refusal as an approval
            if (($late = $this->holdLines($t)) > 0) {
                $this->audit->log($user, ['action' => 'TRANSFER.RESERVE', 'entityType' => 'WarehouseTransfer', 'entityId' => $t->id, 'entityNumber' => $t->number,
                    'newValue' => ['lines' => $t->lines->count(), 'heldQty' => $late, 'at' => 'ship']]);
            }
            foreach ($t->lines as $l) {
                $row = $this->balanceRow($l->product_id, $l->from_bin_id, $l->batch_id);
                $code = $l->fromBin?->code;
                if ($row?->blocked) {
                    throw AppError::rule('ROW_BLOCKED', "سطر {$l->line_no}: الموقع {$code} مجمد لجرد جارٍ — اشحن بعد اعتماد الجرد، أو ألغِ التحويل ليُفك حجزه",
                        "Line {$l->line_no}: bin {$code} is frozen by an open count — ship after the count is approved, or cancel the transfer to release its hold", ['line' => $l->line_no, 'bin' => $code]);
                }
                if ($row?->quarantine) {
                    throw AppError::rule('SOURCE_QUARANTINED', "سطر {$l->line_no}: رصيد {$code} وُضع في الحجر بعد الاعتماد — ارفع الحجر أولًا، أو ألغِ التحويل ليُفك حجزه",
                        "Line {$l->line_no}: the stock at {$code} was quarantined after approval — release it first, or cancel the transfer to release its hold", ['line' => $l->line_no, 'bin' => $code]);
                }
            }
            $txId = $this->inv->newTxId();
            foreach ($t->lines as $l) {
                $qty = 0;
                $value = 0.0;
                foreach ($this->inv->activeHolds(self::HOLD_OWNER, $t->id, $l->id) as $hold) {
                    $mv = $this->inv->postHold($user, $txId, $hold, [
                        'type' => 'transit', 'reference' => ['type' => 'WarehouseTransfer', 'id' => $t->id, 'number' => $t->number],
                        'note' => "{$t->number} · صرف للتحويل → {$t->toWarehouse->code}",
                    ]);
                    $qty += (int) $mv->qty;
                    $value += (float) $mv->unit_cost * (int) $mv->qty;
                }
                $l->update(['unit_cost' => $qty > 0 ? round($value / $qty, 4) : null]);
            }
            $lines = $t->lines->count();
            $qty = (int) $t->lines->sum('qty');
            $upd = $this->setStatus($user, $t, 'transit', $note, ['shipped_at' => now()] + $carrier);
            // on the company's own vehicle the transfer is a trip: vehicle and driver busy, visible to dispatch, costed
            if ($by['vehicle'] && $by['driver']) {
                $this->transferTrips->start($user, $upd, $by['vehicle'], $by['driver'], $by['trip']);
                $upd = $this->load($upd->id);
            }
            $this->audit->log($user, ['action' => 'TRANSFER.SHIP', 'entityType' => 'WarehouseTransfer', 'entityId' => $t->id, 'entityNumber' => $t->number,
                'newValue' => ['lines' => $lines, 'qty' => $qty, 'carrier' => $upd->carrier, 'waybill' => $upd->waybill, 'vehicle' => $upd->vehicle?->code, 'driver' => $upd->driver?->code, 'trip' => $upd->trip?->number],
                'transactionId' => $txId]);

            return $this->present($upd, $this->seesCost($user));
        });
    }

    /**
     * Who carries the transfer → the columns to store. With the policy `transfers.requireCarrier` a transfer cannot
     * leave without (vehicle + driver) or a named carrier.
     *
     * A vehicle is never named without its driver, nor a driver without the vehicle: the pair becomes a trip
     * (TransferTripService); half of it would be a truck on the road nobody answers for.
     *
     * @return array{columns: array{trip_id:?string, vehicle_id:?string, driver_id:?string, carrier:?string, waybill:?string}, vehicle:?Vehicle, driver:?Driver, trip:?Trip}
     */
    private function carrier(array $opts): array
    {
        $text = fn (string $k) => trim((string) ($opts[$k] ?? '')) ?: null;
        $trip = $vehicle = $driver = null;
        if ($ref = $text('tripId')) {
            $trip = Trip::with(['vehicle', 'driver'])->where(fn ($w) => $w->where('id', $ref)->orWhere('number', $ref))->first()
                ?? throw AppError::notFound('TRIP_NOT_FOUND', "الرحلة {$ref} غير موجودة", "Trip {$ref} not found");
            $vehicle = $trip->vehicle;
            $driver = $trip->driver;
        }
        if ($code = $text('vehicleCode')) {
            $vehicle = Vehicle::where(fn ($w) => $w->where('id', $code)->orWhere('code', $code))->first()
                ?? throw AppError::notFound('VEHICLE_NOT_FOUND', "المركبة {$code} غير موجودة", "Vehicle {$code} not found");
        }
        if ($code = $text('driverCode')) {
            $driver = Driver::where(fn ($w) => $w->where('id', $code)->orWhere('code', $code))->first()
                ?? throw AppError::notFound('DRIVER_NOT_FOUND', "السائق {$code} غير موجود", "Driver {$code} not found");
        }
        $carrier = $text('carrier');
        if (($vehicle === null) !== ($driver === null)) {
            throw AppError::rule('CARRIER_REQUIRED', 'حدّد المركبة والسائق معًا — المركبة بلا سائق مسؤول (أو العكس) لا تُقبل، أو اكتب اسم الناقل الخارجي فقط',
                'Pick the vehicle and the driver together — or name the outside carrier only');
        }
        if ((bool) $this->settings->get('transfers.requireCarrier') && ! $carrier && ! ($vehicle && $driver)) {
            throw AppError::rule('CARRIER_REQUIRED', 'لا يُشحن التحويل بلا مركبة وسائق أو ناقل مسمّى — حدّد المركبة والسائق معًا أو اكتب اسم الناقل',
                'A transfer cannot ship without a vehicle and a driver, or a named carrier — pick both or name the carrier');
        }

        return [
            'columns' => ['trip_id' => $trip?->id, 'vehicle_id' => $vehicle?->id, 'driver_id' => $driver?->id, 'carrier' => $carrier, 'waybill' => $text('waybill')],
            'vehicle' => $vehicle, 'driver' => $driver, 'trip' => $trip,
        ];
    }

    /** The cost per unit a line left the source at (the line's own figure; for a transfer shipped before it was kept, its `transit` movement). */
    private function lineCost(WarehouseTransfer $t, TransferLine $l): ?float
    {
        if ($l->unit_cost !== null) {
            return (float) $l->unit_cost;
        }
        $cost = InventoryMovement::where('type', 'transit')->where('reference_type', 'WarehouseTransfer')->where('reference_id', $t->id)
            ->where('product_id', $l->product_id)->where('src_bin_id', $l->from_bin_id)
            ->when($l->batch_id, fn ($q) => $q->where('batch_id', $l->batch_id), fn ($q) => $q->whereNull('batch_id'))
            ->orderBy('created_at')->orderBy('number')->value('unit_cost');

        return $cost !== null ? (float) $cost : null;
    }

    /**
     * The bin a line is received into: the one named now, else the fallback (the line's own destination bin, or the
     * source bin for goods that came back). It must exist in the warehouse, be active and fit the product — NOW.
     */
    private function receivingBin(string $warehouseId, TransferLine $l, ?string $code, ?Bin $fallback): Bin
    {
        $code = trim((string) $code);
        $bin = $code !== '' ? $this->inv->bin($warehouseId, $code) : $fallback;
        if (! $bin) {
            throw AppError::rule('TO_BIN_REQUIRED', "سطر {$l->line_no}: حدّد موقع الاستلام — لم يُحدَّد موقع وجهة لهذا السطر", "Line {$l->line_no}: choose the receiving bin — the line has no destination bin", ['line' => $l->line_no]);
        }
        $chk = $this->inv->locCheck($l->product_id, $bin->id);
        if (! $chk['ok']) {
            throw AppError::rule('LOC_RULE', "سطر {$l->line_no}: {$chk['why']} — اختر موقعًا آخر", "Line {$l->line_no}: ".($chk['whyEn'] ?? '').' — choose another bin', ['line' => $l->line_no, 'bin' => $bin->code]);
        }

        return $bin;
    }

    /** `trf` movement of a line into a bin, valued at the cost the line left the source at. */
    private function postArrival(AuthUser $user, string $txId, WarehouseTransfer $t, TransferLine $l, int $qty, string $warehouseId, Bin $bin, string $note): InventoryMovement
    {
        $cost = $this->lineCost($t, $l);

        return $this->inv->post($user, $txId, [
            'type' => 'trf', 'productId' => $l->product_id, 'batchId' => $l->batch_id, 'qty' => $qty,
            'to' => ['warehouseId' => $warehouseId, 'binId' => $bin->id],
            'reference' => ['type' => 'WarehouseTransfer', 'id' => $t->id, 'number' => $t->number], 'note' => $note,
        ] + ($cost !== null ? ['unitCost' => $cost] : []));
    }

    /**
     * transit | partial → partial | received. `receivedQty` is what arrived NOW (≤ what is still on the road); a line
     * that is not listed is received in full. Each call adds to `received_qty`, so a transfer can be received in as
     * many deliveries as it takes. The first short receipt raises ONE exception; it is resolved when the rest arrives
     * or when the variance is resolved.
     *
     * @param  array<int,array{lineNo:int, receivedQty:int, toBin?:?string}>  $lines
     */
    public function receive(AuthUser $user, string $id, array $lines, ?string $note = null): array
    {
        return DB::transaction(function () use ($user, $id, $lines, $note) {
            $t = $this->load($id, true);
            if (! in_array($t->status, self::ON_THE_ROAD, true)) {
                throw AppError::rule('TRANSFER_TRANSITION', "الاستلام ممكن فقط والتحويل في العبور أو مستلم جزئيًا (الحالة الحالية: {$this->statusLabel($t->status)})", "Receive is only allowed while in transit or partially received (current: {$t->status})");
            }
            $txId = $this->inv->newTxId();
            $byLine = [];
            foreach ($lines as $l) {
                $lineNo = (int) $l['lineNo'];
                if (! $t->lines->contains('line_no', $lineNo)) {
                    throw AppError::validation('BAD_LINE', "السطر {$lineNo} غير موجود في التحويل", "Line {$lineNo} not in transfer");
                }
                $byLine[$lineNo] = ['qty' => (int) $l['receivedQty'], 'toBin' => $l['toBin'] ?? null];
            }
            $now = 0;
            $details = [];
            foreach ($t->lines as $l) {
                $open = $this->remaining($l);
                $rcv = isset($byLine[$l->line_no]) ? $byLine[$l->line_no]['qty'] : $open; // default: everything still on the road
                if ($rcv > $open) {
                    throw AppError::validation('RECEIVE_EXCEEDS', "سطر {$l->line_no}: المستلم ({$rcv}) أكبر من المتبقي في الطريق ({$open}) — المرسل {$l->qty}", "Line {$l->line_no}: received ({$rcv}) exceeds what is still on the road ({$open}) — shipped {$l->qty}", ['line' => $l->line_no, 'remaining' => $open, 'shipped' => $l->qty]);
                }
                $bin = null;
                if ($rcv > 0) {
                    $bin = $this->receivingBin($t->to_warehouse_id, $l, $byLine[$l->line_no]['toBin'] ?? null, $l->toBin);
                    $short = $rcv < $open ? ' (باقٍ '.($open - $rcv).')' : '';
                    $this->postArrival($user, $txId, $t, $l, $rcv, $t->to_warehouse_id, $bin, "{$t->number} · استلام التحويل من {$t->fromWarehouse->code}{$short}");
                }
                $l->update(['received_qty' => (int) $l->received_qty + $rcv] + ($bin ? ['to_bin_id' => $bin->id] : []));
                $now += $rcv;
                $details[] = ['lineNo' => $l->line_no, 'qty' => $l->qty, 'receivedNow' => $rcv, 'receivedQty' => (int) $l->received_qty, 'remaining' => $this->remaining($l), 'bin' => $bin?->code];
            }
            if ($t->status === 'partial' && $now === 0) {
                throw AppError::rule('RECEIVE_NOTHING', 'لم تُدخل أي كمية — أدخل ما وصل الآن، أو سوِّ الفرق إن لم يعد شيء في الطريق', 'Nothing was entered — enter what arrived now, or resolve the variance if nothing more is coming');
            }
            $open = (int) $t->lines->sum(fn (TransferLine $l) => $this->remaining($l));
            $partial = $open > 0;
            $this->transferTrips->arrived($user, $t, $now, $open);
            if ($now > 0) {
                WaitingOrders::stockArrived(); // orders of the destination warehouse that wait for this stock
            }
            $upd = $this->setStatus($user, $t, $partial ? 'partial' : 'received', $partial ? "استُلم {$now} — باقٍ في الطريق {$open}".($note ? ' — '.$note : '') : $note, ['received_at' => now()]);
            $this->audit->log($user, ['action' => 'TRANSFER.RECEIVE', 'entityType' => 'WarehouseTransfer', 'entityId' => $t->id, 'entityNumber' => $t->number,
                'newValue' => ['partial' => $partial, 'receivedNow' => $now, 'remaining' => $open, 'lines' => $details], 'transactionId' => $txId]);

            $exception = $this->openExceptions($t)->first();
            if ($partial && ! $exception) {
                $short = array_values(array_filter($details, fn ($d) => $d['remaining'] > 0));
                $ar = implode('، ', array_map(fn ($d) => "سطر {$d['lineNo']}: {$d['receivedQty']}/{$d['qty']}", $short));
                $en = implode(', ', array_map(fn ($d) => "line {$d['lineNo']}: {$d['receivedQty']}/{$d['qty']}", $short));
                // success path: the exception is committed together with the receipt
                $exception = $this->exceptions->raise($user, [
                    'kind' => 'transfer', 'severity' => 'w', 'ownerRole' => 'wm', 'entityType' => 'WarehouseTransfer', 'entityId' => $t->id, 'entityNumber' => $t->number,
                    'documentType' => 'WarehouseTransfer', 'documentId' => $t->id, 'documentNumber' => $t->number,
                    'textAr' => "فرق كمية في استلام {$t->number} ({$ar}) — استلم الباقي أو سوِّ الفرق", 'textEn' => "Qty variance receiving {$t->number} ({$en}) — receive the rest or resolve the variance",
                ]);
            }
            if (! $partial) {
                $this->resolveExceptions($user, $t, 'استُلم باقي التحويل كاملًا');
                $this->clearInTransit($user, $t);
                $exception = null;
            }

            return $this->present($upd, $this->seesCost($user)) + ['partial' => $partial, 'exception' => $exception?->toArray(), 'receivedNow' => $now, 'remainingQty' => $open];
        });
    }

    /**
     * partial → received: decides what happened to the quantity that left the source and never reached the destination.
     *  - `loss`     one journal Dr inventory loss / Cr inventory in transit for Σ remaining × ship cost (charged to the
     *               source warehouse; nothing is posted for goods that carry no cost);
     *  - `returned` the remainder is received back into the source warehouse — its original bin unless `toBin` says otherwise.
     * Either way the remainder becomes the line's `variance_qty`, the receipt exception is resolved and the transfer can close.
     *
     * @param  array{resolution:string, note:string, lines?:array<int,array{lineNo:int, toBin?:?string}>}  $dto
     */
    public function resolveVariance(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $t = $this->load($id, true);
            if ($t->status !== 'partial') {
                [$ar, $en] = $t->status === 'transit'
                    ? ['سجّل الاستلام أولًا (أدخل 0 لما لم يصل) ثم سوِّ الفرق', 'record the receipt first (enter 0 for what did not arrive), then resolve the variance']
                    : ["الحالة الحالية: {$this->statusLabel($t->status)}", "current status: {$t->status}"];
                throw AppError::rule('TRANSFER_NO_VARIANCE', "لا فرق مفتوح على التحويل {$t->number} — {$ar}", "Transfer {$t->number} has no open variance — {$en}");
            }
            $short = $t->lines->filter(fn (TransferLine $l) => $this->remaining($l) > 0)->values();
            $resolution = $dto['resolution'];
            $note = trim((string) $dto['note']);
            $bins = [];
            foreach ($dto['lines'] ?? [] as $l) {
                $lineNo = (int) $l['lineNo'];
                if (! $short->contains('line_no', $lineNo)) {
                    throw AppError::validation('BAD_LINE', "السطر {$lineNo} ليس من أسطر الفرق في التحويل", "Line {$lineNo} is not a variance line of this transfer");
                }
                $bins[$lineNo] = $l['toBin'] ?? null;
            }
            $txId = $this->inv->newTxId();
            $qty = 0;
            $details = [];
            $entries = [];
            $costCenter = $this->map->forWarehouse($t->from_warehouse_id)?->id;
            foreach ($short as $l) {
                $open = $this->remaining($l);
                $detail = ['lineNo' => $l->line_no, 'sku' => $l->product?->sku, 'qty' => $open];
                if ($resolution === 'returned') {
                    $bin = $this->receivingBin($t->from_warehouse_id, $l, $bins[$l->line_no] ?? null, $l->fromBin);
                    $this->postArrival($user, $txId, $t, $l, $open, $t->from_warehouse_id, $bin, "{$t->number} · فرق التحويل أُعيد إلى المصدر {$t->fromWarehouse->code}");
                    $detail['bin'] = $bin->code;
                } else {
                    $value = round($open * (float) ($this->lineCost($t, $l) ?? 0), 2);
                    if ($value > 0) {
                        $dims = ['productId' => $l->product_id, 'warehouseId' => $t->from_warehouse_id, 'costCenterId' => $costCenter, 'referenceType' => 'TransferLine', 'referenceId' => $l->id];
                        $desc = "{$l->product?->sku} × {$open} · فرق التحويل {$t->number}";
                        $entries[] = ['role' => 'inventory_loss', 'debit' => $value, 'description' => $desc] + $dims;
                        $entries[] = ['role' => 'inventory_in_transit', 'credit' => $value, 'description' => $desc] + $dims;
                    }
                }
                $l->update(['variance_qty' => (int) $l->variance_qty + $open]);
                $qty += $open;
                $details[] = $detail;
            }
            // one journal per transfer, idempotent on its key; goods that carry no cost post nothing
            $journal = $entries ? $this->ledger->post($user, [
                'eventKey' => "trf-variance:{$t->id}", 'date' => now(),
                'source' => ['type' => 'WarehouseTransfer', 'id' => $t->id, 'number' => $t->number],
                'memo' => "خسارة فرق التحويل {$t->number} ({$t->fromWarehouse->code} → {$t->toWarehouse->code}): {$qty} وحدة لم تصل — {$note}",
                'lines' => $entries,
            ]) : null;
            $label = $resolution === 'loss' ? ['خسارة مخزون', 'written off as a loss'] : ['أُعيد إلى المصدر', 'returned to the source'];
            $upd = $this->setStatus($user, $t, 'received', "تسوية الفرق ({$qty}): {$label[0]} — {$note}",
                ['variance_resolution' => $resolution, 'variance_note' => $note, 'variance_journal_id' => $journal?->id, 'received_at' => $t->received_at ?? now()]);
            $this->audit->log($user, ['action' => 'TRANSFER.VARIANCE', 'entityType' => 'WarehouseTransfer', 'entityId' => $t->id, 'entityNumber' => $t->number,
                'newValue' => ['resolution' => $resolution, 'qty' => $qty, 'note' => $note, 'journal' => $journal?->number, 'lines' => $details], 'transactionId' => $txId]);
            $this->notify->activity($user, 'WarehouseTransfer', $t->id, $t->number,
                "سُوّي فرق التحويل {$t->number}: {$qty} وحدة — {$label[0]}", "Variance of transfer {$t->number} resolved: {$qty} unit(s) {$label[1]}", $resolution === 'loss' ? ['wm', 'finance'] : ['wm']);
            $this->resolveExceptions($user, $t, "تسوية الفرق: {$label[0]} — {$note}");
            $this->clearInTransit($user, $t);

            return $this->present($upd, $this->seesCost($user)) + ['resolution' => $resolution, 'varianceQty' => $qty, 'journal' => $journal ? ['id' => $journal->id, 'number' => $journal->number] : null];
        });
    }

    /** The receipt exceptions of the transfer that are still open. */
    private function openExceptions(WarehouseTransfer $t)
    {
        return OpsException::where('entity_type', 'WarehouseTransfer')->where('entity_id', $t->id)->where('kind', 'transfer')
            ->whereIn('status', ['open', 'ack'])->orderBy('created_at')->orderBy('id')->get();
    }

    private function resolveExceptions(AuthUser $user, WarehouseTransfer $t, string $note): void
    {
        foreach ($this->openExceptions($t) as $e) {
            $this->exceptions->setStatus($user, $e->id, 'resolved', $note);
        }
    }

    /**
     * Once nothing of the transfer is on the road, its journals must leave the in-transit account at zero. Arrivals
     * are valued line by line at the ship cost, so all that can remain is the rounding of quantity × 4-decimal cost
     * over several receipts (or the valuation difference of a transfer received before ship costs were kept). That
     * remainder moves to the destination warehouse's inventory account in one clearing journal — which also keeps
     * "a transfer does not change the company's stock value" true to the cent.
     */
    private function clearInTransit(AuthUser $user, WarehouseTransfer $t): ?Journal
    {
        $journals = Journal::where('source_type', 'WarehouseTransfer')->where('source_id', $t->id)->where('status', '!=', 'draft')->pluck('id');
        if ($journals->isEmpty()) {
            return null;
        }
        $account = $this->map->role('inventory_in_transit');
        $sum = JournalLine::whereIn('journal_id', $journals)->where('account_id', $account->id)->selectRaw('coalesce(sum(debit), 0) as debit, coalesce(sum(credit), 0) as credit')->first();
        $left = round((float) $sum->debit - (float) $sum->credit, 2);
        if (abs($left) < 0.005) {
            return null;
        }
        $dims = ['warehouseId' => $t->to_warehouse_id, 'costCenterId' => $this->map->forWarehouse($t->to_warehouse_id)?->id, 'referenceType' => 'WarehouseTransfer', 'referenceId' => $t->id];
        $desc = "تسوية مخزون في الطريق · {$t->number}";
        [$inventory, $transit] = $left > 0 ? ['debit', 'credit'] : ['credit', 'debit'];

        return $this->ledger->post($user, [
            'eventKey' => "trf-clear:{$t->id}", 'date' => now(),
            'source' => ['type' => 'WarehouseTransfer', 'id' => $t->id, 'number' => $t->number],
            'memo' => "تسوية رصيد مخزون في الطريق للتحويل {$t->number} بعد اكتمال استلامه (فرق تقريب / تقييم)",
            'lines' => [
                ['role' => 'inventory', $inventory => abs($left), 'description' => $desc] + $dims,
                ['role' => 'inventory_in_transit', $transit => abs($left), 'description' => $desc] + $dims,
            ],
        ]);
    }
}
