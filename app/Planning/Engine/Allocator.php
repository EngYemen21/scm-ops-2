<?php

namespace App\Planning\Engine;

/**
 * Which purchase requirements a monthly budget pays for. Pure: no database, no clock.
 *
 * WHEN A REQUIREMENT IS DUE. Money is committed when the order is placed: a requirement is due in the month of its
 * order-by date; one that should have been ordered before the first budget month is due in the first one; one whose
 * order-by date is after the last budget month has no month and is left unfunded.
 *
 * ITS PRIORITY. Seven scores of 0–100 each, weighted by the scenario (score = Σ weight × score ÷ Σ weight):
 *   urgency      100 when the order-by date is the anchor day or before it, falling to 0 at the urgency horizon;
 *   stockout     the share of the net requirement that covers a real stock-out (below zero) rather than safety stock;
 *   criticality  critical 100 · high 75 · normal 50 · low 25;
 *   velocity     the value of a day's demand, against the highest of the run;
 *   confidence   the confidence of the forecast behind it;
 *   customer     the criticality of the customers behind the demand;
 *   lead time    the lead time, against the longest of the run (a long lead time cannot be made up later).
 *
 * MONTH BY MONTH. What a month may spend = its budget + what the month before left (when unused budget is carried).
 * Its queue = what is due in it + what earlier months could not pay (when unfunded requirements are carried). The
 * queue is paid in this order: critical products first (when the scenario says so), then the higher score, the earlier
 * need date, the product, the warehouse. A requirement that fits is funded whole. One that does not fit is funded in
 * part when partial funding is allowed — the largest number of whole order multiples the money buys, never less than
 * the vendor's minimum — and the rest stays in the queue; otherwise it waits whole.
 *
 * WHAT IS WRITTEN. One row per decision: a row for every month in which something of a requirement was funded (the
 * later ones say when it was originally due), and one row "not funded" for a requirement that got nothing at all. The
 * order date of a row is the order-by date — never before the anchor day and never before the first day of the month
 * that pays for it; a row is "ordered too late" when that date + lead time + buffer is after the need date.
 */
final class Allocator
{
    public const WEIGHTS = ['urgency', 'stockout', 'criticality', 'velocity', 'confidence', 'customer', 'leadTime'];

    public const CRITICALITY = ['critical' => 100.0, 'high' => 75.0, 'normal' => 50.0, 'low' => 25.0];

    /**
     * @param  array<int, array{id:string, sku:string, warehouse:string, qty:int|float, price:float, orderBy:string, needDate:string, leadDays:float, bufferDays:float,
     *                          net:float, stockout:float, criticality:string, velocity:float, confidence:float, customerScore:float, moq:float, multiple:float}>  $requirements
     * @param  array<int, array{month:string, amount:float}>  $months  first days of the months, in order
     * @param  array{anchor:string, weights:array<string, int|float>, criticalFirst?:bool, allowPartial?:bool, carryDeferred?:bool, carryUnused?:bool, urgencyHorizonDays?:int}  $opt
     * @return array{rows:array<int, array>, months:array<int, array>, totals:array<string, float|int>}
     */
    public static function run(array $requirements, array $months, array $opt): array
    {
        $anchor = $opt['anchor'];
        $criticalFirst = (bool) ($opt['criticalFirst'] ?? true);
        $partial = (bool) ($opt['allowPartial'] ?? true);
        $carryDeferred = (bool) ($opt['carryDeferred'] ?? true);
        $carryUnused = (bool) ($opt['carryUnused'] ?? true);
        $horizon = max(1, (int) ($opt['urgencyHorizonDays'] ?? 30));
        $weights = [];
        foreach (self::WEIGHTS as $key) {
            $weights[$key] = max(0.0, (float) ($opt['weights'][$key] ?? 0));
        }
        usort($months, fn ($a, $b) => strcmp($a['month'], $b['month']));
        $monthKeys = array_column($months, 'month');
        $firstMonth = $monthKeys[0] ?? null;
        $lastMonth = $monthKeys ? $monthKeys[count($monthKeys) - 1] : null;

        // ── scores
        $topVelocity = $topLead = 0.0;
        foreach ($requirements as $r) {
            $topVelocity = max($topVelocity, (float) $r['velocity'] * (float) $r['price']);
            $topLead = max($topLead, (float) $r['leadDays']);
        }
        $cands = [];
        $rows = [];
        $rank = [];
        foreach ($requirements as $r) {
            $price = (float) $r['price'];
            $qty = (float) $r['qty'];
            $days = Calendar::diff($anchor, $r['orderBy']);
            $scores = [
                'urgency' => $days <= 0 ? 100.0 : max(0.0, 100 * (1 - $days / $horizon)),
                'stockout' => $r['net'] > 0 ? 100 * min(1.0, max(0.0, (float) $r['stockout']) / (float) $r['net']) : 0.0,
                'criticality' => self::CRITICALITY[$r['criticality']] ?? self::CRITICALITY['normal'],
                'velocity' => $topVelocity > 0 ? 100 * (float) $r['velocity'] * $price / $topVelocity : 0.0,
                'confidence' => Num::clamp((float) $r['confidence'], 0, 100),
                'customer' => Num::clamp((float) $r['customerScore'], 0, 100),
                'leadTime' => $topLead > 0 ? 100 * (float) $r['leadDays'] / $topLead : 0.0,
            ];
            $sum = array_sum($weights);
            $score = 0.0;
            foreach ($weights as $key => $w) {
                $score += $w * $scores[$key];
            }
            $due = self::monthOf($r['orderBy'], $firstMonth);
            $c = [
                'id' => $r['id'], 'sku' => (string) $r['sku'], 'warehouse' => (string) $r['warehouse'], 'needDate' => $r['needDate'], 'orderBy' => $r['orderBy'], 'due' => $due, 'price' => $price,
                'qty' => $qty, 'left' => $qty, 'value' => Num::m($qty * $price), 'moq' => max(0.0, (float) $r['moq']), 'multiple' => max(1.0, (float) $r['multiple']),
                'ahead' => (int) ceil((float) $r['leadDays'] + (float) $r['bufferDays'] - 1e-9), 'stockout' => max(0.0, (float) $r['stockout']), 'critical' => $r['criticality'] === 'critical',
                'mustFund' => $criticalFirst && $r['criticality'] === 'critical', 'score' => Num::m($sum > 0 ? $score / $sum : 0.0), 'scores' => array_map(fn ($s) => Num::m($s), $scores), 'funded' => 0.0,
            ];
            if ($price <= 0 || $qty <= 0) {
                $rows[] = self::row($c, $due ?? $firstMonth, null, 0, 0.0, 'deferred', null, false, ['code' => 'no_price', 'ar' => 'لا سعر شراء لهذا المنتج — لا يُموَّل قبل تحديد سعره', 'en' => 'No purchase price for this product — it cannot be funded until one is known']);
                $c['left'] = 0.0;
            } elseif ($due === null || $lastMonth === null || $due > $lastMonth) {
                $rows[] = self::row($c, $due ?? $lastMonth, null, 0, 0.0, 'deferred', null, false, ['code' => 'no_month', 'ar' => 'تاريخ الطلب بعد آخر شهر في الميزانية', 'en' => 'The order date is after the last budget month']);
                $c['left'] = 0.0;
            }
            $cands[$r['id']] = $c;
        }

        // ── month by month
        $order = fn (array $a, array $b) => [$b['mustFund'], $b['score'], $a['needDate'], $a['sku'], $a['warehouse'], $a['id']] <=> [$a['mustFund'], $a['score'], $b['needDate'], $b['sku'], $b['warehouse'], $b['id']];
        $results = [];
        $carried = 0.0;
        $waiting = []; // ids that an earlier month could not (fully) pay
        $unused = 0.0;
        foreach ($months as $n => $m) {
            $month = $m['month'];
            $amount = max(0.0, (float) $m['amount']);
            $carriedIn = $carryUnused ? $carried : 0.0;
            $available = $amount + $carriedIn;
            $queue = [];
            $dueValue = 0.0;
            foreach ($cands as $id => $c) {
                if ($c['due'] === $month) {
                    $dueValue += $c['value'];
                }
                if ($c['left'] > 0 && ($c['due'] === $month || isset($waiting[$id]))) {
                    $queue[] = $c;
                }
            }
            usort($queue, $order);
            $waiting = [];
            $funded = 0.0;
            $isLast = $n === count($months) - 1;
            foreach ($queue as $position => $c) {
                $id = $c['id'];
                $rank[$id] ??= $position + 1;
                $late = $c['due'] !== $month;
                // never before the money exists (the first day of the paying month) and never in the past
                $orderDate = max($c['orderBy'], $anchor, $month);
                $atRisk = Calendar::add($orderDate, $c['ahead']) > $c['needDate'];
                $cost = Num::m($c['left'] * $c['price']);
                if ($cost <= $available + 1e-6) {
                    $take = $c['left'];
                    $status = 'funded';
                } else {
                    $take = $partial ? self::affordable($available, $c['price'], $c['multiple'], $c['moq'], $c['left']) : 0.0;
                    $status = 'partial';
                }
                if ($take > 0) {
                    $value = Num::m($take * $c['price']);
                    $rows[] = self::row($c, $month, $orderDate, $position + 1, $take, $status, $late ? $c['due'] : null, $atRisk, $status === 'partial'
                        ? ['code' => 'partial', 'ar' => 'الميزانية المتاحة في الشهر لا تكفي الكمية كلها', 'en' => 'The budget left in the month does not cover the whole quantity'] : null);
                    $available = Num::m($available - $value);
                    $funded += $value;
                    $cands[$id]['left'] = $c['left'] - $take;
                    $cands[$id]['funded'] += $take;
                }
                if ($cands[$id]['left'] > 1e-9 && $carryDeferred && ! $isLast) {
                    $waiting[$id] = true;
                }
            }
            $left = Num::m($available);
            $results[] = ['month' => $month, 'amount' => Num::m($amount), 'carriedIn' => Num::m($carriedIn), 'due' => Num::m($dueValue), 'funded' => Num::m($funded), 'remaining' => $left];
            $carried = $left;
            $unused = $carryUnused ? $left : $unused + $left;
        }

        // ── what got nothing at all: one row that says so
        $lastLabel = $lastMonth !== null ? substr($lastMonth, 0, 7) : null;
        foreach ($cands as $id => $c) {
            if ($c['left'] > 1e-9 && $c['funded'] <= 0) {
                $rows[] = self::row(['left' => $c['left']] + $c, $c['due'], null, $rank[$id] ?? 0, 0.0, 'deferred', null, false, $carryDeferred
                    ? ['code' => 'no_budget', 'ar' => "لم تكفِ الميزانية حتى {$lastLabel}", 'en' => "The budget did not reach it up to {$lastLabel}"]
                    : ['code' => 'no_budget', 'ar' => 'لم تكفِ ميزانية شهره، والترحيل غير مفعّل', 'en' => 'The budget of its month did not reach it and nothing is carried forward']);
            }
        }
        usort($rows, fn ($a, $b) => [$a['month'] ?? '9999', $a['rank'] ?: PHP_INT_MAX, $a['requirementId'], $a['status']] <=> [$b['month'] ?? '9999', $b['rank'] ?: PHP_INT_MAX, $b['requirementId'], $b['status']]);

        // ── totals, counted per requirement (a requirement may have several rows)
        $t = ['budgetTotal' => 0.0, 'required' => 0.0, 'funded' => 0.0, 'stockoutUnfunded' => 0.0, 'requirements' => count($cands), 'fundedCount' => 0, 'partialCount' => 0, 'deferredCount' => 0, 'criticalDeferred' => 0, 'atRisk' => 0];
        foreach ($months as $m) {
            $t['budgetTotal'] += max(0.0, (float) $m['amount']);
        }
        foreach ($cands as $c) {
            $t['required'] += $c['value'];
            $t['funded'] += Num::m($c['funded'] * $c['price']);
            $missing = max(0.0, $c['qty'] - $c['funded']);
            $t['stockoutUnfunded'] += Num::m(min($missing, $c['stockout']) * $c['price']);
            $whole = $missing <= 1e-9 && $c['qty'] > 0 && $c['price'] > 0;
            $t[$whole ? 'fundedCount' : ($c['funded'] > 0 ? 'partialCount' : 'deferredCount')]++;
            $t['criticalDeferred'] += $c['critical'] && ! $whole ? 1 : 0;
        }
        foreach ($rows as $row) {
            $t['atRisk'] += $row['atRisk'] ? 1 : 0;
        }
        $t = array_map(fn ($v) => is_float($v) ? Num::m($v) : $v, $t);
        $t['deferred'] = Num::m($t['required'] - $t['funded']);
        $t['fundedPct'] = $t['required'] > 0 ? Num::m($t['funded'] / $t['required'] * 100) : 0.0;
        $t['unused'] = Num::m($unused);

        return ['rows' => $rows, 'months' => $results, 'totals' => $t];
    }

    /** The first day of the month a requirement is due in: the month of its order-by date, never before the first budget month. */
    public static function monthOf(string $orderBy, ?string $firstMonth): ?string
    {
        if ($firstMonth === null) {
            return null;
        }

        return max(Calendar::monthStart($orderBy), $firstMonth);
    }

    /** The largest number of whole order multiples the money buys — nothing when that is below the vendor's minimum. */
    public static function affordable(float $money, float $price, float $multiple, float $moq, float $wanted): float
    {
        if ($money <= 0 || $price <= 0) {
            return 0.0;
        }
        $units = floor($money / $price + 1e-9);
        $units = $multiple > 1 ? floor($units / $multiple + 1e-9) * $multiple : $units;
        $units = min($units, $wanted);

        return $units > 0 && $units >= $moq ? $units : 0.0;
    }

    private static function row(array $c, ?string $month, ?string $orderDate, int $rank, float $fundedQty, string $status, ?string $carriedFrom, bool $atRisk, ?array $reason): array
    {
        return [
            'requirementId' => $c['id'], 'month' => $month, 'orderDate' => $orderDate, 'rank' => $rank, 'mustFund' => $c['mustFund'], 'score' => $c['score'], 'scores' => $c['scores'],
            'requiredQty' => Num::q($c['left']), 'fundedQty' => Num::q($fundedQty), 'requiredValue' => Num::m($c['left'] * $c['price']), 'fundedValue' => Num::m($fundedQty * $c['price']),
            'status' => $status, 'carriedFrom' => $carriedFrom, 'atRisk' => $status === 'deferred' ? false : $atRisk, 'reason' => $reason,
        ];
    }
}
