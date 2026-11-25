<?php

namespace App\Banking\Services;

use App\Banking\Models\Statement;
use App\Banking\Models\StatementLine;
use App\Banking\Support\Fingerprint;
use App\Banking\Support\Mask;
use App\Banking\Support\Money;
use App\Banking\Support\Owned;
use App\Banking\Support\Shape;
use App\Finance\Models\BankAccount;
use App\Models\StatusHistory;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Bank statements: a statement of one OPS bank account for a period, its lines (typed here or imported by
 * ImportService), the check that the bank's own figures add up (opening + lines = closing), and the lock.
 *
 * STATES. open → reconciled → open (reopened with a reason) · open → void. `history` is what the source system held
 * and never moves. A reconciled statement is immutable: no line of it is added, changed, removed, matched or
 * unmatched until a holder of `bank.reconcile` reopens it. Statements of one bank account are locked in the order of
 * their dates and reopened from the newest back, like the accounting periods.
 *
 * THE OPENING BALANCE of the first statement of a bank account is kept as a line of its own (`kind = opening`, dated
 * the day before the statement starts): it too must be matched with the ledger entries that make it up before that
 * statement can be locked. Later statements need no such line — their opening is the previous closing.
 *
 * Every figure is compared in hundredths (`Money`); payloads are camelCase and already validated.
 */
class StatementsService
{
    public const LINE_STATES = ['all', 'matched', 'unmatched'];

    public function __construct(
        private readonly Banks $banks,
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
    ) {}

    // ───────────────────────────── reads ─────────────────────────────

    /** @param  array{bank?:?string, status?:?string, origin?:?string, from?:?string, to?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $q = Statement::query()->with(['bankAccount:id,code,name_ar,name_en,kind', 'sourceJournal:id,code,name,kind']);
        if (! empty($f['bank'])) {
            $q->where('bank_account_id', $this->banks->find($f['bank'])->id);
        }
        if (! empty($f['journal'])) {
            $q->where('source_journal_id', $f['journal']);
        }
        if (! empty($f['status'])) {
            $q->whereIn('status', array_intersect(array_map('trim', explode(',', $f['status'])), Statement::STATUSES) ?: ['-']);
        } else {
            $q->where('status', '!=', 'void');   // a voided statement is found by asking for it
        }
        if (($f['origin'] ?? null) === 'ops') {
            $q->whereNull('source_system');
        } elseif (! empty($f['origin'])) {
            $q->where('source_system', $f['origin']);
        }
        // the statements whose period touches the range
        if (! empty($f['from'])) {
            $q->where('date_to', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->where('date_from', '<=', $f['to']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('name', 'like', $like)->orWhere('notes', 'like', $like)->orWhere('external_ref', $page->q));
        }
        $column = match ($page->sort) {
            'number' => 'number', 'closing' => 'closing_balance', 'status' => 'status', 'created' => 'created_at', 'dateFrom' => 'date_from', default => 'date_to'
        };
        $q->orderBy($column, $page->order)->orderBy('id', $page->order);
        $total = (clone $q)->toBase()->getCountForPagination();
        $rows = $q->forPage($page->page, $page->pageSize)->get();
        $totals = $this->totalsFor($rows->pluck('id')->all());

        return $page->wrap($rows->map(fn (Statement $s) => Shape::statement($s, $totals[$s->id] ?? self::noTotals()))->all(), $total);
    }

    public function get(AuthUser $user, string $idOrNumber): array
    {
        return $this->detail($user, $this->find($idOrNumber));
    }

    /** One statement with everything its screen needs: totals, what it may do, why it cannot be locked yet, its imports and its history. */
    public function detail(AuthUser $user, Statement $s): array
    {
        $s->loadMissing(['bankAccount.account:id,code,name_ar,name_en', 'sourceJournal:id,code,name,kind']);
        $totals = $this->totals($s);
        $blockers = $s->status === 'open' ? $this->blockers($s, $totals) : [];
        $owned = Owned::bySource($s);
        $open = $s->status === 'open' && ! $owned;

        return Shape::statement($s, $totals) + [
            'bankAccount' => Shape::bank($s->bankAccount, Mask::sees($user)),
            'continuity' => $s->isLive() ? $this->continuity($s) : null,
            'blockers' => $blockers,
            'can' => [
                'edit' => $open, 'lines' => $open, 'import' => $open, 'match' => $open,
                'void' => $open && $totals['matched'] === 0, 'reconcile' => $open && ! $blockers, 'reopen' => $s->status === 'reconciled' && ! $owned && $this->laterReconciled($s) === null,
                'note' => in_array($s->status, ['open', 'history'], true),
            ],
            'imports' => $s->imports()->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get()->map(fn ($i) => Shape::import($i))->all(),
            'timeline' => StatusHistory::where('entity_type', 'BankStatement')->where('entity_id', $s->id)->orderByDesc('at')->orderByDesc('id')->limit(50)->get()
                ->map(fn ($h) => ['from' => $h->from_status, 'to' => $h->to_status, 'by' => $h->username, 'at' => Shape::at($h->at), 'note' => $h->note])->all(),
        ];
    }

    /**
     * Statement lines: of one statement (`statement`), or of every live statement of a bank account (`bank`) — the
     * work list of the reconciliation.
     *
     * @param  array{statement?:?string, bank?:?string, state?:?string, sign?:?string, from?:?string, to?:?string, amount?:?string}  $f
     */
    public function lines(AuthUser $user, Paging $page, array $f): array
    {
        $full = Mask::sees($user);
        $q = $this->linesQuery($f, $page->q, $full);
        $dir = $page->order;
        match ($page->sort) {
            'amount' => $q->orderBy('bank_statement_lines.amount', $dir),
            'date' => $q->orderBy('bank_statement_lines.txn_date', $dir),
            // the order of the statement: the opening balance first, then the lines as the bank lists them
            default => empty($f['statement']) ? $q->orderBy('bank_statement_lines.txn_date', $dir) : $q,
        };
        $q->orderBy('bank_statement_lines.statement_id', $dir)->orderBy('bank_statement_lines.line_no', $dir)->orderBy('bank_statement_lines.id', $dir);
        $total = (clone $q)->toBase()->getCountForPagination();
        $rows = $q->with(['match:id,number,kind,cleared_date', 'statement:id,number'])->forPage($page->page, $page->pageSize)->get();
        $sums = $this->linesQuery($f, $page->q, $full)->toBase()->selectRaw('coalesce(sum(case when amount > 0 then amount else 0 end), 0) as money_in, coalesce(sum(case when amount < 0 then amount else 0 end), 0) as money_out')->first();
        $in = Money::cents((string) $sums->money_in);
        $out = Money::cents((string) $sums->money_out);

        return $page->wrap($rows->map(fn (StatementLine $l) => Shape::line($l, $full))->all(), $total) + ['totals' => ['in' => Money::str($in), 'out' => Money::str($out), 'net' => Money::str($in + $out)]];
    }

    private function linesQuery(array $f, ?string $search, bool $full): Builder
    {
        $q = StatementLine::query();
        if (! empty($f['statement'])) {
            $q->where('bank_statement_lines.statement_id', $this->find($f['statement'])->id);
        } else {
            $bank = $this->banks->find((string) ($f['bank'] ?? ''));
            $q->where('bank_statement_lines.bank_account_id', $bank->id)
                ->whereIn('bank_statement_lines.statement_id', Statement::where('bank_account_id', $bank->id)->whereIn('status', Statement::LIVE)->select('id'));
        }
        match ($f['state'] ?? 'all') {
            'matched' => $q->whereNotNull('bank_statement_lines.match_id'), 'unmatched' => $q->whereNull('bank_statement_lines.match_id'), default => null,
        };
        if (($f['sign'] ?? null) === 'in') {
            $q->where('bank_statement_lines.amount', '>', 0);
        } elseif (($f['sign'] ?? null) === 'out') {
            $q->where('bank_statement_lines.amount', '<', 0);
        }
        if (! empty($f['from'])) {
            $q->where('bank_statement_lines.txn_date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->where('bank_statement_lines.txn_date', '<=', $f['to']);
        }
        if (isset($f['amount']) && $f['amount'] !== '' && Money::valid($f['amount'])) {
            $cents = Money::cents($f['amount']);
            $q->whereIn('bank_statement_lines.amount', array_unique([Money::str($cents), Money::str(-$cents)]));
        }
        if ($search !== null && $search !== '') {
            $like = "%{$search}%";
            // an account number is searched only by a reader who may see one: a masked reader must not find it digit by digit
            $q->where(fn ($w) => $w->where('bank_statement_lines.reference', 'like', $like)->orWhere('bank_statement_lines.description', 'like', $like)->orWhere('bank_statement_lines.partner_name', 'like', $like)
                ->orWhere('bank_statement_lines.source_document', 'like', $like)->when($full, fn ($x) => $x->orWhere('bank_statement_lines.partner_account', 'like', $like)));
        }

        return $q;
    }

    public function line(AuthUser $user, string $lineId): array
    {
        $l = StatementLine::with(['match:id,number,kind,cleared_date', 'statement:id,number'])->whereKey($lineId)->first() ?? throw self::lineNotFound();

        return Shape::line($l, Mask::sees($user));
    }

    // ───────────────────────────── statements ─────────────────────────────

    /** @param  array{bankAccount:string, name?:?string, dateFrom:string, dateTo:string, openingBalance:string|int|float, closingBalance:string|int|float, notes?:?string}  $dto */
    public function create(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $bank = $this->banks->find($dto['bankAccount']);
            [$from, $to] = self::period($dto['dateFrom'], $dto['dateTo']);
            $this->lockBank($bank->id);
            $this->refuseOverlap($bank->id, $from, $to, null);
            $s = Statement::create([
                'number' => $this->numbering->next('BST'), 'bank_account_id' => $bank->id, 'name' => self::text($dto['name'] ?? null, 190), 'date_from' => $from, 'date_to' => $to,
                'opening_balance' => Money::str(Money::cents($dto['openingBalance'])), 'closing_balance' => Money::str(Money::cents($dto['closingBalance'])), 'currency' => $bank->currency ?: 'SAR',
                'status' => 'open', 'notes' => self::text($dto['notes'] ?? null, 2000), 'created_by' => $user->username,
            ]);
            $this->syncOpening($bank->id, $user);
            $this->audit->log($user, ['action' => 'BANK.STATEMENT.CREATE', 'entityType' => 'BankStatement', 'entityId' => $s->id, 'entityNumber' => $s->number,
                'newValue' => ['bank' => $bank->code, 'from' => $from, 'to' => $to, 'opening' => (string) $s->opening_balance, 'closing' => (string) $s->closing_balance]]);

            return $this->detail($user, $s->refresh());
        });
    }

    /** The period, the two balances, the title and the note of an OPEN statement. A note alone may also be put on a history statement. */
    public function update(AuthUser $user, string $idOrNumber, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $dto) {
            $s = $this->find($idOrNumber, lock: true);
            $data = [];
            if (array_key_exists('notes', $dto)) {
                $data['notes'] = self::text($dto['notes'], 2000);
            }
            $noteOnly = ! array_diff(array_keys($dto), ['notes']);
            if ($noteOnly && $s->status === 'history') {
                // a note is OPS's own addition to what the source system holds
            } else {
                $this->assertEditable($s);
            }
            if (array_key_exists('name', $dto)) {
                $data['name'] = self::text($dto['name'], 190);
            }
            foreach (['openingBalance' => 'opening_balance', 'closingBalance' => 'closing_balance'] as $key => $column) {
                if (isset($dto[$key])) {
                    $data[$column] = Money::str(Money::cents($dto[$key]));
                }
            }
            if (isset($dto['dateFrom']) || isset($dto['dateTo'])) {
                [$from, $to] = self::period($dto['dateFrom'] ?? $s->date_from->toDateString(), $dto['dateTo'] ?? $s->date_to->toDateString());
                $this->lockBank($s->bank_account_id);
                $this->refuseOverlap($s->bank_account_id, $from, $to, $s->id);
                $outside = StatementLine::where('statement_id', $s->id)->where('kind', StatementLine::KIND_LINE)->where(fn ($w) => $w->where('txn_date', '<', $from)->orWhere('txn_date', '>', $to))->count();
                if ($outside > 0) {
                    throw AppError::rule('BANK_LINE_DATE_OUTSIDE', "في الكشف {$outside} سطر خارج الفترة الجديدة — عدّل تواريخها أو وسّع الفترة", "{$outside} line(s) of the statement fall outside the new period", ['lines' => $outside]);
                }
                $data['date_from'] = $from;
                $data['date_to'] = $to;
            }
            $changes = [];
            foreach ($data as $column => $value) {
                $current = $s->getAttribute($column);
                $current = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : ($current === null ? null : (string) $current);
                if ($current !== ($value === null ? null : (string) $value)) {
                    $changes[$column] = [$current, $value];
                }
            }
            if (! $changes) {
                return $this->detail($user, $s);
            }
            $s->update(array_map(fn (array $c) => $c[1], $changes) + ['updated_by' => $user->username]);
            if (isset($changes['opening_balance']) || isset($changes['date_from'])) {
                $this->syncOpening($s->bank_account_id, $user);
            }
            // the audit holds the figures and the dates; a note is free text and only its change is recorded
            $told = array_diff_key($changes, ['notes' => 1]);
            $this->audit->log($user, ['action' => 'BANK.STATEMENT.UPDATE', 'entityType' => 'BankStatement', 'entityId' => $s->id, 'entityNumber' => $s->number,
                'oldValue' => array_map(fn (array $c) => $c[0], $told), 'newValue' => array_map(fn (array $c) => $c[1], $told) + (isset($changes['notes']) ? ['notesChanged' => true] : [])]);

            return $this->detail($user, $s->refresh());
        });
    }

    /** A statement entered by mistake: voided with a reason, kept and shown. Its lines may then be imported again. */
    public function void(AuthUser $user, string $idOrNumber, string $reason): array
    {
        $reason = self::reason($reason, 'اكتب سبب إلغاء الكشف (5 أحرف على الأقل)');

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $s = $this->find($idOrNumber, lock: true);
            Owned::refuse($s, 'statement');
            $this->transition($s, 'void');
            $matched = StatementLine::where('statement_id', $s->id)->whereNotNull('match_id')->count();
            if ($matched > 0) {
                throw AppError::rule('BANK_STATEMENT_HAS_MATCHES', "في الكشف {$matched} سطر مطابَق — فكّ مطابقاته قبل إلغاء الكشف", "{$matched} line(s) of the statement are matched — undo the matches first", ['matched' => $matched]);
            }
            $s->update(['status' => 'void', 'voided_at' => now(), 'voided_by' => $user->username, 'void_reason' => $reason, 'updated_by' => $user->username]);
            // the lines stay as the record of what was entered; their fingerprints are released so the right file can be imported
            StatementLine::where('statement_id', $s->id)->update(['fingerprint' => null]);
            $this->syncOpening($s->bank_account_id, $user);
            $this->audit->status($user, 'BankStatement', $s->id, $s->number, 'open', 'void', $reason);

            return $this->detail($user, $s->refresh());
        });
    }

    /**
     * Locks the statement: the bank's figures add up, every line of it (and the opening balance of a first statement)
     * is matched, it continues the statement before it, and that one is locked already.
     */
    public function reconcile(AuthUser $user, string $idOrNumber): array
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $s = $this->find($idOrNumber, lock: true);
            Owned::refuse($s, 'statement');
            $this->transition($s, 'reconciled');
            $blockers = $this->blockers($s, $this->totals($s));
            if ($blockers) {
                throw AppError::rule($blockers[0]['code'], $blockers[0]['ar'], $blockers[0]['en'], ['blockers' => $blockers]);
            }
            $s->update(['status' => 'reconciled', 'reconciled_at' => now(), 'reconciled_by' => $user->username, 'updated_by' => $user->username]);
            $this->audit->status($user, 'BankStatement', $s->id, $s->number, 'open', 'reconciled');
            $bank = $s->bankAccount;
            $this->notify->activity($user, 'BankStatement', $s->id, $s->number, "اعتُمدت مطابقة كشف {$s->number} — {$bank?->name_ar} حتى {$s->date_to->toDateString()}", "Bank statement {$s->number} reconciled — {$bank?->name_en} to {$s->date_to->toDateString()}", ['finance']);

            return $this->detail($user, $s->refresh());
        });
    }

    /** Opens a locked statement again — with a reason, and never while a later statement of the bank is still locked. */
    public function reopen(AuthUser $user, string $idOrNumber, string $reason): array
    {
        $reason = self::reason($reason, 'اكتب سبب إعادة فتح الكشف (5 أحرف على الأقل)');

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $s = $this->find($idOrNumber, lock: true);
            Owned::refuse($s, 'statement');
            $this->transition($s, 'open');
            if ($later = $this->laterReconciled($s)) {
                throw AppError::rule('BANK_STATEMENT_ORDER', "أعد فتح الكشف {$later->number} أولًا — إعادة الفتح تكون من الأحدث", "Reopen {$later->number} first — statements are reopened from the newest back", ['statement' => $later->number]);
            }
            $s->update(['status' => 'open', 'reopened_at' => now(), 'reopened_by' => $user->username, 'reopen_reason' => $reason, 'updated_by' => $user->username]);
            $this->audit->status($user, 'BankStatement', $s->id, $s->number, 'reconciled', 'open', $reason);
            $bank = $s->bankAccount;
            $this->notify->activity($user, 'BankStatement', $s->id, $s->number, "أُعيد فتح كشف {$s->number} — {$bank?->name_ar}: {$reason}", "Bank statement {$s->number} reopened — {$bank?->name_en}", ['finance']);

            return $this->detail($user, $s->refresh());
        });
    }

    // ───────────────────────────── typed lines ─────────────────────────────

    /**
     * @param  array<int, array{date:string, amount:string|int|float, description?:?string, reference?:?string, valueDate?:?string, partnerName?:?string, partnerAccount?:?string, type?:?string, balanceAfter?:string|int|float|null}>  $lines
     */
    public function addLines(AuthUser $user, string $idOrNumber, array $lines): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $lines) {
            $s = $this->find($idOrNumber, lock: true);
            $this->assertEditable($s);
            $no = (int) StatementLine::where('statement_id', $s->id)->max('line_no');
            $total = 0;
            $made = [];
            foreach (array_values($lines) as $i => $dto) {
                $values = $this->lineValues($s, $dto, "lines.{$i}");
                $made[] = StatementLine::create($values + [
                    'statement_id' => $s->id, 'bank_account_id' => $s->bank_account_id, 'line_no' => ++$no, 'kind' => StatementLine::KIND_LINE, 'created_by' => $user->username,
                    'fingerprint' => Fingerprint::nextFree($s->bank_account_id, $values['txn_date'], Money::cents($values['amount']), $values['reference'], $values['description']),
                ]);
                $total += Money::cents($values['amount']);
            }
            $this->audit->log($user, ['action' => 'BANK.LINE.ADD', 'entityType' => 'BankStatement', 'entityId' => $s->id, 'entityNumber' => $s->number,
                'newValue' => ['lines' => count($made), 'total' => Money::str($total), 'from' => $made[0]->line_no, 'to' => $no]]);
            $full = Mask::sees($user);

            return ['lines' => array_map(fn (StatementLine $l) => Shape::line($l->refresh(), $full), $made), 'statement' => $this->detail($user, $s)];
        });
    }

    public function updateLine(AuthUser $user, string $lineId, array $dto): array
    {
        return DB::transaction(function () use ($user, $lineId, $dto) {
            [$s, $l] = $this->lockLine($lineId);
            $current = ['date' => $l->txn_date->toDateString(), 'amount' => (string) $l->amount, 'description' => $l->description, 'reference' => $l->reference, 'valueDate' => $l->value_date?->toDateString(),
                'partnerName' => $l->partner_name, 'partnerAccount' => $l->partner_account, 'type' => $l->txn_type, 'balanceAfter' => $l->balance_after === null ? null : (string) $l->balance_after];
            $values = $this->lineValues($s, $dto + $current, 'line');
            $changes = [];
            foreach ($values as $column => $value) {
                $before = $l->getAttribute($column);
                $before = $before instanceof \DateTimeInterface ? $before->format('Y-m-d') : ($before === null ? null : (string) $before);
                if ($before !== ($value === null ? null : (string) $value)) {
                    $changes[$column] = [$before, $value];
                }
            }
            if (! $changes) {
                return Shape::line($l->load(['statement:id,number']), Mask::sees($user));
            }
            $update = array_map(fn (array $c) => $c[1], $changes);
            if (array_intersect(array_keys($changes), ['txn_date', 'amount', 'reference', 'description'])) {
                $update['fingerprint'] = Fingerprint::nextFree($s->bank_account_id, $values['txn_date'], Money::cents($values['amount']), $values['reference'], $values['description'], $l->id);
            }
            $l->update($update + ['updated_by' => $user->username]);
            // figures, dates and the bank's reference are audited; free text and account numbers are not copied into the trail
            $told = array_intersect_key($changes, ['txn_date' => 1, 'value_date' => 1, 'amount' => 1, 'reference' => 1, 'balance_after' => 1, 'txn_type' => 1]);
            $this->audit->log($user, ['action' => 'BANK.LINE.UPDATE', 'entityType' => 'BankStatementLine', 'entityId' => $l->id, 'entityNumber' => "{$s->number}#{$l->line_no}",
                'oldValue' => array_map(fn (array $c) => $c[0], $told), 'newValue' => array_map(fn (array $c) => $c[1], $told) + ['changed' => array_keys($changes)]]);

            return Shape::line($l->refresh()->load(['statement:id,number']), Mask::sees($user));
        });
    }

    /** Removes a line typed or imported by mistake from an OPEN statement (never a matched one). What it held stays on the audit trail. */
    public function removeLine(AuthUser $user, string $lineId): array
    {
        return DB::transaction(function () use ($user, $lineId) {
            [$s, $l] = $this->lockLine($lineId);
            $this->audit->log($user, ['action' => 'BANK.LINE.REMOVE', 'entityType' => 'BankStatementLine', 'entityId' => $l->id, 'entityNumber' => "{$s->number}#{$l->line_no}",
                'oldValue' => ['date' => $l->txn_date->toDateString(), 'amount' => (string) $l->amount, 'reference' => $l->reference, 'imported' => $l->import_id !== null]]);
            $l->delete();

            return ['ok' => true, 'messageAr' => "حُذف السطر {$l->line_no} من الكشف {$s->number}", 'messageEn' => "Line {$l->line_no} removed from {$s->number}", 'statement' => $this->detail($user, $s)];
        });
    }

    /** The statement of a line, locked, and the line, locked — for a change of the line. @return array{0:Statement, 1:StatementLine} */
    private function lockLine(string $lineId): array
    {
        $statementId = StatementLine::whereKey($lineId)->value('statement_id') ?? throw self::lineNotFound();
        $s = $this->find((string) $statementId, lock: true);
        $l = StatementLine::whereKey($lineId)->lockForUpdate()->first() ?? throw self::lineNotFound();
        Owned::refuse($l, 'line');
        $this->assertEditable($s);
        if ($l->kind === StatementLine::KIND_OPENING) {
            throw AppError::rule('BANK_LINE_OPENING', 'هذا سطر الرصيد الافتتاحي — يتغيّر بتعديل الرصيد الافتتاحي للكشف', 'This is the opening balance line — change the opening balance of the statement instead');
        }
        if ($l->match_id !== null) {
            throw AppError::rule('BANK_LINE_MATCHED', 'السطر مطابَق — فكّ المطابقة قبل تعديله أو حذفه', 'The line is matched — undo the match before changing or removing it', ['match' => $l->match_id]);
        }

        return [$s, $l];
    }

    /** The columns of a typed line from its payload, checked against the statement. */
    private function lineValues(Statement $s, array $dto, string $path): array
    {
        $date = Carbon::parse((string) $dto['date'])->toDateString();
        if ($date < $s->date_from->toDateString() || $date > $s->date_to->toDateString()) {
            throw AppError::rule('BANK_LINE_DATE_OUTSIDE', "تاريخ السطر {$date} خارج فترة الكشف ({$s->date_from->toDateString()} — {$s->date_to->toDateString()})", "The line's date {$date} is outside the statement period",
                ['path' => "{$path}.date", 'date' => $date]);
        }
        $cents = Money::cents($dto['amount']);
        if ($cents === 0) {
            throw AppError::validation('BANK_LINE_AMOUNT', 'مبلغ السطر لا يكون صفرًا: موجب للوارد وسالب للصادر', 'A line amount cannot be zero: positive for money in, negative for money out', [['path' => "{$path}.amount", 'message' => 'المبلغ لا يكون صفرًا']]);
        }

        return [
            'txn_date' => $date, 'value_date' => ! empty($dto['valueDate']) ? Carbon::parse((string) $dto['valueDate'])->toDateString() : null, 'amount' => Money::str($cents),
            'balance_after' => isset($dto['balanceAfter']) && $dto['balanceAfter'] !== '' ? Money::str(Money::cents($dto['balanceAfter'])) : null,
            'reference' => self::text($dto['reference'] ?? null, 120), 'description' => self::text($dto['description'] ?? null, 500), 'partner_name' => self::text($dto['partnerName'] ?? null, 190),
            'partner_account' => self::text($dto['partnerAccount'] ?? null, 64), 'txn_type' => self::text($dto['type'] ?? null, 60),
        ];
    }

    // ───────────────────────────── rules shared with the import and the matching ─────────────────────────────

    /** By id or number; `lock` takes the row for the rest of the transaction (every change of a statement starts here). */
    public function find(string $idOrNumber, bool $lock = false): Statement
    {
        $q = Statement::query()->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber));

        return ($lock ? $q->lockForUpdate() : $q)->first() ?? throw AppError::notFound('BANK_STATEMENT_NOT_FOUND', "الكشف {$idOrNumber} غير موجود", "Statement {$idOrNumber} not found");
    }

    /** Refuses a change of a statement that is Odoo's, history, locked or void — each with its own code. */
    public function assertEditable(Statement $s): void
    {
        Owned::refuse($s, 'statement');
        match ($s->status) {
            'history' => throw AppError::rule('BANK_STATEMENT_HISTORY', "الكشف {$s->number} سجل تاريخي من النظام السابق — يُقرأ ولا يُعدَّل", "Statement {$s->number} is history from the previous system — it is read, not changed"),
            'reconciled' => throw AppError::rule('BANK_STATEMENT_LOCKED', "الكشف {$s->number} مطابَق ومقفل — يُعاد فتحه بصلاحية المطابقة مع ذكر السبب قبل أي تعديل", "Statement {$s->number} is reconciled and locked — reopen it (bank.reconcile, with a reason) before any change", ['statement' => $s->number]),
            'void' => throw AppError::rule('BANK_STATEMENT_VOID', "الكشف {$s->number} ملغى", "Statement {$s->number} is void"),
            default => null,
        };
    }

    private function transition(Statement $s, string $to): void
    {
        if (! in_array($to, Statement::TRANSITIONS[$s->status] ?? [], true)) {
            throw AppError::rule('BANK_STATEMENT_STATE', "انتقال غير مسموح للكشف {$s->number}: {$s->status} ← {$to}", "Invalid transition for statement {$s->number}: {$s->status} -> {$to}", ['from' => $s->status, 'to' => $to]);
        }
    }

    /**
     * The figures of one statement, in hundredths.
     *
     * @return array{lines:int, in:int, out:int, net:int, matched:int, all:int, hasOpening:bool, openingMatched:bool}
     */
    public function totals(Statement $s): array
    {
        return $this->totalsFor([$s->id])[$s->id] ?? self::noTotals();
    }

    /** @return array<string, array{lines:int, in:int, out:int, net:int, matched:int, all:int, hasOpening:bool, openingMatched:bool}> by statement id */
    public function totalsFor(array $statementIds): array
    {
        if (! $statementIds) {
            return [];
        }
        // a line the source system cancelled is kept as history and counts for nothing
        $live = "kind = 'line' and (source_state is null or source_state <> 'cancelled')";
        $rows = StatementLine::query()->toBase()->whereIn('statement_id', $statementIds)->groupBy('statement_id')->selectRaw("statement_id,
            sum(case when {$live} then 1 else 0 end) as n_lines,
            coalesce(sum(case when {$live} and amount > 0 then amount else 0 end), 0) as money_in,
            coalesce(sum(case when {$live} and amount < 0 then amount else 0 end), 0) as money_out,
            sum(case when match_id is not null then 1 else 0 end) as n_matched,
            sum(case when kind = 'opening' then 1 else 0 end) as n_opening,
            sum(case when kind = 'opening' and match_id is not null then 1 else 0 end) as n_opening_matched,
            count(*) as n_all")->get();
        $out = [];
        foreach ($rows as $r) {
            $in = Money::cents((string) $r->money_in);
            $o = Money::cents((string) $r->money_out);
            $out[$r->statement_id] = ['lines' => (int) $r->n_lines, 'in' => $in, 'out' => $o, 'net' => $in + $o, 'matched' => (int) $r->n_matched, 'all' => (int) $r->n_all,
                'hasOpening' => (int) $r->n_opening > 0, 'openingMatched' => (int) $r->n_opening_matched > 0];
        }

        return $out;
    }

    private static function noTotals(): array
    {
        return ['lines' => 0, 'in' => 0, 'out' => 0, 'net' => 0, 'matched' => 0, 'all' => 0, 'hasOpening' => false, 'openingMatched' => false];
    }

    /**
     * Why an open statement cannot be locked yet — every reason, each with its code, so the screen never leaves a
     * person at a dead end.
     *
     * @return array<int, array{code:string, ar:string, en:string, details?:array}>
     */
    public function blockers(Statement $s, array $totals): array
    {
        $out = [];
        $opening = Money::cents((string) $s->opening_balance);
        $closing = Money::cents((string) $s->closing_balance);
        $difference = $closing - ($opening + $totals['net']);
        if ($difference !== 0) {
            $out[] = ['code' => 'BANK_STATEMENT_UNBALANCED', 'ar' => 'الكشف غير متوازن: الرصيد الافتتاحي '.Money::human($opening).' + الحركات '.Money::human($totals['net']).' = '.Money::human($opening + $totals['net']).' ≠ الرصيد الختامي '.Money::human($closing).' (الفرق '.Money::human($difference).')',
                'en' => 'The statement does not add up: opening '.Money::human($opening).' + lines '.Money::human($totals['net']).' ≠ closing '.Money::human($closing).' (difference '.Money::human($difference).')',
                'details' => ['opening' => Money::str($opening), 'lines' => Money::str($totals['net']), 'closing' => Money::str($closing), 'difference' => Money::str($difference)]];
        }
        $unmatched = $totals['all'] - $totals['matched'];
        if ($unmatched > 0) {
            $out[] = ['code' => 'BANK_STATEMENT_UNMATCHED', 'ar' => "في الكشف {$unmatched} سطر غير مطابَق".($totals['hasOpening'] && ! $totals['openingMatched'] ? ' (منها الرصيد الافتتاحي)' : ''),
                'en' => "{$unmatched} line(s) of the statement are not matched".($totals['hasOpening'] && ! $totals['openingMatched'] ? ' (the opening balance among them)' : ''), 'details' => ['unmatched' => $unmatched]];
        }
        $c = $this->continuity($s);
        if ($c['previous'] !== null && ! $c['ok']) {
            $out[] = ['code' => 'BANK_STATEMENT_GAP', 'ar' => "الرصيد الافتتاحي ({$c['opening']}) لا يساوي الرصيد الختامي للكشف السابق {$c['previous']['number']} ({$c['previous']['closingBalance']}) — كشف ناقص بينهما أو رصيد خاطئ (الفرق {$c['gap']})",
                'en' => "The opening balance ({$c['opening']}) is not the closing balance of the previous statement {$c['previous']['number']} ({$c['previous']['closingBalance']}) — a statement is missing in between or a balance is wrong (gap {$c['gap']})",
                'details' => ['previous' => $c['previous']['number'], 'gap' => $c['gap']]];
        }
        if ($c['previous'] !== null && $c['previous']['status'] !== 'reconciled') {
            $out[] = ['code' => 'BANK_STATEMENT_ORDER', 'ar' => "اعتمد مطابقة الكشف السابق {$c['previous']['number']} أولًا — الإقفال يكون بترتيب التواريخ", 'en' => "Reconcile the previous statement {$c['previous']['number']} first — statements are locked in date order",
                'details' => ['previous' => $c['previous']['number']]];
        }

        return $out;
    }

    /**
     * How the statement continues the one before it (the live statement of the same bank that ends before it starts).
     *
     * @return array{previous:?array{id:string, number:string, dateTo:string, closingBalance:string, status:string}, opening:string, gap:string, ok:bool}
     */
    public function continuity(Statement $s): array
    {
        $previous = Statement::where('bank_account_id', $s->bank_account_id)->whereIn('status', Statement::LIVE)->where('id', '!=', $s->id)->where('date_to', '<', $s->date_from->toDateString())
            ->orderByDesc('date_to')->orderByDesc('id')->first();
        $opening = Money::cents((string) $s->opening_balance);
        $gap = $previous ? $opening - Money::cents((string) $previous->closing_balance) : 0;

        return [
            'previous' => $previous ? ['id' => $previous->id, 'number' => $previous->number, 'dateTo' => $previous->date_to->toDateString(), 'closingBalance' => Money::out($previous->closing_balance), 'status' => $previous->status] : null,
            'opening' => Money::str($opening), 'gap' => Money::str($gap), 'ok' => $gap === 0,
        ];
    }

    private function laterReconciled(Statement $s): ?Statement
    {
        return Statement::where('bank_account_id', $s->bank_account_id)->where('status', 'reconciled')->where('id', '!=', $s->id)->where('date_from', '>', $s->date_to->toDateString())->orderByDesc('date_from')->first();
    }

    /**
     * One change of the SET of statements of a bank account at a time (a new one, a change of period): the bank
     * account's row is taken for the transaction — read under a lock, never written — so two people cannot create
     * overlapping statements at the same moment.
     */
    private function lockBank(?string $bankAccountId): void
    {
        BankAccount::whereKey($bankAccountId)->lockForUpdate()->first(['id']);
    }

    private function refuseOverlap(string $bankAccountId, string $from, string $to, ?string $exceptId): void
    {
        $other = Statement::where('bank_account_id', $bankAccountId)->whereIn('status', Statement::LIVE)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where('date_from', '<=', $to)->where('date_to', '>=', $from)->orderBy('date_from')->first();
        if ($other) {
            throw AppError::rule('BANK_STATEMENT_OVERLAP', "الفترة تتداخل مع الكشف {$other->number} ({$other->date_from->toDateString()} — {$other->date_to->toDateString()}) لهذا الحساب",
                "The period overlaps statement {$other->number} of this bank account", ['statement' => $other->number, 'dateFrom' => $other->date_from->toDateString(), 'dateTo' => $other->date_to->toDateString()]);
        }
    }

    /**
     * Keeps the opening-balance line where it belongs: on the first live statement of the bank account when that
     * statement opens with a balance, dated the day before it starts — and nowhere else. An opening line that is
     * matched is never moved or removed silently: the change that would need it is refused, and names the way out.
     */
    public function syncOpening(?string $bankAccountId, ?AuthUser $user = null): void
    {
        if (! $bankAccountId) {
            return;
        }
        $first = Statement::where('bank_account_id', $bankAccountId)->whereIn('status', Statement::LIVE)->orderBy('date_from')->orderBy('id')->first();
        $wanted = $first ? Money::cents((string) $first->opening_balance) : 0;
        $kept = null;
        foreach (StatementLine::where('bank_account_id', $bankAccountId)->where('kind', StatementLine::KIND_OPENING)->orderBy('id')->lockForUpdate()->get() as $line) {
            $belongs = $first && $line->statement_id === $first->id && $wanted !== 0 && $kept === null;
            if ($belongs) {
                $date = $first->date_from->copy()->subDay()->toDateString();
                if (Money::cents((string) $line->amount) !== $wanted || $line->txn_date->toDateString() !== $date) {
                    $this->refuseMatchedOpening($line);
                    $line->update(['amount' => Money::str($wanted), 'txn_date' => $date]);
                }
                $kept = $line;

                continue;
            }
            $this->refuseMatchedOpening($line);
            $line->delete();
        }
        if ($first && $wanted !== 0 && $kept === null) {
            StatementLine::create([
                'statement_id' => $first->id, 'bank_account_id' => $bankAccountId, 'line_no' => 0, 'kind' => StatementLine::KIND_OPENING, 'txn_date' => $first->date_from->copy()->subDay()->toDateString(),
                'amount' => Money::str($wanted), 'description' => 'رصيد افتتاحي — Opening balance', 'created_by' => $user?->username ?? 'system',
            ]);
        }
    }

    private function refuseMatchedOpening(StatementLine $line): void
    {
        if ($line->match_id !== null) {
            $number = Statement::whereKey($line->statement_id)->value('number');
            throw AppError::rule('BANK_OPENING_MATCHED', "الرصيد الافتتاحي للكشف {$number} مطابَق بقيود من الدفاتر — فكّ مطابقته أولًا (وأعد فتح الكشف إن كان مقفلًا) ثم أعد المحاولة",
                "The opening balance of statement {$number} is matched with ledger entries — undo that match first (reopen the statement if it is locked), then try again", ['statement' => $number, 'match' => $line->match_id]);
        }
    }

    /** @return array{0:string, 1:string} */
    private static function period(string $from, string $to): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        if ($from > $to) {
            throw AppError::validation('BANK_STATEMENT_DATES', 'بداية فترة الكشف بعد نهايتها', 'The statement period starts after it ends', [['path' => 'dateTo', 'message' => 'يجب أن يكون بعد تاريخ البداية']]);
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            throw AppError::validation('BANK_STATEMENT_DATES', 'فترة الكشف أطول من سنة — قسّمها على كشوف', 'A statement period cannot be longer than a year', [['path' => 'dateTo', 'message' => 'الفترة أطول من سنة']]);
        }

        return [$from, $to];
    }

    public static function reason(string $reason, string $messageAr): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw AppError::validation('REASON_REQUIRED', $messageAr, 'A reason is required (5 characters at least)', [['path' => 'reason', 'message' => $messageAr]]);
        }

        return mb_substr($reason, 0, 500);
    }

    private static function text(mixed $value, int $max): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private static function lineNotFound(): AppError
    {
        return AppError::notFound('BANK_LINE_NOT_FOUND', 'سطر الكشف غير موجود', 'Statement line not found');
    }
}
