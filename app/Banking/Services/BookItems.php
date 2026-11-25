<?php

namespace App\Banking\Services;

use App\Banking\Models\BankMatchItem;
use App\Banking\Support\Money;
use App\Finance\Models\BankAccount;
use App\Finance\Models\Expense;
use App\Finance\Models\Journal;
use App\Finance\Models\JournalLine;
use App\Finance\Models\Payment;
use App\Models\Customer;
use App\Models\Supplier;
use App\Support\Paging;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The books' side of a bank account: every posted entry on its ledger account (`fin_journal_lines` of a journal that
 * is not a draft). A receipt, a supplier payment, an expense paid from the bank, an asset bought, a manual journal, a
 * write-off — each reaches the bank's ledger account as exactly one such entry, so the entry is what a statement line
 * is matched with, and the document (RCT- / PMT- / EXP- …) is how people recognise it.
 *
 * The amount of an entry is signed like a statement line: + money in (a debit of the bank's account), − money out.
 * The ledger is finance's and is only read here.
 *
 * SHARED LEDGER ACCOUNT. Finance gives each bank its own ledger account; where a person made two banks share one, an
 * entry belongs to the bank its document names (a receipt, a payment, an expense), else to the bank it was matched
 * under, else it is shown under every bank that shares the account until someone matches it.
 */
class BookItems
{
    public function __construct(private readonly Banks $banks) {}

    /** The entries of the bank's ledger account, with the match that stands on each (if any). */
    public function query(BankAccount $bank): Builder
    {
        $q = JournalLine::query()
            ->join('fin_journals as j', 'j.id', '=', 'fin_journal_lines.journal_id')
            ->leftJoin('bank_match_items as mi', 'mi.live_journal_line_id', '=', 'fin_journal_lines.id')
            ->leftJoin('bank_matches as m', 'm.id', '=', 'mi.match_id')
            ->where('fin_journal_lines.account_id', $bank->account_id)
            ->where('j.status', '!=', 'draft')
            ->select(['fin_journal_lines.*', 'j.number as journal_number', 'j.journal_date', 'j.memo', 'j.status as journal_status', 'j.kind as journal_kind', 'j.source_type', 'j.source_id',
                'j.source_number', 'j.reversal_of_id', 'j.reversed_by_id', 'mi.match_id as match_id', 'm.number as match_number', 'm.cleared_date as match_cleared', 'm.kind as match_kind']);
        if ($this->banks->sharing($bank)) {
            $id = $bank->id;
            $q->whereNotExists(fn ($s) => $s->selectRaw('1')->from('fin_payments as sp')->whereColumn('sp.id', 'fin_journal_lines.reference_id')->where('fin_journal_lines.reference_type', 'Payment')->where('sp.bank_account_id', '!=', $id))
                ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('fin_expenses as se')->whereColumn('se.id', 'fin_journal_lines.reference_id')->where('fin_journal_lines.reference_type', 'Expense')
                    ->whereNotNull('se.bank_account_id')->where('se.bank_account_id', '!=', $id))
                ->where(fn ($w) => $w->whereNull('m.id')->orWhere('m.bank_account_id', $id));
        }

        return $q;
    }

    /** The balance of the bank in the books, in hundredths, at the end of a day (today when none is given). */
    public function balance(BankAccount $bank, ?string $asOf = null): int
    {
        $q = $this->query($bank);
        if ($asOf !== null) {
            $q->where('j.journal_date', '<=', $asOf);
        }
        $sum = $q->toBase()->cloneWithout(['columns'])->selectRaw('coalesce(sum(fin_journal_lines.debit), 0) as d, coalesce(sum(fin_journal_lines.credit), 0) as c')->first();

        return Money::cents((string) $sum->d) - Money::cents((string) $sum->c);
    }

    /**
     * @param  array{state?:?string, sign?:?string, kind?:?string, from?:?string, to?:?string, amount?:?string}  $f
     */
    public function list(BankAccount $bank, Paging $page, array $f): array
    {
        $q = $this->filtered($bank, $f, $page->q);
        $dir = $page->order;
        match ($page->sort) {
            'amount' => $q->orderByRaw('(fin_journal_lines.debit - fin_journal_lines.credit) '.($dir === 'asc' ? 'asc' : 'desc')),
            'number' => $q->orderBy('j.number', $dir),
            default => $q->orderBy('j.journal_date', $dir),
        };
        $q->orderBy('j.number', $dir)->orderBy('fin_journal_lines.line_no', $dir)->orderBy('fin_journal_lines.id', $dir);
        $total = (clone $q)->toBase()->getCountForPagination();
        $rows = $q->forPage($page->page, $page->pageSize)->get();
        $sums = $this->filtered($bank, $f, $page->q)->toBase()->cloneWithout(['columns'])->selectRaw('coalesce(sum(fin_journal_lines.debit), 0) as d, coalesce(sum(fin_journal_lines.credit), 0) as c')->first();

        return $page->wrap($this->shape($rows->all(), $bank), $total) + ['totals' => ['in' => Money::out($sums->d), 'out' => Money::out($sums->c), 'net' => Money::str(Money::cents((string) $sums->d) - Money::cents((string) $sums->c))]];
    }

    private function filtered(BankAccount $bank, array $f, ?string $search): Builder
    {
        $q = $this->query($bank);
        match ($f['state'] ?? 'unmatched') {
            'matched' => $q->whereNotNull('mi.match_id'), 'all' => null, default => $q->whereNull('mi.match_id'),
        };
        if (($f['sign'] ?? null) === 'in') {
            $q->where('fin_journal_lines.debit', '>', 0);
        } elseif (($f['sign'] ?? null) === 'out') {
            $q->where('fin_journal_lines.credit', '>', 0);
        }
        match ($f['kind'] ?? null) {
            'receipt' => $q->where('fin_journal_lines.reference_type', 'Payment')->where('fin_journal_lines.debit', '>', 0)->where('j.kind', '!=', 'reversal'),
            'payment' => $q->where('fin_journal_lines.reference_type', 'Payment')->where('fin_journal_lines.credit', '>', 0)->where('j.kind', '!=', 'reversal'),
            'expense' => $q->where('fin_journal_lines.reference_type', 'Expense')->where('j.kind', '!=', 'reversal'),
            'reversal' => $q->where('j.kind', 'reversal'),
            'writeoff' => $q->where('j.source_type', 'BankMatch')->where('j.kind', '!=', 'reversal'),
            'journal' => $q->where(fn ($w) => $w->whereNull('fin_journal_lines.reference_type')->orWhereNotIn('fin_journal_lines.reference_type', ['Payment', 'Expense']))
                ->where('j.kind', '!=', 'reversal')->where(fn ($w) => $w->whereNull('j.source_type')->orWhere('j.source_type', '!=', 'BankMatch')),
            default => null,
        };
        if (! empty($f['from'])) {
            $q->where('j.journal_date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->where('j.journal_date', '<=', $f['to']);
        }
        if (isset($f['amount']) && $f['amount'] !== '' && Money::valid($f['amount'])) {
            // the figure as people read it on a statement: with or without its sign
            $cents = Money::cents($f['amount']);
            $q->whereIn(DB::raw('(fin_journal_lines.debit - fin_journal_lines.credit)'), array_unique([Money::str($cents), Money::str(-$cents)]));
        }
        if ($search !== null && $search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $q->where(fn ($w) => $w->where('j.number', 'like', $like)->orWhere('j.memo', 'like', $like)->orWhere('fin_journal_lines.description', 'like', $like)->orWhere('j.source_number', 'like', $like)
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('fin_payments as qp')->whereColumn('qp.id', 'fin_journal_lines.reference_id')->where('fin_journal_lines.reference_type', 'Payment')
                    ->where(fn ($x) => $x->where('qp.number', 'like', $like)->orWhere('qp.reference', 'like', $like)))
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('fin_expenses as qe')->whereColumn('qe.id', 'fin_journal_lines.reference_id')->where('fin_journal_lines.reference_type', 'Expense')
                    ->where(fn ($x) => $x->where('qe.number', 'like', $like)->orWhere('qe.reference', 'like', $like))));
        }

        return $q;
    }

    /**
     * The unmatched entries a statement line may be matched with: of one sign, dated inside a window. At most `$limit`,
     * the nearest to `$around` first.
     *
     * @return array<int, array<string, mixed>> shaped
     */
    public function candidates(BankAccount $bank, int $sign, string $from, string $to, string $around, int $limit = 400): array
    {
        $q = $this->query($bank)->whereNull('mi.match_id')->whereBetween('j.journal_date', [$from, $to]);
        $sign > 0 ? $q->where('fin_journal_lines.debit', '>', 0) : $q->where('fin_journal_lines.credit', '>', 0);
        $rows = $q->orderBy('j.journal_date')->orderBy('j.number')->orderBy('fin_journal_lines.id')->limit(2000)->get()->all();
        usort($rows, fn ($a, $b) => [abs(strtotime((string) $a->journal_date) - strtotime($around)), (string) $a->id] <=> [abs(strtotime((string) $b->journal_date) - strtotime($around)), (string) $b->id]);

        return $this->shape(array_slice($rows, 0, $limit), $bank);
    }

    /**
     * The unmatched entries booked up to a day, both signs — what an opening balance is made of.
     *
     * @return array{items: array<int, array<string, mixed>>, count: int, total: int} the first `$limit` shaped, how many there are, their sum in hundredths
     */
    public function unmatchedUpTo(BankAccount $bank, string $date, int $limit): array
    {
        $base = fn () => $this->query($bank)->whereNull('mi.match_id')->where('j.journal_date', '<=', $date);
        $sum = $base()->toBase()->cloneWithout(['columns'])->selectRaw('count(*) as n, coalesce(sum(fin_journal_lines.debit), 0) as d, coalesce(sum(fin_journal_lines.credit), 0) as c')->first();
        $items = $limit > 0 ? $this->shape($base()->orderBy('j.journal_date')->orderBy('j.number')->orderBy('fin_journal_lines.id')->limit($limit)->get()->all(), $bank) : [];

        return ['items' => $items, 'count' => (int) $sum->n, 'total' => Money::cents((string) $sum->d) - Money::cents((string) $sum->c)];
    }

    /** The entries with these ids as the section shows them (matched or not), keyed by id. @return array<string, array<string, mixed>> */
    public function byIds(BankAccount $bank, array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $out = [];
        foreach ($this->shape($this->query($bank)->whereIn('fin_journal_lines.id', $ids)->get()->all(), $bank) as $item) {
            $out[$item['id']] = $item;
        }

        return $out;
    }

    /**
     * The JSON of ledger entries, with the document each belongs to, its party, and — for a journal that was reversed
     * and for its reversal — the entry that cancels it (`pair`).
     *
     * @param  array<int, JournalLine>  $rows  from query()
     * @return array<int, array<string, mixed>>
     */
    public function shape(array $rows, BankAccount $bank): array
    {
        if (! $rows) {
            return [];
        }
        $ids = fn (string $type) => array_values(array_unique(array_filter(array_map(fn ($r) => $r->reference_type === $type ? (string) $r->reference_id : null, $rows))));
        $payments = ($p = $ids('Payment')) ? Payment::whereIn('id', $p)->get(['id', 'number', 'direction', 'partner_type', 'partner_id', 'reference', 'method', 'status', 'payment_date'])->keyBy('id') : collect();
        $expenses = ($e = $ids('Expense')) ? Expense::whereIn('id', $e)->get(['id', 'number', 'description', 'reference', 'status', 'supplier_id'])->keyBy('id') : collect();
        $partners = ['customer' => [], 'supplier' => []];
        foreach ($payments as $pay) {
            $partners[$pay->partner_type][(string) $pay->partner_id] = true;
        }
        foreach ($rows as $r) {
            if ($r->partner_id && isset($partners[$r->partner_type])) {
                $partners[$r->partner_type][(string) $r->partner_id] = true;
            }
        }
        $customers = $partners['customer'] ? Customer::whereIn('id', array_keys($partners['customer']))->get(['id', 'code', 'name_ar', 'name_en'])->keyBy('id') : collect();
        $suppliers = $partners['supplier'] ? Supplier::whereIn('id', array_keys($partners['supplier']))->get(['id', 'code', 'name_ar', 'name_en'])->keyBy('id') : collect();
        $party = function (?string $type, ?string $id) use ($customers, $suppliers): ?array {
            $row = $type === 'customer' ? ($customers[$id] ?? null) : ($type === 'supplier' ? ($suppliers[$id] ?? null) : null);

            return $row ? ['type' => $type, 'code' => $row->code, 'nameAr' => $row->name_ar, 'nameEn' => $row->name_en] : null;
        };
        $pairs = $this->pairs($rows, $bank);

        $out = [];
        foreach ($rows as $r) {
            $cents = Money::cents((string) $r->debit) - Money::cents((string) $r->credit);
            $pay = $r->reference_type === 'Payment' ? ($payments[$r->reference_id] ?? null) : null;
            $exp = $r->reference_type === 'Expense' ? ($expenses[$r->reference_id] ?? null) : null;
            $kind = match (true) {
                $r->journal_kind === 'reversal' => 'reversal',
                $r->source_type === 'BankMatch' => 'writeoff',
                $pay !== null => $pay->direction === 'in' ? 'receipt' : 'payment',
                $exp !== null => 'expense',
                default => 'journal',
            };
            $document = match (true) {
                $pay !== null => ['type' => 'Payment', 'id' => $pay->id, 'number' => $pay->number, 'status' => $pay->status],
                $exp !== null => ['type' => 'Expense', 'id' => $exp->id, 'number' => $exp->number, 'status' => $exp->status],
                default => ['type' => $r->source_type ?: 'Journal', 'id' => $r->source_id ?: $r->journal_id, 'number' => $r->source_number ?: $r->journal_number, 'status' => null],
            };
            $date = substr((string) $r->journal_date, 0, 10);
            $out[] = [
                'id' => (string) $r->id, 'journalId' => (string) $r->journal_id, 'journalNumber' => $r->journal_number, 'journalStatus' => $r->journal_status, 'date' => $date,
                'amount' => Money::str($cents), 'direction' => $cents >= 0 ? 'in' : 'out', 'kind' => $kind, 'document' => $document,
                'party' => $pay ? $party($pay->partner_type, (string) $pay->partner_id) : $party($r->partner_type, $r->partner_id ? (string) $r->partner_id : null),
                'reference' => $pay?->reference ?? $exp?->reference, 'method' => $pay?->method, 'description' => $r->description, 'memo' => $r->memo,
                'matched' => $r->match_id !== null,
                'match' => $r->match_id !== null ? ['id' => (string) $r->match_id, 'number' => $r->match_number, 'kind' => $r->match_kind, 'clearedDate' => $r->match_cleared ? substr((string) $r->match_cleared, 0, 10) : null] : null,
                'pair' => $pairs[(string) $r->id] ?? null,
            ];
        }

        return $out;
    }

    /**
     * For an entry of a reversed journal and for the entry of its reversal: the other one. `free` says neither is
     * matched — together they are a movement that never reached the bank (a voided receipt), and may be cleared
     * against each other.
     *
     * @return array<string, array{ledgerLineId:string, journalNumber:?string, free:bool}>
     */
    private function pairs(array $rows, BankAccount $bank): array
    {
        $wanted = [];
        foreach ($rows as $r) {
            $other = $r->reversed_by_id ?: $r->reversal_of_id;
            if ($other) {
                $wanted[(string) $r->id] = [(string) $other, (int) $r->line_no];
            }
        }
        if (! $wanted) {
            return [];
        }
        $journalIds = array_values(array_unique(array_column($wanted, 0)));
        $numbers = Journal::whereIn('id', $journalIds)->pluck('number', 'id');
        $lines = [];
        foreach (JournalLine::whereIn('journal_id', $journalIds)->where('account_id', $bank->account_id)->get(['id', 'journal_id', 'line_no']) as $l) {
            $lines[$l->journal_id.'#'.$l->line_no] = (string) $l->id;
        }
        $taken = $lines ? BankMatchItem::whereIn('live_journal_line_id', array_values($lines))->pluck('live_journal_line_id')->flip()->all() : [];
        $matched = [];
        foreach ($rows as $r) {
            $matched[(string) $r->id] = $r->match_id !== null;
        }
        $out = [];
        foreach ($wanted as $id => [$journalId, $lineNo]) {
            $otherLine = $lines[$journalId.'#'.$lineNo] ?? null;
            if ($otherLine !== null) {
                $out[$id] = ['ledgerLineId' => $otherLine, 'journalNumber' => $numbers[$journalId] ?? null, 'free' => ! $matched[$id] && ! isset($taken[$otherLine])];
            }
        }

        return $out;
    }
}
