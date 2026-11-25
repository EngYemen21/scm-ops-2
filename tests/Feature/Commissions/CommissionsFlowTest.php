<?php

namespace Tests\Feature\Commissions;

use App\Commissions\Models\CommissionLine;
use App\Commissions\Models\CommissionStatement;
use App\Finance\Models\Expense;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\StatusHistory;
use Tests\ApiTestCase;

/**
 * «العمولات» — one month from the finance module's invoices to a statement paid through the books, as one story:
 * a plan, the calculation (and that a second one changes nothing), a salesperson's own view, adjustments with a
 * reason, submit → send back → approve, the payout as an expense voucher of the finance module, its reversal, the
 * summary — and who may do which step.
 *
 * Users of the demo data: finance manages (commission.manage), gm approves and releases payouts (commission.approve),
 * sales only opens the section (his own statements), worker cannot open it at all, admin holds everything.
 */
class CommissionsFlowTest extends ApiTestCase
{
    use CommissionFixtures;

    /** @var array<string, mixed> what the story carries from one step to the next */
    private static array $s = [];

    private function url(string $suffix = ''): string
    {
        return self::API.'/statements/'.self::$s['number'].$suffix;
    }

    public function test_01_a_month_is_calculated_from_the_invoices_once(): void
    {
        $sales = $this->userId('sales');
        $rep = $this->person('flow');
        $mine = $this->customer('mine');
        $theirs = $this->customer('theirs');
        $product = $this->item('flow');
        // whose sale: one customer is the demo salesperson's (a person's decision); the other's order was entered by the other salesperson
        $this->expectRejected($this->putAs('sales', self::API.'/assignments', ['kind' => 'customer', 'subject' => $mine->code, 'user' => 'sales']), 'FORBIDDEN', [403]);
        $this->expectOk($this->putAs('finance', self::API.'/assignments', ['kind' => 'customer', 'subject' => $mine->code, 'user' => 'sales']));
        $first = $this->invoiced($mine, [[$product, 100]], ['date' => '2026-08-05']);     // net 1,000 · VAT 150 · cost 600
        $second = $this->invoiced($mine, [[$product, 50]], ['date' => '2026-08-20']);     // net 500
        $other = $this->invoiced($theirs, [[$product, 30]], ['by' => $rep->username, 'date' => '2026-08-10']);   // net 300
        $later = $this->invoiced($mine, [[$product, 10]], ['date' => '2026-09-02']);      // next month's
        $this->assertSame(['1000.00', '150.00', '1150.00', '600.00', 'issued'], [(string) $first->subtotal, (string) $first->vat_amount, (string) $first->total, (string) $first->cogs_total, $first->status]);

        // nothing can be calculated without a plan in force; a month that has not started is refused; the key is validated
        $this->expectRejected($this->postAs('finance', self::API.'/calculate', ['period' => '2023-01']), 'COMMISSION_NO_ACTIVE_PLAN', [422]);
        $plan = $this->activePlan(['members' => ['sales', $rep->id], 'validFrom' => '2026-08-01', 'validTo' => '2026-09-30']);
        $this->expectRejected($this->postAs('sales', self::API.'/calculate', ['period' => '2026-08']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('gm', self::API.'/calculate', ['period' => '2026-08']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('finance', self::API.'/calculate', ['period' => '2026-13']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('finance', self::API.'/calculate', ['period' => '2026-10']), 'COMMISSION_PERIOD_FUTURE', [422]);
        $this->assertSame(0, CommissionStatement::where('period', '2026-08')->count(), 'a refused calculation writes nothing');

        $run = $this->calc('2026-08');
        $this->assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'frozen' => 0, 'removed' => 0], $run['counts']);
        $this->assertSame(['created', 2, '30.00'], [$this->outcomeOf($run, $sales)['outcome'], $this->outcomeOf($run, $sales)['lines'], $this->outcomeOf($run, $sales)['total']]);
        $this->assertSame(['created', 1, '6.00'], [$this->outcomeOf($run, $rep->id)['outcome'], $this->outcomeOf($run, $rep->id)['lines'], $this->outcomeOf($run, $rep->id)['total']]);

        $statement = $this->statementOf($sales, '2026-08');
        $this->assertStringStartsWith('CMS-', $statement['number']);
        $this->assertSame(['draft', '2026-08', '2026-08-01', '2026-08-31', 2, '1500.00', '30.00', '0.00', '30.00', $plan['code'], 'salesperson', 'sales'],
            [$statement['status'], $statement['period'], $statement['dateFrom'], $statement['dateTo'], $statement['linesCount'], $statement['baseTotal'], $statement['commissionTotal'],
                $statement['adjustmentsTotal'], $statement['total'], $statement['plan']['code'], $statement['earnerType'], $statement['beneficiary']['username']]);
        $this->assertSame(['invoiced', 'net', 2.0], [$statement['terms']['earnOn'], $statement['terms']['base'], (float) $statement['terms']['rules'][0]['rate']], 'the statement keeps the terms it was calculated at');

        // every line explains itself: the invoice, the base and its parts, the rule's rate, whose sale it is and how that is known
        $lines = $this->linesOf($statement);
        $this->assertSame([$first->number, $second->number], array_column($lines, 'docNumber'), 'the invoice of the next month is not in this one');
        $line = $lines[0];
        $this->assertSame(['invoice', 'CustomerInvoice', $first->id, '2026-08-05', '2026-08-05', '1150.00', '1000.00', 2.0, '20.0000', $mine->code, $sales, 'customer', false],
            [$line['kind'], $line['sourceType'], $line['sourceId'], $line['docDate'], $line['earnedOn'], $line['invoiceTotal'], $line['baseAmount'], (float) $line['rate'], $line['commission'],
                $line['customer']['code'], $line['salesperson']['id'], $line['salespersonVia'], $line['excluded']]);
        $this->assertEquals(['earnOn' => 'invoiced', 'base' => 'net', 'net' => 1000, 'tax' => 150, 'cogs' => 600], $line['explain']);
        $theirStatement = $this->statementOf($rep->id, '2026-08');
        $this->assertSame([[$other->number, 'creator', '6.0000']], array_map(fn (array $l) => [$l['docNumber'], $l['salespersonVia'], $l['commission']], $this->linesOf($theirStatement)));

        // idempotent: a second calculation writes nothing — not a line, not an audit row
        $audits = AuditLog::where('action', 'COMMISSION.CALCULATE')->count();
        $before = CommissionLine::where('statement_id', $statement['id'])->orderBy('id')->get(['id', 'updated_at'])->toArray();
        $again = $this->calc('2026-08');
        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'frozen' => 0, 'removed' => 0], $again['counts']);
        $this->assertSame([$audits, $before, $statement['calculatedAt']], [AuditLog::where('action', 'COMMISSION.CALCULATE')->count(),
            CommissionLine::where('statement_id', $statement['id'])->orderBy('id')->get(['id', 'updated_at'])->toArray(), $this->statementOf($sales, '2026-08')['calculatedAt']]);
        $this->assertSame(1, AuditLog::where('action', 'COMMISSION.CALCULATE')->where('entity_number', $statement['number'])->count());

        self::$s = ['number' => $statement['number'], 'id' => $statement['id'], 'theirs' => $theirStatement['number'], 'rep' => $rep->id, 'plan' => $plan['code'], 'later' => $later->number];
    }

    public function test_02_a_salesperson_sees_his_own_statements_and_nothing_else(): void
    {
        $sales = $this->userId('sales');
        // the section is closed to a role without page.commissions
        $this->expectRejected($this->getAs('worker', self::API.'/summary'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('worker', self::API.'/statements'), 'FORBIDDEN', [403]);

        $own = $this->expectOk($this->getAs('sales', self::API.'/statements'));
        $this->assertSame([1, self::$s['number'], true], [$own['total'], $own['items'][0]['number'], $own['items'][0]['mine']]);
        $this->assertSame('30.00', $own['totals']['total']);
        // somebody else's statement does not exist for him — whatever he asks for
        $this->expectRejected($this->getAs('sales', self::API.'/statements/'.self::$s['theirs']), 'COMMISSION_STATEMENT_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('sales', self::API.'/statements/'.self::$s['theirs'].'/lines'), 'COMMISSION_STATEMENT_NOT_FOUND', [404]);
        $this->assertSame(0, $this->expectOk($this->getAs('sales', self::API.'/statements?beneficiary='.self::$s['rep']))['total']);
        $lines = $this->expectOk($this->getAs('sales', self::API.'/lines'));
        $this->assertSame([2, [$sales]], [$lines['total'], array_values(array_unique(array_map(fn (array $l) => $l['statement']['beneficiary']['id'], $lines['items'])))]);
        $this->assertCount(2, $this->expectOk($this->getAs('sales', $this->url('/lines')))['items']);

        // his own statement: all of it to read, nothing to do
        $detail = $this->expectOk($this->getAs('sales', $this->url()));
        $this->assertTrue($detail['mine']);
        $this->assertSame([], array_keys(array_filter($detail['actions'], fn (array $a) => $a['allowed'])));
        $summary = $this->expectOk($this->getAs('sales', self::API.'/summary'));
        $this->assertSame(['mine', 1, '30.00', 1], [$summary['scope'], $summary['totals']['pending']['count'], $summary['totals']['pending']['amount'], count($summary['byBeneficiary'])]);
        $lookups = $this->expectOk($this->getAs('sales', self::API.'/lookups'));
        $this->assertSame([['manage' => false, 'approve' => false], [], [], 'mine'], [$lookups['can'], $lookups['users'], $lookups['plans'], $lookups['scope']]);

        // plans hold other people's rates; calculation, corrections and decisions are not his
        $this->expectRejected($this->getAs('sales', self::API.'/plans'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('sales', self::API.'/plans/'.self::$s['plan']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('sales', self::API.'/assignments'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('sales', self::API.'/unassigned'), 'FORBIDDEN', [403]);
        foreach (['/adjustments' => ['amount' => 50, 'reason' => 'مكافأة هدف الشهر'], '/submit' => [], '/approve' => [], '/recalculate' => [], '/payout' => [], '/return' => ['reason' => 'أريد تعديل الكشف']] as $step => $body) {
            $this->expectRejected($this->postAs('sales', $this->url($step), $body), 'FORBIDDEN', [403]);
        }
        $this->assertSame(['draft', '30.00'], [CommissionStatement::find(self::$s['id'])->status, (string) CommissionStatement::find(self::$s['id'])->total], 'a refused step changes nothing');
        $this->assertGreaterThan(0, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->where('username', 'sales')->where('entity_id', 'like', '%commissions/statements%')->count(), 'a refusal is written to the audit trail');

        // the manager and the approver see every statement
        $this->assertSame(2, $this->expectOk($this->getAs('finance', self::API.'/statements?period=2026-08'))['total']);
        $this->assertSame(2, $this->expectOk($this->getAs('gm', self::API.'/statements?period=2026-08'))['total']);
        $this->assertSame(1, $this->expectOk($this->getAs('finance', self::API.'/statements?period=2026-08&q=sales'))['total']);
        $this->assertSame('all', $this->expectOk($this->getAs('gm', self::API.'/summary'))['scope']);
    }

    public function test_03_adjustments_carry_a_reason_and_are_voided_not_deleted(): void
    {
        $post = fn (string $user, array $body) => $this->postAs($user, $this->url('/adjustments'), $body);
        // the approver decides; he does not correct (segregation of duties)
        $this->expectRejected($post('gm', ['amount' => 50, 'reason' => 'مكافأة تحقيق هدف الشهر']), 'FORBIDDEN', [403]);
        $this->expectRejected($post('finance', ['amount' => 50, 'reason' => 'لا']), 'INVALID_INPUT', [400]);
        $this->expectRejected($post('finance', ['amount' => 50]), 'INVALID_INPUT', [400]);
        $this->expectRejected($post('finance', ['amount' => 0, 'reason' => 'تسوية بلا مبلغ']), 'COMMISSION_ADJUSTMENT_ZERO', [400]);

        $bonus = $this->expectOk($post('finance', ['amount' => 50, 'reason' => 'مكافأة تحقيق هدف الشهر']));
        $this->assertSame(['30.00', '50.00', '80.00', 2], [$bonus['commissionTotal'], $bonus['adjustmentsTotal'], $bonus['total'], $bonus['linesCount']]);
        $this->assertSame(['adjustment', '50.0000', 'مكافأة تحقيق هدف الشهر', 'finance', null], [$bonus['adjustments'][0]['kind'], $bonus['adjustments'][0]['commission'], $bonus['adjustments'][0]['reason'],
            $bonus['adjustments'][0]['createdBy'], $bonus['adjustments'][0]['voided']]);
        $line = $bonus['adjustments'][0]['id'];

        // taking it back needs a reason; the line stays, struck out, and counts no more
        $this->expectRejected($this->postAs('finance', self::API."/lines/{$line}/void", ['reason' => 'x']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('finance', self::API."/lines/{$line}/exclude", ['reason' => 'ليست فاتورة']), 'COMMISSION_LINE_STATE', [422]);
        $voided = $this->expectOk($this->postAs('finance', self::API."/lines/{$line}/void", ['reason' => 'أُدخلت على الكشف الخطأ']));
        $this->assertSame(['0.00', '30.00', 'أُدخلت على الكشف الخطأ', 'finance'], [$voided['adjustmentsTotal'], $voided['total'], $voided['adjustments'][0]['voided']['reason'], $voided['adjustments'][0]['voided']['by']]);
        $this->assertSame(1, CommissionLine::whereKey($line)->count());
        $this->expectRejected($this->postAs('finance', self::API."/lines/{$line}/void", ['reason' => 'مرة أخرى للتأكد']), 'COMMISSION_LINE_STATE', [422]);

        $cut = $this->expectOk($post('finance', ['amount' => -5.5, 'reason' => 'خصم فرق سعر متفق عليه مع العميل']));
        $this->assertSame(['-5.50', '24.50'], [$cut['adjustmentsTotal'], $cut['total']]);
        // a recalculation keeps what a person added
        $this->assertSame('unchanged', $this->outcomeOf($this->calc('2026-08'), $this->userId('sales'))['outcome']);
        $this->assertSame('24.50', $this->expectOk($this->getAs('finance', $this->url()))['total']);
        $this->assertSame(['COMMISSION.ADJUST', 'COMMISSION.ADJUST.VOID', 'COMMISSION.ADJUST'],
            AuditLog::where('entity_type', 'CommissionStatement')->where('entity_id', self::$s['id'])->where('action', 'like', 'COMMISSION.ADJUST%')->orderBy('at')->orderBy('id')->pluck('action')->all());
    }

    public function test_04_submit_send_back_approve(): void
    {
        $id = self::$s['id'];
        // a draft is not approved or paid; the approver does not submit
        $this->expectRejected($this->postAs('gm', $this->url('/submit')), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('gm', $this->url('/approve')), 'COMMISSION_STATEMENT_STATE', [422]);
        $this->expectRejected($this->postAs('gm', $this->url('/payout'), ['account' => '6210', 'bankAccount' => 'BANK', 'reference' => 'TRF-0']), 'COMMISSION_STATEMENT_STATE', [422]);
        $this->expectRejected($this->postAs('gm', $this->url('/return'), ['reason' => 'ليس مقدَّمًا بعد']), 'COMMISSION_STATEMENT_STATE', [422]);

        $submitted = $this->expectOk($this->postAs('finance', $this->url('/submit'), ['note' => 'جاهز للاعتماد']));
        $this->assertSame(['submitted', 'finance'], [$submitted['status'], $submitted['submittedBy']]);
        $this->assertSame(1, Notification::where('role_key', 'gm')->where('entity_type', 'CommissionStatement')->where('entity_id', $id)->count(), 'the approvers are told');
        $this->expectRejected($this->postAs('finance', $this->url('/submit')), 'COMMISSION_STATEMENT_STATE', [422]);

        // submitted = frozen: a calculation passes it by, corrections are refused
        $run = $this->calc('2026-08');
        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 1, 'frozen' => 1, 'removed' => 0], $run['counts']);
        $this->expectRejected($this->postAs('finance', $this->url('/adjustments'), ['amount' => 10, 'reason' => 'تسوية بعد التقديم']), 'COMMISSION_STATEMENT_STATE', [422]);
        $this->expectRejected($this->postAs('finance', $this->url('/recalculate')), 'COMMISSION_STATEMENT_STATE', [422]);
        // the manager does not approve
        $this->expectRejected($this->postAs('finance', $this->url('/approve')), 'FORBIDDEN', [403]);

        // sent back with a reason — by the approver, or withdrawn by the manager
        $this->expectRejected($this->postAs('gm', $this->url('/return'), ['reason' => 'x']), 'INVALID_INPUT', [400]);
        $back = $this->expectOk($this->postAs('gm', $this->url('/return'), ['reason' => 'راجع تسوية الخصم أولًا']));
        $this->assertSame(['draft', 'راجع تسوية الخصم أولًا', 'gm', null], [$back['status'], $back['returned']['reason'], $back['returned']['by'], $back['submittedAt']]);
        $this->expectOk($this->postAs('finance', $this->url('/submit')));
        $this->assertSame('draft', $this->expectOk($this->postAs('finance', $this->url('/return'), ['reason' => 'سحب التقديم لتصحيح رقم']))['status']);
        $this->expectOk($this->postAs('finance', $this->url('/submit')));

        $approved = $this->expectOk($this->postAs('gm', $this->url('/approve'), ['note' => 'معتمد']));
        $this->assertSame(['approved', 'gm', 'معتمد', '24.50'], [$approved['status'], $approved['approvedBy'], $approved['approvalNote'], $approved['total']]);
        $told = Notification::where('user_id', $this->userId('sales'))->where('entity_type', 'CommissionStatement')->where('entity_id', $id)->pluck('text_ar')->all();
        $this->assertCount(1, $told);
        $this->assertStringContainsString('اعتُمد كشف عمولتك', $told[0]);
        $this->assertStringNotContainsString('24.50', $told[0].implode(' ', Notification::where('entity_type', 'CommissionStatement')->where('entity_id', $id)->pluck('text_ar')->all()), 'what a person earns is not broadcast');
        $this->expectRejected($this->postAs('gm', $this->url('/approve')), 'COMMISSION_STATEMENT_STATE', [422]);
        // an approved statement is reopened by the approver only
        $this->expectRejected($this->postAs('finance', $this->url('/return'), ['reason' => 'خطأ في التسوية']), 'FORBIDDEN', [403]);
        $this->assertSame('approved', CommissionStatement::find($id)->status);
        $this->assertSame(['submitted', 'draft', 'submitted', 'draft', 'submitted', 'approved'], StatusHistory::where('entity_type', 'CommissionStatement')->where('entity_id', $id)->orderBy('at')->orderBy('id')->pluck('to_status')->all());
    }

    public function test_05_the_payout_is_an_expense_voucher_of_the_finance_module_and_can_be_reversed(): void
    {
        $id = self::$s['id'];
        $pay = ['account' => '6210', 'bankAccount' => 'BANK', 'reference' => 'TRF-CM-0801', 'date' => '2026-09-10'];
        $this->expectRejected($this->postAs('finance', $this->url('/payout'), $pay), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('finance', self::API.'/payout-options'), 'FORBIDDEN', [403]);
        $options = $this->expectOk($this->getAs('gm', self::API.'/payout-options'));
        $this->assertContains('6210', array_column($options['accounts'], 'code'));
        $this->assertNotContains('1130', array_column($options['accounts'], 'code'), 'expense accounts only');
        // (other stories add bank accounts of their own: the two of the seed are what is looked for)
        $this->assertSame([['BANK', 'CASH'], 'BANK', 'SALES'], [array_values(array_intersect(array_column($options['banks'], 'code'), ['BANK', 'CASH'])), $options['defaults']['bankAccount'], $options['costCenter']]);

        // what is missing is said; what the ledger refuses leaves the statement as it was
        $missing = $this->expectRejected($this->postAs('gm', $this->url('/payout')), 'COMMISSION_PAYOUT_DETAILS', [400]);
        $this->assertSame(['account', 'bankAccount', 'reference'], array_column($missing['details'], 'path'));
        $this->expectRejected($this->postAs('gm', $this->url('/payout'), ['date' => '2026-12-01'] + $pay), 'DATE_FUTURE', [400]);
        $this->expectRejected($this->postAs('gm', $this->url('/payout'), ['account' => '2150'] + $pay), 'EXPENSE_ACCOUNT', [422]);
        $this->expectRejected($this->postAs('gm', $this->url('/payout'), ['bankAccount' => 'NOPE'] + $pay), 'BANK_ACCOUNT_NOT_FOUND', [404]);
        $this->assertSame(['approved', 0], [CommissionStatement::find($id)->status, Expense::where('reference', 'TRF-CM-0801')->count()]);

        $paid = $this->expectOk($this->postAs('gm', $this->url('/payout'), $pay));
        $this->assertSame(['paid', 'gm', '2026-09-10', 'TRF-CM-0801', 'BANK', '6210'], [$paid['status'], $paid['paidBy'], $paid['payout']['date'], $paid['payout']['reference'], $paid['payout']['bankAccount'], $paid['payout']['account']]);
        $voucher = Expense::findOrFail($paid['payout']['expense']['id']);
        $this->assertStringStartsWith('EXP-', $voucher->number);
        $this->assertSame([$paid['payout']['expense']['number'], 'posted', '24.50', '24.50', 'bank', 'TRF-CM-0801', '2026-09-10'],
            [$voucher->number, $voucher->status, (string) $voucher->amount, (string) $voucher->total, $voucher->settlement, $voucher->reference, $voucher->expense_date->toDateString()]);
        $this->assertStringContainsString(self::$s['number'], $voucher->description);
        // Dr commission expense (cost center SALES) / Cr the bank account — posted by the finance module, read back through its own API
        $this->assertEqualsWithDelta(24.50, $this->gl('admin_expenses', $voucher->number), 0.001);
        $this->assertEqualsWithDelta(-24.50, $this->gl('bank', $voucher->number), 0.001);
        $seen = $this->expectOk($this->getAs('finance', "/api/finance/expenses/{$voucher->number}"));
        $this->assertSame(['SALES', '6210', 'posted'], [$seen['costCenter']['code'], $seen['account']['code'], $seen['status']]);
        $this->assertSame(1, Notification::where('user_id', $this->userId('sales'))->where('entity_id', $id)->where('text_ar', 'like', '%صُرفت عمولتك%')->count());
        $this->assertSame(1, AuditLog::where('action', 'COMMISSION.PAYOUT')->where('entity_id', $id)->count());

        // paid is final for everything but the reversal of the payout
        foreach (['/payout' => $pay, '/approve' => [], '/submit' => [], '/return' => ['reason' => 'إعادة فتح بعد الصرف'], '/adjustments' => ['amount' => 5, 'reason' => 'تسوية بعد الصرف']] as $step => $body) {
            $this->expectRejected($this->postAs('admin', $this->url($step), $body), 'COMMISSION_STATEMENT_STATE', [422]);
        }
        $this->assertSame(1, Expense::where('reference', 'TRF-CM-0801')->count());

        // the transfer bounced: the payout is voided with a reason — the voucher is voided by the finance module, both journals stay
        $this->expectRejected($this->postAs('finance', $this->url('/void-payout'), ['reason' => 'حوالة مرتجعة من البنك']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('gm', $this->url('/void-payout'), ['reason' => 'x']), 'INVALID_INPUT', [400]);
        $voided = $this->expectOk($this->postAs('gm', $this->url('/void-payout'), ['reason' => 'حوالة مرتجعة من البنك']));
        $this->assertSame(['approved', null, null], [$voided['status'], $voided['payout'], $voided['paidAt']]);
        $this->assertSame('void', $voucher->refresh()->status);
        $this->assertEqualsWithDelta(0.0, $this->gl('bank', $voucher->number), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->gl('admin_expenses', $voucher->number), 0.001);

        $again = $this->expectOk($this->postAs('gm', $this->url('/payout'), ['reference' => 'TRF-CM-0802'] + $pay));
        $this->assertSame('paid', $again['status']);
        $this->assertNotSame($voucher->number, $again['payout']['expense']['number']);
        $this->assertSame(['submitted', 'draft', 'submitted', 'draft', 'submitted', 'approved', 'paid', 'approved', 'paid'], array_column($again['history'], 'to'));
        // the form remembers where the last payout went
        $this->assertSame(['6210', 'BANK'], [$this->expectOk($this->getAs('gm', self::API.'/payout-options'))['defaults']['account'], $this->expectOk($this->getAs('gm', self::API.'/payout-options'))['defaults']['bankAccount']]);
    }

    public function test_06_the_summary_and_the_next_month(): void
    {
        $sales = $this->userId('sales');
        $summary = $this->expectOk($this->getAs('finance', self::API.'/summary?from=2026-08&to=2026-08'));
        $this->assertSame(['all', ['count' => 1, 'amount' => '6.00'], ['count' => 0, 'amount' => '0.00'], ['count' => 1, 'amount' => '24.50'], '30.50'],
            [$summary['scope'], $summary['totals']['pending'], $summary['totals']['approved'], $summary['totals']['paid'], $summary['totals']['total']]);
        $this->assertSame([['2026-08', '6.00', '24.50']], array_map(fn (array $m) => [$m['period'], $m['pending']['amount'], $m['paid']['amount']], $summary['byMonth']));
        $byPerson = array_column(array_map(fn (array $p) => [$p['beneficiary']['id'], [$p['pending']['amount'], $p['paid']['amount'], $p['total']]], $summary['byBeneficiary']), 1, 0);
        $this->assertSame([$sales => ['0.00', '24.50', '24.50'], self::$s['rep'] => ['6.00', '0.00', '6.00']], $byPerson);
        $this->assertSame(['paid' => ['count' => 1, 'amount' => '24.50']], array_intersect_key($this->expectOk($this->getAs('sales', self::API.'/summary'))['totals'], ['paid' => 1]));

        // September: the invoice that waited for its own month; August's paid statement is history
        // (the suite's clock stands in September 2026: other stories' sales of that month are this salesperson's too when
        // the whole suite runs, so the month's count and total are not this story's to assert — its own invoice is)
        $run = $this->calc('2026-09');
        $this->assertSame('created', $this->outcomeOf($run, $sales)['outcome']);
        $this->assertSame(2.0, $this->commissionByDocument($this->statementOf($sales, '2026-09'))[self::$s['later']] ?? null);
        $this->assertSame(['paid', '24.50'], [CommissionStatement::find(self::$s['id'])->status, (string) CommissionStatement::find(self::$s['id'])->total]);
        // the lists: filters, search, sort, paging
        $list = $this->expectOk($this->getAs('finance', self::API."/statements?beneficiary={$sales}&sort=period&order=asc&pageSize=1&page=2"));
        $this->assertSame([2, 2, 2, '2026-09'], [$list['total'], $list['pages'], $list['page'], $list['items'][0]['period']]);
        $this->assertSame([self::$s['number']], array_column($this->expectOk($this->getAs('finance', self::API.'/statements?status=paid&from=2026-08&to=2026-08'))['items'], 'number'));
        $this->assertSame(1, $this->expectOk($this->getAs('finance', self::API.'/statements?q=TRF-CM-0802'))['total']);
        $found = $this->expectOk($this->getAs('finance', self::API.'/lines?q='.self::$s['later']));
        $this->assertSame([1, '2026-09', 'draft'], [$found['total'], $found['items'][0]['statement']['period'], $found['items'][0]['statement']['status']], 'which statement paid for this invoice, and to whom');
    }

    public function test_07_nobody_decides_on_his_own_money_and_a_statement_of_zero_closes_without_a_voucher(): void
    {
        // the general manager also sells: his own statement is approved and released by somebody else
        $gm = $this->userId('gm');
        $customer = $this->customer('own');
        $invoice = $this->invoiced($customer, [[$this->item('own'), 20]], ['by' => 'gm', 'date' => '2026-01-12']);   // net 200
        $this->activePlan(['members' => ['gm'], 'validFrom' => '2026-01-01', 'validTo' => '2026-01-31', 'rules' => [['scope' => 'all', 'rateType' => 'flat', 'rate' => 3]]]);
        $this->assertSame('6.00', $this->outcomeOf($this->calc('2026-01'), $gm)['total']);
        $statement = $this->statementOf($gm, '2026-01');
        $url = self::API.'/statements/'.$statement['number'];
        $this->expectOk($this->postAs('finance', "{$url}/submit"));
        $this->expectRejected($this->postAs('gm', "{$url}/approve"), 'COMMISSION_SELF_APPROVAL', [422]);
        $this->assertFalse($this->expectOk($this->getAs('gm', $url))['actions']['approve']['allowed']);
        $this->assertSame('COMMISSION_SELF_APPROVAL', $this->expectOk($this->getAs('gm', $url))['actions']['approve']['code']);
        $this->expectOk($this->postAs('admin', "{$url}/approve"));
        $this->expectRejected($this->postAs('gm', "{$url}/payout", ['account' => '6210', 'bankAccount' => 'CASH', 'reference' => 'CASH-1']), 'COMMISSION_SELF_APPROVAL', [422]);
        $paid = $this->expectOk($this->postAs('admin', "{$url}/payout", ['account' => '6210', 'bankAccount' => 'CASH', 'reference' => 'CASH-1']));
        $this->assertSame(['paid', 'CASH'], [$paid['status'], $paid['payout']['bankAccount']]);
        $this->assertEqualsWithDelta(-6.00, $this->gl('cash', $paid['payout']['expense']['number']), 0.001);

        // a statement brought to zero by an adjustment is closed with nothing to pay: no voucher, no details asked
        $rep = $this->person('zero');
        $other = $this->invoiced($customer, [[$this->item('zero'), 10]], ['by' => $rep->username, 'date' => '2026-01-20']);   // net 100 → 3.00
        $this->expectOk($this->patchAs('finance', self::API.'/plans/'.$this->expectOk($this->getAs('finance', $url))['plan']['code'], $this->planBody(['members' => ['gm', $rep->id], 'validFrom' => '2026-01-01', 'validTo' => '2026-01-31', 'rules' => [['scope' => 'all', 'rateType' => 'flat', 'rate' => 3]]])));
        $this->assertSame('3.00', $this->outcomeOf($this->calc('2026-01'), $rep->id)['total']);
        $zero = $this->statementOf($rep->id, '2026-01');
        $zeroUrl = self::API.'/statements/'.$zero['number'];
        $this->expectOk($this->postAs('finance', "{$zeroUrl}/adjustments", ['amount' => -3, 'reason' => 'العمولة صُرفت نقدًا خارج النظام']));
        $this->expectOk($this->postAs('finance', "{$zeroUrl}/submit"));
        $this->expectOk($this->postAs('gm', "{$zeroUrl}/approve"));
        $expenses = Expense::count();
        $closed = $this->expectOk($this->postAs('gm', "{$zeroUrl}/payout"));
        $this->assertSame(['paid', '0.00', null, null], [$closed['status'], $closed['total'], $closed['payout']['expense'], $closed['payout']['reference']]);
        $this->assertSame($expenses, Expense::count());
        // … and reopened the same way, nothing to reverse
        $this->assertSame('approved', $this->expectOk($this->postAs('gm', "{$zeroUrl}/void-payout", ['reason' => 'أُقفل قبل اكتمال المراجعة']))['status']);
        $this->assertSame($invoice->number, $this->linesOf($statement)[0]['docNumber']);
        $this->assertSame($other->number, $this->linesOf($zero)[0]['docNumber']);
    }

    public function test_08_a_draft_is_what_the_plans_say_now_and_one_that_nothing_justifies_is_withdrawn(): void
    {
        $one = $this->person('arch1');
        $two = $this->person('arch2');
        $three = $this->person('arch3');
        $customer = $this->customer('arch');
        $product = $this->item('arch');
        foreach ([[$one, 10], [$two, 20], [$three, 30]] as [$who, $qty]) {
            $this->invoiced($customer, [[$product, $qty]], ['by' => $who->username, 'date' => '2025-12-05']);   // net 100 · 200 · 300 → 2.00 · 4.00 · 6.00
        }
        $plan = $this->activePlan(['members' => [$one->id, $two->id, $three->id], 'validFrom' => '2025-12-01', 'validTo' => '2025-12-31']);
        $run = $this->calc('2025-12');
        $this->assertSame(['2.00', '4.00', '6.00'], [$this->outcomeOf($run, $one->id)['total'], $this->outcomeOf($run, $two->id)['total'], $this->outcomeOf($run, $three->id)['total']]);
        $first = $this->statementOf($one->id, '2025-12');
        $second = $this->statementOf($two->id, '2025-12');
        $third = $this->statementOf($three->id, '2025-12');
        $this->expectOk($this->postAs('finance', self::API."/statements/{$second['number']}/adjustments", ['amount' => 10, 'reason' => 'مكافأة نهاية السنة']));

        // the plan is archived before its drafts were submitted: it decides no more — for them either
        $this->expectOk($this->postAs('finance', self::API."/plans/{$plan['code']}/archive", ['reason' => 'أُلغيت خطة ديسمبر']));
        // submitting recalculates first: nothing justifies the draft any more, so it is withdrawn — not submitted
        $this->expectRejected($this->postAs('finance', self::API."/statements/{$first['number']}/submit"), 'COMMISSION_STATEMENT_WITHDRAWN', [422]);
        $this->assertSame([0, 0, 1], [CommissionStatement::where('id', $first['id'])->count(), CommissionLine::where('statement_id', $first['id'])->count(),
            AuditLog::where('action', 'COMMISSION.STATEMENT.WITHDRAW')->where('entity_id', $first['id'])->count()]);
        $this->expectRejected($this->getAs('finance', self::API."/statements/{$first['number']}"), 'COMMISSION_STATEMENT_NOT_FOUND', [404]);
        // the same through "recalculate": the answer says the statement is gone
        $again = $this->expectOk($this->postAs('finance', self::API."/statements/{$third['number']}/recalculate"));
        $this->assertSame([null, 'removed', 1], [$again['statement'], $again['run']['statements'][0]['outcome'], $again['run']['counts']['removed']]);
        // a draft that carries a person's adjustment stays, with that adjustment and no line
        $run = $this->calc('2025-12');
        $this->assertSame(['created' => 0, 'updated' => 1, 'unchanged' => 0, 'frozen' => 0, 'removed' => 0], $run['counts']);
        $this->assertSame(['updated', 0, '10.00'], [$this->outcomeOf($run, $two->id)['outcome'], $this->outcomeOf($run, $two->id)['lines'], $this->outcomeOf($run, $two->id)['total']]);
        $this->assertSame('unchanged', $this->outcomeOf($this->calc('2025-12'), $two->id)['outcome']);
        $submitted = $this->expectOk($this->postAs('finance', self::API."/statements/{$second['number']}/submit"));
        $this->assertSame(['submitted', 0, '0.00', '10.00'], [$submitted['status'], $submitted['linesCount'], $submitted['commissionTotal'], $submitted['total']]);
        // nothing is left to calculate in that month: no plan, no draft
        $this->expectRejected($this->postAs('finance', self::API.'/calculate', ['period' => '2025-12']), 'COMMISSION_NO_ACTIVE_PLAN', [422]);
    }
}
