<?php

namespace App\Banking\Services;

use App\Banking\Models\SourceJournal;
use App\Banking\Models\Statement;
use App\Banking\Models\StatementLine;
use App\Banking\Support\Mask;
use App\Banking\Support\Money;
use App\Banking\Support\Shape;
use App\Finance\Models\BankAccount;
use App\Support\AuthUser;
use Carbon\Carbon;

/**
 * The reconciliation statement of a bank account at a date — the paper an auditor asks for:
 *
 *   balance per bank statement                         balance per books (the ledger)
 *   + deposits in transit      (booked, not at the bank yet)   + bank credits not booked yet
 *   − outstanding payments     (booked, not at the bank yet)   − bank debits not booked yet (charges …)
 *   = adjusted bank balance                            = adjusted book balance
 *
 * and the two adjusted balances are equal. Every open item is listed. "Open at the date" means: dated on or before
 * it, and either unmatched or part of a match that was only completed by something dated after it (a payment issued
 * on the 30th and cleared by the bank on the 2nd is outstanding on the 31st).
 *
 * THE DIFFERENCE MUST BE EXPLAINED TO ZERO. Because every match is equal to the cent, the two adjusted balances can
 * only differ by what is wrong in the statements themselves, and each such thing is named: a statement whose lines
 * do not add up to its closing balance (lines are missing), and a gap between a statement's opening balance and the
 * closing balance of the one before it (a statement is missing). `unexplained` is what would remain after those — it
 * is zero, and it is computed, not assumed.
 *
 * The statement balance is the bank's own figure: the closing balance of the last statement that ends on or before
 * the date, or — inside a statement — its opening balance plus its lines up to the date. History statements of the
 * source system and voided statements take no part.
 */
class ReconciliationService
{
    public const MAX_ITEMS = 5000;

    public function __construct(private readonly Banks $banks, private readonly BookItems $books, private readonly StatementsService $statements) {}

    public function at(AuthUser $user, string $bankIdOrCode, ?string $date): array
    {
        $bank = $this->banks->find($bankIdOrCode);
        $date = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();
        $full = Mask::sees($user);
        $book = $this->books->balance($bank, $date);

        // ── the books' side: entries dated up to the day that the bank has not shown by that day
        $open = fn () => $this->books->query($bank)->where('j.journal_date', '<=', $date)->where(fn ($w) => $w->whereNull('mi.match_id')->orWhere('m.cleared_date', '>', $date));
        $sums = $open()->toBase()->cloneWithout(['columns'])->selectRaw('sum(case when fin_journal_lines.debit > 0 then 1 else 0 end) as n_in, sum(case when fin_journal_lines.credit > 0 then 1 else 0 end) as n_out,
            coalesce(sum(fin_journal_lines.debit), 0) as d, coalesce(sum(fin_journal_lines.credit), 0) as c')->first();
        $transit = Money::cents((string) $sums->d);
        $outstanding = -Money::cents((string) $sums->c);
        $entries = $this->books->shape($open()->orderBy('j.journal_date')->orderBy('j.number')->orderBy('fin_journal_lines.id')->limit(self::MAX_ITEMS)->get()->all(), $bank);

        $out = [
            'bank' => Shape::bank($bank, $full), 'date' => $date, 'currency' => $bank->currency,
            'book' => ['balance' => Money::str($book), 'ledgerAccount' => Shape::account($bank->account), 'sharedWith' => array_column($this->banks->sharing($bank), 'code')],
            'statement' => null,
            'outstanding' => [
                'depositsInTransit' => ['count' => (int) $sums->n_in, 'total' => Money::str($transit), 'items' => array_values(array_filter($entries, fn (array $e) => $e['direction'] === 'in'))],
                'outstandingPayments' => ['count' => (int) $sums->n_out, 'total' => Money::str($outstanding), 'items' => array_values(array_filter($entries, fn (array $e) => $e['direction'] === 'out'))],
            ],
            'unbooked' => ['credits' => ['count' => 0, 'total' => '0.00', 'items' => []], 'debits' => ['count' => 0, 'total' => '0.00', 'items' => []]],
            'adjusted' => ['bank' => null, 'book' => Money::str($book)], 'difference' => null, 'explained' => [], 'unexplained' => null,
            'status' => 'no_statement', 'reconciled' => false, 'truncated' => count($entries) >= self::MAX_ITEMS,
        ];

        // ── the bank's side: the statements that have begun by the day
        $list = Statement::where('bank_account_id', $bank->id)->whereIn('status', Statement::LIVE)->where('date_from', '<=', $date)->orderBy('date_from')->orderBy('id')->get();
        if ($list->isEmpty()) {
            return $out;
        }
        /** @var Statement $covering */
        $covering = $list->last();
        $inside = $date < $covering->date_to->toDateString();
        $totals = $this->statements->totalsFor($list->pluck('id')->all());
        if ($inside) {
            $running = StatementLine::where('statement_id', $covering->id)->where('kind', StatementLine::KIND_LINE)->where('txn_date', '<=', $date)->sum('amount');
            $balance = Money::cents((string) $covering->opening_balance) + Money::cents((string) ($running ?: '0'));
        } else {
            $balance = Money::cents((string) $covering->closing_balance);
        }

        // statement lines dated up to the day that the books do not hold by that day (the opening balance of the first statement among them)
        $lineQuery = fn () => StatementLine::query()->leftJoin('bank_matches as m', 'm.id', '=', 'bank_statement_lines.match_id')->whereIn('bank_statement_lines.statement_id', $list->pluck('id')->all())
            ->where('bank_statement_lines.txn_date', '<=', $date)->where(fn ($w) => $w->whereNull('bank_statement_lines.match_id')->orWhere('m.cleared_date', '>', $date));
        $lineSums = $lineQuery()->toBase()->selectRaw('sum(case when bank_statement_lines.amount > 0 then 1 else 0 end) as n_in, sum(case when bank_statement_lines.amount < 0 then 1 else 0 end) as n_out,
            coalesce(sum(case when bank_statement_lines.amount > 0 then bank_statement_lines.amount else 0 end), 0) as money_in,
            coalesce(sum(case when bank_statement_lines.amount < 0 then bank_statement_lines.amount else 0 end), 0) as money_out')->first();
        $credits = Money::cents((string) $lineSums->money_in);
        $debits = Money::cents((string) $lineSums->money_out);
        $lines = $lineQuery()->select('bank_statement_lines.*')->with(['match:id,number,kind,cleared_date', 'statement:id,number'])->orderBy('bank_statement_lines.txn_date')
            ->orderBy('bank_statement_lines.statement_id')->orderBy('bank_statement_lines.line_no')->orderBy('bank_statement_lines.id')->limit(self::MAX_ITEMS)->get()
            ->map(fn (StatementLine $l) => Shape::line($l, $full))->all();

        // ── what is wrong in the statements themselves, each named
        $explained = [];
        $explainedTotal = 0;
        $previous = null;
        foreach ($list as $s) {
            if ($previous !== null) {
                $gap = Money::cents((string) $s->opening_balance) - Money::cents((string) $previous->closing_balance);
                if ($gap !== 0) {
                    $explainedTotal += $gap;
                    $explained[] = ['kind' => 'gap', 'statement' => ['id' => $s->id, 'number' => $s->number], 'previous' => ['id' => $previous->id, 'number' => $previous->number], 'amount' => Money::str($gap),
                        'ar' => "الكشف {$s->number} يفتتح برصيد لا يساوي ختام {$previous->number} — كشف ناقص بينهما أو رصيد خاطئ", 'en' => "Statement {$s->number} opens with a balance that is not the closing of {$previous->number} — a statement is missing in between or a balance is wrong"];
                }
            }
            // a statement that has ended by the day must add up; the one the day falls inside is still running
            if (! ($inside && $s->id === $covering->id)) {
                $missing = Money::cents((string) $s->closing_balance) - (Money::cents((string) $s->opening_balance) + ($totals[$s->id]['net'] ?? 0));
                if ($missing !== 0) {
                    $explainedTotal += $missing;
                    $explained[] = ['kind' => 'unbalanced', 'statement' => ['id' => $s->id, 'number' => $s->number], 'previous' => null, 'amount' => Money::str($missing),
                        'ar' => "سطور الكشف {$s->number} لا تبلغ رصيده الختامي — سطور ناقصة أو زائدة", 'en' => "The lines of statement {$s->number} do not reach its closing balance — lines are missing or doubled"];
                }
            }
            $previous = $s;
        }
        $adjustedBank = $balance + $transit + $outstanding;
        $adjustedBook = $book + $credits + $debits;
        $difference = $adjustedBank - $adjustedBook;
        $unexplained = $difference - $explainedTotal;
        $unbooked = (int) $lineSums->n_in + (int) $lineSums->n_out;
        $behind = $date > $covering->date_to->toDateString() ? (int) Carbon::parse($covering->date_to->toDateString())->diffInDays(Carbon::parse($date)) : 0;

        return array_merge($out, [
            'statement' => [
                'balance' => Money::str($balance), 'source' => $inside ? 'running' : 'closing', 'asOf' => $inside ? $date : $covering->date_to->toDateString(), 'behindDays' => $behind,
                'statement' => ['id' => $covering->id, 'number' => $covering->number, 'dateFrom' => $covering->date_from->toDateString(), 'dateTo' => $covering->date_to->toDateString(), 'status' => $covering->status,
                    'openingBalance' => Money::out($covering->opening_balance), 'closingBalance' => Money::out($covering->closing_balance)],
                'statements' => $list->count(), 'reconciledStatements' => $list->where('status', 'reconciled')->count(),
            ],
            'unbooked' => [
                'credits' => ['count' => (int) $lineSums->n_in, 'total' => Money::str($credits), 'items' => array_values(array_filter($lines, fn (array $l) => $l['direction'] === 'in'))],
                'debits' => ['count' => (int) $lineSums->n_out, 'total' => Money::str($debits), 'items' => array_values(array_filter($lines, fn (array $l) => $l['direction'] === 'out'))],
            ],
            'adjusted' => ['bank' => Money::str($adjustedBank), 'book' => Money::str($adjustedBook)],
            'difference' => Money::str($difference), 'explained' => $explained, 'unexplained' => Money::str($unexplained),
            // reconciled: the two adjusted balances are equal and nothing the bank shows is missing from the books
            'status' => $difference !== 0 ? 'difference' : ($unbooked > 0 ? 'open_items' : 'reconciled'), 'reconciled' => $difference === 0 && $unbooked === 0,
            'truncated' => count($entries) >= self::MAX_ITEMS || count($lines) >= self::MAX_ITEMS,
        ]);
    }

    /** Every OPS bank account and cash box with where its reconciliation stands today. */
    public function accounts(AuthUser $user): array
    {
        $full = Mask::sees($user);
        $journals = SourceJournal::whereNotNull('bank_account_id')->orderBy('code')->get(['id', 'code', 'name', 'kind', 'bank_account_id'])->groupBy('bank_account_id');

        return BankAccount::with('account:id,code,name_ar,name_en')->orderByDesc('active')->orderBy('code')->get()->map(function (BankAccount $bank) use ($full, $journals) {
            return $this->overview($bank, $full) + ['sourceJournals' => ($journals[$bank->id] ?? collect())->map(fn (SourceJournal $j) => ['id' => $j->id, 'code' => $j->code, 'name' => $j->name, 'kind' => $j->kind])->values()->all()];
        })->all();
    }

    private function overview(BankAccount $bank, bool $full): array
    {
        $live = Statement::where('bank_account_id', $bank->id)->whereIn('status', Statement::LIVE);
        $last = (clone $live)->orderByDesc('date_to')->orderByDesc('id')->first();
        $lastReconciled = (clone $live)->where('status', 'reconciled')->orderByDesc('date_to')->orderByDesc('id')->first();
        $lines = StatementLine::where('bank_account_id', $bank->id)->whereNull('match_id')->whereIn('statement_id', (clone $live)->select('id'))->toBase()
            ->selectRaw('count(*) as n, coalesce(sum(case when amount > 0 then amount else 0 end), 0) as money_in, coalesce(sum(case when amount < 0 then amount else 0 end), 0) as money_out')->first();
        $entries = $this->books->query($bank)->whereNull('mi.match_id')->toBase()->cloneWithout(['columns'])
            ->selectRaw('count(*) as n, coalesce(sum(fin_journal_lines.debit), 0) as d, coalesce(sum(fin_journal_lines.credit), 0) as c')->first();
        $brief = fn (?Statement $s) => $s ? ['id' => $s->id, 'number' => $s->number, 'dateFrom' => $s->date_from->toDateString(), 'dateTo' => $s->date_to->toDateString(), 'closingBalance' => Money::out($s->closing_balance), 'status' => $s->status,
            'reconciledAt' => Shape::at($s->reconciled_at)] : null;
        $book = $this->books->balance($bank);

        return Shape::bank($bank, $full) + [
            'bookBalance' => Money::str($book), 'statementBalance' => $last ? Money::out($last->closing_balance) : null, 'lastStatement' => $brief($last), 'lastReconciled' => $brief($lastReconciled),
            'openStatements' => (clone $live)->where('status', 'open')->count(), 'statements' => (clone $live)->count(),
            'unmatchedLines' => ['count' => (int) $lines->n, 'in' => Money::out($lines->money_in), 'out' => Money::out($lines->money_out)],
            'unmatchedEntries' => ['count' => (int) $entries->n, 'in' => Money::out($entries->d), 'out' => Money::str(-Money::cents((string) $entries->c))],
            'sharedWith' => array_column($this->banks->sharing($bank), 'code'),
        ];
    }
}
