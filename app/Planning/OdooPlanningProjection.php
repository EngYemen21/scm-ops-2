<?php

namespace App\Planning;

use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Odoo\Projection\Val;
use App\Planning\Support\Refs;
use App\Support\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * From the copy of Odoo's "B2B Demand Forecast & Replenishment Planning" to the planning section: the twelve models of
 * `docs/odoo/shapes/unreadable.txt` (runs, items, periods, history, customer patterns, expected orders, purchase
 * requirements, budget scenarios with their months and allocations, purchase plans with their lines) become rows of
 * the planning tables, marked `source_system = 'odoo'`, `external_ref` = the Odoo id. Idempotent: a record whose
 * values did not change (`source_hash`) is left alone.
 *
 * TODAY THE ODOO USER CANNOT READ THESE MODELS: the copy holds none of their records, so a pass finds nothing. The day
 * the access is given, the mirror copies them and this projection loads them — nothing else has to change.
 *
 * WHAT IS ODOO'S: everything such a record holds — a run computed in Odoo is never recomputed here (RunsService), and
 * while the section follows Odoo nothing of it is edited (`PLANNING_OWNED_BY_SOURCE`).
 * LINKING by identity only: a product, a customer, a vendor through `int_external_refs`; a warehouse through the
 * mapping a person made; a category through the link of the master data. What has no OPS counterpart keeps Odoo's
 * label (SKU, names) and the Odoo id in `refs`.
 * A record deleted in Odoo: a run, a budget scenario or a plan is archived / marked (`source_deleted_at`); a computed
 * row of it (item, period …) goes with it, as in Odoo.
 */
class OdooPlanningProjection extends OdooProjection
{
    /** Odoo model → its table, parents first. */
    public const TABLES = [
        'b2b.demand.forecast' => 'planning_runs',
        'b2b.demand.forecast.item' => 'planning_items',
        'b2b.demand.forecast.line' => 'planning_periods',
        'b2b.demand.forecast.history' => 'planning_history',
        'b2b.demand.forecast.pattern' => 'planning_patterns',
        'b2b.demand.forecast.event' => 'planning_events',
        'b2b.demand.forecast.requirement' => 'planning_requirements',
        'b2b.demand.forecast.budget' => 'planning_budgets',
        'b2b.demand.forecast.budget.line' => 'planning_budget_lines',
        'b2b.demand.forecast.allocation' => 'planning_allocations',
        'b2b.purchase.plan' => 'planning_plans',
        'b2b.purchase.plan.line' => 'planning_plan_lines',
    ];

    /** The heads: archived, not removed, when Odoo deletes them. */
    private const HEADS = ['planning_runs', 'planning_budgets', 'planning_plans'];

    private Refs $ids;

    private bool $installed = true;

    /** @var array<string, array<string, string>> table → external_ref → OPS id (what a pass made or found) */
    private array $known = [];

    public function key(): string
    {
        return 'planning';
    }

    public function models(): array
    {
        return array_keys(self::TABLES);
    }

    protected function begin(): void
    {
        $this->installed = Schema::hasTable('planning_runs');
        if (! $this->installed && ! $this->dry) {
            throw AppError::rule('PLANNING_NOT_INSTALLED', 'جداول قسم تخطيط الطلب لم تُنشأ بعد — شغّل الترحيل أولًا (php artisan migrate)', 'The tables of the planning section do not exist yet — run the migration first');
        }
        $this->ids = new Refs;
        $this->known = [];
    }

    protected function project(OdooRecord $r): void
    {
        $table = self::TABLES[$r->model] ?? null;
        if (! $table) {
            return;
        }
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $existing = $this->installed ? DB::table($table)->where('source_system', self::SYSTEM)->where('external_ref', $ref)->first(['id', 'source_hash']) : null;
        if ($r->deleted_at !== null) {
            if (! $existing) {
                $this->count('skippedDeleted');

                return;
            }
            if (! $this->dry) {
                in_array($table, self::HEADS, true)
                    ? DB::table($table)->where('id', $existing->id)->update(['source_deleted_at' => now()] + ($table === 'planning_runs' ? ['active' => false] : []))
                    : DB::table($table)->where('id', $existing->id)->delete();
            }
            $this->count('archived');

            return;
        }
        $values = $this->values($r->model, $d, $r);
        if ($values === null) {
            return; // counted by values()
        }
        $hash = sha1(json_encode($d));
        if ($existing && $existing->source_hash === $hash) {
            $this->known[$table][$ref] = (string) $existing->id;
            $this->count('unchanged');

            return;
        }
        $values += ['source_system' => self::SYSTEM, 'external_ref' => $ref, 'source_hash' => $hash];
        if (in_array($table, self::HEADS, true)) {
            $values['source_updated_at'] = Val::at($d['write_date'] ?? null);
        }
        foreach ($values as $k => $v) {
            if (is_array($v)) {
                $values[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
        }
        if ($this->dry) {
            $this->known[$table][$ref] = $existing ? (string) $existing->id : 'planned:'.$ref;
            $this->count($existing ? 'updated' : 'created');

            return;
        }
        if ($existing) {
            DB::table($table)->where('id', $existing->id)->update($values);
            $this->known[$table][$ref] = (string) $existing->id;
            $this->count('updated');
        } else {
            $id = (string) Str::ulid();
            DB::table($table)->insert(['id' => $id] + $values + $this->stamps($table));
            $this->known[$table][$ref] = $id;
            $this->count('created');
        }
    }

    private function stamps(string $table): array
    {
        return match ($table) {
            'planning_runs', 'planning_budgets', 'planning_plans', 'planning_plan_lines' => ['created_at' => now(), 'updated_at' => now(), 'created_by' => 'svc.odoo'],
            'planning_items', 'planning_patterns', 'planning_requirements' => ['created_at' => now()],
            default => [],
        };
    }

    /** The OPS id of a projected parent (made in this pass or earlier). */
    private function parent(string $model, mixed $value): ?string
    {
        $id = Val::id($value);
        if (! $id) {
            return null;
        }
        $table = self::TABLES[$model];
        if (isset($this->known[$table][(string) $id])) {
            return $this->known[$table][(string) $id];
        }
        $found = $this->installed ? DB::table($table)->where('source_system', self::SYSTEM)->where('external_ref', (string) $id)->value('id') : null;

        return $found ? ($this->known[$table][(string) $id] = (string) $found) : null;
    }

    private function needParent(string $what, ?string $id, OdooRecord $r): bool
    {
        if ($id !== null) {
            return true;
        }
        $this->count('skippedNoParent', "{$r->model} #{$r->odoo_id}: {$what}");

        return false;
    }

    /** @return array<string, mixed>|null the columns of the row, or null when it cannot be placed */
    private function values(string $model, array $d, OdooRecord $r): ?array
    {
        $f = fn (string $k) => isset($d[$k]) && is_numeric($d[$k]) ? (float) $d[$k] : null;
        $i = fn (string $k) => isset($d[$k]) && is_numeric($d[$k]) ? (int) $d[$k] : null;
        $b = fn (string $k) => Val::bool($d[$k] ?? false);
        $day = fn (string $k) => Val::date($d[$k] ?? null);
        $sel = fn (string $k, array $allowed, ?string $default = null) => in_array($d[$k] ?? null, $allowed, true) ? $d[$k] : $default;
        $product = fn (string $k = 'product_id') => $this->ids->link('product', Val::id($d[$k] ?? null));
        $warehouse = fn (string $k = 'warehouse_id') => $this->ids->warehouse(Val::id($d[$k] ?? null));
        $customer = fn (string $k = 'partner_id') => Val::id($d[$k] ?? null) ? ($this->ids->link('customer', Val::id($d[$k])) ?? 'odoo:'.Val::id($d[$k])) : null;
        $vendor = fn (string $k = 'vendor_id') => $this->ids->link('supplier', Val::id($d[$k] ?? null));
        $sku = fn (string $k = 'product_id') => self::sku(Val::label($d[$k] ?? null)) ?? (is_string($d['default_code'] ?? null) ? $d['default_code'] : null);
        $odoo = fn (array $keys) => ['odoo' => array_filter(array_combine($keys, array_map(fn ($k) => $d[$k] ?? null, $keys)), fn ($v) => $v !== null && $v !== false)];
        $crit = fn (string $k) => $sel($k, ['critical', 'high', 'normal', 'low'], 'normal');

        switch ($model) {
            case 'b2b.demand.forecast':
                $map = fn (string $entity, string $k) => array_values(array_filter(array_map(fn ($x) => $this->ids->link($entity, $x), Val::ids($d[$k] ?? []))));
                $whs = array_values(array_filter(array_map(fn ($x) => $this->ids->warehouse($x), Val::ids($d['warehouse_ids'] ?? []))));
                $unlinked = ['products' => count(Val::ids($d['product_ids'] ?? [])) - count($map('product', 'product_ids')), 'warehouses' => count(Val::ids($d['warehouse_ids'] ?? [])) - count($whs)];

                return [
                    'number' => mb_substr('O-'.(Val::text($d['name'] ?? null, 36) ?? $r->odoo_id), 0, 40), 'name' => Val::text($d['name'] ?? null, 190), 'status' => $sel('state', ['draft', 'computed', 'frozen'], 'draft'),
                    'active' => array_key_exists('active', $d) ? $b('active') : true, 'note' => Val::plain($d['note'] ?? null, 8000), 'anchor_date' => $day('date_ref'), 'computed_at' => Val::at($d['computed_on'] ?? null),
                    'compute_seconds' => $f('compute_duration'), 'history_from' => $day('date_from'), 'history_to' => $day('date_to'), 'forecast_from' => $day('forecast_date_from'), 'forecast_to' => $day('forecast_date_to'),
                    'period_kind' => 'month', 'method' => $sel('method', ['average', 'wma', 'seasonal', 'cadence'], 'wma'), 'wma_window' => $i('wma_window'), 'seasonal_scale' => $b('seasonal_scale_to_level'),
                    'growth_pct' => $f('growth_factor_pct') ?? 0, 'demand_netting' => $sel('demand_netting', ['net', 'gross', 'ignore'], 'net'), 'history_source' => $sel('history_source', ['delivery', 'sale_order'], 'delivery'),
                    'include_returns' => $b('include_returns'), 'include_odoo' => true, 'include_ops' => false, 'safety_method' => $sel('safety_stock_method', ['days', 'service_level'], 'days'),
                    'safety_days' => $f('safety_stock_days') ?? 0, 'service_level' => $sel('service_level', ['90', '95', '98', '99']), 'target_cover_days' => $f('target_cover_days') ?? 0,
                    'use_vendor_lead_time' => $b('use_vendor_lead_time'), 'default_lead_days' => $f('default_lead_days') ?? 0, 'planning_buffer_days' => $f('planning_buffer_days') ?? 0,
                    'qty_multiple_source' => ($d['qty_multiple_source'] ?? null) === 'uom_po' ? 'pack' : 'one', 'product_selection' => $sel('product_selection', ['moving', 'manual', 'all'], 'moving'),
                    'product_ids' => $map('product', 'product_ids'), 'category_ids' => $map('category', 'categ_ids'), 'warehouse_ids' => $whs, 'customer_mode' => $sel('customer_mode', ['none', 'filter', 'split'], 'none'),
                    'customer_ids' => $map('customer', 'partner_ids'), 'exclude_customer_ids' => $map('customer', 'exclude_partner_ids'), 'include_rollup' => $b('include_rollup'),
                    'rollup_method' => $sel('rollup_method', ['pooled', 'sum'], 'pooled'), 'include_history_sheet' => $b('include_history_sheet'), 'cadence_min_orders' => $i('cadence_min_orders'),
                    'cadence_interval_window' => $i('cadence_interval_window'), 'cadence_lapse_factor' => $f('cadence_lapse_factor'), 'cadence_use_trend' => $b('cadence_use_trend'),
                    'cadence_trend_cap_pct' => $f('cadence_trend_cap_pct'), 'cadence_use_seasonality' => $b('cadence_use_seasonality'), 'cadence_fallback' => $sel('cadence_fallback', ['estimate', 'ignore'], 'estimate'),
                    'budget_monthly_amount' => round($f('budget_monthly_amount') ?? 0, 2), 'item_count' => $i('item_count') ?? 0, 'shortage_item_count' => $i('shortage_item_count') ?? 0,
                    'surplus_item_count' => $i('surplus_item_count') ?? 0, 'line_count' => $i('line_count') ?? 0, 'total_forecast_qty' => $f('total_forecast_qty') ?? 0, 'total_shortage_qty' => $f('total_shortage_qty') ?? 0,
                    'total_surplus_qty' => $f('total_surplus_qty') ?? 0, 'total_suggested_qty' => $f('total_suggested_purchase_qty') ?? 0, 'total_suggested_value' => round($f('total_suggested_purchase_value') ?? 0, 2),
                    'pattern_count' => $i('pattern_count') ?? 0, 'event_count' => $i('event_count') ?? 0, 'requirement_count' => $i('requirement_count') ?? 0, 'total_requirement_value' => round($f('total_requirement_value') ?? 0, 2),
                    'updated_by' => Val::label($d['user_id'] ?? null), 'extra' => ['unlinked' => $unlinked, 'orderpointTrigger' => $d['orderpoint_trigger'] ?? null, 'qtyMultipleSource' => $d['qty_multiple_source'] ?? null,
                        'orderpointPushCount' => $i('orderpoint_push_count'), 'preparedBy' => Val::label($d['user_id'] ?? null)],
                ];

            case 'b2b.demand.forecast.item':
                $run = $this->parent('b2b.demand.forecast', $d['run_id'] ?? null);
                if (! $this->dry && ! $this->needParent('run', $run, $r)) {
                    return null;
                }
                $rollup = $b('is_rollup');
                $kind = Val::id($d['partner_id'] ?? null) && ! $rollup ? 'customer' : 'stock';

                return [
                    'run_id' => $run, 'kind' => $kind, 'product_id' => $product(), 'sku' => $sku() ?? Val::text($d['default_code'] ?? null, 60), 'product_name_ar' => Val::text(Val::label($d['product_id'] ?? null), 250),
                    'product_name_en' => null, 'category_id' => $this->ids->link('category', Val::id($d['categ_id'] ?? null)), 'warehouse_id' => $warehouse(), 'warehouse_code' => Val::text(Val::label($d['warehouse_id'] ?? null), 60),
                    'customer_id' => $kind === 'customer' ? $customer() : null, 'customer_name' => $kind === 'customer' ? Val::text(Val::label($d['partner_id'] ?? null), 250) : null, 'is_rollup' => $rollup,
                    'qty_on_hand' => $f('qty_on_hand'), 'incoming_qty' => $f('incoming_qty'), 'outgoing_qty' => $f('outgoing_qty'), 'history_total_qty' => $f('history_total_qty'), 'history_avg_qty' => $f('history_avg_qty'),
                    'history_stdev_qty' => $f('history_stdev_qty'), 'history_periods_used' => $i('history_periods_used'), 'forecast_total_qty' => $f('forecast_total_qty'), 'customer_sum_qty' => $f('customer_sum_qty'),
                    'avg_daily_qty' => $f('avg_daily_qty'), 'current_period_demand_qty' => $f('current_period_demand_qty'), 'min_projected_balance' => $f('min_projected_balance'), 'first_shortage_date' => $day('first_shortage_date'),
                    'shortage_qty' => $f('shortage_qty'), 'surplus_qty' => $f('surplus_qty'), 'coverage_days' => $f('coverage_days'), 'status' => $sel('status', ['shortage', 'surplus', 'ok', 'no_demand'], 'ok'),
                    'lead_days' => $f('lead_days'), 'qty_multiple' => $f('qty_multiple'), 'moq' => $f('moq'), 'suggested_purchase_qty' => $f('suggested_purchase_qty'), 'suggested_purchase_date' => $day('suggested_purchase_date'),
                    'suggested_purchase_value' => round($f('suggested_purchase_value') ?? 0, 2), 'last_purchase_price' => $f('last_purchase_price'), 'suggested_min_qty' => $f('suggested_min_qty'),
                    'suggested_max_qty' => $f('suggested_max_qty'), 'current_min_qty' => $f('current_min_qty'), 'current_max_qty' => $f('current_max_qty'), 'orderpoint_state' => $sel('orderpoint_state', ['missing', 'matches', 'differs']),
                    'push_selected' => $b('push_selected'), 'orderpoint_pushed' => $b('orderpoint_pushed'), 'orderpoint_push_date' => Val::at($d['orderpoint_push_date'] ?? null), 'pushed_min_qty' => $f('pushed_min_qty'),
                    'pushed_max_qty' => $f('pushed_max_qty'), 'method_fallback' => $b('method_fallback'), 'fallback_reason' => Val::text($d['fallback_reason'] ?? null, 250), 'confidence_score' => $f('confidence_score'),
                    'vendor_id' => $vendor(), 'vendor_name' => Val::text(Val::label($d['vendor_id'] ?? null), 250), 'product_criticality' => 'normal',
                    'refs' => $odoo(['product_id', 'warehouse_id', 'partner_id', 'vendor_id', 'orderpoint_id']),
                ];

            case 'b2b.demand.forecast.line':
            case 'b2b.demand.forecast.history':
                $run = $this->parent('b2b.demand.forecast', $d['run_id'] ?? null);
                $item = $this->parent('b2b.demand.forecast.item', $d['item_id'] ?? null);
                if (! $this->dry && (! $this->needParent('run', $run, $r) || ! $this->needParent('item', $item, $r))) {
                    return null;
                }
                $common = ['run_id' => $run, 'item_id' => $item, 'period_index' => $i('period_index') ?? 0, 'period_label' => Val::text($d['period_label'] ?? null, 40) ?? '', 'date_from' => $day('date_from'),
                    'date_to' => $day('date_to'), 'day_count' => $i('day_count')];
                if ($model === 'b2b.demand.forecast.history') {
                    return $common + ['qty' => $f('qty') ?? 0, 'used' => true, 'source' => $sel('source', ['delivery', 'sale_order'], 'delivery')];
                }

                return $common + ['history_qty' => $f('history_qty'), 'forecast_demand_qty' => $f('forecast_demand_qty'), 'scheduled_out_qty' => $f('scheduled_out_qty'), 'scheduled_in_qty' => $f('scheduled_in_qty'),
                    'unmet_demand_qty' => $f('unmet_demand_qty'), 'opening_balance_qty' => $f('opening_balance_qty'), 'odoo_forecast_qty' => $f('odoo_forecast_qty'), 'projected_balance_qty' => $f('projected_balance_qty'),
                    'shortage_qty' => $f('shortage_qty'), 'surplus_qty' => $f('surplus_qty'), 'coverage_days' => $f('coverage_days')];

            case 'b2b.demand.forecast.pattern':
                $run = $this->parent('b2b.demand.forecast', $d['run_id'] ?? null);
                if (! $this->dry && ! $this->needParent('run', $run, $r)) {
                    return null;
                }

                return [
                    'run_id' => $run, 'product_id' => $product(), 'sku' => $sku(), 'warehouse_id' => $warehouse(), 'customer_id' => $customer(), 'customer_name' => Val::text(Val::label($d['partner_id'] ?? null), 250),
                    'customer_criticality' => $crit('customer_criticality'), 'order_count' => $i('order_count') ?? 0, 'first_order_date' => $day('first_order_date'), 'last_order_date' => $day('last_order_date'),
                    'interval_history' => Val::text($d['interval_history'] ?? null, 250), 'quantity_history' => Val::text($d['quantity_history'] ?? null, 250), 'avg_interval_days' => $f('avg_interval_days'),
                    'interval_stdev_days' => $f('interval_stdev_days'), 'interval_cv' => $f('interval_cv'), 'avg_order_qty' => $f('avg_order_qty'), 'qty_cv' => $f('qty_cv'), 'trend_factor' => $f('trend_factor'),
                    'expected_order_qty' => $f('expected_order_qty'), 'seasonal' => $b('seasonal'), 'next_expected_date' => $day('next_expected_date'), 'days_since_last' => $i('days_since_last'),
                    'overdue_days' => $i('overdue_days') ?? 0, 'state' => $sel('state', ['active', 'overdue', 'lapsed', 'insufficient'], 'insufficient'), 'confidence_score' => $f('confidence_score'),
                    'confidence_band' => $sel('confidence_band', ['high', 'medium', 'low', 'none'], 'none'), 'forecast_qty' => $f('forecast_qty'), 'expected_order_count' => $i('expected_order_count') ?? 0,
                    'refs' => $odoo(['product_id', 'warehouse_id', 'partner_id']),
                ];

            case 'b2b.demand.forecast.event':
                $run = $this->parent('b2b.demand.forecast', $d['run_id'] ?? null);
                $pattern = $this->parent('b2b.demand.forecast.pattern', $d['pattern_id'] ?? null);
                if (! $this->dry && (! $this->needParent('run', $run, $r) || ! $this->needParent('pattern', $pattern, $r))) {
                    return null;
                }

                return [
                    'run_id' => $run, 'pattern_id' => $pattern, 'product_id' => $product(), 'warehouse_id' => $warehouse(), 'customer_id' => $customer(), 'expected_date' => $day('expected_date'),
                    'occurrence' => $i('occurrence') ?? 1, 'catch_up' => $b('catch_up'), 'period_index' => $i('period_index'), 'period_label' => Val::text($d['period_label'] ?? null, 40), 'in_horizon' => $b('in_horizon'),
                    'qty' => $f('qty') ?? 0, 'confidence_score' => $f('confidence_score'), 'confidence_band' => $sel('confidence_band', ['high', 'medium', 'low', 'none'], 'none'),
                    'actual_state' => $sel('actual_state', ['pending', 'ordered', 'missed'], 'pending'), 'actual_date' => $day('actual_date'), 'actual_qty' => $f('actual_qty'), 'qty_deviation' => $f('qty_deviation'),
                ];

            case 'b2b.demand.forecast.requirement':
                $run = $this->parent('b2b.demand.forecast', $d['run_id'] ?? null);
                if (! $this->dry && ! $this->needParent('run', $run, $r)) {
                    return null;
                }
                $value = round($f('value') ?? 0, 2);

                return [
                    'run_id' => $run, 'item_id' => $this->parent('b2b.demand.forecast.item', $d['item_id'] ?? null), 'product_id' => $product(), 'sku' => $sku(), 'product_name_ar' => Val::text(Val::label($d['product_id'] ?? null), 250),
                    'warehouse_id' => $warehouse(), 'warehouse_code' => Val::text(Val::label($d['warehouse_id'] ?? null), 60), 'product_criticality' => $crit('product_criticality'), 'period_index' => $i('period_index') ?? 0,
                    'period_label' => Val::text($d['period_label'] ?? null, 40), 'period_from' => $day('period_date_from'), 'period_to' => $day('period_date_to'), 'need_date' => $day('need_date'), 'trigger_date' => $day('trigger_date'),
                    'late' => $b('late'), 'lead_days' => $f('lead_days') ?? 0, 'buffer_days' => $f('buffer_days') ?? 0, 'demand_qty' => $f('demand_qty'), 'opening_balance_qty' => $f('opening_balance_qty'),
                    'projected_balance_qty' => $f('projected_balance_qty'), 'safety_qty' => $f('safety_qty'), 'shortage_qty' => $f('shortage_qty') ?? 0, 'net_qty' => $f('net_qty') ?? 0, 'moq' => $f('moq') ?? 0,
                    'qty_multiple' => $f('qty_multiple') ?? 1, 'qty' => $f('qty') ?? 0, 'vendor_id' => $vendor(), 'vendor_name' => Val::text(Val::label($d['vendor_id'] ?? null), 250), 'price_unit' => $f('price_unit'),
                    'price_source' => $sel('price_source', ['vendor', 'last_purchase', 'cost', 'none'], 'none'), 'value' => $value, 'confidence_score' => $f('confidence_score') ?? 0, 'velocity_qty' => $f('velocity_qty') ?? 0,
                    'customer_score' => $f('customer_score') ?? 50, 'refs' => $odoo(['product_id', 'warehouse_id', 'vendor_id']),
                ];

            case 'b2b.demand.forecast.budget':
                $run = $this->parent('b2b.demand.forecast', $d['run_id'] ?? null);
                if (! $this->dry && ! $this->needParent('run', $run, $r)) {
                    return null;
                }

                return [
                    'run_id' => $run, 'name' => Val::text($d['name'] ?? null, 190) ?? "#{$r->odoo_id}", 'is_primary' => $b('is_primary'), 'status' => $sel('state', ['draft', 'allocated'], 'draft'),
                    'allocated_at' => Val::at($d['allocated_on'] ?? null), 'note' => Val::plain($d['note'] ?? null, 8000), 'weight_urgency' => $i('weight_urgency') ?? 0, 'weight_stockout' => $i('weight_stockout') ?? 0,
                    'weight_criticality' => $i('weight_criticality') ?? 0, 'weight_velocity' => $i('weight_velocity') ?? 0, 'weight_confidence' => $i('weight_confidence') ?? 0, 'weight_customer' => $i('weight_customer') ?? 0,
                    'weight_lead_time' => $i('weight_lead_time') ?? 0, 'critical_first' => $b('critical_first'), 'allow_partial' => $b('allow_partial'), 'carry_deferred' => $b('carry_deferred'), 'carry_unused' => $b('carry_unused'),
                    'urgency_horizon_days' => $i('urgency_horizon_days') ?? 30, 'budget_total' => round($f('budget_total') ?? 0, 2), 'required_value' => round($f('required_value') ?? 0, 2),
                    'funded_value' => round($f('funded_value') ?? 0, 2), 'deferred_value' => round($f('deferred_value') ?? 0, 2), 'funded_pct' => $f('funded_pct') ?? 0, 'unused_value' => round($f('unused_value') ?? 0, 2),
                    'stockout_unfunded_value' => round($f('stockout_unfunded_value') ?? 0, 2), 'requirement_count' => $i('requirement_count') ?? 0, 'funded_count' => $i('funded_count') ?? 0,
                    'partial_count' => $i('partial_count') ?? 0, 'deferred_count' => $i('deferred_count') ?? 0, 'critical_deferred_count' => $i('critical_deferred_count') ?? 0, 'at_risk_count' => $i('at_risk_count') ?? 0,
                ];

            case 'b2b.demand.forecast.budget.line':
                $budget = $this->parent('b2b.demand.forecast.budget', $d['budget_id'] ?? null);
                if (! $this->dry && ! $this->needParent('budget', $budget, $r)) {
                    return null;
                }

                return ['budget_id' => $budget, 'month' => $day('month'), 'amount' => round($f('amount') ?? 0, 2), 'carried_in' => round($f('carried_in') ?? 0, 2), 'required_value' => round($f('required_value') ?? 0, 2),
                    'funded_value' => round($f('funded_value') ?? 0, 2), 'remaining_value' => round($f('remaining_value') ?? 0, 2)];

            case 'b2b.demand.forecast.allocation':
                $budget = $this->parent('b2b.demand.forecast.budget', $d['budget_id'] ?? null);
                $requirement = $this->parent('b2b.demand.forecast.requirement', $d['requirement_id'] ?? null);
                if (! $this->dry && (! $this->needParent('budget', $budget, $r) || ! $this->needParent('requirement', $requirement, $r))) {
                    return null;
                }
                $runId = $this->installed && $budget && ! str_starts_with($budget, 'planned:') ? DB::table('planning_budgets')->where('id', $budget)->value('run_id') : null;
                $reason = Val::text($d['reason'] ?? null, 250);

                return [
                    'budget_id' => $budget, 'run_id' => $runId, 'budget_line_id' => $this->parent('b2b.demand.forecast.budget.line', $d['budget_line_id'] ?? null), 'requirement_id' => $requirement,
                    'month' => $day('month'), 'order_date' => $day('order_date'), 'rank' => $i('rank') ?? 0, 'must_fund' => $b('must_fund'), 'score' => $f('score') ?? 0, 'score_urgency' => $f('score_urgency') ?? 0,
                    'score_stockout' => $f('score_stockout') ?? 0, 'score_criticality' => $f('score_criticality') ?? 0, 'score_velocity' => $f('score_velocity') ?? 0, 'score_confidence' => $f('score_confidence') ?? 0,
                    'score_customer' => $f('score_customer') ?? 0, 'score_lead_time' => $f('score_lead_time') ?? 0, 'required_qty' => $f('required_qty') ?? 0, 'funded_qty' => $f('funded_qty') ?? 0,
                    'required_value' => round($f('required_value') ?? 0, 2), 'funded_value' => round($f('funded_value') ?? 0, 2), 'status' => $sel('status', ['funded', 'partial', 'deferred'], 'deferred'),
                    'carried_from' => $day('carried_from'), 'at_risk' => $b('at_risk'), 'reason_code' => $reason ? 'odoo' : null, 'reason_ar' => $reason, 'reason_en' => $reason,
                ];

            case 'b2b.purchase.plan':
                $run = $this->parent('b2b.demand.forecast', $d['run_id'] ?? null);
                $budget = $this->parent('b2b.demand.forecast.budget', $d['budget_id'] ?? null);
                if (! $this->dry && (! $this->needParent('run', $run, $r) || ! $this->needParent('budget', $budget, $r))) {
                    return null;
                }

                return [
                    'number' => mb_substr('O-'.(Val::text($d['name'] ?? null, 36) ?? $r->odoo_id), 0, 40), 'name' => Val::text($d['name'] ?? null, 190), 'run_id' => $run, 'budget_id' => $budget,
                    'status' => $sel('state', ['draft', 'to_approve', 'approved', 'done', 'cancel'], 'draft'), 'date_from' => $day('date_from'), 'date_to' => $day('date_to'),
                    'rfq_grouping' => $sel('rfq_grouping', ['date', 'week', 'plan'], 'date'), 'note' => Val::plain($d['note'] ?? null, 8000), 'planner_name' => Val::label($d['user_id'] ?? null),
                    'approved_by' => Val::label($d['approved_by_id'] ?? null), 'approved_at' => Val::at($d['approved_on'] ?? null), 'extra' => ['purchaser' => Val::label($d['purchase_user_id'] ?? null)],
                ];

            case 'b2b.purchase.plan.line':
                $plan = $this->parent('b2b.purchase.plan', $d['plan_id'] ?? null);
                if (! $this->dry && ! $this->needParent('plan', $plan, $r)) {
                    return null;
                }
                $qty = $f('qty') ?? 0;

                return [
                    'plan_id' => $plan, 'line_no' => (int) $r->odoo_id, 'allocation_id' => $this->parent('b2b.demand.forecast.allocation', $d['allocation_id'] ?? null),
                    'requirement_id' => $this->parent('b2b.demand.forecast.requirement', $d['requirement_id'] ?? null), 'product_id' => $product(), 'sku' => $sku(), 'warehouse_id' => $warehouse(),
                    'vendor_id' => $vendor(), 'vendor_name' => Val::text(Val::label($d['vendor_id'] ?? null), 250), 'qty' => $qty, 'price_unit' => $f('price_unit'), 'subtotal' => round($f('subtotal') ?? 0, 2),
                    'order_date' => $day('order_date'), 'need_date' => $day('need_date'), 'lead_days' => $f('lead_days'), 'cancelled' => $b('cancelled'),
                    'status' => $sel('status', ['planned', 'rfq', 'confirmed', 'partial', 'received', 'cancelled'], 'planned'), 'ordered_qty' => $f('ordered_qty'), 'received_qty' => $f('received_qty'),
                    'committed_value' => round($f('committed_value') ?? 0, 2), 'received_value' => round($f('received_value') ?? 0, 2), 'late_order' => $b('late_order'), 'late_receipt' => $b('late_receipt'),
                    'refs' => $odoo(['purchase_line_id', 'purchase_order_id', 'product_id', 'warehouse_id', 'vendor_id']),
                ];
        }

        return null;
    }

    /** "[P00318] Halloumi …" → "P00318". */
    private static function sku(?string $label): ?string
    {
        return $label !== null && preg_match('/^\[([^\]]{1,60})\]/', $label, $m) ? $m[1] : null;
    }
}
