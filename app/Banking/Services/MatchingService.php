<?php

namespace App\Banking\Services;

use App\Banking\Models\BankMatch;
use App\Banking\Models\BankMatchItem;
use App\Banking\Models\Statement;
use App\Banking\Models\StatementLine;
use App\Banking\Models\WriteoffRule;
use App\Banking\Support\Mask;
use App\Banking\Support\Money;
use App\Banking\Support\Shape;
use App\Finance\Models\BankAccount;
use App\Finance\Models\Journal;
use App\Finance\Models\JournalLine;
use App\Finance\Services\LedgerService;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Matching: statement lines against the entries of the bank's ledger account — one line with many entries, many lines
 * with one entry, or any group of both — confirmed by a person and undone with a reason.
 *
 * THE RULE OF A MATCH: the lines and the entries are equal to the cent. A difference is never left standing: it is
 * posted by a write-off rule that accepts it (its direction, its limit, its bank) as a journal of the ledger service,
 * whose entry on the bank's account joins the match — or the match is refused. A group with one empty side is a
 * contra: entries (or lines) that cancel each other to zero.
 *
 * NEVER TWICE. The statements, then the statement lines, then the ledger entries are locked in that order before
 * anything is checked, so two people confirming at the same moment are served one after the other and the second is
 * told the line or the document is already matched (409); and the unique `live_*` columns of `bank_match_items` make
 * the database refuse it whatever the code does.
 *
 * UNDO restores what was: the lines and the entries are free again, the match stays as a record (who, when, why), and
 * its write-off journal is reversed through the ledger service — the journal and its reversal are then held together
 * as a `system` match, so they never show up as open items of their own.
 *
 * A reconciled statement is immutable: no line of it is matched or unmatched until it is reopened. The ledger is only
 * read, locked and posted to through its own service; a closed accounting period refuses the write-off or the reversal
 * (PERIOD_CLOSED) and nothing of the match is kept.
 */
class MatchingService
{
    public const MAX_SIDE = 300;

    public function __construct(
        private readonly Banks $banks,
        private readonly BookItems $books,
        private readonly StatementsService $statements,
        private readonly SuggestionService $suggestions,
        private readonly LedgerService $ledger,
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
    ) {}

    // ───────────────────────────── reads ─────────────────────────────

    /** @param  array{bank?:?string, status?:?string, kind?:?string, from?:?string, to?:?string, writeoff?:?string}  $f */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $q = BankMatch::query()->with(['bankAccount:id,code,name_ar,name_en,kind', 'rule:id,name_ar,name_en'])->withCount([
            'items as statement_lines_count' => fn ($i) => $i->where('side', BankMatchItem::SIDE_STATEMENT), 'items as ledger_lines_count' => fn ($i) => $i->where('side', BankMatchItem::SIDE_LEDGER),
        ]);
        if (! empty($f['bank'])) {
            $q->where('bank_account_id', $this->banks->find($f['bank'])->id);
        }
        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['kind'])) {
            $q->where('kind', $f['kind']);
        }
        if (($f['writeoff'] ?? null) === '1') {
            $q->whereNotNull('writeoff_journal_id');
        }
        if (! empty($f['from'])) {
            $q->where('cleared_date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->where('cleared_date', '<=', $f['to']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('note', 'like', $like)->orWhere('undo_reason', 'like', $like)->orWhere('created_by', 'like', $like)
                ->orWhereHas('items', fn ($i) => $i->where('doc_number', 'like', $like)->orWhere('label', 'like', $like)));
        }
        $column = match ($page->sort) {
            'number' => 'number', 'date' => 'cleared_date', 'amount' => 'statement_total', default => 'created_at'
        };
        $q->orderBy($column, $page->order)->orderBy('id', $page->order);

        return $page->paginate($q, fn (BankMatch $m) => array_diff_key(Shape::match($m, false), ['statementLines' => 1, 'ledgerLines' => 1])
            + ['statementLinesCount' => (int) $m->statement_lines_count, 'ledgerLinesCount' => (int) $m->ledger_lines_count, 'hasWriteOff' => $m->writeoff_journal_id !== null]);
    }

    public function get(AuthUser $user, string $idOrNumber): array
    {
        return $this->shape($user, $this->find($idOrNumber));
    }

    private function find(string $idOrNumber, bool $lock = false): BankMatch
    {
        $q = BankMatch::query()->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber));

        return ($lock ? $q->lockForUpdate() : $q)->first() ?? throw AppError::notFound('BANK_MATCH_NOT_FOUND', "المطابقة {$idOrNumber} غير موجودة", "Match {$idOrNumber} not found");
    }

    private function shape(AuthUser $user, BankMatch $m): array
    {
        $m->load(['bankAccount:id,code,name_ar,name_en,kind,account_id', 'rule:id,name_ar,name_en', 'items' => fn ($i) => $i->orderBy('side')->orderBy('item_date')->orderBy('id'), 'items.statementLine.statement:id,number']);
        $ledger = $m->bankAccount ? $this->books->byIds($m->bankAccount, $m->items->where('side', BankMatchItem::SIDE_LEDGER)->pluck('journal_line_id')->all()) : [];
        $journals = Journal::whereIn('id', array_filter([$m->writeoff_journal_id, $m->writeoff_reversal_journal_id]))->pluck('number', 'id')->all();

        return Shape::match($m, Mask::sees($user), $ledger, $journals);
    }

    // ───────────────────────────── confirm ─────────────────────────────

    /**
     * @param  array{statementLines?:string[], ledgerLines?:string[], bankAccount?:?string, writeOff?:?array{rule:string, date?:?string}, note?:?string, origin?:?string}  $dto
     */
    public function confirm(AuthUser $user, array $dto): array
    {
        $lineIds = array_values(array_unique(array_map('strval', $dto['statementLines'] ?? [])));
        $entryIds = array_values(array_unique(array_map('strval', $dto['ledgerLines'] ?? [])));
        if (! $lineIds && ! $entryIds) {
            throw AppError::validation('BANK_MATCH_EMPTY', 'اختر سطر كشف واحدًا على الأقل أو قيدًا من الدفاتر', 'Choose at least one statement line or one ledger entry');
        }
        if (count($lineIds) > self::MAX_SIDE || count($entryIds) > self::MAX_SIDE) {
            throw AppError::validation('BANK_MATCH_TOO_LARGE', 'مطابقة واحدة لا تتجاوز '.self::MAX_SIDE.' سطر في كل جهة', 'A single match holds at most '.self::MAX_SIDE.' lines on each side');
        }
        try {
            $match = DB::transaction(fn () => $this->confirmLocked($user, $lineIds, $entryIds, $dto));
        } catch (UniqueConstraintViolationException) {
            // two confirmations reached the insert together: the database let one through
            throw self::alreadyMatched([], []);
        }

        return $this->shape($user, $match);
    }

    private function confirmLocked(AuthUser $user, array $lineIds, array $entryIds, array $dto): BankMatch
    {
        // ── the statement side: statements first, then their lines (one order everywhere, so two people never wait on each other in a circle)
        $lines = collect();
        if ($lineIds) {
            $statementIds = StatementLine::whereIn('id', $lineIds)->pluck('statement_id')->unique()->sort()->values()->all();
            $statements = Statement::whereIn('id', $statementIds)->orderBy('id')->lockForUpdate()->get();
            $lines = StatementLine::whereIn('id', $lineIds)->orderBy('id')->lockForUpdate()->get();
            if ($lines->count() !== count($lineIds)) {
                throw AppError::notFound('BANK_LINE_NOT_FOUND', 'أحد سطور الكشف المحددة غير موجود', 'One of the chosen statement lines does not exist');
            }
            foreach ($statements as $statement) {
                $this->statements->assertEditable($statement);
            }
            if ($lines->pluck('bank_account_id')->unique()->count() !== 1 || $lines->first()->bank_account_id === null) {
                throw AppError::rule('BANK_MATCH_MIXED_BANK', 'السطور المحددة ليست من حساب بنكي واحد', 'The chosen lines do not belong to one bank account');
            }
        }
        $bank = $lines->isNotEmpty() ? $this->banks->find((string) $lines->first()->bank_account_id) : $this->banks->find((string) ($dto['bankAccount'] ?? ''));
        $taken = $lines->filter(fn (StatementLine $l) => $l->match_id !== null);

        // ── the books' side: the entries are locked (read under a lock — the ledger is never written here), then checked
        $entries = [];
        if ($entryIds) {
            $locked = JournalLine::whereIn('id', $entryIds)->orderBy('id')->lockForUpdate()->get(['id']);
            if ($locked->count() !== count($entryIds)) {
                throw AppError::notFound('BANK_LEDGER_LINE_NOT_FOUND', 'أحد قيود الدفاتر المحددة غير موجود', 'One of the chosen ledger entries does not exist');
            }
            $entries = $this->books->byIds($bank, $entryIds);
            foreach ($entryIds as $id) {
                if (! isset($entries[$id])) {
                    throw AppError::rule('BANK_LEDGER_LINE_FOREIGN', "أحد القيود المحددة ليس قيدًا مرحَّلًا على حساب {$bank->name_ar} في الأستاذ", "One of the chosen entries is not a posted entry of {$bank->name_en}'s ledger account", ['ledgerLine' => $id]);
                }
            }
        }
        // read under a lock, so it is what is committed NOW — not what this transaction saw when it began
        $standing = $entryIds ? BankMatchItem::whereIn('live_journal_line_id', $entryIds)->lockForUpdate()->pluck('match_id', 'live_journal_line_id')->all() : [];
        if ($taken->isNotEmpty() || $standing) {
            throw self::alreadyMatched($taken->map(fn (StatementLine $l) => ['id' => $l->id, 'lineNo' => $l->line_no, 'match' => $l->match_id])->values()->all(),
                array_values(array_map(fn (string $id) => ['id' => $id, 'document' => $entries[$id]['document']['number'] ?? null, 'match' => $standing[$id]], array_keys($standing))));
        }

        // ── the two sides, to the cent
        $statementTotal = Money::sum($lines->pluck('amount'));
        $ledgerTotal = array_sum(array_map(fn (array $e) => Money::cents($e['amount']), $entries));
        $difference = $statementTotal - $ledgerTotal;
        $oneSided = $lines->isEmpty() || ! $entries;
        $rule = null;
        if ($difference !== 0) {
            $ruleId = $dto['writeOff']['rule'] ?? null;
            if ($lines->isEmpty()) {
                throw AppError::rule('BANK_MATCH_ONE_SIDED', 'قيود الدفاتر المحددة لا تلغي بعضها (مجموعها '.Money::human($ledgerTotal).') — اختر سطر الكشف الذي تقابله', 'The chosen ledger entries do not cancel each other (they sum to '.Money::human($ledgerTotal).') — choose the statement line they settle',
                    ['ledgerTotal' => Money::str($ledgerTotal)]);
            }
            if (! $ruleId) {
                throw AppError::rule('BANK_MATCH_DIFFERENCE', 'المطابقة غير متساوية: الكشف '.Money::human($statementTotal).' والدفاتر '.Money::human($ledgerTotal).' — الفرق '.Money::human($difference).'. اختر قيودًا تساوي السطور، أو قاعدة تسوية تقبل الفرق',
                    'The match is not equal: statement '.Money::human($statementTotal).', books '.Money::human($ledgerTotal).' — difference '.Money::human($difference).'. Choose entries that equal the lines, or a write-off rule that accepts the difference',
                    ['statementTotal' => Money::str($statementTotal), 'ledgerTotal' => Money::str($ledgerTotal), 'difference' => Money::str($difference)]);
            }
            $rule = $this->acceptingRule((string) $ruleId, $bank, $difference);
        } elseif ($oneSided && $lines->count() + count($entries) < 2) {
            // equal sides with one of them empty means the other sums to zero — which a single line or entry cannot
            throw AppError::rule('BANK_MATCH_ONE_SIDED', 'مطابقة من جهة واحدة تحتاج سطرين أو قيدين على الأقل يلغي أحدهما الآخر', 'A one-sided match needs at least two lines or two entries that cancel each other');
        }

        $dates = $lines->map(fn (StatementLine $l) => $l->txn_date->toDateString())->merge(array_column($entries, 'date'));
        $match = BankMatch::create([
            'number' => $this->numbering->next('BMT'), 'bank_account_id' => $bank->id, 'status' => 'active', 'kind' => $oneSided && $rule === null ? 'contra' : 'match',
            'origin' => in_array($dto['origin'] ?? null, ['manual', 'suggestion', 'auto'], true) ? $dto['origin'] : 'manual',
            'statement_total' => Money::str($statementTotal), 'ledger_total' => Money::str($ledgerTotal), 'writeoff_amount' => Money::str($rule ? $difference : 0),
            'cleared_date' => $dates->max(), 'note' => isset($dto['note']) && trim((string) $dto['note']) !== '' ? mb_substr(trim((string) $dto['note']), 0, 1000) : null,
            'created_by' => $user->username, 'created_by_id' => $user->id,
        ]);
        foreach ($lines as $line) {
            $this->item($match, BankMatchItem::SIDE_STATEMENT, ['statement_line_id' => $line->id, 'live_statement_line_id' => $line->id, 'amount' => (string) $line->amount,
                'item_date' => $line->txn_date->toDateString(), 'label' => $line->kind === StatementLine::KIND_OPENING ? 'opening' : $line->reference]);
        }
        foreach ($entries as $entry) {
            $this->entryItem($match, $entry, false);
        }
        $journal = null;
        if ($rule) {
            $journal = $this->postWriteOff($user, $match, $bank, $rule, $difference, $dto['writeOff']['date'] ?? null, $lines->max(fn (StatementLine $l) => $l->txn_date->toDateString()));
            $entry = $this->books->byIds($bank, [(string) $journal->lines->firstWhere('account_id', $bank->account_id)?->id]);
            $entry = reset($entry) ?: throw new \LogicException('The write-off journal has no entry on the bank account.');
            $this->entryItem($match, $entry, true);
            $match->update(['writeoff_rule_id' => $rule->id, 'writeoff_journal_id' => $journal->id, 'ledger_total' => Money::str($ledgerTotal + $difference),
                'cleared_date' => max((string) $dates->max(), $entry['date'])]);
        }
        if ($lines->isNotEmpty()) {
            StatementLine::whereIn('id', $lines->pluck('id'))->update(['match_id' => $match->id]);
        }
        $this->audit->log($user, ['action' => 'BANK.MATCH', 'entityType' => 'BankMatch', 'entityId' => $match->id, 'entityNumber' => $match->number, 'newValue' => [
            'bank' => $bank->code, 'kind' => $match->kind, 'origin' => $match->origin, 'statementLines' => $lines->count(), 'ledgerLines' => count($entries), 'statementTotal' => Money::str($statementTotal),
            'ledgerTotal' => Money::str($ledgerTotal), 'documents' => array_slice(array_values(array_map(fn (array $e) => $e['document']['number'], $entries)), 0, 40),
        ] + ($rule ? ['writeOff' => ['rule' => $rule->name_ar, 'amount' => Money::str($difference), 'journal' => $journal->number]] : [])]);

        return $match;
    }

    private function item(BankMatch $match, string $side, array $values): void
    {
        BankMatchItem::create(['match_id' => $match->id, 'side' => $side] + $values);
    }

    private function entryItem(BankMatch $match, array $entry, bool $writeoff): void
    {
        $this->item($match, BankMatchItem::SIDE_LEDGER, ['journal_line_id' => $entry['id'], 'live_journal_line_id' => $entry['id'], 'journal_id' => $entry['journalId'], 'amount' => $entry['amount'],
            'item_date' => $entry['date'], 'doc_type' => $entry['document']['type'], 'doc_id' => $entry['document']['id'], 'doc_number' => $entry['document']['number'], 'label' => $entry['journalNumber'], 'is_writeoff' => $writeoff]);
    }

    /** The rule, if it may absorb this difference on this bank: usable, of the right direction, within its limit. */
    private function acceptingRule(string $ruleId, BankAccount $bank, int $difference): WriteoffRule
    {
        $rule = WriteoffRule::with('account:id,code,name_ar,name_en,is_postable,active')->whereKey($ruleId)->first()
            ?? throw AppError::notFound('BANK_RULE_NOT_FOUND', 'قاعدة التسوية غير موجودة', 'Write-off rule not found');
        if ($block = Shape::ruleBlock($rule, $rule->account)) {
            throw AppError::rule('BANK_RULE_UNUSABLE', "قاعدة «{$rule->name_ar}» لا تُستخدم الآن: {$block['ar']}", "Rule “{$rule->name_en}” cannot be used now: {$block['en']}", ['reason' => $block['code']]);
        }
        if ($rule->bank_account_id !== null && $rule->bank_account_id !== $bank->id) {
            throw AppError::rule('BANK_RULE_OTHER_BANK', "قاعدة «{$rule->name_ar}» مخصصة لحساب بنكي آخر", "Rule “{$rule->name_en}” belongs to another bank account");
        }
        $needs = $difference < 0 ? 'out' : 'in';
        if ($rule->nature !== 'both' && $rule->nature !== $needs) {
            throw AppError::rule('BANK_RULE_DIRECTION', $difference < 0 ? "قاعدة «{$rule->name_ar}» لا تقبل نقصًا في البنك عن الدفاتر" : "قاعدة «{$rule->name_ar}» لا تقبل زيادة في البنك عن الدفاتر",
                "Rule “{$rule->name_en}” does not accept a difference in this direction", ['difference' => Money::str($difference), 'nature' => $rule->nature]);
        }
        $limit = Money::cents((string) $rule->max_amount);
        if (abs($difference) > $limit) {
            throw AppError::rule('BANK_WRITEOFF_OVER_TOLERANCE', 'الفرق '.Money::human(abs($difference))." يتجاوز حد قاعدة «{$rule->name_ar}» (".Money::human($limit).') — لا يُسوّى بها؛ طابِق القيود الصحيحة أو رحّل الفرق بقيد يدوي معتمد',
                'The difference '.Money::human(abs($difference))." exceeds the limit of rule “{$rule->name_en}” (".Money::human($limit).') — match the right entries or post the difference with an approved journal',
                ['difference' => Money::str($difference), 'limit' => Money::str($limit)]);
        }

        return $rule;
    }

    /** The journal of a difference: the bank's account against the account of the rule, through the ledger service (an open period is its condition). */
    private function postWriteOff(AuthUser $user, BankMatch $match, BankAccount $bank, WriteoffRule $rule, int $difference, ?string $date, string $lineDate): Journal
    {
        $amount = Money::float(abs($difference));
        $label = $rule->label ?: $rule->name_ar;
        $bankSide = ['account' => $bank->account_id, 'description' => "{$match->number} · {$bank->name_ar}", 'referenceType' => 'BankMatch', 'referenceId' => $match->id];
        $ruleSide = ['account' => $rule->account_id, 'description' => "{$label} — {$match->number}", 'referenceType' => 'BankMatch', 'referenceId' => $match->id];

        return $this->ledger->post($user, [
            'eventKey' => "bankmatch:{$match->id}:writeoff", 'date' => $date ? Carbon::parse($date)->startOfDay() : Carbon::parse($lineDate)->startOfDay(),
            'source' => ['type' => 'BankMatch', 'id' => $match->id, 'number' => $match->number], 'memo' => "تسوية فرق مطابقة بنكية {$match->number} — {$bank->name_ar}: {$label}",
            // the bank shows less than the books: the difference is a cost; more: an income
            'lines' => $difference < 0
                ? [$ruleSide + ['debit' => $amount], $bankSide + ['credit' => $amount]]
                : [$bankSide + ['debit' => $amount], $ruleSide + ['credit' => $amount]],
        ]);
    }

    private static function alreadyMatched(array $lines, array $entries): AppError
    {
        return new AppError('CONFLICT', 'BANK_ALREADY_MATCHED', 'سطر أو مستند من المحدَّد مطابَق مسبقًا — حدّث الصفحة وأعد الاختيار', 'A chosen line or document is already matched — refresh and choose again',
            ['statementLines' => $lines, 'ledgerLines' => $entries]);
    }

    // ───────────────────────────── undo ─────────────────────────────

    /** @param  ?string  $date  the day the reversal of the write-off journal is dated (today when none; its period must be open) */
    public function undo(AuthUser $user, string $idOrNumber, string $reason, ?string $date = null): array
    {
        $reason = StatementsService::reason($reason, 'اكتب سبب فك المطابقة (5 أحرف على الأقل)');
        $match = DB::transaction(function () use ($user, $idOrNumber, $reason, $date) {
            $id = $this->find($idOrNumber)->id;
            // the same order as a confirmation: statements, their lines, then the match itself
            $lineIds = BankMatchItem::where('match_id', $id)->whereNotNull('statement_line_id')->pluck('statement_line_id')->all();
            $statements = Statement::whereIn('id', StatementLine::whereIn('id', $lineIds)->pluck('statement_id')->unique()->all())->orderBy('id')->lockForUpdate()->get();
            StatementLine::whereIn('id', $lineIds)->orderBy('id')->lockForUpdate()->get(['id']);
            $match = $this->find($id, lock: true);
            if ($match->status !== 'active') {
                throw AppError::rule('BANK_MATCH_STATE', "المطابقة {$match->number} مفكوكة مسبقًا", "Match {$match->number} is already undone", ['status' => $match->status]);
            }
            if ($match->kind === 'system') {
                throw AppError::rule('BANK_MATCH_SYSTEM', "المطابقة {$match->number} قيد تسوية معكوس مع عكسه — من عمل النظام ولا تُفك", "Match {$match->number} holds a reversed write-off journal with its reversal — made by the system, it is not undone");
            }
            foreach ($statements as $statement) {
                $this->statements->assertEditable($statement);
            }
            BankMatchItem::where('match_id', $match->id)->update(['live_statement_line_id' => null, 'live_journal_line_id' => null]);
            StatementLine::where('match_id', $match->id)->update(['match_id' => null]);
            $reversal = null;
            if ($match->writeoff_journal_id) {
                $journal = Journal::whereKey($match->writeoff_journal_id)->first();
                // reversed, never deleted: the ledger keeps both journals and the bank account is back to what it was.
                // A journal someone already reversed in the ledger is not reversed twice: its own reversal is taken.
                $reversal = $journal?->status === 'posted'
                    ? $this->ledger->reverse($user, $journal->id, "فك مطابقة {$match->number}: {$reason}", $date)
                    : ($journal?->reversed_by_id ? Journal::with('lines')->whereKey($journal->reversed_by_id)->first() : null);
                if ($reversal) {
                    $this->holdTogether($user, $match, $reversal);
                }
            }
            $match->update(['status' => 'undone', 'undone_by' => $user->username, 'undone_at' => now(), 'undo_reason' => $reason, 'writeoff_reversal_journal_id' => $reversal?->id]);
            $this->audit->log($user, ['action' => 'BANK.UNMATCH', 'entityType' => 'BankMatch', 'entityId' => $match->id, 'entityNumber' => $match->number,
                'newValue' => ['reason' => $reason, 'statementLines' => count($lineIds)] + ($reversal ? ['writeOffReversed' => $reversal->number] : [])]);
            $this->audit->status($user, 'BankMatch', $match->id, $match->number, 'active', 'undone', $reason);

            return $match;
        });

        return $this->shape($user, $match);
    }

    /**
     * The entry of an undone write-off journal on the bank's account and the entry of its reversal cancel each other
     * and never were a movement of the bank: they are held together as a `system` match.
     */
    private function holdTogether(AuthUser $user, BankMatch $undone, Journal $reversal): void
    {
        $bank = $this->banks->find($undone->bank_account_id);
        $original = JournalLine::where('journal_id', $undone->writeoff_journal_id)->where('account_id', $bank->account_id)->value('id');
        $mirror = $reversal->lines->firstWhere('account_id', $bank->account_id)?->id;
        $entries = $this->books->byIds($bank, array_filter([(string) $original, (string) $mirror]));
        // both entries must be free (a reversal someone made in the ledger may have been matched by hand since)
        if (count($entries) !== 2 || array_filter($entries, fn (array $e) => $e['matched'])) {
            return;
        }
        $pair = BankMatch::create([
            'number' => $this->numbering->next('BMT'), 'bank_account_id' => $bank->id, 'status' => 'active', 'kind' => 'system', 'origin' => 'system', 'statement_total' => '0.00', 'ledger_total' => '0.00',
            'writeoff_amount' => '0.00', 'cleared_date' => max(array_column($entries, 'date')), 'note' => "قيد تسوية المطابقة {$undone->number} وعكسه", 'created_by' => $user->username, 'created_by_id' => $user->id,
        ]);
        foreach ($entries as $entry) {
            $this->entryItem($pair, $entry, true);
        }
    }

    // ───────────────────────────── the safe suggestions of a statement, confirmed in one go ─────────────────────────────

    /**
     * Confirms, for every unmatched line of an open statement, the suggestion that leaves no doubt (see
     * SuggestionService: an equal amount named by its reference, or the only equal amount on both sides). Each is an
     * ordinary confirmation — same locks, same audit — and what was not certain is left for a person.
     *
     * @return array{matched: array<int, array{lineNo:int, match:string, documents:string[]}>, left:int, statement:array}
     */
    public function auto(AuthUser $user, string $idOrNumber, int $window): array
    {
        $statement = $this->statements->find($idOrNumber);
        $this->statements->assertEditable($statement);
        $bank = $this->banks->find((string) $statement->bank_account_id);
        $done = [];
        $lines = StatementLine::where('statement_id', $statement->id)->whereNull('match_id')->where('kind', StatementLine::KIND_LINE)->orderBy('line_no')->orderBy('id')->limit(2000)->get();
        foreach ($lines as $line) {
            $best = collect($this->suggestions->compute($line, $bank, $window))->first(fn (array $s) => $s['safe']);
            if (! $best) {
                continue;
            }
            try {
                $match = DB::transaction(fn () => $this->confirmLocked($user, $best['proposal']['statementLines'], $best['proposal']['ledgerLines'], ['origin' => 'auto']));
                $done[] = ['lineNo' => $line->line_no, 'match' => $match->number, 'documents' => array_values(array_map(fn (array $e) => (string) $e['document']['number'], $best['ledgerLines']))];
            } catch (AppError|UniqueConstraintViolationException) {
                // taken in the meantime by another line or another person: left for a person
            }
        }

        return ['matched' => $done, 'left' => StatementLine::where('statement_id', $statement->id)->whereNull('match_id')->count(), 'statement' => $this->statements->detail($user, $statement->refresh())];
    }
}
