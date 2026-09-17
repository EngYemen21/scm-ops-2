<?php

namespace App\Services\Inventory;

use App\Models\Batch;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\OpsException;
use App\Models\Product;
use App\Models\StatusHistory;
use App\Models\TransferLine;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Exceptions\ExceptionsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Inter-warehouse transfers (§11): draft → requested → approved → picking → ready → transit → received | partial → done.
 * Ship posts `transit` movements (stock leaves the source and is in transit = not sellable); receive posts `trf`
 * movements into the destination bin. Reject/cancel only before shipping.
 */
class TransfersService
{
    /** Controller action → target status. */
    public const ACTIONS = [
        'submit' => 'requested', 'approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled',
        'start-picking' => 'picking', 'picking-done' => 'ready', 'ship' => 'transit', 'close' => 'done',
    ];

    private const STATUS_AR = [
        'draft' => 'مسودة', 'requested' => 'بانتظار الاعتماد', 'approved' => 'معتمد — جاهز للتجهيز', 'picking' => 'قيد التجهيز', 'ready' => 'جاهز للشحن',
        'transit' => 'In Transit — في العبور', 'received' => 'مستلم — بانتظار الإقفال', 'partial' => 'مستلم جزئيًا', 'done' => 'مكتمل ✓', 'rejected' => 'مرفوض', 'cancelled' => 'ملغى',
    ];

    private const REASONS = [
        'shortage' => ['تغطية نقص', 'Cover shortage'], 'rebalance' => ['إعادة توازن المخزون', 'Rebalance stock'], 'season' => ['موسم / طلب متوقع', 'Seasonal demand'],
        'surplus' => ['إرجاع فائض', 'Return surplus'], 'other' => ['أخرى', 'Other'],
    ];

    private const RELATIONS = ['fromWarehouse', 'toWarehouse', 'lines.product', 'lines.batch', 'lines.fromBin.zone', 'lines.toBin.zone'];

    public function __construct(
        private readonly InventoryService $inv,
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly ExceptionsService $exceptions,
    ) {}

    private function load(string $id): WarehouseTransfer
    {
        $t = WarehouseTransfer::with(self::RELATIONS)->where(fn ($w) => $w->where('id', $id)->orWhere('number', $id))->first()
            ?? throw AppError::notFound('TRANSFER_NOT_FOUND', "التحويل {$id} غير موجود", "Transfer {$id} not found");
        $t->setRelation('lines', $t->lines->sortBy('line_no')->values());

        return $t;
    }

    /** The transfer with the reference's include shape (warehouses + lines with product / batch / bins). */
    private function present(WarehouseTransfer $t): array
    {
        return [
            'fromWarehouse' => Shapes::warehouse($t->fromWarehouse), 'toWarehouse' => Shapes::warehouse($t->toWarehouse),
            'lines' => $t->lines->map(fn (TransferLine $l) => [
                'product' => Shapes::product($l->product), 'batch' => Shapes::batch($l->batch), 'fromBin' => Shapes::bin($l->fromBin), 'toBin' => Shapes::bin($l->toBin),
            ] + $l->toArray())->all(),
        ] + $t->toArray();
    }

    private function statusLabel(string $status): string
    {
        return self::STATUS_AR[$status] ?? $status;
    }

    /** Validates the transition, updates the row (+ $extra columns), writes audit / history / activity. Returns the fresh transfer. */
    private function setStatus(AuthUser $user, WarehouseTransfer $t, string $to, ?string $note = null, array $extra = []): WarehouseTransfer
    {
        $from = $t->status;
        if (! Sm::can('TRANSFER_TRANSITIONS', $from, $to)) {
            throw AppError::rule('TRANSFER_TRANSITION', "انتقال غير مسموح: {$this->statusLabel($from)} ← {$this->statusLabel($to)}", "Invalid transition {$from} → {$to}");
        }
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
     * @param  array{fromWarehouseCode:string, toWarehouseCode:string, date:string, eta?:?string, reasonCode:string, notes?:?string, submit:bool,
     *               lines:array<int,array{sku:string, qty:int, batchNo?:?string, fromBin:string, toBin:string}>}  $dto
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
                $toBin = $this->inv->bin($to->id, $l['toBin']);
                $chk = $this->inv->locCheck($product->id, $toBin->id);
                if (! $chk['ok']) {
                    throw AppError::rule('LOC_RULE', "سطر {$no}: {$chk['why']}", "Line {$no}: ".($chk['whyEn'] ?? ''));
                }
                $row = $this->balanceRow($product->id, $fromBin->id, $batch?->id);
                $available = $row && ! $row->quarantine && ! $row->blocked ? $row->on_hand - $row->reserved : 0;
                if ($qty > $available) {
                    throw AppError::rule('INSUFFICIENT_AVAILABLE', "سطر {$no}: المتاح في {$fromBin->code} ({$available}) أقل من {$qty}", "Line {$no}: available at {$fromBin->code} ({$available}) is less than {$qty}", ['line' => $no, 'available' => $available, 'requested' => $qty]);
                }
                $lines[] = ['line_no' => $no, 'qty' => $qty, 'batch_no' => $batch?->batch_no, 'product_id' => $product->id, 'batch_id' => $batch?->id, 'from_bin_id' => $fromBin->id, 'to_bin_id' => $toBin->id];
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

            return $this->present($dto['submit'] ? $this->setStatus($user, $t, 'requested') : $this->load($t->id));
        });
    }

    /** @param  array{status?:?string, warehouse?:?string, from?:?string, to?:?string}  $f */
    public function list(Paging $page, array $f): array
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

        return $page->paginate($query, function (WarehouseTransfer $t) {
            $t->setRelation('lines', $t->lines->sortBy('line_no')->values());
            $total = (int) $t->lines->sum('qty');
            $received = (int) $t->lines->sum(fn (TransferLine $l) => $l->received_qty ?? 0);

            return $this->present($t) + ['totalQty' => $total, 'receivedQty' => $received, 'inTransitQty' => $t->status === 'transit' ? $total - $received : 0];
        });
    }

    public function get(string $id): array
    {
        $t = $this->load($id);
        $history = StatusHistory::where('entity_type', 'WarehouseTransfer')->where('entity_id', $t->id)->orderBy('at')->orderBy('id')->get();
        $movements = InventoryMovement::where('reference_type', 'WarehouseTransfer')->where('reference_id', $t->id)->orderBy('created_at')->orderBy('number')->get(Shapes::DOC_MOVEMENT_COLUMNS);
        $exceptions = OpsException::where('entity_type', 'WarehouseTransfer')->where('entity_id', $t->id)->orderBy('created_at')->get();

        return $this->present($t) + [
            'history' => $history->map->toArray()->all(),
            'movements' => $movements->map(fn (InventoryMovement $m) => Shapes::docMovement($m))->all(),
            'exceptions' => $exceptions->map(fn (OpsException $e) => ['id' => $e->id, 'number' => $e->number, 'kind' => $e->kind, 'status' => $e->status, 'textAr' => $e->text_ar, 'createdAt' => $e->created_at?->toJSON()])->all(),
            'allowed' => Sm::table('TRANSFER_TRANSITIONS')[$t->status] ?? [],
            'totalQty' => (int) $t->lines->sum('qty'),
            'receivedQty' => (int) $t->lines->sum(fn (TransferLine $l) => $l->received_qty ?? 0),
        ];
    }

    // ───────────── transitions ─────────────

    public function act(AuthUser $user, string $id, string $action, ?string $note = null): array
    {
        $to = self::ACTIONS[$action] ?? throw AppError::validation('BAD_ACTION', "إجراء غير معروف: {$action}", "Unknown action {$action}");
        if ($action === 'ship') {
            return $this->ship($user, $id, $note);
        }

        return DB::transaction(function () use ($user, $id, $action, $to, $note) {
            $t = $this->load($id);
            if (in_array($action, ['cancel', 'reject'], true) && in_array($t->status, ['transit', 'received', 'partial', 'done'], true)) {
                throw AppError::rule('TRANSFER_SHIPPED', 'لا يمكن الإلغاء بعد الشحن — استخدم تحويلًا عكسيًا', 'Cannot cancel/reject after shipping — create a reverse transfer');
            }
            $extra = match ($action) {
                'approve' => ['approved_by_id' => $user->id, 'approved_at' => now()], 'close' => ['closed_at' => now()], default => [],
            };

            return $this->present($this->setStatus($user, $t, $to, $note, $extra));
        });
    }

    /** ready → transit: every line leaves its source bin as a `transit` movement. All-or-nothing (check first, then deduct). */
    public function ship(AuthUser $user, string $id, ?string $note = null): array
    {
        return DB::transaction(function () use ($user, $id, $note) {
            $t = $this->load($id);
            if (! Sm::can('TRANSFER_TRANSITIONS', $t->status, 'transit')) {
                throw AppError::rule('TRANSFER_TRANSITION', "انتقال غير مسموح: {$this->statusLabel($t->status)} ← {$this->statusLabel('transit')}", "Invalid transition {$t->status} → transit");
            }
            $txId = $this->inv->newTxId();
            foreach ($t->lines as $l) {
                $row = $this->balanceRow($l->product_id, $l->from_bin_id, $l->batch_id);
                $avail = $row ? $row->on_hand - $row->reserved : 0;
                if (! $row || $row->blocked || $row->quarantine || $avail < $l->qty) {
                    $shown = $row && ! $row->blocked && ! $row->quarantine ? $avail : 0;
                    throw AppError::rule('INSUFFICIENT_AVAILABLE', "مرفوض: المتاح في {$l->fromBin->code} ({$shown}) أقل من {$l->qty} — لا رصيد سالب", "Rejected: available at {$l->fromBin->code} ({$avail}) is less than {$l->qty} — no negative stock", ['line' => $l->line_no, 'available' => $avail, 'requested' => $l->qty]);
                }
            }
            foreach ($t->lines as $l) {
                $this->inv->post($user, $txId, [
                    'type' => 'transit', 'productId' => $l->product_id, 'batchId' => $l->batch_id, 'qty' => $l->qty,
                    'from' => ['warehouseId' => $t->from_warehouse_id, 'binId' => $l->from_bin_id],
                    'reference' => ['type' => 'WarehouseTransfer', 'id' => $t->id, 'number' => $t->number], 'note' => "{$t->number} · صرف للتحويل → {$t->toWarehouse->code}",
                ]);
            }
            $lines = $t->lines->count();
            $qty = (int) $t->lines->sum('qty');
            $upd = $this->setStatus($user, $t, 'transit', $note, ['shipped_at' => now()]);
            $this->audit->log($user, ['action' => 'TRANSFER.SHIP', 'entityType' => 'WarehouseTransfer', 'entityId' => $t->id, 'entityNumber' => $t->number,
                'newValue' => ['lines' => $lines, 'qty' => $qty], 'transactionId' => $txId]);

            return $this->present($upd);
        });
    }

    /**
     * transit → received | partial: received quantities land in the destination bins as `trf` movements (batch carried).
     * Lines that are not listed are received in full.
     *
     * @param  array<int,array{lineNo:int, receivedQty:int}>  $lines
     */
    public function receive(AuthUser $user, string $id, array $lines, ?string $note = null): array
    {
        return DB::transaction(function () use ($user, $id, $lines, $note) {
            $t = $this->load($id);
            if ($t->status !== 'transit') {
                throw AppError::rule('TRANSFER_TRANSITION', "الاستلام ممكن فقط والتحويل في العبور (الحالة الحالية: {$this->statusLabel($t->status)})", "Receive is only allowed while in transit (current: {$t->status})");
            }
            $txId = $this->inv->newTxId();
            $byLine = [];
            foreach ($lines as $l) {
                $lineNo = (int) $l['lineNo'];
                if (! $t->lines->contains('line_no', $lineNo)) {
                    throw AppError::validation('BAD_LINE', "السطر {$lineNo} غير موجود في التحويل", "Line {$lineNo} not in transfer");
                }
                $byLine[$lineNo] = (int) $l['receivedQty'];
            }
            $partial = false;
            $details = [];
            foreach ($t->lines as $l) {
                $rcv = $byLine[$l->line_no] ?? $l->qty; // default: full quantity
                if ($rcv > $l->qty) {
                    throw AppError::validation('RECEIVE_EXCEEDS', "سطر {$l->line_no}: المستلم ({$rcv}) أكبر من المرسل ({$l->qty})", "Line {$l->line_no}: received ({$rcv}) exceeds shipped ({$l->qty})");
                }
                if (! $l->to_bin_id) {
                    throw AppError::rule('TO_BIN_REQUIRED', "سطر {$l->line_no}: موقع الوجهة غير محدد", "Line {$l->line_no}: destination bin missing");
                }
                if ($rcv < $l->qty) {
                    $partial = true;
                }
                if ($rcv > 0) {
                    $short = $rcv < $l->qty ? ' (ناقص '.($l->qty - $rcv).')' : '';
                    $this->inv->post($user, $txId, [
                        'type' => 'trf', 'productId' => $l->product_id, 'batchId' => $l->batch_id, 'qty' => $rcv,
                        'to' => ['warehouseId' => $t->to_warehouse_id, 'binId' => $l->to_bin_id],
                        'reference' => ['type' => 'WarehouseTransfer', 'id' => $t->id, 'number' => $t->number], 'note' => "{$t->number} · استلام التحويل من {$t->fromWarehouse->code}{$short}",
                    ]);
                }
                $l->update(['received_qty' => $rcv]);
                $details[] = ['lineNo' => $l->line_no, 'qty' => $l->qty, 'receivedQty' => $rcv];
            }
            $upd = $this->setStatus($user, $t, $partial ? 'partial' : 'received', $partial ? 'فروقات كمية — أُنشئ استثناء' : $note, ['received_at' => now()]);
            $this->audit->log($user, ['action' => 'TRANSFER.RECEIVE', 'entityType' => 'WarehouseTransfer', 'entityId' => $t->id, 'entityNumber' => $t->number,
                'newValue' => ['partial' => $partial, 'lines' => $details], 'transactionId' => $txId]);
            $exception = null;
            if ($partial) {
                $short = array_values(array_filter($details, fn ($d) => $d['receivedQty'] < $d['qty']));
                $ar = implode('، ', array_map(fn ($d) => "سطر {$d['lineNo']}: {$d['receivedQty']}/{$d['qty']}", $short));
                $en = implode(', ', array_map(fn ($d) => "line {$d['lineNo']}: {$d['receivedQty']}/{$d['qty']}", $short));
                // success path: the exception is committed together with the receipt
                $exception = $this->exceptions->raise($user, [
                    'kind' => 'transfer', 'severity' => 'w', 'ownerRole' => 'wm', 'entityType' => 'WarehouseTransfer', 'entityId' => $t->id, 'entityNumber' => $t->number,
                    'documentType' => 'WarehouseTransfer', 'documentId' => $t->id, 'documentNumber' => $t->number,
                    'textAr' => "فرق كمية في استلام {$t->number} ({$ar})", 'textEn' => "Qty variance receiving {$t->number} ({$en})",
                ])->toArray();
            }

            return $this->present($upd) + ['partial' => $partial, 'exception' => $exception];
        });
    }
}
