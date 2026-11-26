<?php

namespace Tests\Feature\ProductRequests;

use App\Models\Customer;
use App\ProductRequests\Models\ProductRequest;
use Tests\ApiTestCase;

/**
 * The lists of the section: search, every filter, server-side sort, paging — the board of what waits for review with
 * its ageing — the figures of the page head and what the filters pick from.
 */
class ProductRequestsListTest extends ApiTestCase
{
    use ProductRequestFixtures;

    public function test_the_list_searches_filters_sorts_and_pages(): void
    {
        $key = 'زعفران'.self::uid();
        $tag = self::tag();
        $category = $this->category();
        $supplier = $this->supplier();
        $customer = Customer::where('active', true)->orderBy('code')->firstOrFail();
        $existing = $this->catalogueProduct();

        $draft = $this->raise(['nameAr' => "{$key} مسودة", 'costPrice' => 5]);
        $sent = $this->waiting(['nameAr' => "{$key} خيوط فاخرة", 'nameEn' => "Saffron threads {$tag}", 'brand' => "ماركة{$tag}", 'category' => $category->code, 'supplier' => $supplier->code, 'customer' => $customer->code,
            'barcode' => "6287{$tag}", 'costPrice' => 900, 'salePrice' => 1200, 'storageClass' => 'chilled']);
        $review = $this->waiting(['nameAr' => "{$key} مطحون", 'costPrice' => 40.5], 'proc');
        $this->expectOk($this->postAs('inv', "/api/product-requests/{$review['id']}/start-review"));
        $returned = $this->waiting(['nameAr' => "{$key} سائل"]);
        $this->expectOk($this->postAs('gm', "/api/product-requests/{$returned['id']}/send-back", ['reason' => 'أضف السعر']));
        $approved = $this->waiting(['nameAr' => "{$key} معتمد", 'costPrice' => 300], 'proc');
        $this->expectOk($this->postAs('gm', "/api/product-requests/{$approved['id']}/approve"));
        $rejected = $this->waiting(['nameAr' => "{$key} مرفوض", 'costPrice' => 12]);
        $this->expectOk($this->postAs('gm', "/api/product-requests/{$rejected['id']}/reject", ['reason' => 'موجود', 'existingProduct' => $existing['sku']]));
        $cancelled = $this->raise(['nameAr' => "{$key} ملغى"]);
        $this->expectOk($this->postAs('sales', "/api/product-requests/{$cancelled['id']}/cancel"));

        $list = fn (string $query, string $as = 'sales') => $this->expectOk($this->getAs($as, '/api/product-requests?q='.rawurlencode($key)."&{$query}"));
        $numbers = fn (array $page) => array_column($page['items'], 'number');
        $all = [$draft['number'], $sent['number'], $review['number'], $returned['number'], $approved['number'], $rejected['number'], $cancelled['number']];

        $page = $list('pageSize=50');
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($page));
        $this->assertSame(array_reverse($all), $numbers($page), 'the newest first');
        $this->assertSame(array_values(array_diff($all, [$draft['number'], $cancelled['number']])), array_reverse($numbers($list('pageSize=50', 'proc'))), 'another author does not see what was never sent: the draft, and the draft that was cancelled as one');

        // one status, several, a group
        $this->assertSame([$sent['number']], $numbers($list('status=submitted')));
        $this->assertEqualsCanonicalizing([$sent['number'], $review['number']], $numbers($list('status=submitted,in_review')));
        $this->assertEqualsCanonicalizing([$sent['number'], $review['number']], $numbers($list('status=waiting')));
        $this->assertEqualsCanonicalizing([$draft['number'], $sent['number'], $review['number'], $returned['number']], $numbers($list('status=open')));
        $this->assertEqualsCanonicalizing([$approved['number'], $rejected['number'], $cancelled['number']], $numbers($list('status=closed')));
        $this->expectRejected($this->getAs('sales', '/api/product-requests?status=nonsense'), 'INVALID_INPUT', [400]);
        // whose
        $this->assertEqualsCanonicalizing([$draft['number'], $sent['number'], $returned['number'], $rejected['number'], $cancelled['number']], $numbers($list('scope=mine&pageSize=50')));
        $this->assertEqualsCanonicalizing([$review['number'], $approved['number']], $numbers($list('scope=mine', 'proc')));
        $this->assertEqualsCanonicalizing([$sent['number'], $review['number']], $numbers($list('scope=review', 'gm')));
        $this->assertEqualsCanonicalizing([$review['number'], $approved['number']], $numbers($list('requester='.$this->userId('proc'))));
        $this->assertEqualsCanonicalizing([$review['number'], $approved['number']], $numbers($list('requester=me', 'proc')));
        $this->expectRejected($this->getAs('sales', '/api/product-requests?scope=theirs'), 'INVALID_INPUT', [400]);
        // what
        $this->assertSame([$sent['number']], $numbers($list("category={$category->code}")));
        $this->assertSame([$sent['number']], $numbers($list("category={$category->id}")));
        $this->assertSame([$sent['number']], $numbers($list("supplier={$supplier->code}")));
        $this->assertSame([$sent['number']], $numbers($list("customer={$customer->code}")));
        $this->assertSame([$sent['number']], $numbers($list('storage=chilled')));
        $this->assertEqualsCanonicalizing([$approved['number'], $rejected['number']], $numbers($list('hasProduct=1')));
        $this->assertSame(5, $list('hasProduct=0&pageSize=50')['total']);
        $this->assertSame(7, $list('origin=ops&type=new&pageSize=50')['total']);
        $this->assertSame([0, 0, 0], [$list('origin=odoo')['total'], $list('origin=odoo_portal')['total'], $list('type=existing')['total']]);
        $this->expectRejected($this->getAs('sales', '/api/product-requests?origin=mars'), 'INVALID_INPUT', [400]);
        // when
        $today = now()->toDateString();
        $this->assertSame([7, 0], [$list("from={$today}&to={$today}&pageSize=50")['total'], $list('to='.now()->subDay()->toDateString())['total']]);
        $this->expectRejected($this->getAs('sales', '/api/product-requests?from=yesterday'), 'INVALID_INPUT', [400]);

        // search: number, names in both languages, brand, barcode, the product it became — and the name as people misspell it
        $find = fn (string $q, string $as = 'sales') => $numbers($this->expectOk($this->getAs($as, '/api/product-requests?pageSize=50&q='.rawurlencode($q))));
        $this->assertSame([$sent['number']], $find($sent['number']));
        $this->assertSame([$sent['number']], $find("SAFFRON threads {$tag}"));
        $this->assertSame([$sent['number']], $find("ماركة{$tag}"));
        $this->assertSame([$sent['number']], $find("6287{$tag}"));
        $this->assertSame([$rejected['number']], $find($existing['sku']));
        $this->assertSame([$approved['number']], $find($this->row($approved['id'])->product_sku));
        $this->assertSame([$sent['number']], $find(str_replace('ة', 'ه', "{$key} خيوط فاخرة")), '«فاخره» finds «فاخرة»');
        $this->assertSame([], $find('100%_لا_شيء'), 'the wildcards of LIKE are plain characters in a search');

        // sort (server side): the empty values last whichever way, and paging
        $this->assertSame([$sent['number'], $approved['number'], $review['number'], $rejected['number'], $draft['number']], array_slice($numbers($list('sort=costPrice&order=desc&pageSize=50')), 0, 5));
        $this->assertSame([$draft['number'], $rejected['number'], $review['number'], $approved['number'], $sent['number']], array_slice($numbers($list('sort=costPrice&order=asc&pageSize=50')), 0, 5));
        $this->assertSame($all, $numbers($list('sort=number&order=asc&pageSize=50')));
        $this->assertSame($sent['number'], $numbers($list('sort=nameAr&order=asc&pageSize=50&status=waiting'))[0]);
        $this->expectRejected($this->getAs('sales', '/api/product-requests?sort=secret'), 'INVALID_INPUT', [400]);
        $second = $list('sort=number&order=asc&pageSize=3&page=2');
        $this->assertSame([7, 2, 3, 3, array_slice($all, 3, 3)], [$second['total'], $second['page'], $second['pageSize'], $second['pages'], $numbers($second)]);

        // a row carries what the list shows
        $row = $list('status=submitted')['items'][0];
        $this->assertSame([$category->code, $supplier->code, $customer->code, '900.00', '1200.00', 'chilled', 'sales', true, false, 0, null],
            [$row['category']['code'], $row['supplier']['code'], $row['customer']['code'], $row['costPrice'], $row['salePrice'], $row['storageClass'], $row['requestedBy']['username'], $row['mine'], $row['owned'], $row['pictureCount'], $row['picture']]);
        $this->assertArrayNotHasKey('events', $row);
    }

    public function test_the_board_shows_what_waits_and_for_how_long(): void
    {
        $key = 'هيل'.self::uid();
        $make = function (string $name, int $daysAgo, ?string $move = null) use ($key) {
            $r = $this->waiting(['nameAr' => "{$key} {$name}"]);
            if ($move === 'review') {
                $this->expectOk($this->postAs('inv', "/api/product-requests/{$r['id']}/start-review"));
            }
            if ($move === 'back') {
                $this->expectOk($this->postAs('inv', "/api/product-requests/{$r['id']}/send-back", ['reason' => 'ناقص']));
            }
            ProductRequest::whereKey($r['id'])->update(['status_at' => now()->subDays($daysAgo)->subHours(2)]);

            return $r['number'];
        };
        $fresh = $make('اليوم', 0);
        $two = $make('يومان', 2);
        $five = $make('خمسة', 5);
        $old = $make('قديم', 12);
        $inReview = $make('عند المراجع', 4, 'review');
        $back = $make('عند صاحبه', 9, 'back');
        $this->raise(['nameAr' => "{$key} مسودة"]); // a draft waits for nobody
        $closed = $this->waiting(['nameAr' => "{$key} منتهٍ"]);
        $this->expectOk($this->postAs('gm', "/api/product-requests/{$closed['id']}/reject", ['reason' => 'لا']));

        $board = $this->expectOk($this->getAs('gm', '/api/product-requests/board?per=3&q='.rawurlencode($key)));
        $this->assertSame(['columns', 'per', 'ageing', 'waiting'], array_keys($board));
        $this->assertSame(['submitted', 'in_review', 'returned'], array_column($board['columns'], 'status'));
        [$queue, $reviewing, $returned] = $board['columns'];
        $this->assertSame([4, true, [$old, $five, $two]], [$queue['count'], $queue['more'], array_column($queue['items'], 'number')], 'the oldest first; the rest is read through the list');
        $this->assertSame([12, 5, 2], array_column($queue['items'], 'ageDays'));
        $this->assertNotNull($queue['oldestAt']);
        $this->assertSame([1, false, [$inReview], [4]], [$reviewing['count'], $reviewing['more'], array_column($reviewing['items'], 'number'), array_column($reviewing['items'], 'ageDays')]);
        $this->assertSame([1, [$back], [9]], [$returned['count'], array_column($returned['items'], 'number'), array_column($returned['items'], 'ageDays')]);
        // how old the reviewers' queue is: up to a day · 1–3 days · 3–7 days · more than a week
        $this->assertSame(['day' => 1, 'days3' => 1, 'week' => 2, 'older' => 1], array_column($board['ageing'], 'count', 'key'));
        $this->assertSame(5, $board['waiting']);
        // the rest of a column, in the same order
        $rest = $this->expectOk($this->getAs('gm', '/api/product-requests?status=submitted&sort=statusAt&order=asc&pageSize=3&page=2&q='.rawurlencode($key)));
        $this->assertSame([4, [$fresh]], [$rest['total'], array_column($rest['items'], 'number')]);
        // requests that entered the queue at the very same moment (a load from Odoo does that): the cards of a column
        // and its rest, read page by page through the list, show every request once — none twice, none skipped
        $tie = 'قرفة'.self::uid();
        $moment = now()->subDays(3);
        $tied = [];
        foreach (['أعواد', 'مطحونة', 'سيلانية', 'صينية', 'عضوية'] as $kind) {
            $r = $this->waiting(['nameAr' => "{$tie} {$kind}"]);
            ProductRequest::whereKey($r['id'])->update(['status_at' => $moment, 'created_at' => $moment]);
            $tied[] = $r['number'];
        }
        $column = $this->expectOk($this->getAs('gm', '/api/product-requests/board?per=2&q='.rawurlencode($tie)))['columns'][0];
        $seen = array_column($column['items'], 'number');
        foreach ([2, 3] as $p) {
            $seen = [...$seen, ...array_column($this->expectOk($this->getAs('gm', "/api/product-requests?status=submitted&sort=statusAt&order=asc&pageSize=2&page={$p}&q=".rawurlencode($tie)))['items'], 'number')];
        }
        $this->assertSame([5, true, 5], [$column['count'], $column['more'], count($seen)]);
        $this->assertEqualsCanonicalizing($tied, $seen, 'the board and the list read the queue in one order');
        // the filters of the list narrow the board too; somebody who only raises requests reads it as well
        $mine = $this->expectOk($this->getAs('proc', '/api/product-requests/board?requester=me&q='.rawurlencode($key)));
        $this->assertSame([0, 0, 0, 0], [...array_column($mine['columns'], 'count'), $mine['waiting']]);
        $this->expectRejected($this->getAs('gm', '/api/product-requests/board?per=500'), 'INVALID_INPUT', [400]);
    }

    public function test_the_summary_and_the_lookups(): void
    {
        $before = $this->expectOk($this->getAs('sales', '/api/product-requests/summary'));
        $this->assertSame(['byStatus', 'total', 'waiting', 'oldestWaitingAt', 'oldestWaitingDays', 'mine', 'approvedThisMonth', 'fromOdoo', 'follows', 'can'], array_keys($before));
        $this->assertSame(['draft', 'submitted', 'in_review', 'returned', 'approved', 'rejected', 'cancelled'], array_keys($before['byStatus']));
        $this->assertSame(['create' => true, 'review' => false], $before['can']);
        $this->assertSame(['create' => false, 'review' => true], $this->expectOk($this->getAs('gm', '/api/product-requests/summary'))['can']);

        $this->raise();
        $waiting = $this->waiting();
        ProductRequest::whereKey($waiting['id'])->update(['status_at' => now()->subDays(40)]);
        $approved = $this->waiting();
        $this->expectOk($this->postAs('gm', "/api/product-requests/{$approved['id']}/approve"));
        $after = $this->expectOk($this->getAs('sales', '/api/product-requests/summary'));
        $delta = fn (string $status) => $after['byStatus'][$status] - $before['byStatus'][$status];
        $this->assertSame([1, 1, 1, 3, 1, 1], [$delta('draft'), $delta('submitted'), $delta('approved'), $after['total'] - $before['total'], $after['waiting'] - $before['waiting'], $after['approvedThisMonth'] - $before['approvedThisMonth']]);
        $this->assertSame([1, 1, 2], [$after['mine']['draft'] - $before['mine']['draft'], $after['mine']['submitted'] - $before['mine']['submitted'], $after['mine']['open'] - $before['mine']['open']]);
        $this->assertGreaterThanOrEqual(40, $after['oldestWaitingDays']);
        // the figures are of what the reader may see: somebody else's draft is not in them, a reviewer counts every draft
        $proc = $this->userId('proc');
        $other = $this->expectOk($this->getAs('proc', '/api/product-requests/summary'));
        $this->assertSame(ProductRequest::where('status', 'draft')->where(fn ($w) => $w->where('requested_by_id', $proc)->orWhereNotNull('source_system'))->count(), $other['byStatus']['draft']);
        $this->assertSame(ProductRequest::where('status', 'draft')->count(), $this->expectOk($this->getAs('gm', '/api/product-requests/summary'))['byStatus']['draft']);
        $this->assertSame(ProductRequest::where('status', 'draft')->where('requested_by_id', $proc)->count(), $other['mine']['draft']);

        $lk = $this->expectOk($this->getAs('sales', '/api/product-requests/lookups'));
        $this->assertSame(['follows', 'can', 'selfApproval', 'statuses', 'sourceStates', 'storageClasses', 'requesters', 'limits'], array_keys($lk));
        $this->assertSame(['draft', 'submitted', 'in_review', 'returned', 'approved', 'rejected', 'cancelled'], array_column($lk['statuses'], 'key'));
        $this->assertSame(['key', 'ar', 'en'], array_keys($lk['statuses'][0]));
        $this->assertContains('supply_chain_approval', array_column($lk['sourceStates'], 'key'));
        $this->assertSame(['ambient', 'chilled', 'frozen'], $lk['storageClasses']);
        $me = collect($lk['requesters'])->firstWhere('key', $this->userId('sales'));
        $this->assertNotNull($me, 'whoever raised requests is offered as a filter');
        $this->assertGreaterThanOrEqual(3, $me['count']);
        $this->assertSame(['pictureBytes', 'pictures'], array_keys($lk['limits']));
    }
}
