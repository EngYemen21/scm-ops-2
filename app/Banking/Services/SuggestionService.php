<?php

namespace App\Banking\Services;

use App\Banking\Models\Statement;
use App\Banking\Models\StatementLine;
use App\Banking\Models\WriteoffRule;
use App\Banking\Support\Mask;
use App\Banking\Support\Money;
use App\Banking\Support\Shape;
use App\Banking\Support\Sheet\Cells;
use App\Finance\Models\BankAccount;
use App\Support\AppError;
use App\Support\AuthUser;
use Carbon\Carbon;

/**
 * What a statement line may be matched with — proposed, never decided. For one line the unmatched entries of the
 * bank's ledger account (receipts, payments, expenses, journals) are weighed by four things a person would look at:
 * the amount, the bank's reference or the document number in the line's text, the party's name in it, and how many
 * days lie between the two. Every proposal says why (`reasons`) and carries the exact body of the confirmation.
 *
 * Kinds: exact (one entry of the same amount) · many (several entries that add up to the line — one transfer paying
 * several documents) · grouped (this line and other lines that add up to one entry — one document paid in parts) ·
 * writeoff (one entry and a difference a rule accepts) · rule (the whole line by a rule whose words it carries: a bank
 * charge) · opening (the opening balance against the entries before the first statement).
 *
 * `safe` marks the only proposals the "match the certain ones" action confirms without a person choosing: the same
 * amount AND the only entry named by its reference, or the only entry of that amount in the window facing the only
 * line of that amount. Everything is deterministic: the same data gives the same proposals in the same order.
 */
class SuggestionService
{
    public const DEFAULT_WINDOW = 7;

    public const MAX_WINDOW = 60;

    /** An entry named by its reference in the line's text is found this many days away, whatever the window. */
    private const REFERENCE_REACH = 45;

    /** How many entries / lines a combination is looked for in, how many it may hold, and how long the search may run. */
    private const POOL = 18;

    private const MAX_GROUP = 6;

    private const NODE_BUDGET = 60000;

    private const KIND_ORDER = ['exact' => 0, 'opening' => 1, 'many' => 2, 'grouped' => 3, 'writeoff' => 4, 'rule' => 5];

    public function __construct(private readonly Banks $banks, private readonly BookItems $books) {}

    public function forLine(AuthUser $user, string $lineId, int $window): array
    {
        $line = StatementLine::with(['statement:id,number,status,bank_account_id', 'match:id,number,kind,cleared_date'])->whereKey($lineId)->first()
            ?? throw AppError::notFound('BANK_LINE_NOT_FOUND', 'سطر الكشف غير موجود', 'Statement line not found');
        $full = Mask::sees($user);
        $out = ['line' => Shape::line($line, $full), 'window' => $window, 'suggestions' => [], 'rules' => [], 'openingHint' => null];
        if ($line->match_id !== null || $line->bank_account_id === null || ! in_array($line->statement?->status, Statement::LIVE, true)) {
            return $out;   // a matched line, or a line of a void / history statement, has nothing to be proposed
        }
        $bank = $this->banks->find((string) $line->bank_account_id);
        $cents = Money::cents((string) $line->amount);
        $out['suggestions'] = array_map(fn (array $s) => $this->present($s, $full), $this->compute($line, $bank, $window));
        // the rules a person may book the WHOLE line with (a charge, an interest) when no entry of the books answers it
        foreach ($this->rules($bank) as $rule) {
            $out['rules'][] = Shape::rule($rule) + ['fitsLine' => $line->kind === StatementLine::KIND_LINE && self::accepts($rule, $cents)];
        }
        if ($line->kind === StatementLine::KIND_OPENING) {
            $before = $this->books->unmatchedUpTo($bank, $line->txn_date->toDateString(), 0);
            $out['openingHint'] = ['entries' => $before['count'], 'total' => Money::str($before['total']), 'difference' => Money::str($cents - $before['total'])];
        }

        return $out;
    }

    /**
     * The proposals for one unmatched line, best first.
     *
     * @return array<int, array{kind:string, score:int, safe:bool, reasons:array, lines:StatementLine[], ledgerLines:array, rule:?WriteoffRule, proposal:array}>
     */
    public function compute(StatementLine $line, BankAccount $bank, int $window): array
    {
        $window = max(0, min(self::MAX_WINDOW, $window));
        $amount = Money::cents((string) $line->amount);
        $date = $line->txn_date->toDateString();
        if ($amount === 0) {
            return [];
        }
        if ($line->kind === StatementLine::KIND_OPENING) {
            return $this->opening($line, $bank, $amount, $date);
        }
        $reach = max($window, self::REFERENCE_REACH);
        $day = Carbon::parse($date);
        $text = Cells::norm(implode(' ', array_filter([$line->reference, $line->description, $line->partner_name])));
        $key = Mask::key($text) ?? '';
        $candidates = [];
        foreach ($this->books->candidates($bank, $amount <=> 0, $day->copy()->subDays($reach)->toDateString(), $day->copy()->addDays($reach)->toDateString(), $date) as $entry) {
            if ($entry['pair']['free'] ?? false) {
                continue;   // a voided document and its reversal cancel each other: they never reached the bank
            }
            $days = (int) abs($day->diffInDays(Carbon::parse($entry['date'])));
            $candidates[] = ['entry' => $entry, 'cents' => Money::cents($entry['amount']), 'days' => $days, 'in' => $days <= $window, 'reference' => self::referenceHit($entry, $key), 'party' => self::partyHit($entry, $text, $line)];
        }
        $rules = $this->rules($bank);
        $out = array_merge(
            $this->exact($line, $bank, $amount, $candidates, $window),
            $this->withWriteOff($line, $amount, $candidates, $rules, $window),
            $this->byRule($line, $amount, $text, $rules),
            $this->many($line, $amount, $candidates, $window),
            $this->grouped($line, $bank, $amount, $candidates, $window),
        );
        usort($out, fn (array $a, array $b) => [$b['score'], self::KIND_ORDER[$a['kind']], $a['proposal']['ledgerLines'][0] ?? '', count($a['proposal']['ledgerLines'])]
            <=> [$a['score'], self::KIND_ORDER[$b['kind']], $b['proposal']['ledgerLines'][0] ?? '', count($b['proposal']['ledgerLines'])]);

        return array_slice($out, 0, 12);
    }

    // ───────────────────────────── the kinds of proposal ─────────────────────────────

    /** One entry of exactly the line's amount: inside the window, or named by its reference further away. */
    private function exact(StatementLine $line, BankAccount $bank, int $amount, array $candidates, int $window): array
    {
        $equal = array_values(array_filter($candidates, fn (array $c) => $c['cents'] === $amount && ($c['in'] || $c['reference'] !== null)));
        if (! $equal) {
            return [];
        }
        $named = count(array_filter($equal, fn (array $c) => $c['reference'] !== null));
        $inWindow = count(array_filter($equal, fn (array $c) => $c['in']));
        $onlyLine = null;
        $out = [];
        foreach ($equal as $c) {
            $unique = $named === 0 && $inWindow === 1 && $c['in'];
            if ($unique) {
                $onlyLine ??= $this->onlyLineOfThatAmount($line, $bank, $amount, $window);
            }
            $reasons = array_merge([['key' => 'amount']], self::why($c, $window), $unique && $onlyLine ? [['key' => 'unique']] : []);
            $out[] = $this->proposal('exact', 50 + self::weight($c, $window), ($c['reference'] !== null && $named === 1) || ($unique && $onlyLine === true), $reasons, [$line], [$c['entry']], null);
        }

        return $out;
    }

    /** One entry and a difference a write-off rule accepts (a fee the bank kept, a rounding). */
    private function withWriteOff(StatementLine $line, int $amount, array $candidates, array $rules, int $window): array
    {
        $out = [];
        foreach ($candidates as $c) {
            $difference = $amount - $c['cents'];
            if ($difference === 0 || ! ($c['in'] || $c['reference'] !== null)) {
                continue;
            }
            // a difference is only worth proposing when it is small beside the amount, tiny in itself, or the entry is named
            if (! (abs($difference) * 20 <= abs($amount) || abs($difference) <= 100 || $c['reference'] !== null)) {
                continue;
            }
            foreach ($rules as $rule) {
                if (self::accepts($rule, $difference)) {
                    $out[] = $this->proposal('writeoff', 30 + self::weight($c, $window), false,
                        array_merge(self::why($c, $window), [['key' => 'rule', 'value' => $rule->name_ar, 'valueEn' => $rule->name_en, 'difference' => Money::str($difference)]]), [$line], [$c['entry']], $rule);

                    break;   // the first rule in order that accepts it
                }
            }
        }
        usort($out, fn (array $a, array $b) => $b['score'] <=> $a['score'] ?: strcmp($a['proposal']['ledgerLines'][0], $b['proposal']['ledgerLines'][0]));

        return array_slice($out, 0, 5);
    }

    /** The whole line by a rule whose words its text carries (and whose limit and direction accept it). */
    private function byRule(StatementLine $line, int $amount, string $text, array $rules): array
    {
        $out = [];
        foreach ($rules as $rule) {
            if (! self::accepts($rule, $amount)) {
                continue;
            }
            foreach ($rule->keywords() as $word) {
                if (str_contains($text, $word)) {
                    $out[] = $this->proposal('rule', 65, false, [['key' => 'keyword', 'value' => $word], ['key' => 'rule', 'value' => $rule->name_ar, 'valueEn' => $rule->name_en, 'difference' => Money::str($amount)]], [$line], [], $rule);

                    break;
                }
            }
        }

        return array_slice($out, 0, 3);
    }

    /** Several entries inside the window that add up to the line. */
    private function many(StatementLine $line, int $amount, array $candidates, int $window): array
    {
        $pool = array_values(array_filter($candidates, fn (array $c) => $c['in'] && abs($c['cents']) < abs($amount)));
        usort($pool, fn (array $a, array $b) => [$a['days'], -abs($a['cents']), $a['entry']['id']] <=> [$b['days'], -abs($b['cents']), $b['entry']['id']]);
        $pool = array_slice($pool, 0, self::POOL);
        $out = [];
        foreach (self::subsets(array_map(fn (array $c) => abs($c['cents']), $pool), abs($amount), 2, self::MAX_GROUP, 12) as $indexes) {
            $picked = array_map(fn (int $i) => $pool[$i], $indexes);
            $parties = array_unique(array_map(fn (array $c) => $c['entry']['party']['code'] ?? '', $picked));
            $sameParty = count($parties) === 1 && $parties[0] !== '';
            $named = count(array_filter($picked, fn (array $c) => $c['reference'] !== null));
            $hit = (bool) array_filter($picked, fn (array $c) => $c['party'] !== null);
            $days = max(array_column($picked, 'days'));
            $reasons = [['key' => 'sum', 'value' => count($picked)]];
            if ($sameParty) {
                $reasons[] = ['key' => 'sameParty', 'value' => $picked[0]['entry']['party']['nameAr'] ?? null, 'valueEn' => $picked[0]['entry']['party']['nameEn'] ?? null];
            }
            if ($named) {
                $reasons[] = ['key' => 'reference', 'value' => $picked[array_key_first(array_filter($picked, fn (array $c) => $c['reference'] !== null))]['reference']];
            }
            if ($hit) {
                $reasons[] = ['key' => 'party'];
            }
            $reasons[] = ['key' => 'date', 'days' => $days];
            $out[] = $this->proposal('many', 38 + ($sameParty ? 14 : 0) + ($named ? 14 : 0) + ($hit ? 6 : 0) + self::dateWeight($days, $window) - (count($picked) - 2), false, $reasons, [$line], array_column($picked, 'entry'), null);
        }
        usort($out, fn (array $a, array $b) => $b['score'] <=> $a['score'] ?: count($a['ledgerLines']) <=> count($b['ledgerLines']) ?: strcmp(implode(',', $a['proposal']['ledgerLines']), implode(',', $b['proposal']['ledgerLines'])));

        return array_slice($out, 0, 3);
    }

    /** This line and other unmatched lines of the bank that add up to one larger entry. */
    private function grouped(StatementLine $line, BankAccount $bank, int $amount, array $candidates, int $window): array
    {
        $larger = array_values(array_filter($candidates, fn (array $c) => $c['in'] && abs($c['cents']) > abs($amount)));
        if (! $larger) {
            return [];
        }
        $date = $line->txn_date->toDateString();
        $day = Carbon::parse($date);
        $others = StatementLine::where('bank_account_id', $bank->id)->whereNull('match_id')->where('kind', StatementLine::KIND_LINE)->where('id', '!=', $line->id)
            ->whereIn('statement_id', Statement::where('bank_account_id', $bank->id)->where('status', 'open')->select('id'))
            ->whereBetween('txn_date', [$day->copy()->subDays($window)->toDateString(), $day->copy()->addDays($window)->toDateString()])
            ->where('amount', $amount > 0 ? '>' : '<', 0)->orderBy('txn_date')->orderBy('id')->limit(200)->with('statement:id,number')->get()->all();
        usort($others, fn (StatementLine $a, StatementLine $b) => [abs($day->diffInDays($a->txn_date)), $a->id] <=> [abs($day->diffInDays($b->txn_date)), $b->id]);
        $others = array_slice($others, 0, self::POOL);
        if (! $others) {
            return [];
        }
        $values = array_map(fn (StatementLine $l) => abs(Money::cents((string) $l->amount)), $others);
        $out = [];
        foreach ($larger as $c) {
            foreach (self::subsets($values, abs($c['cents']) - abs($amount), 1, self::MAX_GROUP - 1, 3) as $indexes) {
                $lines = array_merge([$line], array_map(fn (int $i) => $others[$i], $indexes));
                $out[] = $this->proposal('grouped', 38 + ($c['reference'] !== null ? 14 : 0) + ($c['party'] !== null ? 6 : 0) + self::dateWeight($c['days'], $window) - (count($lines) - 2), false,
                    array_merge([['key' => 'sum', 'value' => count($lines)]], self::why($c, $window)), $lines, [$c['entry']], null);
            }
        }
        usort($out, fn (array $a, array $b) => $b['score'] <=> $a['score'] ?: count($a['lines']) <=> count($b['lines']) ?: strcmp($a['proposal']['ledgerLines'][0].implode(',', $a['proposal']['statementLines']), $b['proposal']['ledgerLines'][0].implode(',', $b['proposal']['statementLines'])));

        return array_slice($out, 0, 3);
    }

    /** The opening balance of the first statement: one entry of that amount, or every entry booked before the statement when together they are that amount. */
    private function opening(StatementLine $line, BankAccount $bank, int $amount, string $date): array
    {
        $before = $this->books->unmatchedUpTo($bank, $date, MatchingService::MAX_SIDE);
        $out = [];
        foreach ($before['items'] as $entry) {
            if (Money::cents($entry['amount']) === $amount && ! ($entry['pair']['free'] ?? false)) {
                $out[] = $this->proposal('exact', $entry['kind'] === 'journal' ? 85 : 75, false, [['key' => 'amount']], [$line], [$entry], null);
            }
        }
        if ($before['count'] > 1 && $before['count'] <= MatchingService::MAX_SIDE && $before['total'] === $amount) {
            $out[] = $this->proposal('opening', 80, false, [['key' => 'sum', 'value' => $before['count']], ['key' => 'allBefore']], [$line], $before['items'], null);
        }
        usort($out, fn (array $a, array $b) => $b['score'] <=> $a['score'] ?: strcmp($a['proposal']['ledgerLines'][0], $b['proposal']['ledgerLines'][0]));

        return array_slice($out, 0, 6);
    }

    // ───────────────────────────── weighing ─────────────────────────────

    /** The reference, the document number or the journal number of an entry, when the line's text carries it. */
    private static function referenceHit(array $entry, string $key): ?string
    {
        if ($key === '') {
            return null;
        }
        foreach ([$entry['reference'], $entry['document']['number'] ?? null, $entry['journalNumber']] as $value) {
            $needle = Mask::key(is_string($value) ? $value : null);
            // short strings of digits are found inside any number: a reference counts from four characters, five when it is digits only
            if ($needle !== null && strlen($needle) >= (ctype_digit($needle) ? 5 : 4) && str_contains($key, $needle)) {
                return (string) $value;
            }
        }

        return null;
    }

    /** The party of an entry, when the line names it (in its text or as its counterparty). */
    private static function partyHit(array $entry, string $text, StatementLine $line): ?string
    {
        $partner = Cells::norm($line->partner_name);
        foreach ([$entry['party']['nameAr'] ?? null, $entry['party']['nameEn'] ?? null] as $name) {
            $name = Cells::norm($name);
            if (mb_strlen($name) >= 4 && (str_contains($text, $name) || ($partner !== '' && mb_strlen($partner) >= 4 && str_contains($name, $partner)))) {
                return $name;
            }
        }

        return null;
    }

    private static function weight(array $c, int $window): int
    {
        return ($c['reference'] !== null ? 30 : 0) + ($c['party'] !== null ? 10 : 0) + self::dateWeight($c['days'], $window);
    }

    private static function dateWeight(int $days, int $window): int
    {
        return match (true) {
            $days === 0 => 10, $days <= 1 => 8, $days <= 3 => 6, $days <= $window => 3, default => 0,
        };
    }

    private static function why(array $c, int $window): array
    {
        $out = [];
        if ($c['reference'] !== null) {
            $out[] = ['key' => 'reference', 'value' => $c['reference']];
        }
        if ($c['party'] !== null) {
            $out[] = ['key' => 'party', 'value' => $c['entry']['party']['nameAr'] ?? null, 'valueEn' => $c['entry']['party']['nameEn'] ?? null];
        }
        $out[] = ['key' => 'date', 'days' => $c['days'], 'outside' => $c['days'] > $window];

        return $out;
    }

    /** No other unmatched line of the bank shows that amount within the window: the entry cannot belong to another line. */
    private function onlyLineOfThatAmount(StatementLine $line, BankAccount $bank, int $amount, int $window): bool
    {
        $day = Carbon::parse($line->txn_date->toDateString());

        return ! StatementLine::where('bank_account_id', $bank->id)->whereNull('match_id')->where('kind', StatementLine::KIND_LINE)->where('id', '!=', $line->id)->where('amount', Money::str($amount))
            ->whereIn('statement_id', Statement::where('bank_account_id', $bank->id)->whereIn('status', Statement::LIVE)->select('id'))
            ->whereBetween('txn_date', [$day->copy()->subDays($window)->toDateString(), $day->copy()->addDays($window)->toDateString()])->exists();
    }

    /** The rules that may post on this bank right now, in their order. @return WriteoffRule[] */
    private function rules(BankAccount $bank): array
    {
        return WriteoffRule::with(['account:id,code,name_ar,name_en,is_postable,active', 'bankAccount:id,code,name_ar,name_en,kind'])->where('active', true)->where('kind', 'writeoff')
            ->where(fn ($w) => $w->whereNull('bank_account_id')->orWhere('bank_account_id', $bank->id))->orderBy('sequence')->orderBy('created_at')->orderBy('id')->get()
            ->filter(fn (WriteoffRule $r) => Shape::ruleBlock($r, $r->account) === null)->values()->all();
    }

    /** Would the rule absorb this amount (a difference, or a whole line): its direction and its limit. */
    public static function accepts(WriteoffRule $rule, int $cents): bool
    {
        return $cents !== 0 && $rule->max_amount !== null && abs($cents) <= Money::cents((string) $rule->max_amount) && ($rule->nature === 'both' || $rule->nature === ($cents < 0 ? 'out' : 'in'));
    }

    /**
     * The groups of values that add up to the target. Values are positive hundredths; the search goes from the largest
     * down, stops a branch as soon as what is left cannot reach the target, and stops altogether after a fixed number
     * of steps — a proposal is a help, not a proof.
     *
     * @param  int[]  $values
     * @return array<int, int[]> lists of indexes into `$values`
     */
    public static function subsets(array $values, int $target, int $minSize, int $maxSize, int $limit): array
    {
        if ($target <= 0 || ! $values) {
            return [];
        }
        $order = array_keys($values);
        usort($order, fn (int $x, int $y) => $values[$y] <=> $values[$x] ?: $x <=> $y);
        $n = count($order);
        $rest = array_fill(0, $n + 1, 0);
        for ($i = $n - 1; $i >= 0; $i--) {
            $rest[$i] = $rest[$i + 1] + $values[$order[$i]];
        }
        $found = [];
        $picked = [];
        $steps = 0;
        $walk = function (int $i, int $left) use (&$walk, &$found, &$picked, &$steps, $values, $order, $rest, $n, $minSize, $maxSize, $limit): void {
            if (count($found) >= $limit || ++$steps > self::NODE_BUDGET) {
                return;
            }
            if ($left === 0) {
                if (count($picked) >= $minSize) {
                    $found[] = $picked;
                }

                return;
            }
            if ($i >= $n || count($picked) >= $maxSize || $rest[$i] < $left) {
                return;
            }
            $value = $values[$order[$i]];
            if ($value <= $left) {
                $picked[] = $order[$i];
                $walk($i + 1, $left - $value);
                array_pop($picked);
            }
            $walk($i + 1, $left);
        };
        $walk(0, $target);

        return $found;
    }

    // ───────────────────────────── shapes ─────────────────────────────

    /**
     * @param  StatementLine[]  $lines
     * @param  array<int, array<string, mixed>>  $entries  shaped ledger entries
     */
    private function proposal(string $kind, int $score, bool $safe, array $reasons, array $lines, array $entries, ?WriteoffRule $rule): array
    {
        return [
            'kind' => $kind, 'score' => max(1, min(100, $score)), 'safe' => $safe, 'reasons' => array_values($reasons), 'lines' => array_values($lines), 'ledgerLines' => array_values($entries), 'rule' => $rule,
            'proposal' => ['statementLines' => array_values(array_map(fn (StatementLine $l) => (string) $l->id, $lines)), 'ledgerLines' => array_values(array_map(fn (array $e) => (string) $e['id'], $entries))]
                + ($rule ? ['writeOff' => ['rule' => $rule->id]] : []),
        ];
    }

    private function present(array $s, bool $full): array
    {
        $statementTotal = Money::sum(array_map(fn (StatementLine $l) => (string) $l->amount, $s['lines']));
        $ledgerTotal = array_sum(array_map(fn (array $e) => Money::cents($e['amount']), $s['ledgerLines']));

        return [
            'kind' => $s['kind'], 'score' => $s['score'], 'safe' => $s['safe'], 'reasons' => $s['reasons'],
            'statementLines' => array_map(fn (StatementLine $l) => Shape::line($l, $full), $s['lines']), 'ledgerLines' => $s['ledgerLines'],
            'statementTotal' => Money::str($statementTotal), 'ledgerTotal' => Money::str($ledgerTotal), 'difference' => Money::str($statementTotal - $ledgerTotal),
            'rule' => $s['rule'] ? ['id' => $s['rule']->id, 'nameAr' => $s['rule']->name_ar, 'nameEn' => $s['rule']->name_en, 'maxAmount' => Money::out($s['rule']->max_amount)] : null,
            'proposal' => $s['proposal'],
        ];
    }
}
