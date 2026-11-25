<?php

namespace Tests\Feature\Commissions;

use App\Commissions\Models\CommissionLine;
use Tests\ApiTestCase;

/**
 * The rate by what was sold and to whom: rules by product category (sub-categories included) and by customer, the
 * first rule that covers an invoice line deciding; tiers on the month's base — on the whole and progressively;
 * an invoice a person excludes (and how that moves a tier); and the three bases — before tax, with tax, the margin.
 * Month of this class: July 2026.
 */
class CommissionRulesTest extends ApiTestCase
{
    use CommissionFixtures;

    public function test_rules_by_category_and_customer_tiers_exclusions_and_the_base(): void
    {
        $rep = $this->person('rules');
        $frozen = $this->category('frozen');
        $iceCream = $this->category('ice', $frozen);   // a rule on «frozen» covers what is filed under its sub-categories
        $dry = $this->category('dry');
        $ice = $this->item('ice', $iceCream);
        $rice = $this->item('rice', $dry);
        $anyone = $this->customer('any');
        $keyAccount = $this->customer('key');
        $on = fn (string $day) => ['by' => $rep->username, 'date' => $day];
        $mixed = $this->invoiced($anyone, [[$ice, 20], [$rice, 40]], $on('2026-07-03'));   // frozen 200 + dry 400 on one invoice
        $big = $this->invoiced($keyAccount, [[$rice, 100]], $on('2026-07-08'));            // the key account, dry 1,000
        $keyIce = $this->invoiced($keyAccount, [[$ice, 10]], $on('2026-07-12'));           // the key account, frozen 100: the category rule comes first
        $late = $this->invoiced($anyone, [[$rice, 70]], $on('2026-07-20'));                // dry 700
        $rules = fn (string $mode) => [
            ['scope' => 'category', 'scopeIds' => [$frozen->id], 'rateType' => 'flat', 'rate' => 3, 'label' => 'مجمدات'],
            ['scope' => 'customer', 'scopeIds' => [$keyAccount->id], 'rateType' => 'flat', 'rate' => 0.5],
            ['scope' => 'all', 'rateType' => 'tiered', 'tierMode' => $mode, 'tiers' => [['upTo' => 1000, 'rate' => 1], ['upTo' => null, 'rate' => 2]]],
        ];
        $terms = ['members' => [$rep->id], 'validFrom' => '2026-07-01', 'validTo' => '2026-07-31', 'nameAr' => 'خطة بالقواعد والشرائح'];
        $plan = $this->activePlan(['rules' => $rules('whole')] + $terms);
        [$byCategory, $byCustomer, $rest] = array_column($plan['rules'], 'id');

        // ── the first rule that covers a line decides; the "rest" rule is tiered on its own month's base: 400 + 700 = 1,100 → 2 % on all of it
        $this->assertSame('36.00', $this->outcomeOf($this->calc('2026-07'), $rep->id)['total']);
        $statement = $this->statementOf($rep->id, '2026-07');
        $this->assertSame([5, '2400.00', '36.00'], [$statement['linesCount'], $statement['baseTotal'], $statement['commissionTotal']]);
        $rows = fn () => array_map(fn (array $l) => [$l['docNumber'], $l['ruleId'], $l['baseAmount'], (float) $l['rate'], $l['commission']], $this->linesOf($this->statementOf($rep->id, '2026-07')));
        $this->assertEqualsCanonicalizing([
            [$mixed->number, $byCategory, '200.00', 3.0, '6.0000'], [$mixed->number, $rest, '400.00', 2.0, '8.0000'],
            [$big->number, $byCustomer, '1000.00', 0.5, '5.0000'], [$keyIce->number, $byCategory, '100.00', 3.0, '3.0000'], [$late->number, $rest, '700.00', 2.0, '14.0000'],
        ], $rows());
        $lines = collect($this->linesOf($statement));
        $frozenPart = $lines->first(fn (array $l) => $l['docNumber'] === $mixed->number && $l['ruleId'] === $byCategory);
        $this->assertSame(['مجمدات', '690.00'], [$frozenPart['ruleLabel'], $frozenPart['invoiceTotal']]);
        $this->assertEquals(['earnOn' => 'invoiced', 'base' => 'net', 'net' => 200, 'tax' => 30, 'cogs' => 120, 'share' => 0.3333], $frozenPart['explain'], 'the part of the invoice the rule covers');
        $tiered = $lines->first(fn (array $l) => $l['docNumber'] === $late->number);
        $this->assertEquals(['mode' => 'whole', 'total' => 1100, 'from' => 1000, 'upTo' => null], $tiered['explain']['tier'], 'the tier that decided is on the line');
        $breakdown = collect($statement['breakdown'])->keyBy('ruleId');
        $this->assertSame([[2, '300.00', '9.00'], [1, '1000.00', '5.00'], [2, '1100.00', '22.00']],
            array_map(fn (string $id) => [$breakdown[$id]['lines'], $breakdown[$id]['base'], $breakdown[$id]['commission']], [$byCategory, $byCustomer, $rest]));

        // ── an invoice a person excludes stays on the statement and earns nothing; the month's base of its rule falls to 400 → the first tier
        $excludeUrl = self::API.'/lines/'.$tiered['id'].'/exclude';
        $this->expectRejected($this->postAs('finance', $excludeUrl), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('gm', $excludeUrl, ['reason' => 'بيع بالتكلفة']), 'FORBIDDEN', [403]);
        $excluded = $this->expectOk($this->postAs('finance', $excludeUrl, ['reason' => 'بيع بالتكلفة لتصريف المخزون']));
        $this->assertSame([5, '1700.00', '18.00', '18.00'], [$excluded['linesCount'], $excluded['baseTotal'], $excluded['commissionTotal'], $excluded['total']]);
        $after = collect($this->linesOf($excluded))->keyBy('id');
        $this->assertSame([true, 'بيع بالتكلفة لتصريف المخزون', '0.0000', '700.00'], [$after[$tiered['id']]['excluded'], $after[$tiered['id']]['excludeReason'], $after[$tiered['id']]['commission'], $after[$tiered['id']]['baseAmount']]);
        $this->assertEqualsCanonicalizing([[$mixed->number, $byCategory, '200.00', 3.0, '6.0000'], [$mixed->number, $rest, '400.00', 1.0, '4.0000'],
            [$big->number, $byCustomer, '1000.00', 0.5, '5.0000'], [$keyIce->number, $byCategory, '100.00', 3.0, '3.0000'], [$late->number, $rest, '700.00', 0.0, '0.0000']], $rows());
        $this->expectRejected($this->postAs('finance', $excludeUrl, ['reason' => 'مرة ثانية']), 'COMMISSION_LINE_STATE', [422]);
        // a recalculation keeps the decision
        $this->assertSame(['unchanged', '18.00'], [$this->outcomeOf($this->calc('2026-07'), $rep->id)['outcome'], $this->outcomeOf($this->calc('2026-07'), $rep->id)['total']]);
        $this->assertTrue((bool) CommissionLine::find($tiered['id'])->excluded);
        // … and it is taken back the same way
        $this->expectRejected($this->postAs('sales', self::API.'/lines/'.$tiered['id'].'/include'), 'FORBIDDEN', [403]);
        $this->assertSame('36.00', $this->expectOk($this->postAs('finance', self::API.'/lines/'.$tiered['id'].'/include'))['total']);
        $this->expectRejected($this->postAs('finance', self::API.'/lines/'.$tiered['id'].'/include'), 'COMMISSION_LINE_STATE', [422]);
        $this->assertSame(2, $this->expectOk($this->getAs('finance', self::API."/statements/{$statement['number']}/lines?kind=invoice&q={$mixed->number}"))['total']);

        // ── progressive tiers: each slice of the month's base at its own rate, in the order of the days
        $update = fn (array $over) => $this->expectOk($this->patchAs('finance', self::API."/plans/{$plan['code']}", $this->planBody($over + $terms)));
        $this->assertSame([$byCategory, $byCustomer, $rest], array_column($update(['rules' => $rules('progressive')])['rules'], 'id'), 'rules keep their ids, so the lines stay the same lines');
        $run = $this->outcomeOf($this->calc('2026-07'), $rep->id);
        $this->assertSame(['updated', '26.00', 0, 2, 0], [$run['outcome'], $run['total'], $run['added'], $run['changed'], $run['removed']]);
        $this->assertEqualsCanonicalizing([[$mixed->number, $byCategory, '200.00', 3.0, '6.0000'], [$mixed->number, $rest, '400.00', 1.0, '4.0000'],     // 0 → 400 at 1 %
            [$big->number, $byCustomer, '1000.00', 0.5, '5.0000'], [$keyIce->number, $byCategory, '100.00', 3.0, '3.0000'],
            [$late->number, $rest, '700.00', 1.1429, '8.0000']], $rows());                                                                          // 400 → 1,100: 600 at 1 % + 100 at 2 %
        $this->assertEquals(['mode' => 'progressive', 'from' => 400, 'to' => 1100], collect($this->linesOf($statement))->firstWhere('docNumber', $late->number)['explain']['tier']);
        $this->assertSame('progressive', $this->statementOf($rep->id, '2026-07')['terms']['rules'][2]['tierMode'], 'the statement follows its plan while it is a draft');

        // ── the base: with tax (the rule's share of the invoice's VAT is added to it) …
        $update(['rules' => $rules('progressive'), 'base' => 'gross']);
        $this->assertSame('31.40', $this->outcomeOf($this->calc('2026-07'), $rep->id)['total']);   // 6.90 + 4.60 + 5.75 + 3.45 + 10.70
        $this->assertEqualsCanonicalizing([[$mixed->number, $byCategory, '230.00', 3.0, '6.9000'], [$mixed->number, $rest, '460.00', 1.0, '4.6000'],
            [$big->number, $byCustomer, '1150.00', 0.5, '5.7500'], [$keyIce->number, $byCategory, '115.00', 3.0, '3.4500'], [$late->number, $rest, '805.00', 1.3292, '10.7000']], $rows());
        $this->assertSame('2760.00', $this->statementOf($rep->id, '2026-07')['baseTotal']);
        // … and the margin (what was sold less what it cost: 10 − 6 a unit)
        $update(['rules' => $rules('progressive'), 'base' => 'margin']);
        $this->assertSame('10.00', $this->outcomeOf($this->calc('2026-07'), $rep->id)['total']);   // 2.40 + 1.60 + 2.00 + 1.20 + 2.80
        $this->assertEqualsCanonicalizing([[$mixed->number, $byCategory, '80.00', 3.0, '2.4000'], [$mixed->number, $rest, '160.00', 1.0, '1.6000'],
            [$big->number, $byCustomer, '400.00', 0.5, '2.0000'], [$keyIce->number, $byCategory, '40.00', 3.0, '1.2000'], [$late->number, $rest, '280.00', 1.0, '2.8000']], $rows());
        $this->assertEquals(['earnOn' => 'invoiced', 'base' => 'margin', 'net' => 1000, 'tax' => 150, 'cogs' => 600], collect($this->linesOf($statement))->firstWhere('docNumber', $big->number)['explain']);
        $this->assertSame(['960.00', 'margin'], [$this->statementOf($rep->id, '2026-07')['baseTotal'], $this->statementOf($rep->id, '2026-07')['terms']['base']]);
    }
}
