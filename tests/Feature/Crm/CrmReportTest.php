<?php

namespace Tests\Feature\Crm;

use App\Crm\Models\CrmLead;
use Tests\ApiTestCase;

/**
 * The funnel: the open pipeline stage by stage (count, expected revenue, revenue weighted by the chance), what was
 * won and lost in a period, the win rate, the same per salesperson, and why opportunities were lost.
 */
class CrmReportTest extends ApiTestCase
{
    use CrmFixtures;

    public function test_the_funnel_counts_the_pipeline_by_stage_and_the_closings_of_a_period(): void
    {
        $stages = $this->pipeline();
        $team = $this->newTeam();
        $admin = $this->userId('admin');
        $sales = $this->userId('sales');
        $price = $this->lostReason();
        $other = $this->lostReason('QA Chose a competitor', 'اختبار — اختار منافسًا');
        $make = fn (float $revenue, string $stage, array $more = []) => $this->newLead($more + ['teamId' => $team, 'expectedRevenue' => $revenue, 'stageId' => $stages[$stage], 'probability' => 50]);
        $win = fn (array $l) => $this->expectOk($this->postAs('sales', "/api/crm/leads/{$l['id']}/win", ['stageId' => $stages['won']]));
        $lose = fn (array $l, string $reason) => $this->expectOk($this->postAs('sales', "/api/crm/leads/{$l['id']}/lose", ['reasonId' => $reason]));

        $make(1000, 'new');
        $make(3000, 'new', ['ownerId' => $admin]);
        $make(4000, 'offer');
        $w1 = $win($make(8000, 'offer'));
        $win($make(2000, 'visit', ['ownerId' => $admin]));
        $old = $win($make(5000, 'visit'));
        $lose($make(500, 'new'), $price);
        $lose($make(700, 'visit'), $price);
        $lose($make(900, 'visit', ['ownerId' => $admin]), $other);
        // one of the wins was closed last month
        CrmLead::whereKey($old['id'])->update(['closed_at' => now()->subDays(40)]);

        $funnel = fn (string $query = '', string $as = 'gm') => $this->expectOk($this->getAs($as, "/api/crm/report/funnel?team={$team}".($query ? "&{$query}" : '')));
        $all = $funnel();
        $this->assertSame(['period', 'stages', 'totals', 'bySalesperson', 'lostReasons'], array_keys($all));
        $this->assertSame(['from' => null, 'to' => null, 'appliesTo' => 'closedAt'], $all['period']);
        $row = fn (array $report, string $key) => collect($report['stages'])->firstWhere('id', $stages[$key]);
        $figures = fn (array $r) => [$r['count'], (float) $r['revenue'], (float) $r['weighted']];

        // by stage: what is open in it now; the won stage: what was won
        $this->assertSame([2, 4000.0, 2000.0], $figures($row($all, 'new')));
        $this->assertSame([0, 0.0, 0.0], $figures($row($all, 'visit')));
        $this->assertSame([1, 4000.0, 2000.0], $figures($row($all, 'offer')));
        $this->assertSame([3, 15000.0, 15000.0], $figures($row($all, 'won')));
        $this->assertSame(['QA New', false], [$row($all, 'new')['nameEn'], $row($all, 'new')['isWon']]);

        // totals and the win rate: 3 won of 6 decided
        $this->assertSame([3, 8000.0, 4000.0], $figures($all['totals']['open']));
        $this->assertSame([3, 15000.0], array_slice($figures($all['totals']['won']), 0, 2));
        $this->assertSame([3, 2100.0], array_slice($figures($all['totals']['lost']), 0, 2));
        $this->assertEquals(50.0, $all['totals']['winRate']);

        // by salesperson
        $person = fn (array $report, string $id) => collect($report['bySalesperson'])->first(fn ($p) => $p['owner']['id'] === $id);
        $mine = $person($all, $sales);
        $this->assertEquals([2, 5000, 2500, 2, 13000, 2, 50.0], [$mine['open'], $mine['openRevenue'], $mine['weighted'], $mine['won'], $mine['wonRevenue'], $mine['lost'], $mine['winRate']]);
        $his = $person($all, $admin);
        $this->assertEquals([1, 3000, 1, 2000, 1, 50.0, 'admin'], [$his['open'], $his['openRevenue'], $his['won'], $his['wonRevenue'], $his['lost'], $his['winRate'], $his['owner']['username']]);
        $this->assertSame([$sales, $admin], array_map(fn ($p) => $p['owner']['id'], $all['bySalesperson']), 'the fullest pipeline first');

        // why opportunities were lost
        $this->assertEquals([['QA Too expensive', 2, 1200], ['QA Chose a competitor', 1, 900]], array_map(fn ($r) => [$r['reason']['nameEn'], $r['count'], $r['revenue']], $all['lostReasons']));

        // a period counts closings by the day they were closed; the open pipeline is a picture of now
        $recent = $funnel('from='.now()->subDays(7)->toDateString().'&to='.now()->toDateString());
        $this->assertSame([2, 10000.0], array_slice($figures($recent['totals']['won']), 0, 2));
        $this->assertSame(3, $recent['totals']['lost']['count']);
        $this->assertEquals(40.0, $recent['totals']['winRate']);
        $this->assertSame([3, 8000.0, 4000.0], $figures($recent['totals']['open']));
        $this->assertSame(2, $row($recent, 'won')['count']);
        $before = $funnel('to='.now()->subDays(30)->toDateString());
        $this->assertSame([1, 0, 100.0], [$before['totals']['won']['count'], $before['totals']['lost']['count'], (float) $before['totals']['winRate']]);
        $future = $funnel('from='.now()->addDay()->toDateString());
        $this->assertSame([0, 0, null, 3], [$future['totals']['won']['count'], $future['totals']['lost']['count'], $future['totals']['winRate'], $future['totals']['open']['count']]);
        $this->expectRejected($this->getAs('gm', '/api/crm/report/funnel?from=yesterday'), 'INVALID_INPUT', [400]);

        // one salesperson's funnel
        $only = $funnel("owner={$admin}");
        $this->assertSame([1, 1, 1], [$only['totals']['open']['count'], $only['totals']['won']['count'], $only['totals']['lost']['count']]);
        $this->assertCount(1, $only['bySalesperson']);
        $this->assertSame($w1['number'], $this->lead($w1['id'])['number']);
    }
}
