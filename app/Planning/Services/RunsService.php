<?php

namespace App\Planning\Services;

use App\Planning\Engine\Calendar;
use App\Planning\Models\Item;
use App\Planning\Models\Plan;
use App\Planning\Models\Run;
use App\Planning\Support\Clock;
use App\Planning\Support\Owned;
use App\Planning\Support\Shape;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;

/**
 * Forecast runs: their parameters and their way through the states
 *   draft ──compute──▶ computing ──(slices)──▶ computed ──freeze──▶ frozen
 *     ▲                    │ stop                  │ compute again / edit
 *     └────────────────────┴───────────────────────┘
 * A frozen run is immutable: never recomputed, never edited (its budget scenarios and plans go on). A run that came
 * from Odoo was computed there and is never recomputed here (`PLANNING_RUN_FROM_SOURCE`); while the section follows
 * Odoo it is read-only (`PLANNING_OWNED_BY_SOURCE`). A run from which a purchase plan was made (not cancelled) keeps
 * its figures: it can be frozen, not recomputed nor edited (`PLANNING_RUN_HAS_PLAN`). Nothing is deleted: a run is
 * archived (and restored).
 */
class RunsService
{
    /** The parameters as the API names them → their column. */
    public const FIELDS = [
        'name' => 'name', 'note' => 'note', 'anchorDate' => 'anchor_date', 'historyFrom' => 'history_from', 'historyTo' => 'history_to', 'forecastFrom' => 'forecast_from',
        'forecastTo' => 'forecast_to', 'periodKind' => 'period_kind', 'method' => 'method', 'wmaWindow' => 'wma_window', 'seasonalScale' => 'seasonal_scale', 'growthPct' => 'growth_pct',
        'demandNetting' => 'demand_netting', 'historySource' => 'history_source', 'includeReturns' => 'include_returns', 'includeOdoo' => 'include_odoo', 'includeOps' => 'include_ops',
        'safetyMethod' => 'safety_method', 'safetyDays' => 'safety_days', 'serviceLevel' => 'service_level', 'targetCoverDays' => 'target_cover_days', 'useVendorLeadTime' => 'use_vendor_lead_time',
        'defaultLeadDays' => 'default_lead_days', 'planningBufferDays' => 'planning_buffer_days', 'qtyMultipleSource' => 'qty_multiple_source', 'productSelection' => 'product_selection',
        'productIds' => 'product_ids', 'categoryIds' => 'category_ids', 'warehouseIds' => 'warehouse_ids', 'customerMode' => 'customer_mode', 'customerIds' => 'customer_ids',
        'excludeCustomerIds' => 'exclude_customer_ids', 'includeRollup' => 'include_rollup', 'rollupMethod' => 'rollup_method', 'includeHistorySheet' => 'include_history_sheet',
        'cadenceMinOrders' => 'cadence_min_orders', 'cadenceIntervalWindow' => 'cadence_interval_window', 'cadenceLapseFactor' => 'cadence_lapse_factor', 'cadenceUseTrend' => 'cadence_use_trend',
        'cadenceTrendCapPct' => 'cadence_trend_cap_pct', 'cadenceUseSeasonality' => 'cadence_use_seasonality', 'cadenceFallback' => 'cadence_fallback', 'budgetMonthlyAmount' => 'budget_monthly_amount',
    ];

    public const HISTORY_MAX_DAYS = 1830;

    public const HORIZON_MAX_DAYS = 731;

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly Computer $computer,
    ) {}

    // ───────────── reading ─────────────

    public function list(Paging $page, array $f): array
    {
        $q = Run::query();
        if (($f['active'] ?? 'yes') !== 'all') {
            $q->where('active', ($f['active'] ?? 'yes') === 'yes');
        }
        foreach (['status' => 'status', 'method' => 'method'] as $key => $column) {
            if (! empty($f[$key])) {
                $q->where($column, $f[$key]);
            }
        }
        if (! empty($f['origin'])) {
            $f['origin'] === 'odoo' ? $q->where('source_system', 'odoo') : $q->whereNull('source_system');
        }
        if ($page->q) {
            $like = '%'.$page->q.'%';
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('name', 'like', $like));
        }
        $sorts = ['createdAt' => 'created_at', 'number' => 'number', 'name' => 'name', 'computedAt' => 'computed_at', 'anchorDate' => 'anchor_date', 'itemCount' => 'item_count',
            'shortageItemCount' => 'shortage_item_count', 'totalSuggestedValue' => 'total_suggested_value', 'status' => 'status'];
        $q->orderBy($sorts[$page->sort ?? ''] ?? 'created_at', $page->order)->orderByDesc('id');

        return $page->paginate($q, fn (Run $r) => Shape::runHead($r));
    }

    public function get(string $id): array
    {
        $run = $this->find($id);

        return Shape::run($run) + ['plans' => Plan::where('run_id', $run->id)->orderBy('created_at')->get()->map(fn (Plan $p) => Shape::planHead($p))->all()];
    }

    public function items(string $id, Paging $page, array $f): array
    {
        $run = $this->find($id);
        $q = Item::where('run_id', $run->id)->where('kind', $f['kind'] ?? 'stock');
        foreach (['status' => 'status', 'warehouse' => 'warehouse_id', 'vendor' => 'vendor_id', 'criticality' => 'product_criticality', 'priceSource' => 'price_source', 'customer' => 'customer_id'] as $key => $column) {
            if (! empty($f[$key])) {
                $q->where($column, $f[$key]);
            }
        }
        if (isset($f['fallback']) && $f['fallback'] !== '') {
            $q->where('method_fallback', filter_var($f['fallback'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($f['buy'])) {
            $q->where('suggested_purchase_qty', '>', 0);
        }
        if (! empty($f['orderpoint'])) {
            $q->where('orderpoint_state', $f['orderpoint']);
        }
        if ($page->q) {
            $like = '%'.$page->q.'%';
            $q->where(fn ($w) => $w->where('sku', 'like', $like)->orWhere('product_name_ar', 'like', $like)->orWhere('product_name_en', 'like', $like)->orWhere('customer_name', 'like', $like)->orWhere('vendor_name', 'like', $like));
        }
        $sorts = ['sku' => 'sku', 'forecast' => 'forecast_total_qty', 'shortage' => 'shortage_qty', 'surplus' => 'surplus_qty', 'suggestedQty' => 'suggested_purchase_qty',
            'suggestedValue' => 'suggested_purchase_value', 'orderBy' => 'suggested_purchase_date', 'firstShortage' => 'first_shortage_date', 'coverage' => 'coverage_days',
            'onHand' => 'qty_on_hand', 'confidence' => 'confidence_score', 'warehouse' => 'warehouse_code'];
        $col = $sorts[$page->sort ?? ''] ?? null;
        if ($col) {
            in_array($col, ['suggested_purchase_date', 'first_shortage_date', 'coverage_days'], true) ? $q->orderByRaw("{$col} IS NULL")->orderBy($col, $page->order) : $q->orderBy($col, $page->order);
        } else {
            $q->orderByRaw("CASE status WHEN 'shortage' THEN 0 WHEN 'ok' THEN 1 WHEN 'surplus' THEN 2 ELSE 3 END")->orderBy('sku');
        }
        $q->orderBy('warehouse_code')->orderBy('id');
        $pushed = DB::table('planning_pushes')->where('run_id', $run->id)->pluck('at', 'product_id')->all();

        return $page->paginate($q, fn (Item $i) => Shape::item($i, $pushed[$i->product_id] ?? null));
    }

    public function item(string $runId, string $itemId): array
    {
        $run = $this->find($runId);
        $item = Item::where('run_id', $run->id)->whereKey($itemId)->first() ?? throw AppError::notFound('PLANNING_ITEM_NOT_FOUND', 'البند غير موجود في هذا التشغيل', 'Item not found in this run');
        $push = DB::table('planning_pushes')->where('run_id', $run->id)->where('product_id', $item->product_id)->orderByDesc('at')->first();

        return Shape::itemDetail($item, $push) + [
            'periods' => $item->periods()->orderBy('period_index')->get()->map(fn ($p) => Shape::period($p))->all(),
            'history' => $item->history()->orderBy('period_index')->get()->map(fn ($h) => Shape::history($h))->all(),
            'requirements' => $item->requirements()->orderBy('period_index')->get()->map(fn ($r) => Shape::requirement($r))->all(),
            'patterns' => $item->kind === 'stock' ? DB::table('planning_patterns')->where('run_id', $run->id)->where('product_id', $item->product_id)->where('warehouse_id', $item->warehouse_id)
                ->orderByDesc('forecast_qty')->orderBy('customer_name')->limit(200)->get()->map(fn ($p) => Shape::pattern($p))->all() : [],
            'customers' => $item->kind === 'stock' && $item->is_rollup ? Item::where('run_id', $run->id)->where('kind', 'customer')->where('product_id', $item->product_id)->where('warehouse_id', $item->warehouse_id)
                ->orderByDesc('forecast_total_qty')->limit(200)->get()->map(fn (Item $c) => Shape::item($c, null))->all() : [],
        ];
    }

    public function requirements(string $id, Paging $page, array $f): array
    {
        $run = $this->find($id);
        $q = DB::table('planning_requirements')->where('run_id', $run->id);
        foreach (['warehouse' => 'warehouse_id', 'vendor' => 'vendor_id', 'criticality' => 'product_criticality', 'priceSource' => 'price_source'] as $key => $column) {
            if (! empty($f[$key])) {
                $q->where($column, $f[$key]);
            }
        }
        if (isset($f['late']) && $f['late'] !== '') {
            $q->where('late', filter_var($f['late'], FILTER_VALIDATE_BOOLEAN));
        }
        if ($page->q) {
            $like = '%'.$page->q.'%';
            $q->where(fn ($w) => $w->where('sku', 'like', $like)->orWhere('product_name_ar', 'like', $like)->orWhere('product_name_en', 'like', $like)->orWhere('vendor_name', 'like', $like));
        }
        $sorts = ['orderBy' => 'trigger_date', 'needDate' => 'need_date', 'qty' => 'qty', 'value' => 'value', 'sku' => 'sku', 'shortage' => 'shortage_qty'];
        $q->orderBy($sorts[$page->sort ?? ''] ?? 'trigger_date', $page->sort ? $page->order : 'asc')->orderBy('sku')->orderBy('id');
        $total = (clone $q)->count();
        // the list's order means nothing to a sum, and PostgreSQL refuses an ORDER BY on columns a sum does not group
        $sum = (clone $q)->reorder()->selectRaw('COALESCE(SUM(qty), 0) AS q, COALESCE(SUM(value), 0) AS v')->first();

        return $page->wrap($q->forPage($page->page, $page->pageSize)->get()->map(fn ($r) => Shape::requirement($r))->all(), $total) + ['totals' => ['qty' => round((float) $sum->q, 3), 'value' => round((float) $sum->v, 2)]];
    }

    public function patterns(string $id, Paging $page, array $f): array
    {
        $run = $this->find($id);
        $q = DB::table('planning_patterns')->where('run_id', $run->id);
        foreach (['state' => 'state', 'band' => 'confidence_band', 'warehouse' => 'warehouse_id', 'customer' => 'customer_id'] as $key => $column) {
            if (! empty($f[$key])) {
                $q->where($column, $f[$key]);
            }
        }
        if ($page->q) {
            $like = '%'.$page->q.'%';
            $q->where(fn ($w) => $w->where('sku', 'like', $like)->orWhere('customer_name', 'like', $like));
        }
        $sorts = ['nextExpected' => 'next_expected_date', 'confidence' => 'confidence_score', 'forecast' => 'forecast_qty', 'orders' => 'order_count', 'overdue' => 'overdue_days', 'sku' => 'sku'];
        $col = $sorts[$page->sort ?? ''] ?? null;
        $col ? $q->orderByRaw("{$col} IS NULL")->orderBy($col, $page->order) : $q->orderByDesc('forecast_qty');
        $q->orderBy('sku')->orderBy('id');
        $total = (clone $q)->count();

        return $page->wrap($q->forPage($page->page, $page->pageSize)->get()->map(fn ($p) => Shape::pattern($p))->all(), $total);
    }

    public function events(string $id, Paging $page, array $f): array
    {
        $run = $this->find($id);
        $q = DB::table('planning_events as e')->join('planning_patterns as p', 'p.id', '=', 'e.pattern_id')->where('e.run_id', $run->id)
            ->select('e.*', 'p.sku', 'p.customer_name');
        foreach (['actual' => 'e.actual_state', 'pattern' => 'e.pattern_id', 'customer' => 'e.customer_id'] as $key => $column) {
            if (! empty($f[$key])) {
                $q->where($column, $f[$key]);
            }
        }
        if ($page->q) {
            $like = '%'.$page->q.'%';
            $q->where(fn ($w) => $w->where('p.sku', 'like', $like)->orWhere('p.customer_name', 'like', $like));
        }
        $q->orderBy('e.expected_date', $page->sort === 'expectedDate' ? $page->order : 'asc')->orderBy('p.sku')->orderBy('e.id');
        $total = (clone $q)->count();

        return $page->wrap($q->forPage($page->page, $page->pageSize)->get()->map(fn ($e) => Shape::event($e))->all(), $total);
    }

    // ───────────── changing ─────────────

    public function create(AuthUser $user, array $dto): array
    {
        $values = $this->validated(array_merge($this->defaults(), $this->columns($dto)));
        $run = DB::transaction(function () use ($user, $values) {
            $run = Run::create($values + ['number' => $this->numbering->next('DF'), 'status' => 'draft', 'active' => true, 'created_by' => $user->username, 'updated_by' => $user->username]);
            $this->audit->log($user, ['action' => 'PLANNING.RUN_CREATE', 'entityType' => 'PlanningRun', 'entityId' => $run->id, 'entityNumber' => $run->number, 'newValue' => Shape::parameters($run)]);
            $this->audit->status($user, 'PlanningRun', $run->id, $run->number, null, 'draft');

            return $run;
        });

        return $this->get($run->id);
    }

    public function update(AuthUser $user, string $id, array $dto): array
    {
        DB::transaction(function () use ($user, $id, $dto) {
            $run = $this->locked($id);
            $this->assertChangeable($run);
            if (! in_array($run->status, ['draft', 'computed'], true)) {
                throw AppError::rule('PLANNING_RUN_STATE', 'لا يُعدَّل التشغيل وهو قيد الحساب أو مجمّد', 'A run cannot be edited while it is computing or frozen', ['status' => $run->status]);
            }
            $old = Shape::parameters($run);
            $values = $this->validated(array_merge($run->only(array_values(self::FIELDS)), $this->columns($dto)));
            $run->fill($values + ['updated_by' => $user->username]);
            $from = $run->status;
            if ($from === 'computed') {
                $this->computer->reset($run);
                $run->forceFill(['status' => 'draft', 'work' => null, 'progress_done' => 0, 'progress_total' => 0, 'compute_started_at' => null])->save();
                $this->audit->status($user, 'PlanningRun', $run->id, $run->number, 'computed', 'draft', 'تعديل المعطيات أسقط نتائج الحساب السابق · Editing the parameters dropped the earlier results');
            }
            $run->save();
            $this->audit->log($user, ['action' => 'PLANNING.RUN_UPDATE', 'entityType' => 'PlanningRun', 'entityId' => $run->id, 'entityNumber' => $run->number, 'oldValue' => $old, 'newValue' => Shape::parameters($run)]);
        });

        return $this->get($id);
    }

    /** Starts (or starts again) the computation and pushes it on for a few seconds. */
    public function compute(AuthUser $user, string $id, float $seconds = 6.0): array
    {
        DB::transaction(function () use ($user, $id) {
            $run = $this->locked($id);
            $this->assertChangeable($run);
            if ($run->status === 'computing') {
                return; // already on its way: this call only pushes it on
            }
            if (! in_array($run->status, ['draft', 'computed'], true)) {
                throw AppError::rule('PLANNING_RUN_FROZEN', 'التشغيل مجمّد — لا يُعاد حسابه. انسخه إلى تشغيل جديد', 'The run is frozen — it is never recomputed. Copy it to a new run');
            }
            $this->validated($run->only(array_values(self::FIELDS)));
            $from = $run->status;
            $this->computer->reset($run);
            $this->audit->status($user, 'PlanningRun', $run->id, $run->number, $from, 'computing');
            $this->audit->log($user, ['action' => 'PLANNING.RUN_COMPUTE', 'entityType' => 'PlanningRun', 'entityId' => $run->id, 'entityNumber' => $run->number, 'newValue' => ['progressTotal' => $run->progress_total]]);
        });
        $this->computer->advance($this->find($id)->id, $seconds);

        return $this->get($id);
    }

    /** Pushes a computing run on (anyone who may compute; the command does the same without a person). */
    public function advance(string $id, float $seconds = 8.0): array
    {
        $run = $this->find($id);
        if ($run->status !== 'computing') {
            throw AppError::rule('PLANNING_RUN_NOT_COMPUTING', 'التشغيل ليس قيد الحساب', 'The run is not being computed', ['status' => $run->status]);
        }
        $this->computer->advance($run->id, $seconds);

        return $this->get($id);
    }

    /** Stops a computation: the run goes back to draft and what it wrote so far is dropped. */
    public function stop(AuthUser $user, string $id): array
    {
        DB::transaction(function () use ($user, $id) {
            $run = $this->locked($id);
            if ($run->status !== 'computing') {
                throw AppError::rule('PLANNING_RUN_NOT_COMPUTING', 'التشغيل ليس قيد الحساب', 'The run is not being computed', ['status' => $run->status]);
            }
            $this->computer->reset($run);
            $run->forceFill(['status' => 'draft', 'work' => null, 'progress_done' => 0, 'progress_total' => 0, 'compute_started_at' => null, 'lease_until' => null, 'lease_token' => null])->save();
            $this->audit->status($user, 'PlanningRun', $run->id, $run->number, 'computing', 'draft', 'أُوقف الحساب · Computation stopped');
        });

        return $this->get($id);
    }

    public function freeze(AuthUser $user, string $id, ?string $note): array
    {
        DB::transaction(function () use ($user, $id, $note) {
            $run = $this->locked($id);
            Owned::refuse($run, 'run');
            if ($run->status !== 'computed') {
                throw AppError::rule('PLANNING_RUN_TRANSITION', 'لا يُجمَّد إلا تشغيل محسوب', 'Only a computed run can be frozen', ['status' => $run->status]);
            }
            $run->forceFill(['status' => 'frozen', 'frozen_at' => now(), 'frozen_by' => $user->username, 'updated_by' => $user->username])->save();
            $this->audit->status($user, 'PlanningRun', $run->id, $run->number, 'computed', 'frozen', $note);
        });

        return $this->get($id);
    }

    public function archive(AuthUser $user, string $id, bool $active, ?string $reason): array
    {
        DB::transaction(function () use ($user, $id, $active, $reason) {
            $run = $this->locked($id);
            Owned::refuse($run, 'run');
            if ($run->status === 'computing' && ! $active) {
                throw AppError::rule('PLANNING_RUN_COMPUTING', 'أوقف الحساب قبل أرشفة التشغيل', 'Stop the computation before archiving the run');
            }
            if ((bool) $run->active === $active) {
                return;
            }
            $run->forceFill(['active' => $active, 'updated_by' => $user->username])->save();
            $this->audit->log($user, ['action' => $active ? 'PLANNING.RUN_RESTORE' : 'PLANNING.RUN_ARCHIVE', 'entityType' => 'PlanningRun', 'entityId' => $run->id, 'entityNumber' => $run->number,
                'oldValue' => ['active' => ! $active], 'newValue' => ['active' => $active] + ($reason ? ['reason' => $reason] : [])]);
        });

        return $this->get($id);
    }

    /** A new draft run with the parameters of another (the way to "recompute" a frozen run). */
    public function copy(AuthUser $user, string $id): array
    {
        $src = $this->find($id);
        $values = $src->only(array_values(self::FIELDS));
        $values['name'] = mb_substr('نسخة من '.$src->number.' · '.($src->name ?? ''), 0, 190);
        $run = DB::transaction(function () use ($user, $src, $values) {
            $run = Run::create($values + ['number' => $this->numbering->next('DF'), 'status' => 'draft', 'active' => true, 'created_by' => $user->username, 'updated_by' => $user->username]);
            $this->audit->log($user, ['action' => 'PLANNING.RUN_COPY', 'entityType' => 'PlanningRun', 'entityId' => $run->id, 'entityNumber' => $run->number, 'newValue' => ['from' => $src->number]]);
            $this->audit->status($user, 'PlanningRun', $run->id, $run->number, null, 'draft');

            return $run;
        });

        return $this->get($run->id);
    }

    // ───────────── rules ─────────────

    private function assertChangeable(Run $run): void
    {
        Owned::refuse($run, 'run');
        if (Owned::fromSource($run)) {
            throw AppError::rule('PLANNING_RUN_FROM_SOURCE', 'هذا التشغيل حُسب في أودو — لا يُعاد حسابه هنا. انسخه إلى تشغيل جديد', 'This run was computed in Odoo — it is never recomputed here. Copy it to a new run');
        }
        if ($run->status === 'frozen') {
            throw AppError::rule('PLANNING_RUN_FROZEN', 'التشغيل مجمّد — لا يُعدَّل ولا يُعاد حسابه. انسخه إلى تشغيل جديد', 'The run is frozen — it is never edited nor recomputed. Copy it to a new run');
        }
        if (Plan::where('run_id', $run->id)->where('status', '!=', 'cancel')->exists()) {
            throw AppError::rule('PLANNING_RUN_HAS_PLAN', 'صُنعت خطة شراء من هذا التشغيل — تبقى أرقامه كما هي. انسخه إلى تشغيل جديد', 'A purchase plan was made from this run — its figures stay as they are. Copy it to a new run');
        }
    }

    /** @return array<string, mixed> the parameters of a new run before a person changes any */
    private function defaults(): array
    {
        $today = Clock::today();

        return [
            'name' => null, 'note' => null, 'anchor_date' => $today, 'history_from' => Calendar::add($today, -365), 'history_to' => Calendar::add($today, -1), 'forecast_from' => $today,
            'forecast_to' => Calendar::monthEnd(Calendar::add(Calendar::monthStart($today), 62)), 'period_kind' => 'month', 'method' => 'wma', 'wma_window' => 3, 'seasonal_scale' => true,
            'growth_pct' => 0, 'demand_netting' => 'net', 'history_source' => 'delivery', 'include_returns' => true, 'include_odoo' => true, 'include_ops' => true, 'safety_method' => 'days',
            'safety_days' => 7, 'service_level' => '95', 'target_cover_days' => 30, 'use_vendor_lead_time' => true, 'default_lead_days' => 7, 'planning_buffer_days' => 0,
            'qty_multiple_source' => 'one', 'product_selection' => 'moving', 'product_ids' => [], 'category_ids' => [], 'warehouse_ids' => DB::table('warehouses')->where('active', true)->orderBy('code')->pluck('id')->map(fn ($v) => (string) $v)->all(),
            'customer_mode' => 'none', 'customer_ids' => [], 'exclude_customer_ids' => [], 'include_rollup' => true, 'rollup_method' => 'pooled', 'include_history_sheet' => false,
            'cadence_min_orders' => 3, 'cadence_interval_window' => 6, 'cadence_lapse_factor' => 3, 'cadence_use_trend' => true, 'cadence_trend_cap_pct' => 25, 'cadence_use_seasonality' => false,
            'cadence_fallback' => 'estimate', 'budget_monthly_amount' => 0,
        ];
    }

    private function columns(array $dto): array
    {
        $out = [];
        foreach (self::FIELDS as $key => $column) {
            if (array_key_exists($key, $dto)) {
                $out[$column] = $dto[$key];
            }
        }

        return $out;
    }

    /** The rules that hold between the parameters (the controller checked each one alone). */
    private function validated(array $v): array
    {
        $day = fn ($x) => $x instanceof \DateTimeInterface ? $x->format('Y-m-d') : substr((string) $x, 0, 10);
        foreach (['anchor_date', 'history_from', 'history_to', 'forecast_from', 'forecast_to'] as $c) {
            $v[$c] = $day($v[$c]);
            if (! Calendar::valid($v[$c])) {
                throw AppError::validation('PLANNING_DATE', 'تاريخ غير صالح', 'Invalid date', ['field' => $c]);
            }
        }
        $fail = fn (string $ar, string $en, array $d = []) => throw AppError::validation('PLANNING_WINDOW', $ar, $en, $d);
        if ($v['history_from'] > $v['history_to']) {
            $fail('بداية السجل بعد نهايته', 'The history starts after it ends');
        }
        if ($v['forecast_from'] > $v['forecast_to']) {
            $fail('بداية التوقع بعد نهايته', 'The forecast starts after it ends');
        }
        if ($v['history_to'] >= $v['forecast_from']) {
            $fail('فترة السجل يجب أن تنتهي قبل بداية التوقع', 'The history must end before the forecast starts');
        }
        if ($v['anchor_date'] > $v['forecast_from']) {
            $fail('تاريخ الإسناد (اليوم الذي يُحسب منه) يجب ألا يكون بعد بداية التوقع', 'The anchor day must not be after the forecast starts');
        }
        if (Calendar::span($v['history_from'], $v['history_to']) > self::HISTORY_MAX_DAYS) {
            $fail('فترة السجل أطول من خمس سنوات', 'The history is longer than five years');
        }
        if (Calendar::span($v['forecast_from'], $v['forecast_to']) > self::HORIZON_MAX_DAYS) {
            $fail('فترة التوقع أطول من سنتين', 'The forecast is longer than two years');
        }
        $ids = fn ($x) => array_values(array_unique(array_map('strval', array_filter((array) $x, fn ($i) => $i !== null && $i !== ''))));
        foreach (['product_ids', 'category_ids', 'warehouse_ids', 'customer_ids', 'exclude_customer_ids'] as $c) {
            $v[$c] = $ids($v[$c] ?? []);
        }
        if (! $v['warehouse_ids']) {
            throw AppError::validation('PLANNING_WAREHOUSES', 'اختر مستودعًا واحدًا على الأقل', 'Choose at least one warehouse');
        }
        $known = fn (string $table, array $list) => count($list) === 0 || DB::table($table)->whereIn('id', $list)->count() === count($list);
        if (! $known('warehouses', $v['warehouse_ids']) || ! $known('products', $v['product_ids']) || ! $known('product_categories', $v['category_ids'])
            || ! $known('customers', $v['customer_ids']) || ! $known('customers', $v['exclude_customer_ids'])) {
            throw AppError::validation('PLANNING_UNKNOWN_REF', 'مستودع أو منتج أو فئة أو عميل غير موجود', 'A warehouse, product, category or customer does not exist');
        }
        if ($v['product_selection'] === 'manual' && ! $v['product_ids'] && ! $v['category_ids']) {
            throw AppError::validation('PLANNING_PRODUCTS', 'اختر منتجات أو فئات للتحديد اليدوي', 'Choose products or categories for a manual selection');
        }
        if ($v['customer_mode'] === 'filter' && ! $v['customer_ids']) {
            throw AppError::validation('PLANNING_CUSTOMERS', 'اختر العملاء الذين يُحسب طلبهم', 'Choose the customers whose demand is counted');
        }
        if (! $v['include_ops'] && ! $v['include_odoo']) {
            throw AppError::validation('PLANNING_SOURCES', 'اختر مصدرًا واحدًا للسجل على الأقل (OPS أو أودو)', 'Choose at least one history source (OPS or Odoo)');
        }
        $v['service_level'] = $v['service_level'] === null ? null : (string) $v['service_level'];
        $v['budget_monthly_amount'] = round(max(0.0, (float) ($v['budget_monthly_amount'] ?? 0)), 2);

        return $v;
    }

    public function find(string $id): Run
    {
        return Run::where('id', $id)->orWhere('number', $id)->first() ?? throw AppError::notFound('PLANNING_RUN_NOT_FOUND', 'تشغيل التوقع غير موجود', 'Forecast run not found');
    }

    private function locked(string $id): Run
    {
        return Run::whereKey($this->find($id)->id)->lockForUpdate()->first();
    }
}
