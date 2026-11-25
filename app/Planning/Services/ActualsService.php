<?php

namespace App\Planning\Services;

use App\Planning\Engine\Calendar;
use App\Planning\Engine\Num;
use App\Planning\Models\Run;
use App\Planning\Support\Clock;
use App\Planning\Support\Refs;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

/**
 * What really happened to the orders a run expected (cadence method). An expected order whose day has come is matched
 * with the first order of that customer for that product placed within the tolerance around it — half the customer's
 * usual interval, three days at least — that no earlier expected order took:
 *   ordered  the actual day, quantity and the difference from the expected quantity;
 *   missed   the tolerance has passed without such an order;
 *   pending  its day has not come, or the tolerance has not passed yet.
 * Orders are read from OPS (sales orders that are not drafts nor cancelled) and from Odoo's copy (confirmed sale order
 * lines that entered the copy after the run read its history). What the run expected never changes; only `actual_*`.
 */
class ActualsService
{
    public function __construct(private readonly AuditService $audit) {}

    public function refresh(AuthUser $user, Run $run): array
    {
        if (! in_array($run->status, ['computed', 'frozen'], true)) {
            throw AppError::rule('PLANNING_RUN_NOT_COMPUTED', 'احسب التشغيل أولًا', 'Compute the run first');
        }
        $today = Clock::today();
        $events = DB::table('planning_events as e')->join('planning_patterns as p', 'p.id', '=', 'e.pattern_id')->where('e.run_id', $run->id)
            ->where('e.actual_state', 'pending')->where('e.expected_date', '<=', $today)->whereNotNull('e.customer_id')
            ->orderBy('e.pattern_id')->orderBy('e.occurrence')->orderBy('e.id')->get(['e.*', 'p.avg_interval_days']);
        if ($events->isEmpty()) {
            return ['checked' => 0, 'ordered' => 0, 'missed' => 0, 'pending' => 0];
        }
        $orders = $this->orders($run, $events);
        $taken = [];
        $n = ['checked' => 0, 'ordered' => 0, 'missed' => 0, 'pending' => 0];
        DB::transaction(function () use ($events, $orders, $today, &$taken, &$n) {
            foreach ($events as $e) {
                $n['checked']++;
                $tol = max(3, Num::days((float) $e->avg_interval_days / 2));
                $expected = substr((string) $e->expected_date, 0, 10);
                [$from, $to] = [Calendar::add($expected, -$tol), Calendar::add($expected, $tol)];
                $key = "{$e->product_id}|{$e->customer_id}";
                $hit = null;
                foreach ($orders[$key] ?? [] as $i => $o) {
                    if (! isset($taken[$key][$i]) && $o['day'] >= $from && $o['day'] <= $to) {
                        $hit = $i;
                        break;
                    }
                }
                if ($hit !== null) {
                    $taken[$key][$hit] = true;
                    $o = $orders[$key][$hit];
                    DB::table('planning_events')->where('id', $e->id)->update(['actual_state' => 'ordered', 'actual_date' => $o['day'], 'actual_qty' => $o['qty'],
                        'qty_deviation' => Num::q($o['qty'] - (float) $e->qty), 'actual_checked_at' => now()]);
                    $n['ordered']++;
                } elseif ($today > $to) {
                    DB::table('planning_events')->where('id', $e->id)->update(['actual_state' => 'missed', 'actual_checked_at' => now()]);
                    $n['missed']++;
                } else {
                    DB::table('planning_events')->where('id', $e->id)->update(['actual_checked_at' => now()]);
                    $n['pending']++;
                }
            }
        });
        $this->audit->log($user, ['action' => 'PLANNING.ACTUALS', 'entityType' => 'PlanningRun', 'entityId' => $run->id, 'entityNumber' => $run->number, 'newValue' => $n]);

        return $n;
    }

    /** @return array<string, array<int, array{day:string, qty:float}>> "product|customer" → orders by day */
    private function orders(Run $run, $events): array
    {
        $products = $events->pluck('product_id')->unique()->values()->all();
        $customers = $events->pluck('customer_id')->unique()->values()->all();
        $since = substr((string) $events->min('expected_date'), 0, 10);
        $out = [];
        $ops = array_values(array_filter($customers, fn ($c) => ! str_starts_with((string) $c, 'odoo:')));
        if ($ops) {
            $rows = DB::table('sales_order_lines as l')->join('sales_orders as so', 'so.id', '=', 'l.so_id')->whereIn('l.product_id', $products)->whereIn('so.customer_id', $ops)
                ->whereIn('so.warehouse_id', (array) $run->warehouse_ids)->whereNotIn('so.status', ['draft', 'cancelled'])->where('so.date', '>=', Calendar::add($since, -40).' 00:00:00')
                ->orderBy('so.date')->orderBy('so.id')->get(['l.product_id', 'so.customer_id', 'so.date', DB::raw('COALESCE(l.ordered_qty, l.qty) as qty')]);
            foreach ($rows as $r) {
                $out["{$r->product_id}|{$r->customer_id}"][] = ['day' => Clock::day(substr((string) $r->date, 0, 19)), 'qty' => (float) $r->qty];
            }
        }
        $watermark = (int) (((array) $run->work)['watermark'] ?? 0);
        if ($watermark > 0 && $run->include_odoo !== false) {
            $refs = new Refs;
            $wanted = array_fill_keys($products, true);
            $lines = DB::table('odoo_records')->where('model', 'sale.order.line')->where('odoo_id', '>', $watermark)->whereNull('deleted_at')->orderBy('odoo_id')->limit(20000)->get(['data']);
            $heads = [];
            foreach ($lines as $r) {
                $d = json_decode((string) $r->data, true) ?: [];
                $product = $refs->link('product', is_array($d['product_id'] ?? null) ? $d['product_id'][0] : null);
                if (! $product || ! isset($wanted[$product]) || ! in_array($d['state'] ?? null, ['sale', 'done'], true)) {
                    continue;
                }
                $orderId = is_array($d['order_id'] ?? null) ? (int) $d['order_id'][0] : 0;
                $heads[$orderId] ??= json_decode((string) DB::table('odoo_records')->where('model', 'sale.order')->where('odoo_id', $orderId)->value('data'), true) ?: [];
                $head = $heads[$orderId];
                $customer = $refs->customerKey(is_array($head['partner_id'] ?? null) ? (int) $head['partner_id'][0] : null);
                if ($customer && in_array($customer, $customers, true)) {
                    $out["{$product}|{$customer}"][] = ['day' => Clock::day((string) ($head['date_order'] ?? '')), 'qty' => (float) ($d['product_uom_qty'] ?? 0)];
                }
            }
        }
        foreach ($out as &$list) {
            usort($list, fn ($a, $b) => strcmp((string) $a['day'], (string) $b['day']));
        }

        return $out;
    }
}
