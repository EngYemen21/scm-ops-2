<?php

namespace App\Planning\Services;

use App\Planning\Engine\Allocator;
use App\Planning\Engine\Calendar;
use App\Planning\Engine\Num;
use App\Planning\Models\Budget;
use App\Planning\Models\BudgetLine;
use App\Planning\Models\Plan;
use App\Planning\Models\Run;
use App\Planning\Support\Owned;
use App\Planning\Support\Shape;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Budget scenarios of a run: money per month, the weights of the priority and the rules of the allocation — and the
 * allocation itself (App\Planning\Engine\Allocator), written one row per decision. A scenario is `draft` (not allocated)
 * or `allocated`; any change of its money or rules takes it back to `draft` and drops the earlier allocation — unless a
 * purchase plan that is not cancelled was made from it (`PLANNING_BUDGET_HAS_PLAN`): a plan keeps the allocation it was
 * made from. A run's "monthly budget" makes its own scenario (`is_primary`), allocated when the run is computed.
 */
class BudgetsService
{
    public const FLAGS = ['criticalFirst' => 'critical_first', 'allowPartial' => 'allow_partial', 'carryDeferred' => 'carry_deferred', 'carryUnused' => 'carry_unused'];

    public function __construct(private readonly AuditService $audit) {}

    public function list(Run $run): array
    {
        return Budget::where('run_id', $run->id)->with('lines')->orderByDesc('is_primary')->orderBy('created_at')->orderBy('id')->get()->map(fn (Budget $b) => Shape::budget($b))->all();
    }

    public function get(string $id): array
    {
        $b = $this->find($id);
        $b->load('lines');

        return Shape::budget($b) + ['run' => Shape::runHead($b->run), 'plans' => Plan::where('budget_id', $b->id)->orderBy('created_at')->get()->map(fn (Plan $p) => Shape::planHead($p))->all()];
    }

    public function allocations(string $id, Paging $page, array $f): array
    {
        $b = $this->find($id);
        $q = DB::table('planning_allocations as a')->join('planning_requirements as r', 'r.id', '=', 'a.requirement_id')->where('a.budget_id', $b->id)
            ->select('a.*', 'r.sku', 'r.product_name_ar', 'r.product_name_en', 'r.warehouse_code', 'r.vendor_name', 'r.product_criticality', 'r.need_date', 'r.price_unit', 'r.period_label');
        if (! empty($f['status'])) {
            $q->where('a.status', $f['status']);
        }
        if (! empty($f['month'])) {
            $q->where('a.month', $f['month'].'-01');
        }
        if ($page->q) {
            $like = '%'.$page->q.'%';
            $q->where(fn ($w) => $w->where('r.sku', 'like', $like)->orWhere('r.product_name_ar', 'like', $like)->orWhere('r.product_name_en', 'like', $like)->orWhere('r.vendor_name', 'like', $like));
        }
        $sorts = ['rank' => 'a.rank', 'score' => 'a.score', 'month' => 'a.month', 'fundedValue' => 'a.funded_value', 'requiredValue' => 'a.required_value', 'orderDate' => 'a.order_date', 'sku' => 'r.sku'];
        $col = $sorts[$page->sort ?? ''] ?? null;
        if ($col) {
            $q->orderBy($col, $page->order);
        } else {
            $q->orderByRaw('a.month IS NULL')->orderBy('a.month')->orderByRaw('a.rank = 0')->orderBy('a.rank');
        }
        $q->orderBy('a.id');
        $total = (clone $q)->count();
        $rows = $q->forPage($page->page, $page->pageSize)->get();

        return $page->wrap($rows->map(fn ($a) => Shape::allocation($a))->all(), $total);
    }

    public function create(AuthUser $user, Run $run, array $dto): array
    {
        Owned::refuse($run, 'run');
        if (! in_array($run->status, ['computed', 'frozen', 'draft', 'computing'], true)) {
            throw AppError::rule('PLANNING_RUN_STATE', 'لا تُضاف سيناريوهات لهذا التشغيل', 'Budget scenarios cannot be added to this run');
        }
        $b = DB::transaction(function () use ($user, $run, $dto) {
            $b = Budget::create(['run_id' => $run->id, 'name' => $dto['name'], 'is_primary' => false, 'status' => 'draft', 'note' => $dto['note'] ?? null, 'created_by' => $user->username, 'updated_by' => $user->username]
                + $this->rules($dto, true));
            $this->writeLines($b, $dto['lines'] ?? $this->defaultLines($run, (float) ($dto['monthlyAmount'] ?? $run->budget_monthly_amount ?? 0)));
            $this->audit->log($user, ['action' => 'PLANNING.BUDGET_CREATE', 'entityType' => 'PlanningBudget', 'entityId' => $b->id, 'entityNumber' => $run->number, 'newValue' => ['name' => $b->name, 'total' => $this->total($b)]]);

            return $b;
        });

        return $this->get($b->id);
    }

    public function update(AuthUser $user, string $id, array $dto): array
    {
        DB::transaction(function () use ($user, $id, $dto) {
            $b = Budget::whereKey($this->find($id)->id)->lockForUpdate()->first();
            $this->assertEditable($b);
            $old = Shape::budgetRules($b);
            $b->fill(array_filter(['name' => $dto['name'] ?? null], fn ($v) => $v !== null) + (array_key_exists('note', $dto) ? ['note' => $dto['note']] : []) + $this->rules($dto, false));
            $b->updated_by = $user->username;
            self::assertWeights($b);
            $b->save();
            if (array_key_exists('lines', $dto)) {
                $this->writeLines($b, $dto['lines']);
            }
            $this->unallocate($b);
            $this->audit->log($user, ['action' => 'PLANNING.BUDGET_UPDATE', 'entityType' => 'PlanningBudget', 'entityId' => $b->id, 'entityNumber' => $b->run->number, 'oldValue' => $old, 'newValue' => Shape::budgetRules($b->fresh())]);
        });

        return $this->get($id);
    }

    /** Allocates the scenario over the run's requirements. Deterministic: the same requirements and rules give the same rows. */
    public function allocate(?AuthUser $user, string $id): array
    {
        DB::transaction(function () use ($user, $id) {
            $b = Budget::whereKey($this->find($id)->id)->lockForUpdate()->first();
            $this->assertEditable($b);
            $run = Run::whereKey($b->run_id)->first();
            if (! in_array($run->status, ['computed', 'frozen'], true)) {
                throw AppError::rule('PLANNING_RUN_NOT_COMPUTED', 'احسب التشغيل أولًا — لا متطلبات شراء بعد', 'Compute the run first — there are no purchase requirements yet');
            }
            $this->run($b, $run, $user);
        });

        return $this->get($id);
    }

    /** The run's own scenario from its monthly budget: made (or its months refreshed) and allocated. */
    public function runBudget(Run $run): void
    {
        $b = Budget::where('run_id', $run->id)->where('is_primary', true)->first();
        if ($b && Plan::where('budget_id', $b->id)->where('status', '!=', 'cancel')->exists()) {
            return; // a plan was made from it: it keeps its allocation
        }
        if (! $b) {
            $b = Budget::create(['run_id' => $run->id, 'name' => 'ميزانية التشغيل · Run budget', 'is_primary' => true, 'status' => 'draft', 'created_by' => 'system', 'updated_by' => 'system'] + $this->rules([], true));
        }
        $this->writeLines($b, $this->defaultLines($run, (float) $run->budget_monthly_amount));
        $this->run($b, $run, null);
    }

    private function run(Budget $b, Run $run, ?AuthUser $user): void
    {
        $lines = BudgetLine::where('budget_id', $b->id)->orderBy('month')->get();
        $reqs = DB::table('planning_requirements')->where('run_id', $run->id)->orderBy('id')->get();
        $input = [];
        foreach ($reqs as $r) {
            $input[] = [
                'id' => (string) $r->id, 'sku' => (string) $r->sku, 'warehouse' => (string) $r->warehouse_code, 'qty' => (float) $r->qty, 'price' => (float) ($r->price_unit ?? 0),
                'orderBy' => substr((string) $r->trigger_date, 0, 10), 'needDate' => substr((string) $r->need_date, 0, 10), 'leadDays' => (float) $r->lead_days, 'bufferDays' => (float) $r->buffer_days,
                'net' => (float) $r->net_qty, 'stockout' => (float) $r->shortage_qty, 'criticality' => (string) ($r->product_criticality ?: 'normal'), 'velocity' => (float) $r->velocity_qty,
                'confidence' => (float) $r->confidence_score, 'customerScore' => (float) $r->customer_score, 'moq' => (float) $r->moq, 'multiple' => (float) $r->qty_multiple,
            ];
        }
        $weights = [];
        foreach (Budget::WEIGHTS as $key => $column) {
            $weights[$key] = (int) $b->getAttribute($column);
        }
        $out = Allocator::run($input, $lines->map(fn (BudgetLine $l) => ['month' => $l->month->format('Y-m-d'), 'amount' => (float) $l->amount])->all(), [
            'anchor' => $run->day('anchor_date'), 'weights' => $weights, 'criticalFirst' => (bool) $b->critical_first, 'allowPartial' => (bool) $b->allow_partial,
            'carryDeferred' => (bool) $b->carry_deferred, 'carryUnused' => (bool) $b->carry_unused, 'urgencyHorizonDays' => (int) $b->urgency_horizon_days,
        ]);
        DB::table('planning_allocations')->where('budget_id', $b->id)->delete();
        $lineIds = $lines->keyBy(fn (BudgetLine $l) => $l->month->format('Y-m-d'))->map(fn ($l) => $l->id)->all();
        $rows = [];
        foreach ($out['rows'] as $a) {
            $rows[] = [
                'id' => (string) Str::ulid(), 'budget_id' => $b->id, 'run_id' => $run->id, 'budget_line_id' => $a['month'] ? ($lineIds[$a['month']] ?? null) : null, 'requirement_id' => $a['requirementId'],
                'month' => $a['month'], 'order_date' => $a['orderDate'], 'rank' => $a['rank'], 'must_fund' => $a['mustFund'], 'score' => $a['score'],
                'score_urgency' => $a['scores']['urgency'], 'score_stockout' => $a['scores']['stockout'], 'score_criticality' => $a['scores']['criticality'], 'score_velocity' => $a['scores']['velocity'],
                'score_confidence' => $a['scores']['confidence'], 'score_customer' => $a['scores']['customer'], 'score_lead_time' => $a['scores']['leadTime'],
                'required_qty' => $a['requiredQty'], 'funded_qty' => $a['fundedQty'], 'required_value' => $a['requiredValue'], 'funded_value' => $a['fundedValue'], 'status' => $a['status'],
                'carried_from' => $a['carriedFrom'], 'at_risk' => $a['atRisk'], 'reason_code' => $a['reason']['code'] ?? null, 'reason_ar' => $a['reason']['ar'] ?? null, 'reason_en' => $a['reason']['en'] ?? null,
            ];
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('planning_allocations')->insert($chunk);
        }
        foreach ($out['months'] as $m) {
            BudgetLine::where('budget_id', $b->id)->where('month', $m['month'])->update(['carried_in' => $m['carriedIn'], 'required_value' => $m['due'], 'funded_value' => $m['funded'], 'remaining_value' => $m['remaining']]);
        }
        $t = $out['totals'];
        $b->forceFill([
            'status' => 'allocated', 'allocated_at' => now(), 'allocated_by' => $user?->username ?? 'system', 'budget_total' => $t['budgetTotal'], 'required_value' => $t['required'], 'funded_value' => $t['funded'],
            'deferred_value' => $t['deferred'], 'funded_pct' => $t['fundedPct'], 'unused_value' => $t['unused'], 'stockout_unfunded_value' => $t['stockoutUnfunded'], 'requirement_count' => $t['requirements'],
            'funded_count' => $t['fundedCount'], 'partial_count' => $t['partialCount'], 'deferred_count' => $t['deferredCount'], 'critical_deferred_count' => $t['criticalDeferred'], 'at_risk_count' => $t['atRisk'],
        ])->save();
        $this->audit->log($user, ['action' => 'PLANNING.BUDGET_ALLOCATE', 'entityType' => 'PlanningBudget', 'entityId' => $b->id, 'entityNumber' => $run->number,
            'newValue' => ['required' => $t['required'], 'funded' => $t['funded'], 'fundedPct' => $t['fundedPct'], 'requirements' => $t['requirements'], 'rows' => count($rows)]]);
    }

    private function assertEditable(Budget $b): void
    {
        Owned::refuse($b, 'budget');
        if (Plan::where('budget_id', $b->id)->where('status', '!=', 'cancel')->exists()) {
            throw AppError::rule('PLANNING_BUDGET_HAS_PLAN', 'صُنعت خطة شراء من هذا السيناريو — ألغِ الخطة أولًا أو أنشئ سيناريو جديدًا', 'A purchase plan was made from this scenario — cancel the plan first or make a new scenario');
        }
    }

    private function unallocate(Budget $b): void
    {
        DB::table('planning_allocations')->where('budget_id', $b->id)->delete();
        BudgetLine::where('budget_id', $b->id)->update(['carried_in' => 0, 'required_value' => 0, 'funded_value' => 0, 'remaining_value' => 0]);
        $b->forceFill(['status' => 'draft', 'allocated_at' => null, 'allocated_by' => null, 'budget_total' => $this->total($b), 'required_value' => 0, 'funded_value' => 0, 'deferred_value' => 0, 'funded_pct' => 0,
            'unused_value' => 0, 'stockout_unfunded_value' => 0, 'requirement_count' => 0, 'funded_count' => 0, 'partial_count' => 0, 'deferred_count' => 0, 'critical_deferred_count' => 0, 'at_risk_count' => 0])->save();
    }

    /** @return array<string, mixed> the columns of the rules given in a request (all of them, with defaults, for a new scenario) */
    private function rules(array $dto, bool $defaults): array
    {
        $out = [];
        foreach (Budget::WEIGHTS as $key => $column) {
            if (isset($dto['weights'][$key])) {
                $out[$column] = (int) $dto['weights'][$key];
            } elseif ($defaults) {
                $out[$column] = Budget::DEFAULT_WEIGHTS[$key];
            }
        }
        foreach (self::FLAGS as $key => $column) {
            if (array_key_exists($key, $dto)) {
                $out[$column] = (bool) $dto[$key];
            } elseif ($defaults) {
                $out[$column] = true;
            }
        }
        if (isset($dto['urgencyHorizonDays'])) {
            $out['urgency_horizon_days'] = (int) $dto['urgencyHorizonDays'];
        } elseif ($defaults) {
            $out['urgency_horizon_days'] = 30;
        }
        if ($defaults && array_sum(array_map(fn ($c) => (int) ($out[$c] ?? 0), Budget::WEIGHTS)) <= 0) {
            throw AppError::validation('PLANNING_WEIGHTS_ZERO', 'وزن واحد على الأقل يجب أن يكون أكبر من صفر', 'At least one weight must be above zero');
        }

        return $out;
    }

    private static function assertWeights(Budget $b): void
    {
        if (array_sum(array_map(fn ($c) => (int) $b->getAttribute($c), Budget::WEIGHTS)) <= 0) {
            throw AppError::validation('PLANNING_WEIGHTS_ZERO', 'وزن واحد على الأقل يجب أن يكون أكبر من صفر', 'At least one weight must be above zero');
        }
    }

    /** @return array<int, array{month:string, amount:float}> */
    private function defaultLines(Run $run, float $amount): array
    {
        return array_map(fn ($m) => ['month' => substr($m, 0, 7), 'amount' => $amount], Calendar::months($run->day('forecast_from'), $run->day('forecast_to')));
    }

    /** Replaces the months of a scenario (the money is exact: two decimals, rounded once). */
    private function writeLines(Budget $b, array $lines): void
    {
        $months = [];
        foreach ($lines as $l) {
            $month = substr((string) $l['month'], 0, 7).'-01';
            if (! Calendar::valid($month)) {
                throw AppError::validation('PLANNING_BUDGET_MONTH', 'شهر غير صالح في الميزانية', 'Invalid budget month', ['month' => $l['month']]);
            }
            if (isset($months[$month])) {
                throw AppError::validation('PLANNING_BUDGET_MONTH_TWICE', 'الشهر مكرر في الميزانية', 'A month appears twice in the budget', ['month' => substr($month, 0, 7)]);
            }
            $months[$month] = Num::m(max(0.0, (float) $l['amount']));
        }
        ksort($months);
        BudgetLine::where('budget_id', $b->id)->delete();
        foreach ($months as $month => $amount) {
            BudgetLine::create(['budget_id' => $b->id, 'month' => $month, 'amount' => $amount, 'carried_in' => 0, 'required_value' => 0, 'funded_value' => 0, 'remaining_value' => 0]);
        }
        $b->forceFill(['budget_total' => Num::m(array_sum($months))])->save();
    }

    private function total(Budget $b): float
    {
        return Num::m((float) BudgetLine::where('budget_id', $b->id)->sum('amount'));
    }

    public function find(string $id): Budget
    {
        return Budget::whereKey($id)->first() ?? throw AppError::notFound('PLANNING_BUDGET_NOT_FOUND', 'سيناريو الميزانية غير موجود', 'Budget scenario not found');
    }
}
