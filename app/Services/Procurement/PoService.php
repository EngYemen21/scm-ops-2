<?php

namespace App\Services\Procurement;

use App\Models\InboundShipment;
use App\Models\PoApproval;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\ShipmentLine;
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
            foreach (array_values($dto['lines']) as $i => $l) {
                $lines[] = ['line_no' => $i + 1, 'product_id' => $products[$l['sku']]->id, 'qty' => (int) $l['qty'], 'price' => H::round2((float) $l['price'])];
            }
            $total = H::round2(array_sum(array_map(fn ($l) => $l['qty'] * $l['price'], $lines)));
            if ($total <= 0) {
                throw AppError::rule('PO_EMPTY', 'قيمة أمر الشراء يجب أن تكون أكبر من صفر', 'PO total must be positive');
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
            $number = $this->numbering->next('PO');
            $status = ! empty($opts['draft']) ? 'draft' : 'pending';
            $po = PurchaseOrder::create([
                'number' => $number, 'supplier_id' => $supplier->id, 'warehouse_id' => $wh->id, 'rfq_id' => ($opts['rfqId'] ?? null) ?: null, 'status' => $status, 'total' => $total,
                'due_date' => H::parseDate($dto['dueDate']), 'payment_terms' => ($dto['paymentTerms'] ?? null) ?: ($supplier->terms ?: null),
                'reference' => ($dto['reference'] ?? null) ?: null, 'notes' => ($dto['notes'] ?? null) ?: null, 'approval_step' => 0,
                'open_qty' => array_sum(array_column($lines, 'qty')), 'created_by_id' => $user->id, 'created_by' => $user->username,
            ]);
            foreach ($lines as $l) {
                PoLine::create($l + ['po_id' => $po->id]);
            }
            foreach ($chain as $c) {
                PoApproval::create(['po_id' => $po->id, 'step' => $c['step'], 'role_key' => $c['roleKey'], 'label_ar' => $c['labelAr'], 'label_en' => $c['labelEn']]);
            }
            $source = $opts['source'] ?? 'manual';
            $newValue = ['supplier' => $supplier->code, 'warehouse' => $wh->code, 'total' => $total, 'lines' => count($lines), 'chain' => array_column($chain, 'roleKey'), 'source' => $source];
            if (! empty($opts['rfqId'])) {
                $newValue['rfqId'] = $opts['rfqId'];
            }
            $this->audit->log($user, ['action' => 'PO.CREATE', 'entityType' => 'PurchaseOrder', 'entityId' => $po->id, 'entityNumber' => $number, 'newValue' => $newValue]);
            if ($override) {
                $this->audit->log($user, ['action' => 'PO.SUPPLIER_SCORE_OVERRIDE', 'entityType' => 'PurchaseOrder', 'entityId' => $po->id, 'entityNumber' => $number, 'field' => 'supplierScore',
                    'oldValue' => "{$score} < {$min}", 'newValue' => ['supplier' => $supplier->code, 'reason' => $override['reason'], 'by' => $user->username]]);
            }
            $this->audit->status($user, 'PurchaseOrder', $po->id, $number, null, $status);
            $steps = count($chain);
            $totalText = self::num($total);
            $this->notify->activity($user, 'PurchaseOrder', $po->id, $number,
                "أُنشئ {$number} لـ {$supplier->name_ar} بقيمة {$totalText} ر.س — سلسلة اعتماد {$steps} خطوة".($steps > 1 ? 'ات' : '').($status === 'draft' ? ' (مسودة)' : ''),
                "{$number} created for {$supplier->name_en} — {$totalText} SAR, {$steps}-step approval", $status === 'pending' ? [$chain[0]['roleKey']] : []);

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
        ])->withCount(['lines', 'grns']);
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
            'shipments.grns:id,number,posted_at,shipment_id',
            'grns' => fn ($q) => $q->select(['id', 'number', 'posted_at', 'posted_by', 'shipment_id', 'summary_ar', 'summary_en', 'po_id'])->withCount('lines')->orderBy('posted_at')->orderBy('id'),
        ]);
        $po->shipments->each(fn ($s) => $s->grns->each->makeHidden('shipment_id'));
        $po->grns->each->makeHidden('po_id');
        $row = $po->toArray();
        foreach ($row['grns'] as $i => $g) {
            $row['grns'][$i]['_count'] = ['lines' => (int) ($g['linesCount'] ?? 0)];
            unset($row['grns'][$i]['linesCount']);
        }
        $prs = PurchaseRequisition::where('po_id', $po->id)->get(['id', 'number', 'status']);

        return $row + [
            'currentStep' => $po->approvals->first(fn ($a) => $a->decision === 'pending')?->toArray(),
            'sources' => ['rfq' => $po->rfq?->toArray(), 'prs' => $prs->map->toArray()->all()],
            'trace' => $po->shipments->map(fn ($s) => ['shipment' => $s->number, 'status' => $s->status, 'eta' => $s->eta?->toJSON(), 'grns' => $s->grns->pluck('number')->all()])->all(),
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
