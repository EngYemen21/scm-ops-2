<?php

namespace Tests\Feature\Credit;

use App\Credit\Models\CreditRequest;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * The lists: who sees which requests (a user who only raises requests sees his own; a request he may not see is "not
 * found"), search (never by an identity number or a bank account), filters, a total sort order, paging, the counts.
 * Assertions are on the story's own requests (rule 13: the tables are shared).
 */
class CreditListTest extends ApiTestCase
{
    use CreditFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->followOdoo(false);
        $this->plainPolicies();
        $this->twoStagePath();
    }

    public function test_visibility_search_filters_sort_and_paging(): void
    {
        $tag = self::tag();
        $other = $this->user('credreq', ['page.credit', 'credit.request']);
        $a = $this->newRequest($this->customer(['name_ar' => "ألفا {$tag}"]), ['companyName' => "ألفا للتجارة {$tag}", 'requestedCreditLimit' => 10000]);
        $b = $this->newRequest($this->customer(['name_ar' => "بيتا {$tag}"]), ['companyName' => "بيتا للتجارة {$tag}", 'requestedCreditLimit' => 30000]);
        $c = $this->newRequest($this->customer(['name_ar' => "جاما {$tag}"]), ['companyName' => "جاما للتجارة {$tag}", 'requestedCreditLimit' => 20000], $other);
        $this->expectOk($this->postAs('sales', "/api/credit/requests/{$b['id']}/submit"));

        // sales sees his two, not the other requester's; that one is "not found" to him
        $mine = $this->expectOk($this->getAs('sales', "/api/credit/requests?q={$tag}&pageSize=50"));
        $this->assertEqualsCanonicalizing([$a['number'], $b['number']], array_column($mine['items'], 'number'));
        $this->expectRejected($this->getAs('sales', "/api/credit/requests/{$c['id']}"), 'CREDIT_REQUEST_NOT_FOUND', [404]);
        $this->expectRejected($this->patchAs('sales', "/api/credit/requests/{$c['id']}", ['city' => 'x']), 'CREDIT_REQUEST_NOT_FOUND', [404]);
        // the reviewer sees all three
        $all = $this->expectOk($this->getAs('finance', "/api/credit/requests?q={$tag}&sort=requestedCreditLimit&order=desc&pageSize=50"));
        $this->assertSame([$b['number'], $c['number'], $a['number']], array_column($all['items'], 'number'));
        $this->assertSame('60000.00', $all['totals']['requestedCreditLimit']);
        $asc = $this->expectOk($this->getAs('finance', "/api/credit/requests?q={$tag}&sort=requestedCreditLimit&order=asc&pageSize=2&page=2"));
        $this->assertSame([3, 2, 2], [$asc['total'], $asc['pages'], $asc['page']]);
        $this->assertSame([$b['number']], array_column($asc['items'], 'number'));

        // filters
        $this->assertSame([$b['number']], array_column($this->expectOk($this->getAs('finance', "/api/credit/requests?q={$tag}&state=work"))['items'], 'number'));
        $this->assertEqualsCanonicalizing([$a['number'], $c['number']], array_column($this->expectOk($this->getAs('finance', "/api/credit/requests?q={$tag}&state=draft"))['items'], 'number'));
        $this->assertSame([$b['number']], array_column($this->expectOk($this->getAs('finance', "/api/credit/requests?q={$tag}&scope=waiting"))['items'], 'number'));
        $this->assertSame([$a['number']], array_column($this->expectOk($this->getAs('finance', "/api/credit/requests?q={$a['number']}"))['items'], 'number'));
        $this->assertSame(0, $this->expectOk($this->getAs('finance', "/api/credit/requests?q={$tag}&origin=odoo"))['total']);
        $this->assertSame(0, $this->expectOk($this->getAs('finance', '/api/credit/requests?q=1012345678'))['total'], 'an identity number is never a search key');
        $this->expectRejected($this->getAs('finance', '/api/credit/requests?state=nonsense'), null, [400]);

        // a sort over equal values is still a total order (by id)
        DB::table('credit_requests')->whereIn('id', [$a['id'], $b['id'], $c['id']])->update(['request_date' => '2026-09-18']);
        $ids = collect([$a['id'], $b['id'], $c['id']])->sort()->values()->all();
        $this->assertSame($ids, array_column($this->expectOk($this->getAs('finance', "/api/credit/requests?q={$tag}&sort=requestDate&order=asc"))['items'], 'id'));

        // the counts of the page head
        $s = $this->expectOk($this->getAs('finance', '/api/credit/summary'));
        $this->assertGreaterThanOrEqual(1, $s['waiting']);
        $this->assertSame(CreditRequest::where('requester_id', DB::table('users')->where('username', 'sales')->value('id'))->whereNotIn('state', ['approved', 'rejected', 'cancelled'])->count(),
            $this->expectOk($this->getAs('sales', '/api/credit/summary'))['mine']);

        // the customer picker says which customer has an open request
        $picked = $this->expectOk($this->getAs('sales', '/api/credit/customers?q='.urlencode("ألفا {$tag}")))['items'];
        $this->assertSame($a['number'], $picked[0]['openRequest']);
    }

    public function test_comments_are_open_to_who_sees_the_request(): void
    {
        $r = $this->newRequest();
        $d = $this->expectOk($this->postAs('finance', "/api/credit/requests/{$r['id']}/comments", ['text' => 'نحتاج كشف حساب ستة أشهر']));
        $this->assertSame('comment', collect($d['events'])->last()['kind']);
        $this->expectRejected($this->postAs('finance', "/api/credit/requests/{$r['id']}/comments", ['text' => '']), null, [400]);
    }
}
