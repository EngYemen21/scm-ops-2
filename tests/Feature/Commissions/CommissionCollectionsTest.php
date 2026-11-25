<?php

namespace Tests\Feature\Commissions;

use App\Commissions\Models\CommissionLine;
use App\Commissions\Models\CommissionStatement;
use App\Finance\Models\CustomerInvoice;
use Tests\ApiTestCase;

/**
 * The rule the company ran in Odoo, in OPS: a sales manager earns a rate of the PROFIT MARGIN of the invoices of his
 * team that are FULLY PAID. An invoice counts on the day of its last receipt; a partly paid one has earned nothing yet;
 * a collection that arrives after its month was closed lands in the next statement; a receipt that is voided after the
 * statement was closed takes the commission back in the open one — and paying the invoice again earns it again, once.
 * Months of this class: May to July 2026.
 */
class CommissionCollectionsTest extends ApiTestCase
{
    use CommissionFixtures;

    public function test_a_manager_earns_on_the_margin_of_his_teams_fully_paid_invoices(): void
    {
        $manager = $this->person('mgr');
        $one = $this->person('team1');
        $two = $this->person('team2');
        $outsider = $this->person('other');
        $customer = $this->customer('coll');
        $product = $this->item('coll');
        $sold = fn (object $by, int $qty, string $day) => $this->invoiced($customer, [[$product, $qty]], ['by' => $by->username, 'date' => $day]);
        $paidUp = $sold($one, 100, '2026-05-04');       // total 1,150 · margin 400
        $partly = $sold($two, 50, '2026-05-06');        // total 575 · margin 200
        $notTheirs = $sold($outsider, 10, '2026-05-07');
        $small = $sold($one, 20, '2026-05-11');         // total 230 · margin 80
        $receipt = $this->collect($paidUp, 1150, '2026-05-20');
        $this->collect($partly, 300, '2026-05-22');     // 275 still owed
        $this->collect($notTheirs, 115, '2026-05-21');
        $smallReceipt = $this->collect($small, 230, '2026-05-25');
        $this->assertSame(['paid', 'partially_paid'], [$paidUp->refresh()->status, $partly->refresh()->status]);

        $plan = $this->activePlan(['nameAr' => 'عمولة مدير المبيعات 7% من هامش المحصَّل', 'earnerType' => 'manager', 'beneficiary' => $manager->id, 'members' => [$one->id, $two->id],
            'earnOn' => 'collected', 'base' => 'margin', 'validFrom' => '2026-05-01', 'validTo' => '2026-07-31', 'rules' => [['scope' => 'all', 'rateType' => 'flat', 'rate' => 7]]]);
        $this->assertSame(['manager', $manager->id, 2], [$plan['earnerType'], $plan['beneficiary']['id'], count($plan['members'])]);
        $commission = fn (string $period) => $this->commissionByDocument($this->statementOf($manager->id, $period));

        // ── May: what was fully paid in May, by the team — nothing for the half-paid invoice, nothing for the outsider's
        $run = $this->calc('2026-05');
        $this->assertSame(['created', 2, '33.60'], [$this->outcomeOf($run, $manager->id)['outcome'], $this->outcomeOf($run, $manager->id)['lines'], $this->outcomeOf($run, $manager->id)['total']]);
        $this->assertNull($this->outcomeOf($run, $one->id), 'the team are the source of a manager plan, not its earners');
        $may = $this->statementOf($manager->id, '2026-05');
        $this->assertSame(['manager', '480.00', '33.60'], [$may['earnerType'], $may['baseTotal'], $may['commissionTotal']]);
        $this->assertSame([$paidUp->number => 28.0, $small->number => 5.6], $commission('2026-05'));
        $line = collect($this->linesOf($may))->firstWhere('docNumber', $paidUp->number);
        $this->assertSame(['2026-05-04', '2026-05-20', '400.00', '1150.00', $one->id, 'creator', 'paid'], [$line['docDate'], $line['earnedOn'], $line['baseAmount'], $line['invoiceTotal'], $line['salesperson']['id'], $line['salespersonVia'], $line['paymentState']],
            'the invoice counts on the day it was fully paid');
        $this->assertEquals(['earnOn' => 'collected', 'base' => 'margin', 'net' => 1000, 'tax' => 150, 'cogs' => 600], $line['explain']);
        $mayUrl = self::API.'/statements/'.$may['number'];
        $this->expectOk($this->postAs('finance', "{$mayUrl}/submit"));
        $this->expectOk($this->postAs('gm', "{$mayUrl}/approve"));

        // ── the rest of the half-paid invoice arrives in June; another one of May was collected on 28 May, AFTER May was closed
        $this->collect($partly, 275, '2026-06-05');
        $lateOne = $sold($one, 10, '2026-05-27');       // margin 40
        $this->collect($lateOne, 115, '2026-05-28');
        $again = $this->calc('2026-05');
        $this->assertSame(['frozen', 2, '33.60'], [$this->outcomeOf($again, $manager->id)['outcome'], $this->outcomeOf($again, $manager->id)['lines'], $this->outcomeOf($again, $manager->id)['total']], 'a closed statement is history');
        $this->assertSame('16.80', $this->outcomeOf($this->calc('2026-06'), $manager->id)['total']);
        $this->assertSame([$partly->number => 14.0, $lateOne->number => 2.8], $commission('2026-06'));
        $june = $this->statementOf($manager->id, '2026-06');
        $lines = collect($this->linesOf($june))->keyBy('docNumber');
        $this->assertSame(['2026-06-05', '2026-05-28'], [$lines[$partly->number]['earnedOn'], $lines[$lateOne->number]['earnedOn']]);
        $this->assertTrue($lines[$lateOne->number]['explain']['late'], 'what arrives after its month was closed is taken by the next statement, and says so');
        $this->assertArrayNotHasKey('late', $lines[$partly->number]['explain']);

        // ── a receipt of a CLOSED statement is voided: the open statement takes the commission back, by a line that names the one it reverses
        $this->expectOk($this->postAs('finance', "/api/finance/payments/{$receipt['number']}/void", ['reason' => 'شيك مرتجع من البنك']));
        $this->assertSame('issued', $paidUp->refresh()->status);
        $this->assertSame(['updated', '-11.20'], [$this->outcomeOf($this->calc('2026-06'), $manager->id)['outcome'], $this->statementOf($manager->id, '2026-06')['total']]);
        $reversal = collect($this->linesOf($june))->firstWhere('kind', 'reversal');
        $this->assertSame([$paidUp->number, '-400.00', '-28.0000', $line['id'], 'unpaid', $may['number'], 'issued'],
            [$reversal['docNumber'], $reversal['baseAmount'], $reversal['commission'], $reversal['reversesId'], $reversal['explain']['reversal'], $reversal['explain']['of'], $reversal['paymentState']]);
        // a statement that nets below zero can be approved, but nothing is paid out of it
        $juneUrl = self::API.'/statements/'.$june['number'];
        $this->expectOk($this->postAs('finance', "{$juneUrl}/submit"));
        $this->expectOk($this->postAs('gm', "{$juneUrl}/approve"));
        $this->expectRejected($this->postAs('gm', "{$juneUrl}/payout", ['account' => '6210', 'bankAccount' => 'BANK', 'reference' => 'TRF-NEG']), 'COMMISSION_NEGATIVE_TOTAL', [422]);
        $this->assertSame('draft', $this->expectOk($this->postAs('gm', "{$juneUrl}/return", ['reason' => 'ننتظر إعادة تحصيل الشيك']))['status']);

        // … the cheque is collected again before June is closed: the reversal goes, and the invoice is NOT paid a second commission
        $this->collect($paidUp, 1150, '2026-06-20');
        $run = $this->outcomeOf($this->calc('2026-06'), $manager->id);
        $this->assertSame(['updated', '16.80', 0, 1], [$run['outcome'], $run['total'], $run['added'], $run['removed']]);
        $this->assertSame([$partly->number => 14.0, $lateOne->number => 2.8], $commission('2026-06'));
        $this->assertSame(1, CommissionLine::where('source_id', $paidUp->id)->count(), 'one line for the invoice, in May, standing');

        // ── the same with a reversal that is closed: voided → taken back in June (closed) → paid again → earns again, once, in July
        $this->expectOk($this->postAs('finance', "/api/finance/payments/{$smallReceipt['number']}/void", ['reason' => 'حوالة أُعيدت للعميل']));
        $this->assertSame('11.20', $this->outcomeOf($this->calc('2026-06'), $manager->id)['total']);   // 16.80 − 5.60
        $this->expectOk($this->postAs('finance', "{$juneUrl}/submit"));
        $this->expectOk($this->postAs('gm', "{$juneUrl}/approve"));
        // May can no longer be reopened: one of its lines is answered by a reversal in June
        $stuck = $this->expectRejected($this->postAs('gm', "{$mayUrl}/return", ['reason' => 'إعادة فتح مايو للتصحيح']), 'COMMISSION_HAS_REVERSALS', [422]);
        $this->assertSame($june['number'], $stuck['details']['statement']);
        $this->collect($small, 230, '2026-06-25');
        $this->assertSame('frozen', $this->outcomeOf($this->calc('2026-06'), $manager->id)['outcome']);
        $july = $this->outcomeOf($this->calc('2026-07'), $manager->id);
        $this->assertSame(['created', 1, '5.60'], [$july['outcome'], $july['lines'], $july['total']]);
        $earned = collect($this->linesOf($this->statementOf($manager->id, '2026-07')))->first();
        $this->assertSame([$small->number, 'invoice', '2026-06-25', '5.6000', true], [$earned['docNumber'], $earned['kind'], $earned['earnedOn'], $earned['commission'], $earned['explain']['late']]);
        $this->assertSame('unchanged', $this->outcomeOf($this->calc('2026-07'), $manager->id)['outcome']);
        // over the three statements the invoice was paid for exactly once: + 5.60 (May) − 5.60 (June) + 5.60 (July)
        $this->assertSame(['invoice' => 2, 'reversal' => 1], CommissionLine::where('source_id', $small->id)->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind')->map(fn ($n) => (int) $n)->all());
        $this->assertEqualsWithDelta(5.60, (float) CommissionLine::where('source_id', $small->id)->sum('commission'), 0.0001);
        $this->assertEqualsWithDelta(33.60 + 11.20 + 5.60, (float) CommissionStatement::where('beneficiary_id', $manager->id)->sum('total'), 0.001);
        $this->assertSame(0, CommissionStatement::whereIn('beneficiary_id', [$one->id, $two->id, $outsider->id])->count());
        $this->assertSame(['paid', 'paid', 'paid'], [$paidUp->refresh()->status, $small->refresh()->status, CustomerInvoice::find($partly->id)->status]);
    }
}
