<?php

namespace Tests\Feature\Commissions;

use App\Commissions\Models\CommissionAssignment;
use App\Commissions\Models\CommissionLine;
use App\Models\AuditLog;
use Tests\ApiTestCase;

/**
 * Whose sale an invoice is. OPS documents carry no salesperson: it is read from the representative the sales platform
 * names on the order (a name a person assigned to an OPS user, or that user's own login), then from the salesperson
 * the customer is assigned to, then from the user who entered the order — and an invoice nobody answers for is listed
 * as "unassigned". Also here: the plan for "every salesperson" (the default), the plan that names a person deciding
 * over it, and that a sale is paid to one salesperson only, whatever is reassigned later.
 * Month of this class: February 2026.
 */
class CommissionSalespeopleTest extends ApiTestCase
{
    use CommissionFixtures;

    public function test_whose_sale_it_is_the_default_plan_and_the_unassigned_invoices(): void
    {
        $sara = $this->person('sara');
        $omar = $this->person('omar');
        $named = $this->person('named');
        $a = $this->customer('a');
        $b = $this->customer('b');
        $c = $this->customer('c');
        $d = $this->customer('d');
        $product = $this->item('sp');
        $day = ['date' => '2026-02-10'];
        $fromPlatform = $this->invoiced($a, [[$product, 10]], ['rep' => 'سارة العتيبي'] + $day);      // net 100 · the sales platform names a representative OPS does not know yet
        $ofCustomer = $this->invoiced($b, [[$product, 20]], $day);                                   // net 200 · nothing on the order
        $entered = $this->invoiced($c, [[$product, 30]], ['by' => $named->username] + $day);         // net 300 · entered in OPS by a salesperson
        $nobodys = $this->invoiced($d, [[$product, 5]], $day);                                       // net 50
        $this->activePlan(['everyone' => true, 'validFrom' => '2026-02-01', 'validTo' => '2026-02-28', 'rules' => [['scope' => 'all', 'rateType' => 'flat', 'rate' => 1]], 'nameAr' => 'الخطة العامة للمندوبين']);
        $this->activePlan(['members' => [$named->id], 'validFrom' => '2026-02-01', 'validTo' => '2026-02-28', 'rules' => [['scope' => 'all', 'rateType' => 'flat', 'rate' => 4]]]);

        // ── before anybody said whose sales they are: three invoices of the month are nobody's
        $open = $this->expectOk($this->getAs('finance', self::API.'/unassigned?period=2026-02'));
        $this->assertSame(['2026-02', 4, 3, '350.00'], [$open['period'], $open['invoices'], $open['count'], $open['net']]);
        $this->assertEqualsCanonicalizing([[$fromPlatform->number, 'سارة العتيبي', $a->code], [$ofCustomer->number, null, $b->code], [$nobodys->number, null, $d->code]],
            array_map(fn (array $i) => [$i['number'], $i['rep'], $i['customer']['code']], $open['items']));
        $run = $this->calc('2026-02');
        $this->assertSame([['count' => 3, 'net' => '350.00'], '12.00'], [$run['unassigned'], $this->outcomeOf($run, $named->id)['total']], 'the plan that names a person decides for him, not the default (4 %, not 1 %)');
        $this->assertNull($this->outcomeOf($run, $sara->id));

        // ── a person says it once: this name of the sales platform is that user; this customer is that salesperson's
        $put = fn (string $user, array $body) => $this->putAs($user, self::API.'/assignments', $body);
        $this->expectRejected($put('gm', ['kind' => 'rep', 'subject' => 'سارة العتيبي', 'user' => $sara->id]), 'FORBIDDEN', [403]);
        $this->expectRejected($put('finance', ['kind' => 'team', 'subject' => 'x', 'user' => $sara->id]), 'INVALID_INPUT', [400]);
        $this->expectRejected($put('finance', ['kind' => 'rep', 'subject' => 'سارة العتيبي', 'user' => 'no-such-user']), 'COMMISSION_USER_UNKNOWN', [400]);
        $this->expectRejected($put('finance', ['kind' => 'customer', 'subject' => 'NO-SUCH-CUSTOMER', 'user' => $omar->id]), 'CUSTOMER_NOT_FOUND', [404]);
        $rep = $this->expectOk($put('finance', ['kind' => 'rep', 'subject' => '  سارة   العتيبي ', 'user' => $sara->username]));
        $this->assertSame(['rep', 'سارة العتيبي', 'سارة العتيبي', $sara->id], [$rep['kind'], $rep['subject'], $rep['label'], $rep['user']['id']], 'the name is compared without regard to spacing');
        $own = $this->expectOk($put('finance', ['kind' => 'customer', 'subject' => $b->code, 'user' => $omar->id]));
        $this->assertSame(['customer', $b->id, $omar->id], [$own['kind'], $own['subject'], $own['user']['id']]);
        $this->assertStringContainsString($b->code, $own['label']);
        $this->assertSame([$rep['id']], array_column($this->expectOk($this->getAs('finance', self::API."/assignments?user={$sara->id}"))['items'], 'id'));
        $this->assertSame([$own['id']], array_column($this->expectOk($this->getAs('finance', self::API."/assignments?kind=customer&q={$b->code}"))['items'], 'id'));
        // the representative's own login on an order is an identity too — and what the order says comes before the customer's salesperson
        $byLogin = $this->invoiced($b, [[$product, 40]], ['rep' => strtoupper($sara->username)] + $day);   // net 400

        $run = $this->calc('2026-02');
        $this->assertSame([['count' => 1, 'net' => '50.00'], '5.00', '2.00', '12.00'],
            [$run['unassigned'], $this->outcomeOf($run, $sara->id)['total'], $this->outcomeOf($run, $omar->id)['total'], $this->outcomeOf($run, $named->id)['total']]);
        $via = fn (object $who) => array_column(array_map(fn (array $l) => [$l['docNumber'], $l['salespersonVia']], $this->linesOf($this->statementOf($who->id, '2026-02'))), 1, 0);
        $this->assertSame([[$fromPlatform->number => 'rep', $byLogin->number => 'rep'], [$ofCustomer->number => 'customer'], [$entered->number => 'creator']], [$via($sara), $via($omar), $via($named)]);
        $this->assertSame([$nobodys->number], array_column($this->expectOk($this->getAs('finance', self::API.'/unassigned?period=2026-02'))['items'], 'number'));

        // ── a change of mind moves the sale while the statements are drafts: one run, and exactly one of them holds it
        $moved = $this->expectOk($put('finance', ['kind' => 'customer', 'subject' => $b->id, 'user' => $sara->id]));
        $this->assertSame([$own['id'], $sara->id, 1], [$moved['id'], $moved['user']['id'], CommissionAssignment::where('kind', 'customer')->where('subject', $b->id)->count()]);
        $this->assertSame(['COMMISSION.ASSIGNMENT.SET', 'COMMISSION.ASSIGNMENT.CHANGE'], AuditLog::where('entity_type', 'CommissionAssignment')->where('entity_id', $own['id'])->orderBy('at')->orderBy('id')->pluck('action')->all());
        $run = $this->calc('2026-02');
        $this->assertSame(['7.00', 'removed', null], [$this->outcomeOf($run, $sara->id)['total'], $this->outcomeOf($run, $omar->id)['outcome'], $this->statementOf($omar->id, '2026-02')],
            'the draft that lost its only invoice is withdrawn: nothing was owed on it');
        $this->assertSame(1, CommissionLine::where('source_id', $ofCustomer->id)->count());
        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'frozen' => 0, 'removed' => 0], $this->calc('2026-02')['counts']);

        // ── once a statement is closed it keeps the sale it recorded: reassigning the customer does not pay it to somebody else as well
        $saras = $this->statementOf($sara->id, '2026-02');
        $this->expectOk($this->postAs('finance', self::API."/statements/{$saras['number']}/submit"));
        $this->expectOk($put('finance', ['kind' => 'customer', 'subject' => $b->code, 'user' => $omar->id]));
        $run = $this->calc('2026-02');
        $this->assertSame(['frozen', '7.00', null], [$this->outcomeOf($run, $sara->id)['outcome'], $this->outcomeOf($run, $sara->id)['total'], $this->outcomeOf($run, $omar->id)]);
        $this->assertSame([$sara->id], CommissionLine::where('source_id', $ofCustomer->id)->pluck('beneficiary_id')->all());
        // a new invoice of that customer is the new salesperson's
        $next = $this->invoiced($b, [[$product, 15]], ['date' => '2026-02-20']);   // net 150
        $this->assertSame('1.50', $this->outcomeOf($this->calc('2026-02'), $omar->id)['total']);
        $this->assertSame([$next->number => 1.5], $this->commissionByDocument($this->statementOf($omar->id, '2026-02')));

        // ── removing an answer: the invoices fall back to what else names their salesperson (here: nothing)
        $this->expectRejected($this->postAs('sales', self::API."/assignments/{$own['id']}/remove"), 'FORBIDDEN', [403]);
        $this->assertSame(['ok' => true, 'id' => $own['id']], $this->expectOk($this->postAs('finance', self::API."/assignments/{$own['id']}/remove")));
        $this->expectRejected($this->postAs('finance', self::API."/assignments/{$own['id']}/remove"), 'COMMISSION_ASSIGNMENT_NOT_FOUND', [404]);
        $run = $this->calc('2026-02');
        $this->assertSame([['count' => 2, 'net' => '200.00'], 'removed'], [$run['unassigned'], $this->outcomeOf($run, $omar->id)['outcome']]);
        $this->assertSame(1, AuditLog::where('action', 'COMMISSION.ASSIGNMENT.REMOVE')->where('entity_id', $own['id'])->count());
    }
}
