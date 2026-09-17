<?php

namespace App\Services\Procurement;

use App\Models\PrApproval;
use App\Models\PrLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Rfq;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Procurement\ProcurementHelpers as H;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sm;
use Illuminate\Support\Facades\DB;

/** Purchase requisitions: header + lines → submit → role-checked approval steps → approved → convert to RFQ or PO. */
class PrService
{
    /** PR approval chain (procurement review). Kept as a constant here; PO chains are the configurable ones. */
    private const PR_CHAIN = ['proc'];

    private const PRODUCT_SEL = 'id,sku,name_ar,name_en,purchase_price,reorder_min,preferred_supplier_name';

    public function __construct(
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly PoService $po,
        private readonly RfqService $rfq,
    ) {}

    /**
     * Joins the caller's transaction when there is one.
     *
     * @param  array{warehouseCode:string, needDate?:?string, priority?:?string, justification:string, costCenter?:?string,
     *               lines:array<int, array{sku:string, qty:int|string, price?:float|int|string|null, notes?:?string}>}  $dto
     * @param  array{submit?:bool, source?:string}  $opts
     */
    public function create(AuthUser $user, array $dto, array $opts = []): PurchaseRequisition
    {
        return DB::transaction(function () use ($user, $dto, $opts) {
            $submit = ! empty($opts['submit']);
            $priority = ($dto['priority'] ?? null) ?: 'normal';
            $wh = H::findWarehouse($dto['warehouseCode']);
            $products = H::findProducts(array_map(fn ($l) => $l['sku'], $dto['lines']));
            $number = $this->numbering->next('PR');
            $status = $submit ? 'submitted' : 'draft';
            $pr = PurchaseRequisition::create([
                'number' => $number, 'warehouse_id' => $wh->id, 'requested_by_id' => $user->id, 'requested_by' => $user->nameAr ?: $user->username,
                'need_date' => H::parseDate($dto['needDate'] ?? null), 'priority' => $priority, 'justification' => $dto['justification'], 'cost_center' => ($dto['costCenter'] ?? null) ?: null,
                'status' => $status, 'submitted_at' => $submit ? now() : null,
            ]);
            foreach (array_values($dto['lines']) as $i => $l) {
                $p = $products[$l['sku']];
                PrLine::create(['pr_id' => $pr->id, 'line_no' => $i + 1, 'product_id' => $p->id, 'qty' => (int) $l['qty'],
                    'est_price' => isset($l['price']) ? H::round2((float) $l['price']) : $p->purchase_price, 'notes' => ($l['notes'] ?? null) ?: null]);
            }
            if ($submit) {
                $this->createChain($pr->id);
            }
            $count = count($dto['lines']);
            $this->audit->log($user, ['action' => 'PR.CREATE', 'entityType' => 'PurchaseRequisition', 'entityId' => $pr->id, 'entityNumber' => $number, 'newValue' => ['warehouse' => $wh->code, 'lines' => $count, 'priority' => $priority, 'source' => $opts['source'] ?? 'manual']]);
            $this->audit->status($user, 'PurchaseRequisition', $pr->id, $number, null, $status);
            if ($submit) {
                $this->notify->activity($user, 'PurchaseRequisition', $pr->id, $number, "طلب شراء {$number} من {$wh->name_ar} ({$count} بند".($priority === 'urgent' ? ' — عاجل' : '').') — بانتظار مراجعة المشتريات', "PR {$number} from {$wh->name_en} submitted — pending procurement review", self::PR_CHAIN);
            }

            return $this->load($pr->id);
        });
    }

    public function submit(AuthUser $user, string $id): PurchaseRequisition
    {
        $pr = $this->find($id);
        $this->transition($pr, 'submitted');

        return DB::transaction(function () use ($user, $pr) {
            $from = $pr->status;
            PrApproval::where('pr_id', $pr->id)->delete();
            $pr->update(['status' => 'submitted', 'submitted_at' => now()]);
            $this->createChain($pr->id);
            $this->audit->status($user, 'PurchaseRequisition', $pr->id, $pr->number, $from, 'submitted');
            $this->notify->activity($user, 'PurchaseRequisition', $pr->id, $pr->number, "طلب شراء {$pr->number} من {$pr->warehouse->name_ar} — بانتظار مراجعة المشتريات", "PR {$pr->number} submitted", self::PR_CHAIN);

            return $this->load($pr->id);
        });
    }

    public function approve(AuthUser $user, string $id, ?string $note = null): PurchaseRequisition
    {
        $pr = $this->find($id);
        $this->assertPending($pr);
        $step = $pr->approvals->first(fn ($a) => $a->decision === 'pending');
        if (! $step) {
            throw AppError::rule('PR_NO_STEP', 'لا توجد خطوة اعتماد معلّقة', 'No pending approval step');
        }
        $this->assertStepRole($user, $pr, $step);

        return DB::transaction(function () use ($user, $pr, $step, $note) {
            $step->update(['decision' => 'approved', 'approver_id' => $user->id, 'approver' => $user->username, 'note' => $note ?: null, 'decided_at' => now()]);
            $this->audit->log($user, ['action' => 'PR.APPROVE', 'entityType' => 'PurchaseRequisition', 'entityId' => $pr->id, 'entityNumber' => $pr->number, 'field' => "step{$step->step}", 'oldValue' => 'pending',
                'newValue' => ['decision' => 'approved', 'role' => $step->role_key] + ($note !== null ? ['note' => $note] : [])]);
            $next = $pr->approvals->first(fn ($a) => $a->decision === 'pending' && $a->id !== $step->id);
            $from = $pr->status;
            $to = $next ? 'review' : 'approved';
            if ($to !== $from) {
                $this->transition($pr, $to);
            }
            $pr->update(['status' => $to, 'approved_at' => $to === 'approved' ? now() : null]);
            if ($to !== $from) {
                $this->audit->status($user, 'PurchaseRequisition', $pr->id, $pr->number, $from, $to, $note);
            }
            $this->notify->activity($user, 'PurchaseRequisition', $pr->id, $pr->number,
                $to === 'approved' ? "اعتُمد {$pr->number} — جاهز للتحويل إلى RFQ أو أمر شراء" : "اعتُمدت الخطوة {$step->step} من {$pr->number} — التالي: {$next->role_key}",
                $to === 'approved' ? "PR {$pr->number} approved" : "Step {$step->step} approved", $to === 'approved' ? ['proc'] : [$next->role_key]);

            return $this->load($pr->id);
        });
    }

    public function reject(AuthUser $user, string $id, string $reason): PurchaseRequisition
    {
        $pr = $this->find($id);
        $this->assertPending($pr);
        $step = $pr->approvals->first(fn ($a) => $a->decision === 'pending');
        if ($step) {
            $this->assertStepRole($user, $pr, $step);
        }
        $this->transition($pr, 'rejected');

        return DB::transaction(function () use ($user, $pr, $step, $reason) {
            $step?->update(['decision' => 'rejected', 'approver_id' => $user->id, 'approver' => $user->username, 'note' => $reason, 'decided_at' => now()]);
            $from = $pr->status;
            $pr->update(['status' => 'rejected']);
            $this->audit->log($user, ['action' => 'PR.REJECT', 'entityType' => 'PurchaseRequisition', 'entityId' => $pr->id, 'entityNumber' => $pr->number, 'newValue' => ['reason' => $reason]]);
            $this->audit->status($user, 'PurchaseRequisition', $pr->id, $pr->number, $from, 'rejected', $reason);
            $this->notify->activity($user, 'PurchaseRequisition', $pr->id, $pr->number, "رُفض {$pr->number} — {$reason}", "PR {$pr->number} rejected — {$reason}", ['wm']);

            return $this->load($pr->id);
        });
    }

    /**
     * approved → converted: creates a multi-line RFQ from the PR lines.
     *
     * @param  array{closeDate:string, invitedRule?:?string, supplierCodes?:?array, terms?:?string, deliveryWarehouseCode?:?string, notes?:?string}  $dto
     */
    public function toRfq(AuthUser $user, string $id, array $dto): Rfq
    {
        $pr = $this->find($id);
        $this->transition($pr, 'converted', "لا يمكن تحويل {$pr->number} إلى RFQ إلا بعد اعتماده (الحالة: {$pr->status})");

        return DB::transaction(function () use ($user, $pr, $dto) {
            $rfq = $this->rfq->create($user, [
                'prNumber' => $pr->number, 'lines' => $pr->lines->map(fn ($l) => ['sku' => $l->product->sku, 'qty' => $l->qty])->all(),
                'invitedRule' => $dto['invitedRule'] ?? null, 'supplierCodes' => $dto['supplierCodes'] ?? null, 'closeDate' => $dto['closeDate'], 'terms' => $dto['terms'] ?? null,
                'deliveryWarehouseCode' => ($dto['deliveryWarehouseCode'] ?? null) ?: $pr->warehouse->code, 'notes' => ($dto['notes'] ?? null) ?: ($pr->justification ?: null),
            ], ['prId' => $pr->id, 'source' => 'pr']);
            $from = $pr->status;
            $pr->update(['status' => 'converted', 'rfq_id' => $rfq->id]);
            $this->audit->status($user, 'PurchaseRequisition', $pr->id, $pr->number, $from, 'converted', "→ {$rfq->number}");

            return $rfq;
        });
    }

    /**
     * approved → converted: creates a PO directly (prices from dto, else PR estimate, else product purchase price).
     *
     * @param  array{supplierCode:string, dueDate:string, paymentTerms?:?string, notes?:?string, prices?:?array, overrideSupplierScore?:?bool, overrideReason?:?string}  $dto
     */
    public function toPo(AuthUser $user, string $id, array $dto): PurchaseOrder
    {
        $pr = $this->find($id);
        $this->transition($pr, 'converted', "لا يمكن تحويل {$pr->number} إلى أمر شراء إلا بعد اعتماده (الحالة: {$pr->status})");
        $given = [];
        foreach (($dto['prices'] ?? null) ?: [] as $p) {
            $given[$p['sku']] ??= (float) $p['price'];
        }
        $lines = [];
        foreach ($pr->lines as $l) {
            $price = $given[$l->product->sku] ?? ($l->est_price !== null ? (float) $l->est_price : ($l->product->purchase_price !== null ? (float) $l->product->purchase_price : null));
            if (! $price || $price <= 0) {
                throw AppError::validation('PRICE_REQUIRED', "حدد سعر الوحدة لـ {$l->product->name_ar}", "Unit price required for {$l->product->sku}");
            }
            $lines[] = ['sku' => $l->product->sku, 'qty' => $l->qty, 'price' => $price];
        }

        return DB::transaction(function () use ($user, $pr, $dto, $lines) {
            $po = $this->po->create($user, [
                'supplierCode' => $dto['supplierCode'], 'warehouseCode' => $pr->warehouse->code, 'dueDate' => $dto['dueDate'], 'paymentTerms' => $dto['paymentTerms'] ?? null, 'reference' => $pr->number,
                'notes' => $dto['notes'] ?? null, 'lines' => $lines, 'overrideSupplierScore' => $dto['overrideSupplierScore'] ?? null, 'overrideReason' => $dto['overrideReason'] ?? null,
            ], ['source' => 'pr']);
            $from = $pr->status;
            $pr->update(['status' => 'converted', 'po_id' => $po->id]);
            $this->audit->status($user, 'PurchaseRequisition', $pr->id, $pr->number, $from, 'converted', "→ {$po->number}");

            return $po;
        });
    }

    /** @param  array{status?:?string, warehouse?:?string, priority?:?string}  $filters */
    public function list(Paging $page, array $filters): array
    {
        $query = PurchaseRequisition::query()->with($this->includes());
        if (! empty($filters['status'])) {
            $query->whereIn('status', H::csv($filters['status']));
        }
        if (! empty($filters['warehouse'])) {
            $query->whereHas('warehouse', fn ($w) => $w->where('code', strtoupper($filters['warehouse'])));
        }
        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $query->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('requested_by', 'like', $like)->orWhere('justification', 'like', $like)
                ->orWhereHas('lines.product', fn ($p) => $p->where(fn ($x) => $x->where('sku', 'like', $like)->orWhere('name_ar', 'like', $like))));
        }
        $query->orderBy('created_at', $page->order)->orderBy('id', $page->order);

        return $page->paginate($query, fn (PurchaseRequisition $pr) => $pr->toArray() + $this->computed($pr));
    }

    public function get(string $id): array
    {
        $pr = $this->find($id);
        $rfq = $pr->rfq_id ? Rfq::where('id', $pr->rfq_id)->first(['id', 'number', 'status']) : null;
        $po = $pr->po_id ? PurchaseOrder::where('id', $pr->po_id)->first(['id', 'number', 'status', 'total']) : null;

        return $pr->toArray() + $this->computed($pr) + ['rfq' => $rfq?->toArray(), 'po' => $po?->toArray()];
    }

    /** @return array{estTotal: float, currentStep: ?array} */
    private function computed(PurchaseRequisition $pr): array
    {
        return [
            'estTotal' => H::round2($pr->lines->sum(fn ($l) => $l->qty * (float) ($l->est_price ?? 0))),
            'currentStep' => $pr->approvals->first(fn ($a) => $a->decision === 'pending')?->toArray(),
        ];
    }

    private function createChain(string $prId): void
    {
        foreach (self::PR_CHAIN as $i => $roleKey) {
            PrApproval::create(['pr_id' => $prId, 'step' => $i + 1, 'role_key' => $roleKey]);
        }
    }

    private function includes(): array
    {
        return ['warehouse:id,code,name_ar,name_en', 'lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product:'.self::PRODUCT_SEL, 'approvals' => fn ($q) => $q->orderBy('step')];
    }

    private function load(string $prId): PurchaseRequisition
    {
        return PurchaseRequisition::with($this->includes())->findOrFail($prId);
    }

    private function find(string $id): PurchaseRequisition
    {
        $pr = PurchaseRequisition::where('id', $id)->orWhere('number', $id)->first(['id'])
            ?? throw AppError::notFound('PR_NOT_FOUND', "طلب الشراء {$id} غير موجود", "PR {$id} not found");

        return $this->load($pr->id);
    }

    private function assertPending(PurchaseRequisition $pr): void
    {
        if (! in_array($pr->status, ['submitted', 'review'], true)) {
            throw AppError::rule('PR_NOT_PENDING', "طلب الشراء {$pr->number} ليس بانتظار الاعتماد (الحالة: {$pr->status})", "PR {$pr->number} is not pending approval");
        }
    }

    private function assertStepRole(AuthUser $user, PurchaseRequisition $pr, PrApproval $step): void
    {
        if (! H::hasRole($user, $step->role_key)) {
            throw AppError::rule('PR_APPROVAL_ROLE', "الخطوة {$step->step} من {$pr->number} تتطلب دور «{$step->role_key}»", "Step {$step->step} requires role {$step->role_key}");
        }
    }

    private function transition(PurchaseRequisition $pr, string $to, ?string $ar = null): void
    {
        if (! Sm::can('PR_TRANSITIONS', $pr->status, $to)) {
            throw AppError::rule('PR_TRANSITION', $ar ?: "انتقال غير مسموح لطلب الشراء {$pr->number}: {$pr->status} ← {$to}", "Invalid PR transition {$pr->status} → {$to}");
        }
    }
}
