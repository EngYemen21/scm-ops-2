<?php

namespace App\Banking;

use App\Banking\Models\PaymentTerm;
use App\Banking\Models\SourceJournal;
use App\Banking\Models\Statement;
use App\Banking\Models\StatementLine;
use App\Banking\Models\WriteoffRule;
use App\Banking\Services\SourceJournalsService;
use App\Banking\Support\Mask;
use App\Banking\Support\Money;
use App\Banking\Support\Sheet\Cells;
use App\Finance\Models\BankAccount;
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
 * From the copy of Odoo's banking to «البنوك والمطابقة». Idempotent: a second pass changes nothing.
 *
 * BANK AND CASH JOURNALS (`account.journal`, type bank | cash) become `bank_source_journals` and are LINKED to the OPS
 * bank account each of them is — by identity only: the same IBAN, the same account number (an IBAN ends with it), or
 * exactly the same name, and only when ONE OPS bank account answers and no other journal holds it. Nothing is doubled:
 * the projection never creates an OPS bank account. What it cannot link is counted and named (`journals.unlinked`)
 * for a person, who links or adopts it on the screen. A link, once made — here or by a person — is OPS's and a pass
 * never changes it; a link a person removed is not made again.
 *
 * STATEMENT LINES (`account.bank.statement.line`, with `account.bank.statement` where Odoo groups them) become
 * HISTORY: statements of status `history` and their lines, read-only, each with what Odoo says of it (reconciled
 * there, open, or cancelled with its journal entry). A line without a statement (Odoo 17 allows it) is kept in one
 * history statement per journal. History never takes part in the OPS reconciliation. The day and the journal of a
 * line are its journal entry's (`account.move`): the line model itself holds neither.
 *
 * RECONCILIATION MODELS (`account.reconcile.model` + its lines) become write-off rules: a model with a write-off
 * button is a rule; an invoice-matching model has no counterpart account and is kept as the record it is (`matching`:
 * it never posts). The OPS ledger account and the limit of a rule are OPS's own (Odoo's chart is not OPS's) and a
 * pass never touches them. PAYMENT TERMS (`account.payment.term` + its lines) are kept as a dictionary.
 *
 * Payments and the reconciliations of invoices with payments (`account.payment`, `account.partial.reconcile`,
 * `account.full.reconcile`) are documents of the accounting archive and are not projected here.
 *
 * Bank account numbers are compared and stored, never counted, noted, logged or audited.
 * plan() also runs where the section's tables do not exist yet (it then counts everything as new) — it never writes.
 */
class OdooBankingProjection extends OdooProjection
{
    private const KINDS = ['bank', 'cash'];

    /** What Odoo holds on a reconciliation model and OPS has no column for — kept as it is, when it holds something. */
    private const RULE_EXTRA = ['rule_type', 'auto_reconcile', 'to_check', 'matching_order', 'match_amount', 'match_amount_min', 'match_amount_max', 'match_label', 'match_label_param', 'match_note',
        'match_note_param', 'match_transaction_type', 'match_transaction_type_param', 'match_partner', 'match_same_currency', 'allow_payment_tolerance', 'payment_tolerance_type', 'payment_tolerance_param', 'past_months_limit'];

    /** A whole-mirror pass (plan / apply): the OPS rows are read once, and the children are folded into their parents. */
    private bool $whole = false;

    private bool $installed = true;

    /** @var array<int|string, SourceJournal> by Odoo journal id */
    private array $journals = [];

    /** @var array<string, Statement> by external ref ("<statement id>" or "loose:<journal id>") */
    private array $statements = [];

    /** @var array<int|string, StatementLine>|null by Odoo line id (a whole-mirror pass) */
    private ?array $lines = null;

    /** @var array<int|string, WriteoffRule> */
    private array $rules = [];

    /** @var array<int|string, PaymentTerm> */
    private array $terms = [];

    /** @var array<string, array<string, true>> what a plan decided to create, per entity */
    private array $planned = [];

    /** @var array<string, int|string> OPS bank account id → the Odoo journal that holds it (existing links and the ones this pass makes) */
    private array $held = [];

    /** @var array<int, BankAccount>|null the OPS bank accounts, read once */
    private ?array $banks = null;

    /** @var array<string, array<int, array<int, array>>> children of the copy per parent: model → parent id → rows */
    private array $children = [];

    /** @var array<string, true> parents already re-projected because one of their children changed */
    private array $refolded = [];

    /** @var array<string, int> the next line number of a history statement */
    private array $lineNo = [];

    public function __construct(
        ExternalRefs $refs,
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly SourceJournalsService $journalLinks,
    ) {
        parent::__construct($refs);
    }

    public function key(): string
    {
        return 'banking';
    }

    /** Parents before children: a statement belongs to a journal, a line to a statement; a model and a term before their lines. */
    public function models(): array
    {
        return ['account.journal', 'account.bank.statement', 'account.bank.statement.line', 'account.reconcile.model', 'account.reconcile.model.line', 'account.payment.term', 'account.payment.term.line'];
    }

    public function plan(): array
    {
        return $this->wholePass(fn () => parent::plan());
    }

    public function apply(): array
    {
        return $this->wholePass(fn () => parent::apply());
    }

    private function wholePass(callable $pass): array
    {
        $this->whole = true;
        try {
            return $pass();
        } finally {
            $this->whole = false;
            $this->lines = null;
        }
    }

    protected function begin(): void
    {
        $this->installed = Schema::hasTable('bank_statements');
        if (! $this->installed && ! $this->dry) {
            throw AppError::rule('BANKING_NOT_INSTALLED', 'جداول قسم البنوك والمطابقة لم تُنشأ بعد — شغّل الترحيل أولًا (php artisan migrate)', 'The tables of the banking section do not exist yet — run the migration first');
        }
        $this->planned = $this->children = $this->refolded = $this->lineNo = $this->held = [];
        $this->banks = null;
        $own = fn (string $class) => $this->installed ? $class::where('source_system', self::SYSTEM)->get()->keyBy('external_ref')->all() : [];
        $this->journals = $own(SourceJournal::class);
        $this->statements = $own(Statement::class);
        $this->rules = $own(WriteoffRule::class);
        $this->terms = $own(PaymentTerm::class);
        $this->lines = $this->whole ? $own(StatementLine::class) : null;
        if ($this->installed) {
            foreach (SourceJournal::whereNotNull('bank_account_id')->get(['bank_account_id', 'external_ref', 'id']) as $j) {
                $this->held[(string) $j->bank_account_id] = $j->external_ref ?? $j->id;
            }
        }
    }

    protected function project(OdooRecord $r): void
    {
        match ($r->model) {
            'account.journal' => $this->journal($r),
            'account.bank.statement' => $this->statement($r),
            'account.bank.statement.line' => $this->line($r),
            'account.reconcile.model' => $this->rule($r),
            'account.payment.term' => $this->term($r),
            // a child changed: its parent is read again (a whole pass has just done that with every child in hand)
            'account.reconcile.model.line' => $this->refold('account.reconcile.model', Val::id($r->data['model_id'] ?? null)),
            'account.payment.term.line' => $this->refold('account.payment.term', Val::id($r->data['payment_id'] ?? null)),
            default => null,
        };
    }

    private function refold(string $model, ?int $parentId): void
    {
        if ($this->whole || ! $parentId || isset($this->refolded["{$model}:{$parentId}"])) {
            return;
        }
        $this->refolded["{$model}:{$parentId}"] = true;
        unset($this->children[$model === 'account.reconcile.model' ? 'account.reconcile.model.line' : 'account.payment.term.line']);
        if ($parent = $this->mirrored($model, $parentId)) {
            $this->project($parent);
        }
    }

    // ───────────── bank and cash journals ─────────────

    private function journal(OdooRecord $r): ?SourceJournal
    {
        $d = (array) $r->data;
        $row = $this->journals[$r->odoo_id] ?? null;
        $kind = in_array($d['type'] ?? null, self::KINDS, true) ? $d['type'] : null;
        if ($kind === null && ! $row) {
            $this->count('journals.skippedNotBank');   // sales, purchases and miscellaneous journals are not this section's

            return null;
        }
        if (! $row && $r->deleted_at !== null) {
            $this->count('journals.skippedDeleted');

            return null;
        }
        $account = $this->mirrored('res.partner.bank', Val::id($d['bank_account_id'] ?? null));
        $number = Val::text($account?->data['acc_number'] ?? null, 64);
        $name = self::cut(Val::text($d['name'] ?? null) ?? Val::text($r->display_name) ?? "#{$r->odoo_id}", 190);
        $values = [
            'code' => Val::text($d['code'] ?? null, 20), 'name' => $name, 'kind' => $kind ?? $row->kind, 'currency' => self::cut(Val::label($d['currency_id'] ?? null), 3),
            'bank_name' => self::cut(Val::label($account?->data['bank_id'] ?? null) ?? Val::label($d['bank_id'] ?? null), 190), 'account_number' => $number,
            'account_key' => self::cut(Mask::key(Val::text($account?->data['sanitized_acc_number'] ?? null, 64) ?? $number), 64),
            'ledger_account' => self::cut(Val::label($d['default_account_id'] ?? null), 190), 'active' => $kind !== null && $this->alive($r),
            'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $r->deleted_at,
        ];
        $label = trim(($values['code'] ? "{$values['code']} " : '').$name);
        if (! $row) {
            $this->count('journals.created');
            if (! $this->dry) {
                $row = DB::transaction(function () use ($r, $values, $label) {
                    $row = SourceJournal::create($values + ['source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id, 'auto_link' => true, 'created_by' => $this->actor()->username]);
                    $this->audit->log($this->actor(), ['action' => 'BANK.JOURNAL.CREATE', 'entityType' => 'BankSourceJournal', 'entityId' => $row->id, 'entityNumber' => $label,
                        'newValue' => ['origin' => "from Odoo account.journal #{$r->odoo_id}", 'kind' => $values['kind'], 'active' => $values['active']]]);

                    return $row;
                });
                $this->journals[$r->odoo_id] = $row;
            } else {
                $this->planned['journals'][(string) $r->odoo_id] = true;
            }
        } else {
            $changes = self::changes($row, $values);
            $this->count(! $changes ? 'journals.unchanged' : (($changes['active'] ?? null) === false ? 'journals.archived' : 'journals.updated'));
            if ($changes && ! $this->dry) {
                DB::transaction(function () use ($row, $changes, $label) {
                    $row->update($changes);
                    // the trail says which facts changed — an account number is never copied into it
                    $this->audit->log($this->actor(), ['action' => 'BANK.JOURNAL.UPDATE', 'entityType' => 'BankSourceJournal', 'entityId' => $row->id, 'entityNumber' => $label,
                        'newValue' => ['changed' => array_keys($changes), 'origin' => 'Odoo'] + array_intersect_key($changes, ['name' => 1, 'code' => 1, 'kind' => 1, 'active' => 1])]);
                });
            }
        }
        $this->recognise($r, $row, $values, $label);

        return $row;
    }

    /** Links the journal to the one OPS bank account it is — or says, for a person, that none (or more than one) answers. */
    private function recognise(OdooRecord $r, ?SourceJournal $row, array $values, string $label): void
    {
        if (! $values['active'] || $r->deleted_at !== null) {
            return;
        }
        if ($row?->bank_account_id !== null) {
            $this->count('journals.linkKept');

            return;
        }
        if ($row && ! $row->auto_link) {
            $this->count('journals.unlinked', $label);   // a person removed its link: it stays for a person

            return;
        }
        [$bank, $by, $several] = $this->answering($values);
        if ($bank === null) {
            $this->count($several ? 'journals.ambiguous' : 'journals.unlinked', $label);

            return;
        }
        $holder = $this->held[(string) $bank->id] ?? null;
        if ($holder !== null && (string) $holder !== (string) $r->odoo_id) {
            $this->count('journals.ambiguous', "{$label}: {$bank->code} مربوط بدفتر آخر");

            return;
        }
        $this->held[(string) $bank->id] = $r->odoo_id;
        $this->count('journals.linked', "{$label} ↔ {$bank->code} ({$by})");
        if ($this->dry || ! $row) {
            return;
        }
        DB::transaction(function () use ($row, $bank, $by, $label, $r) {
            $this->journalLinks->attach($row, $bank, $by, $this->actor()->username);
            $this->audit->log($this->actor(), ['action' => 'BANK.JOURNAL.LINK', 'entityType' => 'BankSourceJournal', 'entityId' => $row->id, 'entityNumber' => $label,
                'newValue' => ['bank' => $bank->code, 'by' => $by, 'source' => "odoo #{$r->odoo_id}"]]);
        });
        $row->refresh();
        // the history the journal already has follows it to its bank account
        foreach ($this->statements as $statement) {
            if ($statement->source_journal_id === $row->id) {
                $statement->bank_account_id = $bank->id;
                $statement->syncOriginalAttribute('bank_account_id');
            }
        }
    }

    /**
     * The OPS bank account a journal is, by identity: the same account key (IBAN or number), an IBAN that ends with the
     * number, or exactly the same name.
     *
     * @return array{0:?BankAccount, 1:?string, 2:bool} the bank account, how it was recognised, whether several answered
     */
    private function answering(array $values): array
    {
        $this->banks ??= $this->installed || Schema::hasTable('fin_bank_accounts') ? BankAccount::orderBy('code')->get()->all() : [];
        $key = $values['account_key'];
        if ($key !== null) {
            $same = array_values(array_filter($this->banks, fn (BankAccount $b) => Mask::key($b->iban) === $key));
            if (count($same) === 1) {
                return [$same[0], Mask::isIban($key) ? 'iban' : 'number', false];
            }
            if ($same) {
                return [null, null, true];
            }
            // an IBAN carries the account number at its end: one side holds the IBAN, the other the bare number
            $digits = ltrim($key, '0');
            $tail = array_values(array_filter($this->banks, function (BankAccount $b) use ($key, $digits) {
                $iban = Mask::key($b->iban);
                if ($iban === null) {
                    return false;
                }
                $bare = ltrim($iban, '0');

                return (Mask::isIban($iban) && ! Mask::isIban($key) && ctype_digit($key) && strlen($digits) >= 8 && str_ends_with($iban, $digits))
                    || (Mask::isIban($key) && ! Mask::isIban($iban) && ctype_digit($iban) && strlen($bare) >= 8 && str_ends_with($key, $bare));
            }));
            if (count($tail) === 1) {
                return [$tail[0], 'number', false];
            }
            if ($tail) {
                return [null, null, true];
            }
        }
        $name = Cells::norm($values['name']);
        $named = $name === '' ? [] : array_values(array_filter($this->banks, fn (BankAccount $b) => Cells::norm($b->name_ar) === $name || Cells::norm($b->name_en) === $name));

        return count($named) === 1 ? [$named[0], 'name', false] : [null, null, count($named) > 1];
    }

    /** The OPS row of an Odoo journal; one the pass has not met yet is made from the copy on the spot. */
    private function journalOf(?int $odooId): ?SourceJournal
    {
        if (! $odooId) {
            return null;
        }
        if (! isset($this->journals[$odooId]) && ! isset($this->planned['journals'][(string) $odooId]) && ($r = $this->mirrored('account.journal', $odooId))) {
            $this->journal($r);
        }

        return $this->journals[$odooId] ?? null;
    }

    // ───────────── history: statements and their lines ─────────────

    private function statement(OdooRecord $r): ?Statement
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $row = $this->statements[$ref] ?? null;
        if (! $row && $r->deleted_at !== null) {
            $this->count('statements.skippedDeleted');

            return null;
        }
        $journalId = Val::id($d['journal_id'] ?? null);
        $journal = $this->journalOf($journalId);
        if (! $journal && ! isset($this->planned['journals'][(string) $journalId])) {
            $this->count('statements.skippedNoJournal', (string) ($r->display_name ?? "#{$ref}"));

            return null;
        }
        $values = [
            'name' => self::cut(Val::text($d['name'] ?? null) ?? Val::text($d['reference'] ?? null) ?? Val::text($r->display_name), 190),
            'opening_balance' => array_key_exists('balance_start', $d) ? Money::str(Money::cents(Val::float($d['balance_start']))) : null,
            'closing_balance' => array_key_exists('balance_end_real', $d) ? Money::str(Money::cents(Val::float($d['balance_end_real']))) : null,
            'source_journal_id' => $journal?->id, 'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $r->deleted_at,
        ];

        return $this->historyStatement($ref, $values, Val::date($d['date'] ?? null) ?? Val::date($d['create_date'] ?? null) ?? now()->toDateString(), $journal, "account.bank.statement #{$ref}");
    }

    /** Creates or refreshes a history statement. Its period only ever widens (by its lines), so a second pass changes nothing. */
    private function historyStatement(string $ref, array $values, string $day, ?SourceJournal $journal, string $origin): ?Statement
    {
        $row = $this->statements[$ref] ?? null;
        if (! $row) {
            if (isset($this->planned['statements'][$ref])) {
                return null;
            }
            $this->count('statements.created');
            if ($this->dry) {
                $this->planned['statements'][$ref] = true;

                return null;
            }
            $row = DB::transaction(function () use ($ref, $values, $day, $journal, $origin) {
                $row = Statement::create($values + ['number' => $this->numbering->next('BST'), 'bank_account_id' => $journal?->bank_account_id, 'date_from' => $day, 'date_to' => $day,
                    'currency' => $journal?->currency ?: 'SAR', 'status' => 'history', 'source_system' => self::SYSTEM, 'external_ref' => $ref, 'created_by' => $this->actor()->username]);
                $this->audit->log($this->actor(), ['action' => 'BANK.STATEMENT.CREATE', 'entityType' => 'BankStatement', 'entityId' => $row->id, 'entityNumber' => $row->number,
                    'newValue' => ['origin' => "from Odoo {$origin}", 'journal' => $journal?->code, 'status' => 'history']]);

                return $row;
            });

            return $this->statements[$ref] = $row;
        }
        $changes = self::changes($row, $values);
        $this->count($changes ? 'statements.updated' : 'statements.unchanged');
        if ($changes && ! $this->dry) {
            $row->update($changes);
        }

        return $row;
    }

    private function line(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $row = $this->lines !== null ? ($this->lines[$r->odoo_id] ?? null) : ($this->installed ? StatementLine::where('source_system', self::SYSTEM)->where('external_ref', $ref)->first() : null);
        if (! $row && $r->deleted_at !== null) {
            $this->count('lines.skippedDeleted');

            return;
        }
        // the journal entry of the line holds its day, its journal and whether it was cancelled
        $move = (array) ($this->mirrored('account.move', Val::id($d['move_id'] ?? null))?->data ?? []);
        $day = Val::date($move['date'] ?? null) ?? self::dayOfIndex($d['internal_index'] ?? null);
        if ($day === null) {
            $this->count('lines.skippedNoDate', Val::label($d['move_id'] ?? null) ?? "#{$ref}");

            return;
        }
        $statementId = Val::id($d['statement_id'] ?? null);
        $statementRecord = $statementId ? $this->mirrored('account.bank.statement', $statementId) : null;
        $journalId = Val::id($move['journal_id'] ?? null) ?? Val::id($statementRecord?->data['journal_id'] ?? null);
        $journal = $this->journalOf($journalId);
        $journalPlanned = isset($this->planned['journals'][(string) $journalId]);
        if (! $journal && ! $journalPlanned) {
            $this->count('lines.skippedNoJournal', Val::label($d['move_id'] ?? null) ?? "#{$ref}");

            return;
        }
        if ($statementId) {
            $statementRef = (string) $statementId;
            $statement = $this->statements[$statementRef] ?? ($statementRecord ? $this->statement($statementRecord) : null);
        } else {
            // Odoo lets a line stand in no statement: such lines are kept together, one history statement per journal
            $statementRef = "loose:{$journalId}";
            $title = 'حركات أودو بلا كشف — '.($journal?->name ?? Val::label($move['journal_id'] ?? null) ?? "#{$journalId}");
            $statement = $this->statements[$statementRef] ?? $this->historyStatement($statementRef, ['name' => self::cut($title, 190), 'source_journal_id' => $journal?->id], $day, $journal, "account.journal #{$journalId} (lines without a statement)");
        }
        if (! $statement && ! isset($this->planned['statements'][$statementRef])) {
            $this->count('lines.skippedNoStatement', Val::label($d['move_id'] ?? null) ?? "#{$ref}");

            return;
        }
        $cancelled = ($move['state'] ?? null) === 'cancel';
        $values = [
            'txn_date' => $day, 'amount' => Money::str(Money::cents(Val::float($d['amount'] ?? 0))), 'reference' => Val::text($move['ref'] ?? null, 120), 'description' => Val::text($d['payment_ref'] ?? null, 500),
            'partner_name' => Val::text($d['partner_name'] ?? null, 190) ?? self::cut(Val::label($d['partner_id'] ?? null), 190), 'partner_account' => Val::text($d['account_number'] ?? null, 64),
            'txn_type' => Val::text($d['transaction_type'] ?? null, 60), 'source_state' => $cancelled ? 'cancelled' : (Val::bool($d['is_reconciled'] ?? false) ? 'reconciled' : 'open'),
            'source_document' => self::cut(Val::label($d['move_id'] ?? null), 120),
            'extra' => array_filter(['posSession' => Val::label($d['pos_session_id'] ?? null), 'sequence' => Val::int($d['sequence'] ?? 0) ?: null, 'residual' => Val::float($d['amount_residual'] ?? 0) ?: null,
                'importId' => Val::text($d['unique_import_id'] ?? null, 120), 'onlineId' => Val::text($d['online_transaction_identifier'] ?? null, 120), 'entryState' => Val::text($move['state'] ?? null, 20)],
                fn ($v) => $v !== null && $v !== '') ?: null,
            'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $r->deleted_at,
        ];
        if (! $row) {
            $this->count('lines.created');
            $this->count('lines.'.$values['source_state']);
            if ($this->dry || ! $statement) {
                return;
            }
            $row = DB::transaction(function () use ($ref, $values, $statement, $day) {
                $this->lineNo[$statement->id] ??= (int) StatementLine::where('statement_id', $statement->id)->max('line_no');
                $row = StatementLine::create($values + ['statement_id' => $statement->id, 'bank_account_id' => $statement->bank_account_id, 'line_no' => ++$this->lineNo[$statement->id], 'kind' => StatementLine::KIND_LINE,
                    'source_system' => self::SYSTEM, 'external_ref' => $ref, 'created_by' => $this->actor()->username]);
                $this->widen($statement, $day);

                return $row;
            });
            if ($this->lines !== null) {
                $this->lines[(int) $ref] = $row;
            }

            return;
        }
        $changes = self::changes($row, $values);
        $this->count($changes ? (isset($changes['source_deleted_at']) && $values['source_deleted_at'] !== null ? 'lines.archived' : 'lines.updated') : 'lines.unchanged');
        if ($changes && ! $this->dry) {
            DB::transaction(function () use ($row, $changes, $statement, $day) {
                $row->update($changes);
                if ($statement) {
                    $this->widen($statement, $day);
                }
            });
        }
    }

    /** The period of a history statement covers the days of its lines. */
    private function widen(Statement $statement, string $day): void
    {
        $from = $statement->date_from->toDateString();
        $to = $statement->date_to->toDateString();
        if ($day < $from || $day > $to) {
            $statement->update(['date_from' => min($from, $day), 'date_to' => max($to, $day)]);
        }
    }

    /** Odoo's ordering key of a statement line starts with its day: YYYYMMDD. */
    private static function dayOfIndex(mixed $index): ?string
    {
        return is_string($index) && preg_match('/^(\d{4})(\d{2})(\d{2})/', $index, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    // ───────────── reconciliation models → write-off rules ─────────────

    private function rule(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $row = $this->rules[$r->odoo_id] ?? null;
        if (! $row && $r->deleted_at !== null) {
            $this->count('rules.skippedDeleted');

            return;
        }
        $lines = $this->childrenOf('account.reconcile.model.line', 'model_id', $r->odoo_id);
        $name = self::cut(Val::text($d['name'] ?? null) ?? Val::text($r->display_name) ?? "#{$r->odoo_id}", 190);
        $writeoff = in_array($d['rule_type'] ?? null, ['writeoff_button', 'writeoff_suggestion'], true);
        $journals = Val::ids($d['match_journal_ids'] ?? []);
        $onlyJournal = count($journals) === 1 ? $this->journalOf($journals[0]) : null;
        $values = [
            'name_ar' => $name, 'name_en' => $name, 'kind' => $writeoff ? 'writeoff' : 'matching',
            'nature' => match ($d['match_nature'] ?? null) {
                'amount_received' => 'in', 'amount_paid' => 'out', default => 'both'
            },
            'label' => self::cut(Val::text($lines[0]['label'] ?? null), 190),
            'match_text' => ($d['match_label'] ?? null) === 'contains' ? self::cut(Val::text($d['match_label_param'] ?? null), 250) : null,
            'bank_account_id' => $onlyJournal?->bank_account_id, 'sequence' => Val::int($d['sequence'] ?? 0), 'active' => $this->alive($r),
            'extra' => array_filter(array_intersect_key($d, array_flip(self::RULE_EXTRA)), fn ($v) => $v !== false && $v !== null && $v !== '' && $v !== 0 && $v !== 0.0)
                + ($journals ? ['journals' => $journals] : [])
                + ($lines ? ['lines' => array_map(fn (array $l) => array_filter(['account' => Val::label($l['account_id'] ?? null), 'label' => Val::text($l['label'] ?? null), 'amountType' => Val::text($l['amount_type'] ?? null, 40),
                    'amount' => Val::text($l['amount_string'] ?? null, 40) ?? (isset($l['amount']) ? (string) Val::float($l['amount']) : null)], fn ($v) => $v !== null), $lines)] : []) ?: null,
            'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $r->deleted_at,
        ];
        if ($r->deleted_at !== null) {
            $values = ['active' => false, 'source_deleted_at' => $r->deleted_at];
        }
        if (! $row) {
            // OPS already has a rule of exactly that name: the same thing, adopted — never a second one beside it
            $twin = $this->installed ? WriteoffRule::whereNull('source_system')->where(fn ($w) => $w->where('name_ar', $name)->orWhere('name_en', $name))->orderBy('created_at')->first() : null;
            $this->count($twin ? 'rules.linked' : 'rules.created', $twin ? $name : null);
            $this->count($writeoff ? 'rules.writeoff' : 'rules.matchingModel');
            if ($this->dry) {
                return;
            }
            // Odoo gives a limit only as a fixed tolerance; the OPS account and — otherwise — the limit are set here by a person
            $limit = ($d['payment_tolerance_type'] ?? null) === 'fixed_amount' && Val::float($d['payment_tolerance_param'] ?? 0) > 0 ? Money::str(Money::cents(Val::float($d['payment_tolerance_param']))) : null;
            $this->rules[$r->odoo_id] = DB::transaction(function () use ($r, $values, $twin, $limit, $name) {
                $origin = ['source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id];
                if ($twin) {
                    $twin->update($values + $origin);   // its account and its limit stay as OPS set them
                    $row = $twin;
                } else {
                    $row = WriteoffRule::create($values + $origin + ['max_amount' => $limit, 'created_by' => $this->actor()->username]);
                }
                $this->audit->log($this->actor(), ['action' => $twin ? 'BANK.RULE.LINK' : 'BANK.RULE.CREATE', 'entityType' => 'BankWriteoffRule', 'entityId' => $row->id, 'entityNumber' => $name,
                    'newValue' => ['origin' => "from Odoo account.reconcile.model #{$r->odoo_id}", 'kind' => $values['kind'], 'nature' => $values['nature'], 'active' => $values['active']]]);

                return $row;
            });

            return;
        }
        $changes = self::changes($row, $values);
        $this->count(! $changes ? 'rules.unchanged' : (($changes['active'] ?? null) === false ? 'rules.archived' : 'rules.updated'));
        if ($changes && ! $this->dry) {
            DB::transaction(function () use ($row, $changes) {
                $row->update($changes);
                $this->audit->log($this->actor(), ['action' => 'BANK.RULE.UPDATE', 'entityType' => 'BankWriteoffRule', 'entityId' => $row->id, 'entityNumber' => $row->name_ar,
                    'newValue' => ['changed' => array_keys($changes), 'origin' => 'Odoo']]);
            });
        }
    }

    // ───────────── payment terms ─────────────

    private function term(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $row = $this->terms[$r->odoo_id] ?? null;
        if (! $row && $r->deleted_at !== null) {
            $this->count('terms.skippedDeleted');

            return;
        }
        $schedule = array_map(fn (array $l) => ['value' => Val::text($l['value'] ?? null, 20) ?? 'percent', 'amount' => round(Val::float($l['value_amount'] ?? 0), 4), 'days' => Val::int($l['nb_days'] ?? 0),
            'delayType' => Val::text($l['delay_type'] ?? null, 40) ?? 'days_after'], $this->childrenOf('account.payment.term.line', 'payment_id', $r->odoo_id));
        $early = Val::bool($d['early_discount'] ?? false);
        $values = [
            'name' => self::cut(Val::text($d['name'] ?? null) ?? Val::text($r->display_name) ?? "#{$r->odoo_id}", 190), 'note' => Val::plain($d['note'] ?? null, 2000), 'sequence' => Val::int($d['sequence'] ?? 0),
            'early_discount' => $early, 'discount_percent' => $early ? number_format(Val::float($d['discount_percentage'] ?? 0), 2, '.', '') : null, 'discount_days' => $early ? Val::int($d['discount_days'] ?? 0) : null,
            'schedule' => $schedule, 'due_days' => $schedule ? max(array_column($schedule, 'days')) : null, 'active' => $this->alive($r),
            'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $r->deleted_at,
        ];
        if (! $row) {
            $this->count('terms.created');
            if (! $this->dry) {
                $this->terms[$r->odoo_id] = PaymentTerm::create($values + ['source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id, 'created_by' => $this->actor()->username]);
            }

            return;
        }
        $changes = self::changes($row, $values);
        $this->count(! $changes ? 'terms.unchanged' : (($changes['active'] ?? null) === false ? 'terms.archived' : 'terms.updated'));
        if ($changes && ! $this->dry) {
            $row->update($changes);
        }
    }

    // ───────────── helpers ─────────────

    /**
     * The children the copy holds for a parent (the lines of a model, the instalments of a term), in Odoo's order.
     * These are small dictionaries: all of them are read once per pass.
     *
     * @return array<int, array<string, mixed>>
     */
    private function childrenOf(string $model, string $parentField, int $parentId): array
    {
        if (! isset($this->children[$model])) {
            $this->children[$model] = [];
            foreach (OdooRecord::where('model', $model)->whereNull('deleted_at')->orderBy('odoo_id')->get() as $child) {
                $data = (array) $child->data;
                if ($id = Val::id($data[$parentField] ?? null)) {
                    $this->children[$model][$id][] = ['_sequence' => Val::int($data['sequence'] ?? 0), '_id' => $child->odoo_id] + $data;
                }
            }
            foreach ($this->children[$model] as $id => $rows) {
                usort($rows, fn (array $a, array $b) => [$a['_sequence'], $a['_id']] <=> [$b['_sequence'], $b['_id']]);
                $this->children[$model][$id] = $rows;
            }
        }

        return $this->children[$model][$parentId] ?? [];
    }

    private static function cut(?string $s, int $max): ?string
    {
        return $s === null ? null : mb_substr($s, 0, $max);
    }

    /**
     * The values that differ from what the row holds. A moment equals the same moment, a day its `YYYY-MM-DD`, 5 equals
     * "5.00"; a JSON column is compared by content.
     *
     * @return array<string, mixed>
     */
    private static function changes(Model $row, array $values): array
    {
        $out = [];
        foreach ($values as $column => $value) {
            $current = $row->getAttribute($column);
            if ($current instanceof DateTimeInterface || $value instanceof DateTimeInterface) {
                $format = is_string($value) && strlen($value) === 10 || (is_string($current) && strlen($current) === 10) ? 'Y-m-d' : 'Y-m-d H:i:s';
                if ($current instanceof DateTimeInterface && is_string($value) && strlen($value) === 10) {
                    $format = 'Y-m-d';
                }
                $same = ($current instanceof DateTimeInterface ? $current->format($format) : $current) === ($value instanceof DateTimeInterface ? $value->format($format) : $value);
            } elseif (is_bool($current) || is_bool($value)) {
                $same = $current !== null && $value !== null && (bool) $current === (bool) $value;
            } elseif (is_array($current) || is_array($value)) {
                $same = self::canonical((array) ($current ?: [])) == self::canonical((array) ($value ?: []));
            } elseif (is_numeric($current) && is_numeric($value)) {
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

    /** A JSON value with its keys in one order at every depth (the database gives them back in its own). */
    private static function canonical(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::canonical($v);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
