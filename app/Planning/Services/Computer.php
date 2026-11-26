<?php

namespace App\Planning\Services;

use App\Planning\Engine\Calendar;
use App\Planning\Models\Budget;
use App\Planning\Models\Run;
use App\Planning\Support\Clock;
use App\Planning\Support\Refs;
use App\Support\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Computes a run in resumable slices. A run being computed is `computing` and carries where it stands (`work`) and
 * its progress (`progress_done` of `progress_total`); every slice is one transaction that writes its rows AND the new
 * position together, so a computation that is stopped — a request that ends, a server that restarts — continues
 * exactly where it stood and never writes a row twice. Anyone may push it on: the endpoint `POST runs/{id}/advance`,
 * the command `php artisan scm:planning-compute`, or the call that started it.
 *
 * THE PHASES
 *   collect  the demand of the history window into the run's own table (HistoryCollector), Odoo's copy read in slices;
 *   items    the products of the run, a few at a time: stock, supplier, forecast, projection, what to buy (ItemBuilder);
 *   finish   the totals of the run, its diagnostics, and — when the run has a monthly budget — its own budget scenario,
 *            allocated.
 * Only one worker advances a run at a time: a worker claims the run for the time it is given (`lease_until`); a second
 * one that comes meanwhile answers with the run as it stands and does nothing.
 */
class Computer
{
    /** Products per slice of the items phase. */
    public const PRODUCTS_PER_SLICE = 25;

    public function __construct(
        private readonly StockReader $stock,
        private readonly PriceReader $prices,
        private readonly BudgetsService $budgets,
    ) {}

    /** Empties what an earlier computation wrote and puts the run at the start. The caller holds the run's lock. */
    public function reset(Run $run): void
    {
        $budgets = Budget::where('run_id', $run->id)->pluck('id');
        DB::table('planning_allocations')->whereIn('budget_id', $budgets)->delete();
        DB::table('planning_budget_lines')->whereIn('budget_id', $budgets)->update(['carried_in' => 0, 'required_value' => 0, 'funded_value' => 0, 'remaining_value' => 0]);
        Budget::whereIn('id', $budgets)->update(['status' => 'draft', 'allocated_at' => null, 'allocated_by' => null, 'required_value' => 0, 'funded_value' => 0, 'deferred_value' => 0, 'funded_pct' => 0,
            'unused_value' => 0, 'stockout_unfunded_value' => 0, 'requirement_count' => 0, 'funded_count' => 0, 'partial_count' => 0, 'deferred_count' => 0, 'critical_deferred_count' => 0, 'at_risk_count' => 0]);
        foreach (['planning_events', 'planning_patterns', 'planning_requirements', 'planning_periods', 'planning_history', 'planning_items', 'planning_demand', 'planning_prices'] as $table) {
            DB::table($table)->where('run_id', $run->id)->delete();
        }
        $run->forceFill([
            'status' => 'computing', 'work' => ['phase' => 'collect', 'collect' => [], 'products' => null, 'next' => 0,
                // the newest Odoo order line the copy held when the run read its history: later ones are "what happened next"
                'watermark' => (int) DB::table('odoo_records')->where('model', 'sale.order.line')->max('odoo_id')], 'progress_done' => 0, 'progress_total' => HistoryCollector::estimate($run) + 1,
            'compute_started_at' => now(), 'computed_at' => null, 'compute_seconds' => null, 'last_error' => null, 'stock_at' => null, 'lease_until' => null,
        ] + array_fill_keys(self::TOTALS, 0))->save();
    }

    public const TOTALS = ['item_count', 'shortage_item_count', 'surplus_item_count', 'line_count', 'pattern_count', 'event_count', 'requirement_count', 'total_forecast_qty',
        'total_shortage_qty', 'total_surplus_qty', 'total_suggested_qty', 'total_suggested_value', 'total_requirement_value'];

    /**
     * Pushes a computing run on for at most `$seconds` (one slice at least) — or for `$slices` slices.
     *
     * @return bool true when this call did work, false when another worker holds the run
     */
    public function advance(string $runId, float $seconds = 8.0, ?int $slices = null): bool
    {
        $until = microtime(true) + max(0.5, $seconds);
        $token = (string) Str::ulid();
        $claimed = DB::table('planning_runs')->where('id', $runId)->where('status', 'computing')
            ->where(fn ($w) => $w->whereNull('lease_until')->orWhere('lease_until', '<', now()))
            ->update(['lease_until' => now()->addSeconds((int) ceil($seconds) + 60), 'lease_token' => $token]);
        if (! $claimed) {
            return false;
        }
        try {
            $first = true;
            $done = 0;
            while ($first || ($slices === null ? microtime(true) < $until : $done < $slices)) {
                $first = false;
                $done++;
                $more = DB::transaction(function () use ($runId, $token) {
                    $run = Run::whereKey($runId)->lockForUpdate()->first();
                    if (! $run || $run->status !== 'computing' || $run->lease_token !== $token) {
                        return false; // stopped meanwhile (a person reset it): nothing more to do
                    }

                    return $this->slice($run);
                });
                if (! $more) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            DB::table('planning_runs')->where('id', $runId)->update(['last_error' => mb_substr($e->getMessage(), 0, 1000)]);
            report($e);
            throw $e instanceof AppError ? $e : AppError::rule('PLANNING_COMPUTE_FAILED', 'تعذّر حساب جزء من التشغيل — أُوقف عند آخر جزء مكتمل ويمكن متابعته', 'A slice of the computation failed — it stopped after the last complete slice and can be continued');
        } finally {
            DB::table('planning_runs')->where('id', $runId)->where('lease_token', $token)->update(['lease_until' => null, 'lease_token' => null]);
        }

        return true;
    }

    /** One slice of work. @return bool true while there is more to do */
    private function slice(Run $run): bool
    {
        $work = (array) $run->work;
        $done = (int) $run->progress_done;
        if (($work['phase'] ?? 'collect') === 'collect') {
            $state = (array) ($work['collect'] ?? []);
            $complete = (new HistoryCollector)->advance($run, $state, 0.0, $done);
            $work['collect'] = $state;
            if ($complete) {
                $products = RunScope::products($run);
                $work['phase'] = 'items';
                $work['products'] = $products;
                $work['next'] = 0;
                $work['collectedAt'] = now()->toJSON();
                $run->progress_total = $done + count($products) + 1;
            }
            $run->forceFill(['work' => $work, 'progress_done' => $done])->save();

            return true;
        }
        if ($work['phase'] === 'items') {
            $products = (array) $work['products'];
            $slice = array_slice($products, (int) $work['next'], self::PRODUCTS_PER_SLICE);
            if ($slice) {
                $this->items($run, $slice);
                $run->stock_at ??= now();
            }
            $work['next'] = (int) $work['next'] + count($slice);
            if ($work['next'] >= count($products)) {
                $work['phase'] = 'finish';
            }
            $run->forceFill(['work' => $work, 'progress_done' => $done + count($slice)])->save();

            return true;
        }
        $this->finish($run, $work);

        return false;
    }

    /** The items of a few products. */
    private function items(Run $run, array $products): void
    {
        $anchor = $run->day('anchor_date');
        $warehouses = array_values(array_map('strval', (array) $run->warehouse_ids));
        $facts = [];
        foreach (DB::table('planning_demand')->where('run_id', $run->id)->whereIn('product_id', $products)->orderBy('product_id')->orderBy('warehouse_id')->orderBy('day')->orderByRaw('customer_key IS NULL')->orderBy('customer_key')->orderBy('id')->get() as $f) {
            $f->day = substr((string) $f->day, 0, 10);
            $facts[(string) $f->product_id][(string) $f->warehouse_id][] = $f;
        }
        $onHand = $this->stock->onHand($products, $warehouses);
        $booked = $this->stock->booked($products, $warehouses, $anchor);
        $master = $this->stock->products($products);
        $critical = $this->stock->criticality('product', $products);
        $last = [];
        foreach (DB::table('planning_prices')->where('run_id', $run->id)->whereIn('product_id', $products)->orderBy('day')->orderByRaw("CASE WHEN origin = 'ops' THEN 1 ELSE 0 END")->get() as $p) {
            $last[(string) $p->product_id] = ['price' => (float) $p->price, 'day' => $p->day ? substr((string) $p->day, 0, 10) : null, 'origin' => (string) $p->origin, 'ref' => $p->ref];
        }
        $ctx = $this->context($run, $facts) + ['last' => $last];
        $builder = new ItemBuilder;
        foreach ($products as $productId) {
            $product = $master[$productId] ?? null;
            if (! $product) {
                continue;
            }
            $whs = $warehouses;
            if ($run->product_selection === 'moving') {
                // a product that moved: the warehouses where it moved, has stock or has booked documents
                $whs = array_values(array_filter($warehouses, fn ($w) => isset($facts[$productId][$w]) || ($onHand[$productId][$w] ?? 0) != 0 || isset($booked['in'][$productId][$w]) || isset($booked['out'][$productId][$w])));
            }
            $quote = $this->prices->quote($productId, $anchor, (bool) $run->use_vendor_lead_time, (float) $run->default_lead_days, $last[$productId] ?? null, $product);
            $builder->product($run, $ctx, $product, $whs, $facts[$productId] ?? [], ['onHand' => $onHand[$productId] ?? [], 'in' => $booked['in'][$productId] ?? [], 'out' => $booked['out'][$productId] ?? []],
                $quote, $critical[$productId] ?? 'normal', $this->prices);
        }
        $this->write($builder->rows);
    }

    /** What every item of a slice shares: the periods, the warehouses and who the customers are. */
    private function context(Run $run, array $facts): array
    {
        $keys = [];
        foreach ($facts as $byWh) {
            foreach ($byWh as $rows) {
                foreach ($rows as $f) {
                    $keys[(string) ($f->customer_key ?? '')] = true;
                }
            }
        }
        $ops = array_values(array_filter(array_keys($keys), fn ($k) => $k !== '' && ! str_starts_with($k, 'odoo:')));
        $customers = [];
        $names = DB::table('customers')->whereIn('id', $ops ?: ['-'])->get(['id', 'code', 'name_ar'])->keyBy(fn ($c) => (string) $c->id);
        $levels = $this->stock->criticality('customer', $ops);
        $refs = new Refs;
        foreach (array_keys($keys) as $k) {
            if ($k === '') {
                $customers[$k] = ['name' => null, 'code' => null, 'criticality' => 'normal'];
            } elseif (str_starts_with($k, 'odoo:')) {
                $customers[$k] = ['name' => $refs->partnerName((int) substr($k, 5)), 'code' => null, 'criticality' => 'normal'];
            } else {
                $customers[$k] = ['name' => $names[$k]->name_ar ?? null, 'code' => $names[$k]->code ?? null, 'criticality' => $levels[$k] ?? 'normal'];
            }
        }
        $anchor = $run->day('anchor_date');

        return [
            'history' => Calendar::history($run->day('history_from'), $run->day('history_to'), $run->period_kind ?: 'month'),
            'horizon' => Calendar::horizon($run->day('forecast_from'), $run->day('forecast_to'), $run->period_kind ?: 'month'),
            'anchor' => $anchor, 'gap' => max(0, Calendar::diff($anchor, $run->day('forecast_from'))), 'now' => Clock::today(),
            'warehouses' => DB::table('warehouses')->whereIn('id', (array) $run->warehouse_ids)->get(['id', 'code'])->keyBy(fn ($w) => (string) $w->id)->all(),
            'customers' => $customers,
        ];
    }

    /** @param array{items:array, periods:array, history:array, requirements:array, patterns:array, events:array} $rows */
    private function write(array $rows): void
    {
        $now = now();
        $json = fn (array $r, array $keys) => array_merge($r, array_map(fn ($k) => isset($r[$k]) ? json_encode($r[$k], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) : null, array_combine($keys, $keys)));
        $items = array_map(function ($r) use ($json, $now) {
            unset($r['__index']);

            return $json($r, ['explain']) + ['created_at' => $now];
        }, $rows['items']);
        $tables = [
            'planning_items' => self::uniform($items),
            'planning_periods' => self::uniform($rows['periods']),
            'planning_history' => $rows['history'],
            'planning_requirements' => array_map(fn ($r) => $r + ['created_at' => $now], $rows['requirements']),
            'planning_patterns' => array_map(fn ($r) => $json($r, ['refs']) + ['created_at' => $now], $rows['patterns']),
            'planning_events' => $rows['events'],
        ];
        foreach ($tables as $table => $list) {
            foreach (array_chunk($list, 300) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
    }

    /** Rows of one insert must carry the same columns (a customer item has fewer than a stock item). */
    private static function uniform(array $rows): array
    {
        $keys = [];
        foreach ($rows as $r) {
            $keys += array_fill_keys(array_keys($r), null);
        }

        return array_map(fn ($r) => array_merge($keys, $r), $rows);
    }

    private function finish(Run $run, array $work): void
    {
        $stock = DB::table('planning_items')->where('run_id', $run->id)->where('kind', 'stock');
        $t = (clone $stock)->selectRaw('COUNT(*) AS n, COALESCE(SUM(forecast_total_qty), 0) AS f, COALESCE(SUM(shortage_qty), 0) AS sh, COALESCE(SUM(surplus_qty), 0) AS su,
            COALESCE(SUM(suggested_purchase_qty), 0) AS q, COALESCE(SUM(suggested_purchase_value), 0) AS v')->first();
        $values = [
            'item_count' => (int) $t->n, 'shortage_item_count' => (clone $stock)->where('status', 'shortage')->count(), 'surplus_item_count' => (clone $stock)->where('status', 'surplus')->count(),
            'line_count' => DB::table('planning_periods')->where('run_id', $run->id)->count(), 'pattern_count' => DB::table('planning_patterns')->where('run_id', $run->id)->count(),
            'event_count' => DB::table('planning_events')->where('run_id', $run->id)->count(), 'requirement_count' => DB::table('planning_requirements')->where('run_id', $run->id)->count(),
            'total_forecast_qty' => round((float) $t->f, 3), 'total_shortage_qty' => round((float) $t->sh, 3), 'total_surplus_qty' => round((float) $t->su, 3),
            'total_suggested_qty' => round((float) $t->q, 3), 'total_suggested_value' => round((float) $t->v, 2),
            'total_requirement_value' => round((float) DB::table('planning_requirements')->where('run_id', $run->id)->sum('value'), 2),
        ];
        $collect = (array) ($work['collect'] ?? []);
        $skipped = (array) ($collect['skipped'] ?? []);
        ksort($skipped);
        $diagnostics = [
            'read' => (array) ($collect['read'] ?? []), 'skipped' => $skipped, 'products' => count((array) ($work['products'] ?? [])),
            'demandRows' => DB::table('planning_demand')->where('run_id', $run->id)->count(), 'fallbacks' => (clone $stock)->where('method_fallback', true)->count(),
            'noPrice' => (clone $stock)->where('suggested_purchase_qty', '>', 0)->where('price_source', 'none')->count(),
        ];
        $started = $run->compute_started_at;
        $run->forceFill($values + [
            'status' => 'computed', 'computed_at' => now(), 'compute_seconds' => $started ? round(max(0, now()->getTimestampMs() - $started->getTimestampMs()) / 1000, 3) : null,
            'diagnostics' => $diagnostics, 'work' => ['phase' => 'done', 'collectedAt' => $work['collectedAt'] ?? null, 'watermark' => $work['watermark'] ?? 0], 'progress_done' => (int) $run->progress_total,
        ])->save();
        if ((float) $run->budget_monthly_amount > 0) {
            $this->budgets->runBudget($run);
        }
    }
}
