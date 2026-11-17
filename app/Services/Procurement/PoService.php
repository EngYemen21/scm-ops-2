<?php

namespace App\Services\Procurement;

use App\Models\GrnLine;
use App\Models\InboundShipment;
use App\Models\PoApproval;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\ShipmentLine;
use App\Models\SupplierInvoiceLine;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Services\Procurement\ProcurementHelpers as H;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sm;
use Illuminate\Support\Facades\DB;

/**
 * Purchase orders: creation with supplier-score gate, approval chain from `procurement.approvalTiers`,
 * role-checked approval steps, send → expected inbound shipment (idempotent per PO), confirm, cancel.
 * Every status change goes through PO_TRANSITIONS + audit.status.
 */
class PoService
{
    private const PRODUCT_SEL = 'id,sku,name_ar,name_en,storage_class,tracks_expiry';

    private const AFTER_SEND = ['sent', 'confirmed', 'partial', 'received', 'closed'];

    /** Statuses in which goods are still expected — a due date in the past makes the PO late. */
    public const OPEN_FOR_DELIVERY = ['sent', 'confirmed', 'partial'];

    public function __construct(
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Approval chain for a PO total — first tier whose `max` is null or ≥ total (configurable, defaults = prototype).
     *
     * @return array<int, array{step:int, roleKey:string, labelAr:string, labelEn:string}>
     */
    public function chainFor(float $total): array
    {
        $tiers = (array) ($this->settings->get('procurement.approvalTiers') ?: []);
        $tier = null;
        foreach ($tiers as $t) {
            if (! isset($t['max']) || $total <= (float) $t['max']) {
                $tier = $t;
                break;
            }
        }
        $tier ??= $tiers ? $tiers[count($tiers) - 1] : ['max' => null, 'roles' => ['proc']];
        $labels = (array) config('scm.ROLE_LABELS', []);
        $chain = [];
        foreach (array_values((array) $tier['roles']) as $i => $roleKey) {
            $chain[] = ['step' => $i + 1, 'roleKey' => $roleKey, 'labelAr' => $labels[$roleKey]['ar'] ?? $roleKey, 'labelEn' => $labels[$roleKey]['en'] ?? $roleKey];
        }

        return $chain;
    }

    /**
     * Creates a PO (pending approval, or draft). Joins the caller's transaction when there is one.
     *
     * @param  array{supplierCode:string, warehouseCode:string, dueDate:string, paymentTerms?:?string, reference?:?string, notes?:?string,
     *               lines:array<int, array{sku:string, qty:int|string, price:float|int|string}>, overrideSupplierScore?:?bool, overrideReason?:?string}  $dto
     * @param  array{rfqId?:?string, source?:string, draft?:bool}  $opts
     */
    public function create(AuthUser $user, array $dto, array $opts = []): PurchaseOrder
    {
        return DB::transaction(function () use ($user, $dto, $opts) {
            $supplier = H::findSupplier($dto['supplierCode']);
            $wh = H::findWarehouse($dto['warehouseCode']);
            $products = H::findProducts(array_map(fn ($l) => $l['sku'], $dto['lines']));
            $lines = [];
            $raised = []; // lines priced above the requisition estimate beyond the tolerance
            $tolerance = (float) ($this->settings->get('procurement.prPriceTolerancePct') ?? 10);
            foreach (array_values($dto['lines']) as $i => $l) {
                $price = H::round2((float) $l['price']);
                // `prEstPrice` only ever comes from PrService::toPo (the controllers' validation never lets it through)
                $est = isset($l['prEstPrice']) && (float) $l['prEstPrice'] > 0 ? round((float) $l['prEstPrice'], 4) : null;
                $lines[] = ['line_no' => $i + 1, 'product_id' => $products[$l['sku']]->id, 'qty' => (int) $l['qty'], 'price' => $price, 'pr_est_price' => $est];
                if ($est !== null && $price > $est * (1 + $tolerance / 100) + 1e-9) {
                    $raised[] = ['lineNo' => $i + 1, 'sku' => $products[$l['sku']]->sku, 'product' => $products[$l['sku']]->name_ar, 'estimate' => $est, 'price' => $price, 'pct' => round(($price - $est) / $est * 100, 1)];
                }
            }
            $total = H::round2(array_sum(array_map(fn ($l) => $l['qty'] * $l['price'], $lines)));
            if ($total <= 0) {
                throw AppError::rule('PO_EMPTY', 'قيمة أمر الشراء يجب أن تكون أكبر من صفر', 'PO total must be positive');
            }
            // Price control from requisition to order: the requisition was approved at an estimate; ordering well above it
            // needs a written reason, is flagged on the order and is seen by finance whatever the value tier says.
            $priceReason = trim((string) ($dto['priceReason'] ?? ''));
            if ($raised && mb_strlen($priceReason) < 5) {
                $list = implode('، ', array_map(fn ($r) => "{$r['product']} +{$r['pct']}%", $raised));
                throw AppError::rule('PR_PRICE_REASON', 'سعر أمر الشراء يتجاوز تقدير طلب الشراء المعتمد بأكثر من '.self::num($tolerance)."% ({$list}) — اكتب سبب تغيّر السعر (5 أحرف على الأقل)؛ يُعرض على المعتمدين ويُضاف اعتماد المالية",
                    'The order price exceeds the approved requisition estimate by more than '.self::num($tolerance).'% — a written reason is required; finance approval is added', ['tolerancePct' => $tolerance, 'lines' => $raised]);
            }
            // order-level charges: total (net goods) − discount + shipping, then VAT → gross. Approval tiers stay on the net goods value.
            $discount = H::round2((float) ($dto['discount'] ?? 0));
            $shipping = H::round2((float) ($dto['shippingCost'] ?? 0));
            $vatPct = isset($dto['vatPct']) && $dto['vatPct'] !== null && $dto['vatPct'] !== '' ? (float) $dto['vatPct'] : (float) ($this->settings->get('sales.vatPct') ?? 15);
            if ($discount < 0 || $shipping < 0 || $discount > $total) {
                throw AppError::validation('PO_CHARGES', 'الخصم والشحن لا يكونان سالبين والخصم لا يتجاوز قيمة الأصناف', 'Discount and shipping must be non-negative and the discount may not exceed the goods value');
            }
            $gross = H::round2(($total - $discount + $shipping) * (1 + $vatPct / 100));

            // Supplier qualification gate: an expired mandatory document blocks new POs unless a po.approve holder documents the exception.
            $expired = $supplier->documents()->where('mandatory', true)->whereNotNull('expires_at')->where('expires_at', '<', H::dayStart())->get();
            $docsOverride = null;
            if ($expired->isNotEmpty()) {
                $names = $expired->map(fn ($d) => $d->type.($d->expires_at ? ' ('.$d->expires_at->toDateString().')' : ''))->implode('، ');
                if (empty($dto['overrideDocuments'])) {
                    throw AppError::rule('SUPPLIER_DOCS_EXPIRED', "المورد {$supplier->name_ar} له مستند إلزامي منتهٍ: {$names} — لا يصدر أمر شراء قبل التجديد إلا باستثناء موثّق", "Supplier {$supplier->name_en} has an expired mandatory document ({$names})", ['documents' => $expired->map->toArray()->all()]);
                }
                if (! $user->can('po.approve') || mb_strlen(trim((string) ($dto['documentsReason'] ?? ''))) < 5) {
                    throw AppError::forbidden('SUPPLIER_DOCS_OVERRIDE_DENIED', 'استثناء المستندات المنتهية يتطلب صلاحية اعتماد أوامر الشراء وسببًا مكتوبًا', 'Overriding expired documents requires po.approve and a written reason');
                }
                $docsOverride = ['documents' => $names, 'reason' => trim((string) $dto['documentsReason'])];
            }

            // Supplier score gate: below the minimum → reject unless an explicit override by a user who can approve POs.
            $minScore = (float) ($this->settings->get('procurement.minSupplierScore') ?? 65);
            $score = self::num($supplier->score);
            $min = self::num($minScore);
            $override = null;
            if ($supplier->score < $minScore) {
                if (empty($dto['overrideSupplierScore'])) {
                    throw AppError::rule('SUPPLIER_SCORE_LOW', "المورد {$supplier->name_ar} تقييمه {$score} — دون الحد الأدنى ({$min}) ويحتاج استثناء من مدير المشتريات", "Supplier {$supplier->name_en} score {$score} is below the minimum ({$min}) — procurement-manager override required", ['score' => $supplier->score, 'minScore' => $minScore]);
                }
                if (! $user->can('po.approve')) {
                    throw AppError::forbidden('SUPPLIER_SCORE_OVERRIDE_DENIED', 'استثناء تقييم المورد يتطلب صلاحية اعتماد أوامر الشراء (مدير المشتريات)', 'Overriding the supplier score requires the po.approve permission');
                }
                $override = ['reason' => (string) ($dto['overrideReason'] ?? '')];
            }

            $chain = $this->chainFor($total);
            if ($raised && ! in_array('finance', array_column($chain, 'roleKey'), true)) {
                $labels = (array) config('scm.ROLE_LABELS', []);
                $chain[] = ['step' => count($chain) + 1, 'roleKey' => 'finance', 'labelAr' => $labels['finance']['ar'] ?? 'finance', 'labelEn' => $labels['finance']['en'] ?? 'finance'];
            }
            $number = $this->numbering->next('PO');
            $status = ! empty($opts['draft']) ? 'draft' : 'pending';
            $po = PurchaseOrder::create([
                'price_changed' => (bool) $raised, 'price_note' => $raised ? $priceReason : null,
                'number' => $number, 'supplier_id' => $supplier->id, 'warehouse_id' => $wh->id, 'rfq_id' => ($opts['rfqId'] ?? null) ?: null, 'status' => $status, 'total' => $total,
                'due_date' => H::parseDate($dto['dueDate']), 'payment_terms' => ($dto['paymentTerms'] ?? null) ?: ($supplier->terms ?: null),
                'reference' => ($dto['reference'] ?? null) ?: null, 'notes' => ($dto['notes'] ?? null) ?: null, 'approval_step' => 0,
                'open_qty' => array_sum(array_column($lines, 'qty')), 'created_by_id' => $user->id, 'created_by' => $user->username,
                'discount' => $discount, 'shipping_cost' => $shipping, 'vat_pct' => $vatPct, 'gross_total' => $gross,
            ]);
            foreach ($lines as $l) {
                PoLine::create($l + ['po_id' => $po->id]);
            }
            foreach ($chain as $c) {
                PoApproval::create(['po_id' => $po->id, 'step' => $c['step'], 'role_key' => $c['roleKey'], 'label_ar' => $c['labelAr'], 'label_en' => $c['labelEn']]);
            }
            $source = $opts['source'] ?? 'manual';
            $newValue = ['supplier' => $supplier->code, 'warehouse' => $wh->code, 'total' => $total, 'discount' => $discount, 'shipping' => $shipping, 'vatPct' => $vatPct, 'gross' => $gross, 'lines' => count($lines), 'chain' => array_column($chain, 'roleKey'), 'source' => $source];
            if (! empty($opts['rfqId'])) {
                $newValue['rfqId'] = $opts['rfqId'];
            }
            // converted from a requisition: the estimate each price was compared with stays on the audit trail
            $estimates = array_values(array_filter(array_map(fn ($l) => $l['pr_est_price'] !== null ? ['lineNo' => $l['line_no'], 'estimate' => $l['pr_est_price'], 'price' => $l['price']] : null, $lines)));
            if ($estimates) {
                $newValue['prPrices'] = $estimates;
            }
            $this->audit->log($user, ['action' => 'PO.CREATE', 'entityType' => 'PurchaseOrder', 'entityId' => $po->id, 'entityNumber' => $number, 'newValue' => $newValue]);
            if ($raised) {
                $this->audit->log($user, ['action' => 'PO.PRICE_ABOVE_ESTIMATE', 'entityType' => 'PurchaseOrder', 'entityId' => $po->id, 'entityNumber' => $number, 'field' => 'price',
                    'oldValue' => ['tolerancePct' => $tolerance, 'reference' => ($dto['reference'] ?? null) ?: null], 'newValue' => ['reason' => $priceReason, 'lines' => $raised, 'by' => $user->username]]);
            }
            if ($override) {
                $this->audit->log($user, ['action' => 'PO.SUPPLIER_SCORE_OVERRIDE', 'entityType' => 'PurchaseOrder', 'entityId' => $po->id, 'entityNumber' => $number, 'field' => 'supplierScore',
                    'oldValue' => "{$score} < {$min}", 'newValue' => ['supplier' => $supplier->code, 'reason' => $override['reason'], 'by' => $user->username]]);
            }
            if ($docsOverride) {
                $this->audit->log($user, ['action' => 'PO.SUPPLIER_DOCS_OVERRIDE', 'entityType' => 'PurchaseOrder', 'entityId' => $po->id, 'entityNumber' => $number, 'field' => 'supplierDocuments',
                    'oldValue' => $docsOverride['documents'], 'newValue' => ['supplier' => $supplier->code, 'reason' => $docsOverride['reason'], 'by' => $user->username]]);
            }
            $this->audit->status($user, 'PurchaseOrder', $po->id, $number, null, $status);
            $steps = count($chain);
            $totalText = self::num($total);
            $this->notify->activity($user, 'PurchaseOrder', $po->id, $number,
                "أُنشئ {$number} لـ {$supplier->name_ar} بقيمة {$totalText} ر.س — سلسلة اعتماد {$steps} خطوة".($steps > 1 ? 'ات' : '').($status === 'draft' ? ' (مسودة)' : ''),
                "{$number} created for {$supplier->name_en} — {$totalText} SAR, {$steps}-step approval", $status === 'pending' ? [$chain[0]['roleKey']] : []);
            if ($raised) {
                $worst = max(array_column($raised, 'pct'));
                $this->notify->activity($user, 'PurchaseOrder', $po->id, $number, "{$number}: سعر أعلى من تقدير طلب الشراء (حتى +{$worst}%) — السبب: {$priceReason}. أُضيف اعتماد المالية",
                    "{$number}: priced above the requisition estimate (up to +{$worst}%) — {$priceReason}. Finance approval added", ['finance']);
            }

            return $this->load($po->id);
        });
    }

    /** draft → pending (starts the approval chain). */
    public function submit(AuthUser $user, string $id): PurchaseOrder
    {
        return $this->locked($id, function (PurchaseOrder $po) use ($user) {
            $this->transition($po, 'pending');
            $from = $po->status;
            $po->update(['status' => 'pending', 'approval_step' => 0]);
            $this->audit->status($user, 'PurchaseOrder', $po->id, $po->number, $from, 'pending');
            $first = $po->approvals->first();
            $this->notify->activity($user, 'PurchaseOrder', $po->id, $po->number, "{$po->number} بانتظار الاعتماد — الخطوة الأولى: ".($first?->label_ar ?: 'مدير المشتريات'), "{$po->number} pending approval", [$first?->role_key ?: 'proc']);

            return $this->load($po->id);
        });
    }

    /** Approves the current step; the approver must hold the step's role (super passes). Last step → status approved. */
    public function approve(AuthUser $user, string $id, ?string $note = null): PurchaseOrder
    {
        return $this->locked($id, function (PurchaseOrder $po) use ($user, $note) {
            if ($po->status !== 'pending') {
                throw AppError::rule('PO_NOT_PENDING', "أمر الشراء {$po->number} ليس بانتظار الاعتماد (الحالة: {$po->status})", "PO {$po->number} is not pending approval");
            }
            $step = $po->approvals->first(fn ($a) => $a->decision === 'pending');
            if (! $step) {
                throw AppError::rule('PO_NO_STEP', 'لا توجد خطوة اعتماد معلّقة', 'No pending approval step');
            }
            if (! H::hasRole($user, $step->role_key)) {
                $roles = implode('، ', $user->roles);
                throw AppError::rule('PO_APPROVAL_ROLE', "الخطوة {$step->step} من {$po->number} تتطلب دور «{$step->label_ar}» — دورك ({$roles}) غير مخوّل لهذه الخطوة", "Step {$step->step} of {$po->number} requires role {$step->role_key}", ['step' => $step->step, 'requiredRole' => $step->role_key]);
            }
            // Segregation of duties: whoever created the PO does not approve it (policy switch: procurement.allowSelfApproval)
            if ($po->created_by_id === $user->id && ! (bool) $this->settings->get('procurement.allowSelfApproval')) {
                throw AppError::rule('PO_SELF_APPROVAL', "لا يعتمد منشئ أمر الشراء {$po->number} أمره — فصل المسؤوليات؛ يعتمده شخص آخر بدور «{$step->label_ar}»", "The creator of {$po->number} cannot approve it (segregation of duties)");
            }
            $step->update(['decision' => 'approved', 'approver_id' => $user->id, 'approver' => $user->username, 'note' => $note ?: null, 'decided_at' => now()]);
            $this->audit->log($user, ['action' => 'PO.APPROVE', 'entityType' => 'PurchaseOrder', 'entityId' => $po->id, 'entityNumber' => $po->number, 'field' => "step{$step->step}", 'oldValue' => 'pending',
                'newValue' => ['decision' => 'approved', 'role' => $step->role_key] + ($note !== null ? ['note' => $note] : [])]);
            $next = $po->approvals->first(fn ($a) => $a->decision === 'pending' && $a->id !== $step->id);
            if ($next) {
                $po->update(['approval_step' => $next->step - 1]);
                $this->notify->activity($user, 'PurchaseOrder', $po->id, $po->number, "اعتُمدت الخطوة {$step->step} من {$po->number} — التالي: {$next->label_ar}", "Step {$step->step} of {$po->number} approved — next: {$next->label_en}", [$next->role_key]);

                return $this->load($po->id);
            }
            $po->update(['status' => 'approved', 'approval_step' => 99, 'approved_at' => now()]);
            $this->audit->status($user, 'PurchaseOrder', $po->id, $po->number, 'pending', 'approved', $note);
            $this->notify->activity($user, 'PurchaseOrder', $po->id, $po->number, "اعتُمد {$po->number} بالكامل — جاهز للإرسال إلى {$po->supplier->name_ar}", "{$po->number} fully approved — ready to send to {$po->supplier->name_en}", ['proc']);

            return $this->load($po->id);
        });
    }

    /** Rejects at the current step (approver must hold the role); PO → cancelled with the reason. */
    public function reject(AuthUser $user, string $id, string $reason): PurchaseOrder
    {
        return $this->locked($id, function (PurchaseOrder $po) use ($user, $reason) {
            if ($po->status !== 'pending') {
                throw AppError::rule('PO_NOT_PENDING', "أمر الشراء {$po->number} ليس بانتظار الاعتماد (الحالة: {$po->status})", "PO {$po->number} is not pending approval");
            }
            $step = $po->approvals->first(fn ($a) => $a->decision === 'pending');
            if ($step && ! H::hasRole($user, $step->role_key)) {
                throw AppError::rule('PO_APPROVAL_ROLE', "الخطوة {$step->step} من {$po->number} تتطلب دور «{$step->label_ar}»", "Step {$step->step} requires role {$step->role_key}");
            }
            $this->transition($po, 'cancelled');
            $step?->update(['decision' => 'rejected', 'approver_id' => $user->id, 'approver' => $user->username, 'note' => $reason, 'decided_at' => now()]);
            $po->update(['status' => 'cancelled', 'closed_at' => now()]);
            $this->audit->log($user, ['action' => 'PO.REJECT', 'entityType' => 'PurchaseOrder', 'entityId' => $po->id, 'entityNumber' => $po->number, 'field' => $step ? "step{$step->step}" : null,
                'newValue' => ['reason' => $reason] + ($step ? ['role' => $step->role_key] : [])]);
            $this->audit->status($user, 'PurchaseOrder', $po->id, $po->number, 'pending', 'cancelled', $reason);
            $this->notify->activity($user, 'PurchaseOrder', $po->id, $po->number, "رُفض {$po->number} — {$reason}", "{$po->number} rejected — {$reason}", ['proc']);

            return $this->load($po->id);
        });
    }

    /**
     * Sends an approved PO to the supplier: status → sent + creates the expected inbound shipment from the PO lines.
     * Idempotent per PO: a second call (even concurrent — the PO row is locked) returns the existing shipment.
     *
     * @return array{po: PurchaseOrder, shipment: InboundShipment, created: bool}
     */
    public function send(AuthUser $user, string $id): array
    {
        return $this->locked($id, function (PurchaseOrder $po) use ($user) {
            $existing = InboundShipment::where('po_id', $po->id)->where('status', '!=', 'cancelled')->orderBy('created_at')->orderBy('id')->first();
            if ($existing && in_array($po->status, self::AFTER_SEND, true)) {
                return ['po' => $po, 'shipment' => $this->loadShipment($existing->id), 'created' => false];
            }
            $this->transition($po, 'sent', "لا يمكن إرسال {$po->number} وهو بحالة «{$po->status}» — يجب أن يكون معتمدًا بالكامل");
            $shipment = $existing;
            if (! $shipment) {
                $number = $this->numbering->next('SHP');
                $shipment = InboundShipment::create(['number' => $number, 'po_id' => $po->id, 'supplier_id' => $po->supplier_id, 'warehouse_id' => $po->warehouse_id, 'eta' => $po->due_date, 'status' => 'expected']);
                foreach ($po->lines as $l) {
                    ShipmentLine::create(['shipment_id' => $shipment->id, 'line_no' => $l->line_no, 'po_line_id' => $l->id, 'product_id' => $l->product_id, 'ordered_qty' => $l->qty,
                        'suggestion_ar' => 'يُحدَّد الموقع المقترح عند الاستلام وفق شروط التخزين', 'suggestion_en' => 'Suggested bin is assigned at receiving per storage rules']);
                }
                $this->audit->log($user, ['action' => 'SHIPMENT.EXPECTED', 'entityType' => 'InboundShipment', 'entityId' => $shipment->id, 'entityNumber' => $number,
                    'newValue' => ['po' => $po->number, 'supplier' => $po->supplier->code, 'lines' => $po->lines->count(), 'eta' => $po->due_date?->toJSON()]]);
                $this->audit->status($user, 'InboundShipment', $shipment->id, $number, null, 'expected');
            }
            $from = $po->status;
            $po->update(['status' => 'sent', 'sent_at' => now()]);
            $this->audit->status($user, 'PurchaseOrder', $po->id, $po->number, $from, 'sent');
            $this->notify->activity($user, 'PurchaseOrder', $po->id, $po->number, "أُرسل {$po->number} إلى {$po->supplier->name_ar} — شحنة متوقعة {$shipment->number} في {$po->warehouse->name_ar}", "{$po->number} sent to {$po->supplier->name_en} — expected shipment {$shipment->number}", ['wm']);
            $this->notify->event('PO_SENT', ['po' => $po->number, 'supplier' => $po->supplier->code, 'supplierEmail' => $po->supplier->email, 'total' => (float) $po->total, 'dueDate' => $po->due_date?->toJSON(), 'shipment' => $shipment->number]);

            return ['po' => $this->load($po->id), 'shipment' => $this->loadShipment($shipment->id), 'created' => ! $existing];
        });
    }

    /**
     * Supplier confirmed the order (sent → confirmed).
     *
     * @param  array{supplierRef?:?string, note?:?string}  $dto
     */
    public function confirm(AuthUser $user, string $id, array $dto = []): PurchaseOrder
    {
        return $this->locked($id, function (PurchaseOrder $po) use ($user, $dto) {
            $this->transition($po, 'confirmed', "لا يمكن تأكيد {$po->number} وهو بحالة «{$po->status}» — يجب إرساله للمورد أولًا");
            $ref = ($dto['supplierRef'] ?? null) ?: null;
            $notes = implode("\n", array_filter([$po->notes, $ref ? "تأكيد المورد: {$ref}" : null, ($dto['note'] ?? null) ?: null])) ?: null;
            $from = $po->status;
            $po->update(['status' => 'confirmed', 'confirmed_at' => now(), 'notes' => $notes]);
            $this->audit->status($user, 'PurchaseOrder', $po->id, $po->number, $from, 'confirmed', $ref);
            $this->notify->activity($user, 'PurchaseOrder', $po->id, $po->number, "أكّد {$po->supplier->name_ar} أمر الشراء {$po->number}".($ref ? ' — مرجع '.$ref : ''), "{$po->supplier->name_en} confirmed {$po->number}", ['wm']);

            return $this->load($po->id);
        });
    }

    /** Delivery follow-up on an open PO: last contact with the supplier / delay reason, visible on the follow-up board. */
    public function followUp(AuthUser $user, string $id, string $note): PurchaseOrder
    {
        return $this->locked($id, function (PurchaseOrder $po) use ($user, $note) {
            if (! in_array($po->status, [...self::OPEN_FOR_DELIVERY, 'approved'], true)) {
                throw AppError::rule('PO_NOT_OPEN', "أمر الشراء {$po->number} ليس قيد التوريد (الحالة: {$po->status})", "PO {$po->number} is not awaiting delivery");
            }
            $po->update(['follow_up_note' => $note, 'followed_up_at' => now(), 'followed_up_by' => $user->username]);
            $this->audit->log($user, ['action' => 'PO.FOLLOW_UP', 'entityType' => 'PurchaseOrder', 'entityId' => $po->id, 'entityNumber' => $po->number, 'newValue' => ['note' => $note]]);
            $this->notify->activity($user, 'PurchaseOrder', $po->id, $po->number, "متابعة توريد {$po->number}: {$note}", "Follow-up on {$po->number}: {$note}");

            return $this->load($po->id);
        });
    }

    /** Late = still awaiting goods after the required delivery date. */
    public static function isLate(PurchaseOrder $po): bool
    {
        return in_array($po->status, self::OPEN_FOR_DELIVERY, true) && $po->due_date !== null && $po->due_date->lt(H::dayStart());
    }

    /**
     * Share of the ordered quantity that physically came in (accepted + damaged). Rejected goods never entered, so they
     * are never progress — whatever `procurement.rejectedStaysOpen` says. Needs `lines` loaded with their counters.
     */
    public static function receivedPct(PurchaseOrder $po): int
    {
        $ordered = (int) $po->lines->sum('qty');
        $in = (int) $po->lines->sum(fn ($l) => min((int) $l->qty, (int) $l->received_qty + (int) $l->damaged_qty));

        return $ordered > 0 ? (int) round($in / $ordered * 100) : 0;
    }

    /**
     * The four figures of a purchase order, never one percentage: accepted into stock, damaged (in DMG-01, settled by a
     * purchase return), rejected (never entered) and what the supplier still owes. `waived` is what was written off when
     * the order was closed short. Needs `lines` loaded with their counters.
     *
     * @return array{ordered:int, received:int, damaged:int, rejected:int, open:int, waived:int}
     */
    public static function receipt(PurchaseOrder $po): array
    {
        $staysOpen = H::rejectedStaysOpen();
        $owed = (int) $po->lines->sum(fn ($l) => H::openQty($l, $staysOpen));
        $over = in_array($po->status, ['closed', 'cancelled'], true);

        return ['ordered' => (int) $po->lines->sum('qty'), 'received' => (int) $po->lines->sum('received_qty'), 'damaged' => (int) $po->lines->sum('damaged_qty'),
            'rejected' => (int) $po->lines->sum('rejected_qty'), 'open' => $over ? 0 : $owed, 'waived' => $po->status === 'closed' ? $owed : 0];
    }

    /**
     * Close an order short: the supplier will not deliver what is still open (out of stock, rejected goods not replaced).
     * `partial → closed` — the transition the state machine always had and nothing implemented. What is open is waived:
     * no follow-up shipment, no "incoming" in replenishment, the commitment leaves the open-orders value. A shipment that
     * never arrived is cancelled with the order; a truck at the gate has to be received or refused first.
     */
    public function close(AuthUser $user, string $id, string $reason): PurchaseOrder
    {
        return $this->locked($id, function (PurchaseOrder $po) use ($user, $reason) {
            $this->transition($po, 'closed', $po->status === 'closed' ? "أمر الشراء {$po->number} مقفل مسبقًا"
                : "يُقفل أمر الشراء بعد استلام جزء منه (الحالة: {$po->status}) — قبل أي استلام يُلغى أو تُتابَع الشحنة المتوقعة");
            // A truck at the gate is not locked here on purpose (GRN posting locks shipment → order, the reverse order would
            // deadlock): the rare arrival registered during this very request is stopped by receiving itself, which refuses
            // a closed order (PO_NOT_RECEIVABLE) and leaves "refuse at the gate" as the way out.
            $busy = InboundShipment::where('po_id', $po->id)->whereIn('status', ['arrived', 'inspecting'])->orderBy('created_at')->value('number');
            if ($busy) {
                throw AppError::rule('PO_CLOSE_SHIPMENT_OPEN', "الشحنة {$busy} عند البوابة لهذا الأمر — استلمها أو ارفضها أولًا ثم أقفل الأمر", "Shipment {$busy} of this order is at the gate — receive or refuse it first", ['shipment' => $busy]);
            }
            $waiting = InboundShipment::where('po_id', $po->id)->where('status', 'expected')->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
            $figures = self::receipt($po);
            $waived = $po->lines->map(fn ($l) => ['lineNo' => $l->line_no, 'sku' => $l->product?->sku, 'qty' => H::openQty($l)])->filter(fn ($l) => $l['qty'] > 0)->values()->all();
            $waivedQty = array_sum(array_column($waived, 'qty'));
            $waivedValue = H::round2($po->lines->sum(fn ($l) => H::openQty($l) * (float) $l->price));
            foreach ($waiting as $s) {
                $s->update(['status' => 'cancelled']);
                $this->audit->status($user, 'InboundShipment', $s->id, $s->number, 'expected', 'cancelled', "إقفال {$po->number}: {$reason}");
            }
            $from = $po->status;
            $po->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $user->username, 'close_reason' => $reason, 'open_qty' => 0]);
            $this->audit->log($user, ['action' => 'PO.CLOSE', 'entityType' => 'PurchaseOrder', 'entityId' => $po->id, 'entityNumber' => $po->number, 'oldValue' => ['status' => $from, 'openQty' => $waivedQty] + $figures,
                'newValue' => ['status' => 'closed', 'reason' => $reason, 'waivedQty' => $waivedQty, 'waivedValue' => $waivedValue, 'lines' => $waived]]);
            $this->audit->status($user, 'PurchaseOrder', $po->id, $po->number, $from, 'closed', $reason);
            $valueText = self::num($waivedValue);
            $this->notify->activity($user, 'PurchaseOrder', $po->id, $po->number, "أُقفل {$po->number} على ما استُلم — أُسقطت {$waivedQty} وحدة لم تورَّد (قيمتها {$valueText} ر.س): {$reason}",
                "{$po->number} closed short — {$waivedQty} undelivered units waived ({$valueText} SAR): {$reason}", ['proc', 'finance']);

            return $this->load($po->id);
        });
    }

    /** Cancel is allowed only before the PO is sent to the supplier. */
    public function cancel(AuthUser $user, string $id, string $reason): PurchaseOrder
    {
        return $this->locked($id, function (PurchaseOrder $po) use ($user, $reason) {
            if (in_array($po->status, self::AFTER_SEND, true)) {
                throw AppError::rule('PO_CANCEL_AFTER_SEND', "لا يمكن إلغاء {$po->number} بعد إرساله إلى المورد (الحالة: {$po->status})", "PO {$po->number} cannot be cancelled after it was sent");
            }
            $this->transition($po, 'cancelled');
            PoApproval::where('po_id', $po->id)->where('decision', 'pending')->update(['decision' => 'cancelled', 'decided_at' => now(), 'note' => $reason]);
            $from = $po->status;
            $po->update(['status' => 'cancelled', 'closed_at' => now()]);
            $this->audit->status($user, 'PurchaseOrder', $po->id, $po->number, $from, 'cancelled', $reason);
            $this->notify->activity($user, 'PurchaseOrder', $po->id, $po->number, "أُلغي {$po->number} — {$reason}", "{$po->number} cancelled — {$reason}", ['proc']);

            return $this->load($po->id);
        });
    }

    /** @param  array{status?:?string, supplier?:?string, warehouse?:?string, step?:?string}  $filters */
    public function list(Paging $page, array $filters): array
    {
        $query = PurchaseOrder::query()->with([
            'supplier:id,code,name_ar,name_en,score', 'warehouse:id,code,name_ar,name_en',
            'approvals' => fn ($q) => $q->orderBy('step'), 'shipments:id,po_id,number,status,eta',
        ])->with('lines:id,po_id,qty,received_qty,damaged_qty,rejected_qty')->withCount(['lines', 'grns' => fn ($q) => $q->whereNull('voided_at')]);
        if (! empty($filters['status'])) {
            $query->whereIn('status', H::csv($filters['status']));
        }
        if (! empty($filters['supplier'])) {
            $query->whereHas('supplier', fn ($s) => $s->where('code', $filters['supplier']));
        }
        if (! empty($filters['warehouse'])) {
            $query->whereHas('warehouse', fn ($w) => $w->where('code', strtoupper($filters['warehouse'])));
        }
        if (! empty($filters['step'])) {
            $query->where('status', 'pending')->whereHas('approvals', fn ($a) => $a->where('role_key', $filters['step'])->where('decision', 'pending'));
        }
        if (! empty($filters['late'])) {
            $query->whereIn('status', self::OPEN_FOR_DELIVERY)->where('due_date', '<', H::dayStart());
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $query->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('reference', 'like', $like)
                ->orWhereHas('supplier', fn ($s) => $s->where(fn ($x) => $x->where('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('code', 'like', $like))));
        }
        $column = match ($page->sort) {
            'total' => 'total', 'dueDate' => 'due_date', default => 'created_at'
        };
        $query->orderBy($column, $page->order)->orderBy('id', $page->order);

        return $page->paginate($query, function (PurchaseOrder $po) {
            $po->supplier?->makeHidden('id');
            $po->warehouse?->makeHidden('id');
            $po->shipments->each->makeHidden(['id', 'po_id']);
            $row = $po->toArray();
            $row['_count'] = ['lines' => (int) $po->lines_count, 'grns' => (int) $po->grns_count];
            unset($row['linesCount'], $row['grnsCount']);
            $row['currentStep'] = $po->approvals->first(fn ($a) => $a->decision === 'pending')?->toArray();
            $row['late'] = self::isLate($po);
            $row['receivedPct'] = self::receivedPct($po);
            $row['receipt'] = self::receipt($po);
            unset($row['lines']);

            return $row;
        });
    }

    /** Full PO with traceability PO → shipments → GRN numbers. */
    public function get(string $id): array
    {
        $po = PurchaseOrder::where('id', $id)->orWhere('number', $id)->first()
            ?? throw AppError::notFound('PO_NOT_FOUND', "أمر الشراء {$id} غير موجود", "PO {$id} not found");
        $po = $this->load($po->id);
        $po->load([
            'rfq:id,number,status',
            'shipments' => fn ($q) => $q->orderBy('created_at')->orderBy('id'),
            'shipments.lines' => fn ($q) => $q->orderBy('line_no'), 'shipments.lines.product:'.self::PRODUCT_SEL,
            'shipments.grns:id,number,posted_at,shipment_id,voided_at',
            'grns' => fn ($q) => $q->select(['id', 'number', 'posted_at', 'posted_by', 'shipment_id', 'summary_ar', 'summary_en', 'po_id', 'voided_at', 'voided_by', 'void_reason'])->withCount('lines')->orderBy('posted_at')->orderBy('id'),
        ]);
        $po->shipments->each(fn ($s) => $s->grns->each->makeHidden('shipment_id'));
        $po->grns->each->makeHidden('po_id');
        $row = $po->toArray();
        foreach ($row['grns'] as $i => $g) {
            $row['grns'][$i]['_count'] = ['lines' => (int) ($g['linesCount'] ?? 0)];
            unset($row['grns'][$i]['linesCount']);
        }
        $prs = PurchaseRequisition::where('po_id', $po->id)->get(['id', 'number', 'status']);
        // per line: what the GRNs accepted and what supplier invoices already cover — the invoice form bills the difference
        $lineIds = $po->lines->pluck('id');
        $accepted = GrnLine::whereIn('po_line_id', $lineIds)->whereHas('grn', fn ($q) => $q->whereNull('voided_at')) // a voided receipt is history, not goods
            ->selectRaw('po_line_id, coalesce(sum(accepted_qty), 0) as qty')->groupBy('po_line_id')->pluck('qty', 'po_line_id');
        $invoiced = SupplierInvoiceLine::whereIn('po_line_id', $lineIds)->whereHas('invoice', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->selectRaw('po_line_id, coalesce(sum(qty), 0) as qty')->groupBy('po_line_id')->pluck('qty', 'po_line_id');
        $staysOpen = H::rejectedStaysOpen();
        $over = in_array($po->status, ['closed', 'cancelled'], true);
        $tolerance = (float) ($this->settings->get('procurement.prPriceTolerancePct') ?? 10);
        foreach ($row['lines'] as $i => $l) {
            $line = $po->lines[$i];
            $row['lines'][$i]['acceptedQty'] = (int) ($accepted[$l['id']] ?? 0);
            $row['lines'][$i]['invoicedQty'] = (int) ($invoiced[$l['id']] ?? 0);
            // what the supplier still owes on the line (rejected goods included while the policy says so); 0 once the order is over
            $row['lines'][$i]['openQty'] = $over ? 0 : H::openQty($line, $staysOpen);
            $row['lines'][$i]['waivedQty'] = $po->status === 'closed' ? H::openQty($line, $staysOpen) : 0;
            $est = $line->pr_est_price !== null ? (float) $line->pr_est_price : null;
            $row['lines'][$i]['priceChangePct'] = $est ? round(((float) $line->price - $est) / $est * 100, 1) : null;
            $row['lines'][$i]['priceAboveEstimate'] = $est !== null && (float) $line->price > $est * (1 + $tolerance / 100) + 1e-9;
        }

        return $row + [
            'late' => self::isLate($po), 'receivedPct' => self::receivedPct($po), 'receipt' => self::receipt($po),
            'rejectedStaysOpen' => $staysOpen, 'prPriceTolerancePct' => $tolerance,
            // discount / shipping the next invoice may still carry: the order's figure minus what earlier invoices already billed
            'charges' => SupplierInvoiceService::chargesLeft($po),
            'canClose' => Sm::can('PO_TRANSITIONS', $po->status, 'closed'),
            'invoices' => $po->invoices()->orderBy('created_at')->get(['id', 'number', 'supplier_invoice_no', 'status', 'total', 'due_date'])->map->toArray()->all(),
            'currentStep' => $po->approvals->first(fn ($a) => $a->decision === 'pending')?->toArray(),
            'sources' => ['rfq' => $po->rfq?->toArray(), 'prs' => $prs->map->toArray()->all()],
            'trace' => $po->shipments->map(fn ($s) => ['shipment' => $s->number, 'status' => $s->status, 'eta' => $s->eta?->toJSON(), 'grns' => $s->grns->whereNull('voided_at')->pluck('number')->values()->all()])->all(),
        ];
    }

    /** The PO with the projection every PO endpoint returns (supplier, warehouse, lines + product, approvals). */
    public function load(string $poId): PurchaseOrder
    {
        return PurchaseOrder::with([
            'supplier', 'warehouse',
            'lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product:'.self::PRODUCT_SEL,
            'approvals' => fn ($q) => $q->orderBy('step'),
        ])->findOrFail($poId);
    }

    private function loadShipment(string $shipmentId): InboundShipment
    {
        $shipment = InboundShipment::with(['lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product:'.self::PRODUCT_SEL, 'grns:id,number,posted_at,shipment_id'])->findOrFail($shipmentId);
        $shipment->grns->each->makeHidden('shipment_id');

        return $shipment;
    }

    /**
     * Runs $fn(PurchaseOrder) in a transaction that holds the PO row lock (serialises approve/send/cancel on the same PO).
     * The id is resolved BEFORE the transaction on purpose: under MySQL REPEATABLE READ the first plain read fixes the
     * snapshot, so the row lock has to be the first statement — otherwise a concurrent send that committed while this
     * request waited for the lock would stay invisible and a second shipment could be created.
     */
    private function locked(string $id, callable $fn): mixed
    {
        $poId = PurchaseOrder::where('id', $id)->orWhere('number', $id)->value('id')
            ?? throw AppError::notFound('PO_NOT_FOUND', "أمر الشراء {$id} غير موجود", "PO {$id} not found");

        return DB::transaction(function () use ($poId, $fn) {
            PurchaseOrder::where('id', $poId)->lockForUpdate()->first(['id']);

            return $fn($this->load($poId));
        });
    }

    private function transition(PurchaseOrder $po, string $to, ?string $ar = null): void
    {
        if (! Sm::can('PO_TRANSITIONS', $po->status, $to)) {
            throw AppError::rule('PO_TRANSITION', $ar ?: "انتقال غير مسموح لأمر الشراء {$po->number}: {$po->status} ← {$to}", "Invalid PO transition {$po->status} → {$to}");
        }
    }

    /** Formats a number the way the reference prints it inside texts (97 not 97.0, 1672.5 stays). */
    public static function num(float|int|string|null $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    }
}
