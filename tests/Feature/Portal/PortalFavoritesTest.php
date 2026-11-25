<?php

namespace Tests\Feature\Portal;

use App\Finance\Models\CustomerInvoiceLine;
use App\Models\AuditLog;
use App\Models\Quotation;
use App\Portal\Models\FavoriteItem;
use App\Portal\Models\FavoriteList;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * Favourite lists: a list and its items (read a page at a time, edited, reordered), what stays Odoo's on a list that
 * came from Odoo, and "order again" — a draft quotation of the sales module made from a list.
 */
class PortalFavoritesTest extends ApiTestCase
{
    use PortalFixtures;

    private function newList(array $more = [], string $as = 'sales'): array
    {
        return $this->expectOk($this->postAs($as, '/api/portal/favorites/lists', $more + ['name' => 'قائمة '.self::tag()]));
    }

    private function skus(string $listId, string $query = ''): array
    {
        $page = $this->expectOk($this->getAs('gm', "/api/portal/favorites/lists/{$listId}/items?{$query}"));

        return array_map(fn (array $i) => $i['product']['sku'], $page['items']);
    }

    public function test_a_list_is_made_edited_archived_and_audited(): void
    {
        $customer = $this->makeCustomer();
        [$p1, $p2] = [$this->makeProduct(), $this->makeProduct()];
        $branch = $this->expectOk($this->postAs('sales', '/api/portal/branches', ['customerId' => $customer->code, 'name' => 'فرع القائمة']));

        $this->expectRejected($this->postAs('gm', '/api/portal/favorites/lists', ['customerId' => $customer->code, 'name' => 'ممنوعة']), 'FORBIDDEN', [403]);
        $l = $this->newList(['customerId' => $customer->code, 'name' => ' طلبية الأسبوع ', 'branchId' => $branch['id'], 'items' => [['sku' => $p1->sku, 'qty' => 12], ['sku' => $p2->sku]]]);
        $this->assertSame(['طلبية الأسبوع', 2, true, false, null], [$l['name'], $l['itemsCount'], $l['active'], $l['owned'], $l['sourceSystem']]);
        $this->assertSame([$customer->code, $branch['id']], [$l['customer']['code'], $l['branch']['id']]);
        $this->assertSame(['edit' => true, 'assign' => true, 'items' => true, 'archive' => true, 'restore' => false, 'orderAgain' => true], $l['can']);
        $this->assertTrue(AuditLog::where('action', 'PORTAL.FAVLIST.CREATE')->where('entity_id', $l['id'])->exists());
        $items = $this->expectOk($this->getAs('gm', "/api/portal/favorites/lists/{$l['id']}/items"));
        $this->assertSame([[$p1->sku, '12', 1], [$p2->sku, '1', 2]], array_map(fn (array $i) => [$i['product']['sku'], $i['qty'], $i['position']], $items['items']));

        // the rules of a new list
        $this->expectRejected($this->postAs('sales', '/api/portal/favorites/lists', ['customerId' => $customer->code, 'name' => 'طلبية الأسبوع']), 'PORTAL_LIST_EXISTS', [422]);
        $this->expectRejected($this->postAs('sales', '/api/portal/favorites/lists', ['customerId' => $customer->code, 'name' => 'مكررة', 'items' => [['sku' => $p1->sku], ['sku' => $p1->sku]]]), 'PORTAL_FAVORITE_EXISTS', [400]);
        $this->expectRejected($this->postAs('sales', '/api/portal/favorites/lists', ['customerId' => $customer->code, 'name' => 'مجهولة', 'items' => [['sku' => 'NO-SUCH-SKU']]]), 'SKU_NOT_FOUND', [400]);
        $this->assertSame(1, FavoriteList::where('customer_id', $customer->id)->count(), 'a refused list leaves nothing behind');
        $stranger = $this->expectOk($this->postAs('sales', '/api/portal/branches', ['customerId' => $this->makeCustomer()->code, 'name' => 'فرع عميل آخر']));
        $this->expectRejected($this->patchAs('sales', "/api/portal/favorites/lists/{$l['id']}", ['branchId' => $stranger['id']]), 'PORTAL_BRANCH_CUSTOMER', [422]);

        $e = $this->expectOk($this->patchAs('sales', "/api/portal/favorites/lists/{$l['id']}", ['name' => 'طلبية الشهر', 'branchId' => null, 'notes' => 'تُراجع كل شهر']));
        $this->assertSame(['طلبية الشهر', null, 'تُراجع كل شهر'], [$e['name'], $e['branch'], $e['notes']]);

        $a = $this->expectOk($this->postAs('sales', "/api/portal/favorites/lists/{$l['id']}/archive", ['reason' => 'لم تعد تُستخدم']));
        $this->assertSame([false, 'لم تعد تُستخدم', false, false], [$a['active'], $a['archiveReason'], $a['can']['items'], $a['can']['orderAgain']]);
        $this->expectRejected($this->postAs('sales', "/api/portal/favorites/lists/{$l['id']}/items", ['sku' => $this->makeProduct()->sku]), 'PORTAL_LIST_ARCHIVED', [422]);
        $this->assertSame(2, FavoriteItem::where('list_id', $l['id'])->count(), 'an archived list keeps its items');
        $r = $this->expectOk($this->postAs('sales', "/api/portal/favorites/lists/{$l['id']}/restore"));
        $this->assertSame(['restored', 'archived', 'updated', 'created'], array_column($r['events'], 'kind'));
    }

    public function test_items_are_added_changed_reordered_and_removed(): void
    {
        $customer = $this->makeCustomer();
        $products = array_map(fn () => $this->makeProduct(), range(1, 5));
        $l = $this->newList(['customerId' => $customer->code]);
        foreach ($products as $i => $p) {
            $added = $this->expectOk($this->postAs('sales', "/api/portal/favorites/lists/{$l['id']}/items", ['sku' => $p->sku, 'qty' => $i + 1]));
            $this->assertSame([$i + 1, $p->sku], [$added['list']['itemsCount'], $added['item']['product']['sku']]);
        }
        $skus = array_map(fn ($p) => $p->sku, $products);
        $this->assertSame($skus, $this->skus($l['id']));
        $this->assertSame([10, 20, 30, 40, 50], FavoriteItem::where('list_id', $l['id'])->orderBy('sequence')->pluck('sequence')->all());

        // a product is on a list once; an unknown or stopped product does not go on a list
        $this->expectRejected($this->postAs('sales', "/api/portal/favorites/lists/{$l['id']}/items", ['sku' => $skus[0]]), 'PORTAL_FAVORITE_EXISTS', [422]);
        $this->expectRejected($this->postAs('sales', "/api/portal/favorites/lists/{$l['id']}/items", ['sku' => 'NO-SUCH-SKU']), 'SKU_NOT_FOUND', [400]);
        $this->expectRejected($this->postAs('sales', "/api/portal/favorites/lists/{$l['id']}/items", ['sku' => $this->makeProduct(['active' => false])->sku]), 'SKU_INACTIVE', [400]);
        $this->expectRejected($this->postAs('sales', "/api/portal/favorites/lists/{$l['id']}/items", ['sku' => $this->makeProduct()->sku, 'qty' => 0]), 'INVALID_INPUT', [400]);

        // the usual quantity
        $id = fn (string $sku) => FavoriteItem::where('list_id', $l['id'])->where('product_id', DB::table('products')->where('sku', $sku)->value('id'))->value('id');
        $changed = $this->expectOk($this->patchAs('sales', "/api/portal/favorites/lists/{$l['id']}/items/{$id($skus[1])}", ['qty' => 7.5]));
        $this->assertSame('7.5', $changed['item']['qty']);
        $this->assertTrue(AuditLog::where('action', 'PORTAL.FAVORITE.QTY')->where('entity_id', $l['id'])->where('old_value', '2')->where('new_value', '7.5')->exists());

        // reorder: the fifth to the top, the first to the end, a place past the end is the end
        $this->expectOk($this->postAs('sales', "/api/portal/favorites/lists/{$l['id']}/items/{$id($skus[4])}/move", ['position' => 1]));
        $this->assertSame([$skus[4], $skus[0], $skus[1], $skus[2], $skus[3]], $this->skus($l['id']));
        $this->expectOk($this->postAs('sales', "/api/portal/favorites/lists/{$l['id']}/items/{$id($skus[0])}/move", ['position' => 99]));
        $this->assertSame([$skus[4], $skus[1], $skus[2], $skus[3], $skus[0]], $this->skus($l['id']));
        $this->assertSame([10, 20, 30, 40, 50], FavoriteItem::where('list_id', $l['id'])->orderBy('sequence')->pluck('sequence')->all(), 'the list is renumbered in steps of ten');
        $this->expectRejected($this->postAs('sales', "/api/portal/favorites/lists/{$l['id']}/items/01NOSUCHITEM00000000000000/move", ['position' => 1]), 'PORTAL_FAVORITE_NOT_FOUND', [404]);

        // a page of the list, and a search inside it
        $page2 = $this->expectOk($this->getAs('gm', "/api/portal/favorites/lists/{$l['id']}/items?pageSize=2&page=2"));
        $this->assertSame([[$skus[2], 3], [$skus[3], 4]], array_map(fn (array $i) => [$i['product']['sku'], $i['position']], $page2['items']));
        $this->assertSame([5, 3], [$page2['total'], $page2['pages']]);
        $this->assertSame([$skus[3]], $this->skus($l['id'], 'q='.urlencode($skus[3])));

        // removing is a removal: the row goes, the list's timeline and the audit trail keep who took it off
        $after = $this->expectOk($this->deleteAs('sales', "/api/portal/favorites/lists/{$l['id']}/items/{$id($skus[1])}"));
        $this->assertSame([4, 4], [$after['itemsCount'], FavoriteItem::where('list_id', $l['id'])->count()]);
        $this->assertSame('item_removed', $after['events'][0]['kind']);
        $this->assertSame($skus[1], $after['events'][0]['data']['sku']);
        $this->assertTrue(AuditLog::where('action', 'PORTAL.FAVORITE.REMOVE')->where('entity_id', $l['id'])->where('username', 'sales')->exists());
        $this->expectRejected($this->deleteAs('gm', "/api/portal/favorites/lists/{$l['id']}/items/{$id($skus[2])}"), 'FORBIDDEN', [403]);

        // the lists a product is on
        $other = $this->newList(['customerId' => $customer->code, 'items' => [['sku' => $skus[2]]]]);
        $with = $this->expectOk($this->getAs('gm', "/api/portal/favorites/lists?product={$skus[2]}&sort=createdAt&order=asc&customer={$customer->code}"));
        $this->assertSame([$l['id'], $other['id']], array_column($with['items'], 'id'));
        $this->assertSame([$l['id']], array_column($this->expectOk($this->getAs('gm', "/api/portal/favorites/lists?product={$skus[4]}"))['items'], 'id'));
    }

    public function test_what_odoo_owns_on_a_list_is_refused_and_what_ops_adds_is_saved(): void
    {
        $customer = $this->makeCustomer();
        $p = $this->makeProduct();
        $list = FavoriteList::create(['name' => 'قائمة أودو '.self::tag(), 'customer_id' => $customer->id, 'customer_ref' => '810', 'customer_name' => 'عميل أودو', 'sequence' => 10, 'items_count' => 1,
            'active' => true, 'source_system' => 'odoo', 'external_ref' => (string) random_int(100000, 999999)]);
        $item = FavoriteItem::create(['list_id' => $list->id, 'product_id' => $p->id, 'qty' => 3, 'sequence' => 10, 'source_system' => 'odoo', 'external_ref' => (string) random_int(100000, 999999)]);
        $branch = $this->expectOk($this->postAs('sales', '/api/portal/branches', ['customerId' => $customer->code, 'name' => 'فرع أودو']));

        $this->followOdoo(true);
        $shown = $this->expectOk($this->getAs('sales', "/api/portal/favorites/lists/{$list->id}"));
        $this->assertSame([true, false, true, false, true], [$shown['owned'], $shown['can']['edit'], $shown['can']['assign'], $shown['can']['items'], $shown['can']['orderAgain']]);
        foreach ([
            fn () => $this->patchAs('sales', "/api/portal/favorites/lists/{$list->id}", ['name' => 'اسم آخر']),
            fn () => $this->postAs('sales', "/api/portal/favorites/lists/{$list->id}/items", ['sku' => $this->makeProduct()->sku]),
            fn () => $this->patchAs('sales', "/api/portal/favorites/lists/{$list->id}/items/{$item->id}", ['qty' => 9]),
            fn () => $this->deleteAs('sales', "/api/portal/favorites/lists/{$list->id}/items/{$item->id}"),
            fn () => $this->postAs('sales', "/api/portal/favorites/lists/{$list->id}/items/{$item->id}/move", ['position' => 1]),
            fn () => $this->postAs('sales', "/api/portal/favorites/lists/{$list->id}/archive"),
        ] as $attempt) {
            $this->expectRejected($attempt(), 'PORTAL_OWNED_BY_SOURCE', [422]);
        }
        $this->assertSame(['3.000', 1], [FavoriteItem::find($item->id)->qty, FavoriteItem::where('list_id', $list->id)->count()]);
        // the branch the list is kept for and the notes are OPS's own
        $saved = $this->expectOk($this->patchAs('sales', "/api/portal/favorites/lists/{$list->id}", ['branchId' => $branch['id'], 'notes' => 'قائمة فرع أودو']));
        $this->assertSame([$branch['id'], 'قائمة فرع أودو'], [$saved['branch']['id'], $saved['notes']]);

        $this->followOdoo(false);
        $this->expectOk($this->patchAs('sales', "/api/portal/favorites/lists/{$list->id}/items/{$item->id}", ['qty' => 9]));
        $this->assertSame('9.000', FavoriteItem::find($item->id)->qty);
    }

    public function test_order_again_makes_a_draft_quotation_through_the_sales_module(): void
    {
        $customer = $this->makeCustomer();
        [$a, $b, $stopped] = [$this->makeProduct(), $this->makeProduct(), $this->makeProduct()];
        $l = $this->newList(['customerId' => $customer->code, 'items' => [['sku' => $a->sku, 'qty' => 2.5], ['sku' => $b->sku, 'qty' => 4], ['sku' => $stopped->sku, 'qty' => 1]]]);
        $stopped->update(['active' => false]);
        // a product the source system named and OPS does not have yet
        FavoriteItem::create(['list_id' => $l['id'], 'product_ref' => '4711', 'product_name' => 'صنف من أودو غير مربوط', 'qty' => 6, 'sequence' => 90]);
        // what the customer paid for A the last time, and what it was quoted for B
        $invoice = $this->receivable($customer, 500);
        CustomerInvoiceLine::create(['invoice_id' => $invoice->id, 'line_no' => 1, 'product_id' => $a->id, 'qty' => 10, 'price' => 42.5, 'disc_pct' => 0, 'line_total' => 425]);
        $earlier = $this->expectOk($this->postAs('sales', '/api/sales/quotations', ['customerCode' => $customer->code, 'validUntil' => now()->addDays(10)->toDateString(), 'lines' => [['sku' => $b->sku, 'qty' => 1, 'price' => 18]]]));

        $preview = $this->expectOk($this->getAs('gm', "/api/portal/favorites/lists/{$l['id']}/order-again"));
        $this->assertSame([true, null, now()->addDays(14)->toDateString(), false], [$preview['allowed'], $preview['code'], $preview['validUntil'], $preview['more']]);
        $this->assertSame([
            [$a->sku, 3, '2.5', 42.5, 'invoice', $invoice->number],          // 2.5 units are quoted as 3: a quotation counts whole units
            [$b->sku, 4, '4', 18.0, 'quotation', $earlier['number']],
        ], array_map(fn (array $x) => [$x['sku'], $x['qty'], $x['usualQty'], (float) $x['price'], $x['priceSource'], $x['priceRef']], $preview['lines']));
        $this->assertEqualsCanonicalizing(['inactive', 'unlinked'], array_column($preview['skipped'], 'reason'), 'what cannot be quoted is named, not dropped silently');

        $body = ['validUntil' => $preview['validUntil'], 'lines' => [['sku' => $a->sku, 'qty' => 3, 'price' => 42.5], ['sku' => $b->sku, 'qty' => 4, 'price' => 18]]];
        // a quotation is the sales module's: it needs its permission as well
        $before = Quotation::count();
        $this->expectRejected($this->postAs($this->portalOnlyUser(), "/api/portal/favorites/lists/{$l['id']}/order-again", $body), 'FORBIDDEN', [403]);
        $this->assertSame($before, Quotation::count());
        $this->assertTrue(AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->where('username', 'portalonly')->where('new_value', 'denied: sales.manage')->exists());
        // only the products of the list, each once
        $this->expectRejected($this->postAs('sales', "/api/portal/favorites/lists/{$l['id']}/order-again", ['lines' => [['sku' => $this->makeProduct()->sku, 'qty' => 1, 'price' => 5]]] + $body), 'PORTAL_NOT_ON_LIST', [400]);
        $this->expectRejected($this->postAs('sales', "/api/portal/favorites/lists/{$l['id']}/order-again", ['lines' => [$body['lines'][0], $body['lines'][0]]] + $body), 'PORTAL_LINE_TWICE', [400]);
        $this->expectRejected($this->postAs('sales', "/api/portal/favorites/lists/{$l['id']}/order-again", ['lines' => [['sku' => $a->sku, 'qty' => 3, 'price' => 0]]] + $body), 'INVALID_INPUT', [400]);
        $this->assertSame($before, Quotation::count());

        $done = $this->expectOk($this->postAs('sales', "/api/portal/favorites/lists/{$l['id']}/order-again", $body));
        $this->assertStringStartsWith('QT-', $done['quotation']['number']);
        $this->assertSame(['draft', 2], [$done['quotation']['status'], $done['quotation']['lines']]);
        $this->assertEqualsWithDelta((3 * 42.5 + 4 * 18) * 1.15, $done['quotation']['totals']['total'], 0.01);
        $qt = $this->expectOk($this->getAs('sales', "/api/sales/quotations/{$done['quotation']['number']}"));
        $this->assertSame([$customer->code, 'sales'], [$qt['customer']['code'], $qt['createdBy']]);
        $this->assertStringContainsString($l['name'], $qt['notes']);
        // the list remembers the quotation and is otherwise untouched
        $this->assertSame(['quotation', $done['quotation']['number']], [$done['list']['events'][0]['kind'], $done['list']['events'][0]['data']['number']]);
        $this->assertSame(4, FavoriteItem::where('list_id', $l['id'])->count());
        $this->assertTrue(AuditLog::where('action', 'PORTAL.FAVLIST.ORDER_AGAIN')->where('entity_id', $l['id'])->exists());

        // a list whose owner is not an OPS customer yet cannot be quoted — and says why
        $foreign = FavoriteList::create(['name' => 'قائمة عميل غير مربوط', 'customer_ref' => '9001', 'customer_name' => 'عميل في أودو فقط', 'sequence' => 10, 'items_count' => 1, 'active' => true,
            'source_system' => 'odoo', 'external_ref' => (string) random_int(100000, 999999)]);
        FavoriteItem::create(['list_id' => $foreign->id, 'product_id' => $a->id, 'qty' => 1, 'sequence' => 10]);
        $no = $this->expectOk($this->getAs('gm', "/api/portal/favorites/lists/{$foreign->id}/order-again"));
        $this->assertSame([false, 'PORTAL_NO_CUSTOMER', false, '9001'], [$no['allowed'], $no['code'], $no['customer']['linked'], $no['customer']['ref']]);
        $this->expectRejected($this->postAs('sales', "/api/portal/favorites/lists/{$foreign->id}/order-again", ['lines' => [['sku' => $a->sku, 'qty' => 1, 'price' => 5]]] + $body), 'PORTAL_NO_CUSTOMER', [422]);
        $empty = $this->newList(['customerId' => $customer->code]);
        $this->assertSame('PORTAL_LIST_EMPTY', $this->expectOk($this->getAs('gm', "/api/portal/favorites/lists/{$empty['id']}/order-again"))['code']);
    }

    public function test_the_list_of_lists_searches_filters_and_pages(): void
    {
        $tag = self::tag();
        $customer = $this->makeCustomer(['name_ar' => "مقهى الندى {$tag}"]);
        $branch = $this->expectOk($this->postAs('sales', '/api/portal/branches', ['customerId' => $customer->code, 'name' => 'فرع الندى']));
        $one = $this->newList(['customerId' => $customer->code, 'name' => "مشروبات {$tag}", 'branchId' => $branch['id'], 'items' => [['sku' => $this->makeProduct()->sku], ['sku' => $this->makeProduct()->sku]]]);
        $two = $this->newList(['customerId' => $customer->code, 'name' => "حلويات {$tag}"]);
        // a list of a customer OPS does not have yet: kept by the id and the name the source gave
        $foreign = FavoriteList::create(['name' => "قائمة أودو {$tag}", 'customer_ref' => '5150', 'customer_name' => "مطعم غير مربوط {$tag}", 'sequence' => 10, 'items_count' => 0, 'active' => true,
            'source_system' => 'odoo', 'external_ref' => (string) random_int(100000, 999999)]);
        $list = fn (string $query) => $this->expectOk($this->getAs('gm', '/api/portal/favorites/lists?'.$query));

        $mine = $list("customer={$customer->code}&sort=itemsCount&order=desc");
        $this->assertSame([[$one['id'], 2], [$two['id'], 0]], array_map(fn (array $x) => [$x['id'], $x['itemsCount']], $mine['items']));
        $this->assertSame([$one['id']], array_column($list("customer={$customer->code}&branch={$branch['id']}")['items'], 'id'));
        $this->assertSame([$two['id']], array_column($list("customer={$customer->code}&branch=none")['items'], 'id'));
        $this->assertSame([$two['id']], array_column($list('q='.urlencode("حلويات {$tag}"))['items'], 'id'));
        $this->assertSame(2, $list('q='.urlencode("الندى {$tag}"))['total'], 'a list is found by its customer\'s name');
        // the unlinked one: found by the name the source gave, filtered by `linked` and by the source's own id
        $found = $list('q='.urlencode("غير مربوط {$tag}"));
        $this->assertSame([[$foreign->id, false, '5150']], array_map(fn (array $x) => [$x['id'], $x['customer']['linked'], $x['customer']['ref']], $found['items']));
        $this->assertSame([$foreign->id], array_column($list('linked=no&q='.urlencode($tag))['items'], 'id'));
        $this->assertSame(2, $list('linked=yes&q='.urlencode($tag))['total']);
        $this->assertSame([$foreign->id], array_column($list('customer=ref:5150&q='.urlencode($tag))['items'], 'id'));
        $this->assertSame([$foreign->id], array_column($list('origin=odoo&q='.urlencode($tag))['items'], 'id'));
        $this->expectRejected($this->getAs('gm', '/api/portal/favorites/lists/01NOSUCHLIST00000000000000'), 'PORTAL_LIST_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('wm', '/api/portal/favorites/lists'), 'FORBIDDEN', [403]);
    }
}
