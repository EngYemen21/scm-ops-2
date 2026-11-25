<?php

namespace App\Planning\Services;

use App\Models\PrLine;
use App\Planning\Engine\Calendar;
use App\Planning\Engine\Num;
use App\Planning\Models\Budget;
use App\Planning\Models\Plan;
use App\Planning\Models\PlanLine;
use App\Planning\Support\Clock;
use App\Planning\Support\Owned;
use App\Planning\Support\Shape;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Services\Procurement\PrService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;

/**
 * Purchase plans: what an allocated budget scenario buys, line by line (product, warehouse, vendor, quantity, price,
 * order date), between two order dates.
 *
 *   draft ──submit──▶ to_approve ──approve──▶ approved (in progress) ──close──▶ done
 *     ▲                   │ return (reason)            │
 *     └───────────────────┘          cancel (reason) from draft / to_approve / approved, while no line was requested
 * `purchaseplan.manage` makes, edits, submits, requests and closes; `purchaseplan.approve` approves or returns — and
 * never a plan he submitted himself (`PLANNING_PLAN_SELF_APPROVAL`).
 *
 * REQUESTS. An approved plan's lines become purchase requests of the procurement section through `PrService::create`
 * (submitted, so procurement reviews them as any other): one request per warehouse and vendor and — as the plan says —
 * per order date, per week (Monday to Sunday) or for the whole plan. A line is requested ONCE: the lines are locked
 * while they are turned into requests and a line that names a request is never requested again. The person also needs
 * `pr.create`, the permission procurement asks for a request.
 *
 * PROGRESS is read, never kept: a line's request, the purchase order it became (directly, or through the request for
 * quotation it went to) and that order's line of the product give what was ordered and received.
 */
class PlansService
{
    public const PO_LIVE = ['approved', 'sent', 'confirmed', 'partial', 'received', 'closed'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly PrService $prs,
    ) {}

    public function list(Paging $page, array $f): array
    {
        $q = Plan::query();
        foreach (['status' => 'status', 'run' => 'run_id', 'budget' => 'budget_id'] as $key => $column) {
            if (! empty($f[$key])) {
                $q->where($column, $f[$key]);
            }
        }
        if ($page->q) {
            $like = '%'.$page->q.'%';
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('name', 'like', $like));
        }
        $sorts = ['createdAt' => 'created_at', 'number' => 'number', 'dateFrom' => 'date_from', 'status' => 'status'];
        $q->orderBy($sorts[$page->sort ?? ''] ?? 'created_at', $page->order)->orderByDesc('id');

        return $page->paginate($q, fn (Plan $p) => Shape::planHead($p) + ['totals' => $this->progress($p)['totals']]);
    }

    public function get(string $id): array
    {
        $plan = $this->find($id);
        $progress = $this->progress($plan);

        return Shape::plan($plan) + ['totals' => $progress['totals'], 'lines' => $progress['lines'], 'budget' => $plan->budget ? Shape::budgetHead($plan->budget) : null, 'run' => Shape::runHead($plan->run)];
    }

    /** A new draft plan from an allocated scenario: every funded allocation whose order date is in the window and is not in another live plan. */
    public function create(AuthUser $user, string $budgetId, array $dto): array
    {
        $plan = DB::transaction(function () use ($user, $budgetId, $dto) {
            $budget = Budget::whereKey($budgetId)->lockForUpdate()->first() ?? throw AppError::notFound('PLANNING_BUDGET_NOT_FOUND', 'سيناريو الميزانية غير موجود', 'Budget scenario not found');
            Owned::refuse($budget, 'budget');
            if ($budget->status !== 'allocated') {
                throw AppError::rule('PLANNING_BUDGET_NOT_ALLOCATED', 'وزّع الميزانية أولًا', 'Allocate the budget first');
            }
            $months = DB::table('planning_budget_lines')->where('budget_id', $budget->id)->orderBy('month')->pluck('month')->map(fn ($m) => substr((string) $m, 0, 10))->all();
            $from = $dto['dateFrom'] ?? ($months[0] ?? Clock::today());
            $to = $dto['dateTo'] ?? ($months ? Calendar::monthEnd(end($months)) : $from);
            if ($from > $to) {
                throw AppError::validation('PLANNING_WINDOW', 'بداية الخطة بعد نهايتها', 'The plan starts after it ends');
            }
            $taken = DB::table('planning_plan_lines as l')->join('planning_plans as p', 'p.id', '=', 'l.plan_id')->where('p.status', '!=', 'cancel')->where('l.cancelled', false)
                ->whereNotNull('l.allocation_id')->pluck('l.allocation_id')->all();
            $allocations = DB::table('planning_allocations as a')->join('planning_requirements as r', 'r.id', '=', 'a.requirement_id')
                ->where('a.budget_id', $budget->id)->where('a.funded_qty', '>', 0)->whereBetween('a.order_date', [$from, $to])->whereNotIn('a.id', $taken ?: ['-'])
                ->orderBy('a.order_date')->orderBy('r.vendor_name')->orderBy('r.sku')->orderBy('r.warehouse_code')->orderBy('a.id')
                ->get(['a.id', 'a.requirement_id', 'a.funded_qty', 'a.order_date', 'r.product_id', 'r.sku', 'r.warehouse_id', 'r.vendor_id', 'r.vendor_name', 'r.price_unit', 'r.need_date', 'r.lead_days']);
            if ($allocations->isEmpty()) {
                throw AppError::rule('PLANNING_PLAN_EMPTY', 'لا مشتريات مموّلة بتاريخ طلب في هذه الفترة لم تدخل خطة أخرى', 'No funded purchase with an order date in this window that is not in another plan');
            }
            $plan = Plan::create([
                'number' => $this->numbering->next('PPL'), 'name' => $dto['name'] ?? null, 'run_id' => $budget->run_id, 'budget_id' => $budget->id, 'status' => 'draft', 'date_from' => $from, 'date_to' => $to,
                'rfq_grouping' => $dto['rfqGrouping'] ?? 'date', 'note' => $dto['note'] ?? null, 'planner_id' => $user->id, 'planner_name' => $user->nameAr ?: $user->username,
                'created_by' => $user->username, 'updated_by' => $user->username,
            ]);
            foreach ($allocations->values() as $i => $a) {
                $qty = (float) (int) floor((float) $a->funded_qty + 1e-9);
                $price = $a->price_unit !== null ? (float) $a->price_unit : null;
                PlanLine::create([
                    'plan_id' => $plan->id, 'line_no' => $i + 1, 'allocation_id' => $a->id, 'requirement_id' => $a->requirement_id, 'product_id' => $a->product_id, 'sku' => $a->sku, 'warehouse_id' => $a->warehouse_id,
                    'vendor_id' => $a->vendor_id, 'vendor_name' => $a->vendor_name, 'qty' => $qty, 'price_unit' => $price, 'subtotal' => $price !== null ? Num::m($qty * $price) : 0,
                    'order_date' => substr((string) $a->order_date, 0, 10), 'need_date' => $a->need_date ? substr((string) $a->need_date, 0, 10) : null, 'lead_days' => $a->lead_days, 'cancelled' => false, 'status' => 'planned',
                ]);
            }
            $this->audit->log($user, ['action' => 'PLANNING.PLAN_CREATE', 'entityType' => 'PurchasePlan', 'entityId' => $plan->id, 'entityNumber' => $plan->number,
                'newValue' => ['budget' => $budget->name, 'from' => $from, 'to' => $to, 'lines' => $allocations->count()]]);
            $this->audit->status($user, 'PurchasePlan', $plan->id, $plan->number, null, 'draft');

            return $plan;
        });

        return $this->get($plan->id);
    }

    public function update(AuthUser $user, string $id, array $dto): array
    {
        DB::transaction(function () use ($user, $id, $dto) {
            $plan = $this->locked($id);
            $this->assertStatus($plan, ['draft'], 'تُعدَّل الخطة وهي مسودة فقط', 'A plan is edited only while it is a draft');
            $old = $plan->only(['name', 'note', 'rfq_grouping']);
            $set = ['updated_by' => $user->username];
            foreach (['name' => 'name', 'note' => 'note', 'rfqGrouping' => 'rfq_grouping'] as $key => $column) {
                if (array_key_exists($key, $dto)) {
                    $set[$column] = $dto[$key];
                }
            }
            $plan->fill($set)->save();
            $this->audit->log($user, ['action' => 'PLANNING.PLAN_UPDATE', 'entityType' => 'PurchasePlan', 'entityId' => $plan->id, 'entityNumber' => $plan->number, 'oldValue' => $old, 'newValue' => $plan->only(['name', 'note', 'rfq_grouping'])]);
        });

        return $this->get($id);
    }

    /** Changes one line of a draft plan: quantity (whole units), vendor, order date, note. */
    public function updateLine(AuthUser $user, string $id, string $lineId, array $dto): array
    {
        DB::transaction(function () use ($user, $id, $lineId, $dto) {
            $plan = $this->locked($id);
            $this->assertStatus($plan, ['draft'], 'تُعدَّل بنود الخطة وهي مسودة فقط', 'The lines of a plan are edited only while it is a draft');
            $line = PlanLine::where('plan_id', $plan->id)->whereKey($lineId)->lockForUpdate()->first() ?? throw AppError::notFound('PLANNING_PLAN_LINE_NOT_FOUND', 'بند الخطة غير موجود', 'Plan line not found');
            if ($line->cancelled) {
                throw AppError::rule('PLANNING_PLAN_LINE_CANCELLED', 'البند ملغى', 'The line is cancelled');
            }
            $old = $line->only(['qty', 'vendor_id', 'order_date', 'note']);
            if (isset($dto['qty'])) {
                $line->qty = (float) (int) $dto['qty'];
            }
            if (array_key_exists('vendorId', $dto)) {
                $s = $dto['vendorId'] ? DB::table('suppliers')->where('id', $dto['vendorId'])->orWhere('code', $dto['vendorId'])->first() : null;
                if ($dto['vendorId'] && ! $s) {
                    throw AppError::notFound('SUPPLIER_NOT_FOUND', 'المورد غير موجود', 'Supplier not found');
                }
                $line->vendor_id = $s?->id;
                $line->vendor_name = $s?->name_ar;
            }
            if (! empty($dto['orderDate'])) {
                $line->order_date = $dto['orderDate'];
            }
            if (array_key_exists('note', $dto)) {
                $line->note = $dto['note'];
            }
            $line->subtotal = $line->price_unit !== null ? Num::m($line->qty * (float) $line->price_unit) : 0;
            $line->save();
            $this->audit->log($user, ['action' => 'PLANNING.PLAN_LINE_UPDATE', 'entityType' => 'PurchasePlan', 'entityId' => $plan->id, 'entityNumber' => $plan->number, 'field' => "line{$line->line_no}",
                'oldValue' => $old, 'newValue' => $line->only(['qty', 'vendor_id', 'order_date', 'note'])]);
        });

        return $this->get($id);
    }

    /** A line is cancelled (kept, with its reason) while the plan is a draft, or approved and the line not requested. */
    public function cancelLine(AuthUser $user, string $id, string $lineId, string $reason): array
    {
        DB::transaction(function () use ($user, $id, $lineId, $reason) {
            $plan = $this->locked($id);
            $this->assertStatus($plan, ['draft', 'approved'], 'يُلغى بند في خطة مسودة أو قيد التنفيذ فقط', 'A line is cancelled only in a draft or an approved plan');
            $line = PlanLine::where('plan_id', $plan->id)->whereKey($lineId)->lockForUpdate()->first() ?? throw AppError::notFound('PLANNING_PLAN_LINE_NOT_FOUND', 'بند الخطة غير موجود', 'Plan line not found');
            if ($line->cancelled) {
                return;
            }
            if ($line->pr_id) {
                throw AppError::rule('PLANNING_PLAN_LINE_REQUESTED', "صار البند طلب شراء ({$line->pr_number}) — يُلغى من المشتريات", "The line became a purchase request ({$line->pr_number}) — cancel it in procurement");
            }
            $line->forceFill(['cancelled' => true, 'cancel_reason' => $reason, 'status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $user->username])->save();
            $this->audit->log($user, ['action' => 'PLANNING.PLAN_LINE_CANCEL', 'entityType' => 'PurchasePlan', 'entityId' => $plan->id, 'entityNumber' => $plan->number, 'field' => "line{$line->line_no}", 'newValue' => ['reason' => $reason]]);
        });

        return $this->get($id);
    }

    public function submit(AuthUser $user, string $id): array
    {
        return $this->move($user, $id, 'to_approve', null, function (Plan $plan) use ($user) {
            if (! PlanLine::where('plan_id', $plan->id)->where('cancelled', false)->exists()) {
                throw AppError::rule('PLANNING_PLAN_EMPTY', 'لا بنود قائمة في الخطة', 'The plan has no line left');
            }
            $plan->forceFill(['submitted_at' => now(), 'submitted_by' => $user->username, 'submitted_by_id' => $user->id]);
        });
    }

    public function approve(AuthUser $user, string $id, ?string $note): array
    {
        return $this->move($user, $id, 'approved', $note, function (Plan $plan) use ($user) {
            if ($plan->submitted_by_id === $user->id) {
                throw AppError::forbidden('PLANNING_PLAN_SELF_APPROVAL', 'لا يعتمد أحد خطة رفعها بنفسه', 'Nobody approves a plan he submitted himself');
            }
            $plan->forceFill(['approved_at' => now(), 'approved_by' => $user->username, 'approved_by_id' => $user->id]);
        });
    }

    public function returnToDraft(AuthUser $user, string $id, string $reason): array
    {
        return $this->move($user, $id, 'draft', $reason, fn (Plan $plan) => $plan->forceFill(['submitted_at' => null, 'submitted_by' => null, 'submitted_by_id' => null, 'return_reason' => $reason]));
    }

    public function close(AuthUser $user, string $id, ?string $note): array
    {
        return $this->move($user, $id, 'done', $note, fn (Plan $plan) => $plan->forceFill(['closed_at' => now(), 'closed_by' => $user->username, 'close_note' => $note]));
    }

    public function cancel(AuthUser $user, string $id, string $reason): array
    {
        return $this->move($user, $id, 'cancel', $reason, function (Plan $plan) use ($user, $reason) {
            if (PlanLine::where('plan_id', $plan->id)->whereNotNull('pr_id')->exists()) {
                throw AppError::rule('PLANNING_PLAN_REQUESTED', 'صارت بنود من الخطة طلبات شراء — أغلق الخطة بدل إلغائها', 'Lines of the plan became purchase requests — close the plan instead of cancelling it');
            }
            $plan->forceFill(['cancelled_at' => now(), 'cancelled_by' => $user->username, 'cancel_reason' => $reason]);
        });
    }

    /**
     * Turns the open lines of an approved plan (or the ones named) into purchase requests, once.
     *
     * @return array the plan, with `requests`: the purchase requests made by this call
     */
    public function request(AuthUser $user, string $id, array $lineIds = []): array
    {
        if (! $user->can('pr.create')) {
            throw AppError::forbidden('PLANNING_PR_PERMISSION', 'تحويل الخطة إلى طلبات شراء يحتاج صلاحية إنشاء طلب الشراء', 'Turning the plan into purchase requests needs the permission to create a purchase request');
        }
        $made = DB::transaction(function () use ($user, $id, $lineIds) {
            $plan = $this->locked($id);
            Owned::refuse($plan, 'plan');
            $this->assertStatus($plan, ['approved'], 'تُحوَّل الخطة المعتمدة فقط إلى طلبات شراء', 'Only an approved plan becomes purchase requests');
            $lines = PlanLine::where('plan_id', $plan->id)->where('cancelled', false)->whereNull('pr_id')->when($lineIds, fn ($q) => $q->whereIn('id', $lineIds))
                ->orderBy('line_no')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw AppError::rule('PLANNING_PLAN_NOTHING_TO_REQUEST', 'كل بنود الخطة صارت طلبات شراء أو أُلغيت', 'Every line of the plan was already requested or cancelled');
            }
            $warehouses = DB::table('warehouses')->whereIn('id', $lines->pluck('warehouse_id')->unique()->all())->pluck('code', 'id')->all();
            $groups = [];
            foreach ($lines as $l) {
                $day = $l->order_date->format('Y-m-d');
                $key = match ($plan->rfq_grouping) {
                    'week' => Calendar::add($day, -((int) $l->order_date->format('N') - 1)),
                    'plan' => 'plan',
                    default => $day,
                };
                $groups["{$l->warehouse_id}|{$l->vendor_id}|{$key}"][] = $l;
            }
            ksort($groups);
            $made = [];
            foreach ($groups as $group) {
                $first = $group[0];
                $vendor = $first->vendor_name ?: 'بلا مورد محدد';
                $pr = $this->prs->create($user, [
                    'warehouseCode' => $warehouses[$first->warehouse_id], 'needDate' => min(array_map(fn ($l) => $l->need_date?->format('Y-m-d') ?? $l->order_date->format('Y-m-d'), $group)),
                    'priority' => min(array_map(fn ($l) => $l->order_date->format('Y-m-d'), $group)) < Clock::today() ? 'urgent' : 'normal',
                    'justification' => "خطة الشراء {$plan->number} · المورد: {$vendor} · تاريخ الطلب ".min(array_map(fn ($l) => $l->order_date->format('Y-m-d'), $group))." · Purchase plan {$plan->number}",
                    'lines' => array_map(fn (PlanLine $l) => ['sku' => $l->sku, 'qty' => (int) $l->qty, 'price' => $l->price_unit, 'notes' => "{$plan->number} / {$l->line_no}"], $group),
                ], ['submit' => true, 'source' => 'planning']);
                $prLines = PrLine::where('pr_id', $pr->id)->pluck('id', 'line_no')->all();
                foreach ($group as $i => $l) {
                    $l->forceFill(['pr_id' => $pr->id, 'pr_number' => $pr->number, 'pr_line_id' => $prLines[$i + 1] ?? null, 'requested_at' => now(), 'requested_by' => $user->username, 'status' => 'rfq'])->save();
                }
                $made[] = ['id' => $pr->id, 'number' => $pr->number, 'lines' => count($group), 'vendor' => $first->vendor_name];
            }
            $this->audit->log($user, ['action' => 'PLANNING.PLAN_REQUEST', 'entityType' => 'PurchasePlan', 'entityId' => $plan->id, 'entityNumber' => $plan->number,
                'newValue' => ['requests' => array_column($made, 'number'), 'lines' => $lines->count()]]);

            return $made;
        });

        return $this->get($id) + ['requests' => $made];
    }

    // ───────────── progress ─────────────

    /** @return array{lines:array, totals:array} */
    public function progress(Plan $plan): array
    {
        $lines = PlanLine::where('plan_id', $plan->id)->orderBy('line_no')->orderBy('id')->get();
        $prIds = $lines->pluck('pr_id')->filter()->unique()->values()->all();
        $prs = DB::table('purchase_requisitions')->whereIn('id', $prIds ?: ['-'])->get(['id', 'number', 'status', 'po_id', 'rfq_id'])->keyBy('id');
        $poIds = [];
        foreach ($prs as $pr) {
            if ($pr->po_id) {
                $poIds[$pr->id] = [$pr->po_id];
            } elseif ($pr->rfq_id) {
                $poIds[$pr->id] = DB::table('purchase_orders')->where('rfq_id', $pr->rfq_id)->pluck('id')->all();
            }
        }
        $allPo = array_values(array_unique(array_merge([], ...array_values($poIds))));
        $pos = DB::table('purchase_orders')->whereIn('id', $allPo ?: ['-'])->get(['id', 'number', 'status', 'warehouse_id'])->keyBy('id');
        $poLines = DB::table('po_lines')->whereIn('po_id', $allPo ?: ['-'])->orderBy('line_no')->get(['po_id', 'product_id', 'qty', 'received_qty', 'price']);
        $today = Clock::today();
        $names = DB::table('products')->whereIn('id', $lines->pluck('product_id')->filter()->unique()->values()->all() ?: ['-'])->get(['id', 'name_ar', 'name_en'])->keyBy('id');
        $whCodes = DB::table('warehouses')->whereIn('id', $lines->pluck('warehouse_id')->filter()->unique()->values()->all() ?: ['-'])->pluck('code', 'id');
        $budgetAmount = (float) DB::table('planning_budget_lines')->where('budget_id', $plan->budget_id)
            ->whereBetween('month', [Calendar::monthStart($plan->date_from->format('Y-m-d')), $plan->date_to->format('Y-m-d')])->sum('amount');
        $t = ['lines' => 0, 'cancelled' => 0, 'requested' => 0, 'ordered' => 0, 'received' => 0, 'lateOrder' => 0, 'lateReceipt' => 0, 'planned' => 0.0, 'committed' => 0.0, 'receivedValue' => 0.0, 'plannedQty' => 0.0, 'receivedQty' => 0.0];
        $out = [];
        $used = [];
        foreach ($lines as $l) {
            $pr = $l->pr_id ? ($prs[$l->pr_id] ?? null) : null;
            $po = null;
            $poLine = null;
            foreach ($pr ? ($poIds[$pr->id] ?? []) : [] as $poId) {
                $candidate = $pos[$poId] ?? null;
                if (! $candidate || ! in_array($candidate->status, self::PO_LIVE, true)) {
                    continue;
                }
                foreach ($poLines as $i => $pl) {
                    if ($pl->po_id === $poId && $pl->product_id === $l->product_id && ! isset($used[$i])) {
                        [$po, $poLine] = [$candidate, $pl];
                        $used[$i] = true;
                        break 2;
                    }
                }
            }
            $ordered = $poLine ? (float) $poLine->qty : 0.0;
            $received = $poLine ? (float) $poLine->received_qty : 0.0;
            $status = match (true) {
                (bool) $l->cancelled => 'cancelled',
                $poLine && $received >= $ordered - 1e-9 && $ordered > 0 => 'received',
                $poLine && $received > 0 => 'partial',
                $poLine !== null => 'confirmed',
                $l->pr_id !== null => 'rfq',
                default => 'planned',
            };
            $lateOrder = ! $l->cancelled && ! $poLine && $l->order_date->format('Y-m-d') < $today;
            $lateReceipt = ! $l->cancelled && $l->need_date !== null && $l->need_date->format('Y-m-d') < $today && $received + 1e-9 < (float) $l->qty;
            $committed = $poLine ? Num::m($ordered * (float) $poLine->price) : 0.0;
            $receivedValue = $poLine ? Num::m($received * (float) $poLine->price) : 0.0;
            if (! $l->cancelled) {
                $t['lines']++;
                $t['planned'] += (float) $l->subtotal;
                $t['plannedQty'] += (float) $l->qty;
                $t['requested'] += $l->pr_id ? 1 : 0;
                $t['ordered'] += $poLine ? 1 : 0;
                $t['received'] += $status === 'received' ? 1 : 0;
                $t['lateOrder'] += $lateOrder ? 1 : 0;
                $t['lateReceipt'] += $lateReceipt ? 1 : 0;
                $t['committed'] += $committed;
                $t['receivedValue'] += $receivedValue;
                $t['receivedQty'] += min($received, (float) $l->qty);
            } else {
                $t['cancelled']++;
            }
            $out[] = Shape::planLine($l) + ['nameAr' => $names[$l->product_id]->name_ar ?? null, 'nameEn' => $names[$l->product_id]->name_en ?? null, 'warehouseCode' => $whCodes[$l->warehouse_id] ?? null,
                'status' => $status, 'request' => $pr ? ['id' => $pr->id, 'number' => $pr->number, 'status' => $pr->status] : null,
                'purchaseOrder' => $po ? ['id' => $po->id, 'number' => $po->number, 'status' => $po->status] : null, 'orderedQty' => $ordered, 'receivedQty' => $received,
                'committedValue' => $committed, 'receivedValue' => $receivedValue, 'lateOrder' => $lateOrder, 'lateReceipt' => $lateReceipt];
        }
        $t = array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $t);
        $t['budget'] = round($budgetAmount, 2);
        $t['remainingBudget'] = round($budgetAmount - $t['committed'], 2);
        $t['progressPct'] = $t['plannedQty'] > 0 ? round($t['receivedQty'] / $t['plannedQty'] * 100, 2) : 0.0;

        return ['lines' => $out, 'totals' => $t];
    }

    // ───────────── rules ─────────────

    private function move(AuthUser $user, string $id, string $to, ?string $note, callable $before): array
    {
        DB::transaction(function () use ($user, $id, $to, $note, $before) {
            $plan = $this->locked($id);
            Owned::refuse($plan, 'plan');
            $from = $plan->status;
            if (! in_array($to, Plan::TRANSITIONS[$from] ?? [], true)) {
                throw AppError::rule('PLANNING_PLAN_TRANSITION', "لا يُنقل خطة من «{$from}» إلى «{$to}»", "A plan cannot move from {$from} to {$to}", ['from' => $from, 'to' => $to]);
            }
            $before($plan);
            $plan->forceFill(['status' => $to, 'updated_by' => $user->username])->save();
            $this->audit->status($user, 'PurchasePlan', $plan->id, $plan->number, $from, $to, $note);
        });

        return $this->get($id);
    }

    private function assertStatus(Plan $plan, array $states, string $ar, string $en): void
    {
        Owned::refuse($plan, 'plan');
        if (! in_array($plan->status, $states, true)) {
            throw AppError::rule('PLANNING_PLAN_STATE', $ar, $en, ['status' => $plan->status]);
        }
    }

    public function find(string $id): Plan
    {
        return Plan::where('id', $id)->orWhere('number', $id)->first() ?? throw AppError::notFound('PLANNING_PLAN_NOT_FOUND', 'خطة الشراء غير موجودة', 'Purchase plan not found');
    }

    private function locked(string $id): Plan
    {
        return Plan::whereKey($this->find($id)->id)->lockForUpdate()->first();
    }
}
