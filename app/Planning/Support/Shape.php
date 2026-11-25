<?php

namespace App\Planning\Support;

use App\Planning\Models\Budget;
use App\Planning\Models\Item;
use App\Planning\Models\Plan;
use App\Planning\Models\PlanLine;
use App\Planning\Models\Run;
use App\Planning\Services\RunsService;
use DateTimeInterface;

/** The planning section as the API answers it (camelCase; days as `YYYY-MM-DD`, moments as ISO text). */
final class Shape
{
    public static function day(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return $v instanceof DateTimeInterface ? $v->format('Y-m-d') : substr((string) $v, 0, 10);
    }

    public static function at(mixed $v): ?string
    {
        return $v instanceof DateTimeInterface ? $v->format('Y-m-d\TH:i:s.v\Z') : ($v ? (string) $v : null);
    }

    private static function num(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    public static function runHead(?Run $r): ?array
    {
        if (! $r) {
            return null;
        }

        return [
            'id' => $r->id, 'number' => $r->number, 'name' => $r->name, 'status' => $r->status, 'active' => (bool) $r->active, 'method' => $r->method, 'anchorDate' => self::day($r->anchor_date),
            'historyFrom' => self::day($r->history_from), 'historyTo' => self::day($r->history_to), 'forecastFrom' => self::day($r->forecast_from), 'forecastTo' => self::day($r->forecast_to),
            'itemCount' => (int) $r->item_count, 'shortageItemCount' => (int) $r->shortage_item_count, 'surplusItemCount' => (int) $r->surplus_item_count,
            'totalSuggestedQty' => (float) $r->total_suggested_qty, 'totalSuggestedValue' => (float) $r->total_suggested_value, 'computedAt' => self::at($r->computed_at),
            'progress' => ['done' => (int) $r->progress_done, 'total' => (int) $r->progress_total, 'phase' => ((array) $r->work)['phase'] ?? null],
            'createdAt' => self::at($r->created_at), 'createdBy' => $r->created_by,
        ] + Owned::origin($r);
    }

    public static function parameters(Run $r): array
    {
        $out = [];
        foreach (RunsService::FIELDS as $key => $column) {
            $v = $r->getAttribute($column);
            $out[$key] = $v instanceof DateTimeInterface ? $v->format('Y-m-d') : $v;
        }
        $out['budgetMonthlyAmount'] = (float) ($r->budget_monthly_amount ?? 0);

        return $out;
    }

    public static function run(Run $r): array
    {
        $planned = Plan::where('run_id', $r->id)->where('status', '!=', 'cancel')->exists();
        $mine = ! Owned::fromSource($r) && ! $planned;

        return self::runHead($r) + [
            'parameters' => self::parameters($r),
            'totals' => [
                'items' => (int) $r->item_count, 'shortage' => (int) $r->shortage_item_count, 'surplus' => (int) $r->surplus_item_count, 'periods' => (int) $r->line_count,
                'patterns' => (int) $r->pattern_count, 'events' => (int) $r->event_count, 'requirements' => (int) $r->requirement_count, 'forecastQty' => (float) $r->total_forecast_qty,
                'shortageQty' => (float) $r->total_shortage_qty, 'surplusQty' => (float) $r->total_surplus_qty, 'suggestedQty' => (float) $r->total_suggested_qty,
                'suggestedValue' => (float) $r->total_suggested_value, 'requirementValue' => (float) $r->total_requirement_value,
            ],
            'diagnostics' => $r->diagnostics, 'computeSeconds' => $r->compute_seconds, 'stockAt' => self::at($r->stock_at), 'frozenAt' => self::at($r->frozen_at), 'frozenBy' => $r->frozen_by,
            'lastError' => $r->last_error, 'note' => $r->note, 'updatedAt' => self::at($r->updated_at),
            'hasPlan' => $planned,
            'can' => ['edit' => in_array($r->status, ['draft', 'computed'], true) && $mine, 'compute' => in_array($r->status, ['draft', 'computed', 'computing'], true) && $mine,
                'freeze' => $r->status === 'computed', 'stop' => $r->status === 'computing'],
        ];
    }

    public static function item(Item $i, mixed $pushedAt): array
    {
        return [
            'id' => $i->id, 'kind' => $i->kind, 'productId' => $i->product_id, 'sku' => $i->sku, 'nameAr' => $i->product_name_ar, 'nameEn' => $i->product_name_en,
            'warehouseId' => $i->warehouse_id, 'warehouseCode' => $i->warehouse_code, 'customerId' => $i->customer_id, 'customerCode' => $i->customer_code, 'customerName' => $i->customer_name,
            'isRollup' => (bool) $i->is_rollup, 'status' => $i->status, 'criticality' => $i->product_criticality, 'onHand' => $i->qty_on_hand, 'bookedIn' => $i->incoming_qty, 'bookedOut' => $i->outgoing_qty,
            'historyTotal' => $i->history_total_qty, 'historyPeriodsUsed' => $i->history_periods_used, 'forecast' => $i->forecast_total_qty, 'customerSum' => $i->customer_sum_qty, 'perDay' => $i->avg_daily_qty,
            'beforeHorizon' => $i->current_period_demand_qty, 'minBalance' => $i->min_projected_balance, 'firstShortage' => self::day($i->first_shortage_date), 'shortage' => $i->shortage_qty,
            'surplus' => $i->surplus_qty, 'coverageDays' => $i->coverage_days, 'safety' => $i->safety_qty, 'leadDays' => $i->lead_days, 'leadSource' => $i->lead_source, 'moq' => $i->moq,
            'multiple' => $i->qty_multiple, 'suggestedQty' => $i->suggested_purchase_qty, 'orderBy' => self::day($i->suggested_purchase_date), 'priceUnit' => $i->price_unit, 'priceSource' => $i->price_source,
            'suggestedValue' => $i->suggested_purchase_value === null ? null : (float) $i->suggested_purchase_value, 'vendor' => $i->vendor_id ? ['id' => $i->vendor_id, 'code' => $i->vendor_code, 'name' => $i->vendor_name] : null,
            'suggestedMin' => $i->suggested_min_qty, 'suggestedMax' => $i->suggested_max_qty, 'currentMin' => $i->current_min_qty, 'currentMax' => $i->current_max_qty, 'orderpointState' => $i->orderpoint_state,
            'pushedAt' => self::at($pushedAt), 'methodUsed' => $i->method_used, 'fallback' => (bool) $i->method_fallback, 'fallbackReason' => $i->fallback_reason, 'confidence' => $i->confidence_score,
        ];
    }

    public static function itemDetail(Item $i, ?object $push): array
    {
        return self::item($i, $push?->at) + ['explain' => $i->explain, 'push' => $push ? ['at' => self::at($push->at), 'by' => $push->by, 'oldMin' => $push->old_min, 'oldMax' => $push->old_max,
            'newMin' => $push->new_min, 'newMax' => $push->new_max] : null, 'lastPurchasePrice' => $i->last_purchase_price];
    }

    public static function period(object $p): array
    {
        return ['index' => (int) $p->period_index, 'label' => $p->period_label, 'from' => self::day($p->date_from), 'to' => self::day($p->date_to), 'days' => (int) $p->day_count,
            'lastYear' => self::num($p->history_qty), 'forecast' => self::num($p->forecast_demand_qty), 'bookedOut' => self::num($p->scheduled_out_qty), 'bookedIn' => self::num($p->scheduled_in_qty),
            'unmet' => self::num($p->unmet_demand_qty), 'opening' => self::num($p->opening_balance_qty), 'bookedBalance' => self::num($p->booked_balance_qty), 'projected' => self::num($p->projected_balance_qty),
            'shortage' => self::num($p->shortage_qty), 'surplus' => self::num($p->surplus_qty), 'coverageDays' => self::num($p->coverage_days)];
    }

    public static function history(object $h): array
    {
        return ['index' => (int) $h->period_index, 'label' => $h->period_label, 'from' => self::day($h->date_from), 'to' => self::day($h->date_to), 'days' => (int) $h->day_count, 'qty' => self::num($h->qty), 'used' => (bool) $h->used];
    }

    public static function requirement(object $r): array
    {
        return [
            'id' => $r->id, 'itemId' => $r->item_id, 'productId' => $r->product_id, 'sku' => $r->sku, 'nameAr' => $r->product_name_ar, 'nameEn' => $r->product_name_en, 'warehouseId' => $r->warehouse_id,
            'warehouseCode' => $r->warehouse_code, 'criticality' => $r->product_criticality, 'period' => (int) $r->period_index, 'periodLabel' => $r->period_label, 'periodFrom' => self::day($r->period_from),
            'periodTo' => self::day($r->period_to), 'needDate' => self::day($r->need_date), 'orderBy' => self::day($r->trigger_date), 'late' => (bool) $r->late, 'leadDays' => self::num($r->lead_days),
            'leadSource' => $r->lead_source, 'bufferDays' => self::num($r->buffer_days), 'demand' => self::num($r->demand_qty), 'opening' => self::num($r->opening_balance_qty),
            'projected' => self::num($r->projected_balance_qty), 'safety' => self::num($r->safety_qty), 'stockout' => self::num($r->shortage_qty), 'net' => self::num($r->net_qty), 'moq' => self::num($r->moq),
            'multiple' => self::num($r->qty_multiple), 'qty' => self::num($r->qty), 'vendor' => $r->vendor_id ? ['id' => $r->vendor_id, 'name' => $r->vendor_name] : ($r->vendor_name ? ['id' => null, 'name' => $r->vendor_name] : null),
            'priceUnit' => self::num($r->price_unit), 'priceSource' => $r->price_source, 'value' => self::num($r->value), 'confidence' => self::num($r->confidence_score), 'perDay' => self::num($r->velocity_qty),
            'customerScore' => self::num($r->customer_score),
        ];
    }

    public static function pattern(object $p): array
    {
        return [
            'id' => $p->id, 'productId' => $p->product_id, 'sku' => $p->sku, 'warehouseId' => $p->warehouse_id, 'customerId' => $p->customer_id, 'customerName' => $p->customer_name,
            'customerCriticality' => $p->customer_criticality, 'orders' => (int) $p->order_count, 'firstOrder' => self::day($p->first_order_date), 'lastOrder' => self::day($p->last_order_date),
            'intervals' => $p->interval_history, 'quantities' => $p->quantity_history, 'avgInterval' => self::num($p->avg_interval_days), 'intervalStdev' => self::num($p->interval_stdev_days),
            'intervalCv' => self::num($p->interval_cv), 'avgQty' => self::num($p->avg_order_qty), 'qtyCv' => self::num($p->qty_cv), 'trend' => self::num($p->trend_factor), 'expectedQty' => self::num($p->expected_order_qty),
            'seasonal' => (bool) $p->seasonal, 'nextExpected' => self::day($p->next_expected_date), 'daysSinceLast' => $p->days_since_last === null ? null : (int) $p->days_since_last,
            'overdueDays' => (int) $p->overdue_days, 'state' => $p->state, 'confidence' => self::num($p->confidence_score), 'band' => $p->confidence_band, 'forecast' => self::num($p->forecast_qty),
            'expectedOrders' => (int) $p->expected_order_count, 'estimate' => $p->refs ? (json_decode((string) $p->refs, true)['estimate'] ?? null) : null,
        ];
    }

    public static function event(object $e): array
    {
        return [
            'id' => $e->id, 'patternId' => $e->pattern_id, 'sku' => $e->sku ?? null, 'customerName' => $e->customer_name ?? null, 'productId' => $e->product_id, 'customerId' => $e->customer_id,
            'expectedDate' => self::day($e->expected_date), 'occurrence' => (int) $e->occurrence, 'catchUp' => (bool) $e->catch_up, 'period' => $e->period_index === null ? null : (int) $e->period_index,
            'periodLabel' => $e->period_label, 'inHorizon' => (bool) $e->in_horizon, 'qty' => self::num($e->qty), 'confidence' => self::num($e->confidence_score), 'band' => $e->confidence_band,
            'actual' => $e->actual_state, 'actualDate' => self::day($e->actual_date), 'actualQty' => self::num($e->actual_qty), 'deviation' => self::num($e->qty_deviation),
        ];
    }

    public static function budgetRules(Budget $b): array
    {
        $weights = [];
        foreach (Budget::WEIGHTS as $key => $column) {
            $weights[$key] = (int) $b->getAttribute($column);
        }

        return ['weights' => $weights, 'criticalFirst' => (bool) $b->critical_first, 'allowPartial' => (bool) $b->allow_partial, 'carryDeferred' => (bool) $b->carry_deferred,
            'carryUnused' => (bool) $b->carry_unused, 'urgencyHorizonDays' => (int) $b->urgency_horizon_days];
    }

    public static function budgetHead(Budget $b): array
    {
        return ['id' => $b->id, 'runId' => $b->run_id, 'name' => $b->name, 'isPrimary' => (bool) $b->is_primary, 'status' => $b->status, 'allocatedAt' => self::at($b->allocated_at),
            'budgetTotal' => (float) $b->budget_total, 'required' => (float) $b->required_value, 'funded' => (float) $b->funded_value, 'deferred' => (float) $b->deferred_value,
            'fundedPct' => (float) $b->funded_pct, 'unused' => (float) $b->unused_value] + Owned::origin($b);
    }

    public static function budget(Budget $b): array
    {
        $planned = Plan::where('budget_id', $b->id)->where('status', '!=', 'cancel')->exists();

        return self::budgetHead($b) + self::budgetRules($b) + [
            'hasPlan' => $planned, 'can' => ['edit' => ! $planned && ! Owned::bySource($b), 'allocate' => ! $planned && ! Owned::bySource($b)],
            'note' => $b->note, 'allocatedBy' => $b->allocated_by, 'stockoutUnfunded' => (float) $b->stockout_unfunded_value,
            'counts' => ['requirements' => (int) $b->requirement_count, 'funded' => (int) $b->funded_count, 'partial' => (int) $b->partial_count, 'deferred' => (int) $b->deferred_count,
                'criticalDeferred' => (int) $b->critical_deferred_count, 'atRisk' => (int) $b->at_risk_count],
            'lines' => $b->lines->sortBy(fn ($l) => $l->month->format('Y-m-d'))->values()->map(fn ($l) => ['id' => $l->id, 'month' => $l->month->format('Y-m'), 'amount' => (float) $l->amount,
                'carriedIn' => (float) $l->carried_in, 'due' => (float) $l->required_value, 'funded' => (float) $l->funded_value, 'remaining' => (float) $l->remaining_value])->all(),
        ];
    }

    public static function allocation(object $a): array
    {
        return [
            'id' => $a->id, 'requirementId' => $a->requirement_id, 'sku' => $a->sku, 'nameAr' => $a->product_name_ar, 'nameEn' => $a->product_name_en, 'warehouseCode' => $a->warehouse_code,
            'vendorName' => $a->vendor_name, 'criticality' => $a->product_criticality, 'needDate' => self::day($a->need_date), 'priceUnit' => self::num($a->price_unit), 'periodLabel' => $a->period_label,
            'month' => $a->month ? substr((string) $a->month, 0, 7) : null, 'orderDate' => self::day($a->order_date), 'rank' => (int) $a->rank, 'mustFund' => (bool) $a->must_fund, 'score' => self::num($a->score),
            'scores' => ['urgency' => self::num($a->score_urgency), 'stockout' => self::num($a->score_stockout), 'criticality' => self::num($a->score_criticality), 'velocity' => self::num($a->score_velocity),
                'confidence' => self::num($a->score_confidence), 'customer' => self::num($a->score_customer), 'leadTime' => self::num($a->score_lead_time)],
            'requiredQty' => self::num($a->required_qty), 'fundedQty' => self::num($a->funded_qty), 'requiredValue' => self::num($a->required_value), 'fundedValue' => self::num($a->funded_value),
            'status' => $a->status, 'carriedFrom' => $a->carried_from ? substr((string) $a->carried_from, 0, 7) : null, 'atRisk' => (bool) $a->at_risk,
            'reason' => $a->reason_code ? ['code' => $a->reason_code, 'ar' => $a->reason_ar, 'en' => $a->reason_en] : null,
        ];
    }

    public static function planHead(Plan $p): array
    {
        return ['id' => $p->id, 'number' => $p->number, 'name' => $p->name, 'status' => $p->status, 'runId' => $p->run_id, 'budgetId' => $p->budget_id, 'dateFrom' => self::day($p->date_from),
            'dateTo' => self::day($p->date_to), 'rfqGrouping' => $p->rfq_grouping, 'planner' => $p->planner_name, 'createdAt' => self::at($p->created_at)] + Owned::origin($p);
    }

    public static function plan(Plan $p): array
    {
        return self::planHead($p) + ['note' => $p->note, 'submittedAt' => self::at($p->submitted_at), 'submittedBy' => $p->submitted_by, 'approvedAt' => self::at($p->approved_at),
            'approvedBy' => $p->approved_by, 'closedAt' => self::at($p->closed_at), 'closedBy' => $p->closed_by, 'closeNote' => $p->close_note, 'cancelledAt' => self::at($p->cancelled_at),
            'cancelledBy' => $p->cancelled_by, 'cancelReason' => $p->cancel_reason, 'returnReason' => $p->return_reason];
    }

    public static function planLine(PlanLine $l): array
    {
        return ['id' => $l->id, 'lineNo' => (int) $l->line_no, 'allocationId' => $l->allocation_id, 'requirementId' => $l->requirement_id, 'productId' => $l->product_id, 'sku' => $l->sku,
            'warehouseId' => $l->warehouse_id, 'vendor' => $l->vendor_id || $l->vendor_name ? ['id' => $l->vendor_id, 'name' => $l->vendor_name] : null, 'qty' => (float) $l->qty,
            'priceUnit' => $l->price_unit === null ? null : (float) $l->price_unit, 'subtotal' => (float) $l->subtotal, 'orderDate' => self::day($l->order_date), 'needDate' => self::day($l->need_date),
            'leadDays' => $l->lead_days === null ? null : (float) $l->lead_days, 'cancelled' => (bool) $l->cancelled, 'cancelReason' => $l->cancel_reason, 'note' => $l->note,
            'requestedAt' => self::at($l->requested_at), 'requestedBy' => $l->requested_by];
    }
}
