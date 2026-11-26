<?php

namespace App\Commissions\Services;

use App\Commissions\Models\CommissionLine;
use App\Commissions\Models\CommissionPlan;
use App\Commissions\Models\CommissionStatement;
use App\Commissions\Support\Doc;
use App\Commissions\Support\Owned;
use App\Commissions\Support\People;
use App\Commissions\Support\Period;
use App\Commissions\Support\Shape;
use App\Commissions\Support\Tiers;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * From the finance module's invoices to the lines of the statements — for one month.
 *
 * IDEMPOTENT. A run rebuilds the DRAFT statement of every earner of the month and writes only what differs: a second
 * run changes nothing. A statement that was submitted, approved or paid is history and is never touched.
 * A DRAFT IS WHAT THE PLANS AND THE DOCUMENTS SAY NOW: its lines go when its invoices were cancelled, when the sale was
 * assigned to somebody else, or when no active plan names its earner any more (the plan was archived). A draft left with
 * no line and no adjustment is withdrawn; one that carries a person's adjustment stays, with that adjustment.
 *
 * A DOCUMENT EARNS ONCE per earner. A run takes every invoice that counts on a day up to the end of the month (its
 * date, or — for a plan on collections — the day it was fully paid) and that no other statement of the earner holds:
 * an invoice that arrives after its month was closed (a late collection, a statement approved early) lands in the
 * next statement, marked late — nothing is lost and nothing is paid twice. The look-back is `Documents::LOOK_BACK_MONTHS`.
 *
 * WHAT TAKES COMMISSION BACK.
 *   credit note   a negative line on the day of the note, by the same rule as the invoice it credits (plans "net of
 *                 returns"); a note issued before its invoice was fully paid comes with the invoice;
 *   reversal      a line of an earlier, closed statement whose invoice was cancelled since — or, for a plan on
 *                 collections, is no longer fully paid (its receipt was voided) — is taken back in the open statement
 *                 by a line that names it. Should the invoice be paid again later, it earns again, once.
 *
 * EXPLAINABLE. Every line carries the document, the base and its parts (net, tax, cost), the rule and the rate — and for
 * a tiered rule the tier that decided — so a person can redo the sum from the line alone.
 */
class CalculationService
{
    /** What is compared to decide that a line changed. */
    private const COMPARED = ['beneficiary_id', 'plan_id', 'rule_label', 'doc_number', 'doc_date', 'earned_on', 'customer_id', 'salesperson_id', 'salesperson_via',
        'invoice_total', 'base_amount', 'rate', 'commission', 'explain', 'excluded', 'exclude_reason', 'payment_state'];

    /** @var array<string, string[]>|null a product category → itself and its ancestors */
    private ?array $ancestors = null;

    /** @var array<string, array<string, Doc>> credit notes of a window, read once per run */
    private array $notesIn = [];

    public function __construct(
        private readonly PlanResolver $plans,
        private readonly Documents $documents,
        private readonly Salespeople $salespeople,
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
    ) {}

    /**
     * Calculates the month for every earner (or for one).
     *
     * @return array{period:string, dateFrom:string, dateTo:string, lookBackFrom:string, counts:array<string,int>, statements:array<int, array<string,mixed>>, unassigned:array{count:int, net:string}}
     */
    public function run(AuthUser $user, string $period, ?string $beneficiary = null): array
    {
        $this->fresh();
        [$from, $to] = Period::bounds($period);
        if ($from->gt(Carbon::today())) {
            throw AppError::rule('COMMISSION_PERIOD_FUTURE', "الفترة {$period} لم تبدأ بعد", "Period {$period} has not started yet");
        }
        $only = $beneficiary !== null && $beneficiary !== '' ? (string) People::earner($beneficiary, 'beneficiary')->id : null;
        $fromDay = $from->toDateString();
        $toDay = $to->toDateString();
        $resolved = $this->plans->forPeriod($fromDay, $toDay);
        $drafts = CommissionStatement::where('period', $period)->whereNull('source_system')->where('status', 'draft')->whereNotNull('beneficiary_id')
            ->when($only !== null, fn ($q) => $q->where('beneficiary_id', $only))->pluck('beneficiary_id')->all();
        if (! $resolved['explicit'] && ! $resolved['default'] && ! $drafts) {
            // (with drafts of the month the run goes on: they are brought to what the plans say now — nothing)
            throw AppError::rule('COMMISSION_NO_ACTIVE_PLAN', "لا توجد خطة عمولات فعّالة تغطي {$period} — فعّل خطة أولًا", "No active commission plan covers {$period} — activate a plan first");
        }
        $since = $from->copy()->subMonthsNoOverflow(Documents::LOOK_BACK_MONTHS)->toDateString();

        $cache = [];
        $docsOf = function (CommissionPlan $plan) use (&$cache, $since, $toDay): array {
            [$lower, $upper] = self::window($plan, $since, $toDay);
            if ($lower > $upper) {
                return [];
            }
            $key = "{$plan->earn_on}|{$lower}|{$upper}";
            if (! isset($cache[$key])) {
                $cache[$key] = $this->documents->invoices($plan->earn_on, $lower, $upper);
                $this->salespeople->prime($cache[$key]);
            }

            return $cache[$key];
        };

        /** @var array<string, array{plan: ?CommissionPlan, docs: array<string, Doc>}> $work */
        $work = [];
        foreach ($resolved['explicit'] as $earner => $plan) {
            $earner = (string) $earner;
            if ($only !== null && $earner !== $only) {
                continue;
            }
            $docs = $docsOf($plan);
            if ($plan->earner_type === 'manager') {
                if (! $plan->everyone) {
                    $team = array_flip($plan->members->pluck('user_id')->filter()->map(fn ($id) => (string) $id)->all());
                    $docs = array_filter($docs, fn (Doc $d) => ($sp = $this->salespeople->of($d)[0]) !== null && isset($team[$sp]));
                }
            } else {
                $docs = array_filter($docs, fn (Doc $d) => $this->salespeople->of($d)[0] === $earner);
            }
            $work[$earner] = ['plan' => $plan, 'docs' => $docs];
        }
        $default = $resolved['default'];
        if ($default) {
            foreach ($docsOf($default) as $id => $doc) {
                $sp = $this->salespeople->of($doc)[0];
                if ($sp === null || isset($resolved['explicit'][$sp]) || ($only !== null && $sp !== $only)) {
                    continue;
                }
                $work[$sp] ??= ['plan' => $default, 'docs' => []];
                $work[$sp]['docs'][$id] = $doc;
            }
        }
        // … and whoever has something that may have to be taken back, or a draft whose documents are gone
        $known = CommissionLine::query()->join('commission_statements as st', 'st.id', '=', 'commission_lines.statement_id')
            ->where('commission_lines.source_type', CommissionLine::SOURCE_FINANCE)->whereIn('commission_lines.kind', CommissionLine::GRANTS)
            ->whereNull('st.source_system')->whereDate('st.date_to', '>=', $since)->whereNotNull('commission_lines.beneficiary_id')
            ->when($only !== null, fn ($q) => $q->where('commission_lines.beneficiary_id', $only))
            ->distinct()->pluck('commission_lines.beneficiary_id')->all();
        foreach (array_unique(array_map('strval', array_merge($known, $drafts))) as $earner) {
            $work[$earner] ??= ['plan' => $resolved['explicit'][$earner] ?? $default, 'docs' => []];
        }
        ksort($work);

        $out = [];
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'frozen' => 0, 'removed' => 0];
        foreach ($work as $earner => $w) {
            $r = $this->build($user, (string) $earner, $w['plan'], $period, $fromDay, $toDay, $w['docs'], $since);
            if ($r['outcome'] === 'nothing') {
                continue;
            }
            $counts[$r['outcome']]++;
            $out[] = $r;
        }
        $users = People::byId(array_column($out, 'beneficiaryId'));
        $unassigned = $this->nobodys($period, 0);

        return [
            'period' => $period, 'dateFrom' => $fromDay, 'dateTo' => $toDay, 'lookBackFrom' => $since, 'counts' => $counts,
            'statements' => array_map(fn (array $r) => ['beneficiary' => People::shape($r['beneficiaryId'], $users[$r['beneficiaryId']] ?? null, null)] + $r, $out),
            'unassigned' => ['count' => $unassigned['count'], 'net' => $unassigned['net']],
        ];
    }

    /** The button of a draft statement: the same run, for its earner and its month. */
    public function recalculate(AuthUser $user, CommissionStatement $s): array
    {
        Owned::refuse($s, 'statement');
        if ($s->status !== 'draft' || $s->source_system !== null || ! $s->beneficiary_id) {
            throw AppError::rule('COMMISSION_STATEMENT_STATE', $s->status !== 'draft' ? "الكشف {$s->number} ليس مسودة — لا يُعاد احتسابه" : "الكشف {$s->number} تاريخ منقول من نظام آخر — لا يُحتسب من فواتير العمليات",
                $s->status !== 'draft' ? "Statement {$s->number} is not a draft — it is not recalculated" : "Statement {$s->number} is history from another system — it is not calculated from OPS invoices");
        }

        return $this->run($user, $s->period, $s->beneficiary_id);
    }

    /**
     * The invoices of a month that are nobody's sale: no representative on the order that OPS knows, no salesperson
     * for the customer, entered by no person. They earn no salesperson a commission until a person assigns them.
     *
     * @return array{period:string, invoices:int, count:int, net:string, items:array<int, array<string,mixed>>}
     */
    public function unassigned(string $period, int $limit = 100): array
    {
        $this->fresh();

        return $this->nobodys($period, $limit);
    }

    private function nobodys(string $period, int $limit): array
    {
        [$from, $to] = Period::bounds($period);
        $docs = $this->documents->invoices('invoiced', $from->toDateString(), $to->toDateString());
        $this->salespeople->prime($docs);
        // an invoice a statement already recorded a salesperson for was somebody's sale, whatever was reassigned since
        $recorded = [];
        foreach (array_chunk(array_keys($docs), 1000) as $chunk) {
            $recorded += array_flip(CommissionLine::where('source_type', CommissionLine::SOURCE_FINANCE)->whereIn('source_id', array_map('strval', $chunk))->whereIn('kind', CommissionLine::GRANTS)
                ->whereNotNull('salesperson_id')->distinct()->pluck('source_id')->all());
        }
        $count = 0;
        $net = 0.0;
        $items = [];
        foreach ($docs as $id => $doc) {
            if (isset($recorded[$id]) || $this->salespeople->of($doc)[0] !== null) {
                continue;
            }
            $count++;
            $net += $doc->subtotal;
            if (count($items) < $limit) {
                $items[] = $doc;
            }
        }
        $customers = $items ? DB::table('customers')->whereIn('id', array_values(array_unique(array_map(fn (Doc $d) => $d->customerId, $items))))->get(['id', 'code', 'name_ar', 'name_en'])->keyBy('id') : collect();

        return [
            'period' => $period, 'invoices' => count($docs), 'count' => $count, 'net' => Shape::money($net),
            'items' => array_map(function (Doc $d) use ($customers) {
                $c = $customers[$d->customerId] ?? null;

                return ['id' => $d->id, 'number' => $d->number, 'date' => $d->date, 'net' => Shape::money($d->subtotal), 'total' => Shape::money($d->total), 'rep' => $this->salespeople->repName($d),
                    'customer' => $c ? ['id' => (string) $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en] : null];
            }, $items),
        ];
    }

    /**
     * Applies the rates of the statement's own terms to its lines again — after a person excluded an invoice or took an
     * exclusion back (under a tiered rule that moves the month's total, and with it the rate of the other lines).
     */
    public function rerate(CommissionStatement $s): void
    {
        $lines = CommissionLine::where('statement_id', $s->id)->whereIn('kind', CommissionLine::GRANTS)->orderBy('id')->get()->values();
        $rows = $lines->map(fn (CommissionLine $l) => ['kind' => $l->kind, 'rule_id' => $l->rule_id, 'base_amount' => (float) $l->base_amount, 'excluded' => (bool) $l->excluded,
            'earned_on' => Shape::day($l->earned_on), 'doc_number' => $l->doc_number, 'explain' => $l->explain, 'rate' => $l->rate, 'commission' => (float) $l->commission])->all();
        $rated = self::rate($rows, array_column((array) ($s->terms['rules'] ?? []), null, 'id'));
        foreach ($lines as $i => $line) {
            $delta = self::delta($line, array_intersect_key($rated[$i], array_flip(['rate', 'commission', 'explain'])));
            if ($delta) {
                $line->update($delta);
            }
        }
        self::retotal($s);
    }

    /** The totals of a statement OPS calculates, from its lines. (A statement of Odoo keeps Odoo's own totals.) */
    public static function retotal(CommissionStatement $s): void
    {
        $calculated = CommissionLine::where('statement_id', $s->id)->whereIn('kind', CommissionLine::CALCULATED);
        $commission = round((float) (clone $calculated)->sum('commission'), 2);
        $adjustments = round((float) CommissionLine::where('statement_id', $s->id)->where('kind', 'adjustment')->whereNull('voided_at')->sum('commission'), 2);
        $s->update([
            'lines_count' => (clone $calculated)->count(), 'base_total' => round((float) (clone $calculated)->where('excluded', false)->sum('base_amount'), 2),
            'commission_total' => $commission, 'adjustments_total' => $adjustments, 'total' => round($commission + $adjustments, 2),
        ]);
    }

    /**
     * The rate and the commission of every granting row (invoice, credit note), rule by rule. A flat rule pays its rate
     * on each base. A tiered rule looks at the month's base of the rule (what a person excluded aside): `whole` pays the
     * rate of the tier that total reaches on every row; `progressive` walks the rows by day and pays each slice at the
     * rate of its own tier. Rows of other kinds, and rows whose rule the terms no longer hold, are returned as they are.
     * Pure: no database.
     *
     * @param  array<int, array<string, mixed>>  $rows  kind, rule_id, base_amount, excluded, earned_on, doc_number, explain
     * @param  array<string, array<string, mixed>>  $rules  the rules of the terms, by id: rateType, rate, tierMode, tiers
     * @return array<int, array<string, mixed>> the same rows with rate, commission and explain.tier set
     */
    public static function rate(array $rows, array $rules): array
    {
        $byRule = [];
        foreach ($rows as $i => $row) {
            if (in_array($row['kind'], CommissionLine::GRANTS, true)) {
                $byRule[(string) ($row['rule_id'] ?? '')][] = $i;
            }
        }
        foreach ($byRule as $ruleId => $indexes) {
            $rule = $rules[$ruleId] ?? null;
            if (! $rule) {
                continue;
            }
            usort($indexes, fn ($a, $b) => [$rows[$a]['earned_on'] ?? '', $rows[$a]['doc_number'] ?? '', $rows[$a]['kind']] <=> [$rows[$b]['earned_on'] ?? '', $rows[$b]['doc_number'] ?? '', $rows[$b]['kind']]);
            $tiered = ($rule['rateType'] ?? 'flat') === 'tiered' && ! empty($rule['tiers']);
            $tiers = $tiered ? array_values((array) $rule['tiers']) : [];
            $progressive = $tiered && ($rule['tierMode'] ?? 'whole') === 'progressive';
            $total = 0.0;
            foreach ($indexes as $i) {
                $total += empty($rows[$i]['excluded']) ? (float) $rows[$i]['base_amount'] : 0.0;
            }
            $total = round($total, 2);
            $tier = $tiered && ! $progressive ? Tiers::tierAt($tiers, max(0.0, $total)) : null;
            $running = 0.0;
            foreach ($indexes as $i) {
                $base = (float) $rows[$i]['base_amount'];
                $explain = (array) ($rows[$i]['explain'] ?? []);
                unset($explain['tier']);
                if (! empty($rows[$i]['excluded'])) {
                    $rows[$i]['rate'] = $tiered ? null : round((float) ($rule['rate'] ?? 0), 4);
                    $rows[$i]['commission'] = 0.0;
                } elseif (! $tiered) {
                    $rows[$i]['rate'] = round((float) ($rule['rate'] ?? 0), 4);
                    $rows[$i]['commission'] = round($base * $rows[$i]['rate'] / 100, 2);
                } elseif (! $progressive) {
                    $rows[$i]['rate'] = round($tier['rate'], 4);
                    $rows[$i]['commission'] = round($base * $tier['rate'] / 100, 2);
                    $explain['tier'] = ['mode' => 'whole', 'total' => $total, 'from' => round($tier['from'], 2), 'upTo' => $tier['upTo']];
                } else {
                    $commission = round(Tiers::cumulative($tiers, $running + $base) - Tiers::cumulative($tiers, $running), 2);
                    $rows[$i]['commission'] = $commission;
                    $rows[$i]['rate'] = abs($base) > 0.004 ? round($commission / $base * 100, 4) : round(Tiers::tierAt($tiers, max(0.0, $running))['rate'], 4);
                    $explain['tier'] = ['mode' => 'progressive', 'from' => round($running, 2), 'to' => round($running + $base, 2)];
                    $running += $base;
                }
                $rows[$i]['explain'] = $explain ?: null;
            }
        }

        return $rows;
    }

    /**
     * A run reads what the tables hold NOW: nothing a previous run of the same service object read is reused (users,
     * assignments, orders, credit notes, the category tree).
     */
    private function fresh(): void
    {
        $this->ancestors = null;
        $this->notesIn = [];
        $this->salespeople->fresh();
    }

    // ───────────────────────────── one statement ─────────────────────────────

    /** @param array<string, Doc> $docs the invoices of the window that are this earner's under the plan */
    private function build(AuthUser $user, string $earner, ?CommissionPlan $plan, string $period, string $fromDay, string $toDay, array $docs, string $since): array
    {
        $slot = "u:{$earner}:{$period}";
        $attempt = fn () => DB::transaction(function () use ($user, $earner, $plan, $period, $fromDay, $toDay, $docs, $since, $slot) {
            $s = CommissionStatement::where('slot', $slot)->lockForUpdate()->first();
            if ($s && $s->frozen()) {
                return self::outcome($s, 'frozen');   // history: what arrives late lands in the next statement
            }
            $specs = $plan ? $this->specs($earner, $plan, $s, $docs, $fromDay, $toDay, $since) : [];
            if (! $s && (! $specs || ! $plan)) {
                return ['beneficiaryId' => $earner, 'outcome' => 'nothing'];
            }
            $created = ! $s;
            $terms = $plan ? Shape::terms($plan) : $s->terms;
            $s ??= CommissionStatement::create([
                'number' => $this->numbering->next('CMS'), 'slot' => $slot, 'beneficiary_id' => $earner, 'period' => $period, 'date_from' => $fromDay, 'date_to' => $toDay,
                'plan_id' => $plan->id, 'earner_type' => $plan->earner_type, 'terms' => $terms, 'status' => 'draft', 'created_by' => $user->username,
            ]);
            $diff = $this->persist($s, $specs, $user);
            if (! $created && ! $specs && ! CommissionLine::where('statement_id', $s->id)->exists()) {
                // no document justifies it any more — its invoices were cancelled or are somebody else's now, or no plan names
                // its earner — and no person added anything to it: the draft is withdrawn (nothing was owed; its number is not reused)
                $this->audit->log($user, ['action' => 'COMMISSION.STATEMENT.WITHDRAW', 'entityType' => 'CommissionStatement', 'entityId' => $s->id, 'entityNumber' => $s->number,
                    'oldValue' => ['period' => $period, 'beneficiary' => $earner, 'plan' => $s->plan_id], 'newValue' => $diff]);
                $withdrawn = ['id' => $s->id, 'number' => $s->number, 'beneficiaryId' => $earner, 'status' => 'draft', 'outcome' => 'removed', 'lines' => 0, 'total' => Shape::money(0)] + $diff;
                $s->delete();

                return $withdrawn;
            }
            $planChanged = ! $created && $plan !== null && ((string) $s->plan_id !== (string) $plan->id || self::canon($s->terms) !== self::canon($terms));
            $changed = $created || $planChanged || array_sum($diff) > 0;
            if ($planChanged) {
                $s->fill(['plan_id' => $plan->id, 'earner_type' => $plan->earner_type, 'terms' => $terms]);
            }
            if ($changed) {
                $s->fill(['calculated_at' => now(), 'calculated_by' => $user->username]);
            }
            $s->save();
            self::retotal($s);
            if ($changed) {
                $this->audit->log($user, ['action' => 'COMMISSION.CALCULATE', 'entityType' => 'CommissionStatement', 'entityId' => $s->id, 'entityNumber' => $s->number,
                    'newValue' => ['period' => $period, 'plan' => $plan?->code, 'lines' => (int) $s->lines_count, 'base' => (string) $s->base_total, 'commission' => (string) $s->commission_total, 'total' => (string) $s->total] + $diff]);
            }

            return self::outcome($s, $created ? 'created' : ($changed ? 'updated' : 'unchanged')) + $diff;
        });
        try {
            return $attempt();
        } catch (UniqueConstraintViolationException) {
            return $attempt();   // two runs made the same statement at the same moment: the second one works on the first one's
        }
    }

    private static function outcome(CommissionStatement $s, string $outcome): array
    {
        return ['id' => $s->id, 'number' => $s->number, 'beneficiaryId' => (string) $s->beneficiary_id, 'status' => $s->status, 'outcome' => $outcome, 'lines' => (int) $s->lines_count, 'total' => Shape::money($s->total)];
    }

    /**
     * The lines the earner's statement of the month should hold now, by key.
     *
     * @param  array<string, Doc>  $docs
     * @return array<string, array<string, mixed>>
     */
    private function specs(string $earner, CommissionPlan $plan, ?CommissionStatement $s, array $docs, string $fromDay, string $toDay, string $since): array
    {
        [$lower, $upper] = self::window($plan, $since, $toDay);
        $rules = Shape::terms($plan)['rules'];
        $own = $s?->id;
        $rows = [];

        // 1 ── the invoices no other statement holds for this earner (nor, for a salesperson, a closed statement of another one)
        $held = $this->taken($earner, array_keys($docs), $own, (string) $plan->earner_type);
        $now = [];
        foreach ($docs as $id => $doc) {
            if (isset($held[$id])) {
                continue;
            }
            $lines = $this->linesOf($doc, 'invoice', $plan, $rules, $earner, $fromDay);
            if ($lines) {
                $now[(string) $id] = $doc;
                array_push($rows, ...$lines);
            }
        }

        // 2 ── credit notes: of what is taken here, and — dated in the window — of what an earlier statement took
        if ($plan->net_of_returns && $lower <= $upper) {
            $notes = $now ? $this->documents->creditNotesOf(array_keys($now), $upper) : [];
            $window = $this->notesIn["{$lower}|{$upper}"] ??= $this->documents->creditNotesIn($lower, $upper);
            $earlier = $this->held($earner, array_values(array_unique(array_filter(array_map(fn (Doc $n) => $n->creditOfId, $window)))), $own);
            foreach ($window as $id => $note) {
                if (! isset($notes[$id]) && $note->creditOfId !== null && isset($earlier[$note->creditOfId])) {
                    $notes[$id] = $note;
                }
            }
            $this->salespeople->prime($notes);
            $taken = $this->held($earner, array_keys($notes), $own);
            foreach ($notes as $id => $note) {
                if (isset($taken[$id])) {
                    continue;
                }
                $invoice = $now[$note->creditOfId] ?? null;
                // a note issued before its invoice was fully paid comes back with it, on the day the invoice counts
                $day = $invoice && $invoice->earnedOn > $note->date ? $invoice->earnedOn : $note->date;
                array_push($rows, ...$this->linesOf($note->on($day), 'credit_note', $plan, $rules, $earner, $fromDay));
            }
        }

        // 3 ── lines of closed statements that no longer hold
        array_push($rows, ...$this->reversals($earner, $own, $since, $toDay));

        // 4 ── what a person excluded stays excluded over a recalculation; then the rates
        $keep = $own ? CommissionLine::where('statement_id', $own)->whereIn('kind', CommissionLine::GRANTS)->where('excluded', true)->get()
            ->mapWithKeys(fn (CommissionLine $l) => [self::key($l->getAttributes()) => $l->exclude_reason])->all() : [];
        foreach ($rows as $i => $row) {
            $k = self::key($row);
            $rows[$i]['excluded'] = array_key_exists($k, $keep);
            $rows[$i]['exclude_reason'] = $keep[$k] ?? null;
        }
        $out = [];
        foreach (self::rate($rows, array_column($rules, null, 'id')) as $row) {
            $out[self::key($row)] = $row;
        }

        return $out;
    }

    /**
     * One line per rule that covers something of the document. The first rule (in the plan's order) that covers an
     * invoice line decides for it; a line no rule covers earns nothing.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, array<string, mixed>>
     */
    private function linesOf(Doc $doc, string $kind, CommissionPlan $plan, array $rules, string $earner, string $fromDay): array
    {
        $buckets = [];
        foreach ($doc->lines as $l) {
            $i = $this->ruleFor($rules, $doc->customerId, $l['categoryId']);
            if ($i !== null) {
                $buckets[$i] ??= ['net' => 0.0, 'cogs' => 0.0];
                $buckets[$i]['net'] += $l['net'];
                $buckets[$i]['cogs'] += $l['cogs'];
            }
        }
        if (! $doc->lines && ($i = $this->ruleFor($rules, $doc->customerId, null)) !== null) {
            $buckets[$i] = ['net' => $doc->subtotal, 'cogs' => $doc->cogs];   // a document without lines: its header is all there is
        }
        ksort($buckets);
        [$salesperson, $via] = $this->salespeople->of($doc);
        $sign = $kind === 'credit_note' ? -1 : 1;
        $out = [];
        foreach ($buckets as $i => $b) {
            $rule = $rules[$i];
            $share = abs($doc->subtotal) > 0.004 ? $b['net'] / $doc->subtotal : 0.0;
            $tax = round($doc->vat * $share, 2);
            $base = match ($plan->base_kind) {
                'gross' => $b['net'] + $tax, 'margin' => $b['net'] - $b['cogs'], default => $b['net']
            };
            $out[] = [
                'kind' => $kind, 'beneficiary_id' => $earner, 'plan_id' => (string) $plan->id, 'rule_id' => (string) $rule['id'], 'rule_label' => $rule['label'] ?? null,
                'source_type' => CommissionLine::SOURCE_FINANCE, 'source_id' => $doc->id, 'doc_number' => $doc->number, 'doc_date' => $doc->date, 'earned_on' => $doc->earnedOn,
                'customer_id' => $doc->customerId, 'salesperson_id' => $salesperson, 'salesperson_via' => $via, 'invoice_total' => round($sign * $doc->total, 2),
                'base_amount' => round($sign * $base, 2), 'rate' => null, 'commission' => 0.0, 'payment_state' => $doc->status, 'reverses_id' => null, 'excluded' => false, 'exclude_reason' => null,
                'explain' => array_filter([
                    'earnOn' => $plan->earn_on, 'base' => $plan->base_kind, 'net' => round($sign * $b['net'], 2), 'tax' => round($sign * $tax, 2), 'cogs' => round($sign * $b['cogs'], 2),
                    'share' => $share < 0.9999 ? round($share, 4) : null, 'late' => $doc->earnedOn < $fromDay ? true : null,
                ], fn ($v) => $v !== null),
            ];
        }

        return $out;
    }

    /** @param array<int, array<string, mixed>> $rules */
    private function ruleFor(array $rules, string $customerId, ?string $categoryId): ?int
    {
        foreach ($rules as $i => $r) {
            if ($r['scope'] === 'all'
                || ($r['scope'] === 'customer' && in_array($customerId, (array) $r['scopeIds'], true))
                || ($r['scope'] === 'category' && $categoryId !== null && array_intersect($this->ancestorsOf($categoryId), (array) $r['scopeIds']))) {
                return $i;
            }
        }

        return null;
    }

    /** A category and everything above it: a rule on «مجمدات» covers the products of its sub-categories. @return string[] */
    private function ancestorsOf(string $categoryId): array
    {
        if ($this->ancestors === null) {
            $parents = DB::table('product_categories')->pluck('parent_id', 'id')->all();
            $this->ancestors = [];
            foreach (array_keys($parents) as $id) {
                $chain = [];
                for ($at = (string) $id; $at !== '' && ! in_array($at, $chain, true) && count($chain) < 20; $at = (string) ($parents[$at] ?? '')) {
                    $chain[] = $at;
                }
                $this->ancestors[(string) $id] = $chain;
            }
        }

        return $this->ancestors[$categoryId] ?? [$categoryId];
    }

    /**
     * The documents this earner holds a standing line for in ANOTHER statement (a line that a reversal took back no
     * longer stands). The lines of the statement being rebuilt do not count: they are what is being decided.
     *
     * @param  string[]  $sourceIds
     * @return array<string, true>
     */
    private function held(string $earner, array $sourceIds, ?string $own): array
    {
        $out = [];
        foreach (array_chunk(array_map('strval', $sourceIds), 1000) as $chunk) {
            $lines = CommissionLine::where('beneficiary_id', $earner)->where('source_type', CommissionLine::SOURCE_FINANCE)->whereIn('source_id', $chunk)->whereIn('kind', CommissionLine::GRANTS)
                ->when($own, fn ($q) => $q->where('statement_id', '!=', $own))->get(['id', 'source_id']);
            if ($lines->isEmpty()) {
                continue;
            }
            $reversed = array_flip(CommissionLine::whereIn('reverses_id', $lines->pluck('id')->all())->when($own, fn ($q) => $q->where('statement_id', '!=', $own))->pluck('reverses_id')->all());
            foreach ($lines as $l) {
                if (! isset($reversed[$l->id])) {
                    $out[(string) $l->source_id] = true;
                }
            }
        }

        return $out;
    }

    /**
     * The documents this earner may not take. What he holds in another statement of his own — and, for a salesperson,
     * what a CLOSED statement of another salesperson holds: a sale is one person's, and a later change of mind about
     * whose it is (an assignment moved to somebody else) does not pay it a second time. A draft of somebody else does not
     * count: it is rebuilt by the same run and gives up what is no longer his. A manager earns on top of the
     * salespeople, so what they hold never stands in his way.
     *
     * @param  string[]  $sourceIds
     * @return array<string, true>
     */
    private function taken(string $earner, array $sourceIds, ?string $own, string $earnerType): array
    {
        $out = $this->held($earner, $sourceIds, $own);
        if ($earnerType !== 'salesperson') {
            return $out;
        }
        foreach (array_chunk(array_map('strval', $sourceIds), 1000) as $chunk) {
            $lines = CommissionLine::query()->join('commission_statements as st', 'st.id', '=', 'commission_lines.statement_id')
                ->where('commission_lines.source_type', CommissionLine::SOURCE_FINANCE)->whereIn('commission_lines.source_id', $chunk)->whereIn('commission_lines.kind', CommissionLine::GRANTS)
                ->where('commission_lines.beneficiary_id', '!=', $earner)->where('st.earner_type', 'salesperson')->whereIn('st.status', CommissionStatement::FROZEN)
                ->get(['commission_lines.id', 'commission_lines.source_id']);
            if ($lines->isEmpty()) {
                continue;
            }
            $reversed = array_flip(CommissionLine::whereIn('reverses_id', $lines->pluck('id')->all())->pluck('reverses_id')->all());
            foreach ($lines as $l) {
                if (! isset($reversed[$l->id])) {
                    $out[(string) $l->source_id] = true;
                }
            }
        }

        return $out;
    }

    /**
     * Lines of this earner's closed statements whose document no longer earns: cancelled, or — taken on collection —
     * no longer fully paid; and the credit-note lines of an invoice taken back that way. Each is answered by a line that
     * names it and carries its figures with the other sign.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reversals(string $earner, ?string $own, string $since, string $toDay): array
    {
        $lines = CommissionLine::query()->join('commission_statements as st', 'st.id', '=', 'commission_lines.statement_id')
            ->where('commission_lines.beneficiary_id', $earner)->where('commission_lines.source_type', CommissionLine::SOURCE_FINANCE)->whereIn('commission_lines.kind', CommissionLine::GRANTS)
            ->where('commission_lines.excluded', false)->whereIn('st.status', CommissionStatement::FROZEN)->whereNull('st.source_system')->whereDate('st.date_to', '>=', $since)
            ->when($own, fn ($q) => $q->where('st.id', '!=', $own))
            ->orderBy('commission_lines.doc_date')->orderBy('commission_lines.id')->get(['commission_lines.*', 'st.number as statement_number']);
        if ($lines->isEmpty()) {
            return [];
        }
        $reversed = [];
        foreach (array_chunk($lines->pluck('id')->all(), 1000) as $chunk) {
            $reversed += array_flip(CommissionLine::whereIn('reverses_id', $chunk)->when($own, fn ($q) => $q->where('statement_id', '!=', $own))->pluck('reverses_id')->all());
        }
        $standing = $lines->reject(fn (CommissionLine $l) => isset($reversed[$l->id]))->values();
        $states = $this->documents->states($standing->pluck('source_id')->all());
        $why = [];
        $gone = [];
        foreach ($standing as $l) {
            if ($l->kind !== 'invoice') {
                continue;
            }
            $state = $states[$l->source_id] ?? null;
            $reason = $state === null ? 'missing' : ($state['status'] === 'cancelled' ? 'cancelled' : ((($l->explain['earnOn'] ?? null) === 'collected' && $state['status'] !== 'paid') ? 'unpaid' : null));
            if ($reason) {
                $why[$l->id] = $reason;
                $gone[(string) $l->source_id] = true;
            }
        }
        foreach ($standing as $l) {
            if ($l->kind !== 'credit_note') {
                continue;
            }
            $state = $states[$l->source_id] ?? null;
            $reason = $state === null ? 'missing' : ($state['status'] === 'cancelled' ? 'cancelled' : ($state['creditOfId'] !== null && isset($gone[$state['creditOfId']]) ? 'invoice_reversed' : null));
            if ($reason) {
                $why[$l->id] = $reason;
            }
        }
        $day = min(Carbon::today()->toDateString(), $toDay);
        $out = [];
        foreach ($standing as $l) {
            if (! isset($why[$l->id])) {
                continue;
            }
            $out[] = [
                'kind' => 'reversal', 'beneficiary_id' => $earner, 'plan_id' => $l->plan_id, 'rule_id' => $l->rule_id, 'rule_label' => $l->rule_label,
                'source_type' => CommissionLine::SOURCE_FINANCE, 'source_id' => (string) $l->source_id, 'doc_number' => $l->doc_number, 'doc_date' => Shape::day($l->doc_date), 'earned_on' => $day,
                'customer_id' => $l->customer_id, 'salesperson_id' => $l->salesperson_id, 'salesperson_via' => $l->salesperson_via,
                'invoice_total' => $l->invoice_total === null ? null : round(-(float) $l->invoice_total, 2), 'base_amount' => round(-(float) $l->base_amount, 2),
                'rate' => $l->rate, 'commission' => round(-(float) $l->commission, 4), 'payment_state' => $states[$l->source_id]['status'] ?? 'missing', 'reverses_id' => (string) $l->id,
                'excluded' => false, 'exclude_reason' => null, 'explain' => array_filter(['reversal' => $why[$l->id], 'of' => $l->getAttribute('statement_number'), 'ofKind' => $l->kind, 'base' => $l->explain['base'] ?? null], fn ($v) => $v !== null),
            ];
        }

        return $out;
    }

    /** Writes only what differs. @return array{added:int, changed:int, removed:int} */
    private function persist(CommissionStatement $s, array $specs, AuthUser $user): array
    {
        $existing = CommissionLine::where('statement_id', $s->id)->whereIn('kind', CommissionLine::CALCULATED)->get()->keyBy(fn (CommissionLine $l) => self::key($l->getAttributes()));
        $diff = ['added' => 0, 'changed' => 0, 'removed' => 0];
        foreach ($specs as $key => $spec) {
            $row = $existing[$key] ?? null;
            if (! $row) {
                CommissionLine::create(['statement_id' => $s->id, 'created_by' => $user->username] + $spec);
                $diff['added']++;

                continue;
            }
            $delta = self::delta($row, array_intersect_key($spec, array_flip(self::COMPARED)));
            if ($delta) {
                $row->update($delta);
                $diff['changed']++;
            }
            $existing->forget($key);
        }
        foreach ($existing as $row) {
            $row->delete();   // a line of a DRAFT that the documents no longer justify: the draft is rebuilt, nothing was owed yet
            $diff['removed']++;
        }

        return $diff;
    }

    /** What makes a calculated line the same line across runs. */
    private static function key(array $row): string
    {
        return implode('|', [$row['kind'], $row['source_id'] ?? '', $row['rule_id'] ?? '', $row['reverses_id'] ?? '']);
    }

    /** The values of `$values` that differ from what the line holds. @return array<string, mixed> */
    private static function delta(CommissionLine $line, array $values): array
    {
        $out = [];
        foreach ($values as $column => $value) {
            $current = $line->getAttribute($column);
            $same = match (true) {
                in_array($column, ['doc_date', 'earned_on'], true) => Shape::day($current) === Shape::day($value),
                in_array($column, ['invoice_total', 'base_amount', 'rate', 'commission'], true) => ($current === null) === ($value === null) && abs((float) $current - (float) $value) < 0.00005,
                $column === 'explain' => self::canon($current ?: null) === self::canon($value ?: null),
                $column === 'excluded' => (bool) $current === (bool) $value,
                default => (string) ($current ?? '') === (string) ($value ?? ''),
            };
            if (! $same) {
                $out[$column] = $value;
            }
        }

        return $out;
    }

    /** One text for a value whatever the order the database returns its keys in, and whether a number came back as 7 or 7.0. */
    private static function canon(mixed $value): string
    {
        $walk = function (mixed $v) use (&$walk): mixed {
            if (is_array($v)) {
                if (! array_is_list($v)) {
                    ksort($v);
                }

                return array_map($walk, $v);
            }

            return is_int($v) || is_float($v) ? round((float) $v, 6) : $v;
        };

        return (string) json_encode($walk($value));
    }

    /** The days of a plan a run of the month looks at: from the plan's start (not further back than the look-back) to the month's end (not past the plan's end). @return array{0:string, 1:string} */
    private static function window(CommissionPlan $plan, string $since, string $toDay): array
    {
        $from = (string) Shape::day($plan->valid_from);
        $to = Shape::day($plan->valid_to);

        return [max($from, $since), $to !== null && $to < $toDay ? $to : $toDay];
    }
}
