<?php

use App\Support\PermissionSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «تخطيط الطلب والشراء» — demand forecast and purchase planning (docs/odoo/WAVE4.md §Q2), the native home of Odoo's
 * "B2B Demand Forecast & Replenishment Planning" (the twelve models of docs/odoo/shapes/unreadable.txt):
 *   runs · the run's own demand table and last prices (what it read) · items · forecast periods · history periods ·
 *   customer reorder patterns · expected orders · purchase requirements · budget scenarios · budget months ·
 *   allocations · purchase plans · plan lines · pushes of reorder settings · criticality of products and customers ·
 *   the mapping of Odoo warehouses to OPS warehouses.
 * Rows that came from Odoo carry `source_system = 'odoo'`, `external_ref` (the Odoo id) and `source_hash`. A run, a
 * scenario and a plan are never removed (archived / cancelled); what a computation wrote is replaced by the next one.
 */
return new class extends Migration
{
    private const TABLES = ['planning_runs', 'planning_demand', 'planning_prices', 'planning_items', 'planning_periods', 'planning_history', 'planning_patterns', 'planning_events',
        'planning_requirements', 'planning_budgets', 'planning_budget_lines', 'planning_allocations', 'planning_plans', 'planning_plan_lines', 'planning_pushes', 'planning_attributes',
        'planning_warehouse_maps'];

    public function up(): void
    {
        $source = function (Blueprint $t, string $name) {
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->string('source_hash', 40)->nullable();
            $t->unique(['source_system', 'external_ref'], "{$name}_source_uq");
        };
        $qty = function (Blueprint $t, array $columns) {
            foreach ($columns as $c) {
                $t->double($c)->nullable();
            }
        };

        Schema::create('planning_runs', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();              // DF-YYYY-NNNNN (an Odoo run: O-<its reference>)
            $t->string('name', 190)->nullable();
            $t->string('status', 12)->default('draft');      // draft | computing | computed | frozen
            $t->boolean('active')->default(true);
            $t->text('note')->nullable();
            // the windows
            $t->date('anchor_date')->nullable();             // "today" of the run
            $t->date('history_from')->nullable();
            $t->date('history_to')->nullable();
            $t->date('forecast_from')->nullable();
            $t->date('forecast_to')->nullable();
            $t->string('period_kind', 8)->default('month');  // month | week
            // the method
            $t->string('method', 12)->default('wma');        // average | wma | seasonal | cadence
            $t->integer('wma_window')->nullable();
            $t->boolean('seasonal_scale')->default(true);
            $t->double('growth_pct')->default(0);
            $t->string('demand_netting', 8)->default('net'); // net | gross | ignore
            $t->string('history_source', 12)->default('delivery'); // delivery | sale_order
            $t->boolean('include_returns')->default(true);
            $t->boolean('include_odoo')->default(true);      // Odoo's history from the copy
            $t->boolean('include_ops')->default(true);       // OPS's own sales and deliveries
            // stock and buying
            $t->string('safety_method', 16)->default('days'); // days | service_level
            $t->double('safety_days')->default(0);
            $t->string('service_level', 4)->nullable();      // 90 | 95 | 98 | 99
            $t->double('target_cover_days')->default(0);
            $t->boolean('use_vendor_lead_time')->default(true);
            $t->double('default_lead_days')->default(0);
            $t->double('planning_buffer_days')->default(0);
            $t->string('qty_multiple_source', 8)->default('one'); // one | pack
            // what it is about
            $t->string('product_selection', 8)->default('moving'); // moving | manual | all
            $t->json('product_ids')->nullable();
            $t->json('category_ids')->nullable();
            $t->json('warehouse_ids')->nullable();
            $t->string('customer_mode', 8)->default('none'); // none | filter | split
            $t->json('customer_ids')->nullable();
            $t->json('exclude_customer_ids')->nullable();
            $t->boolean('include_rollup')->default(true);
            $t->string('rollup_method', 8)->default('pooled'); // pooled | sum
            $t->boolean('include_history_sheet')->default(false);
            // the cadence method
            $t->integer('cadence_min_orders')->nullable();
            $t->integer('cadence_interval_window')->nullable();
            $t->double('cadence_lapse_factor')->nullable();
            $t->boolean('cadence_use_trend')->default(true);
            $t->double('cadence_trend_cap_pct')->nullable();
            $t->boolean('cadence_use_seasonality')->default(false);
            $t->string('cadence_fallback', 10)->default('estimate'); // estimate | ignore
            $t->decimal('budget_monthly_amount', 16, 2)->default(0);
            // the computation
            $t->json('work')->nullable();                    // where a computation stands (phase, cursor, what it skipped)
            $t->integer('progress_done')->default(0);
            $t->integer('progress_total')->default(0);
            $t->dateTime('compute_started_at', 3)->nullable();
            $t->dateTime('lease_until', 3)->nullable();      // one worker at a time
            $t->string('lease_token', 40)->nullable();
            $t->text('last_error')->nullable();
            $t->dateTime('computed_at', 3)->nullable();
            $t->double('compute_seconds')->nullable();
            $t->dateTime('stock_at', 3)->nullable();         // when the stock figures were read
            $t->json('diagnostics')->nullable();
            $t->dateTime('frozen_at', 3)->nullable();
            $t->string('frozen_by')->nullable();
            // what it found
            foreach (['item_count', 'shortage_item_count', 'surplus_item_count', 'line_count', 'pattern_count', 'event_count', 'requirement_count'] as $c) {
                $t->integer($c)->default(0);
            }
            foreach (['total_forecast_qty', 'total_shortage_qty', 'total_surplus_qty', 'total_suggested_qty'] as $c) {
                $t->double($c)->default(0);
            }
            $t->decimal('total_suggested_value', 16, 2)->default(0);
            $t->decimal('total_requirement_value', 16, 2)->default(0);
            $t->json('extra')->nullable();
            $source($t, 'planning_runs');
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['active', 'status'], 'planning_runs_state_ix');
            $t->index(['created_at'], 'planning_runs_created_ix');
        });

        // the demand a run read (one row per product · warehouse · customer · day · origin and slice): its own copy
        Schema::create('planning_demand', function (Blueprint $t) {
            $t->id();
            $t->ulid('run_id');
            $t->string('product_id', 40);
            $t->string('warehouse_id', 40);
            $t->string('customer_key', 60)->nullable();      // an OPS customer id, or odoo:<partner> when not linked
            $t->date('day');
            $t->double('qty')->default(0);                   // delivered / ordered
            $t->double('returned')->default(0);
            $t->string('origin', 8);                         // ops | odoo
            $t->index(['run_id', 'product_id'], 'planning_demand_run_ix');
        });

        Schema::create('planning_prices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('run_id');
            $t->string('product_id', 40);
            $t->decimal('price', 14, 4);
            $t->date('day')->nullable();
            $t->string('origin', 8);
            $t->string('ref', 60)->nullable();
            $t->index(['run_id', 'product_id'], 'planning_prices_run_ix');
        });

        Schema::create('planning_items', function (Blueprint $t) use ($source, $qty) {
            $t->ulid('id')->primary();
            $t->ulid('run_id');
            $t->string('kind', 10)->default('stock');        // stock | customer
            $t->string('product_id', 40)->nullable();        // null: an Odoo product with no OPS product
            $t->string('sku', 60)->nullable();
            $t->string('product_name_ar', 250)->nullable();
            $t->string('product_name_en', 250)->nullable();
            $t->string('category_id', 40)->nullable();
            $t->string('warehouse_id', 40)->nullable();
            $t->string('warehouse_code', 60)->nullable();
            $t->string('customer_id', 60)->nullable();
            $t->string('customer_code', 60)->nullable();
            $t->string('customer_name', 250)->nullable();
            $t->boolean('is_rollup')->default(false);
            $t->string('product_criticality', 10)->default('normal');
            $qty($t, ['qty_on_hand', 'incoming_qty', 'outgoing_qty', 'history_total_qty', 'history_avg_qty', 'history_stdev_qty', 'forecast_total_qty', 'customer_sum_qty', 'avg_daily_qty',
                'current_period_demand_qty', 'min_projected_balance', 'shortage_qty', 'surplus_qty', 'coverage_days', 'safety_qty', 'lead_days', 'qty_multiple', 'moq', 'suggested_purchase_qty',
                'last_purchase_price', 'price_unit', 'suggested_min_qty', 'suggested_max_qty', 'current_min_qty', 'current_max_qty', 'pushed_min_qty', 'pushed_max_qty', 'confidence_score']);
            $t->integer('history_periods_used')->nullable();
            $t->date('first_shortage_date')->nullable();
            $t->string('status', 12)->default('ok');         // shortage | surplus | ok | no_demand
            $t->string('lead_source', 10)->nullable();       // vendor | default
            $t->date('suggested_purchase_date')->nullable();
            $t->decimal('suggested_purchase_value', 16, 2)->nullable();
            $t->string('price_source', 16)->nullable();      // vendor | supplier_link | last_purchase | cost | none
            $t->string('vendor_id', 40)->nullable();
            $t->string('vendor_code', 60)->nullable();
            $t->string('vendor_name', 250)->nullable();
            $t->string('orderpoint_state', 10)->nullable();  // missing | matches | differs
            $t->boolean('push_selected')->default(false);
            $t->boolean('orderpoint_pushed')->default(false);
            $t->dateTime('orderpoint_push_date', 3)->nullable();
            $t->string('method_used', 12)->nullable();
            $t->boolean('method_fallback')->default(false);
            $t->string('fallback_reason', 250)->nullable();
            $t->json('explain')->nullable();                 // every number behind the suggestion
            $t->json('refs')->nullable();
            $source($t, 'planning_items');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['run_id', 'kind', 'status'], 'planning_items_run_ix');
            $t->index(['run_id', 'product_id', 'warehouse_id'], 'planning_items_product_ix');
            $t->index(['run_id', 'sku'], 'planning_items_sku_ix');
        });

        Schema::create('planning_periods', function (Blueprint $t) use ($source, $qty) {
            $t->ulid('id')->primary();
            $t->ulid('run_id');
            $t->ulid('item_id');
            $t->integer('period_index');
            $t->string('period_label', 40);
            $t->date('date_from')->nullable();
            $t->date('date_to')->nullable();
            $t->integer('day_count')->nullable();
            $qty($t, ['history_qty', 'forecast_demand_qty', 'scheduled_out_qty', 'scheduled_in_qty', 'unmet_demand_qty', 'opening_balance_qty', 'booked_balance_qty', 'odoo_forecast_qty',
                'projected_balance_qty', 'shortage_qty', 'surplus_qty', 'coverage_days']);
            $source($t, 'planning_periods');
            $t->index(['item_id', 'period_index'], 'planning_periods_item_ix');
            $t->index(['run_id'], 'planning_periods_run_ix');
        });

        Schema::create('planning_history', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->ulid('run_id');
            $t->ulid('item_id');
            $t->integer('period_index');
            $t->string('period_label', 40);
            $t->date('date_from')->nullable();
            $t->date('date_to')->nullable();
            $t->integer('day_count')->nullable();
            $t->double('qty')->default(0);
            $t->boolean('used')->default(true);              // the periods before the first sale are not history
            $t->string('source', 12)->default('delivery');
            $source($t, 'planning_history');
            $t->index(['item_id', 'period_index'], 'planning_history_item_ix');
            $t->index(['run_id'], 'planning_history_run_ix');
        });

        Schema::create('planning_patterns', function (Blueprint $t) use ($source, $qty) {
            $t->ulid('id')->primary();
            $t->ulid('run_id');
            $t->string('product_id', 40)->nullable();
            $t->string('sku', 60)->nullable();
            $t->string('warehouse_id', 40)->nullable();
            $t->string('customer_id', 60)->nullable();
            $t->string('customer_name', 250)->nullable();
            $t->string('customer_criticality', 10)->default('normal');
            $t->integer('order_count')->default(0);
            $t->date('first_order_date')->nullable();
            $t->date('last_order_date')->nullable();
            $t->string('interval_history', 250)->nullable();
            $t->string('quantity_history', 250)->nullable();
            $qty($t, ['avg_interval_days', 'interval_stdev_days', 'interval_cv', 'avg_order_qty', 'qty_cv', 'trend_factor', 'expected_order_qty', 'confidence_score', 'forecast_qty']);
            $t->boolean('seasonal')->default(false);
            $t->date('next_expected_date')->nullable();
            $t->integer('days_since_last')->nullable();
            $t->integer('overdue_days')->default(0);
            $t->string('state', 12)->default('insufficient'); // active | overdue | lapsed | insufficient
            $t->string('confidence_band', 8)->default('none');
            $t->integer('expected_order_count')->default(0);
            $t->json('refs')->nullable();
            $source($t, 'planning_patterns');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['run_id', 'product_id', 'warehouse_id'], 'planning_patterns_run_ix');
        });

        Schema::create('planning_events', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->ulid('run_id');
            $t->ulid('pattern_id');
            $t->string('product_id', 40)->nullable();
            $t->string('warehouse_id', 40)->nullable();
            $t->string('customer_id', 60)->nullable();
            $t->date('expected_date')->nullable();
            $t->integer('occurrence')->default(1);
            $t->boolean('catch_up')->default(false);
            $t->integer('period_index')->nullable();
            $t->string('period_label', 40)->nullable();
            $t->boolean('in_horizon')->default(true);
            $t->double('qty')->default(0);
            $t->double('confidence_score')->nullable();
            $t->string('confidence_band', 8)->default('none');
            $t->string('actual_state', 8)->default('pending'); // pending | ordered | missed
            $t->date('actual_date')->nullable();
            $t->double('actual_qty')->nullable();
            $t->double('qty_deviation')->nullable();
            $t->dateTime('actual_checked_at', 3)->nullable();
            $t->json('refs')->nullable();
            $source($t, 'planning_events');
            $t->index(['run_id', 'actual_state', 'expected_date'], 'planning_events_run_ix');
            $t->index(['pattern_id'], 'planning_events_pattern_ix');
        });

        Schema::create('planning_requirements', function (Blueprint $t) use ($source, $qty) {
            $t->ulid('id')->primary();
            $t->ulid('run_id');
            $t->ulid('item_id')->nullable();
            $t->string('product_id', 40)->nullable();
            $t->string('sku', 60)->nullable();
            $t->string('product_name_ar', 250)->nullable();
            $t->string('product_name_en', 250)->nullable();
            $t->string('category_id', 40)->nullable();
            $t->string('warehouse_id', 40)->nullable();
            $t->string('warehouse_code', 60)->nullable();
            $t->string('product_criticality', 10)->default('normal');
            $t->integer('period_index')->default(0);
            $t->string('period_label', 40)->nullable();
            $t->date('period_from')->nullable();
            $t->date('period_to')->nullable();
            $t->date('need_date')->nullable();
            $t->date('trigger_date')->nullable();            // order by
            $t->boolean('late')->default(false);
            $t->double('lead_days')->default(0);
            $t->string('lead_source', 10)->nullable();
            $t->double('buffer_days')->default(0);
            $qty($t, ['demand_qty', 'opening_balance_qty', 'projected_balance_qty', 'safety_qty']);
            $t->double('shortage_qty')->default(0);          // the part below zero (a real stock-out)
            $t->double('net_qty')->default(0);
            $t->double('moq')->default(0);
            $t->double('qty_multiple')->default(1);
            $t->double('qty')->default(0);
            $t->string('vendor_id', 40)->nullable();
            $t->string('vendor_name', 250)->nullable();
            $t->double('price_unit')->nullable();
            $t->string('price_source', 16)->default('none');
            $t->decimal('value', 16, 2)->default(0);
            $t->double('confidence_score')->default(0);
            $t->double('velocity_qty')->default(0);
            $t->double('customer_score')->default(50);
            $t->json('refs')->nullable();
            $source($t, 'planning_requirements');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['run_id', 'trigger_date'], 'planning_requirements_run_ix');
            $t->index(['item_id'], 'planning_requirements_item_ix');
        });

        Schema::create('planning_budgets', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->ulid('run_id');
            $t->string('name', 190);
            $t->boolean('is_primary')->default(false);       // the run's own budget (its monthly amount)
            $t->string('status', 10)->default('draft');      // draft (not allocated) | allocated
            $t->dateTime('allocated_at', 3)->nullable();
            $t->string('allocated_by')->nullable();
            $t->text('note')->nullable();
            foreach (['weight_urgency', 'weight_stockout', 'weight_criticality', 'weight_velocity', 'weight_confidence', 'weight_customer', 'weight_lead_time'] as $c) {
                $t->integer($c)->default(0);
            }
            foreach (['critical_first', 'allow_partial', 'carry_deferred', 'carry_unused'] as $c) {
                $t->boolean($c)->default(true);
            }
            $t->integer('urgency_horizon_days')->default(30);
            foreach (['budget_total', 'required_value', 'funded_value', 'deferred_value', 'unused_value', 'stockout_unfunded_value'] as $c) {
                $t->decimal($c, 16, 2)->default(0);
            }
            $t->double('funded_pct')->default(0);
            foreach (['requirement_count', 'funded_count', 'partial_count', 'deferred_count', 'critical_deferred_count', 'at_risk_count'] as $c) {
                $t->integer($c)->default(0);
            }
            $t->json('extra')->nullable();
            $source($t, 'planning_budgets');
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['run_id'], 'planning_budgets_run_ix');
        });

        Schema::create('planning_budget_lines', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->ulid('budget_id');
            $t->date('month')->nullable();                   // its first day
            foreach (['amount', 'carried_in', 'required_value', 'funded_value', 'remaining_value'] as $c) {
                $t->decimal($c, 16, 2)->default(0);
            }
            $source($t, 'planning_budget_lines');
            $t->index(['budget_id', 'month'], 'planning_budget_lines_budget_ix');
        });

        Schema::create('planning_allocations', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->ulid('budget_id');
            $t->ulid('run_id')->nullable();
            $t->ulid('budget_line_id')->nullable();
            $t->ulid('requirement_id');
            $t->date('month')->nullable();
            $t->date('order_date')->nullable();
            $t->integer('rank')->default(0);
            $t->boolean('must_fund')->default(false);
            foreach (['score', 'score_urgency', 'score_stockout', 'score_criticality', 'score_velocity', 'score_confidence', 'score_customer', 'score_lead_time', 'required_qty', 'funded_qty'] as $c) {
                $t->double($c)->default(0);
            }
            $t->decimal('required_value', 16, 2)->default(0);
            $t->decimal('funded_value', 16, 2)->default(0);
            $t->string('status', 10);                        // funded | partial | deferred
            $t->date('carried_from')->nullable();
            $t->boolean('at_risk')->default(false);
            $t->string('reason_code', 20)->nullable();
            $t->string('reason_ar', 250)->nullable();
            $t->string('reason_en', 250)->nullable();
            $source($t, 'planning_allocations');
            $t->index(['budget_id', 'month', 'rank'], 'planning_allocations_budget_ix');
            $t->index(['requirement_id'], 'planning_allocations_requirement_ix');
        });

        Schema::create('planning_plans', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();              // PPL-YYYY-NNNNN
            $t->string('name', 190)->nullable();
            $t->ulid('run_id');
            $t->ulid('budget_id');
            $t->string('status', 12)->default('draft');      // draft | to_approve | approved | done | cancel
            $t->date('date_from')->nullable();
            $t->date('date_to')->nullable();
            $t->string('rfq_grouping', 8)->default('date');  // date | week | plan
            $t->text('note')->nullable();
            $t->string('planner_id', 40)->nullable();
            $t->string('planner_name', 190)->nullable();
            $t->dateTime('submitted_at', 3)->nullable();
            $t->string('submitted_by')->nullable();
            $t->string('submitted_by_id', 40)->nullable();
            $t->dateTime('approved_at', 3)->nullable();
            $t->string('approved_by')->nullable();
            $t->string('approved_by_id', 40)->nullable();
            $t->text('return_reason')->nullable();
            $t->dateTime('closed_at', 3)->nullable();
            $t->string('closed_by')->nullable();
            $t->text('close_note')->nullable();
            $t->dateTime('cancelled_at', 3)->nullable();
            $t->string('cancelled_by')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->json('extra')->nullable();
            $source($t, 'planning_plans');
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['status', 'created_at'], 'planning_plans_status_ix');
            $t->index(['budget_id'], 'planning_plans_budget_ix');
        });

        Schema::create('planning_plan_lines', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->ulid('plan_id');
            $t->integer('line_no');
            $t->ulid('allocation_id')->nullable();
            $t->ulid('requirement_id')->nullable();
            $t->string('product_id', 40)->nullable();
            $t->string('sku', 60)->nullable();
            $t->string('warehouse_id', 40)->nullable();
            $t->string('vendor_id', 40)->nullable();
            $t->string('vendor_name', 250)->nullable();
            $t->double('qty')->default(0);
            $t->double('price_unit')->nullable();
            $t->decimal('subtotal', 16, 2)->default(0);
            $t->date('order_date')->nullable();
            $t->date('need_date')->nullable();
            $t->double('lead_days')->nullable();
            $t->boolean('cancelled')->default(false);
            $t->text('cancel_reason')->nullable();
            $t->dateTime('cancelled_at', 3)->nullable();
            $t->string('cancelled_by')->nullable();
            $t->string('note', 1000)->nullable();
            $t->string('status', 12)->default('planned');    // as Odoo kept it; OPS reads it live from the request and the order
            $t->string('pr_id', 40)->nullable();
            $t->string('pr_number', 40)->nullable();
            $t->string('pr_line_id', 40)->nullable()->unique('planning_plan_lines_pr_line_uq'); // a line is requested once
            $t->dateTime('requested_at', 3)->nullable();
            $t->string('requested_by')->nullable();
            $t->double('ordered_qty')->nullable();
            $t->double('received_qty')->nullable();
            $t->decimal('committed_value', 16, 2)->nullable();
            $t->decimal('received_value', 16, 2)->nullable();
            $t->boolean('late_order')->default(false);
            $t->boolean('late_receipt')->default(false);
            $t->json('refs')->nullable();
            $source($t, 'planning_plan_lines');
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['plan_id', 'line_no'], 'planning_plan_lines_plan_ix');
            $t->index(['allocation_id'], 'planning_plan_lines_allocation_ix');
        });

        Schema::create('planning_pushes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('run_id');
            $t->string('product_id', 40);
            $t->string('sku', 60)->nullable();
            $t->integer('old_min')->nullable();
            $t->integer('old_max')->nullable();
            $t->integer('new_min');
            $t->integer('new_max');
            $t->json('item_ids')->nullable();
            $t->integer('changed')->default(0);
            $t->dateTime('at', 3)->useCurrent();
            $t->string('by')->nullable();
            $t->index(['run_id', 'product_id'], 'planning_pushes_run_ix');
        });

        Schema::create('planning_attributes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('entity', 10);                        // product | customer
            $t->string('entity_id', 40);
            $t->string('criticality', 10)->default('normal');
            $t->string('note', 500)->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['entity', 'entity_id'], 'planning_attributes_uq');
        });

        Schema::create('planning_warehouse_maps', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('system', 20)->default('odoo');
            $t->string('external_id', 40);
            $t->string('external_name', 190)->nullable();
            $t->string('warehouse_id', 40)->nullable();      // null = not mapped (said so on purpose)
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['system', 'external_id'], 'planning_warehouse_maps_uq');
        });

        $this->caseInsensitiveText();
        PermissionSync::ensure();   // page.planning · forecast.manage · purchaseplan.manage · purchaseplan.approve on a database that predates them
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * PostgreSQL only — a no-op on MySQL. The application compares text without regard to letter case
     * (2026_09_17_000000_pgsql_compatibility): the text and id columns of these tables get the same CITEXT type as the
     * tables they are joined with. Columns already converted report USER-DEFINED and are left alone.
     */
    private function caseInsensitiveText(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        $marks = implode(', ', array_fill(0, count(self::TABLES), '?'));
        $columns = DB::select("SELECT table_name, column_name FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name IN ({$marks}) AND data_type IN ('character', 'character varying', 'text')
            ORDER BY table_name, ordinal_position", self::TABLES);
        $byTable = [];
        foreach ($columns as $c) {
            $byTable[$c->table_name][] = sprintf('ALTER COLUMN "%1$s" TYPE citext USING rtrim("%1$s")::citext', $c->column_name);
        }
        foreach ($byTable as $table => $changes) {
            DB::statement(sprintf('ALTER TABLE "%s" %s', $table, implode(', ', $changes)));
        }
    }
};
