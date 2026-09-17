<?php

namespace App\Services\Inbound;

use App\Models\Batch;
use App\Models\Bin;
use App\Models\GoodsReceipt;
use App\Models\GrnLine;
use App\Models\InboundShipment;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PutawayTask;
use App\Models\QcResult;
use App\Models\StagingEntry;
use App\Models\StatusHistory;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Services\Exceptions\ExceptionsService;
use App\Services\Inventory\InventoryService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Inbound: expected inbound (from an approved PO) → arrival (gate check) → inspection (per-line counted / QC
 * quantities) → GRN (header + lines) → accepted goods into inbound staging (STG-IN) → putaway tasks → putaway
 * confirmation (scan bin + product, central location check) → available inventory. Damaged goes to the DMG area
 * (never available); rejected never enters stock. Shortage / damage / rejected raise exceptions. Partial receiving
 * keeps the PO open (partial).
 */
class InboundService
{
    private const OPEN_STATUSES = ['expected', 'arrived', 'inspecting', 'putaway'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
        private readonly SettingsService $settings,
        private readonly ExceptionsService $exceptions,
        private readonly InventoryService $inventory,
    ) {}

    // ───────────── shipments ─────────────

    /** @param  array{status?:?string, warehouse?:?string, supplier?:?string, po?:?string}  $filters */
    public function listShipments(Paging $page, array $filters): array
    {
        $query = InboundShipment::with($this->shipmentWith());
        if (! empty($filters['status'])) {
            $filters['status'] === 'open' ? $query->whereIn('status', self::OPEN_STATUSES) : $query->where('status', $filters['status']);
        }
        if (! empty($filters['warehouse'])) {
            $query->whereHas('warehouse', fn ($w) => $w->where('code', $filters['warehouse']));
        }
        if (! empty($filters['supplier'])) {
            $query->whereHas('supplier', fn ($s) => $s->where('code', $filters['supplier']));
        }
        if (! empty($filters['po'])) {
            $query->whereHas('po', fn ($p) => $p->where('number', 'like', "%{$filters['po']}%"));
        }
        if ($page->q) {
            $q = $page->q;
            $query->where(fn ($w) => $w->where('number', 'like', "%{$q}%")
                ->orWhereHas('po', fn ($p) => $p->where('number', 'like', "%{$q}%"))
                ->orWhereHas('supplier', fn ($s) => $s->where('name_ar', 'like', "%{$q}%")->orWhere('name_en', 'like', "%{$q}%")));
        }
        // eta ascending with open-ended (null) etas last, as the reference database sorts them
        $query->orderByRaw('eta IS NULL')->orderBy('eta')->orderByDesc('created_at')->orderByDesc('id');

        return $page->paginate($query, fn (InboundShipment $s) => $this->decorate($s));
    }

    public function getShipment(string $idOrNumber): array
    {
        $s = InboundShipment::with(array_merge($this->shipmentWith(), [
            'putaways' => fn ($q) => $q->orderBy('created_at')->orderBy('number'),
            'putaways.suggestedBin:id,code', 'putaways.actualBin:id,code', 'putaways.product:id,sku,name_ar',
        ]))->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('SHIPMENT_NOT_FOUND', 'الشحنة غير موجودة', 'Shipment not found');
        $history = StatusHistory::where('entity_type', 'InboundShipment')->where('entity_id', $s->id)->orderBy('at')->orderBy('id')->get();

        return $this->decorate($s) + ['history' => $history->toArray()];
    }

    /** Search / scan a PO number or a shipment number: the latest OPEN shipment that matches. */
    public function scan(string $code): array
    {
        $c = trim($code);
        $s = InboundShipment::whereIn('status', self::OPEN_STATUSES)
            ->where(fn ($w) => $w->where('number', $c)->orWhereHas('po', fn ($p) => $p->where('number', $c)))
            ->orderByDesc('created_at')->orderByDesc('id')->first()
            ?? throw AppError::notFound('PO_NOT_FOUND', "لا شحنة مفتوحة للمرجع {$c} — تحقق من رقم أمر الشراء", "No open shipment for {$c}");

        return $this->getShipment($s->id);
    }

    public function arrive(AuthUser $user, string $idOrNumber, ?string $carrier = null): InboundShipment
    {
        return $this->transition($user, $idOrNumber, 'arrived', ['arrived_at' => now()] + ($carrier ? ['carrier' => $carrier] : []));
    }

    public function startInspection(AuthUser $user, string $idOrNumber): InboundShipment
    {
        return $this->transition($user, $idOrNumber, 'inspecting', ['inspection_started_at' => now()]);
    }

    public function cancel(AuthUser $user, string $idOrNumber): InboundShipment
    {
        return $this->transition($user, $idOrNumber, 'cancelled');
    }

    /**
     * Suggests a putaway bin for a product in a warehouse: the bin that already holds the product (same zone class)
     * first, else the first empty bin of the right zone type.
     *
     * @return array{binId:?string, ar:string, en:string}
     */
    public function suggestBin(string $productId, string $warehouseId): array
    {
        $p = Product::findOrFail($productId);
        $existing = InventoryBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->where('on_hand', '>', 0)->where('quarantine', false)
            ->whereHas('bin', fn ($b) => $b->where('status', 'active')->whereHas('zone', fn ($z) => $z->where('type', $p->storage_class)))
            ->orderByDesc('on_hand')->orderBy('id')->first();
        if ($existing) {
            return ['binId' => $existing->bin_id, 'ar' => 'نفس موقع الرصيد الحالي وسعة كافية', 'en' => 'Same bin as current balance'];
        }
        $empty = Bin::where('warehouse_id', $warehouseId)->where('status', 'active')->where('type', '!=', 'virtual')
            ->whereHas('zone', fn ($z) => $z->where('type', $p->storage_class))
            ->whereNotIn('id', InventoryBalance::select('bin_id')->where('warehouse_id', $warehouseId)->where('on_hand', '>', 0))
            ->where(fn ($w) => $w->whereNull('fixed_product_id')->orWhere('fixed_product_id', $productId))
            ->orderBy('code')->first();
        if ($empty) {
            $zone = ['frozen' => 'FZ (مجمد)', 'chilled' => 'CH (مبرد)'][$p->storage_class] ?? 'التخزين العادي';

            return ['binId' => $empty->id, 'ar' => "أقرب Bin شاغر في منطقة {$zone}", 'en' => 'Nearest free bin in the right zone'];
        }

        return ['binId' => null, 'ar' => 'لا موقع شاغر مطابق — اختر موقعًا يدويًا', 'en' => 'No free matching bin — choose manually'];
    }

    // ───────────── GRN ─────────────

    /**
     * Posts the GRN: applies the counted quantities per line, rejects over-receipt against the remaining open quantity
     * (excess rejected unless `receiving.allowExcess`), creates GRN header + lines + QC results, moves the accepted
     * quantity into STG-IN with putaway tasks and the damaged quantity into DMG-01, raises exceptions and updates the
     * PO lines / status. One transaction: any bad line rejects the whole receipt (no half receipts).
     *
     * @param  array<int, array{lineNo:int, acceptedQty:int, damagedQty:int, rejectedQty:int, batchNo?:?string, mfgDate?:?string, expiryDate?:?string, qcNote?:?string}>  $lines
     */
    public function postGrn(AuthUser $user, string $idOrNumber, array $lines, ?string $notes = null): array
    {
        $number = DB::transaction(function () use ($user, $idOrNumber, $lines, $notes) {
            // The shipment row is locked, so two simultaneous posts can never both pass the state guard.
            $s = InboundShipment::with(['lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product', 'po'])
                ->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
                ?? throw AppError::notFound('SHIPMENT_NOT_FOUND', 'الشحنة غير موجودة');
            if ($s->status !== 'inspecting') {
                if (in_array($s->status, ['expected', 'arrived'], true)) {
                    throw AppError::rule('GRN_BEFORE_INSPECTION', 'لا يمكن إصدار GRN قبل الوصول وبدء الفحص', 'GRN requires arrival and inspection');
                }
                throw AppError::conflict('GRN_DUPLICATE', 'GRN صادر لهذه الشحنة — لا تكرار', 'GRN already posted for this shipment');
            }
            $allowExcess = (bool) $this->settings->get('receiving.allowExcess');
            $byNo = [];
            foreach ($lines as $l) {
                $byNo[(int) $l['lineNo']] = $l;
            }

            // validation pass
            foreach ($s->lines as $sl) {
                $r = $byNo[$sl->line_no] ?? throw AppError::validation('LINE_MISSING', "السطر {$sl->line_no} بلا كميات", "Line {$sl->line_no} has no quantities");
                $rem = $sl->ordered_qty - $sl->accepted_qty - $sl->damaged_qty - $sl->rejected_qty;
                foreach (['acceptedQty', 'damagedQty', 'rejectedQty'] as $k) {
                    if (! is_int($r[$k]) || $r[$k] < 0) {
                        throw AppError::validation('BAD_QTY', "كمية غير صالحة في السطر {$sl->line_no}", "Invalid quantity on line {$sl->line_no}");
                    }
                }
                $sum = $r['acceptedQty'] + $r['damagedQty'] + $r['rejectedQty'];
                if ($sum > $rem && ! $allowExcess) {
                    throw AppError::rule('OVER_RECEIPT', "مرفوض: المستلم+التالف+المرفوض ({$sum}) يتجاوز المتبقي على {$s->po->number} ({$rem}) — لا سياسة Over-Receipt (Excess مرفوض)",
                        "Received+damaged+rejected ({$sum}) exceeds open quantity ({$rem}) — excess rejected", ['lineNo' => $sl->line_no, 'remaining' => $rem, 'entered' => $sum]);
                }
                if ($sl->product->tracks_expiry && $r['acceptedQty'] > 0 && ! (($r['expiryDate'] ?? null) || $sl->expiry_date)) {
                    throw AppError::validation('EXPIRY_REQUIRED', "تاريخ الانتهاء إلزامي للسطر {$sl->line_no} ({$sl->product->name_ar})", "Expiry date required for line {$sl->line_no}");
                }
                if ($sl->product->tracks_expiry && $r['acceptedQty'] > 0 && ! (($r['batchNo'] ?? null) || $sl->batch_no)) {
                    throw AppError::validation('BATCH_REQUIRED', "رقم الدفعة إلزامي للسطر {$sl->line_no}", "Batch number required for line {$sl->line_no}");
                }
            }

            $txId = $this->inventory->newTxId();
            $number = $this->numbering->next('GRN');
            $grn = GoodsReceipt::create([
                'number' => $number, 'shipment_id' => $s->id, 'po_id' => $s->po_id, 'supplier_id' => $s->supplier_id, 'warehouse_id' => $s->warehouse_id,
                'posted_by_id' => $user->id, 'posted_by' => $user->username, 'posted_at' => now(), 'transaction_id' => $txId,
            ]);
            $stgIn = $this->inventory->specialBin($s->warehouse_id, 'STG-IN');
            $dmgBin = $this->inventory->specialBin($s->warehouse_id, 'DMG-01');
            $reference = ['type' => 'GoodsReceipt', 'id' => $grn->id, 'number' => $number];
            $excBase = ['severity' => 'w', 'ownerRole' => 'proc', 'entityType' => 'GoodsReceipt', 'entityId' => $grn->id, 'entityNumber' => $number,
                'documentType' => 'PurchaseOrder', 'documentId' => $s->po_id, 'documentNumber' => $s->po->number];
            $sumAr = $sumEn = [];
            $exceptionsRaised = $putawayTasks = 0;

            foreach ($s->lines as $sl) {
                $r = $byNo[$sl->line_no];
                [$accepted, $damaged, $rejected] = [$r['acceptedQty'], $r['damagedQty'], $r['rejectedQty']];
                $batchNo = trim((string) (($r['batchNo'] ?? null) ?: ($sl->batch_no ?: ''))) ?: null;
                $expiry = ! empty($r['expiryDate']) ? Carbon::parse($r['expiryDate']) : $sl->expiry_date;
                $mfg = ! empty($r['mfgDate']) ? Carbon::parse($r['mfgDate']) : $sl->mfg_date;
                $batchId = null;
                if ($batchNo) {
                    $batch = Batch::where('product_id', $sl->product_id)->where('batch_no', $batchNo)->lockForUpdate()->first();
                    if ($batch) {
                        $batch->update(array_filter(['expiry_date' => $expiry, 'mfg_date' => $mfg]));
                    } else {
                        $batch = Batch::create(['product_id' => $sl->product_id, 'batch_no' => $batchNo, 'expiry_date' => $expiry, 'mfg_date' => $mfg, 'supplier_id' => $s->supplier_id]);
                    }
                    $batchId = $batch->id;
                }
                $prevRem = $sl->ordered_qty - $sl->accepted_qty - $sl->damaged_qty - $sl->rejected_qty;
                $remaining = max(0, $prevRem - $accepted - $damaged - $rejected);
                $qcNote = $r['qcNote'] ?? null;
                $gl = GrnLine::create([
                    'grn_id' => $grn->id, 'line_no' => $sl->line_no, 'shipment_line_id' => $sl->id, 'po_line_id' => $sl->po_line_id, 'product_id' => $sl->product_id,
                    'received_qty' => $accepted + $damaged + $rejected, 'accepted_qty' => $accepted, 'damaged_qty' => $damaged, 'rejected_qty' => $rejected, 'remaining_qty' => $remaining,
                    'batch_no' => $batchNo, 'batch_id' => $batchId, 'mfg_date' => $mfg, 'expiry_date' => $expiry,
                    'qc_result' => $rejected > 0 ? 'rejected' : ($damaged > 0 ? 'damaged' : 'accepted'), 'qc_note' => $qcNote, 'qc_by_id' => $user->id, 'qc_at' => now(),
                ]);
                foreach (['accepted' => $accepted, 'damaged' => $damaged, 'rejected' => $rejected] as $result => $qty) {
                    if ($qty > 0) {
                        QcResult::create(['grn_line_id' => $gl->id, 'result' => $result, 'qty' => $qty, 'inspector_id' => $user->id, 'inspector' => $user->username, 'notes' => $qcNote, 'at' => now()]);
                    }
                }
                $sl->update([
                    'accepted_qty' => $sl->accepted_qty + $accepted, 'damaged_qty' => $sl->damaged_qty + $damaged, 'rejected_qty' => $sl->rejected_qty + $rejected,
                    'batch_no' => $batchNo ?: $sl->batch_no, 'expiry_date' => $expiry, 'mfg_date' => $mfg,
                ]);
                if ($sl->po_line_id && ($poLine = PoLine::whereKey($sl->po_line_id)->lockForUpdate()->first())) {
                    $poLine->update(['received_qty' => $poLine->received_qty + $accepted, 'damaged_qty' => $poLine->damaged_qty + $damaged, 'rejected_qty' => $poLine->rejected_qty + $rejected]);
                }
                $nameAr = $sl->product->name_ar;
                $nameEn = $sl->product->name_en;
                if ($accepted > 0) {
                    $this->inventory->post($user, $txId, ['type' => 'grn', 'productId' => $sl->product_id, 'batchId' => $batchId, 'qty' => $accepted,
                        'to' => ['warehouseId' => $s->warehouse_id, 'binId' => $stgIn->id], 'reference' => $reference, 'note' => 'استلام مقبول → Inbound Staging']);
                    $sug = $sl->suggested_bin_id
                        ? ['binId' => $sl->suggested_bin_id, 'ar' => $sl->suggestion_ar ?: 'موقع مقترح', 'en' => $sl->suggestion_en ?: 'Suggested bin']
                        : $this->suggestBin($sl->product_id, $s->warehouse_id);
                    PutawayTask::create([
                        'number' => $this->numbering->next('PUT'), 'grn_id' => $grn->id, 'grn_line_id' => $gl->id, 'shipment_id' => $s->id, 'product_id' => $sl->product_id,
                        'batch_id' => $batchId, 'batch_no' => $batchNo, 'expiry_date' => $expiry, 'qty' => $accepted, 'warehouse_id' => $s->warehouse_id,
                        'suggested_bin_id' => $sug['binId'], 'suggestion_ar' => $sug['ar'], 'suggestion_en' => $sug['en'],
                    ]);
                    StagingEntry::create(['direction' => 'in', 'bin_id' => $stgIn->id, 'reference_type' => 'GoodsReceipt', 'reference_id' => $grn->id, 'reference_number' => $number, 'qty' => $accepted]);
                    $putawayTasks++;
                }
                if ($damaged > 0) {
                    $this->inventory->post($user, $txId, ['type' => 'dmg', 'productId' => $sl->product_id, 'batchId' => $batchId, 'qty' => $damaged,
                        'to' => ['warehouseId' => $s->warehouse_id, 'binId' => $dmgBin->id], 'reference' => $reference, 'note' => 'تالف عند الاستلام — منطقة التالف، لا يدخل المتاح']);
                    $this->exceptions->raise($user, $excBase + ['kind' => 'damage', 'textAr' => "{$nameAr}: {$damaged} تالفة عند الاستلام — موثقة، لم تدخل المخزون المتاح", 'textEn' => "{$nameEn}: {$damaged} damaged at receiving"]);
                    $exceptionsRaised++;
                }
                if ($rejected > 0) {
                    $this->exceptions->raise($user, $excBase + ['kind' => 'rejected', 'textAr' => "{$nameAr}: {$rejected} مرفوضة عند الفحص (QC) — تُعاد للمورد", 'textEn' => "{$nameEn}: {$rejected} rejected at QC"]);
                    $exceptionsRaised++;
                }
                if ($remaining > 0) {
                    $this->exceptions->raise($user, $excBase + ['kind' => 'shortage', 'textAr' => "{$nameAr}: كمية مفتوحة {$remaining} على {$s->po->number} — استلام جزئي", 'textEn' => "{$nameEn}: open qty {$remaining} on {$s->po->number}"]);
                    $exceptionsRaised++;
                }
                $short = fn (string $name) => implode(' ', array_slice(explode(' ', $name), 0, 2));
                $sumAr[] = "{$short($nameAr)} {$accepted}/{$sl->ordered_qty}".($damaged ? " ({$damaged} تالفة)" : '').($rejected ? " ({$rejected} مرفوضة)" : '');
                $sumEn[] = "{$short((string) $nameEn)} {$accepted}/{$sl->ordered_qty}".($damaged ? " ({$damaged} dmg)" : '').($rejected ? " ({$rejected} rej)" : '');
            }
            $grn->update(['summary_ar' => implode(' · ', $sumAr), 'summary_en' => implode(' · ', $sumEn)]);

            // PO status
            $openQty = (int) PoLine::where('po_id', $s->po_id)->get()->sum(fn ($l) => max(0, $l->qty - $l->received_qty - $l->damaged_qty - $l->rejected_qty));
            $poStatus = $openQty === 0 ? 'received' : 'partial';
            $poFrom = $s->po->status;
            PurchaseOrder::whereKey($s->po_id)->update(['open_qty' => $openQty, 'status' => $poStatus]);
            $this->audit->status($user, 'PurchaseOrder', $s->po_id, $s->po->number, $poFrom, $poStatus, "GRN {$number}", $txId);
            $shipStatus = $putawayTasks ? 'putaway' : 'done';
            $s->update(['status' => $shipStatus, 'completed_at' => $putawayTasks ? null : now()]);
            $this->audit->status($user, 'InboundShipment', $s->id, $s->number, 'inspecting', $shipStatus, "GRN {$number}", $txId);
            $this->audit->log($user, ['action' => 'GRN.POST', 'entityType' => 'GoodsReceipt', 'entityId' => $grn->id, 'entityNumber' => $number,
                'newValue' => ['shipment' => $s->number, 'po' => $s->po->number, 'lines' => count($lines), 'notes' => $notes], 'transactionId' => $txId]);
            $this->notify->activity($user, 'GoodsReceipt', $grn->id, $number,
                "أُصدر {$number} — السليم في Inbound Staging بانتظار Putaway".($exceptionsRaised ? "، و{$exceptionsRaised} استثناء فُتح للمشتريات" : ''), "GRN {$number} posted", ['wm', 'proc']);
            $this->notify->event('InventoryReceived', ['grn' => $number, 'po' => $s->po->number, 'supplier' => $s->supplier_id, 'warehouse' => $s->warehouse_id]);

            return $number;
        });

        return $this->getGrn($number);
    }

    public function getGrn(string $idOrNumber): array
    {
        $g = GoodsReceipt::with([
            'po:id,number,status', 'supplier:id,code,name_ar,name_en', 'warehouse:id,code,name_ar', 'shipment:id,number,status',
            'lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product:id,sku,name_ar,name_en', 'lines.qcResults',
            'putaways' => fn ($q) => $q->orderBy('created_at')->orderBy('number'), 'putaways.suggestedBin:id,code', 'putaways.actualBin:id,code', 'putaways.product:id,sku,name_ar',
        ])->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('GRN_NOT_FOUND', 'GRN غير موجود');
        $movements = InventoryMovement::with(['srcBin:id,code', 'dstBin:id,code'])->where('reference_type', 'GoodsReceipt')->where('reference_id', $g->id)
            ->orderBy('created_at')->orderBy('number')->get()
            ->map(fn (InventoryMovement $m) => self::movementRow($m))->all();

        return $g->toArray() + ['movements' => $movements];
    }

    /** The ledger columns the document pages show (same projection as the reference). */
    public static function movementRow(InventoryMovement $m): array
    {
        return [
            'number' => $m->number, 'type' => $m->type, 'qty' => $m->qty, 'batchNo' => $m->batch_no, 'createdAt' => $m->created_at?->toJSON(),
            'dstBin' => $m->dstBin ? ['code' => $m->dstBin->code] : null, 'srcBin' => $m->srcBin ? ['code' => $m->srcBin->code] : null,
        ];
    }

    /** @param  array{warehouse?:?string, supplier?:?string, po?:?string, from?:?string, to?:?string}  $filters */
    public function listGrns(Paging $page, array $filters): array
    {
        $query = GoodsReceipt::with(['po:id,number', 'supplier:id,code,name_ar,name_en', 'warehouse:id,code', 'lines:id,grn_id,accepted_qty,damaged_qty,rejected_qty,remaining_qty']);
        if (! empty($filters['warehouse'])) {
            $query->whereHas('warehouse', fn ($w) => $w->where('code', $filters['warehouse']));
        }
        if (! empty($filters['supplier'])) {
            $query->whereHas('supplier', fn ($s) => $s->where('code', $filters['supplier']));
        }
        if (! empty($filters['po'])) {
            $query->whereHas('po', fn ($p) => $p->where('number', 'like', "%{$filters['po']}%"));
        }
        if (! empty($filters['from'])) {
            $query->where('posted_at', '>=', Carbon::parse($filters['from']));
        }
        if (! empty($filters['to'])) {
            $query->where('posted_at', '<=', Carbon::parse($filters['to']));
        }
        if ($page->q) {
            $q = $page->q;
            $query->where(fn ($w) => $w->where('number', 'like', "%{$q}%")->orWhereHas('po', fn ($p) => $p->where('number', 'like', "%{$q}%"))->orWhere('summary_ar', 'like', "%{$q}%"));
        }

        return $page->paginate($query->orderByDesc('posted_at')->orderByDesc('id'));
    }

    // ───────────── putaway ─────────────

    /** @param  array{warehouse?:?string, status?:?string, grn?:?string}  $filters */
    public function listPutaway(Paging $page, array $filters): array
    {
        $query = PutawayTask::with([
            'product:id,sku,name_ar,name_en,storage_class', 'product.barcodes' => fn ($q) => $q->where('is_primary', true),
            'grn:id,number', 'shipment:id,number', 'warehouse:id,code', 'suggestedBin:id,code,zone_id', 'suggestedBin.zone:id,code,type', 'actualBin:id,code',
        ])->where('status', ($filters['status'] ?? null) ?: 'open');
        if (! empty($filters['warehouse'])) {
            $query->whereHas('warehouse', fn ($w) => $w->where('code', $filters['warehouse']));
        }
        if (! empty($filters['grn'])) {
            $query->whereHas('grn', fn ($g) => $g->where('number', $filters['grn']));
        }

        return $page->paginate($query->orderBy('created_at')->orderBy('number'));
    }

    /**
     * Putaway confirmation: scan product + bin → validate → move STG-IN → bin. A duplicate confirmation is rejected.
     *
     * @return array{task:string, bin:string, movement:string, shipmentDone:?bool}
     */
    public function confirmPutaway(AuthUser $user, string $idOrNumber, ?string $scannedBin = null, ?string $scannedProduct = null): array
    {
        $t = PutawayTask::with(['product.barcodes', 'suggestedBin', 'grn'])->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('PUTAWAY_NOT_FOUND', 'مهمة التخزين غير موجودة');
        if ($t->status !== 'open') {
            throw AppError::conflict('PUTAWAY_DONE', 'المهمة منفَّذة مسبقًا — لا تكرار', 'Putaway task already confirmed');
        }
        $targetCode = mb_strtoupper(trim((string) ($scannedBin ?: ($t->suggestedBin?->code ?? ''))));
        if ($targetCode === '') {
            throw AppError::validation('BIN_REQUIRED', 'امسح موقع التخزين (Scan Location)', 'Scan the bin');
        }
        if ($scannedProduct) {
            $sp = trim($scannedProduct);
            $ok = mb_strtoupper($sp) === mb_strtoupper($t->product->sku) || $t->product->barcodes->contains(fn ($b) => $b->barcode === $sp);
            if (! $ok) {
                throw AppError::rule('WRONG_PRODUCT', "Scan منتج خاطئ: {$scannedProduct} ≠ {$t->product->sku} — مرفوض", "Wrong product scan {$scannedProduct} ≠ {$t->product->sku}");
            }
        }

        // Validation + exceptions happen OUTSIDE the movement transaction, so a rejected attempt still leaves its exception persisted.
        $raiseWrongLoc = fn (string $textAr, string $textEn) => $this->exceptions->raise($user, ['kind' => 'wrongloc', 'severity' => 'w', 'ownerRole' => 'wm',
            'entityType' => 'GoodsReceipt', 'entityId' => $t->grn_id, 'entityNumber' => $t->grn->number, 'documentType' => 'PutawayTask', 'documentId' => $t->id, 'documentNumber' => $t->number,
            'textAr' => $textAr, 'textEn' => $textEn]);
        try {
            $bin = $this->inventory->bin($t->warehouse_id, $targetCode);
        } catch (AppError $e) {
            $raiseWrongLoc("مسح موقع غير موجود {$targetCode} لـ {$t->product->name_ar}", "Unknown bin scan {$targetCode}");
            throw $e;
        }
        $lc = $this->inventory->locCheck($t->product_id, $bin->id);
        if (! $lc['ok']) {
            $raiseWrongLoc("محاولة تخزين {$t->product->name_ar} في {$bin->code} — {$lc['why']}", "Wrong location attempt {$bin->code} — {$lc['whyEn']}");
            throw AppError::rule('WRONG_LOCATION', "موقع خاطئ — {$lc['why']}. Putaway مرفوض", "Wrong location — {$lc['whyEn']}");
        }
        if (InventoryBalance::where('bin_id', $bin->id)->where('quarantine', true)->where('on_hand', '>', 0)->exists()) {
            throw AppError::rule('BIN_QUARANTINED', "الموقع {$bin->code} محجور — مرفوض", "Bin {$bin->code} is quarantined");
        }

        $txId = $this->inventory->newTxId();

        return DB::transaction(function () use ($user, $t, $bin, $txId) {
            $locked = PutawayTask::whereKey($t->id)->lockForUpdate()->first();
            if ($locked->status !== 'open') {
                throw AppError::conflict('PUTAWAY_DONE', 'المهمة منفَّذة مسبقًا — لا تكرار', 'Putaway task already confirmed');
            }
            $stgIn = $this->inventory->specialBin($t->warehouse_id, 'STG-IN');
            $mv = $this->inventory->post($user, $txId, ['type' => 'putaway', 'productId' => $t->product_id, 'batchId' => $t->batch_id, 'qty' => $t->qty,
                'from' => ['warehouseId' => $t->warehouse_id, 'binId' => $stgIn->id], 'to' => ['warehouseId' => $t->warehouse_id, 'binId' => $bin->id],
                'reference' => ['type' => 'PutawayTask', 'id' => $t->id, 'number' => $t->number], 'note' => "{$t->grn->number} · Putaway → {$bin->code}"]);
            $locked->update(['status' => 'done', 'actual_bin_id' => $bin->id, 'confirmed_by_id' => $user->id, 'confirmed_by' => $user->username, 'confirmed_at' => now()]);
            StagingEntry::where('reference_type', 'GoodsReceipt')->where('reference_id', $t->grn_id)->where('status', 'waiting')->where('qty', $t->qty)
                ->update(['status' => 'cleared', 'cleared_at' => now()]);
            $this->audit->log($user, ['action' => 'PUTAWAY.CONFIRM', 'entityType' => 'PutawayTask', 'entityId' => $t->id, 'entityNumber' => $t->number,
                'field' => 'bin', 'oldValue' => 'STG-IN', 'newValue' => $bin->code, 'transactionId' => $txId]);
            $remaining = $t->shipment_id ? PutawayTask::where('shipment_id', $t->shipment_id)->where('status', 'open')->count() : 0;
            if ($t->shipment_id && $remaining === 0) {
                $sh = InboundShipment::whereKey($t->shipment_id)->lockForUpdate()->firstOrFail();
                $sh->update(['status' => 'done', 'completed_at' => now()]);
                $this->audit->status($user, 'InboundShipment', $sh->id, $sh->number, 'putaway', 'done', null, $txId);
            }
            $this->notify->activity($user, 'PutawayTask', $t->id, $t->number, "تأكيد تخزين {$t->product->name_ar} × {$t->qty} في {$bin->code} ({$t->grn->number})", "Putaway {$t->qty} × {$t->product->sku} → {$bin->code}");

            return ['task' => $t->number, 'bin' => $bin->code, 'movement' => $mv->number, 'shipmentDone' => $t->shipment_id ? $remaining === 0 : null];
        });
    }

    // ───────────── internals ─────────────

    private function shipmentWith(): array
    {
        return [
            'po:id,number,status,due_date,open_qty', 'supplier:id,code,name_ar,name_en', 'warehouse:id,code,name_ar,name_en',
            'lines' => fn ($q) => $q->orderBy('line_no'),
            'lines.product:id,sku,name_ar,name_en,storage_class,tracks_expiry', 'lines.product.barcodes' => fn ($q) => $q->where('is_primary', true),
            'lines.suggestedBin:id,code,zone_id', 'lines.suggestedBin.zone:id,code,type', 'lines.poLine:id,qty,received_qty,damaged_qty,rejected_qty',
            'grns:id,shipment_id,number,posted_at,posted_by',
        ];
    }

    /** Adds per-line previouslyReceived / openQty and the shipment totals. */
    private function decorate(InboundShipment $s): array
    {
        $out = $s->toArray();
        $out['lines'] = array_map(function (array $l) {
            $prev = $l['acceptedQty'] + $l['damagedQty'] + $l['rejectedQty'];

            return $l + ['previouslyReceived' => $prev, 'openQty' => max(0, $l['orderedQty'] - $prev)];
        }, $out['lines']);
        $sum = fn (string $key) => array_sum(array_column($out['lines'], $key));
        $out['totals'] = ['ordered' => $sum('orderedQty'), 'accepted' => $sum('acceptedQty'), 'damaged' => $sum('damagedQty'), 'rejected' => $sum('rejectedQty'), 'open' => $sum('openQty')];

        return $out;
    }

    private function transition(AuthUser $user, string $idOrNumber, string $to, array $extra = []): InboundShipment
    {
        return DB::transaction(function () use ($user, $idOrNumber, $to, $extra) {
            $s = InboundShipment::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
                ?? throw AppError::notFound('SHIPMENT_NOT_FOUND', 'الشحنة غير موجودة');
            if ($s->locked) {
                throw AppError::rule('SHIPMENT_LOCKED', 'الشحنة مقفلة — بانتظار تأكيد المورد', 'Shipment locked');
            }
            if (! Sm::can('SHIPMENT_TRANSITIONS', $s->status, $to)) {
                throw AppError::rule('SHIPMENT_TRANSITION', "انتقال غير مسموح: {$s->status} ← {$to}", "Invalid transition {$s->status} → {$to}");
            }
            $from = $s->status;
            $s->update(['status' => $to] + $extra);
            $this->audit->status($user, 'InboundShipment', $s->id, $s->number, $from, $to);

            return $s->refresh();
        });
    }
}
