<?php

namespace App\Services\Returns;

use App\Models\Batch;
use App\Models\Bin;
use App\Models\Customer;
use App\Models\FulfillmentOrder;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
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
use App\Services\Inventory\InventoryService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sm;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Returns: requested (pending) → approved → received (stock enters RET-01, not available) → inspection → decision
 * (restock → valid bin · quarantine → QTN-01 · damaged → DMG-01 · scrap → out · return to supplier → out) → closed.
 * A decision before inspection is rejected by the state machine; every decision is a transaction + ledger + audit.
 */
class ReturnsService
{
    private const REASON_AR = ['damaged' => 'تالف', 'wrong_product' => 'منتج خاطئ', 'wrong_qty' => 'كمية خاطئة', 'expired' => 'منتهي الصلاحية', 'cust_reject' => 'رفض العميل',
        'del_fail' => 'فشل توصيل', 'quality' => 'مشكلة جودة', 'partial' => 'تسليم جزئي', 'other' => 'أخرى'];

    private const TYPE_AR = ['cust' => 'مرتجع عميل', 'sup' => 'إرجاع لمورد', 'del' => 'مرتجع توصيل', 'dmg' => 'أصناف تالفة'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
        private readonly InventoryService $inventory,
    ) {}

    /** @param  array{status?:?string, type?:?string, warehouse?:?string}  $filters */
    public function list(Paging $page, array $filters): array
    {
        $query = ReturnOrder::with($this->with());
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['warehouse'])) {
            $query->whereHas('warehouse', fn ($w) => $w->where('code', $filters['warehouse']));
        }
        if ($page->q) {
            $q = $page->q;
            $query->where(fn ($w) => $w->where('number', 'like', "%{$q}%")->orWhere('source_ar', 'like', "%{$q}%")->orWhere('reference', 'like', "%{$q}%"));
        }

        return $page->paginate($query->orderByDesc('created_at')->orderByDesc('id'));
    }

    public function get(string $idOrNumber): array
    {
        $r = ReturnOrder::with($this->with())->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('RTN_NOT_FOUND', 'المرتجع غير موجود');
        $history = StatusHistory::where('entity_type', 'ReturnOrder')->where('entity_id', $r->id)->orderBy('at')->orderBy('id')->get();
        $movements = InventoryMovement::with(['srcBin:id,code', 'dstBin:id,code'])->where('reference_type', 'ReturnOrder')->where('reference_id', $r->id)
            ->orderBy('created_at')->orderBy('number')->get()
            ->map(fn (InventoryMovement $m) => [
                'number' => $m->number, 'type' => $m->type, 'qty' => $m->qty, 'batchNo' => $m->batch_no, 'createdAt' => $m->created_at?->toJSON(),
                'srcBin' => $m->srcBin ? ['code' => $m->srcBin->code] : null, 'dstBin' => $m->dstBin ? ['code' => $m->dstBin->code] : null,
            ])->all();

        return $r->toArray() + ['history' => $history->toArray(), 'movements' => $movements];
    }

    /**
     * Creates a return. Joins the caller's transaction when there is one (the delivery flow creates the return of a
     * partial / failed delivery already `approved`: the goods are physically on the truck coming back).
     *
     * @param  array{type:string, source:string, reference?:?string, customerCode?:?string, supplierCode?:?string, foNumber?:?string, tripNumber?:?string,
     *               warehouseCode:string, reasonCode:string, notes?:?string, attachments?:?array, lines:array<int, array{sku:string, qty:int, batchNo?:?string}>}  $dto
     */
    public function create(AuthUser $user, array $dto, string $status = 'pending'): ReturnOrder
    {
        return DB::transaction(function () use ($user, $dto, $status) {
            $wh = Warehouse::where('code', $dto['warehouseCode'])->first() ?? throw AppError::notFound('WH_NOT_FOUND', 'المستودع غير موجود');
            $customer = ! empty($dto['customerCode']) ? Customer::where('code', $dto['customerCode'])->first() : null;
            $supplier = ! empty($dto['supplierCode']) ? Supplier::where('code', $dto['supplierCode'])->first() : null;
            if ($dto['type'] === 'sup' && ! $supplier) {
                throw AppError::validation('SUPPLIER_REQUIRED', 'إرجاع المورد يحتاج مورّدًا مسجلًا', 'Supplier return needs a registered supplier');
            }
            $fo = ! empty($dto['foNumber']) ? FulfillmentOrder::where('number', $dto['foNumber'])->first() : null;
            $trip = ! empty($dto['tripNumber']) ? Trip::where('number', $dto['tripNumber'])->first() : null;
            $products = Product::whereIn('sku', array_column($dto['lines'], 'sku'))->get()->keyBy('sku');
            $lines = [];
            foreach (array_values($dto['lines']) as $i => $l) {
                $p = $products->get($l['sku']) ?? throw AppError::validation('SKU_NOT_FOUND', "المنتج {$l['sku']} غير موجود");
                $batchNo = ($l['batchNo'] ?? null) ?: null;
                $batch = $batchNo ? Batch::where('product_id', $p->id)->where('batch_no', $batchNo)->first() : null;
                $lines[] = ['line_no' => $i + 1, 'product_id' => $p->id, 'qty' => (int) $l['qty'], 'batch_no' => $batchNo, 'batch_id' => $batch?->id, 'expiry_date' => $batch?->expiry_date];
            }
            $notes = ($dto['notes'] ?? null) ?: null;
            $reasonAr = self::REASON_AR[$dto['reasonCode']] ?? $dto['reasonCode'];
            $number = $this->numbering->next('RTN');
            $r = ReturnOrder::create([
                'number' => $number, 'type' => $dto['type'], 'source_ar' => $dto['source'], 'source_en' => $dto['source'], 'reference' => $dto['reference'] ?? null,
                'customer_id' => $customer?->id, 'supplier_id' => $supplier?->id, 'fo_id' => $fo?->id, 'trip_id' => $trip?->id, 'warehouse_id' => $wh->id, 'status' => $status,
                'reason_code' => $dto['reasonCode'], 'reason_ar' => $reasonAr.($notes ? ' — '.$notes : ''), 'reason_en' => $dto['reasonCode'], 'notes' => $notes,
                'attachments' => array_values($dto['attachments'] ?? []), 'created_by_id' => $user->id, 'created_by' => $user->username,
            ]);
            foreach ($lines as $line) {
                ReturnLine::create(['return_id' => $r->id] + $line);
            }
            $this->audit->log($user, ['action' => 'RTN.CREATE', 'entityType' => 'ReturnOrder', 'entityId' => $r->id, 'entityNumber' => $number,
                'newValue' => ['type' => $dto['type'], 'reason' => $dto['reasonCode'], 'lines' => $dto['lines']]]);
            $this->audit->status($user, 'ReturnOrder', $r->id, $number, null, $status);
            $typeAr = self::TYPE_AR[$dto['type']] ?? 'مرتجع';
            $this->notify->activity($user, 'ReturnOrder', $r->id, $number, "أُنشئ {$typeAr} {$number} — سبب: {$reasonAr}", "Return {$number} created", ['wm', 'super']);

            return $r->refresh();
        });
    }

    public function approve(AuthUser $user, string $id): array
    {
        return $this->transition($user, $id, 'approved', fn () => ['approved_at' => now()]);
    }

    public function reject(AuthUser $user, string $id, ?string $reason = null): array
    {
        return $this->transition($user, $id, 'rejected', fn () => [], $reason);
    }

    /** Receive: the goods physically enter the returns area (RET-01) — not available, awaiting inspection. */
    public function receive(AuthUser $user, string $id): array
    {
        return $this->transition($user, $id, 'received', function (ReturnOrder $r) use ($user) {
            $ret = $this->inventory->specialBin($r->warehouse_id, 'RET-01');
            $txId = $this->inventory->newTxId();
            foreach ($r->lines as $l) {
                $this->inventory->post($user, $txId, ['type' => 'ret', 'productId' => $l->product_id, 'batchId' => $l->batch_id, 'qty' => $l->qty,
                    'to' => ['warehouseId' => $r->warehouse_id, 'binId' => $ret->id], 'reference' => ['type' => 'ReturnOrder', 'id' => $r->id, 'number' => $r->number],
                    'note' => 'استلام مرتجع → منطقة الفحص RET-01']);
            }
            ReturnReceiving::create(['return_id' => $r->id, 'received_by_id' => $user->id, 'received_by' => $user->username, 'received_at' => now(), 'bin_code' => 'RET-01']);

            return ['received_at' => now()];
        });
    }

    public function inspect(AuthUser $user, string $id, ?string $findings = null): array
    {
        return $this->transition($user, $id, 'inspect', function (ReturnOrder $r) use ($user, $findings) {
            ReturnInspection::create(['return_id' => $r->id, 'inspector_id' => $user->id, 'inspector' => $user->username, 'started_at' => now(), 'findings' => $findings]);

            return ['inspected_at' => now()];
        });
    }

    /**
     * Decision after inspection: every line leaves RET-01 through the inventory engine.
     *
     * @param  array{decision:string, binCode?:?string, note?:?string}  $dto
     */
    public function decide(AuthUser $user, string $idOrNumber, array $dto): array
    {
        $decision = $dto['decision'];
        $id = DB::transaction(function () use ($user, $idOrNumber, $dto, $decision) {
            $r = ReturnOrder::with(['lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product', 'supplier'])
                ->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
                ?? throw AppError::notFound('RTN_NOT_FOUND', 'المرتجع غير موجود');
            if ($r->status !== 'inspect') {
                throw AppError::rule('RTN_DECISION_STATE', "القرار غير مسموح في الحالة «{$r->status}» — المسار: اعتماد → استلام → فحص → قرار", "Decision not allowed in status {$r->status}");
            }
            $txId = $this->inventory->newTxId();
            $ret = $this->inventory->specialBin($r->warehouse_id, 'RET-01');
            $msgs = [];
            foreach ($r->lines as $l) {
                $to = null;
                $type = 'restock';
                $quarantine = false;
                $note = '';
                $lineUpdate = ['decision' => $decision, 'inspected_qty' => $l->qty];
                if ($decision === 'restock') {
                    $bin = $this->restockBin($r, $l, $dto['binCode'] ?? null);
                    $to = ['warehouseId' => $r->warehouse_id, 'binId' => $bin->id];
                    $note = "قرار: إعادة للمخزون → {$bin->code}";
                    $lineUpdate['bin_id'] = $bin->id;
                } elseif ($decision === 'qtn') {
                    $to = ['warehouseId' => $r->warehouse_id, 'binId' => $this->inventory->specialBin($r->warehouse_id, 'QTN-01')->id];
                    $type = 'qtn';
                    $quarantine = true;
                    $note = 'قرار: حجر بانتظار الجودة';
                } elseif ($decision === 'dmg') {
                    $to = ['warehouseId' => $r->warehouse_id, 'binId' => $this->inventory->specialBin($r->warehouse_id, 'DMG-01')->id];
                    $type = 'dmg';
                    $note = 'قرار: تالف — منطقة التالف (لا يدخل المتاح)';
                } elseif ($decision === 'dispose') {
                    $type = 'scrap';
                    $note = 'قرار: إعدام موثق (شهادة إتلاف)';
                } elseif ($decision === 'sup') {
                    if (! $r->supplier_id) {
                        throw AppError::validation('SUPPLIER_REQUIRED', 'الإرجاع للمورد يحتاج مورّدًا على المرتجع', 'Supplier required');
                    }
                    $type = 'supret';
                    $note = 'إرجاع للمورد '.($r->supplier?->name_ar ?? '');
                }
                $mv = $this->inventory->post($user, $txId, ['type' => $type, 'productId' => $l->product_id, 'batchId' => $l->batch_id, 'qty' => $l->qty,
                    'from' => ['warehouseId' => $r->warehouse_id, 'binId' => $ret->id], 'to' => $to, 'quarantine' => $quarantine,
                    'reference' => ['type' => 'ReturnOrder', 'id' => $r->id, 'number' => $r->number], 'note' => $note]);
                $l->update($lineUpdate);
                ReturnDecision::create(['return_id' => $r->id, 'decision' => $decision, 'qty' => $l->qty, 'target_bin_id' => $to['binId'] ?? null, 'movement_id' => $mv->id,
                    'decided_by_id' => $user->id, 'decided_by' => $user->username, 'note' => $dto['note'] ?? null, 'at' => now()]);
                $msgs[] = "{$l->product->name_ar} × {$l->qty}: {$note}";
            }
            $r->update(['status' => 'closed', 'decision' => $decision, 'decided_by_id' => $user->id, 'decided_by' => $user->username, 'decided_at' => now(), 'closed_at' => now()]);
            $this->audit->status($user, 'ReturnOrder', $r->id, $r->number, 'inspect', 'closed', $decision, $txId);
            $this->audit->log($user, ['action' => 'RTN.DECIDE', 'entityType' => 'ReturnOrder', 'entityId' => $r->id, 'entityNumber' => $r->number,
                'field' => 'decision', 'oldValue' => 'inspect', 'newValue' => $decision, 'transactionId' => $txId]);
            $label = config("scm.RETURN_DECISION_LABELS.{$decision}.ar");
            $this->notify->activity($user, 'ReturnOrder', $r->id, $r->number, "قرار المرتجع {$r->number}: {$label} — ".implode(' · ', $msgs), "Return {$r->number} decision: {$decision}", ['sales', 'wm']);
            if ($decision === 'sup') {
                $this->notify->event('SupplierReturnCreated', ['rtn' => $r->number, 'supplier' => $r->supplier_id]);
            }

            return $r->id;
        });

        return $this->get($id);
    }

    // ───────────── internals ─────────────

    /** Restock target: the scanned bin (location-checked), else the bin already holding the product, else the first storage bin of the right zone class. */
    private function restockBin(ReturnOrder $r, ReturnLine $l, ?string $binCode): Bin
    {
        if ($binCode) {
            $bin = $this->inventory->bin($r->warehouse_id, $binCode);
            $lc = $this->inventory->locCheck($l->product_id, $bin->id);
            if (! $lc['ok']) {
                throw AppError::rule('WRONG_LOCATION', "موقع خاطئ — {$lc['why']}", $lc['whyEn']);
            }

            return $bin;
        }
        $class = $l->product->storage_class;
        $inZone = fn ($b) => $b->where('status', 'active')->whereHas('zone', fn ($z) => $z->where('type', $class));
        $candidate = InventoryBalance::with('bin')->where('product_id', $l->product_id)->where('warehouse_id', $r->warehouse_id)->where('on_hand', '>', 0)->where('quarantine', false)
            ->whereHas('bin', $inZone)->orderBy('id')->first();
        $bin = $candidate?->bin ?? $inZone(Bin::where('warehouse_id', $r->warehouse_id)->where('type', '!=', 'virtual'))->orderBy('code')->first();

        return $bin ?? throw AppError::rule('NO_BIN', 'لا موقع تخزين مطابق لشروط الصنف — اختر حجرًا أو موقعًا صحيحًا', 'No matching storage bin');
    }

    /** @param  Closure(ReturnOrder):array  $extra  side effects of the transition; returns extra columns to set */
    private function transition(AuthUser $user, string $idOrNumber, string $to, Closure $extra, ?string $note = null): array
    {
        $id = DB::transaction(function () use ($user, $idOrNumber, $to, $extra, $note) {
            // The row is locked so the same return can never be received (stock posted) twice.
            $r = ReturnOrder::with(['lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product'])
                ->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
                ?? throw AppError::notFound('RTN_NOT_FOUND', 'المرتجع غير موجود');
            if (! Sm::can('RETURN_TRANSITIONS', $r->status, $to)) {
                throw AppError::rule('RTN_TRANSITION', "انتقال غير مسموح: {$r->status} ← {$to} — المسار: اعتماد → استلام → فحص → قرار", "Invalid transition {$r->status} → {$to}");
            }
            $from = $r->status;
            $r->update(['status' => $to] + $extra($r));
            $this->audit->status($user, 'ReturnOrder', $r->id, $r->number, $from, $to, $note);

            return $r->id;
        });

        return $this->get($id);
    }

    private function with(): array
    {
        return [
            'customer:id,code,name_ar', 'supplier:id,code,name_ar', 'warehouse:id,code,name_ar', 'fo:id,number', 'trip:id,number',
            'lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product:id,sku,name_ar,name_en,storage_class', 'lines.bin:id,code', 'lines.batch:id,batch_no,expiry_date',
            'receiving', 'inspection', 'decisions' => fn ($q) => $q->orderBy('at')->orderBy('id'),
        ];
    }
}
