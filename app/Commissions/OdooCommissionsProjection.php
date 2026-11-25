<?php

namespace App\Commissions;

use App\Commissions\Models\CommissionLine;
use App\Commissions\Models\CommissionPlan;
use App\Commissions\Models\CommissionPlanMember;
use App\Commissions\Models\CommissionPlanRule;
use App\Commissions\Models\CommissionStatement;
use App\Commissions\Support\People;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Odoo\Projection\Val;
use App\Integration\Services\ExternalRefs;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * From the copy of Odoo's commission module to «العمولات». What Odoo holds, and the home each record gets:
 *
 *   sales.commission             "Sales Commission Calculation": one earner, a window of invoice dates, a type
 *                                (individual | manager), a rate "applied on profit margin", the total margin, the
 *                                commission and a state (draft → confirmed → paid)
 *                                → a STATEMENT (`source_system = 'odoo'`, `external_ref` = the Odoo id; Odoo's reference
 *                                  is kept, its totals are kept as they are: Odoo rounds once, on the whole)
 *                                → and the RULE it carries (type, rate, earner) becomes a commission PLAN, once per
 *                                  distinct rule: margin of fully paid invoices, flat rate — a draft, for a person to
 *                                  put to work in OPS;
 *   commission.invoice.line      one paid customer invoice of that calculation: its total, its profit margin, the
 *                                invoice's salesperson, excluded or not (with a reason)
 *                                → a LINE of the statement: base = the margin, rate = the calculation's, commission =
 *                                  margin × rate (unrounded, so the lines add up to Odoo's total), linked to the OPS
 *                                  customer and users where links exist, otherwise kept by name;
 *   invoice.commission.tracking  "this invoice was processed for this commission, at this time" — Odoo's guard
 *                                against paying an invoice twice
 *                                → the line's `processed_at` and `tracking_ref` (in OPS the line itself is that guard).
 *
 * LINKING is by identity only. A person is the OPS user linked to the Odoo user (`int_external_refs`, entity `user`),
 * or the one whose login or e-mail is the Odoo user's — one user, or none: then the name Odoo gives is kept, and a later
 * pass attaches the history to the user once he exists. A customer is the OPS customer the invoice's partner (or that
 * partner's company) is linked to. The invoices themselves stay Odoo's: a line names the Odoo invoice by id and number.
 *
 * Nothing is removed: a calculation deleted in Odoo keeps its statement, marked; a line deleted in Odoo is voided.
 * Idempotent: a second pass changes nothing. plan() also runs where the section's tables do not exist yet (everything
 * then counts as new) and never writes. `totals()` gives the sums of the last pass — by state, never by person.
 */
class OdooCommissionsProjection extends OdooProjection
{
    private const STATE = ['draft' => 'draft', 'confirmed' => 'approved', 'paid' => 'paid'];

    /** A whole-mirror pass (plan / apply): the OPS lines are read once, not one by one. */
    private bool $whole = false;

    /** The section's tables exist (a plan may run before the migration). */
    private bool $installed = true;

    /** @var array<int|string, CommissionStatement> statements that came from Odoo, by Odoo id */
    private array $statements = [];

    /** @var array<int|string, array{rate: float, beneficiary: ?string, plan: string}> calculations a plan decided to create */
    private array $plannedStatements = [];

    /** @var array<string, CommissionPlan> plans that came from Odoo, by their rule key */
    private array $plans = [];

    /** @var array<string, true> rule keys this pass has dealt with */
    private array $seenPlans = [];

    /** @var array<int|string, CommissionLine>|null lines that came from Odoo, by Odoo id (a whole-mirror pass) */
    private ?array $lines = null;

    /** @var array<string, CommissionLine|true> "<commission id>:<invoice id>" → the line (true: a plan decided to create it) */
    private array $lineByDoc = [];

    /** @var array<string, int>|null "<commission id>:<invoice id>" → the Odoo id of the line, read from the copy when the feed brings a tracking record first */
    private ?array $mirrorLines = null;

    /** @var array<int|string, string> OPS user id by lower-case username / e-mail */
    private array $userByName = [];

    private array $userByEmail = [];

    /** @var array<int, array{0:?string, 1:?string}> Odoo user id → [OPS user id, name kept] */
    private array $people = [];

    /** @var array<int, ?int> Odoo partner id → its company */
    private array $parents = [];

    /** @var array<string, true>|null ids of the OPS customers */
    private ?array $customerIds = null;

    /** @var array<string, true> statements whose lines this pass wrote */
    private array $touched = [];

    /** @var array<string, float|int> */
    private array $totals = [];

    public function __construct(ExternalRefs $refs, private readonly AuditService $audit, private readonly NumberingService $numbering)
    {
        parent::__construct($refs);
    }

    public function key(): string
    {
        return 'commissions';
    }

    /** Parents before children: a line belongs to a calculation, a tracking record names a line. */
    public function models(): array
    {
        return ['sales.commission', 'commission.invoice.line', 'invoice.commission.tracking'];
    }

    public function plan(): array
    {
        return $this->wholePass(fn () => parent::plan());
    }

    public function apply(): array
    {
        return $this->wholePass(function () {
            $out = parent::apply();
            $this->finish(true);

            return $out;
        });
    }

    public function consume(int $max = 500, float $seconds = 8.0): array
    {
        $out = parent::consume($max, $seconds);
        $this->finish();

        return $out;
    }

    /**
     * The sums of the last pass, for the person who reads the plan: money by statement state and in total — never by person.
     *
     * @return array<string, float|int>
     */
    public function totals(): array
    {
        return array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $this->totals);
    }

    private function wholePass(callable $pass): array
    {
        $this->whole = true;
        try {
            return $pass();
        } finally {
            $this->whole = false;
            $this->lines = null;
            $this->lineByDoc = [];
        }
    }

    protected function begin(): void
    {
        $this->installed = Schema::hasTable('commission_statements');
        if (! $this->installed && ! $this->dry) {
            throw AppError::rule('COMMISSIONS_NOT_INSTALLED', 'جداول قسم العمولات لم تُنشأ بعد — شغّل الترحيل أولًا (php artisan migrate)', 'The tables of the commissions section do not exist yet — run the migration first');
        }
        $this->plannedStatements = $this->seenPlans = $this->lineByDoc = $this->people = $this->parents = $this->touched = $this->totals = $this->userByName = $this->userByEmail = [];
        $this->mirrorLines = $this->customerIds = null;
        $this->statements = $this->installed ? CommissionStatement::where('source_system', self::SYSTEM)->get()->keyBy('external_ref')->all() : [];
        $this->plans = $this->installed ? CommissionPlan::where('source_system', self::SYSTEM)->get()->keyBy('external_ref')->all() : [];
        $this->lines = null;
        if ($this->whole) {
            $this->lines = [];
            if ($this->installed) {
                $byId = [];
                foreach ($this->statements as $ref => $s) {
                    $byId[$s->id] = $ref;
                }
                foreach (CommissionLine::where('source_system', self::SYSTEM)->lazy(1000) as $line) {
                    $this->lines[$line->external_ref] = $line;
                    if ($line->source_id !== null && isset($byId[$line->statement_id])) {
                        $this->lineByDoc[$byId[$line->statement_id].':'.$line->source_id] = $line;
                    }
                }
            }
        }
        foreach (DB::table('users')->where('username', 'not like', People::SERVICE_PREFIX.'%')->get(['id', 'username', 'email']) as $u) {
            $this->userByName[mb_strtolower((string) $u->username)] = (string) $u->id;
            if ($u->email) {
                $this->userByEmail[mb_strtolower((string) $u->email)] = (string) $u->id;
            }
        }
    }

    protected function project(OdooRecord $r): void
    {
        match ($r->model) {
            'sales.commission' => $this->statement($r),
            'commission.invoice.line' => $this->line($r),
            'invoice.commission.tracking' => $this->tracking($r),
            default => null,
        };
    }

    // ───────────── sales.commission → a statement (and the plan its rule is) ─────────────

    private function statement(OdooRecord $r): ?CommissionStatement
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $row = $this->statements[$r->odoo_id] ?? null;
        $gone = $r->deleted_at !== null;
        if (! $row && $gone) {
            $this->count('statements.skippedDeleted');

            return null;
        }
        $to = Val::date($d['date_to'] ?? null) ?? Val::date($d['date_from'] ?? null);
        if (! $to) {
            // a statement belongs to a month: without a date it has no place
            $this->count('statements.skippedNoPeriod', "sales.commission #{$ref}");

            return null;
        }
        $from = Val::date($d['date_from'] ?? null) ?? $to;
        $type = ($d['commission_type'] ?? null) === 'manager' ? 'manager' : 'salesperson';
        $rate = round(Val::float($d['commission_rate'] ?? 0), 4);
        $odooUser = Val::id($d['salesperson_id'] ?? null);
        [$userId, $name] = $this->person($d['salesperson_id'] ?? null);
        $status = self::STATE[$d['state'] ?? 'draft'] ?? 'draft';
        $commission = round(Val::float($d['commission_amount'] ?? 0), 2);
        $planId = $this->planFor($type, $rate, $d['salesperson_id'] ?? null, $userId, $name, $from);
        $this->add("statements.{$status}.count", 1);
        $this->add("statements.{$status}.commission", $commission);
        $this->add('statements.commission', $commission);
        $this->add('statements.margin', round(Val::float($d['total_margin'] ?? 0), 2));
        $values = [
            'reference' => Val::text($d['name'] ?? null, 120) ?? Val::text($r->display_name, 120), 'beneficiary_id' => $userId, 'beneficiary_name' => $name,
            'beneficiary_ref' => $odooUser ? (string) $odooUser : null, 'period' => substr($to, 0, 7), 'date_from' => $from, 'date_to' => $to, 'plan_id' => $planId, 'earner_type' => $type,
            'terms' => self::terms($type, (string) ($d['commission_type'] ?? 'individual'), $rate), 'status' => $status,
            'base_total' => round(Val::float($d['total_margin'] ?? 0), 2), 'commission_total' => $commission,
            'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $gone ? $r->deleted_at : null,
        ];
        if (! $row) {
            $this->count('statements.created');
            if ($this->dry) {
                $this->plannedStatements[$r->odoo_id] = ['rate' => $rate, 'beneficiary' => $userId, 'plan' => $planId];

                return null;
            }
            $row = DB::transaction(function () use ($d, $ref, $values, $commission) {
                $s = CommissionStatement::create($values + [
                    'number' => $this->numbering->next('CMS'), 'slot' => self::SYSTEM.':'.$ref, 'total' => $commission, 'adjustments_total' => 0, 'lines_count' => 0,
                    'source_system' => self::SYSTEM, 'external_ref' => $ref, 'created_by' => Val::label($d['create_uid'] ?? null), 'created_at' => Val::at($d['create_date'] ?? null) ?? now(),
                ]);
                $this->audit->log($this->actor(), ['action' => 'COMMISSION.STATEMENT.CREATE', 'entityType' => 'CommissionStatement', 'entityId' => $s->id, 'entityNumber' => $s->number,
                    'newValue' => ['origin' => "from Odoo sales.commission #{$ref}", 'reference' => $s->reference, 'period' => $s->period, 'status' => $s->status]]);

                return $s;
            });
            $this->statements[$r->odoo_id] = $row;

            return $row;
        }
        // what a person added here after the hand-over (adjustments) stays on top of Odoo's own figure
        $values['total'] = round($commission + (float) $row->adjustments_total, 2);
        $changes = self::changes($row, $values);
        if (! $changes) {
            $this->count('statements.unchanged');

            return $row;
        }
        $this->count(isset($changes['source_deleted_at']) && $gone ? 'statements.archived' : 'statements.updated');
        if ($this->dry) {
            return $row;
        }
        DB::transaction(function () use ($row, $changes, $rate) {
            $old = self::plain(array_intersect_key($row->getAttributes(), $changes));
            $from = $row->status;
            $row->update($changes);
            // the lines follow what their calculation says: its rate, its earner, its plan
            if (array_intersect_key($changes, array_flip(['beneficiary_id', 'plan_id', 'terms']))) {
                CommissionLine::where('statement_id', $row->id)->where('source_system', self::SYSTEM)->update([
                    'beneficiary_id' => $row->beneficiary_id, 'plan_id' => $row->plan_id, 'rate' => $rate,
                    'commission' => DB::raw('CASE WHEN excluded OR voided_at IS NOT NULL THEN 0 ELSE ROUND(base_amount * '.number_format($rate, 4, '.', '').' / 100, 4) END'),
                ]);
            }
            if (isset($changes['status'])) {
                $this->audit->status($this->actor(), 'CommissionStatement', $row->id, $row->number, $from, $row->status, 'Odoo');
            }
            $this->audit->log($this->actor(), ['action' => 'COMMISSION.STATEMENT.UPDATE', 'entityType' => 'CommissionStatement', 'entityId' => $row->id, 'entityNumber' => $row->number,
                'oldValue' => $old, 'newValue' => self::plain($changes) + ['origin' => 'Odoo']]);
        });

        return $row;
    }

    /**
     * The plan a calculation's rule is: one per (type, rate, earner) for a manager, one per (type, rate) for the
     * salespeople — made once, as a draft OPS can take over; later passes only widen it (an earlier start, one more
     * salesperson). In a plan of a pass: the id, or a marker for "will be made".
     */
    private function planFor(string $type, float $rate, mixed $many2one, ?string $userId, ?string $name, string $from): ?string
    {
        $odooUser = Val::id($many2one);
        $key = $type === 'manager' ? "manager:{$rate}:".($odooUser ?? 0) : "individual:{$rate}";
        $plan = $this->plans[$key] ?? null;
        $first = ! isset($this->seenPlans[$key]);
        $this->seenPlans[$key] = true;
        if (! $plan) {
            if ($first) {
                $this->count('plans.created');
            }
            if ($this->dry) {
                return "new:plan:{$key}";
            }
            $plan = DB::transaction(function () use ($type, $rate, $odooUser, $userId, $name, $from, $key) {
                $pct = rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.');
                $plan = CommissionPlan::create([
                    'code' => $this->numbering->next('CPL'),
                    'name_ar' => ($type === 'manager' ? "عمولة مدير المبيعات {$pct}% من هامش الفواتير المحصّلة" : "عمولة المندوب {$pct}% من هامش فواتيره المحصّلة").' (أودو)',
                    'name_en' => ($type === 'manager' ? "Sales manager {$pct}% of the margin of paid invoices" : "Salesperson {$pct}% of the margin of his paid invoices").' (Odoo)',
                    'earner_type' => $type, 'beneficiary_id' => $type === 'manager' ? $userId : null, 'beneficiary_name' => $type === 'manager' ? $name : null,
                    'beneficiary_ref' => $type === 'manager' && $odooUser ? (string) $odooUser : null,
                    'everyone' => $type === 'manager',   // Odoo: "Manager: commission for all invoices"
                    'earn_on' => 'collected', 'base_kind' => 'margin', 'net_of_returns' => false, 'valid_from' => $from, 'status' => 'draft',
                    'notes' => 'القاعدة التي كان أودو يحتسب بها: نسبة من هامش ربح الفواتير المسدَّدة بالكامل. مسودة — راجعها وفعّلها (أو انسخها) حين تُحتسب العمولات في نظام العمليات.',
                    'source_system' => self::SYSTEM, 'external_ref' => $key, 'created_by' => $this->actor()->username,
                ]);
                CommissionPlanRule::create(['plan_id' => $plan->id, 'sequence' => 1, 'scope' => 'all', 'rate_type' => 'flat', 'rate' => $rate]);
                $this->audit->log($this->actor(), ['action' => 'COMMISSION.PLAN.CREATE', 'entityType' => 'CommissionPlan', 'entityId' => $plan->id, 'entityNumber' => $plan->code,
                    'newValue' => ['origin' => 'the rule of Odoo sales.commission', 'type' => $type, 'rate' => $rate, 'earnOn' => 'collected', 'base' => 'margin']]);

                return $plan;
            });
            $this->plans[$key] = $plan;
        } else {
            $changes = array_filter([
                'valid_from' => $from < $plan->valid_from->format('Y-m-d') ? $from : null,
                'beneficiary_id' => $type === 'manager' && $plan->beneficiary_id !== $userId && $plan->status === 'draft' ? ($userId ?? false) : null,
            ], fn ($v) => $v !== null);
            if ($first) {
                $this->count($changes ? 'plans.updated' : 'plans.unchanged');
            }
            if ($changes && ! $this->dry) {
                $plan->update(array_map(fn ($v) => $v === false ? null : $v, $changes) + (array_key_exists('beneficiary_id', $changes) ? ['beneficiary_name' => $name] : []));
            }
        }
        // the salespeople of an "individual" rule are the people Odoo calculated it for
        if ($type !== 'manager' && $odooUser && ! $this->dry) {
            $member = CommissionPlanMember::where('plan_id', $plan->id)->where(fn ($w) => $w->where('person_ref', (string) $odooUser)->when($userId, fn ($q) => $q->orWhere('user_id', $userId)))->first();
            if (! $member) {
                CommissionPlanMember::create(['plan_id' => $plan->id, 'user_id' => $userId, 'person_name' => $userId ? null : $name, 'person_ref' => (string) $odooUser]);
            } elseif ($userId && ! $member->user_id) {
                $member->update(['user_id' => $userId, 'person_name' => null]);   // the person has an OPS user now
            }
        }

        return (string) $plan->id;
    }

    /** The terms a statement of Odoo was calculated at, in the shape the statements of OPS keep theirs. */
    private static function terms(string $type, string $odooType, float $rate): array
    {
        return [
            'origin' => self::SYSTEM, 'commissionType' => $odooType, 'earnerType' => $type, 'earnOn' => 'collected', 'base' => 'margin', 'netOfReturns' => false, 'everyone' => $type === 'manager',
            'rules' => [['id' => null, 'sequence' => 1, 'scope' => 'all', 'scopeIds' => [], 'rateType' => 'flat', 'rate' => $rate, 'tierMode' => null, 'tiers' => null, 'label' => null]],
        ];
    }

    // ───────────── commission.invoice.line → a line ─────────────

    private function line(OdooRecord $r): ?CommissionLine
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $line = $this->lines !== null ? ($this->lines[$ref] ?? null) : ($this->installed ? CommissionLine::where('source_system', self::SYSTEM)->where('external_ref', $ref)->first() : null);
        $gone = $r->deleted_at !== null;
        if (! $line && $gone) {
            $this->count('lines.skippedDeleted');

            return null;
        }
        $commissionId = Val::id($d['commission_id'] ?? null);
        $statement = $this->statementOf($commissionId);
        $planned = $commissionId ? ($this->plannedStatements[$commissionId] ?? null) : null;
        if (! $statement && ! $planned) {
            // a line is a line of a calculation: without one it has no place
            $this->count('lines.skippedNoStatement', "commission.invoice.line #{$ref}");

            return null;
        }
        $rate = $statement ? (float) ($statement->terms['rules'][0]['rate'] ?? 0) : $planned['rate'];
        $invoiceId = Val::id($d['invoice_id'] ?? null);
        $partnerId = Val::id($d['partner_id'] ?? null);
        $odooUser = Val::id($d['salesperson_id'] ?? null);
        [$salespersonId, $salespersonName] = $this->person($d['salesperson_id'] ?? null);
        $customerId = $this->customer($partnerId);
        $excluded = Val::bool($d['excluded'] ?? false);
        $margin = round(Val::float($d['margin'] ?? 0), 2);
        $commission = $excluded || $gone ? 0.0 : round($margin * $rate / 100, 4);
        $values = [
            'statement_id' => $statement?->id, 'kind' => 'invoice', 'beneficiary_id' => $statement ? $statement->beneficiary_id : $planned['beneficiary'],
            'plan_id' => $statement ? $statement->plan_id : null, 'source_type' => CommissionLine::SOURCE_ODOO, 'source_id' => $invoiceId ? (string) $invoiceId : null,
            'doc_number' => Val::text($d['invoice_number'] ?? null, 80) ?? self::cut(Val::label($d['invoice_id'] ?? null), 80), 'doc_date' => Val::date($d['invoice_date'] ?? null),
            'customer_id' => $customerId, 'customer_name' => self::cut(Val::label($d['partner_id'] ?? null), 250), 'customer_ref' => $partnerId ? (string) $partnerId : null,
            'salesperson_id' => $salespersonId, 'salesperson_name' => $salespersonName, 'salesperson_ref' => $odooUser ? (string) $odooUser : null, 'salesperson_via' => 'odoo',
            'invoice_total' => round(Val::float($d['amount_total'] ?? 0), 2), 'base_amount' => $margin, 'rate' => $rate, 'commission' => $commission,
            'excluded' => $excluded, 'exclude_reason' => Val::text($d['exclude_reason'] ?? null, 250), 'payment_state' => Val::text($d['payment_state'] ?? null, 20),
            'explain' => ['origin' => self::SYSTEM, 'earnOn' => 'collected', 'base' => 'margin'],
        ];
        if (! $gone) {
            $this->add('lines.count', 1);
            $this->add('lines.invoiced', (float) $values['invoice_total']);
            $this->add('lines.margin', $excluded ? 0.0 : $margin);
            $this->add('lines.commission', $commission);
            if ($customerId) {
                $this->count('lines.withCustomer');
            }
            if ($excluded) {
                $this->count('lines.excluded');
            }
        }
        $docKey = "{$commissionId}:{$invoiceId}";
        if (! $line) {
            $this->count('lines.created');
            if ($this->dry) {
                $this->lineByDoc[$docKey] = true;

                return null;
            }
            $line = CommissionLine::create($values + ['source_system' => self::SYSTEM, 'external_ref' => $ref, 'created_by' => $this->actor()->username, 'created_at' => Val::at($d['create_date'] ?? null) ?? now()]);
            $this->touched[(string) $line->statement_id] = true;
            $this->remember($ref, $docKey, $line);

            return $line;
        }
        if ($gone) {
            // removed from its calculation in Odoo: kept here, struck out, counted no more (who it names is still kept current)
            $values = array_intersect_key($values, array_flip(['beneficiary_id', 'customer_id', 'salesperson_id', 'salesperson_name']))
                + ($line->voided_at ? [] : ['voided_at' => $r->deleted_at, 'voided_by' => self::SYSTEM, 'void_reason' => 'حُذف السطر في أودو', 'commission' => 0]);
        } elseif (! $statement) {
            unset($values['statement_id'], $values['plan_id']);   // a plan of a pass: the calculation is not made yet
        }
        $changes = self::changes($line, $values);
        if (! $changes) {
            $this->count('lines.unchanged');
            $this->remember($ref, $docKey, $line);

            return $line;
        }
        $this->count(isset($changes['voided_at']) ? 'lines.archived' : 'lines.updated');
        if ($this->dry) {
            return $line;
        }
        DB::transaction(function () use ($line, $changes, $ref) {
            $before = (string) $line->statement_id;
            $old = self::plain(array_intersect_key($line->getAttributes(), $changes));
            $line->update($changes);
            $this->touched[$before] = $this->touched[(string) $line->statement_id] = true;
            $this->audit->log($this->actor(), ['action' => 'COMMISSION.LINE.UPDATE', 'entityType' => 'CommissionLine', 'entityId' => $line->id, 'entityNumber' => $line->doc_number,
                'oldValue' => $old, 'newValue' => self::plain($changes) + ['origin' => "Odoo commission.invoice.line #{$ref}"]]);
        });
        $this->remember($ref, $docKey, $line);

        return $line;
    }

    private function remember(string $ref, string $docKey, CommissionLine $line): void
    {
        if ($this->lines !== null) {
            $this->lines[$ref] = $line;
        }
        $this->lineByDoc[$docKey] = $line;
    }

    /** The statement of an Odoo calculation; one the pass has not met yet (the feed brought a line first) is made from the copy on the spot. */
    private function statementOf(?int $commissionId): ?CommissionStatement
    {
        if (! $commissionId) {
            return null;
        }
        if (isset($this->statements[$commissionId])) {
            return $this->statements[$commissionId];
        }
        if (isset($this->plannedStatements[$commissionId])) {
            return null;
        }
        $r = $this->mirrored('sales.commission', $commissionId);

        return $r ? $this->statement($r) : null;
    }

    // ───────────── invoice.commission.tracking → the line's "processed at" ─────────────

    private function tracking(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $commissionId = Val::id($d['commission_id'] ?? null);
        $invoiceId = Val::id($d['invoice_id'] ?? null);
        $docKey = "{$commissionId}:{$invoiceId}";
        $line = $this->trackedLine($commissionId, $invoiceId, $docKey);
        if ($r->deleted_at !== null) {
            if ($line instanceof CommissionLine && $line->tracking_ref === $ref) {
                $this->count('tracking.removed');
                if (! $this->dry) {
                    $line->update(['tracking_ref' => null, 'processed_at' => null]);
                }
            } else {
                $this->count('tracking.skippedDeleted');
            }

            return;
        }
        if ($line === null) {
            // Odoo says the invoice was processed, and holds no line of it for that calculation
            $this->count('tracking.skippedNoLine', "invoice.commission.tracking #{$ref}");

            return;
        }
        if ($line === true) {
            $this->count('tracking.attached');   // a plan of a pass: the line will be made, and this with it

            return;
        }
        $changes = self::changes($line, ['processed_at' => Val::at($d['date_processed'] ?? null), 'tracking_ref' => $ref]);
        if (! $changes) {
            $this->count('tracking.unchanged');

            return;
        }
        $this->count($line->tracking_ref ? 'tracking.updated' : 'tracking.attached');
        if (! $this->dry) {
            $line->update($changes);
        }
    }

    /** @return CommissionLine|true|null the line a tracking record speaks of (true: a plan of a pass will create it) */
    private function trackedLine(?int $commissionId, ?int $invoiceId, string $docKey): CommissionLine|bool|null
    {
        if (! $commissionId || ! $invoiceId) {
            return null;
        }
        if (isset($this->lineByDoc[$docKey])) {
            return $this->lineByDoc[$docKey];
        }
        if ($this->lines === null && $this->installed) {
            $statement = $this->statementOf($commissionId);
            $line = $statement ? CommissionLine::where('statement_id', $statement->id)->where('source_system', self::SYSTEM)->where('source_type', CommissionLine::SOURCE_ODOO)
                ->where('source_id', (string) $invoiceId)->first() : null;
            if ($line) {
                return $this->lineByDoc[$docKey] = $line;
            }
        }
        // the feed may bring the tracking record before its line: the line is made from the copy on the spot
        if ($this->mirrorLines === null) {
            $this->mirrorLines = [];
            foreach (OdooRecord::where('model', 'commission.invoice.line')->whereNull('deleted_at')->lazy(1000) as $m) {
                $this->mirrorLines[Val::id($m->data['commission_id'] ?? null).':'.Val::id($m->data['invoice_id'] ?? null)] = (int) $m->odoo_id;
            }
        }
        $record = isset($this->mirrorLines[$docKey]) ? $this->mirrored('commission.invoice.line', $this->mirrorLines[$docKey]) : null;
        if ($record) {
            $this->line($record);
        }

        return $this->lineByDoc[$docKey] ?? null;
    }

    /**
     * After a pass that wrote: the number of lines each touched statement holds. A whole pass checks EVERY statement
     * that came from Odoo: a first load that was cut short left lines no later pass "touches", and the next whole pass
     * must still leave each statement saying how many lines it holds. Nothing is written where the number is right.
     */
    private function finish(bool $every = false): void
    {
        $ids = array_map('strval', array_keys($this->touched));
        if ($every && $this->installed) {
            foreach ($this->statements as $s) {
                $ids[] = (string) $s->id;
            }
        }
        foreach (array_unique($ids) as $id) {
            if ($id !== '') {
                $n = CommissionLine::where('statement_id', $id)->whereIn('kind', CommissionLine::CALCULATED)->whereNull('voided_at')->count();
                CommissionStatement::whereKey($id)->where('lines_count', '!=', $n)->update(['lines_count' => $n]);
            }
        }
        $this->touched = [];
    }

    // ───────────── people, customers ─────────────

    /**
     * The OPS user an Odoo user is — a link, or the login / e-mail, and only when exactly one user answers — or the name to keep.
     *
     * @return array{0:?string, 1:?string} [OPS user id, name kept when there is none]
     */
    private function person(mixed $many2one): array
    {
        $odooId = Val::id($many2one);
        if (! $odooId) {
            return [null, null];
        }
        if (! isset($this->people[$odooId])) {
            $name = Val::label($many2one);
            $found = [];
            if ($linked = $this->linked('user', $odooId)) {
                $found[(string) $linked] = true;
            } elseif ($u = $this->mirrored('res.users', $odooId)) {
                $keys = [Val::text($u->data['login'] ?? null), Val::text($u->data['email'] ?? null),
                    Val::text($this->mirrored('res.partner', Val::id($u->data['partner_id'] ?? null))?->data['email'] ?? null)];   // the e-mail of an Odoo user lives on its partner
                $name ??= Val::text($u->display_name);
                foreach (array_filter($keys) as $key) {
                    foreach ([$this->userByEmail[mb_strtolower($key)] ?? null, $this->userByName[mb_strtolower($key)] ?? null] as $id) {
                        if ($id) {
                            $found[$id] = true;
                        }
                    }
                }
            }
            $id = count($found) === 1 ? (string) array_key_first($found) : null;
            $this->people[$odooId] = [$id, $id ? null : self::cut($name ?? "#{$odooId}", 190)];
            $this->count($id ? 'people.matched' : 'people.byName');
        }

        return $this->people[$odooId];
    }

    /** The OPS customer the invoice's partner is linked to; for a contact or a branch of a company, the company's. */
    private function customer(?int $partnerId): ?string
    {
        if (! $partnerId) {
            return null;
        }
        $id = $this->linked('customer', $partnerId);
        if (! $id) {
            if (! array_key_exists($partnerId, $this->parents)) {
                $this->parents[$partnerId] = Val::id($this->mirrored('res.partner', $partnerId)?->data['parent_id'] ?? null);
            }
            $id = $this->parents[$partnerId] ? $this->linked('customer', $this->parents[$partnerId]) : null;
        }
        if (! $id) {
            return null;
        }
        $this->customerIds ??= array_flip(DB::table('customers')->pluck('id')->map(fn ($x) => (string) $x)->all());

        return isset($this->customerIds[(string) $id]) ? (string) $id : null;   // a link to a customer that is no longer there names nobody
    }

    // ───────────── helpers ─────────────

    private function add(string $what, float|int $amount): void
    {
        $this->totals[$what] = ($this->totals[$what] ?? 0) + $amount;
    }

    private static function cut(?string $s, int $max): ?string
    {
        return $s === null ? null : mb_substr($s, 0, $max);
    }

    /** Values as the audit trail writes them. */
    private static function plain(array $values): array
    {
        return array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, $values);
    }

    /**
     * The values that differ from what the row holds. A moment equals the same moment, a day its `YYYY-MM-DD`, 5 equals
     * "5.00", a map equals the same map whatever the order of its keys, and a marker of a plan ("new:…") always differs.
     *
     * @return array<string, mixed>
     */
    private static function changes(Model $row, array $values): array
    {
        $out = [];
        foreach ($values as $column => $value) {
            $current = $row->getAttribute($column);
            if ($current instanceof DateTimeInterface || $value instanceof DateTimeInterface) {
                $format = (is_string($value) && strlen($value) === 10) || (is_string($current) && strlen($current) === 10) ? 'Y-m-d' : 'Y-m-d H:i:s';
                $same = ($current instanceof DateTimeInterface ? $current->format($format) : $current) === ($value instanceof DateTimeInterface ? $value->format($format) : $value);
            } elseif (is_bool($current) || is_bool($value)) {
                $same = $current !== null && $value !== null && (bool) $current === (bool) $value;
            } elseif (is_array($current) || is_array($value)) {
                $same = self::canon($current ?: null) === self::canon($value ?: null);
            } elseif (is_numeric($current) && is_numeric($value) && (is_int($value) || is_float($value) || is_int($current) || is_float($current))) {
                $same = abs((float) $current - (float) $value) < 0.00005;
            } else {
                $same = (string) ($current ?? '') === (string) ($value ?? '');
            }
            if (! $same) {
                $out[$column] = $value;
            }
        }

        return $out;
    }

    /** One text for a map whatever the order the database returns its keys in, and whether a number came back as 7 or 7.0. */
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
}
