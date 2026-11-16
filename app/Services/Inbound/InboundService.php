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
use App\Models\ShipmentLine;
use App\Models\StagingEntry;
use App\Models\StatusHistory;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Services\Exceptions\ExceptionsService;
use App\Services\Inventory\InventoryService;
use App\Services\Procurement\ProcurementHelpers as H;
use App\Services\Sales\WaitingOrders;
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
 *
 * Ways out of every state (docs/ops-hardening/SPEC.md §W7): expected → cancel · arrived → un-arrive (wrong click) ·
 * arrived | inspecting → refuse at the gate · putaway (nothing put away yet) → void the receipt (GrnVoidService).
 * What the supplier still owes — including goods rejected at inspection — stays open on the purchase order
 * (ProcurementHelpers::openQty) and arrives on a follow-up shipment.
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

        return $this->decorate($s) + ['history' => $history->toArray(), 'backorder' => $this->backorderState($s->po_id),
            'policy' => ['rejectedStaysOpen' => H::rejectedStaysOpen(), 'allowExcess' => (bool) $this->settings->get('receiving.allowExcess')]];
    }

    /** Statuses in which a shipment is still waiting for goods: only one such shipment may exist per purchase order. */
    private const AWAITING_GOODS = ['expected', 'arrived', 'inspecting'];

    /** Purchase-order statuses that can still receive goods. */
    private const PO_RECEIVABLE = ['sent', 'confirmed', 'partial'];

    /**
     * Can a follow-up shipment be opened for what is still undelivered on the purchase order?
     *
     * @return array{allowed:bool, openQty:int, openLines:int, reasonAr:?string, reasonEn:?string, shipment:?string}
     */
    public function backorderState(string $poId): array
    {
        $po = PurchaseOrder::with('lines')->find($poId);
        $staysOpen = H::rejectedStaysOpen();
        $open = $po ? $po->lines->map(fn ($l) => H::openQty($l, $staysOpen)) : collect();
        $state = ['allowed' => false, 'openQty' => (int) $open->sum(), 'openLines' => $open->filter()->count(), 'reasonAr' => null, 'reasonEn' => null, 'shipment' => null];
        if (! $po || $state['openQty'] === 0) {
            return ['reasonAr' => 'لا كميات متبقية على أمر الشراء', 'reasonEn' => 'Nothing is left open on the purchase order'] + $state;
        }
        if (! in_array($po->status, self::PO_RECEIVABLE, true)) {
            return ['reasonAr' => "أمر الشراء بحالة «{$po->status}» ولا يقبل استلامًا", 'reasonEn' => "The purchase order is {$po->status} and cannot receive goods"] + $state;
        }
        $waiting = InboundShipment::where('po_id', $po->id)->whereIn('status', self::AWAITING_GOODS)->orderBy('created_at')->value('number');
        if ($waiting) {
            return ['reasonAr' => "توجد شحنة مفتوحة لهذا الأمر: {$waiting}", 'reasonEn' => "An open shipment already exists for this order: {$waiting}", 'shipment' => $waiting] + $state;
        }

        return ['allowed' => true] + $state;
    }

    /**
     * Opens the follow-up ("backorder") shipment of a purchase order: an expected shipment whose lines are exactly the
     * quantities still undelivered. Needed after a partial receipt (the supplier delivers the rest later) and after an
     * expected shipment was cancelled. One waiting shipment per order — the order row is locked, so two clicks or two
     * users can never open two.
     */
    public function createBackorder(AuthUser $user, string $poNumberOrId, ?string $eta = null): array
    {
        $id = DB::transaction(function () use ($user, $poNumberOrId, $eta) {
            $po = PurchaseOrder::with(['lines' => fn ($q) => $q->orderBy('line_no'), 'supplier:id,code,name_ar', 'warehouse:id,code,name_ar'])
                ->where(fn ($w) => $w->where('id', $poNumberOrId)->orWhere('number', $poNumberOrId))->lockForUpdate()->first()
                ?? throw AppError::notFound('PO_NOT_FOUND', 'أمر الشراء غير موجود', 'Purchase order not found');
            $state = $this->backorderState($po->id);
            if (! $state['allowed']) {
                throw $state['shipment']
                    ? AppError::conflict('SHIPMENT_OPEN_EXISTS', $state['reasonAr'], $state['reasonEn'], ['shipment' => $state['shipment']])
                    : AppError::rule($state['openQty'] === 0 ? 'PO_NOTHING_OPEN' : 'PO_NOT_RECEIVABLE', $state['reasonAr'], $state['reasonEn']);
            }
            $number = $this->numbering->next('SHP');
            $due = $eta ? Carbon::parse($eta) : null;
            $s = InboundShipment::create(['number' => $number, 'po_id' => $po->id, 'supplier_id' => $po->supplier_id, 'warehouse_id' => $po->warehouse_id, 'eta' => $due ?? $po->due_date, 'status' => 'expected']);
            $lineNo = 0;
            $staysOpen = H::rejectedStaysOpen();
            foreach ($po->lines as $l) {
                $open = H::openQty($l, $staysOpen);
                if ($open === 0) {
                    continue;
                }
                ShipmentLine::create(['shipment_id' => $s->id, 'line_no' => ++$lineNo, 'po_line_id' => $l->id, 'product_id' => $l->product_id, 'ordered_qty' => $open,
                    'suggestion_ar' => 'يُحدَّد الموقع المقترح عند الاستلام وفق شروط التخزين', 'suggestion_en' => 'Suggested bin is assigned at receiving per storage rules']);
            }
            $this->audit->log($user, ['action' => 'SHIPMENT.BACKORDER', 'entityType' => 'InboundShipment', 'entityId' => $s->id, 'entityNumber' => $number,
                'newValue' => ['po' => $po->number, 'lines' => $lineNo, 'openQty' => $state['openQty'], 'eta' => $s->eta?->toJSON()]]);
            $this->audit->status($user, 'InboundShipment', $s->id, $number, null, 'expected', "متبقي {$po->number}");
            $this->notify->activity($user, 'InboundShipment', $s->id, $number,
                "فُتحت الشحنة {$number} لاستلام المتبقي على {$po->number} ({$state['openQty']} وحدة) من {$po->supplier->name_ar}", "Shipment {$number} opened for the {$state['openQty']} units still due on {$po->number}", ['wm', 'proc']);

            return $s->id;
        });

        return $this->getShipment($id);
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
        // only from `arrived`: the table's putaway → inspecting is the reopening done by a receipt void, never a button
        return $this->transition($user, $idOrNumber, 'inspecting', ['inspection_started_at' => now()], ['arrived']);
    }

    /** Cancel is for a shipment that never came (expected). Once the truck is at the gate the exits are refuse / un-arrive. */
    public function cancel(AuthUser $user, string $idOrNumber): InboundShipment
    {
        return $this->transition($user, $idOrNumber, 'cancelled', [], ['expected'],
            'لا تُلغى الشحنة بعد تسجيل وصولها — استخدم «رفض الشحنة عند البوابة» أو «تراجع عن تسجيل الوصول»', 'A shipment that has arrived is refused at the gate or un-arrived, not cancelled');
    }

    /** arrived → expected: the arrival was registered by mistake (wrong shipment, wrong click). Nothing else has happened yet. */
    public function unarrive(AuthUser $user, string $idOrNumber): array
    {
        $s = $this->transition($user, $idOrNumber, 'expected', ['arrived_at' => null, 'carrier' => null], ['arrived'],
            'التراجع عن الوصول ممكن فقط قبل بدء الفحص — بعد بدء الفحص ارفض الشحنة عند البوابة إن لزم', 'Un-arrive is only possible before the inspection starts', 'تراجع عن تسجيل الوصول');
        $this->audit->log($user, ['action' => 'SHIPMENT.UNARRIVE', 'entityType' => 'InboundShipment', 'entityId' => $s->id, 'entityNumber' => $s->number, 'field' => 'status', 'oldValue' => 'arrived', 'newValue' => 'expected']);
        $this->notify->activity($user, 'InboundShipment', $s->id, $s->number, "تراجع {$user->username} عن تسجيل وصول الشحنة {$s->number} — عادت «متوقعة»", "Arrival of {$s->number} was undone — the shipment is expected again", ['wm']);

        return $this->getShipment($s->id);
    }

    /**
     * Refuse the delivery at the gate: the truck is here (arrived) or is being inspected, nothing was received (no GRN),
     * and the whole delivery goes back — wrong goods, broken cold chain, no documents. The shipment is closed as
     * cancelled with who / when / why, procurement gets an exception, the purchase order stays open and a follow-up
     * shipment can be opened for the same quantity.
     */
    public function refuse(AuthUser $user, string $idOrNumber, string $reason): array
    {
        $reason = trim($reason);
        $id = DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $s = InboundShipment::with(['po:id,number,status', 'supplier:id,code,name_ar,name_en'])
                ->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
                ?? throw AppError::notFound('SHIPMENT_NOT_FOUND', 'الشحنة غير موجودة', 'Shipment not found');
            if ($s->locked) {
                throw AppError::rule('SHIPMENT_LOCKED', 'الشحنة مقفلة — بانتظار تأكيد المورد', 'Shipment locked');
            }
            $grn = GoodsReceipt::where('shipment_id', $s->id)->whereNull('voided_at')->value('number');
            if ($grn) {
                throw AppError::rule('SHIPMENT_HAS_GRN', "للشحنة {$s->number} إذن استلام {$grn} — لا تُرفض عند البوابة. ألغِ إذن الاستلام إن لم يُخزَّن منه شيء، أو أنشئ مرتجع مشتريات",
                    "Shipment {$s->number} already has receipt {$grn} — void the receipt (if nothing was put away) or raise a purchase return", ['grn' => $grn]);
            }
            if (! in_array($s->status, ['arrived', 'inspecting'], true) || ! Sm::can('SHIPMENT_TRANSITIONS', $s->status, 'cancelled')) {
                throw AppError::rule('SHIPMENT_TRANSITION', $s->status === 'expected'
                    ? "الشحنة {$s->number} لم تصل بعد — ألغِها بدل رفضها"
                    : "رفض الشحنة يكون بعد وصولها وقبل إصدار إذن الاستلام (الحالة: {$s->status})", "A shipment is refused after arrival and before its GRN (status {$s->status})");
            }
            $from = $s->status;
            $s->update(['status' => 'cancelled', 'refused_at' => now(), 'refused_by' => $user->username, 'refused_reason' => $reason]);
            $this->audit->status($user, 'InboundShipment', $s->id, $s->number, $from, 'cancelled', "رفض عند البوابة: {$reason}");
            $this->audit->log($user, ['action' => 'SHIPMENT.REFUSE', 'entityType' => 'InboundShipment', 'entityId' => $s->id, 'entityNumber' => $s->number,
                'oldValue' => $from, 'newValue' => ['status' => 'cancelled', 'reason' => $reason, 'po' => $s->po?->number, 'carrier' => $s->carrier]]);
            $poOver = in_array($s->po?->status, ['closed', 'cancelled'], true);
            $this->exceptions->raise($user, ['kind' => 'rejected', 'severity' => 'w', 'ownerRole' => 'proc', 'entityType' => 'InboundShipment', 'entityId' => $s->id, 'entityNumber' => $s->number,
                'documentType' => 'PurchaseOrder', 'documentId' => $s->po_id, 'documentNumber' => $s->po?->number,
                'textAr' => "رُفضت الشحنة {$s->number} من {$s->supplier?->name_ar} عند البوابة — {$reason}. ".($poOver ? "أمر الشراء {$s->po?->number} مقفل: أبلغ المورد بعدم التوريد" : "أمر الشراء {$s->po?->number} ما زال مفتوحًا: نسّق إعادة التوريد وافتح شحنة جديدة"),
                'textEn' => "Shipment {$s->number} from {$s->supplier?->name_en} was refused at the gate — {$reason}. ".($poOver ? "{$s->po?->number} is closed: tell the supplier not to deliver" : "{$s->po?->number} stays open: arrange redelivery and open a new shipment")]);
            $this->notify->activity($user, 'InboundShipment', $s->id, $s->number, "رُفضت الشحنة {$s->number} عند البوابة — {$reason}", "Shipment {$s->number} refused at the gate — {$reason}", ['proc', 'wm']);

            return $s->id;
        });

        return $this->getShipment($id);
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
                if ($s->status === 'cancelled') {
                    throw AppError::rule('SHIPMENT_CANCELLED', "الشحنة {$s->number} ".($s->refused_at ? 'رُفضت عند البوابة' : 'ملغاة').' — يُستلم المتبقي على أمر الشراء بشحنة متابعة جديدة', "Shipment {$s->number} is cancelled — receive what is still due on a follow-up shipment");
                }
                throw AppError::conflict('GRN_DUPLICATE', 'GRN صادر لهذه الشحنة — لا تكرار', 'GRN already posted for this shipment');
            }
            if (in_array($s->po?->status, ['closed', 'cancelled'], true)) {
                throw AppError::rule('PO_NOT_RECEIVABLE', "أمر الشراء {$s->po->number} ".($s->po->status === 'closed' ? 'مقفل' : 'ملغى').' — لا يُستلم عليه. ارفض الشحنة عند البوابة', "Purchase order {$s->po->number} is {$s->po->status} — nothing can be received on it; refuse the delivery at the gate");
            }
            $allowExcess = (bool) $this->settings->get('receiving.allowExcess');
            $staysOpen = H::rejectedStaysOpen();
            $byNo = [];
            foreach ($lines as $l) {
                $no = (int) $l['lineNo'];
                if (isset($byNo[$no])) {
                    // a repeated line used to overwrite the first one silently — a line received in several batches is sent once, with `batches`
                    throw AppError::validation('LINE_DUPLICATE', "السطر {$no} مكرر في الطلب — يُرسل كل سطر مرة واحدة، وتقسيمه على عدة دفعات يكون عبر «دفعات» السطر", "Line {$no} is repeated — send each line once and split it with its `batches`", ['lineNo' => $no]);
                }
                $byNo[$no] = $l;
            }

            // validation pass
            $splitsByNo = [];
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
                $splitsByNo[$sl->line_no] = $this->splitsOf($sl, $r);
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
                $splits = $splitsByNo[$sl->line_no]; // ≥ 1: the accepted quantity per batch; the first row also carries the line's damaged / rejected
                $prevRem = $sl->ordered_qty - $sl->accepted_qty - $sl->damaged_qty - $sl->rejected_qty;
                $undelivered = max(0, $prevRem - $accepted - $damaged - $rejected);    // the supplier did not bring it at all
                $remaining = $undelivered + ($staysOpen ? $rejected : 0);              // still owed on the PO line after this receipt
                $qcNote = $r['qcNote'] ?? null;
                $unitCost = null; // finance: receipts are valued at the purchase-order price (moving average + GRNI accrual)
                if ($sl->po_line_id && ($poLine = PoLine::whereKey($sl->po_line_id)->lockForUpdate()->first())) {
                    $poLine->update(['received_qty' => $poLine->received_qty + $accepted, 'damaged_qty' => $poLine->damaged_qty + $damaged, 'rejected_qty' => $poLine->rejected_qty + $rejected]);
                    $unitCost = (float) $poLine->price;
                }
                $nameAr = $sl->product->name_ar;
                $nameEn = $sl->product->name_en;

                foreach ($splits as $i => $sp) {
                    $first = $i === 0;
                    [$qty, $dmg, $rej] = [$sp['qty'], $first ? $damaged : 0, $first ? $rejected : 0];
                    $batchId = null;
                    if ($sp['batchNo']) {
                        $batch = Batch::where('product_id', $sl->product_id)->where('batch_no', $sp['batchNo'])->lockForUpdate()->first();
                        if ($batch) {
                            $batch->update(array_filter(['expiry_date' => $sp['expiry'], 'mfg_date' => $sp['mfg']]));
                        } else {
                            $batch = Batch::create(['product_id' => $sl->product_id, 'batch_no' => $sp['batchNo'], 'expiry_date' => $sp['expiry'], 'mfg_date' => $sp['mfg'], 'supplier_id' => $s->supplier_id]);
                        }
                        $batchId = $batch->id;
                    }
                    $gl = GrnLine::create([
                        'grn_id' => $grn->id, 'line_no' => $sl->line_no, 'split_no' => $i + 1, 'shipment_line_id' => $sl->id, 'po_line_id' => $sl->po_line_id, 'product_id' => $sl->product_id,
                        'received_qty' => $qty + $dmg + $rej, 'accepted_qty' => $qty, 'damaged_qty' => $dmg, 'rejected_qty' => $rej, 'remaining_qty' => $first ? $remaining : 0,
                        'batch_no' => $sp['batchNo'], 'batch_id' => $batchId, 'mfg_date' => $sp['mfg'], 'expiry_date' => $sp['expiry'],
                        'qc_result' => $rej > 0 ? 'rejected' : ($dmg > 0 ? 'damaged' : 'accepted'), 'qc_note' => $qcNote, 'qc_by_id' => $user->id, 'qc_at' => now(),
                    ]);
                    foreach (['accepted' => $qty, 'damaged' => $dmg, 'rejected' => $rej] as $result => $n) {
                        if ($n > 0) {
                            QcResult::create(['grn_line_id' => $gl->id, 'result' => $result, 'qty' => $n, 'inspector_id' => $user->id, 'inspector' => $user->username, 'notes' => $qcNote, 'at' => now()]);
                        }
                    }
                    if ($qty > 0) {
                        $this->inventory->post($user, $txId, ['type' => 'grn', 'productId' => $sl->product_id, 'batchId' => $batchId, 'qty' => $qty, 'unitCost' => $unitCost,
                            'to' => ['warehouseId' => $s->warehouse_id, 'binId' => $stgIn->id], 'reference' => $reference, 'note' => 'استلام مقبول → Inbound Staging']);
                        $sug = $sl->suggested_bin_id
                            ? ['binId' => $sl->suggested_bin_id, 'ar' => $sl->suggestion_ar ?: 'موقع مقترح', 'en' => $sl->suggestion_en ?: 'Suggested bin']
                            : $this->suggestBin($sl->product_id, $s->warehouse_id);
                        $task = PutawayTask::create([
                            'number' => $this->numbering->next('PUT'), 'grn_id' => $grn->id, 'grn_line_id' => $gl->id, 'shipment_id' => $s->id, 'product_id' => $sl->product_id,
                            'batch_id' => $batchId, 'batch_no' => $sp['batchNo'], 'expiry_date' => $sp['expiry'], 'qty' => $qty, 'warehouse_id' => $s->warehouse_id,
                            'suggested_bin_id' => $sug['binId'], 'suggestion_ar' => $sug['ar'], 'suggestion_en' => $sug['en'],
                        ]);
                        // the entry belongs to ITS task (reference), so a putaway clears exactly this one — never "an entry with the same quantity"
                        StagingEntry::create(['direction' => 'in', 'bin_id' => $stgIn->id, 'reference_type' => 'PutawayTask', 'reference_id' => $task->id, 'reference_number' => $task->number, 'qty' => $qty]);
                        $putawayTasks++;
                    }
                    if ($dmg > 0) {
                        $this->inventory->post($user, $txId, ['type' => 'dmg', 'productId' => $sl->product_id, 'batchId' => $batchId, 'qty' => $dmg, 'unitCost' => $unitCost,
                            'to' => ['warehouseId' => $s->warehouse_id, 'binId' => $dmgBin->id], 'reference' => $reference, 'note' => 'تالف عند الاستلام — منطقة التالف، لا يدخل المتاح']);
                    }
                }
                $head = $splits[0];
                $sl->update([
                    'accepted_qty' => $sl->accepted_qty + $accepted, 'damaged_qty' => $sl->damaged_qty + $damaged, 'rejected_qty' => $sl->rejected_qty + $rejected,
                    'batch_no' => $head['batchNo'] ?: $sl->batch_no, 'expiry_date' => $head['expiry'], 'mfg_date' => $head['mfg'],
                ]);
                if ($damaged > 0) {
                    $this->exceptions->raise($user, $excBase + ['kind' => 'damage', 'textAr' => "{$nameAr}: {$damaged} تالفة عند الاستلام — موثقة، لم تدخل المخزون المتاح", 'textEn' => "{$nameEn}: {$damaged} damaged at receiving"]);
                    $exceptionsRaised++;
                }
                if ($rejected > 0) {
                    $this->exceptions->raise($user, $excBase + ['kind' => 'rejected'] + ($staysOpen
                        ? ['textAr' => "{$nameAr}: {$rejected} مرفوضة عند الفحص (QC) — لم تدخل المخزون وتُعاد للمورد، وتبقى مفتوحة على {$s->po->number} لإعادة توريدها", 'textEn' => "{$nameEn}: {$rejected} rejected at QC — never entered stock, still owed on {$s->po->number}"]
                        : ['textAr' => "{$nameAr}: {$rejected} مرفوضة عند الفحص (QC) — تُعاد للمورد", 'textEn' => "{$nameEn}: {$rejected} rejected at QC"]));
                    $exceptionsRaised++;
                }
                if ($undelivered > 0) {
                    $this->exceptions->raise($user, $excBase + ['kind' => 'shortage', 'textAr' => "{$nameAr}: كمية مفتوحة {$undelivered} على {$s->po->number} — استلام جزئي", 'textEn' => "{$nameEn}: open qty {$undelivered} on {$s->po->number}"]);
                    $exceptionsRaised++;
                }
                $short = fn (string $name) => implode(' ', array_slice(explode(' ', $name), 0, 2));
                $batchesAr = count($splits) > 1 ? ' على '.count($splits).' دفعات' : '';
                $batchesEn = count($splits) > 1 ? ' in '.count($splits).' batches' : '';
                $sumAr[] = "{$short($nameAr)} {$accepted}/{$sl->ordered_qty}{$batchesAr}".($damaged ? " ({$damaged} تالفة)" : '').($rejected ? " ({$rejected} مرفوضة)" : '');
                $sumEn[] = "{$short((string) $nameEn)} {$accepted}/{$sl->ordered_qty}{$batchesEn}".($damaged ? " ({$damaged} dmg)" : '').($rejected ? " ({$rejected} rej)" : '');
            }
            $grn->update(['summary_ar' => implode(' · ', $sumAr), 'summary_en' => implode(' · ', $sumEn)]);

            // PO status: open = what the supplier still owes (rejected goods never arrived, so they keep the order partial)
            $openQty = (int) PoLine::where('po_id', $s->po_id)->get()->sum(fn ($l) => H::openQty($l, $staysOpen));
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

    /**
     * The accepted quantity of one receiving line, per batch. Without `batches` the line is ONE batch (the line's own
     * batch / dates, falling back to what the shipment line already declares) — exactly the old behaviour. With
     * `batches: [{qty, batchNo, mfgDate?, expiryDate}]` every entry becomes its own GRN row, batch, putaway task and
     * `grn` movement; the quantities must add up to the accepted quantity.
     *
     * @return array<int, array{qty:int, batchNo:?string, mfg:?Carbon, expiry:?Carbon}>
     */
    private function splitsOf(ShipmentLine $sl, array $r): array
    {
        $no = $sl->line_no;
        $tracked = (bool) $sl->product->tracks_expiry;
        $accepted = (int) $r['acceptedQty'];
        $batches = array_values((array) ($r['batches'] ?? []));
        if (! $batches) {
            if ($tracked && $accepted > 0 && ! (($r['expiryDate'] ?? null) || $sl->expiry_date)) {
                throw AppError::validation('EXPIRY_REQUIRED', "تاريخ الانتهاء إلزامي للسطر {$no} ({$sl->product->name_ar})", "Expiry date required for line {$no}");
            }
            if ($tracked && $accepted > 0 && ! (($r['batchNo'] ?? null) || $sl->batch_no)) {
                throw AppError::validation('BATCH_REQUIRED', "رقم الدفعة إلزامي للسطر {$no}", "Batch number required for line {$no}");
            }

            return [[
                'qty' => $accepted, 'batchNo' => trim((string) (($r['batchNo'] ?? null) ?: ($sl->batch_no ?: ''))) ?: null,
                'mfg' => ! empty($r['mfgDate']) ? Carbon::parse($r['mfgDate']) : $sl->mfg_date,
                'expiry' => ! empty($r['expiryDate']) ? Carbon::parse($r['expiryDate']) : $sl->expiry_date,
            ]];
        }
        $out = [];
        $seen = [];
        foreach ($batches as $b) {
            $qty = $b['qty'] ?? null;
            $batchNo = trim((string) ($b['batchNo'] ?? ''));
            if (! is_int($qty) || $qty <= 0) {
                throw AppError::validation('BAD_QTY', "كمية دفعة غير صالحة في السطر {$no} — كل دفعة بكمية أكبر من صفر", "Invalid batch quantity on line {$no}");
            }
            if ($batchNo === '') {
                throw AppError::validation('BATCH_REQUIRED', "رقم الدفعة إلزامي لكل دفعة في السطر {$no}", "Every batch of line {$no} needs its batch number");
            }
            if ($tracked && empty($b['expiryDate'])) {
                throw AppError::validation('EXPIRY_REQUIRED', "تاريخ الانتهاء إلزامي للدفعة {$batchNo} في السطر {$no} ({$sl->product->name_ar})", "Expiry date required for batch {$batchNo} on line {$no}");
            }
            if (isset($seen[mb_strtoupper($batchNo)])) {
                throw AppError::validation('BATCH_SPLIT_DUPLICATE', "الدفعة {$batchNo} مكررة في السطر {$no} — اجمع كميتها في صف واحد", "Batch {$batchNo} is repeated on line {$no}", ['lineNo' => $no, 'batchNo' => $batchNo]);
            }
            $seen[mb_strtoupper($batchNo)] = true;
            $out[] = ['qty' => $qty, 'batchNo' => $batchNo, 'mfg' => ! empty($b['mfgDate']) ? Carbon::parse($b['mfgDate']) : null, 'expiry' => ! empty($b['expiryDate']) ? Carbon::parse($b['expiryDate']) : null];
        }
        $sum = array_sum(array_column($out, 'qty'));
        if ($sum !== $accepted) {
            throw AppError::validation('BATCH_SPLIT_SUM', "مجموع كميات الدفعات ({$sum}) لا يساوي الكمية المقبولة ({$accepted}) في السطر {$no}", "Batch quantities ({$sum}) do not add up to the accepted quantity ({$accepted}) on line {$no}", ['lineNo' => $no, 'accepted' => $accepted, 'batches' => $sum]);
        }

        return $out;
    }

    public function getGrn(string $idOrNumber): array
    {
        $g = GoodsReceipt::with([
            'po:id,number,status', 'supplier:id,code,name_ar,name_en', 'warehouse:id,code,name_ar', 'shipment:id,number,status',
            'lines' => fn ($q) => $q->orderBy('line_no')->orderBy('split_no'), 'lines.product:id,sku,name_ar,name_en', 'lines.qcResults',
            'putaways' => fn ($q) => $q->orderBy('created_at')->orderBy('number'), 'putaways.suggestedBin:id,code', 'putaways.actualBin:id,code', 'putaways.product:id,sku,name_ar',
        ])->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('GRN_NOT_FOUND', 'GRN غير موجود');
        $movements = InventoryMovement::with(['srcBin:id,code', 'dstBin:id,code'])->where('reference_type', 'GoodsReceipt')->where('reference_id', $g->id)
            ->orderBy('created_at')->orderBy('number')->get()
            ->map(fn (InventoryMovement $m) => self::movementRow($m))->all();

        // `void`: may this receipt be voided now, and if not what blocks it — the page shows the button or the reason
        return $g->toArray() + ['movements' => $movements, 'voided' => $g->voided_at !== null, 'void' => app(GrnVoidService::class)->state($g)];
    }

    /** The ledger columns the document pages show (same projection as the reference). */
    public static function movementRow(InventoryMovement $m): array
    {
        return [
            'number' => $m->number, 'type' => $m->type, 'qty' => $m->qty, 'batchNo' => $m->batch_no, 'createdAt' => $m->created_at?->toJSON(),
            'dstBin' => $m->dstBin ? ['code' => $m->dstBin->code] : null, 'srcBin' => $m->srcBin ? ['code' => $m->srcBin->code] : null,
        ];
    }

    /**
     * Voided receipts stay in the log (with `voidedAt`), `voided=0` hides them; their lines never count in a total.
     *
     * @param  array{warehouse?:?string, supplier?:?string, po?:?string, from?:?string, to?:?string, voided?:?string}  $filters
     */
    public function listGrns(Paging $page, array $filters): array
    {
        $query = GoodsReceipt::with(['po:id,number', 'supplier:id,code,name_ar,name_en', 'warehouse:id,code', 'lines:id,grn_id,accepted_qty,damaged_qty,rejected_qty,remaining_qty']);
        if (isset($filters['voided']) && $filters['voided'] !== '') {
            in_array($filters['voided'], ['1', 'true'], true) ? $query->whereNotNull('voided_at') : $query->whereNull('voided_at');
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

        return $page->paginate($query->orderByDesc('posted_at')->orderByDesc('id'), fn (GoodsReceipt $g) => $g->toArray() + ['voided' => $g->voided_at !== null]);
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
     * `$qty` (1 … what is left of the task) puts only part of the task into the scanned bin — a pallet that does not fit
     * one location is split over several; `done_qty` accumulates and the task stays open until nothing is left. Without
     * `$qty` the whole remainder moves, as before. The shipment is done when no putaway quantity is left on it.
     *
     * @return array{task:string, bin:string, movement:string, qty:int, doneQty:int, remaining:int, taskDone:bool, shipmentDone:?bool}
     */
    public function confirmPutaway(AuthUser $user, string $idOrNumber, ?string $scannedBin = null, ?string $scannedProduct = null, ?int $qty = null): array
    {
        $t = PutawayTask::with(['product.barcodes', 'suggestedBin', 'grn'])->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('PUTAWAY_NOT_FOUND', 'مهمة التخزين غير موجودة');
        $this->assertTaskOpen($t);
        $this->assertPutawayQty($t, $qty);
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

        return DB::transaction(function () use ($user, $t, $bin, $txId, $qty) {
            $locked = PutawayTask::whereKey($t->id)->lockForUpdate()->first();
            $this->assertTaskOpen($locked);
            $left = $this->assertPutawayQty($locked, $qty);
            $move = $qty ?? $left;
            $stgIn = $this->inventory->specialBin($t->warehouse_id, 'STG-IN');
            $mv = $this->inventory->post($user, $txId, ['type' => 'putaway', 'productId' => $t->product_id, 'batchId' => $t->batch_id, 'qty' => $move,
                'from' => ['warehouseId' => $t->warehouse_id, 'binId' => $stgIn->id], 'to' => ['warehouseId' => $t->warehouse_id, 'binId' => $bin->id],
                'reference' => ['type' => 'PutawayTask', 'id' => $t->id, 'number' => $t->number], 'note' => "{$t->grn->number} · Putaway → {$bin->code}"]);
            $done = $locked->done_qty + $move;
            $finished = $done >= $locked->qty;
            $locked->update(['status' => $finished ? 'done' : 'open', 'done_qty' => $done, 'actual_bin_id' => $bin->id, 'confirmed_by_id' => $user->id, 'confirmed_by' => $user->username, 'confirmed_at' => now()]);

            // The task's own staging entry (by reference). Receipts posted before entries were linked to their task carry the
            // GRN as reference: ONE such entry of the same quantity is taken, by id — never every entry that happens to match.
            $entry = StagingEntry::where('reference_type', 'PutawayTask')->where('reference_id', $t->id)->where('status', 'waiting')->lockForUpdate()->first();
            $legacy = $entry ? null : StagingEntry::where('reference_type', 'GoodsReceipt')->where('reference_id', $t->grn_id)->where('status', 'waiting')->where('qty', $locked->qty)
                ->orderBy('created_at')->orderBy('id')->lockForUpdate()->first();
            if ($finished) {
                ($entry ?? $legacy)?->update(['status' => 'cleared', 'cleared_at' => now(), 'qty' => $locked->qty]);
            } else {
                $entry?->update(['qty' => $locked->qty - $done]); // what still waits in staging for this task
            }
            $this->audit->log($user, ['action' => 'PUTAWAY.CONFIRM', 'entityType' => 'PutawayTask', 'entityId' => $t->id, 'entityNumber' => $t->number,
                'field' => 'bin', 'oldValue' => 'STG-IN', 'newValue' => ['bin' => $bin->code, 'qty' => $move, 'doneQty' => $done, 'of' => $locked->qty], 'transactionId' => $txId]);
            $openTasks = $t->shipment_id ? PutawayTask::where('shipment_id', $t->shipment_id)->where('status', 'open')->count() : 0;
            $shipmentDone = false;
            if ($t->shipment_id && $openTasks === 0) {
                $sh = InboundShipment::whereKey($t->shipment_id)->lockForUpdate()->firstOrFail();
                if ($sh->status === 'putaway') {
                    $sh->update(['status' => 'done', 'completed_at' => now()]);
                    $this->audit->status($user, 'InboundShipment', $sh->id, $sh->number, 'putaway', 'done', null, $txId);
                }
                $shipmentDone = true;
            }
            $partAr = $finished ? '' : ' — جزء من المهمة، المتبقي '.($locked->qty - $done);
            $this->notify->activity($user, 'PutawayTask', $t->id, $t->number, "تأكيد تخزين {$t->product->name_ar} × {$move} في {$bin->code} ({$t->grn->number}){$partAr}", "Putaway {$move} × {$t->product->sku} → {$bin->code}".($finished ? '' : ' — '.($locked->qty - $done).' left'));

            WaitingOrders::stockArrived(); // the goods are on a shelf now: orders waiting for them are tried once this commits

            return ['task' => $t->number, 'bin' => $bin->code, 'movement' => $mv->number, 'qty' => $move, 'doneQty' => $done, 'remaining' => $locked->qty - $done,
                'taskDone' => $finished, 'shipmentDone' => $t->shipment_id ? $shipmentDone : null];
        });
    }

    private function assertTaskOpen(PutawayTask $t): void
    {
        if ($t->status === 'cancelled') {
            throw AppError::conflict('PUTAWAY_CANCELLED', "أُلغيت مهمة التخزين {$t->number} مع إلغاء إذن الاستلام — لا تُنفَّذ", "Putaway task {$t->number} was cancelled with its voided receipt");
        }
        if ($t->status !== 'open') {
            throw AppError::conflict('PUTAWAY_DONE', 'المهمة منفَّذة مسبقًا — لا تكرار', 'Putaway task already confirmed');
        }
    }

    /** Validates the optional partial quantity; returns what is left of the task. */
    private function assertPutawayQty(PutawayTask $t, ?int $qty): int
    {
        $left = max(0, (int) $t->qty - (int) $t->done_qty);
        if ($qty !== null && ($qty < 1 || $qty > $left)) {
            throw AppError::validation('PUTAWAY_QTY', "الكمية يجب أن تكون بين 1 و{$left} — المتبقي من مهمة التخزين {$t->number}", "Quantity must be between 1 and {$left} (what is left of {$t->number})", ['remaining' => $left, 'entered' => $qty]);
        }

        return $left;
    }

    // ───────────── internals ─────────────

    private function shipmentWith(): array
    {
        return [
            'po:id,number,status,due_date,open_qty', 'supplier:id,code,name_ar,name_en', 'warehouse:id,code,name_ar,name_en',
            'lines' => fn ($q) => $q->orderBy('line_no'),
            'lines.product:id,sku,name_ar,name_en,storage_class,tracks_expiry', 'lines.product.barcodes' => fn ($q) => $q->where('is_primary', true),
            'lines.suggestedBin:id,code,zone_id', 'lines.suggestedBin.zone:id,code,type', 'lines.poLine:id,qty,received_qty,damaged_qty,rejected_qty',
            'grns' => fn ($q) => $q->select(['id', 'shipment_id', 'number', 'posted_at', 'posted_by', 'voided_at'])->orderBy('posted_at')->orderBy('id'),
        ];
    }

    /** Adds per-line previouslyReceived / openQty, the shipment totals and `grn` = the receipt in force (a voided one is history). */
    private function decorate(InboundShipment $s): array
    {
        $out = $s->toArray();
        $out['grn'] = $s->grns->whereNull('voided_at')->last()?->number;
        $out['lines'] = array_map(function (array $l) {
            $prev = $l['acceptedQty'] + $l['damagedQty'] + $l['rejectedQty'];

            return $l + ['previouslyReceived' => $prev, 'openQty' => max(0, $l['orderedQty'] - $prev)];
        }, $out['lines']);
        $sum = fn (string $key) => array_sum(array_column($out['lines'], $key));
        $out['totals'] = ['ordered' => $sum('orderedQty'), 'accepted' => $sum('acceptedQty'), 'damaged' => $sum('damagedQty'), 'rejected' => $sum('rejectedQty'), 'open' => $sum('openQty')];

        return $out;
    }

    /**
     * One guarded status change. `$onlyFrom` narrows the state machine for an action that is allowed from fewer states
     * than the table (cancel = expected only; the table's arrived / inspecting → cancelled belongs to `refuse`).
     *
     * @param  string[]|null  $onlyFrom
     */
    private function transition(AuthUser $user, string $idOrNumber, string $to, array $extra = [], ?array $onlyFrom = null, ?string $ar = null, ?string $en = null, ?string $note = null): InboundShipment
    {
        return DB::transaction(function () use ($user, $idOrNumber, $to, $extra, $onlyFrom, $ar, $en, $note) {
            $s = InboundShipment::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
                ?? throw AppError::notFound('SHIPMENT_NOT_FOUND', 'الشحنة غير موجودة');
            if ($s->locked) {
                throw AppError::rule('SHIPMENT_LOCKED', 'الشحنة مقفلة — بانتظار تأكيد المورد', 'Shipment locked');
            }
            if (! Sm::can('SHIPMENT_TRANSITIONS', $s->status, $to) || ($onlyFrom !== null && ! in_array($s->status, $onlyFrom, true))) {
                throw AppError::rule('SHIPMENT_TRANSITION', ($ar ? "{$ar} — " : '')."انتقال غير مسموح: {$s->status} ← {$to}", ($en ? "{$en} — " : '')."Invalid transition {$s->status} → {$to}");
            }
            $from = $s->status;
            $s->update(['status' => $to] + $extra);
            $this->audit->status($user, 'InboundShipment', $s->id, $s->number, $from, $to, $note);

            return $s->refresh();
        });
    }
}
