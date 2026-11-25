<?php

namespace Tests\Feature\Banking;

use App\Banking\Models\Statement;
use App\Banking\Models\StatementLine;
use App\Models\AuditLog;
use App\Models\StatusHistory;
use Tests\ApiTestCase;

/**
 * Bank statements and their typed lines: who may read and enter them, the first statement's opening balance kept as a
 * line of its own, overlapping periods refused, opening + lines = closing checked, the lines' rules (inside the period,
 * never zero, exact to the cent), account numbers masked for a reader without a banking permission, and the void.
 */
class BankStatementsTest extends ApiTestCase
{
    use BankingFixtures;

    private const IBAN = 'SA0380000000608010167519';

    private static array $s = [];

    public function test_01_the_section_answers_by_permission_and_masks_account_numbers(): void
    {
        $bank = $this->newBank('BS', self::IBAN);
        self::$s['bank'] = $bank['code'];

        $lookups = $this->expectOk($this->getAs('finance', '/api/banking/lookups'));
        $this->assertTrue($lookups['seesNumbers']);
        $this->assertSame(['statements' => true, 'reconcile' => true, 'createBank' => true], $lookups['can']);
        $mine = collect($lookups['banks'])->firstWhere('code', $bank['code']);
        $this->assertSame(self::IBAN, $mine['iban']);
        $this->assertNotEmpty($lookups['accounts']);
        $this->assertNotContains($bank['account']['code'] ?? $mine['ledgerAccount']['code'], array_column($lookups['accounts'], 'code'), 'a bank ledger account is never offered to a write-off rule');

        // the general manager reads the section: the account number leaves as its last four characters
        $gm = $this->expectOk($this->getAs('gm', '/api/banking/lookups'));
        $this->assertFalse($gm['seesNumbers']);
        $this->assertSame(['statements' => false, 'reconcile' => false, 'createBank' => false], $gm['can']);
        $this->assertSame('••••7519', collect($gm['banks'])->firstWhere('code', $bank['code'])['iban']);

        // sales does not see the section at all; gm may not enter a statement — and nothing is made
        $this->expectRejected($this->getAs('sales', '/api/banking/lookups'), 'FORBIDDEN', [403]);
        $before = Statement::count();
        $this->expectRejected($this->postAs('gm', '/api/banking/statements', ['bankAccount' => $bank['code'], 'dateFrom' => $this->day(-60), 'dateTo' => $this->day(-31), 'openingBalance' => '0', 'closingBalance' => '0']), 'FORBIDDEN', [403]);
        $this->assertSame($before, Statement::count());
    }

    public function test_02_the_first_statement_carries_its_opening_balance_as_a_line_and_periods_never_overlap(): void
    {
        $bank = self::$s['bank'];
        $s1 = $this->newStatement($bank, $this->day(-60), $this->day(-31), '1000.00', '1000.00');
        self::$s['s1'] = $s1['number'];
        $this->assertSame('open', $s1['status']);
        $this->assertStringStartsWith('BST-', $s1['number']);
        $this->assertSame('1000.00', $s1['openingBalance']);
        $this->assertTrue($s1['totals']['balanced'], 'no movement: opening = closing');
        $this->assertTrue($s1['totals']['hasOpeningLine']);
        $this->assertSame(1, $s1['totals']['unmatched'], 'the opening balance waits to be matched like any line');
        $opening = StatementLine::where('statement_id', $s1['id'])->where('kind', 'opening')->firstOrFail();
        $this->assertSame('1000.00', (string) $opening->amount);
        $this->assertSame($this->day(-61), $opening->txn_date->toDateString(), 'dated the day before the statement starts');
        $this->assertContains('BANK_STATEMENT_UNMATCHED', array_column($s1['blockers'], 'code'));

        // an overlapping period of the same bank is refused, by its code, naming the statement in the way
        $res = $this->expectRejected($this->postAs('finance', '/api/banking/statements', ['bankAccount' => $bank, 'dateFrom' => $this->day(-40), 'dateTo' => $this->day(-20), 'openingBalance' => '1000', 'closingBalance' => '1000']), 'BANK_STATEMENT_OVERLAP', [422]);
        $this->assertSame($s1['number'], $res['details']['statement']);
        // a period that ends before it starts, and an amount of three decimals, never reach the books
        $this->expectRejected($this->postAs('finance', '/api/banking/statements', ['bankAccount' => $bank, 'dateFrom' => $this->day(-10), 'dateTo' => $this->day(-20), 'openingBalance' => '0', 'closingBalance' => '0']), 'BANK_STATEMENT_DATES', [400]);
        $this->expectRejected($this->postAs('finance', '/api/banking/statements', ['bankAccount' => $bank, 'dateFrom' => $this->day(-30), 'dateTo' => $this->day(-1), 'openingBalance' => '1000.005', 'closingBalance' => '0']), null, [400]);
        $this->expectRejected($this->postAs('finance', '/api/banking/statements', ['bankAccount' => 'NO-SUCH-BANK', 'dateFrom' => $this->day(-30), 'dateTo' => $this->day(-1), 'openingBalance' => '0', 'closingBalance' => '0']), 'BANK_ACCOUNT_NOT_FOUND', [404]);

        // the second statement continues the first: no opening line of its own
        $s2 = $this->newStatement($bank, $this->day(-30), $this->day(-1), '1000.00', '1250.75');
        self::$s['s2'] = $s2['number'];
        $this->assertFalse($s2['totals']['hasOpeningLine']);
        $this->assertSame($s1['number'], $s2['continuity']['previous']['number']);
        $this->assertTrue($s2['continuity']['ok']);
        $this->assertContains('BANK_STATEMENT_ORDER', array_column($s2['blockers'], 'code'), 'the earlier statement must be locked first');
    }

    public function test_03_typed_lines_add_up_exactly_and_keep_to_the_period(): void
    {
        $s2 = self::$s['s2'];
        $lines = $this->addLines($s2, [
            ['date' => $this->day(-25), 'amount' => '500.10', 'reference' => 'DEP-1', 'description' => 'إيداع نقدي'],
            ['date' => $this->day(-20), 'amount' => '-249.35', 'reference' => 'CHQ-7', 'description' => 'شيك صادر'],
            ['date' => $this->day(-20), 'amount' => '-249.35', 'reference' => 'CHQ-7', 'description' => 'شيك صادر'],   // the same movement twice in one day is two lines
        ]);
        $this->assertSame([1, 2, 3], array_column($lines, 'lineNo'));
        $this->assertSame(['in', 'out', 'out'], array_column($lines, 'direction'));
        self::$s['lines'] = array_column($lines, 'id');
        $this->assertCount(3, StatementLine::whereIn('id', self::$s['lines'])->whereNotNull('fingerprint')->distinct()->pluck('fingerprint'));

        $detail = $this->statement($s2);
        $this->assertSame('500.10', $detail['totals']['in']);
        $this->assertSame('-498.70', $detail['totals']['out']);
        $this->assertSame('1.40', $detail['totals']['net']);
        $this->assertSame('1001.40', $detail['totals']['computedClosing']);
        $this->assertSame('249.35', $detail['totals']['difference']);
        $this->assertFalse($detail['totals']['balanced']);
        $this->assertSame('BANK_STATEMENT_UNBALANCED', $detail['blockers'][0]['code']);

        // the duplicate was a mistake: removed (open statement, unmatched line), the trail keeps what it held
        $removed = $this->expectOk($this->deleteAs('finance', '/api/banking/lines/'.self::$s['lines'][2]));
        $this->assertTrue($removed['ok']);
        $this->assertSame(1, AuditLog::where('action', 'BANK.LINE.REMOVE')->where('entity_id', self::$s['lines'][2])->count());
        // a change of the figures gives the line a new fingerprint and is audited with the old and the new amount
        $print = StatementLine::find(self::$s['lines'][1])->fingerprint;
        $line = $this->expectOk($this->patchAs('finance', '/api/banking/lines/'.self::$s['lines'][1], ['amount' => '250.65']));
        $this->assertSame('250.65', $line['amount'], 'a typed amount keeps the sign it is given');
        $this->assertNotSame($print, StatementLine::find(self::$s['lines'][1])->fingerprint);
        $trail = AuditLog::where('action', 'BANK.LINE.UPDATE')->where('entity_id', self::$s['lines'][1])->orderByDesc('at')->orderByDesc('id')->firstOrFail();
        $this->assertSame(['amount' => '-249.35'], array_intersect_key((array) json_decode((string) $trail->old_value, true), ['amount' => 1]));
    }

    public function test_04_a_line_is_never_zero_never_outside_the_period_and_the_figures_balance(): void
    {
        $s2 = self::$s['s2'];
        // the patch above typed a positive figure for a cheque: put the sign right
        $fixed = $this->expectOk($this->patchAs('finance', '/api/banking/lines/'.self::$s['lines'][1], ['amount' => '-249.35']));
        $this->assertSame('-249.35', $fixed['amount']);
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s2}/lines", ['date' => $this->day(-5), 'amount' => '0']), null, [400]);
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s2}/lines", ['date' => $this->day(-5), 'amount' => '10.001']), null, [400]);
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s2}/lines", ['date' => $this->day(-45), 'amount' => '10']), 'BANK_LINE_DATE_OUTSIDE', [422]);
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s2}/lines", ['date' => $this->day(5), 'amount' => '10']), 'BANK_LINE_DATE_OUTSIDE', [422]);

        // the bank's closing balance was mistyped too: opening 1000.00 + 500.10 − 249.35 = 1250.75 … the bank printed 1250.75; lines are now right
        $detail = $this->statement($s2);
        $this->assertSame('1250.75', $detail['totals']['computedClosing']);
        $this->assertTrue($detail['totals']['balanced']);
        $this->assertNotContains('BANK_STATEMENT_UNBALANCED', array_column($detail['blockers'], 'code'));

        // the period cannot shrink past a line, and cannot grow into the first statement
        $this->expectRejected($this->patchAs('finance', "/api/banking/statements/{$s2}", ['dateTo' => $this->day(-22)]), 'BANK_LINE_DATE_OUTSIDE', [422]);
        $this->expectRejected($this->patchAs('finance', "/api/banking/statements/{$s2}", ['dateFrom' => $this->day(-35)]), 'BANK_STATEMENT_OVERLAP', [422]);
        $updated = $this->expectOk($this->patchAs('finance', "/api/banking/statements/{$s2}", ['name' => 'كشف الشهر', 'notes' => 'ملاحظة داخلية']));
        $this->assertSame('كشف الشهر', $updated['name']);
        $audit = AuditLog::where('action', 'BANK.STATEMENT.UPDATE')->where('entity_number', $s2)->orderByDesc('at')->orderByDesc('id')->firstOrFail();
        $this->assertStringNotContainsString('ملاحظة داخلية', (string) $audit->new_value, 'free text is not copied into the trail');

        // the clerk enters lines; gm may not
        $this->expectOk($this->postAs($this->clerk(), "/api/banking/statements/{$s2}/lines", ['date' => $this->day(-3), 'amount' => '75.00', 'reference' => 'DEP-2']));
        $this->expectRejected($this->postAs('gm', "/api/banking/statements/{$s2}/lines", ['date' => $this->day(-3), 'amount' => '75.00']), 'FORBIDDEN', [403]);
        $this->assertFalse($this->statement($s2)['totals']['balanced'], 'a line the bank does not show unbalances the statement');
    }

    public function test_05_account_numbers_leave_in_full_only_for_a_banking_permission(): void
    {
        $s2 = self::$s['s2'];
        $iban = 'SA44 2000 0001 2345 6789 1234';
        $line = $this->addLines($s2, [['date' => $this->day(-2), 'amount' => '-75.00', 'reference' => 'TRF-9', 'description' => "تحويل إلى {$iban} مورد", 'partnerName' => 'مورد التحويل', 'partnerAccount' => 'SA4420000001234567891234']])[0];
        $this->assertSame('SA4420000001234567891234', $line['partnerAccount']);
        $this->assertTrue($this->statement($s2)['totals']['balanced']);

        $asGm = $this->expectOk($this->getAs('gm', '/api/banking/lines/'.$line['id']));
        $this->assertSame('••••1234', $asGm['partnerAccount']);
        $this->assertStringNotContainsString('2000 0001', $asGm['description']);
        $this->assertStringContainsString('••••1234', $asGm['description']);
        $this->assertSame('••••7519', $this->expectOk($this->getAs('gm', "/api/banking/statements/{$s2}"))['bankAccount']['iban']);
        // a masked reader does not find a line digit by digit through its account number
        $this->assertSame(0, $this->expectOk($this->getAs('gm', "/api/banking/lines?statement={$s2}&q=4567891234"))['total']);
        $this->assertSame(1, $this->expectOk($this->getAs('finance', "/api/banking/lines?statement={$s2}&q=4567891234"))['total']);
        // and the number never reaches the trail
        $this->assertSame(0, AuditLog::where('entity_number', $s2)->where(fn ($w) => $w->where('new_value', 'like', '%4567891234%')->orWhere('old_value', 'like', '%4567891234%'))->count());
    }

    public function test_06_lists_filter_sort_and_page(): void
    {
        $bank = self::$s['bank'];
        $all = $this->expectOk($this->getAs('gm', "/api/banking/statements?bank={$bank}"));
        $this->assertSame(2, $all['total']);
        $this->assertSame([self::$s['s2'], self::$s['s1']], array_column($all['items'], 'number'), 'newest period first');
        $asc = $this->expectOk($this->getAs('gm', "/api/banking/statements?bank={$bank}&sort=dateFrom&order=asc&pageSize=1"));
        $this->assertSame([self::$s['s1']], array_column($asc['items'], 'number'));
        $this->assertSame(2, $asc['pages']);
        $this->assertSame(1, $this->expectOk($this->getAs('gm', "/api/banking/statements?bank={$bank}&from={$this->day(-10)}"))['total']);
        $this->assertSame(1, $this->expectOk($this->getAs('gm', "/api/banking/statements?bank={$bank}&q=".urlencode('كشف الشهر')))['total']);

        $lines = $this->expectOk($this->getAs('gm', '/api/banking/lines?statement='.self::$s['s2']));
        $this->assertSame([1, 2, 3, 4], array_column($lines['items'], 'lineNo'), 'the removed line no longer counts: the next line took the free number');
        $this->assertSame('250.75', $lines['totals']['net']);
        $out = $this->expectOk($this->getAs('gm', '/api/banking/lines?statement='.self::$s['s2'].'&sign=out&sort=amount&order=asc'));
        $this->assertSame(['-249.35', '-75.00'], array_column($out['items'], 'amount'));
        $this->assertSame(1, $this->expectOk($this->getAs('gm', '/api/banking/lines?statement='.self::$s['s2'].'&amount=249.35'))['total'], 'an amount is found with or without its sign');
        $byBank = $this->expectOk($this->getAs('gm', "/api/banking/lines?bank={$bank}&state=unmatched"));
        $this->assertSame(5, $byBank['total'], 'the four lines and the opening balance of the first statement');
        $this->expectRejected($this->getAs('gm', '/api/banking/lines'), null, [400]);
    }

    public function test_07_a_statement_entered_by_mistake_is_voided_with_a_reason_and_stays_visible(): void
    {
        $bank = $this->newBank('BV');
        $s = $this->newStatement($bank['code'], $this->day(-20), $this->day(-10), '0', '100.00');
        $line = $this->addLines($s['number'], [['date' => $this->day(-15), 'amount' => '100.00', 'reference' => 'X-1']])[0];
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s['number']}/void", ['reason' => 'خطأ']), null, [400]);
        $this->expectRejected($this->postAs('gm', "/api/banking/statements/{$s['number']}/void", ['reason' => 'أدخل بالخطأ']), 'FORBIDDEN', [403]);
        $void = $this->expectOk($this->postAs('finance', "/api/banking/statements/{$s['number']}/void", ['reason' => 'أدخل للحساب الخطأ']));
        $this->assertSame('void', $void['status']);
        $this->assertSame('أدخل للحساب الخطأ', $void['voidReason']);
        $this->assertNull(StatementLine::find($line['id'])->fingerprint, 'the fingerprints are released for the right import');
        $this->assertNotNull(StatementLine::find($line['id']), 'the lines stay as the record of what was entered');
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s['number']}/void", ['reason' => 'مرة أخرى']), 'BANK_STATEMENT_STATE', [422]);
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s['number']}/lines", ['date' => $this->day(-15), 'amount' => '5']), 'BANK_STATEMENT_VOID', [422]);
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s['number']}/reconcile"), 'BANK_STATEMENT_STATE', [422]);
        $this->assertSame(0, $this->expectOk($this->getAs('finance', "/api/banking/statements?bank={$bank['code']}"))['total'], 'a voided statement is found by asking for it');
        $this->assertSame(1, $this->expectOk($this->getAs('finance', "/api/banking/statements?bank={$bank['code']}&status=void"))['total']);
        // its period is free again
        $again = $this->newStatement($bank['code'], $this->day(-20), $this->day(-10), '0', '100.00');
        $this->assertSame(1, count($this->addLines($again['number'], [['date' => $this->day(-15), 'amount' => '100.00', 'reference' => 'X-1']])));
        $this->assertSame(1, StatusHistory::where('entity_type', 'BankStatement')->where('entity_id', $s['id'])->where('to_status', 'void')->count());
        $this->assertSame('void', $this->statement($s['number'])['timeline'][0]['to']);
    }
}
