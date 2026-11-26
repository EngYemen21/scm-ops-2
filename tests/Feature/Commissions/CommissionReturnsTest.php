<?php

namespace Tests\Feature\Commissions;

use App\Commissions\Models\CommissionLine;
use Tests\ApiTestCase;

/**
 * What takes a commission back: a credit note (a negative line by the same rule as the invoice it credits — when the
 * plan is net of returns), and an invoice cancelled after its statement was closed (a reversal in the open statement).
 * An invoice cancelled while its line is still in a draft simply leaves the draft.
 * Months of this class: March and April 2026. The credit notes come out of the real returns flow.
 */
class CommissionReturnsTest extends ApiTestCase
{
    use CommissionFixtures;

    public function test_a_credit_note_takes_its_share_back_and_a_cancelled_invoice_is_reversed(): void
    {
        $rep = $this->person('net');
        $gross = $this->person('gross');
        $customer = $this->customer('ret');
        $product = $this->item('ret');
        $sold = fn (object $by, int $qty, string $day) => $this->invoiced($customer, [[$product, $qty]], ['by' => $by->username, 'date' => $day]);
        $window = ['validFrom' => '2026-03-01', 'validTo' => '2026-04-30', 'rules' => [['scope' => 'all', 'rateType' => 'flat', 'rate' => 5]]];
        $this->activePlan(['members' => [$rep->id], 'netOfReturns' => true] + $window);
        $this->activePlan(['members' => [$gross->id], 'netOfReturns' => false] + $window);
        $commission = fn (object $who, string $period) => $this->commissionByDocument($this->statementOf($who->id, $period));

        $first = $sold($rep, 40, '2026-03-10');          // net 400 → 20.00
        $second = $sold($rep, 10, '2026-03-15');         // net 100 → 5.00
        $theirs = $sold($gross, 20, '2026-03-12');       // net 200 → 10.00
        $note = $this->creditNote($first, $product, 4, '2026-03-20');     // 4 units back: net 40, VAT 6
        $this->creditNote($theirs, $product, 5, '2026-03-22');
        $this->assertSame(['credit_note', '40.00', '46.00', $first->id], [$note->kind, (string) $note->subtotal, (string) $note->total, $note->credit_of_id]);

        // ── March: the credit note is a negative line beside its invoice; a plan that is not net of returns ignores it
        $run = $this->calc('2026-03');
        $this->assertSame(['23.00', '10.00'], [$this->outcomeOf($run, $rep->id)['total'], $this->outcomeOf($run, $gross->id)['total']]);
        $this->assertSame([$first->number => 20.0, $second->number => 5.0, $note->number => -2.0], $commission($rep, '2026-03'));
        $this->assertSame([$theirs->number => 10.0], $commission($gross, '2026-03'));
        $march = $this->statementOf($rep->id, '2026-03');
        $this->assertSame([3, '460.00', '23.00'], [$march['linesCount'], $march['baseTotal'], $march['commissionTotal']]);
        $back = collect($this->linesOf($march))->firstWhere('docNumber', $note->number);
        $this->assertSame(['credit_note', 'CustomerInvoice', $note->id, '2026-03-20', '-40.00', '-46.00', '-2.0000', $rep->id], [$back['kind'], $back['sourceType'], $back['sourceId'], $back['docDate'], $back['baseAmount'], $back['invoiceTotal'], $back['commission'], $back['salesperson']['id']]);
        $this->assertEquals(['earnOn' => 'invoiced', 'base' => 'net', 'net' => -40, 'tax' => -6, 'cogs' => -24], $back['explain']);
        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'frozen' => 0, 'removed' => 0], $this->calc('2026-03')['counts']);
        $url = self::API.'/statements/'.$march['number'];
        $this->expectOk($this->postAs('finance', "{$url}/submit"));
        $this->expectOk($this->postAs('gm', "{$url}/approve"));

        // ── April: a second credit note of the March invoice, the other March invoice is cancelled, and a new sale
        $second->refresh();
        $laterNote = $this->creditNote($first, $product, 6, '2026-04-05');      // net 60 → −3.00
        $this->expectOk($this->postAs('finance', "/api/finance/invoices/{$second->number}/cancel", ['reason' => 'فاتورة صدرت بالخطأ']));
        $third = $sold($rep, 30, '2026-04-10');                                 // net 300 → 15.00
        $run = $this->outcomeOf($this->calc('2026-04'), $rep->id);
        $this->assertSame(['created', 3, '7.00'], [$run['outcome'], $run['lines'], $run['total']]);   // 15.00 − 3.00 − 5.00
        $april = $this->statementOf($rep->id, '2026-04');
        $lines = collect($this->linesOf($april))->keyBy('docNumber');
        $this->assertSame(['invoice', 'credit_note', 'reversal'], [$lines[$third->number]['kind'], $lines[$laterNote->number]['kind'], $lines[$second->number]['kind']]);
        $marchLine = collect($this->linesOf($march))->firstWhere('docNumber', $second->number);
        $this->assertSame(['-100.00', '-5.0000', $marchLine['id'], 'cancelled', $march['number'], 'cancelled'], [$lines[$second->number]['baseAmount'], $lines[$second->number]['commission'], $lines[$second->number]['reversesId'],
            $lines[$second->number]['explain']['reversal'], $lines[$second->number]['explain']['of'], $lines[$second->number]['paymentState']]);
        $this->assertSame(['-60.00', '-3.0000'], [$lines[$laterNote->number]['baseAmount'], $lines[$laterNote->number]['commission']]);
        $this->assertSame(['unchanged', '7.00'], [$this->outcomeOf($this->calc('2026-04'), $rep->id)['outcome'], $this->outcomeOf($this->calc('2026-04'), $rep->id)['total']]);
        $this->assertSame('frozen', $this->outcomeOf($this->calc('2026-03'), $rep->id)['outcome']);
        $this->assertSame('23.00', $this->statementOf($rep->id, '2026-03')['total'], 'the closed statement keeps what it said');
        $this->assertSame([5, 1, 2], [CommissionLine::where('beneficiary_id', $rep->id)->whereIn('kind', ['invoice', 'credit_note'])->count(),
            CommissionLine::where('beneficiary_id', $rep->id)->where('kind', 'reversal')->count(), CommissionLine::where('beneficiary_id', $rep->id)->where('kind', 'credit_note')->count()]);

        // ── an invoice cancelled while its line is still in a draft simply leaves the draft
        $mistake = $sold($rep, 10, '2026-04-12');                               // net 100 → 5.00
        $run = $this->outcomeOf($this->calc('2026-04'), $rep->id);
        $this->assertSame(['updated', 4, '12.00', 1, 0], [$run['outcome'], $run['lines'], $run['total'], $run['added'], $run['removed']]);
        $this->expectOk($this->postAs('finance', "/api/finance/invoices/{$mistake->number}/cancel", ['reason' => 'أُدخلت مرتين']));
        // submitting recalculates first: the figures moved, so the manager is shown them before anything is submitted
        $aprilUrl = self::API.'/statements/'.$april['number'];
        $changed = $this->expectRejected($this->postAs('finance', "{$aprilUrl}/submit"), 'COMMISSION_STATEMENT_CHANGED', [422]);
        $this->assertSame(['7.00', 3, 1], [$changed['details']['total'], $changed['details']['lines'], $changed['details']['removed']]);
        $this->assertSame(['draft', '7.00', 3], [$this->statementOf($rep->id, '2026-04')['status'], $this->statementOf($rep->id, '2026-04')['total'], $this->statementOf($rep->id, '2026-04')['linesCount']]);
        $this->assertSame(0, CommissionLine::where('source_id', $mistake->id)->count());
        $this->assertSame('submitted', $this->expectOk($this->postAs('finance', "{$aprilUrl}/submit"))['status']);
    }
}
