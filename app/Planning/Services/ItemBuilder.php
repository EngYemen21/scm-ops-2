<?php

namespace App\Planning\Services;

use App\Planning\Engine\Cadence;
use App\Planning\Engine\Calendar;
use App\Planning\Engine\Forecaster;
use App\Planning\Engine\Num;
use App\Planning\Engine\Period;
use App\Planning\Engine\Projection;
use App\Planning\Models\Run;
use Illuminate\Support\Str;

/**
 * One product at one warehouse of a run: from its demand of the past, its stock and its supplier to the rows the run
 * keeps — the item (with every number behind its suggestion in `explain`), its forecast periods, its history periods,
 * what must be bought (requirements), and for the cadence method the reorder pattern of each customer with the orders
 * it expects. Pure arithmetic on what the computation read; the engine classes do the forecasting.
 *
 * CONFIDENCE (0–100) of a forecast that works on periods = 100 × (0.6 × regularity + 0.4 × depth), where regularity =
 * 1 − the variability of the daily rate of the periods used (kept in 0…1) and depth = periods used ÷ 6 (at most 1); a
 * forecast that fell back to another method keeps 80 % of it; no demand = 0. The cadence method takes the confidence of
 * its customers' patterns, weighted by what each is expected to buy.
 *
 * CUSTOMER CRITICALITY SCORE of a requirement: the criticality of the customers behind the demand of the history
 * (critical 100 · high 75 · normal 50 · low 25), weighted by each customer's share; demand without a known customer
 * counts as normal.
 */
class ItemBuilder
{
    public const LEVEL = ['critical' => 100.0, 'high' => 75.0, 'normal' => 50.0, 'low' => 25.0];

    /** @var array{items:array, periods:array, history:array, requirements:array, patterns:array, events:array} */
    public array $rows = ['items' => [], 'periods' => [], 'history' => [], 'requirements' => [], 'patterns' => [], 'events' => []];

    /**
     * @param  array{history:Period[], horizon:Period[], anchor:string, gap:int, now:string, warehouses:array<string, object>, customers:array<string, array{name:?string, code:?string, criticality:string}>}  $ctx
     * @param  array<string, array<int, object>>  $facts  warehouse → demand rows (customer_key, day, qty, returned, origin)
     * @param  array{onHand:array<string,float>, in:array<string,array>, out:array<string,array>}  $stock  by warehouse
     * @param  array  $quote  PriceReader::quote()
     */
    public function product(Run $run, array $ctx, object $product, array $warehouses, array $facts, array $stock, array $quote, string $criticality, PriceReader $prices): void
    {
        $stockItems = [];
        foreach ($warehouses as $wh) {
            $stockItems[] = $this->warehouse($run, $ctx, $product, $wh, $facts[$wh] ?? [], $stock, $quote, $criticality, $prices);
        }
        // the reorder settings of OPS are kept per product: the run's suggestion for the product is the sum over its warehouses
        $sumMin = array_sum(array_map(fn ($i) => $i['suggested_min_qty'], $stockItems));
        $sumMax = array_sum(array_map(fn ($i) => $i['suggested_max_qty'], $stockItems));
        $curMin = (int) ($product->reorder_min ?? 0);
        $curMax = $product->reorder_max === null ? null : (int) $product->reorder_max;
        $state = $curMin <= 0 && $curMax === null ? 'missing' : ($curMin === (int) $sumMin && $curMax === (int) $sumMax ? 'matches' : 'differs');
        foreach ($stockItems as $i => $item) {
            $this->rows['items'][$item['__index']]['orderpoint_state'] = $state;
            $this->rows['items'][$item['__index']]['explain']['minMax'] += ['productMin' => (int) $sumMin, 'productMax' => (int) $sumMax, 'currentMin' => $curMin, 'currentMax' => $curMax];
        }
    }

    /** @return array the stock item as written (with its position in the rows) */
    private function warehouse(Run $run, array $ctx, object $product, string $wh, array $facts, array $stock, array $quote, string $criticality, PriceReader $prices): array
    {
        [$history, $horizon] = [$ctx['history'], $ctx['horizon']];
        $returns = (bool) $run->include_returns;
        $pooled = [];
        $byCustomer = [];
        $origins = ['ops' => 0.0, 'odoo' => 0.0];
        $returned = 0.0;
        foreach ($facts as $f) {
            $net = (float) $f->qty - ($returns ? (float) $f->returned : 0.0);
            $pooled[$f->day] = ($pooled[$f->day] ?? 0.0) + $net;
            $key = $f->customer_key ?? '';
            $byCustomer[$key][$f->day] = ($byCustomer[$key][$f->day] ?? 0.0) + $net;
            $origins[$f->origin] = ($origins[$f->origin] ?? 0.0) + (float) $f->qty;
            $returned += (float) $f->returned;
        }
        ksort($byCustomer, SORT_STRING);
        $opt = ['wmaWindow' => (int) ($run->wma_window ?: 3), 'growthPct' => (float) $run->growth_pct, 'seasonalScale' => (bool) $run->seasonal_scale];
        $method = $run->method;
        $split = $run->customer_mode === 'split';
        $patterns = [];
        $customerForecasts = [];

        // ── the forecast of the warehouse
        if ($method === 'cadence') {
            $f = $this->cadence($run, $ctx, $pooled, $byCustomer, $opt, $patterns, $customerForecasts);
        } else {
            $f = Forecaster::run($method, $pooled, $history, $horizon, $opt);
            $f['beforeQty'] = Num::q($f['dailyRate'] * max(0.0, 1 + (float) $run->growth_pct / 100) * $ctx['gap']);
            $f['confidence'] = self::confidence($f, $history);
            if ($split) {
                foreach ($byCustomer as $key => $daily) {
                    $customerForecasts[$key] = Forecaster::run($method, $daily, $history, $horizon, $opt);
                }
                if ($run->rollup_method === 'sum') {
                    $f = $this->summed($f, $customerForecasts, $horizon, $ctx['gap'], (float) $run->growth_pct);
                }
            }
        }
        $horizonDays = array_sum(array_map(fn (Period $p) => $p->days, $horizon));
        $avgDaily = $horizonDays ? $f['forecastTotal'] / $horizonDays : 0.0;

        // ── stock, supplier, safety
        $onHand = (float) ($stock['onHand'][$wh] ?? 0);
        [$in, $inDocs] = self::bucket($stock['in'][$wh] ?? [], $horizon);
        [$out, $outDocs] = self::bucket($stock['out'][$wh] ?? [], $horizon);
        $lead = (float) $quote['leadDays'];
        $buffer = max(0.0, (float) $run->planning_buffer_days);
        $safety = Projection::safety($run->safety_method, $avgDaily, (float) $f['sigmaDaily'], (float) $run->safety_days, $run->service_level, $lead, $buffer);
        $cover = max(0.0, (float) $run->target_cover_days);
        $maxLevel = $cover > 0 ? $safety['qty'] + $avgDaily * $cover : 0.0;
        $multiple = $run->qty_multiple_source === 'pack' ? max(1.0, (float) ($product->pack_qty ?? 1)) : 1.0;
        $moq = (float) $quote['moq'];
        $projection = Projection::run($horizon, $f['forecast'], $in, $out, ['onHand' => $onHand, 'before' => $f['beforeQty'], 'netting' => $run->demand_netting, 'safety' => $safety['qty'], 'maxLevel' => $maxLevel, 'avgDaily' => $avgDaily]);
        $reqs = Projection::requirements($horizon, $f['forecast'], $in, $out, ['onHand' => $onHand, 'before' => $f['beforeQty'], 'netting' => $run->demand_netting, 'safety' => $safety['qty'],
            'moq' => $moq, 'multiple' => $multiple, 'leadDays' => $lead, 'bufferDays' => $buffer, 'anchor' => $ctx['anchor']]);
        $suggested = array_sum(array_column($reqs, 'qty'));
        $price = $quote['price'];
        $tier = null;
        if ($reqs && $quote['vendor'] && $quote['priceSource'] === 'vendor' && $reqs[0]['qty'] > 1) {
            $tier = $prices->priceFor($quote['vendor']['id'], (string) $product->id, (float) $reqs[0]['qty'], $ctx['anchor']);
            $price = $tier ?? $price;
        }
        $minMax = Projection::minMax($safety['qty'], $avgDaily, $lead, $buffer, $cover);
        $status = $f['forecastTotal'] <= 0 && $projection['bookedOut'] <= 0 ? 'no_demand' : ($projection['shortage'] > 1e-9 ? 'shortage' : ($projection['surplus'] > 1e-9 ? 'surplus' : 'ok'));
        $customerScore = $this->customerScore($byCustomer, $ctx['customers']);
        $coverage = $avgDaily > 0 ? Num::m(max(0.0, $onHand - $f['beforeQty']) / $avgDaily) : null;

        $itemId = (string) Str::ulid();
        $warehouse = $ctx['warehouses'][$wh] ?? null;
        $base = [
            'run_id' => $run->id, 'product_id' => (string) $product->id, 'sku' => (string) $product->sku, 'product_name_ar' => $product->name_ar, 'product_name_en' => $product->name_en,
            'category_id' => $product->category_id, 'warehouse_id' => $wh, 'warehouse_code' => $warehouse->code ?? null, 'product_criticality' => $criticality,
        ];
        $explain = [
            'method' => ['asked' => $f['asked'], 'used' => $f['used'], 'fallback' => $f['fallback'], 'reason' => $f['reason'], 'detail' => $f['detail']],
            'history' => ['periods' => count($history), 'used' => $f['periodsUsed'], 'firstUsed' => $f['firstUsed'], 'total' => $f['total'], 'mean' => $f['mean'], 'stdev' => $f['stdev'],
                'sigmaDaily' => round((float) $f['sigmaDaily'], 6), 'returns' => Num::q($returned), 'returnsNetted' => $returns, 'from' => ['ops' => Num::q($origins['ops'] ?? 0), 'odoo' => Num::q($origins['odoo'] ?? 0)]],
            'forecast' => ['total' => $f['forecastTotal'], 'perDay' => round($avgDaily, 6), 'beforeHorizon' => $f['beforeQty'], 'gapDays' => $ctx['gap'], 'growthPct' => (float) $run->growth_pct, 'horizonDays' => $horizonDays],
            'stock' => ['onHand' => Num::q($onHand), 'bookedIn' => $projection['bookedIn'], 'bookedOut' => $projection['bookedOut'], 'opening' => $projection['opening'], 'inDocs' => $inDocs, 'outDocs' => $outDocs, 'netting' => $run->demand_netting],
            'safety' => $safety, 'maxLevel' => Num::q($maxLevel), 'coverDays' => $cover,
            'lead' => ['days' => $lead, 'source' => $quote['leadSource'], 'bufferDays' => $buffer, 'vendor' => $quote['vendor']],
            'price' => ['price' => $price, 'source' => $quote['priceSource'], 'reason' => $quote['priceReason'], 'quotedQty' => $quote['quotedQty'], 'tierPrice' => $tier],
            'rounding' => ['moq' => Num::q($moq), 'multiple' => $multiple, 'multipleSource' => $run->qty_multiple_source],
            'minMax' => ['min' => $minMax['min'], 'max' => $minMax['max'], 'safety' => $safety['qty'], 'leadDemand' => Num::q($avgDaily * ($lead + $buffer)), 'coverDemand' => Num::q($avgDaily * $cover)],
            'confidence' => $f['confidence'], 'customerScore' => $customerScore,
            'firstShortage' => $projection['firstShortage'], 'minBalance' => $projection['minBalance'],
        ];
        $item = $base + [
            'id' => $itemId, 'kind' => 'stock', 'customer_id' => null, 'customer_code' => null, 'customer_name' => null, 'is_rollup' => $split,
            'qty_on_hand' => Num::q($onHand), 'incoming_qty' => $projection['bookedIn'], 'outgoing_qty' => $projection['bookedOut'],
            'history_total_qty' => $f['total'], 'history_avg_qty' => $f['mean'], 'history_stdev_qty' => $f['stdev'], 'history_periods_used' => $f['periodsUsed'],
            'forecast_total_qty' => $f['forecastTotal'], 'customer_sum_qty' => $split || $method === 'cadence' ? Num::q(array_sum(array_map(fn ($c) => $c['forecastTotal'], $customerForecasts))) : null,
            'avg_daily_qty' => round($avgDaily, 6), 'current_period_demand_qty' => $f['beforeQty'], 'min_projected_balance' => $projection['minBalance'], 'first_shortage_date' => $projection['firstShortage'],
            'shortage_qty' => $projection['shortage'], 'surplus_qty' => $projection['surplus'], 'coverage_days' => $coverage, 'status' => $status, 'safety_qty' => $safety['qty'],
            'lead_days' => $lead, 'lead_source' => $quote['leadSource'], 'qty_multiple' => $multiple, 'moq' => Num::q($moq),
            'suggested_purchase_qty' => (float) $suggested, 'suggested_purchase_date' => $reqs[0]['orderBy'] ?? null, 'suggested_purchase_value' => $price !== null ? Num::m($suggested * $price) : 0.0,
            'last_purchase_price' => $ctx['last'][(string) $product->id]['price'] ?? null, 'price_unit' => $price, 'price_source' => $quote['priceSource'],
            'vendor_id' => $quote['vendor']['id'] ?? null, 'vendor_code' => $quote['vendor']['code'] ?? null, 'vendor_name' => $quote['vendor']['name'] ?? null,
            'suggested_min_qty' => (float) $minMax['min'], 'suggested_max_qty' => (float) $minMax['max'], 'current_min_qty' => (float) ($product->reorder_min ?? 0),
            'current_max_qty' => $product->reorder_max === null ? null : (float) $product->reorder_max, 'orderpoint_state' => 'missing',
            'method_used' => $f['used'], 'method_fallback' => (bool) $f['fallback'], 'fallback_reason' => $f['reason'] ? ($f['reason']['code'] ?? null) : null,
            'confidence_score' => $f['confidence'], 'explain' => $explain,
        ];
        $item['__index'] = count($this->rows['items']);
        $this->rows['items'][] = $item;

        foreach ($projection['periods'] as $p) {
            $this->rows['periods'][] = ['id' => (string) Str::ulid(), 'run_id' => $run->id, 'item_id' => $itemId, 'period_index' => $p['index'], 'period_label' => $p['label'], 'date_from' => $p['from'], 'date_to' => $p['to'],
                'day_count' => $p['days'], 'history_qty' => $f['sameDatesLastYear'][$p['index']] ?? null, 'forecast_demand_qty' => $p['forecast'], 'scheduled_out_qty' => $p['bookedOut'], 'scheduled_in_qty' => $p['bookedIn'],
                'unmet_demand_qty' => $p['unmet'], 'opening_balance_qty' => $p['opening'], 'booked_balance_qty' => $p['bookedBalance'], 'projected_balance_qty' => $p['projected'], 'shortage_qty' => $p['shortage'],
                'surplus_qty' => $p['surplus'], 'coverage_days' => $p['coverageDays']];
        }
        $this->history($run, $itemId, $history, $f);
        foreach ($reqs as $r) {
            $value = $price !== null ? Num::m($r['qty'] * $price) : 0.0;
            $this->rows['requirements'][] = $base + [
                'id' => (string) Str::ulid(), 'item_id' => $itemId, 'period_index' => $r['period'], 'period_label' => $r['label'], 'period_from' => $r['from'], 'period_to' => $r['to'], 'need_date' => $r['needDate'],
                'trigger_date' => $r['orderBy'], 'late' => $r['late'], 'lead_days' => $r['leadDays'], 'lead_source' => $quote['leadSource'], 'buffer_days' => $r['bufferDays'], 'demand_qty' => $r['demand'],
                'opening_balance_qty' => $r['opening'], 'projected_balance_qty' => $r['projected'], 'safety_qty' => $r['safety'], 'shortage_qty' => $r['stockout'], 'net_qty' => $r['net'], 'moq' => $r['moq'],
                'qty_multiple' => $r['multiple'], 'qty' => (float) $r['qty'], 'vendor_id' => $quote['vendor']['id'] ?? null, 'vendor_name' => $quote['vendor']['name'] ?? null, 'price_unit' => $price,
                'price_source' => $quote['priceSource'], 'value' => $value, 'confidence_score' => $f['confidence'], 'velocity_qty' => round($avgDaily, 6), 'customer_score' => $customerScore,
            ];
        }
        // the share of each customer: its own forecast (split) and its reorder pattern (cadence)
        foreach ($patterns as $p) {
            $this->pattern($run, $base, $p, $ctx);
        }
        if ($split) {
            foreach ($customerForecasts as $key => $cf) {
                $this->customerItem($run, $ctx, $base, $key, $cf, $history);
            }
        }

        return $item;
    }

    /**
     * The cadence method: each customer's reorder pattern gives its expected orders; a customer with too few orders is
     * estimated with the weighted moving average of its own demand (or left out, as the run says).
     */
    private function cadence(Run $run, array $ctx, array $pooled, array $byCustomer, array $opt, array &$patterns, array &$customerForecasts): array
    {
        [$history, $horizon] = [$ctx['history'], $ctx['horizon']];
        $base = Forecaster::run('average', $pooled, $history, $horizon, ['growthPct' => 0]); // the history figures of the item
        $season = $run->cadence_use_seasonality ? Cadence::seasonFactor($pooled, $run->day('history_from'), $run->day('history_to'), $run->day('forecast_from'), $run->day('forecast_to')) : null;
        $copt = ['anchor' => $ctx['anchor'], 'horizonFrom' => $run->day('forecast_from'), 'horizonTo' => $run->day('forecast_to'), 'minOrders' => (int) ($run->cadence_min_orders ?: 3),
            'intervalWindow' => (int) ($run->cadence_interval_window ?: 6), 'lapseFactor' => (float) ($run->cadence_lapse_factor ?: 3), 'useTrend' => (bool) $run->cadence_use_trend,
            'trendCapPct' => (float) ($run->cadence_trend_cap_pct ?? 25), 'seasonFactor' => $season, 'growthPct' => (float) $run->growth_pct];
        $forecast = array_fill_keys(array_map(fn (Period $p) => $p->index, $horizon), 0.0);
        $before = 0.0;
        $estimated = $lapsed = $active = 0;
        $weighted = $weights = 0.0;
        foreach ($byCustomer as $key => $daily) {
            $pattern = Cadence::pattern(Cadence::orders($daily), $copt, $horizon);
            $pattern['customer'] = $key;
            $pattern['estimate'] = null;
            if ($pattern['state'] === 'insufficient' && $run->cadence_fallback !== 'ignore') {
                $est = Forecaster::run('wma', $daily, $history, $horizon, $opt);
                $pattern['estimate'] = ['forecastTotal' => $est['forecastTotal'], 'used' => $est['used']];
                foreach ($est['forecast'] as $i => $q) {
                    $forecast[$i] += $q;
                }
                $before += $est['dailyRate'] * max(0.0, 1 + (float) $run->growth_pct / 100) * $ctx['gap'];
                $estimated += $est['forecastTotal'] > 0 ? 1 : 0;
                $customerForecasts[$key] = $est + ['confidence' => self::confidence($est, $history) * 0.8];
                $weighted += $est['forecastTotal'] * $customerForecasts[$key]['confidence'];
                $weights += $est['forecastTotal'];
            } else {
                foreach ($pattern['perPeriod'] as $i => $q) {
                    $forecast[$i] += $q;
                }
                $before += $pattern['beforeHorizonQty'];
                $lapsed += $pattern['state'] === 'lapsed' ? 1 : 0;
                $active += in_array($pattern['state'], ['active', 'overdue'], true) ? 1 : 0;
                $customerForecasts[$key] = ['forecastTotal' => $pattern['forecastQty'], 'forecast' => $pattern['perPeriod']];
                $weighted += $pattern['forecastQty'] * $pattern['confidence'];
                $weights += $pattern['forecastQty'];
            }
            $patterns[] = $pattern;
        }
        $forecast = array_map(fn ($q) => Num::q($q), $forecast);
        $total = Num::q(array_sum($forecast));
        $out = $base;
        $out['asked'] = 'cadence';
        $out['used'] = $byCustomer ? 'cadence' : 'none';
        $out['fallback'] = $estimated > 0;
        $out['reason'] = ! $byCustomer ? ['code' => 'no_history', 'ar' => 'لا طلب في فترة السجل', 'en' => 'No demand in the history window']
            : ($estimated > 0 ? ['code' => 'cadence_estimate', 'ar' => "{$estimated} من العملاء طلباتهم أقل من الحد الأدنى — قُدّر طلبهم بالمتوسط المتحرك المرجّح",
                'en' => "{$estimated} customer(s) below the minimum number of orders — estimated with the weighted moving average"] : null);
        $out['forecast'] = $forecast;
        $out['forecastTotal'] = $total;
        $out['beforeQty'] = Num::q($before);
        $out['detail'] = ['customers' => count($byCustomer), 'active' => $active, 'lapsed' => $lapsed, 'estimated' => $estimated, 'seasonFactor' => $season, 'growthPct' => (float) $run->growth_pct];
        $out['confidence'] = $weights > 0 ? Num::m($weighted / $weights) : 0.0;

        return $out;
    }

    /** The warehouse total as the sum of the customers' forecasts (split by customer, "sum"). */
    private function summed(array $pooled, array $customers, array $horizon, int $gap, float $growth): array
    {
        $forecast = array_fill_keys(array_map(fn (Period $p) => $p->index, $horizon), 0.0);
        $rate = 0.0;
        foreach ($customers as $c) {
            foreach ($c['forecast'] as $i => $q) {
                $forecast[$i] += $q;
            }
            $rate += $c['dailyRate'];
        }
        $pooled['forecast'] = array_map(fn ($q) => Num::q($q), $forecast);
        $pooled['forecastTotal'] = Num::q(array_sum($pooled['forecast']));
        $pooled['beforeQty'] = Num::q($rate * max(0.0, 1 + $growth / 100) * $gap);
        $pooled['detail']['rollup'] = 'sum';

        return $pooled;
    }

    private function history(Run $run, string $itemId, array $history, array $f): void
    {
        foreach ($history as $p) {
            $this->rows['history'][] = ['id' => (string) Str::ulid(), 'run_id' => $run->id, 'item_id' => $itemId, 'period_index' => $p->index, 'period_label' => $p->label, 'date_from' => $p->from,
                'date_to' => $p->to, 'day_count' => $p->days, 'qty' => $f['history'][$p->index] ?? 0.0, 'used' => $f['firstUsed'] !== null && $p->index >= $f['firstUsed'], 'source' => $run->history_source];
        }
    }

    /** A customer's share of a run split by customer: its history and its forecast (no stock: stock is the warehouse's). */
    private function customerItem(Run $run, array $ctx, array $base, string $key, array $cf, array $history): void
    {
        $c = $ctx['customers'][$key] ?? ['name' => null, 'code' => null, 'criticality' => 'normal'];
        $id = (string) Str::ulid();
        $horizonDays = array_sum(array_map(fn (Period $p) => $p->days, $ctx['horizon']));
        $total = (float) ($cf['forecastTotal'] ?? 0);
        $this->rows['items'][] = $base + [
            'id' => $id, 'kind' => 'customer', 'customer_id' => $key === '' ? null : $key, 'customer_code' => $c['code'], 'customer_name' => $c['name'], 'is_rollup' => false,
            'history_total_qty' => $cf['total'] ?? null, 'history_avg_qty' => $cf['mean'] ?? null, 'history_stdev_qty' => $cf['stdev'] ?? null, 'history_periods_used' => $cf['periodsUsed'] ?? null,
            'forecast_total_qty' => Num::q($total), 'avg_daily_qty' => $horizonDays ? round($total / $horizonDays, 6) : 0.0, 'status' => $total > 0 ? 'ok' : 'no_demand',
            'method_used' => $cf['used'] ?? $run->method, 'method_fallback' => (bool) ($cf['fallback'] ?? false), 'fallback_reason' => isset($cf['reason']['code']) ? $cf['reason']['code'] : null,
            'confidence_score' => isset($cf['periodsUsed']) ? self::confidence($cf, $history) : null,
            'explain' => ['method' => ['asked' => $cf['asked'] ?? $run->method, 'used' => $cf['used'] ?? $run->method, 'fallback' => $cf['fallback'] ?? false, 'reason' => $cf['reason'] ?? null, 'detail' => $cf['detail'] ?? null]],
        ];
        if (isset($cf['history'])) {
            $this->history($run, $id, $history, $cf);
        }
        foreach ($ctx['horizon'] as $p) {
            $this->rows['periods'][] = ['id' => (string) Str::ulid(), 'run_id' => $run->id, 'item_id' => $id, 'period_index' => $p->index, 'period_label' => $p->label, 'date_from' => $p->from, 'date_to' => $p->to,
                'day_count' => $p->days, 'history_qty' => $cf['sameDatesLastYear'][$p->index] ?? null, 'forecast_demand_qty' => Num::q((float) ($cf['forecast'][$p->index] ?? 0))];
        }
    }

    private function pattern(Run $run, array $base, array $p, array $ctx): void
    {
        $key = $p['customer'];
        $c = $ctx['customers'][$key] ?? ['name' => null, 'code' => null, 'criticality' => 'normal'];
        $id = (string) Str::ulid();
        $this->rows['patterns'][] = [
            'id' => $id, 'run_id' => $run->id, 'product_id' => $base['product_id'], 'sku' => $base['sku'], 'warehouse_id' => $base['warehouse_id'], 'customer_id' => $key === '' ? null : $key,
            'customer_name' => $c['name'], 'customer_criticality' => $c['criticality'], 'order_count' => $p['orderCount'], 'first_order_date' => $p['firstOrder'], 'last_order_date' => $p['lastOrder'],
            'interval_history' => mb_substr(implode(', ', $p['intervals']), 0, 250), 'quantity_history' => mb_substr(implode(', ', array_map(fn ($q) => rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.'), $p['quantities'])), 0, 250),
            'avg_interval_days' => $p['avgInterval'], 'interval_stdev_days' => $p['intervalStdev'], 'interval_cv' => $p['intervalCv'], 'avg_order_qty' => $p['avgQty'], 'qty_cv' => $p['qtyCv'],
            'trend_factor' => $p['trendFactor'], 'expected_order_qty' => $p['expectedQty'], 'seasonal' => $p['seasonal'], 'next_expected_date' => $p['nextExpected'], 'days_since_last' => $p['daysSinceLast'],
            'overdue_days' => $p['overdueDays'], 'state' => $p['state'], 'confidence_score' => $p['confidence'], 'confidence_band' => $p['band'],
            'forecast_qty' => $p['estimate'] ? $p['estimate']['forecastTotal'] : $p['forecastQty'], 'expected_order_count' => $p['expectedOrders'],
            'refs' => $p['estimate'] ? ['estimate' => $p['estimate']] : null,
        ];
        $labels = [];
        foreach ($ctx['horizon'] as $h) {
            $labels[$h->index] = $h->label;
        }
        foreach ($p['events'] as $e) {
            $this->rows['events'][] = [
                'id' => (string) Str::ulid(), 'run_id' => $run->id, 'pattern_id' => $id, 'product_id' => $base['product_id'], 'warehouse_id' => $base['warehouse_id'], 'customer_id' => $key === '' ? null : $key,
                'expected_date' => $e['day'], 'occurrence' => $e['occurrence'], 'catch_up' => $e['catchUp'], 'period_index' => $e['period'], 'period_label' => $e['period'] !== null ? $labels[$e['period']] : null,
                'in_horizon' => $e['inHorizon'], 'qty' => $e['qty'], 'confidence_score' => $e['confidence'], 'confidence_band' => $p['band'], 'actual_state' => 'pending',
            ];
        }
    }

    /** Booked documents by forecast period: one dated before the forecast counts in the first period, one after it is outside. @return array{0:array<int,float>, 1:array} */
    private static function bucket(array $docs, array $horizon): array
    {
        $by = [];
        $list = [];
        if (! $horizon) {
            return [[], []];
        }
        $first = $horizon[0];
        foreach ($docs as $d) {
            $index = $d['day'] < $first->from ? $first->index : Calendar::indexOf($horizon, $d['day']);
            if ($index === null) {
                continue;
            }
            $by[$index] = ($by[$index] ?? 0.0) + $d['qty'];
            if (count($list) < 25) {
                $list[] = ['ref' => $d['ref'], 'day' => $d['day'], 'qty' => Num::q($d['qty']), 'period' => $index];
            }
        }

        return [$by, $list];
    }

    /** See the class header. */
    public static function confidence(array $f, array $history): float
    {
        if (($f['used'] ?? 'none') === 'none' || ($f['periodsUsed'] ?? 0) === 0) {
            return 0.0;
        }
        $rates = [];
        foreach ($history as $p) {
            if ($f['firstUsed'] !== null && $p->index >= $f['firstUsed']) {
                $rates[] = (float) ($f['history'][$p->index] ?? 0) / $p->days;
            }
        }
        $regular = Num::clamp(1 - Num::cv($rates), 0, 1);
        $depth = min(1.0, count($rates) / 6);
        $score = 100 * (0.6 * $regular + 0.4 * $depth) * (! empty($f['fallback']) ? 0.8 : 1.0);

        return Num::m($score);
    }

    /** @param array<string, array<string, float>> $byCustomer */
    private function customerScore(array $byCustomer, array $customers): float
    {
        $sum = $weighted = 0.0;
        foreach ($byCustomer as $key => $daily) {
            $qty = max(0.0, array_sum($daily));
            $level = self::LEVEL[$customers[$key]['criticality'] ?? 'normal'] ?? 50.0;
            $sum += $qty;
            $weighted += $qty * $level;
        }

        return $sum > 0 ? Num::m($weighted / $sum) : 50.0;
    }
}
