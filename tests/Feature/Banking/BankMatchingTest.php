<?php

namespace Tests\Feature\Banking;

use App\Banking\Models\BankMatch;
use App\Banking\Models\BankMatchItem;
use App\Banking\Models\StatementLine;
use App\Finance\Models\Journal;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * Matching a statement with the books, one story on a bank account of its own:
 *
 *   receipts R1 1,500.00 (ref INV-REF-1001) · R2 300.00 + R3 200.00 · R4 1,000.00 · R5 1,000.00 · R6 4,900.00 ·
 *   expenses E1 120.50 · E2 300.00 · E3 300.00 (never reaches the bank) · R7 250.00 received then voided
 *
 *   L1 +1,500.00 (names INV-REF-1001) → R1 exact, the safe proposal     L6 +997.50 → R5 and a 2.50 bank charge written off
 *   L2 +500.00 → R2 + R3 (one transfer, many documents)                 L7 −15.00 "رسوم" → wholly by the charges rule
 *   L3 +600.00 + L4 +400.00 → R4 (one document paid in parts)           L8 +5,000.00 → R6 and a 100.00 difference (over a
 *   L5 −120.50 → E1, confirmed by "match the certain ones"                 50.00 limit: refused; closed period: refused)
 *   L9 −300.00 → E2                                                      R7 and its reversal cancel each other (contra)
 *
 * Proven: every match equal to the cent (a difference is posted by a rule within its limit and direction, or refused),
 * never twice (409, also when a stale reader slips past the code — the database refuses), undo restores the previous
 * state and reverses the write-off journal, a closed period refuses, the lock, and the reconciliation statement at a date
 * explained to zero.
 */
class BankMatchingTest extends ApiTestCase
{
    use BankingFixtures;

    private static array $s = [];

    private function line(int $no): string
    {
        return self::$s['lines'][$no];
    }

    private function entry(string $key): string
    {
        return self::$s['entries'][$key];
    }

    public function test_01_the_books_and_the_bank(): void
    {
        $bank = $this->newBank('BM')['code'];
        $customer = $this->customerCode();
        self::$s['bank'] = $bank;
        $docs = [
            'R1' => $this->receipt($bank, $customer, 1500, $this->day(-20), 'INV-REF-1001'),
            'R2' => $this->receipt($bank, $customer, 300, $this->day(-18)),
            'R3' => $this->receipt($bank, $customer, 200, $this->day(-18)),
            'R4' => $this->receipt($bank, $customer, 1000, $this->day(-15)),
            'E1' => $this->expense($bank, 120.50, $this->day(-12), 'رسوم صيانة'),
            'R5' => $this->receipt($bank, $customer, 1000, $this->day(-10)),
            'R6' => $this->receipt($bank, $customer, 4900, $this->day(-3)),
            'E2' => $this->expense($bank, 300, $this->day(-2), 'إيجار مخزن'),
            'E3' => $this->expense($bank, 300, $this->day(-2), 'شيك لم يُصرف'),
            'R7' => $this->receipt($bank, $customer, 250, $this->day(-7)),
        ];
        foreach ($docs as $key => $doc) {
            self::$s['entries'][$key] = $this->entryOf($bank, $doc['number'])['id'];
            self::$s['docs'][$key] = $doc;
        }
        $entry = $this->entryOf($bank, $docs['R1']['number']);
        $this->assertSame(['receipt', '1500.00', 'in', 'INV-REF-1001', false], [$entry['kind'], $entry['amount'], $entry['direction'], $entry['reference'], $entry['matched']]);
        $this->assertSame('expense', $this->entryOf($bank, $docs['E1']['number'])['kind']);
        $this->assertSame('-120.50', $this->entryOf($bank, $docs['E1']['number'])['amount']);

        $s = $this->newStatement($bank, $this->day(-21), $this->day(-1), '0.00', '8562.00');
        self::$s['statement'] = $s['number'];
        $this->assertFalse($s['totals']['hasOpeningLine'], 'a first statement that opens at zero needs no opening line');
        $lines = $this->addLines($s['number'], [
            ['date' => $this->day(-19), 'amount' => '1500.00', 'description' => 'تحويل وارد INV-REF-1001'],
            ['date' => $this->day(-17), 'amount' => '500.00', 'description' => 'تحويل وارد'],
            ['date' => $this->day(-14), 'amount' => '600.00', 'description' => 'إيداع جزئي'],
            ['date' => $this->day(-13), 'amount' => '400.00', 'description' => 'إيداع جزئي'],
            ['date' => $this->day(-11), 'amount' => '-120.50', 'description' => 'خصم'],
            ['date' => $this->day(-9), 'amount' => '997.50', 'description' => 'تحويل وارد بعد الرسوم'],
            ['date' => $this->day(-5), 'amount' => '-15.00', 'description' => 'رسوم خدمة الحساب'],
            ['date' => $this->day(-3), 'amount' => '5000.00', 'description' => 'إيداع'],
            ['date' => $this->day(-2), 'amount' => '-300.00', 'description' => 'شيك'],
        ]);
        self::$s['lines'] = array_combine(range(1, 9), array_column($lines, 'id'));
        $this->assertTrue($this->statement($s['number'])['totals']['balanced']);
    }

    public function test_02_an_exact_amount_named_by_its_reference_is_the_safe_proposal(): void
    {
        $out = $this->expectOk($this->getAs('gm', '/api/banking/lines/'.$this->line(1).'/suggestions'));
        $best = $out['suggestions'][0];
        $this->assertSame('exact', $best['kind']);
        $this->assertTrue($best['safe']);
        $this->assertContains('reference', array_column($best['reasons'], 'key'));
        $this->assertSame([$this->entry('R1')], $best['proposal']['ledgerLines']);
        $this->assertSame('0.00', $best['difference']);

        // gm reads proposals but does not confirm them
        $this->expectRejected($this->postAs('gm', '/api/banking/matches', $best['proposal']), 'FORBIDDEN', [403]);
        $m = $this->match($best['proposal']['statementLines'], $best['proposal']['ledgerLines'], ['origin' => 'suggestion', 'note' => 'تحويل العميل']);
        self::$s['m1'] = $m['number'];
        $this->assertSame(['active', 'match', 'suggestion', '1500.00', '1500.00', null], [$m['status'], $m['kind'], $m['origin'], $m['statementTotal'], $m['ledgerTotal'], $m['writeOff']]);
        $this->assertSame($this->day(-19), $m['clearedDate'], 'the day the match was complete: its latest side');
        $this->assertTrue($this->expectOk($this->getAs('finance', '/api/banking/lines/'.$this->line(1)))['matched']);
        $this->assertTrue($this->entryOf(self::$s['bank'], self::$s['docs']['R1']['number'])['matched']);
        $this->assertSame(1, AuditLog::where('action', 'BANK.MATCH')->where('entity_number', $m['number'])->count());

        // never twice: not the line, not the document
        $this->expectRejected($this->postAs('finance', '/api/banking/matches', ['statementLines' => [$this->line(1)], 'ledgerLines' => [$this->entry('R4')]]), 'BANK_ALREADY_MATCHED', [409]);
        $this->expectRejected($this->postAs('finance', '/api/banking/matches', ['statementLines' => [$this->line(8)], 'ledgerLines' => [$this->entry('R1')]]), 'BANK_ALREADY_MATCHED', [409]);
        $this->assertSame(1, BankMatch::where('bank_account_id', BankMatch::where('number', $m['number'])->value('bank_account_id'))->count());
    }

    public function test_03_one_line_to_many_documents_and_many_lines_to_one(): void
    {
        $many = collect($this->expectOk($this->getAs('finance', '/api/banking/lines/'.$this->line(2).'/suggestions'))['suggestions'])->firstWhere('kind', 'many');
        $this->assertNotNull($many);
        $this->assertEqualsCanonicalizing([$this->entry('R2'), $this->entry('R3')], $many['proposal']['ledgerLines']);
        $this->assertContains('sameParty', array_column($many['reasons'], 'key'));
        $m2 = $this->match($many['proposal']['statementLines'], $many['proposal']['ledgerLines']);
        $this->assertSame(['500.00', '500.00'], [$m2['statementTotal'], $m2['ledgerTotal']]);
        $this->assertCount(2, $m2['ledgerLines']);

        $grouped = collect($this->expectOk($this->getAs('finance', '/api/banking/lines/'.$this->line(3).'/suggestions'))['suggestions'])
            ->first(fn (array $s) => $s['kind'] === 'grouped' && $s['proposal']['ledgerLines'] === [$this->entry('R4')]);
        $this->assertNotNull($grouped, 'the two partial deposits add up to R4');
        $this->assertEqualsCanonicalizing([$this->line(3), $this->line(4)], $grouped['proposal']['statementLines']);
        $m3 = $this->match($grouped['proposal']['statementLines'], $grouped['proposal']['ledgerLines']);
        $this->assertCount(2, $m3['statementLines']);
        $this->assertSame('1000.00', $m3['ledgerTotal']);

        // a group that is not equal and has no rule is refused, naming the difference — and nothing is kept
        $before = BankMatch::count();
        $res = $this->expectRejected($this->postAs('finance', '/api/banking/matches', ['statementLines' => [$this->line(6)], 'ledgerLines' => [$this->entry('R5')]]), 'BANK_MATCH_DIFFERENCE', [422]);
        $this->assertSame('-2.50', $res['details']['difference']);
        $this->assertSame($before, BankMatch::count());
        // one entry on its own cannot be a match; neither can entries of another bank
        $this->expectRejected($this->postAs('finance', '/api/banking/matches', ['ledgerLines' => [$this->entry('E3')], 'bankAccount' => self::$s['bank']]), 'BANK_MATCH_ONE_SIDED', [422]);
        $this->expectRejected($this->postAs('finance', '/api/banking/matches', []), null, [400]);
    }

    public function test_04_a_small_difference_is_written_off_by_a_rule_within_its_limit_and_direction(): void
    {
        $bank = self::$s['bank'];
        $charges = $this->newRule('رسوم بنكية '.self::uid(), '50.00', 'out', null, 'رسوم,charges');
        $income = $this->newRule('فروقات دائنة '.self::uid(), '50.00', 'in', $bank);
        self::$s['charges'] = $charges['id'];
        self::$s['income'] = $income['id'];
        $this->assertTrue($charges['usable']);
        // a rule's account must take a difference: not a bank's own ledger account, not a control account
        $glOfBank = $this->expectOk($this->getAs('finance', '/api/banking/accounts'))['items'];
        $bankGl = collect($glOfBank)->firstWhere('code', $bank)['ledgerAccount']['code'];
        $this->expectRejected($this->postAs('finance', '/api/banking/rules', ['nameAr' => 'خطأ '.self::uid(), 'account' => $bankGl, 'maxAmount' => '10']), 'BANK_RULE_ACCOUNT', [422]);
        $this->expectRejected($this->postAs('finance', '/api/banking/rules', ['nameAr' => $charges['nameAr'], 'account' => $this->expenseAccount(), 'maxAmount' => '10']), 'BANK_RULE_EXISTS', [409]);
        $this->expectRejected($this->postAs('finance', '/api/banking/rules', ['nameAr' => 'بلا حد '.self::uid(), 'account' => $this->expenseAccount(), 'maxAmount' => '0']), null, [400]);

        $proposals = $this->expectOk($this->getAs('finance', '/api/banking/lines/'.$this->line(6).'/suggestions'))['suggestions'];
        $writeoff = collect($proposals)->firstWhere('kind', 'writeoff');
        $this->assertSame($charges['id'], $writeoff['rule']['id']);
        $this->assertSame('-2.50', $writeoff['difference']);
        // the bank shows LESS than the books: a rule for differences in the bank's favour does not take it
        $this->expectRejected($this->postAs('finance', '/api/banking/matches', ['statementLines' => [$this->line(6)], 'ledgerLines' => [$this->entry('R5')], 'writeOff' => ['rule' => $income['id']]]), 'BANK_RULE_DIRECTION', [422]);

        $balanceBefore = $this->bookBalance();
        $m = $this->match([$this->line(6)], [$this->entry('R5')], ['writeOff' => ['rule' => $charges['id']]]);
        self::$s['m6'] = $m['number'];
        $this->assertSame(['997.50', '997.50', '-2.50'], [$m['statementTotal'], $m['ledgerTotal'], $m['writeOff']['amount']], 'equal to the cent: the write-off entry stands among the ledger entries');
        $this->assertCount(2, $m['ledgerLines']);
        $this->assertSame([false, true], array_column($m['ledgerLines'], 'isWriteoff'));
        $journal = Journal::with('lines')->where('number', $m['writeOff']['journalNumber'])->firstOrFail();
        $this->assertSame(['posted', 'BankMatch', $this->day(-9)], [$journal->status, $journal->source_type, $journal->journal_date->toDateString()]);
        $bankAccountId = DB::table('fin_bank_accounts')->where('code', $bank)->value('account_id');
        $this->assertEquals(2.50, (float) $journal->lines->firstWhere('account_id', $bankAccountId)->credit);
        $this->assertSame(Journal::where('number', $m['writeOff']['journalNumber'])->value('total_debit'), Journal::where('number', $m['writeOff']['journalNumber'])->value('total_credit'));
        $this->assertSame(bcsub($balanceBefore, '2.50', 2), $this->bookBalance());

        // a bank charge the books never held: the whole line by the rule whose word it carries
        $rule = collect($this->expectOk($this->getAs('finance', '/api/banking/lines/'.$this->line(7).'/suggestions'))['suggestions'])->firstWhere('kind', 'rule');
        $this->assertSame($charges['id'], $rule['rule']['id']);
        $this->assertSame([], $rule['proposal']['ledgerLines']);
        $m7 = $this->match($rule['proposal']['statementLines'], [], ['writeOff' => $rule['proposal']['writeOff']]);
        $this->assertSame(['-15.00', '-15.00', 'match'], [$m7['statementTotal'], $m7['ledgerTotal'], $m7['kind']]);
    }

    public function test_05_the_certain_ones_are_matched_in_one_go_and_the_doubtful_are_left(): void
    {
        $this->expectRejected($this->postAs($this->clerk(), '/api/banking/statements/'.self::$s['statement'].'/auto-match'), 'FORBIDDEN', [403]);
        $out = $this->expectOk($this->postAs('finance', '/api/banking/statements/'.self::$s['statement'].'/auto-match'));
        $this->assertSame([5], array_column($out['matched'], 'lineNo'), 'E1 is the only 120.50 in the window facing the only line of that amount');
        $this->assertSame([self::$s['docs']['E1']['number']], $out['matched'][0]['documents']);
        $this->assertSame(2, $out['left'], 'L8 (a difference) and L9 (two expenses of 300.00 — which one is a person\'s call)');
        $this->assertSame('auto', BankMatch::where('number', $out['matched'][0]['match'])->value('origin'));
    }

    public function test_06_a_difference_over_the_limit_or_in_a_closed_period_is_refused_and_nothing_is_kept(): void
    {
        $bank = self::$s['bank'];
        $journals = Journal::where('source_type', 'BankMatch')->count();
        $matches = BankMatch::count();
        // 5,000.00 at the bank against R6 4,900.00: 100.00 is over the 50.00 limit
        $res = $this->expectRejected($this->postAs('finance', '/api/banking/matches', ['statementLines' => [$this->line(8)], 'ledgerLines' => [$this->entry('R6')], 'writeOff' => ['rule' => self::$s['income']]]),
            'BANK_WRITEOFF_OVER_TOLERANCE', [422]);
        $this->assertSame(['100.00', '50.00'], [$res['details']['difference'], $res['details']['limit']]);
        $wide = $this->newRule('فروقات تحصيل '.self::uid(), '150.00', 'in', $bank);

        // a write-off dated in a closed period: the ledger refuses, and the match goes with it
        $other = $this->newBank('BP')['code'];
        $this->receipt($other, $this->customerCode(), 10, '2003-01-15');
        $this->expectOk($this->postAs('finance', '/api/finance/periods/2003-01/close', ['notes' => 'إقفال']));
        $this->expectRejected($this->postAs('finance', '/api/banking/matches', ['statementLines' => [$this->line(8)], 'ledgerLines' => [$this->entry('R6')], 'writeOff' => ['rule' => $wide['id'], 'date' => '2003-01-20']]), 'PERIOD_CLOSED', [422]);
        $this->assertSame([$journals, $matches], [Journal::where('source_type', 'BankMatch')->count(), BankMatch::count()], 'no write-off journal, no match');
        $this->assertNull(StatementLine::find($this->line(8))->match_id);
        $this->expectOk($this->postAs('finance', '/api/finance/periods/2003-01/reopen', ['reason' => 'إعادة فتح بعد الاختبار']));

        $m = $this->match([$this->line(8)], [$this->entry('R6')], ['writeOff' => ['rule' => $wide['id']]]);
        $this->assertSame(['5000.00', '5000.00', '100.00'], [$m['statementTotal'], $m['ledgerTotal'], $m['writeOff']['amount']]);
    }

    public function test_07_a_line_is_never_matched_twice_even_when_a_stale_reader_slips_past_the_code(): void
    {
        $m9 = $this->match([$this->line(9)], [$this->entry('E2')]);
        self::$s['m9'] = $m9['number'];
        // a second person read the line as free before the first confirmation committed: the code sees nothing, the
        // database still holds the standing match of the line (unique live_statement_line_id) and refuses the insert
        DB::table('bank_statement_lines')->where('id', $this->line(9))->update(['match_id' => null]);
        $matches = BankMatch::count();
        $items = BankMatchItem::count();
        $this->expectRejected($this->postAs('finance', '/api/banking/matches', ['statementLines' => [$this->line(9)], 'ledgerLines' => [$this->entry('E3')]]), 'BANK_ALREADY_MATCHED', [409]);
        $this->assertSame([$matches, $items], [BankMatch::count(), BankMatchItem::count()], 'the refused match left nothing behind');
        $this->assertFalse($this->entryOf(self::$s['bank'], self::$s['docs']['E3']['number'])['matched']);
        DB::table('bank_statement_lines')->where('id', $this->line(9))->update(['match_id' => BankMatch::where('number', $m9['number'])->value('id')]);
        // the same on the books' side: an entry held by a standing match is refused whatever the line says
        $this->expectRejected($this->postAs('finance', '/api/banking/matches', ['ledgerLines' => [$this->entry('E2'), $this->entry('E3')], 'bankAccount' => self::$s['bank']]), 'BANK_ALREADY_MATCHED', [409]);
        $this->assertSame(1, BankMatchItem::where('live_statement_line_id', $this->line(9))->count());
    }

    public function test_08_a_receipt_and_its_reversal_are_cleared_against_each_other(): void
    {
        $bank = self::$s['bank'];
        $this->expectOk($this->postAs('finance', '/api/finance/payments/'.self::$s['docs']['R7']['number'].'/void', ['reason' => 'شيك مرتجع من العميل']));
        $pair = $this->expectOk($this->getAs('finance', "/api/banking/ledger-items?bank={$bank}&state=unmatched&q=".urlencode(self::$s['docs']['R7']['number'])))['items'];
        $this->assertCount(2, $pair);
        $this->assertTrue($pair[0]['pair']['free']);
        // a voided document never reached the bank: it is not proposed for a line
        foreach ($this->expectOk($this->getAs('finance', '/api/banking/lines/'.$this->line(9).'/suggestions'))['suggestions'] as $s) {
            $this->assertNotContains($this->entry('R7'), $s['proposal']['ledgerLines']);
        }
        $contra = $this->match([], array_column($pair, 'id'), ['bankAccount' => $bank]);
        $this->assertSame(['contra', '0.00', '0.00'], [$contra['kind'], $contra['statementTotal'], $contra['ledgerTotal']]);
    }

    public function test_09_undo_restores_what_was_and_reverses_the_write_off_journal(): void
    {
        $m6 = self::$s['m6'];
        $balance = $this->bookBalance();
        $this->expectRejected($this->postAs('gm', "/api/banking/matches/{$m6}/undo", ['reason' => 'مطابقة خاطئة']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('finance', "/api/banking/matches/{$m6}/undo", ['reason' => 'لا']), null, [400]);
        $writeoff = Journal::where('number', $this->expectOk($this->getAs('finance', "/api/banking/matches/{$m6}"))['writeOff']['journalNumber'])->firstOrFail();

        $undone = $this->expectOk($this->postAs('finance', "/api/banking/matches/{$m6}/undo", ['reason' => 'الرسوم تخص شهرًا آخر', 'date' => $this->day(-9)]));
        $this->assertSame(['undone', 'الرسوم تخص شهرًا آخر', false], [$undone['status'], $undone['undoReason'], $undone['can']['undo']]);
        $this->assertNull(StatementLine::find($this->line(6))->match_id);
        $this->assertFalse($this->entryOf(self::$s['bank'], self::$s['docs']['R5']['number'])['matched']);
        $writeoff->refresh();
        $this->assertSame('reversed', $writeoff->status, 'reversed, never deleted');
        $reversal = Journal::findOrFail($writeoff->reversed_by_id);
        $this->assertSame($undone['writeOff']['reversalJournalNumber'], $reversal->number);
        $this->assertSame(bcadd($balance, '2.50', 2), $this->bookBalance(), 'the bank account is back to what it was');
        // the write-off and its reversal are held together and never show up as open items of their own
        $system = BankMatch::where('kind', 'system')->where('bank_account_id', BankMatch::where('number', $m6)->value('bank_account_id'))->orderByDesc('created_at')->firstOrFail();
        $this->assertEqualsCanonicalizing([$writeoff->id, $reversal->id], BankMatchItem::where('match_id', $system->id)->pluck('journal_id')->all());
        $this->expectRejected($this->postAs('finance', "/api/banking/matches/{$system->number}/undo", ['reason' => 'محاولة فك']), 'BANK_MATCH_SYSTEM', [422]);
        $this->expectRejected($this->postAs('finance', "/api/banking/matches/{$m6}/undo", ['reason' => 'مرة ثانية']), 'BANK_MATCH_STATE', [422]);
        $open = $this->expectOk($this->getAs('finance', '/api/banking/ledger-items?bank='.self::$s['bank'].'&kind=writeoff&state=unmatched'));
        $this->assertSame(0, $open['total']);

        // matched again, the way it should have been
        $again = $this->match([$this->line(6)], [$this->entry('R5')], ['writeOff' => ['rule' => self::$s['charges']]]);
        $this->assertNotSame($undone['writeOff']['journalNumber'], $again['writeOff']['journalNumber'], 'a new journal: the undone one stays reversed');
        $this->assertSame($balance, $this->bookBalance());
        $listed = $this->expectOk($this->getAs('gm', '/api/banking/matches?bank='.self::$s['bank'].'&status=undone'));
        $this->assertSame([$m6], array_column($listed['items'], 'number'));
    }

    public function test_10_a_reconciled_statement_is_locked_until_reopened_with_a_reason(): void
    {
        $s = self::$s['statement'];
        $detail = $this->statement($s);
        $this->assertSame([], $detail['blockers']);
        $this->assertTrue($detail['can']['reconcile']);
        $this->expectRejected($this->postAs($this->clerk(), "/api/banking/statements/{$s}/reconcile"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s}/void", ['reason' => 'محاولة إلغاء']), 'BANK_STATEMENT_HAS_MATCHES', [422]);
        $locked = $this->expectOk($this->postAs('finance', "/api/banking/statements/{$s}/reconcile"));
        $this->assertSame('reconciled', $locked['status']);
        $this->assertFalse($locked['can']['edit']);

        // nothing of it changes: lines, figures, matches
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s}/lines", ['date' => $this->day(-4), 'amount' => '1']), 'BANK_STATEMENT_LOCKED', [422]);
        $this->expectRejected($this->patchAs('finance', '/api/banking/lines/'.$this->line(2), ['amount' => '501']), 'BANK_STATEMENT_LOCKED', [422]);
        $this->expectRejected($this->patchAs('finance', "/api/banking/statements/{$s}", ['closingBalance' => '1']), 'BANK_STATEMENT_LOCKED', [422]);
        $this->expectRejected($this->postAs('finance', '/api/banking/matches/'.self::$s['m1'].'/undo', ['reason' => 'محاولة فك']), 'BANK_STATEMENT_LOCKED', [422]);
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s}/reconcile"), 'BANK_STATEMENT_STATE', [422]);

        // reopened by bank.reconcile with a reason only
        $this->expectRejected($this->postAs($this->clerk(), "/api/banking/statements/{$s}/reopen", ['reason' => 'تصحيح سطر']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s}/reopen", []), null, [400]);
        $open = $this->expectOk($this->postAs('finance', "/api/banking/statements/{$s}/reopen", ['reason' => 'تصحيح وصف سطر']));
        $this->assertSame(['open', 'تصحيح وصف سطر'], [$open['status'], $open['reopenReason']]);
        $this->assertSame(['reconciled', 'open'], array_column(array_reverse(array_slice($open['timeline'], 0, 2)), 'to'));
        $this->expectOk($this->postAs('finance', "/api/banking/statements/{$s}/reconcile"));
    }

    public function test_11_the_reconciliation_statement_explains_the_difference_to_zero(): void
    {
        $bank = self::$s['bank'];
        $today = $this->expectOk($this->getAs('gm', "/api/banking/reconciliation?bank={$bank}"));
        $this->assertSame('8262.00', $today['book']['balance'], '8,900.00 received − 720.50 paid + 82.50 written off');
        $this->assertSame(['8562.00', 'closing'], [$today['statement']['balance'], $today['statement']['source']]);
        $this->assertSame([0, '0.00'], [$today['outstanding']['depositsInTransit']['count'], $today['outstanding']['depositsInTransit']['total']]);
        $this->assertSame([1, '-300.00'], [$today['outstanding']['outstandingPayments']['count'], $today['outstanding']['outstandingPayments']['total']], 'E3: the cheque the bank has not paid yet');
        $this->assertSame(self::$s['docs']['E3']['number'], $today['outstanding']['outstandingPayments']['items'][0]['document']['number']);
        $this->assertSame([0, 0], [$today['unbooked']['credits']['count'], $today['unbooked']['debits']['count']]);
        $this->assertSame(['8262.00', '8262.00', '0.00', '0.00', 'reconciled', true], [$today['adjusted']['bank'], $today['adjusted']['book'], $today['difference'], $today['unexplained'], $today['status'], $today['reconciled']]);

        // inside the statement: the bank's running balance, and what each side held by then
        $mid = $this->expectOk($this->getAs('gm', "/api/banking/reconciliation?bank={$bank}&date={$this->day(-16)}"));
        $this->assertSame(['2000.00', 'running', '2000.00', '0.00'], [$mid['statement']['balance'], $mid['statement']['source'], $mid['book']['balance'], $mid['difference']]);
        $before = $this->expectOk($this->getAs('gm', "/api/banking/reconciliation?bank={$bank}&date={$this->day(-14)}"));
        $this->assertSame(['2600.00', '3000.00'], [$before['statement']['balance'], $before['book']['balance']]);
        $this->assertSame(['0.00', '0.00'], [$before['difference'], $before['unexplained']], 'R4 is in transit at that day: L4 completed its match the next day');
        $this->assertSame([1, '1000.00'], [$before['outstanding']['depositsInTransit']['count'], $before['outstanding']['depositsInTransit']['total']]);
        $this->assertSame([1, '600.00'], [$before['unbooked']['credits']['count'], $before['unbooked']['credits']['total']]);

        // a statement that does not continue the last one: the gap is named, and nothing is left unexplained
        $gap = $this->newStatement($bank, $this->day(1), $this->day(10), '8000.00', '8000.00');
        $this->assertContains('BANK_STATEMENT_GAP', array_column($gap['blockers'], 'code'));
        $later = $this->expectOk($this->getAs('gm', "/api/banking/reconciliation?bank={$bank}&date={$this->day(10)}"));
        $this->assertSame(['-562.00', '0.00', 'difference', false], [$later['difference'], $later['unexplained'], $later['status'], $later['reconciled']]);
        $this->assertSame([['kind' => 'gap', 'amount' => '-562.00']], array_map(fn (array $e) => ['kind' => $e['kind'], 'amount' => $e['amount']], $later['explained']));

        $overview = collect($this->expectOk($this->getAs('gm', '/api/banking/accounts'))['items'])->firstWhere('code', $bank);
        $this->assertSame(['8262.00', 2, 1], [$overview['bookBalance'], $overview['statements'], $overview['openStatements']]);
        $this->assertSame(self::$s['statement'], $overview['lastReconciled']['number']);
        $this->expectRejected($this->getAs('gm', '/api/banking/reconciliation'), null, [400]);
    }

    /** The bank's balance in the books, as the section's overview gives it. */
    private function bookBalance(): string
    {
        return collect($this->expectOk($this->getAs('finance', '/api/banking/accounts'))['items'])->firstWhere('code', self::$s['bank'])['bookBalance'];
    }
}
