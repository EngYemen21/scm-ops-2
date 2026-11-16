<?php

namespace App\Services\Returns;

use App\Finance\Models\CustomerInvoice;
use App\Finance\Models\CustomerInvoiceLine;
use App\Finance\Models\DebitNote;
use App\Finance\Services\CostingService;
use App\Finance\Services\CustomerInvoiceService;
use App\Finance\Services\DebitNoteService;
use App\Integration\Services\OrderEvents;
use App\Models\Batch;
use App\Models\Bin;
use App\Models\Customer;
use App\Models\FulfillmentOrder;
use App\Models\GoodsReceipt;
use App\Models\GrnLine;
use App\Models\InventoryBalance;
use App\Models\InventoryHold;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\ReturnDecision;
use App\Models\ReturnInspection;
use App\Models\ReturnLine;
use App\Models\ReturnOrder;
use App\Models\ReturnReceiving;
use App\Models\StatusHistory;
use App\Models\Supplier;
use App\Models\Trip;
use App\Models\Warehouse;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Services\Exceptions\ExceptionsService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\WaitingOrders;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sm;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Returns. Two paths, chosen at creation and kept in `source_mode`:
 *
 *  - Goods that physically ARRIVE (customer / delivery returns): requested (pending) → approved → received — only the
 *    quantity that really arrived enters RET-01 (not available) → inspection per line → decision per line, a line may be
 *    split over several decisions (restock → valid bin with its batch · quarantine → QTN-01 · damaged → DMG-01 ·
 *    scrap → out · return to supplier → out + debit note) → closed.
 *  - Goods taken FROM STOCK (`source_mode = stock`: return to a supplier, damaged goods): requested → approved — the
 *    quantity is held in its bin → shipped (out to the supplier with a debit note, or moved to DMG-01) → closed.
 *    Nothing is ever created in RET-01 for them, so the books are not inflated.
 *
 * `pending | approved → cancelled` releases the holds. Every step is a transaction + ledger + audit.
 */
class ReturnsService
{
    /** What the inspector found on a line. */
    public const CONDITIONS = ['good', 'damaged', 'expired', 'defective', 'opened'];

    /** Return types whose goods are already in the warehouse: they need the bin they are taken from. */
    public const FROM_STOCK_TYPES = ['sup', 'dmg'];

    private const REASON_AR = ['damaged' => 'تالف', 'wrong_product' => 'منتج خاطئ', 'wrong_qty' => 'كمية خاطئة', 'expired' => 'منتهي الصلاحية', 'cust_reject' => 'رفض العميل',
        'del_fail' => 'فشل توصيل', 'quality' => 'مشكلة جودة', 'partial' => 'تسليم جزئي', 'other' => 'أخرى'];

    private const TYPE_AR = ['cust' => 'مرتجع عميل', 'sup' => 'إرجاع لمورد', 'del' => 'مرتجع توصيل', 'dmg' => 'أصناف تالفة'];

    private const ENDED_TRIP_STATES = ['closed', 'cancelled', 'failed'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
        private readonly InventoryService $inventory,
        private readonly SettingsService $settings,
        private readonly ExceptionsService $exceptions,
        private readonly DebitNoteService $debitNotes,
    ) {}

    // ───────────── reads ─────────────

    /** @param  array{status?:?string, type?:?string, warehouse?:?string, supplier?:?string, sourceMode?:?string}  $filters */
    public function list(Paging $page, array $filters): array
    {
        $query = ReturnOrder::with($this->with());
        if (! empty($filters['status'])) {
            $query->whereIn('status', array_map('trim', explode(',', $filters['status'])));
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['sourceMode'])) {
            $query->where('source_mode', $filters['sourceMode']);
        }
        if (! empty($filters['warehouse'])) {
            $query->whereHas('warehouse', fn ($w) => $w->where('code', $filters['warehouse']));
        }
        if (! empty($filters['supplier'])) {
            $query->whereHas('supplier', fn ($w) => $w->where('code', $filters['supplier']));
        }
        if ($page->q) {
            $q = $page->q;
            $query->where(fn ($w) => $w->where('number', 'like', "%{$q}%")->orWhere('source_ar', 'like', "%{$q}%")->orWhere('reference', 'like', "%{$q}%"));
        }
        $out = $page->paginate($query->orderByDesc('created_at')->orderByDesc('id'));
        $notes = DebitNote::whereIn('return_id', array_map(fn (ReturnOrder $r) => $r->id, $out['items']))->where('status', '!=', 'cancelled')
            ->orderBy('created_at')->orderBy('number')->get(['id', 'return_id', 'number', 'total', 'status'])->groupBy('return_id');
        $out['items'] = array_map(fn (ReturnOrder $r) => $r->toArray() + [
            'debitNotes' => ($notes->get($r->id)?->map(fn (DebitNote $n) => ['number' => $n->number, 'total' => $n->total, 'status' => $n->status])->values()->all()) ?? [],
        ], $out['items']);

        return $out;
    }

    public function get(string $idOrNumber): array
    {
        $r = ReturnOrder::with($this->with())->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('RTN_NOT_FOUND', 'المرتجع غير موجود', 'Return not found');
        $history = StatusHistory::where('entity_type', 'ReturnOrder')->where('entity_id', $r->id)->orderBy('at')->orderBy('id')->get();
        $movements = InventoryMovement::with(['srcBin:id,code', 'dstBin:id,code'])->where('reference_type', 'ReturnOrder')->where('reference_id', $r->id)
            ->orderBy('created_at')->orderBy('number')->get()
            ->map(fn (InventoryMovement $m) => [
                'number' => $m->number, 'type' => $m->type, 'qty' => $m->qty, 'batchNo' => $m->batch_no, 'createdAt' => $m->created_at?->toJSON(), 'note' => $m->note,
                'srcBin' => $m->srcBin ? ['code' => $m->srcBin->code] : null, 'dstBin' => $m->dstBin ? ['code' => $m->dstBin->code] : null,
            ])->all();

        $row = $r->toArray();
        // what the decision screen needs per line: the quantity to decide, whether a batch must be named, the default supplier
        $open = in_array($r->status, ['received', 'inspect'], true);
        $requireBatch = (bool) $this->settings->get('returns.requireBatchOnRestock');
        foreach ($r->lines->values() as $i => $l) {
            $needsBatch = $open && $requireBatch && (bool) $l->product?->tracks_expiry && ! $l->batch_id;
            $row['lines'][$i] += [
                'pendingQty' => $open ? $this->receivedOf($l) : null,
                'needsBatch' => $needsBatch,
                'batchOptions' => $needsBatch ? $this->batchOptions($r, $l) : [],
                'suggestedSupplier' => $open ? $this->supplierBrief($this->defaultSupplierId($r, $l)) : null,
            ];
        }
        $lineById = $r->lines->keyBy('id');
        $bins = Bin::whereIn('id', $r->decisions->pluck('target_bin_id')->filter()->unique()->all())->pluck('code', 'id');
        $batches = Batch::whereIn('id', $r->decisions->pluck('batch_id')->filter()->unique()->all())->pluck('batch_no', 'id');
        $suppliers = Supplier::whereIn('id', $r->decisions->pluck('supplier_id')->filter()->unique()->all())->get(['id', 'code', 'name_ar', 'name_en'])->keyBy('id');
        $row['decisions'] = $r->decisions->map(function (ReturnDecision $d) use ($lineById, $bins, $batches, $suppliers) {
            $line = $d->line_id ? $lineById->get($d->line_id) : null;
            $s = $d->supplier_id ? $suppliers->get($d->supplier_id) : null;

            return $d->toArray() + [
                'lineNo' => $line?->line_no, 'sku' => $line?->product?->sku, 'binCode' => $d->target_bin_id ? ($bins[$d->target_bin_id] ?? null) : null,
                'batchNo' => $d->batch_id ? ($batches[$d->batch_id] ?? null) : null, 'supplier' => $s ? ['code' => $s->code, 'nameAr' => $s->name_ar, 'nameEn' => $s->name_en] : null,
            ];
        })->values()->all();
        $creditNote = CustomerInvoice::where('return_id', $r->id)->first(['id', 'number', 'total', 'status', 'credit_of_id']);
        $holds = InventoryHold::with(['bin:id,code', 'batch:id,batch_no'])->where('owner_type', 'ReturnOrder')->where('owner_id', $r->id)->where('status', 'active')->orderBy('created_at')->orderBy('id')->get();

        return $row + [
            'flow' => $this->flow($r),
            'holds' => $holds->map(fn (InventoryHold $h) => ['lineId' => $h->owner_line_id, 'bin' => $h->bin?->code, 'batchNo' => $h->batch?->batch_no, 'qty' => $h->qty])->all(),
            'debitNotes' => $this->debitNotes->ofReturn($r->id),
            'creditNote' => $creditNote ? ['number' => $creditNote->number, 'total' => $creditNote->total, 'status' => $creditNote->status,
                'invoice' => $creditNote->credit_of_id ? CustomerInvoice::whereKey($creditNote->credit_of_id)->value('number') : null] : null,
            'po' => $r->po_id ? PurchaseOrder::whereKey($r->po_id)->first(['id', 'number'])?->toArray() : null,
            'grn' => $r->grn_id ? GoodsReceipt::whereKey($r->grn_id)->first(['id', 'number'])?->toArray() : null,
            'history' => $history->toArray(), 'movements' => $movements,
        ];
    }

    // ───────────── create ─────────────

    /**
     * Creates a return. Joins the caller's transaction when there is one (the delivery flow creates the return of a
     * partial / failed delivery already `approved`: the goods are physically on the truck coming back).
     *
     * `sup` / `dmg` returns are taken from stock: every line names its `fromBin` (`source_mode = stock`). A manual
     * customer return may name the delivery it returns (`foNumber` or `invoiceNumber`): receiving it then reverses the
     * cost of sales and issues the credit note (`source_mode = post_delivery`).
     *
     * @param  array{type:string, source:string, reference?:?string, customerCode?:?string, supplierCode?:?string, foNumber?:?string, invoiceNumber?:?string,
     *               tripNumber?:?string, poNumber?:?string, grnNumber?:?string, warehouseCode:string, reasonCode:string, notes?:?string, attachments?:?array,
     *               lines:array<int, array{sku:string, qty:int, batchNo?:?string, fromBin?:?string, unitPrice?:float|int|string|null}>}  $dto
     */
    public function create(AuthUser $user, array $dto, string $status = 'pending'): ReturnOrder
    {
        return DB::transaction(function () use ($user, $dto, $status) {
            $wh = Warehouse::where('code', $dto['warehouseCode'])->first() ?? throw AppError::notFound('WH_NOT_FOUND', 'المستودع غير موجود', 'Warehouse not found');
            $type = $dto['type'];
            $customer = ! empty($dto['customerCode']) ? Customer::where('code', $dto['customerCode'])->first() : null;
            $supplier = ! empty($dto['supplierCode']) ? Supplier::where('code', $dto['supplierCode'])->first() : null;
            if ($type === 'sup' && ! $supplier) {
                throw AppError::validation('SUPPLIER_REQUIRED', 'إرجاع المورد يحتاج مورّدًا مسجلًا', 'Supplier return needs a registered supplier');
            }
            $trip = ! empty($dto['tripNumber']) ? Trip::where('number', $dto['tripNumber'])->first() : null;
            $fo = ! empty($dto['foNumber']) ? FulfillmentOrder::where('number', $dto['foNumber'])->first() : null;
            $fromStock = in_array($type, self::FROM_STOCK_TYPES, true);
            // a manual customer return that names its delivery (never the delivery flow itself, which passes the trip)
            $invoice = null;
            $postDelivery = ! $trip && $type === 'cust' && (! empty($dto['foNumber']) || ! empty($dto['invoiceNumber']));
            if ($postDelivery) {
                [$fo, $invoice] = $this->deliveryReference($dto, $fo);
                if ($customer && $customer->id !== $invoice->customer_id) {
                    throw AppError::rule('CUSTOMER_MISMATCH', "الفاتورة {$invoice->number} ليست للعميل {$customer->name_ar}", "Invoice {$invoice->number} belongs to another customer");
                }
                $customer ??= Customer::find($invoice->customer_id);
            }
            [$po, $grn] = $type === 'sup' ? $this->purchaseReference($dto, $supplier) : [null, null];

            $products = Product::whereIn('sku', array_column($dto['lines'], 'sku'))->get()->keyBy('sku');
            $lines = [];
            foreach (array_values($dto['lines']) as $i => $l) {
                $p = $products->get($l['sku']) ?? throw AppError::validation('SKU_NOT_FOUND', "المنتج {$l['sku']} غير موجود", "Product {$l['sku']} not found");
                $no = $i + 1;
                $qty = (int) $l['qty'];
                $batchNo = trim((string) ($l['batchNo'] ?? '')) ?: null;
                $batch = null;
                if ($batchNo) {
                    $batch = Batch::where('product_id', $p->id)->where('batch_no', $batchNo)->first()
                        ?? throw AppError::validation('BATCH_NOT_FOUND', "الدفعة {$batchNo} غير مسجلة للصنف {$p->name_ar} (السطر {$no}) — اختر دفعة موجودة، أو اترك الحقل فارغًا وحدّدها عند القرار",
                            "Batch {$batchNo} does not exist for {$p->sku} (line {$no})", ['lineNo' => $no, 'field' => "lines.{$i}.batchNo"]);
                }
                $fromBinCode = trim((string) ($l['fromBin'] ?? '')) ?: null;
                $fromBin = null;
                if ($fromStock) {
                    if (! $fromBinCode) {
                        throw AppError::rule('FROM_BIN_REQUIRED', "السطر {$no} ({$p->name_ar}): ".self::TYPE_AR[$type].' يؤخذ من المخزون — حدّد الموقع الذي تُسحب منه الكمية (لا يُنشأ رصيد في منطقة المرتجعات)',
                            "Line {$no}: a supplier / damaged return is taken from stock — name the bin it leaves from", ['lineNo' => $no, 'field' => "lines.{$i}.fromBin"]);
                    }
                    [$fromBin, $batch] = $this->sourceRow($wh, $p, $fromBinCode, $batch, $qty, $type, $no);
                } elseif ($fromBinCode) {
                    throw AppError::validation('FROM_BIN_NOT_ALLOWED', "السطر {$no}: موقع المصدر لمرتجعات المخزون فقط (إرجاع لمورد / تالف) — مرتجع العميل يُستلم في منطقة المرتجعات",
                        "Line {$no}: a source bin applies only to supplier / damaged returns", ['lineNo' => $no, 'field' => "lines.{$i}.fromBin"]);
                }
                // goods coming back from a delivery without a named batch: they are the batches the order was loaded from
                // (last loaded first) — one return line per batch, so a tracked product is never restocked batchless
                $split = [[$batch, $qty]];
                if (! $batch && $fo && $p->tracks_expiry) {
                    $loaded = $this->loadedPerBatch($fo, $p);
                    // off the truck: spread over the loaded batches · a customer returning later: only when there is one batch it can be —
                    // which of several delivered batches came back is for the inspector to say
                    $split = $trip ? $this->spreadOverBatches($loaded, $qty) : [[count($loaded) === 1 ? Batch::find(array_key_first($loaded)) : null, $qty]];
                }
                foreach ($split as [$lineBatch, $lineQty]) {
                    $lines[] = [
                        'line_no' => count($lines) + 1, 'product_id' => $p->id, 'qty' => $lineQty, 'batch_no' => $lineBatch?->batch_no, 'batch_id' => $lineBatch?->id, 'expiry_date' => $lineBatch?->expiry_date,
                        'from_bin_id' => $fromBin?->id, 'supplier_id' => $type === 'sup' ? $supplier->id : null,
                        'unit_price' => $type === 'sup' && isset($l['unitPrice']) && $l['unitPrice'] !== '' && $l['unitPrice'] !== null ? round((float) $l['unitPrice'], 4) : null,
                    ];
                }
            }
            if ($postDelivery) {
                $this->assertWithinInvoice($invoice, $lines, $products->keyBy('id'));
            }
            $notes = ($dto['notes'] ?? null) ?: null;
            $reasonAr = self::REASON_AR[$dto['reasonCode']] ?? $dto['reasonCode'];
            $number = $this->numbering->next('RTN');
            $status = $fromStock ? 'pending' : $status; // stock is held at approval, so a return from stock always starts as a request
            $r = ReturnOrder::create([
                'number' => $number, 'type' => $type, 'source_ar' => $dto['source'], 'source_en' => $dto['source'], 'reference' => ($dto['reference'] ?? null) ?: ($invoice?->number ?? $po?->number ?? $grn?->number),
                'customer_id' => $customer?->id, 'supplier_id' => $supplier?->id, 'fo_id' => $fo?->id, 'trip_id' => $trip?->id, 'warehouse_id' => $wh->id, 'status' => $status,
                'source_mode' => $fromStock ? 'stock' : ($postDelivery ? 'post_delivery' : null), 'po_id' => $po?->id, 'grn_id' => $grn?->id,
                'reason_code' => $dto['reasonCode'], 'reason_ar' => $reasonAr.($notes ? ' — '.$notes : ''), 'reason_en' => $dto['reasonCode'], 'notes' => $notes,
                'attachments' => array_values($dto['attachments'] ?? []), 'created_by_id' => $user->id, 'created_by' => $user->username,
            ]);
            foreach ($lines as $line) {
                ReturnLine::create(['return_id' => $r->id] + $line);
            }
            $this->audit->log($user, ['action' => 'RTN.CREATE', 'entityType' => 'ReturnOrder', 'entityId' => $r->id, 'entityNumber' => $number,
                'newValue' => ['type' => $type, 'reason' => $dto['reasonCode'], 'sourceMode' => $r->source_mode, 'invoice' => $invoice?->number, 'po' => $po?->number, 'grn' => $grn?->number, 'lines' => $dto['lines']]]);
            $this->audit->status($user, 'ReturnOrder', $r->id, $number, null, $status);
            $typeAr = self::TYPE_AR[$type] ?? 'مرتجع';
            $this->notify->activity($user, 'ReturnOrder', $r->id, $number, "أُنشئ {$typeAr} {$number} — سبب: {$reasonAr}".($supplier ? " — المورد {$supplier->name_ar}" : ''),
                "Return {$number} created", $type === 'sup' ? ['wm', 'super', 'proc'] : ['wm', 'super']);
            $this->emitReturn($r, 'return.created', ['reasonAr' => $reasonAr, 'lines' => $dto['lines']]);

            return $r->refresh();
        });
    }

    // ───────────── state machine ─────────────

    /** pending → approved. A return from stock holds every line in its bin here, so nothing else can take the quantity. */
    public function approve(AuthUser $user, string $id): array
    {
        return $this->locked($id, function (ReturnOrder $r) use ($user) {
            $this->assertCan($r, 'approved');
            $this->assertHasSource($r);
            $held = 0;
            if ($r->source_mode === 'stock') {
                foreach ($r->lines as $l) {
                    try {
                        $this->inventory->hold(['ownerType' => 'ReturnOrder', 'ownerId' => $r->id, 'ownerNumber' => $r->number, 'ownerLineId' => $l->id,
                            'productId' => $l->product_id, 'binId' => $l->from_bin_id, 'batchId' => $l->batch_id, 'qty' => $l->qty, 'allowQuarantine' => true]);
                    } catch (AppError $e) {
                        if ($e->errorCode !== 'INSUFFICIENT_AVAILABLE') {
                            throw $e;
                        }
                        throw AppError::rule('INSUFFICIENT_AVAILABLE', "السطر {$l->line_no} ({$l->product->name_ar}): {$e->messageAr}", "Line {$l->line_no} ({$l->product->sku}): {$e->messageEn}",
                            ['lineNo' => $l->line_no] + (is_array($e->details) ? $e->details : []));
                    }
                    $held += $l->qty;
                }
            }
            $this->move($user, $r, 'approved', ['approved_at' => now()], $held ? "حُجزت {$held} وحدة في مواقعها" : null);
            if ($held) {
                $this->notify->activity($user, 'ReturnOrder', $r->id, $r->number, "اعتُمد {$this->typeAr($r)} {$r->number} — حُجزت {$held} وحدة في مواقعها بانتظار الشحن",
                    "Return {$r->number} approved — {$held} units held for shipping", $r->type === 'sup' ? ['proc', 'wm'] : ['wm']);
            }
            $this->emitReturn($r, 'return.approved');
        });
    }

    public function reject(AuthUser $user, string $id, ?string $reason = null): array
    {
        return $this->locked($id, function (ReturnOrder $r) use ($user, $reason) {
            $this->assertCan($r, 'rejected');
            $this->move($user, $r, 'rejected', [], $reason);
            $this->emitReturn($r, 'return.rejected');
        });
    }

    /**
     * pending | approved → cancelled; the holds of a return from stock go back to available. A return created by a
     * delivery has its goods on the truck: it is cancelled only once the trip is over and nothing came back.
     */
    public function cancel(AuthUser $user, string $id, ?string $reason = null): array
    {
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 3) {
            throw AppError::validation('REASON_REQUIRED', 'اكتب سبب إلغاء المرتجع', 'A cancellation reason is required', [['path' => 'reason', 'message' => 'اكتب سبب الإلغاء']]);
        }

        return $this->locked($id, function (ReturnOrder $r) use ($user, $reason) {
            $this->assertCan($r, 'cancelled');
            $trip = $r->trip_id ? Trip::whereKey($r->trip_id)->first(['id', 'number', 'status']) : null;
            if ($trip && ! in_array($trip->status, self::ENDED_TRIP_STATES, true)) {
                throw AppError::rule('RTN_ON_TRUCK', "بضاعة المرتجع {$r->number} على المركبة — الرحلة {$trip->number} لم تُقفل بعد: استلمه عند عودتها، أو ألغِه بعد إقفال الرحلة إن لم يعد شيء",
                    "The goods of {$r->number} are on the truck — trip {$trip->number} is not closed yet", ['trip' => $trip->number, 'tripStatus' => $trip->status]);
            }
            $released = $this->inventory->releaseHolds('ReturnOrder', $r->id);
            $from = $r->status;
            $this->move($user, $r, 'cancelled', ['cancelled_at' => now(), 'cancelled_by' => $user->username, 'cancel_reason' => $reason], $reason);
            $this->audit->log($user, ['action' => 'RTN.CANCEL', 'entityType' => 'ReturnOrder', 'entityId' => $r->id, 'entityNumber' => $r->number,
                'oldValue' => $from, 'newValue' => ['reason' => $reason, 'releasedQty' => $released, 'trip' => $trip?->number]]);
            $text = "أُلغي {$this->typeAr($r)} {$r->number} — {$reason}".($released ? " (أُعيدت {$released} وحدة محجوزة إلى المتاح)" : '');
            $this->notify->activity($user, 'ReturnOrder', $r->id, $r->number, $text, "Return {$r->number} cancelled — {$reason}", $r->type === 'sup' ? ['proc', 'wm'] : ['wm', 'sales']);
            if ($trip) {
                // the goods left the warehouse at their load cost and never came back: someone has to account for them
                $qty = (int) $r->lines->sum('qty');
                $this->exceptions->raise($user, ['kind' => 'shortage', 'severity' => 'w', 'ownerRole' => 'disp', 'entityType' => 'ReturnOrder', 'entityId' => $r->id, 'entityNumber' => $r->number,
                    'documentType' => 'Trip', 'documentId' => $trip->id, 'documentNumber' => $trip->number,
                    'textAr' => "أُلغي مرتجع التوصيل {$r->number} دون استلام بضاعته ({$qty} وحدة) بعد إقفال الرحلة {$trip->number} — لم تعد للمستودع وقيمتها ما زالت في «بضاعة في الطريق»: تحقّق وسوِّها مع المالية",
                    'textEn' => "Delivery return {$r->number} was cancelled without its goods ({$qty} units) after trip {$trip->number} closed — their value is still in goods in transit"]);
                $this->notify->activity($user, 'ReturnOrder', $r->id, $r->number, "مرتجع توصيل ملغى دون استلام: {$r->number} ({$qty} وحدة) — قيمة البضاعة ما زالت في حساب «بضاعة في الطريق» وتحتاج تسوية",
                    "Cancelled delivery return {$r->number}: its goods are still valued in goods in transit", ['finance']);
            }
            $this->emitReturn($r, 'return.cancelled', ['reason' => $reason]);
        });
    }

    /**
     * Receive: what physically arrived enters the returns area (RET-01) — not available, awaiting inspection.
     *
     * @param  array<int, array{lineNo:int, receivedQty:int}>|null  $lines  quantity that arrived per line (0 … requested); a line not listed arrived in full
     */
    public function receive(AuthUser $user, string $id, ?array $lines = null, ?string $notes = null): array
    {
        return $this->locked($id, function (ReturnOrder $r) use ($user, $lines, $notes) {
            $this->assertCan($r, 'received');
            if ($r->source_mode === 'stock') {
                throw AppError::rule('RTN_FROM_STOCK', "المرتجع {$r->number} مأخوذ من المخزون — يُشحن من موقعه ولا يُستلم في منطقة المرتجعات", "Return {$r->number} is taken from stock: it is shipped, not received");
            }
            $this->assertHasSource($r);
            $received = $this->receivedQuantities($r, $lines);
            if (array_sum($received) === 0) {
                throw AppError::rule('NOTHING_RECEIVED', 'لم يُستلم أي صنف — لا يُسجَّل استلام بكمية صفر: ألغِ المرتجع إن لم تصل بضاعته', 'Nothing was received — cancel the return if its goods did not arrive');
            }
            $ret = $this->inventory->specialBin($r->warehouse_id, 'RET-01');
            $txId = $this->inventory->newTxId();
            // finance: goods that never left the truck (POD return) come back out of "goods in transit" at their load cost;
            // a post-delivery customer return reverses the cost of sales and gets a credit note; other returns re-enter at the average cost
            $origin = $this->costOrigin($r);
            $costing = app(CostingService::class);
            $short = [];
            foreach ($r->lines as $l) {
                $qty = $received[$l->id];
                if ($qty > 0) {
                    $this->inventory->post($user, $txId, ['type' => 'ret', 'productId' => $l->product_id, 'batchId' => $l->batch_id, 'qty' => $qty,
                        'costOrigin' => $origin, 'unitCost' => $origin ? $costing->loadCost($r->fo_id, $l->product_id) : null,
                        'to' => ['warehouseId' => $r->warehouse_id, 'binId' => $ret->id], 'reference' => ['type' => 'ReturnOrder', 'id' => $r->id, 'number' => $r->number],
                        'note' => 'استلام مرتجع → منطقة الفحص RET-01'.($qty < $l->qty ? " (استُلم {$qty} من {$l->qty})" : '')]);
                }
                $l->update(['received_qty' => $qty]);
                if ($qty < $l->qty) {
                    $short[] = "{$l->product->name_ar} × ".($l->qty - $qty)." (استُلم {$qty} من {$l->qty})";
                }
            }
            ReturnReceiving::create(['return_id' => $r->id, 'received_by_id' => $user->id, 'received_by' => $user->username, 'received_at' => now(), 'bin_code' => 'RET-01',
                'notes' => trim(($notes ? $notes.' ' : '').($short ? 'نقص: '.implode(' · ', $short) : '')) ?: null]);
            $creditNote = null;
            if ($origin === 'customer_return') {
                $creditNote = app(CustomerInvoiceService::class)->creditFromReturn($user, $this->receivedView($r, $received));
            }
            $this->move($user, $r, 'received', ['received_at' => now()], $short ? 'استلام ناقص: '.implode(' · ', $short) : null, $txId);
            $this->audit->log($user, ['action' => 'RTN.RECEIVE', 'entityType' => 'ReturnOrder', 'entityId' => $r->id, 'entityNumber' => $r->number, 'transactionId' => $txId,
                'newValue' => ['received' => $r->lines->map(fn (ReturnLine $l) => ['lineNo' => $l->line_no, 'qty' => $l->qty, 'receivedQty' => $received[$l->id]])->all(), 'creditNote' => $creditNote?->number]]);
            if ($short) {
                // what did not arrive is somebody's to explain: the driver's run for a delivery return, the warehouse otherwise
                $this->exceptions->raise($user, ['kind' => 'shortage', 'severity' => 'w', 'ownerRole' => $r->trip_id ? 'disp' : 'wm', 'entityType' => 'ReturnOrder', 'entityId' => $r->id, 'entityNumber' => $r->number,
                    'documentType' => $r->trip_id ? 'Trip' : null, 'documentId' => $r->trip_id, 'documentNumber' => $r->trip_id ? Trip::whereKey($r->trip_id)->value('number') : null,
                    'textAr' => "استلام ناقص للمرتجع {$r->number}: ".implode(' · ', $short).($origin === 'pod_return' ? ' — قيمة الناقص باقية في «بضاعة في الطريق»' : ''),
                    'textEn' => "Short receipt on return {$r->number}: ".count($short).' line(s) arrived short']);
            }
            if ($r->source_mode === 'post_delivery' && ! $creditNote) {
                $this->notify->activity($user, 'ReturnOrder', $r->id, $r->number, "استُلم مرتجع العميل {$r->number} ولم يصدر له إشعار دائن (لا فاتورة سارية تغطي الكمية) — راجِعه", "Return {$r->number} received without a credit note", ['finance']);
            }
            $this->emitReturn($r, 'return.received');
        });
    }

    /**
     * received → inspect, with what the inspector found per line; called again while in `inspect` it corrects the record.
     *
     * @param  array<int, array{lineNo:int, inspectedQty:int, condition:string, note?:?string}>|null  $lines
     */
    public function inspect(AuthUser $user, string $id, ?string $findings = null, ?array $lines = null): array
    {
        return $this->locked($id, function (ReturnOrder $r) use ($user, $findings, $lines) {
            $update = $r->status === 'inspect' && ($lines || $findings !== null);
            if (! $update) {
                $this->assertCan($r, 'inspect');
            }
            $recorded = [];
            $byNo = $this->indexLines($lines);
            foreach ($r->lines as $l) {
                $in = $byNo[$l->line_no] ?? null;
                unset($byNo[$l->line_no]);
                if (! $in) {
                    continue;
                }
                $received = $this->receivedOf($l);
                $qty = $in['inspectedQty'];
                if (! is_int($qty) || $qty < 0 || $qty > $received) {
                    throw AppError::validation('BAD_QTY', "الكمية المفحوصة للسطر {$l->line_no} يجب أن تكون بين 0 و{$received} (المستلم)", "Inspected quantity of line {$l->line_no} must be between 0 and {$received}", ['lineNo' => $l->line_no, 'max' => $received]);
                }
                $l->update(['inspected_qty' => $qty, 'condition' => $in['condition'], 'inspect_note' => ($in['note'] ?? null) ?: null]);
                $recorded[] = ['lineNo' => $l->line_no, 'inspectedQty' => $qty, 'condition' => $in['condition']];
            }
            if ($byNo) {
                throw AppError::validation('LINE_NOT_FOUND', 'سطر غير موجود في المرتجع: '.implode('، ', array_keys($byNo)), 'Unknown return line: '.implode(', ', array_keys($byNo)));
            }
            if ($update) {
                if ($findings !== null) {
                    ReturnInspection::where('return_id', $r->id)->update(['findings' => $findings]);
                }
            } else {
                ReturnInspection::create(['return_id' => $r->id, 'inspector_id' => $user->id, 'inspector' => $user->username, 'started_at' => now(), 'findings' => $findings]);
                $this->move($user, $r, 'inspect', ['inspected_at' => now()]);
                $this->emitReturn($r, 'return.inspect');
            }
            if ($recorded || $update) {
                $this->audit->log($user, ['action' => 'RTN.INSPECT', 'entityType' => 'ReturnOrder', 'entityId' => $r->id, 'entityNumber' => $r->number, 'newValue' => ['findings' => $findings, 'lines' => $recorded]]);
            }
        });
    }

    /**
     * Decision after inspection: the received quantity of every line leaves RET-01 through the inventory engine.
     *
     * Either one decision for the whole return (`decision` + optional `binCode` — the bin is used for the lines it
     * fits, the others are placed automatically) or a decision per line, a line split over several parts whose
     * quantities add up to what was received. One movement and one `return_decisions` row per part.
     *
     * @param  array{decision?:?string, binCode?:?string, supplierCode?:?string, note?:?string,
     *               lines?:array<int, array{lineNo:int, parts:array<int, array{qty:int, decision:string, binCode?:?string, batchNo?:?string, expiryDate?:?string, supplierCode?:?string, note?:?string}>}>}  $dto
     */
    public function decide(AuthUser $user, string $idOrNumber, array $dto): array
    {
        return $this->locked($idOrNumber, function (ReturnOrder $r) use ($user, $dto) {
            if ($r->status !== 'inspect') {
                throw AppError::rule('RTN_DECISION_STATE', "القرار غير مسموح في الحالة «{$r->status}» — المسار: اعتماد → استلام → فحص → قرار", "Decision not allowed in status {$r->status}");
            }
            $plan = $this->decisionPlan($r, $dto);
            $txId = $this->inventory->newTxId();
            $ret = $this->inventory->specialBin($r->warehouse_id, 'RET-01');
            $requireBatch = (bool) $this->settings->get('returns.requireBatchOnRestock');
            $reference = ['type' => 'ReturnOrder', 'id' => $r->id, 'number' => $r->number];
            $msgs = $kinds = $recorded = $supplierIds = [];
            foreach ($plan as $item) {
                /** @var ReturnLine $l */
                $l = $item['line'];
                $lineKinds = [];
                $restockBinId = null;
                foreach ($item['parts'] as $part) {
                    $qty = (int) $part['qty'];
                    $decision = $part['decision'];
                    if ($qty <= 0) {
                        throw AppError::validation('BAD_QTY', "كمية القرار في السطر {$l->line_no} يجب أن تكون أكبر من صفر", "Decision quantity on line {$l->line_no} must be positive", ['lineNo' => $l->line_no, 'field' => "{$part['where']}.qty"]);
                    }
                    if ($part['strict'] && $decision !== 'restock' && ! empty($part['binCode'])) {
                        throw AppError::validation('BIN_NOT_APPLICABLE', "السطر {$l->line_no}: الموقع يُحدَّد لإعادة التخزين فقط — الحجر يذهب إلى QTN-01 والتالف إلى DMG-01 تلقائيًا",
                            "Line {$l->line_no}: a bin applies to restock only", ['lineNo' => $l->line_no, 'field' => "{$part['where']}.binCode"]);
                    }
                    $to = null;
                    $type = 'restock';
                    $quarantine = false;
                    $partner = null;
                    $supplier = null;
                    $batchId = $l->batch_id;
                    if ($decision === 'restock') {
                        $batchId = $this->partBatchId($l, $part, $requireBatch && (bool) $l->product->tracks_expiry);
                        $bin = $this->restockBin($r, $l, ($part['binCode'] ?? null) ?: null);
                        $to = ['warehouseId' => $r->warehouse_id, 'binId' => $bin->id];
                        $note = "قرار: إعادة للمخزون → {$bin->code}";
                        $restockBinId ??= $bin->id;
                    } elseif ($decision === 'qtn') {
                        $batchId = $this->partBatchId($l, $part, false);
                        $to = ['warehouseId' => $r->warehouse_id, 'binId' => $this->inventory->specialBin($r->warehouse_id, 'QTN-01')->id];
                        $type = 'qtn';
                        $quarantine = true;
                        $note = 'قرار: حجر بانتظار الجودة → QTN-01';
                    } elseif ($decision === 'dmg') {
                        $batchId = $this->partBatchId($l, $part, false);
                        $to = ['warehouseId' => $r->warehouse_id, 'binId' => $this->inventory->specialBin($r->warehouse_id, 'DMG-01')->id];
                        $type = 'dmg';
                        $note = 'قرار: تالف → منطقة التالف DMG-01 (يبقى في الرصيد ولا يدخل المتاح)';
                    } elseif ($decision === 'dispose') {
                        $type = 'scrap';
                        $note = 'قرار: إعدام موثق (شهادة إتلاف) — يخرج من المخزون';
                    } else { // sup
                        $supplier = $this->partSupplier($r, $l, $part);
                        $type = 'supret';
                        $partner = ['type' => 'supplier', 'id' => $supplier->id];
                        $supplierIds[$supplier->id] = true;
                        $note = "إرجاع للمورد {$supplier->name_ar} — يخرج من المخزون";
                    }
                    if ($to && $batchId !== $l->batch_id) {
                        $this->rebatch($user, $txId, $r, $l, $ret, $qty, $batchId);
                    }
                    $mv = $this->inventory->post($user, $txId, ['type' => $type, 'productId' => $l->product_id, 'batchId' => $batchId, 'qty' => $qty,
                        'from' => ['warehouseId' => $r->warehouse_id, 'binId' => $ret->id], 'to' => $to, 'quarantine' => $quarantine, 'partner' => $partner,
                        'reference' => $reference, 'note' => $note]);
                    ReturnDecision::create(['return_id' => $r->id, 'line_id' => $l->id, 'decision' => $decision, 'qty' => $qty, 'target_bin_id' => $to['binId'] ?? null, 'movement_id' => $mv->id,
                        'batch_id' => $batchId, 'supplier_id' => $supplier?->id, 'condition' => $l->condition, 'decided_by_id' => $user->id, 'decided_by' => $user->username,
                        'note' => ($part['note'] ?? null) ?: null, 'at' => now()]);
                    $lineKinds[$decision] = true;
                    $kinds[$decision] = true;
                    $msgs[] = "{$l->product->name_ar} × {$qty}: {$note}";
                    $recorded[] = ['lineNo' => $l->line_no, 'qty' => $qty, 'decision' => $decision, 'bin' => isset($bin) && $decision === 'restock' ? $bin->code : null, 'movement' => $mv->number];
                }
                $l->update(['decision' => count($lineKinds) === 1 ? array_key_first($lineKinds) : ($lineKinds ? 'mixed' : null),
                    'inspected_qty' => $l->inspected_qty ?? $item['received'], 'bin_id' => $restockBinId]);
            }
            $decision = count($kinds) === 1 ? array_key_first($kinds) : 'mixed';
            if (isset($kinds['restock'])) {
                WaitingOrders::stockArrived();
            }
            $label = $this->decisionAr($decision);
            $r->update(['decision' => $decision, 'decided_by_id' => $user->id, 'decided_by' => $user->username, 'decided_at' => now(), 'closed_at' => now()]);
            $notes = $supplierIds ? $this->debitNotes->issue($user, $r) : [];
            $this->move($user, $r, 'closed', [], $decision, $txId);
            $this->emitReturn($r, 'return.closed', ['decision' => $decision, 'decisionAr' => $label]);
            $this->audit->log($user, ['action' => 'RTN.DECIDE', 'entityType' => 'ReturnOrder', 'entityId' => $r->id, 'entityNumber' => $r->number,
                'field' => 'decision', 'oldValue' => 'inspect', 'newValue' => $decision, 'transactionId' => $txId]);
            $this->audit->log($user, ['action' => 'RTN.DECIDE.PARTS', 'entityType' => 'ReturnOrder', 'entityId' => $r->id, 'entityNumber' => $r->number,
                'field' => 'decisions', 'newValue' => $recorded, 'transactionId' => $txId]);
            $this->notify->activity($user, 'ReturnOrder', $r->id, $r->number, "قرار المرتجع {$r->number}: {$label} — ".implode(' · ', $msgs)
                .($notes ? ' — إشعار مدين '.implode('، ', array_map(fn (DebitNote $n) => $n->number, $notes)) : ''), "Return {$r->number} decision: {$decision}", ['sales', 'wm']);
            if ($supplierIds) {
                $this->notify->event('SupplierReturnCreated', ['rtn' => $r->number, 'supplier' => array_key_first($supplierIds), 'suppliers' => array_keys($supplierIds),
                    'debitNotes' => array_map(fn (DebitNote $n) => $n->number, $notes)]);
            }
        });
    }

    /**
     * approved → shipped → closed for a return taken from stock. `sup`: every held quantity leaves to the supplier
     * (Dr payable / Cr inventory at cost) and the debit note is issued; `dmg`: it moves to DMG-01 (stays on hand).
     *
     * @param  array{carrier?:?string, note?:?string}  $dto
     */
    public function ship(AuthUser $user, string $idOrNumber, array $dto = []): array
    {
        return $this->locked($idOrNumber, function (ReturnOrder $r) use ($user, $dto) {
            if ($r->source_mode !== 'stock') {
                throw AppError::rule('RTN_NOT_FROM_STOCK', "الشحن لمرتجعات المخزون فقط (إرجاع لمورد / تالف من موقعه) — المرتجع {$r->number} يُستلم ثم يُفحص ويُقرَّر", "Only a return taken from stock is shipped — {$r->number} is received, inspected and decided");
            }
            $this->assertCan($r, 'shipped');
            $holds = $this->inventory->activeHolds('ReturnOrder', $r->id);
            if ($holds->isEmpty()) {
                throw AppError::rule('RTN_NOTHING_HELD', "لا كمية محجوزة للمرتجع {$r->number} — ألغِه وأنشئ مرتجعًا جديدًا", "Return {$r->number} holds no stock");
            }
            $txId = $this->inventory->newTxId();
            $reference = ['type' => 'ReturnOrder', 'id' => $r->id, 'number' => $r->number];
            $toSupplier = $r->type === 'sup';
            $dmg = $toSupplier ? null : $this->inventory->specialBin($r->warehouse_id, 'DMG-01');
            $carrier = trim((string) ($dto['carrier'] ?? '')) ?: null;
            $shipNote = trim((string) ($dto['note'] ?? '')) ?: null;
            $lines = $r->lines->keyBy('id');
            $qty = 0;
            foreach ($holds as $hold) {
                $l = $lines->get($hold->owner_line_id);
                $mv = $this->inventory->postHold($user, $txId, $hold, $toSupplier
                    ? ['type' => 'supret', 'partner' => ['type' => 'supplier', 'id' => $r->supplier_id], 'reference' => $reference,
                        'note' => 'إرجاع للمورد '.($r->supplier?->name_ar ?? '').($carrier ? " — الناقل: {$carrier}" : '')]
                    : ['type' => 'dmg', 'to' => ['warehouseId' => $r->warehouse_id, 'binId' => $dmg->id], 'reference' => $reference, 'note' => 'أصناف تالفة من موقعها → منطقة التالف DMG-01']);
                ReturnDecision::create(['return_id' => $r->id, 'line_id' => $l?->id, 'decision' => $toSupplier ? 'sup' : 'dmg', 'qty' => $hold->qty, 'target_bin_id' => $dmg?->id, 'movement_id' => $mv->id,
                    'batch_id' => $hold->batch_id, 'supplier_id' => $toSupplier ? $r->supplier_id : null, 'decided_by_id' => $user->id, 'decided_by' => $user->username, 'note' => $shipNote, 'at' => now()]);
                $l?->update(['decision' => $toSupplier ? 'sup' : 'dmg', 'bin_id' => $dmg?->id]);
                $qty += $hold->qty;
            }
            $decision = $toSupplier ? 'sup' : 'dmg';
            $this->move($user, $r, 'shipped', ['shipped_at' => now(), 'carrier' => $carrier, 'ship_note' => $shipNote, 'decision' => $decision,
                'decided_by_id' => $user->id, 'decided_by' => $user->username, 'decided_at' => now()], trim(($carrier ? "الناقل: {$carrier} " : '').($shipNote ?? '')) ?: null, $txId);
            $this->emitReturn($r, 'return.shipped');
            $notes = $toSupplier ? $this->debitNotes->issue($user, $r) : [];
            $numbers = implode('، ', array_map(fn (DebitNote $n) => $n->number, $notes));
            $this->move($user, $r, 'closed', ['closed_at' => now()], $notes ? "إشعار مدين {$numbers}" : null, $txId);
            $this->emitReturn($r, 'return.closed', ['decision' => $decision, 'decisionAr' => $this->decisionAr($decision)]);
            $this->audit->log($user, ['action' => 'RTN.SHIP', 'entityType' => 'ReturnOrder', 'entityId' => $r->id, 'entityNumber' => $r->number, 'transactionId' => $txId,
                'newValue' => ['qty' => $qty, 'carrier' => $carrier, 'decision' => $decision, 'debitNotes' => array_map(fn (DebitNote $n) => $n->number, $notes)]]);
            if ($toSupplier) {
                $total = number_format(array_sum(array_map(fn (DebitNote $n) => (float) $n->total, $notes)), 2);
                $this->notify->activity($user, 'ReturnOrder', $r->id, $r->number, "شُحن مرتجع المورد {$r->number} ({$qty} وحدة) إلى {$r->supplier?->name_ar}".($notes ? " — إشعار مدين {$numbers} بقيمة {$total} ر.س" : ''),
                    "Supplier return {$r->number} shipped ({$qty} units)".($notes ? " — debit note {$numbers}" : ''), ['proc', 'finance']);
                $this->notify->event('SupplierReturnCreated', ['rtn' => $r->number, 'supplier' => $r->supplier_id, 'suppliers' => [$r->supplier_id], 'debitNotes' => array_map(fn (DebitNote $n) => $n->number, $notes)]);
            } else {
                $this->notify->activity($user, 'ReturnOrder', $r->id, $r->number, "نُقلت أصناف {$r->number} التالفة ({$qty} وحدة) من مواقعها إلى منطقة التالف DMG-01", "Damaged goods of {$r->number} moved to DMG-01 ({$qty} units)", ['wm']);
            }
        });
    }

    /**
     * (Re)issues the debit note of goods that already went back to a supplier: after its note was cancelled (a wrong
     * price is corrected here), or for a return left `shipped` without one. Closes a `shipped` return.
     *
     * @param  array<int, array{lineNo:int, unitPrice:float|int|string}>  $prices
     */
    public function issueDebitNote(AuthUser $user, string $idOrNumber, array $prices = []): array
    {
        return $this->locked($idOrNumber, function (ReturnOrder $r) use ($user, $prices) {
            $sent = ReturnDecision::where('return_id', $r->id)->where('decision', 'sup')->whereNotNull('movement_id')->get();
            if (! in_array($r->status, ['shipped', 'closed'], true) || $sent->isEmpty()) {
                throw AppError::rule('DN_NOTHING_TO_CLAIM', "لا بضاعة مُرجعة لمورد على المرتجع {$r->number} — إشعار المدين يصدر عند الشحن أو عند قرار «للمورد»", "Return {$r->number} has no goods sent back to a supplier");
            }
            $suppliers = $sent->map(fn (ReturnDecision $d) => $d->supplier_id ?: $r->supplier_id)->filter()->unique();
            $covered = DebitNote::where('return_id', $r->id)->where('status', '!=', 'cancelled')->pluck('supplier_id');
            if ($suppliers->diff($covered)->isEmpty()) {
                throw AppError::conflict('DN_EXISTS', "للمرتجع {$r->number} إشعار مدين سارٍ — ألغِه من المالية أولًا لإصدار إشعار مصحح", "Return {$r->number} already has an active debit note — cancel it first");
            }
            $byNo = $this->indexLines($prices);
            foreach ($r->lines as $l) {
                if (isset($byNo[$l->line_no])) {
                    $l->update(['unit_price' => round((float) $byNo[$l->line_no]['unitPrice'], 4)]);
                    unset($byNo[$l->line_no]);
                }
            }
            if ($byNo) {
                throw AppError::validation('LINE_NOT_FOUND', 'سطر غير موجود في المرتجع: '.implode('، ', array_keys($byNo)), 'Unknown return line: '.implode(', ', array_keys($byNo)));
            }
            $notes = $this->debitNotes->issue($user, $r);
            if ($r->status === 'shipped' && $notes) {
                $this->move($user, $r, 'closed', ['closed_at' => now()], 'إشعار مدين '.implode('، ', array_map(fn (DebitNote $n) => $n->number, $notes)));
                $this->emitReturn($r, 'return.closed', ['decision' => $r->decision, 'decisionAr' => $this->decisionAr((string) $r->decision)]);
            }
        });
    }

    // ───────────── internals ─────────────

    /**
     * The invoiced delivery a manual customer return refers to.
     *
     * @return array{0: FulfillmentOrder, 1: CustomerInvoice}
     */
    private function deliveryReference(array $dto, ?FulfillmentOrder $fo): array
    {
        $invoice = null;
        if (! empty($dto['invoiceNumber'])) {
            $invoice = CustomerInvoice::with('lines')->where('number', $dto['invoiceNumber'])->where('kind', 'invoice')->first()
                ?? throw AppError::notFound('INVOICE_NOT_FOUND', "فاتورة العميل {$dto['invoiceNumber']} غير موجودة", "Customer invoice {$dto['invoiceNumber']} not found");
            if ($invoice->status === 'cancelled') {
                throw AppError::rule('INVOICE_CANCELLED', "الفاتورة {$invoice->number} ملغاة — لا يُرجَع عليها", "Invoice {$invoice->number} is cancelled");
            }
            if ($fo && $invoice->fo_id !== $fo->id) {
                throw AppError::rule('REFERENCE_MISMATCH', "الفاتورة {$invoice->number} ليست لأمر التجهيز {$fo->number}", "Invoice {$invoice->number} does not belong to {$fo->number}");
            }
            $fo ??= $invoice->fo_id ? FulfillmentOrder::find($invoice->fo_id) : null;
        }
        if (! $fo) {
            $ref = ($dto['foNumber'] ?? null) ?: ($dto['invoiceNumber'] ?? '');
            throw AppError::notFound('FO_NOT_FOUND', "أمر التجهيز {$ref} غير موجود — اكتب رقم أمر التجهيز أو فاتورة العميل الذي يُرجَع منه", "Fulfilment order {$ref} not found");
        }
        // the same invoice the credit note will be issued against (CustomerInvoiceService::creditFromReturn)
        $invoice = CustomerInvoice::with('lines')->where('fo_id', $fo->id)->where('kind', 'invoice')->where('status', '!=', 'cancelled')->orderBy('created_at')->first()
            ?? throw AppError::rule('FO_NOT_INVOICED', "أمر التجهيز {$fo->number} بلا فاتورة عميل سارية (لم يُسلَّم بعد أو أُلغيت فاتورته) — أنشئ المرتجع بدون مرجع إن كانت البضاعة ستعود فعلًا",
                "Fulfilment order {$fo->number} has no active customer invoice");

        return [$fo, $invoice];
    }

    /** Nothing can be returned on an invoice beyond what it billed and has not yet been credited. */
    private function assertWithinInvoice(CustomerInvoice $invoice, array $lines, $productsById): void
    {
        $credited = CustomerInvoiceLine::whereIn('invoice_id', CustomerInvoice::where('credit_of_id', $invoice->id)->where('status', '!=', 'cancelled')->pluck('id'))
            ->selectRaw('product_id, coalesce(sum(qty), 0) as qty')->groupBy('product_id')->pluck('qty', 'product_id');
        // returns on the same delivery that are requested but not received yet will take their share of the invoice too
        $open = ReturnLine::query()->join('returns as r', 'r.id', '=', 'return_lines.return_id')->where('r.fo_id', $invoice->fo_id)->where('r.source_mode', 'post_delivery')
            ->whereIn('r.status', ['pending', 'approved'])->selectRaw('return_lines.product_id, coalesce(sum(return_lines.qty), 0) as qty')->groupBy('return_lines.product_id')->pluck('qty', 'product_id');
        $asked = [];
        foreach ($lines as $l) {
            $asked[$l['product_id']] = ($asked[$l['product_id']] ?? 0) + $l['qty'];
        }
        foreach ($asked as $productId => $qty) {
            $p = $productsById->get($productId);
            $billed = (int) $invoice->lines->where('product_id', $productId)->sum('qty');
            if ($billed === 0) {
                throw AppError::rule('NOT_ON_INVOICE', "الصنف {$p?->name_ar} ({$p?->sku}) ليس على الفاتورة {$invoice->number}", "{$p?->sku} is not on invoice {$invoice->number}", ['sku' => $p?->sku]);
            }
            $pending = (int) ($open[$productId] ?? 0);
            $left = max(0, $billed - (int) ($credited[$productId] ?? 0) - $pending);
            if ($qty > $left) {
                throw AppError::rule('RETURN_EXCEEDS_INVOICED', "الكمية المرتجعة من {$p?->name_ar} ({$qty}) تتجاوز المتبقي على الفاتورة {$invoice->number} ({$left} من {$billed}".($pending ? " — {$pending} في مرتجعات لم تُستلم بعد" : '').')',
                    "Returned quantity of {$p?->sku} ({$qty}) exceeds what is left on invoice {$invoice->number} ({$left})", ['sku' => $p?->sku, 'invoiced' => $billed, 'remaining' => $left, 'pending' => $pending, 'requested' => $qty]);
            }
        }
    }

    /**
     * The purchase order / goods receipt a supplier return refers to (both optional, both must be this supplier's).
     *
     * @return array{0: ?PurchaseOrder, 1: ?GoodsReceipt}
     */
    private function purchaseReference(array $dto, Supplier $supplier): array
    {
        $po = $grn = null;
        if (! empty($dto['grnNumber'])) {
            $grn = GoodsReceipt::where('number', $dto['grnNumber'])->first() ?? throw AppError::notFound('GRN_NOT_FOUND', "سند الاستلام {$dto['grnNumber']} غير موجود", "GRN {$dto['grnNumber']} not found");
            if ($grn->supplier_id !== $supplier->id) {
                throw AppError::rule('REFERENCE_MISMATCH', "سند الاستلام {$grn->number} ليس من المورد {$supplier->name_ar}", "GRN {$grn->number} is not from this supplier");
            }
        }
        if (! empty($dto['poNumber'])) {
            $po = PurchaseOrder::where('number', $dto['poNumber'])->first() ?? throw AppError::notFound('PO_NOT_FOUND', "أمر الشراء {$dto['poNumber']} غير موجود", "PO {$dto['poNumber']} not found");
            if ($po->supplier_id !== $supplier->id || ($grn && $grn->po_id !== $po->id)) {
                throw AppError::rule('REFERENCE_MISMATCH', "أمر الشراء {$po->number} لا يطابق المورد / سند الاستلام المحدد", "PO {$po->number} does not match the supplier / GRN");
            }
        }
        $po ??= $grn ? PurchaseOrder::find($grn->po_id) : null;

        return [$po, $grn];
    }

    /**
     * The balance row a line of a return from stock is taken from: the bin must hold the product (in the named batch, or
     * in its only batch) with the quantity free.
     *
     * @return array{0: Bin, 1: ?Batch}
     */
    private function sourceRow(Warehouse $wh, Product $p, string $binCode, ?Batch $batch, int $qty, string $type, int $no): array
    {
        $bin = $this->inventory->bin($wh->id, $binCode);
        $zone = $bin->zone?->type;
        if (in_array($zone, ReturnStockSources::NO_SOURCE_ZONES, true)) {
            throw AppError::rule('FROM_BIN_INVALID', "السطر {$no}: الموقع {$bin->code} منطقة تجهيز / مرتجعات — رصيدها يخص مستندًا قيد التنفيذ ولا يُسحب منه مرتجع", "Line {$no}: {$bin->code} is a staging / returns area", ['lineNo' => $no]);
        }
        if ($type === 'dmg' && $zone === 'damaged') {
            throw AppError::rule('FROM_BIN_INVALID', "السطر {$no}: الرصيد في منطقة التالف {$bin->code} مسبقًا — لإخراجه استخدم إرجاعًا للمورد أو تسوية إتلاف", "Line {$no}: the stock is already in the damaged area", ['lineNo' => $no]);
        }
        $rows = InventoryBalance::where('product_id', $p->id)->where('bin_id', $bin->id)->where('on_hand', '>', 0)->get();
        if (! $batch && $rows->count() === 1 && $rows->first()->batch_id) {
            $batch = Batch::find($rows->first()->batch_id);
        }
        $row = $rows->first(fn (InventoryBalance $b) => $b->batch_id === $batch?->id);
        if (! $row) {
            if (! $batch && $rows->count() > 1) {
                throw AppError::validation('BATCH_REQUIRED', "السطر {$no}: الموقع {$bin->code} يحمل أكثر من دفعة من {$p->name_ar} — حدّد الدفعة", "Line {$no}: {$bin->code} holds several batches of {$p->sku} — name the batch", ['lineNo' => $no]);
            }
            throw AppError::rule('NO_STOCK', "السطر {$no}: لا رصيد من {$p->name_ar} في {$bin->code}".($batch ? " للدفعة {$batch->batch_no}" : ''), "Line {$no}: no stock of {$p->sku} at {$bin->code}", ['lineNo' => $no]);
        }
        $free = $row->blocked ? 0 : $row->on_hand - $row->reserved;
        if ($qty > $free) {
            throw AppError::rule('INSUFFICIENT_AVAILABLE', "السطر {$no} ({$p->name_ar}): المتاح في {$bin->code} ({$free}) أقل من {$qty}".($row->blocked ? ' — الرصيد مجمّد للجرد' : ''),
                "Line {$no}: available at {$bin->code} ({$free}) is less than {$qty}", ['lineNo' => $no, 'available' => $free, 'requested' => $qty]);
        }

        return [$bin, $batch];
    }

    /**
     * Quantity of a product an order was loaded with, per batch — last loaded first.
     *
     * @return array<string, int> batch id → loaded quantity
     */
    private function loadedPerBatch(FulfillmentOrder $fo, Product $p): array
    {
        $perBatch = [];
        $loads = InventoryMovement::where('type', 'load')->where('reference_type', 'FulfillmentOrder')->where('reference_id', $fo->id)->where('product_id', $p->id)
            ->whereNotNull('batch_id')->orderByDesc('created_at')->orderByDesc('number')->get(['batch_id', 'qty']);
        foreach ($loads as $m) {
            $perBatch[$m->batch_id] = ($perBatch[$m->batch_id] ?? 0) + (int) $m->qty;
        }

        return $perBatch;
    }

    /**
     * A quantity that came back on the truck, spread over the batches the order was loaded from (last loaded first).
     * What exceeds the batch-identified load (nothing loaded through the system) stays batchless and is identified at the decision.
     *
     * @param  array<string, int>  $perBatch
     * @return array<int, array{0: ?Batch, 1: int}>
     */
    private function spreadOverBatches(array $perBatch, int $qty): array
    {
        $out = [];
        $left = $qty;
        foreach ($perBatch as $batchId => $loaded) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $loaded);
            $out[] = [Batch::find($batchId), $take];
            $left -= $take;
        }
        if ($left > 0) {
            $out[] = [null, $left];
        }

        return $out;
    }

    /** `sup` / `dmg` returns created before stock sources existed have nothing to take the goods from: they can only be rejected or cancelled. */
    private function assertHasSource(ReturnOrder $r): void
    {
        if ($r->source_mode !== 'stock' && in_array($r->type, self::FROM_STOCK_TYPES, true)) {
            throw AppError::rule('FROM_BIN_REQUIRED', "{$this->typeAr($r)} {$r->number} بلا موقع مصدر (أُنشئ بالطريقة السابقة) — ألغِه وأنشئ مرتجعًا جديدًا يحدد الموقع الذي تُسحب منه الكمية",
                "Return {$r->number} has no source bin — cancel it and create a new one that names the bin");
        }
    }

    /** Which account the receipt into RET-01 is credited to (CostingService `ret`). */
    private function costOrigin(ReturnOrder $r): ?string
    {
        if (! $r->fo_id) {
            return null; // no delivery behind it: average cost against inventory adjustment
        }
        if ($r->source_mode === 'post_delivery') {
            return 'customer_return';
        }
        if (in_array($r->reason_code, CustomerInvoiceService::POD_RETURN_REASONS, true)) {
            return 'pod_return'; // created by the delivery itself: the goods never left the truck
        }

        return $r->type === 'cust' ? 'customer_return' : null;
    }

    /** The return as the credit note must see it: one line per product carrying the quantity that really arrived. */
    private function receivedView(ReturnOrder $r, array $received): ReturnOrder
    {
        $view = clone $r;
        $perProduct = [];
        foreach ($r->lines as $l) {
            if ($received[$l->id] <= 0) {
                continue;
            }
            if (! isset($perProduct[$l->product_id])) {
                $perProduct[$l->product_id] = (clone $l)->setAttribute('qty', 0);
            }
            $perProduct[$l->product_id]->setAttribute('qty', $perProduct[$l->product_id]->qty + $received[$l->id]);
        }

        return $view->setRelation('lines', $r->lines->make(array_values($perProduct)));
    }

    /** @return array<string, int> line id → quantity received now */
    private function receivedQuantities(ReturnOrder $r, ?array $lines): array
    {
        $byNo = $this->indexLines($lines);
        $out = [];
        foreach ($r->lines as $l) {
            $qty = $l->qty;
            if (isset($byNo[$l->line_no])) {
                $qty = $byNo[$l->line_no]['receivedQty'];
                if (! is_int($qty) || $qty < 0 || $qty > $l->qty) {
                    throw AppError::validation('BAD_QTY', "الكمية المستلمة للسطر {$l->line_no} يجب أن تكون بين 0 و{$l->qty}", "Received quantity of line {$l->line_no} must be between 0 and {$l->qty}", ['lineNo' => $l->line_no, 'max' => $l->qty]);
                }
                unset($byNo[$l->line_no]);
            }
            $out[$l->id] = $qty;
        }
        if ($byNo) {
            throw AppError::validation('LINE_NOT_FOUND', 'سطر غير موجود في المرتجع: '.implode('، ', array_keys($byNo)), 'Unknown return line: '.implode(', ', array_keys($byNo)));
        }

        return $out;
    }

    /** Payload lines keyed by `lineNo`; a line given twice is refused instead of silently overwriting. */
    private function indexLines(?array $lines): array
    {
        $byNo = [];
        foreach ($lines ?? [] as $i => $l) {
            $no = (int) $l['lineNo'];
            if (isset($byNo[$no])) {
                throw AppError::validation('LINE_DUPLICATE', "السطر {$no} مكرر في الطلب", "Line {$no} is given twice", ['lineNo' => $no]);
            }
            $byNo[$no] = ['index' => $i] + $l;
        }

        return $byNo;
    }

    /** Quantity of a line that is in RET-01 (returns received before partial receipt existed arrived in full). */
    private function receivedOf(ReturnLine $l): int
    {
        return (int) ($l->received_qty ?? $l->qty);
    }

    /**
     * The parts to execute per line, validated: Σ parts of a line = what was received on it.
     *
     * @return array<int, array{line: ReturnLine, received: int, parts: array<int, array>}>
     */
    private function decisionPlan(ReturnOrder $r, array $dto): array
    {
        $plan = [];
        if (! empty($dto['lines'])) {
            $byNo = $this->indexLines($dto['lines']);
            foreach ($r->lines as $l) {
                $received = $this->receivedOf($l);
                $given = $byNo[$l->line_no] ?? null;
                unset($byNo[$l->line_no]);
                if (! $given && $received > 0) {
                    throw AppError::validation('LINE_MISSING', "السطر {$l->line_no} ({$l->product->name_ar}) بلا قرار — كل سطر مستلم يحتاج قرارًا لكامل كميته ({$received})", "Line {$l->line_no} has no decision", ['lineNo' => $l->line_no]);
                }
                $parts = [];
                foreach (array_values($given['parts'] ?? []) as $j => $part) {
                    $parts[] = $part + ['where' => "lines.{$given['index']}.parts.{$j}", 'strict' => true];
                }
                $sum = array_sum(array_map(fn ($p) => (int) $p['qty'], $parts));
                if ($sum !== $received) {
                    throw AppError::rule('PARTS_SUM', "مجموع قرارات السطر {$l->line_no} ({$l->product->name_ar}) = {$sum} ولا يساوي الكمية المستلمة ({$received})",
                        "Decisions of line {$l->line_no} add up to {$sum}, not the received quantity ({$received})", ['lineNo' => $l->line_no, 'received' => $received, 'sum' => $sum]);
                }
                $plan[] = ['line' => $l, 'received' => $received, 'parts' => $parts];
            }
            if ($byNo) {
                throw AppError::validation('LINE_NOT_FOUND', 'سطر غير موجود في المرتجع: '.implode('، ', array_keys($byNo)), 'Unknown return line: '.implode(', ', array_keys($byNo)));
            }

            return $plan;
        }
        // one decision for the whole return: the typed bin is used for the lines it fits, the others are placed automatically
        $decision = $dto['decision'];
        $binCode = trim((string) ($dto['binCode'] ?? '')) ?: null;
        $bin = $binCode && $decision === 'restock' ? $this->inventory->bin($r->warehouse_id, $binCode) : null;
        $fits = 0;
        $why = null;
        foreach ($r->lines as $l) {
            $received = $this->receivedOf($l);
            $part = ['qty' => $received, 'decision' => $decision, 'supplierCode' => $dto['supplierCode'] ?? null, 'note' => $dto['note'] ?? null, 'where' => 'decision', 'strict' => false];
            if ($bin && $received > 0) {
                $check = $this->inventory->locCheck($l->product_id, $bin->id);
                if ($check['ok']) {
                    $part['binCode'] = $bin->code;
                    $fits++;
                } else {
                    $why ??= $check;
                }
            }
            $plan[] = ['line' => $l, 'received' => $received, 'parts' => $received > 0 ? [$part] : []];
        }
        if ($bin && $fits === 0) {
            throw AppError::rule('WRONG_LOCATION', "موقع خاطئ — {$why['why']}", $why['whyEn']);
        }

        return $plan;
    }

    /**
     * Batch a part goes back into stock with: the one named on the part (created with its expiry date when it is new),
     * else the line's. `$required`: a product with expiry tracking is never restocked without one.
     */
    private function partBatchId(ReturnLine $l, array $part, bool $required): ?string
    {
        $batchNo = trim((string) ($part['batchNo'] ?? '')) ?: null;
        if ($batchNo) {
            $batch = Batch::where('product_id', $l->product_id)->where('batch_no', $batchNo)->lockForUpdate()->first();
            if (! $batch) {
                if ($l->product->tracks_expiry && empty($part['expiryDate'])) {
                    throw AppError::validation('EXPIRY_REQUIRED', "السطر {$l->line_no}: الدفعة {$batchNo} جديدة على {$l->product->name_ar} — أدخل تاريخ انتهائها لتُسجَّل",
                        "Line {$l->line_no}: batch {$batchNo} is new — its expiry date is required", ['lineNo' => $l->line_no, 'field' => "{$part['where']}.expiryDate"]);
                }
                $batch = Batch::create(['product_id' => $l->product_id, 'batch_no' => $batchNo, 'expiry_date' => ! empty($part['expiryDate']) ? Carbon::parse($part['expiryDate']) : null]);
            }

            return $batch->id;
        }
        if (! $l->batch_id && $required) {
            throw AppError::validation('BATCH_REQUIRED', "السطر {$l->line_no} ({$l->product->name_ar}) متتبَّع بالصلاحية — لا يُعاد للمخزون إلا بدفعته: أدخل رقم الدفعة (وتاريخ انتهائها إن كانت جديدة)",
                "Line {$l->line_no} ({$l->product->sku}) tracks expiry — a batch number is required to restock it", ['lineNo' => $l->line_no, 'field' => "{$part['where']}.batchNo"]);
        }

        return $l->batch_id;
    }

    /** The received quantity sits in RET-01 under the line's batch; a part decided under another batch is re-identified there first (two internal moves, no accounting). */
    private function rebatch(AuthUser $user, string $txId, ReturnOrder $r, ReturnLine $l, Bin $ret, int $qty, ?string $toBatchId): void
    {
        $name = fn (?string $id) => $id ? (Batch::whereKey($id)->value('batch_no') ?? '—') : 'بلا دفعة';
        $base = ['type' => 'move', 'productId' => $l->product_id, 'qty' => $qty, 'reference' => ['type' => 'ReturnOrder', 'id' => $r->id, 'number' => $r->number],
            'note' => "تحديد دفعة المرتجع عند الفحص: {$name($l->batch_id)} ← {$name($toBatchId)}"];
        $at = ['warehouseId' => $r->warehouse_id, 'binId' => $ret->id];
        $this->inventory->post($user, $txId, $base + ['batchId' => $l->batch_id, 'from' => $at]);
        $this->inventory->post($user, $txId, $base + ['batchId' => $toBatchId, 'to' => $at]);
    }

    /** Supplier a `sup` part goes back to: the one named on the part, else the default of the line. */
    private function partSupplier(ReturnOrder $r, ReturnLine $l, array $part): Supplier
    {
        $code = trim((string) ($part['supplierCode'] ?? '')) ?: null;
        if ($code) {
            return Supplier::where('code', $code)->first() ?? throw AppError::notFound('SUPPLIER_NOT_FOUND', "المورد {$code} غير موجود", "Supplier {$code} not found");
        }
        $id = $this->defaultSupplierId($r, $l);
        $field = $part['where'] === 'decision' ? 'supplierCode' : "{$part['where']}.supplierCode";

        return ($id ? Supplier::find($id) : null) ?? throw AppError::validation('SUPPLIER_REQUIRED',
            "الإرجاع للمورد يحتاج مورّدًا: لا مورد معروف للسطر {$l->line_no} ({$l->product->name_ar}) — اختره في حقل «المورد» ({$field})",
            "Supplier required for line {$l->line_no}: set {$field}", ['lineNo' => $l->line_no, 'field' => $field]);
    }

    /** The return's own supplier, else who delivered the line's batch, else the product's preferred supplier. */
    private function defaultSupplierId(ReturnOrder $r, ReturnLine $l): ?string
    {
        if ($r->supplier_id) {
            return $r->supplier_id;
        }
        if ($l->batch_id) {
            $id = GrnLine::query()->join('goods_receipts as g', 'g.id', '=', 'grn_lines.grn_id')->where('grn_lines.batch_id', $l->batch_id)->whereNull('g.voided_at')
                ->orderByDesc('g.posted_at')->orderByDesc('g.id')->value('g.supplier_id') ?? Batch::whereKey($l->batch_id)->value('supplier_id');
            if ($id) {
                return $id;
            }
        }
        $links = ProductSupplier::where('product_id', $l->product_id)->orderByDesc('preferred')->orderBy('id')->get(['supplier_id', 'preferred']);
        if ($links->isNotEmpty() && ($links->first()->preferred || $links->count() === 1)) {
            return $links->first()->supplier_id;
        }
        $name = $l->product?->preferred_supplier_name;

        return $name ? Supplier::where('name_ar', $name)->orWhere('name_en', $name)->value('id') : null;
    }

    private function supplierBrief(?string $id): ?array
    {
        $s = $id ? Supplier::whereKey($id)->first(['id', 'code', 'name_ar', 'name_en']) : null;

        return $s ? ['code' => $s->code, 'nameAr' => $s->name_ar, 'nameEn' => $s->name_en] : null;
    }

    /** Batches worth offering for a line that needs one: those the delivery was loaded from, else the product's latest. */
    private function batchOptions(ReturnOrder $r, ReturnLine $l): array
    {
        $loaded = $r->fo_id ? InventoryMovement::where('type', 'load')->where('reference_type', 'FulfillmentOrder')->where('reference_id', $r->fo_id)->where('product_id', $l->product_id)
            ->whereNotNull('batch_id')->distinct()->pluck('batch_id')->all() : [];
        $q = Batch::where('product_id', $l->product_id);
        $batches = $loaded ? $q->whereIn('id', $loaded)->orderBy('expiry_date')->get() : $q->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get();

        return $batches->map(fn (Batch $b) => ['batchNo' => $b->batch_no, 'expiryDate' => $b->expiry_date?->toJSON()])->all();
    }

    /** Restock target: the scanned bin (location-checked), else the bin already holding the product, else the first storage bin of the right zone class. */
    private function restockBin(ReturnOrder $r, ReturnLine $l, ?string $binCode): Bin
    {
        if ($binCode) {
            $bin = $this->inventory->bin($r->warehouse_id, $binCode);
            $lc = $this->inventory->locCheck($l->product_id, $bin->id);
            if (! $lc['ok']) {
                throw AppError::rule('WRONG_LOCATION', "موقع خاطئ — {$lc['why']}", $lc['whyEn'], ['lineNo' => $l->line_no]);
            }

            return $bin;
        }
        $class = $l->product->storage_class;
        $inZone = fn ($b) => $b->where('status', 'active')->whereHas('zone', fn ($z) => $z->where('type', $class));
        $candidate = InventoryBalance::with('bin')->where('product_id', $l->product_id)->where('warehouse_id', $r->warehouse_id)->where('on_hand', '>', 0)->where('quarantine', false)
            ->whereHas('bin', $inZone)->orderBy('id')->first();
        $bin = $candidate?->bin ?? $inZone(Bin::where('warehouse_id', $r->warehouse_id)->where('type', '!=', 'virtual'))->orderBy('code')->first();

        return $bin ?? throw AppError::rule('NO_BIN', "لا موقع تخزين مطابق لشروط الصنف {$l->product->name_ar} (السطر {$l->line_no}) — اختر حجرًا أو موقعًا صحيحًا", 'No matching storage bin', ['lineNo' => $l->line_no]);
    }

    /** What the screen may offer next, with the reason when it may not — never a dead end. */
    private function flow(ReturnOrder $r): array
    {
        $fromStock = $r->source_mode === 'stock';
        $cancel = ['allowed' => Sm::can('RETURN_TRANSITIONS', $r->status, 'cancelled'), 'code' => null, 'reasonAr' => null, 'reasonEn' => null];
        $trip = $r->trip_id ? Trip::whereKey($r->trip_id)->first(['id', 'number', 'status']) : null;
        if ($cancel['allowed'] && $trip && ! in_array($trip->status, self::ENDED_TRIP_STATES, true)) {
            $cancel = ['allowed' => false, 'code' => 'RTN_ON_TRUCK', 'reasonAr' => "البضاعة على المركبة — يُلغى بعد إقفال الرحلة {$trip->number} إن لم يعد شيء", 'reasonEn' => "Goods are on the truck — can be cancelled once trip {$trip->number} is closed"];
        }
        $blocked = null;
        if (! $fromStock && in_array($r->type, self::FROM_STOCK_TYPES, true) && in_array($r->status, ['pending', 'approved'], true)) {
            $blocked = ['code' => 'FROM_BIN_REQUIRED', 'reasonAr' => 'مرتجع بلا موقع مصدر (أُنشئ بالطريقة السابقة) — ألغِه وأنشئ مرتجعًا جديدًا يحدد الموقع الذي تُسحب منه الكمية', 'reasonEn' => 'No source bin — cancel it and create a new return that names the bin'];
        }

        return [
            'fromStock' => $fromStock,
            'blocked' => $blocked,
            'cancel' => $cancel,
            // expected: a credit note is issued at receipt · undelivered: the goods were never invoiced · none: no delivery named, nothing to credit
            'credit' => in_array($r->type, ['cust', 'del'], true) ? ($r->source_mode === 'post_delivery' ? 'expected' : ($r->trip_id ? 'undelivered' : ($r->type === 'cust' ? 'none' : null))) : null,
            'requireBatchOnRestock' => (bool) $this->settings->get('returns.requireBatchOnRestock'),
        ];
    }

    /** Runs `$fn` on the locked return in one transaction (the same return can never be received or shipped twice) and returns its fresh detail. */
    private function locked(string $idOrNumber, Closure $fn): array
    {
        $id = DB::transaction(function () use ($idOrNumber, $fn) {
            $r = ReturnOrder::with(['lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product', 'supplier'])
                ->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
                ?? throw AppError::notFound('RTN_NOT_FOUND', 'المرتجع غير موجود', 'Return not found');
            $fn($r);

            return $r->id;
        });

        return $this->get($id);
    }

    private function assertCan(ReturnOrder $r, string $to): void
    {
        if (! Sm::can('RETURN_TRANSITIONS', $r->status, $to)) {
            throw AppError::rule('RTN_TRANSITION', "انتقال غير مسموح: {$r->status} ← {$to} — المسار: اعتماد → استلام → فحص → قرار (أو اعتماد → شحن لمرتجعات المخزون)", "Invalid transition {$r->status} → {$to}");
        }
    }

    /** Status change + status history; `$columns` are the extra columns of the step. */
    private function move(AuthUser $user, ReturnOrder $r, string $to, array $columns = [], ?string $note = null, ?string $txId = null): void
    {
        $this->assertCan($r, $to);
        $from = $r->status;
        $r->update(['status' => $to] + $columns);
        $this->audit->status($user, 'ReturnOrder', $r->id, $r->number, $from, $to, $note, $txId);
    }

    private function typeAr(ReturnOrder $r): string
    {
        return self::TYPE_AR[$r->type] ?? 'مرتجع';
    }

    private function decisionAr(string $decision): string
    {
        return $decision === 'mixed' ? 'قرارات متعددة' : (config("scm.RETURN_DECISION_LABELS.{$decision}.ar") ?? $decision);
    }

    /** A return of an order received from another system is reported to it (return.created / approved / received / inspect / closed …). */
    private function emitReturn(ReturnOrder $r, string $type, array $extra = []): void
    {
        $soId = $r->fo_id ? FulfillmentOrder::whereKey($r->fo_id)->value('so_id') : null;
        app(OrderEvents::class)->emit($soId, $type, ['return' => $r->number, 'returnType' => $r->type, 'returnStatus' => $r->status] + $extra);
    }

    private function with(): array
    {
        return [
            'customer:id,code,name_ar', 'supplier:id,code,name_ar', 'warehouse:id,code,name_ar', 'fo:id,number', 'trip:id,number',
            'lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product:id,sku,name_ar,name_en,storage_class,tracks_expiry,preferred_supplier_name', 'lines.bin:id,code', 'lines.fromBin:id,code',
            'lines.batch:id,batch_no,expiry_date', 'receiving', 'inspection', 'decisions' => fn ($q) => $q->orderBy('at')->orderBy('id'),
        ];
    }
}
