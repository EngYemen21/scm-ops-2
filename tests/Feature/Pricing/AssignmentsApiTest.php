<?php

namespace Tests\Feature\Pricing;

use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Services\ExternalRefs;
use App\Models\AuditLog;
use App\Pricing\Models\PriceList;
use App\Pricing\Models\PriceListCustomer;
use App\Pricing\Models\ProductListPrice;
use App\Pricing\Support\Day;
use Tests\ApiTestCase;

/**
 * The price list of each customer and the list price of each product, over the API: giving a customer a list, moving
 * it, taking it off; what the screens list; who may do it; what the audit trail keeps — and what belongs to Odoo while
 * the section follows it.
 */
class AssignmentsApiTest extends ApiTestCase
{
    use PricingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->followOdoo(false);
        $this->noDefaultList();
    }

    private function newList(string $name, array $more = []): array
    {
        return $this->expectOk($this->postAs('sales', '/api/pricing/lists', ['name' => $name] + $more));
    }

    public function test_a_customer_is_given_a_list_moved_and_taken_off_it(): void
    {
        $tag = self::uid();
        $general = $this->newList("العامة {$tag}", ['isDefault' => true]);
        $a = $this->newList("مطاعم {$tag}");
        $b = $this->newList("فنادق {$tag}");
        $c1 = $this->customer("مطعم أ {$tag}");
        $c2 = $this->customer("مطعم ب {$tag}");
        $c3 = $this->customer("بقالة ج {$tag}");

        // without a list of its own a customer is priced with the default list
        $shown = $this->expectOk($this->getAs('finance', "/api/pricing/customers/{$c1->code}"));
        $this->assertSame([null, $general['id'], 'default', false], [$shown['assignment'], $shown['effective']['list']['id'], $shown['effective']['via'], $shown['locked']]);

        $given = $this->expectOk($this->postAs('sales', "/api/pricing/customers/{$c1->code}/assign", ['list' => $a['code'], 'note' => 'عقد سنوي']));
        $this->assertSame([$a['id'], 'manual', 'عقد سنوي', false, 'assigned', $a['id']],
            [$given['assignment']['listId'], $given['assignment']['basis'], $given['assignment']['note'], $given['assignment']['owned'], $given['effective']['via'], $given['effective']['list']['id']]);
        $audit = fn (string $action) => AuditLog::where('action', $action)->where('entity_type', 'Customer')->where('entity_id', $c1->id);
        $this->assertSame([1, $c1->code, 'sales'], [$audit('PRICING.ASSIGN')->count(), $audit('PRICING.ASSIGN')->value('entity_number'), $audit('PRICING.ASSIGN')->value('username')]);
        $this->expectOk($this->postAs('sales', "/api/pricing/customers/{$c1->id}/assign", ['list' => $a['id']]));
        $this->assertSame(1, $audit('PRICING.ASSIGN')->count(), 'the same list again changes nothing');

        // moved: the trail keeps where it came from
        $moved = $this->expectOk($this->postAs('sales', "/api/pricing/customers/{$c1->code}/assign", ['list' => $b['id']]));
        $this->assertSame([$b['id'], null], [$moved['assignment']['listId'], $moved['assignment']['note']]);
        $this->assertStringContainsString($a['code'], (string) $audit('PRICING.ASSIGN')->orderByDesc('at')->orderByDesc('id')->value('old_value'));
        $this->assertSame(1, PriceListCustomer::where('customer_id', $c1->id)->count(), 'one list per customer');

        // refused
        $this->expectRejected($this->postAs('sales', "/api/pricing/customers/{$c1->code}/assign", []), null, [400]);
        $this->expectRejected($this->postAs('sales', "/api/pricing/customers/{$c1->code}/assign", ['list' => 'NO-SUCH-LIST']), 'PRICE_LIST_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('sales', '/api/pricing/customers/NO-SUCH-CUSTOMER/assign', ['list' => $a['id']]), 'CUSTOMER_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('proc', "/api/pricing/customers/{$c2->code}/assign", ['list' => $a['id']]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('wm', '/api/pricing/customers'), 'FORBIDDEN', [403]);
        $archived = $this->newList("مؤرشفة {$tag}");
        $this->expectOk($this->postAs('sales', "/api/pricing/lists/{$archived['id']}/archive"));
        $this->expectRejected($this->postAs('sales', "/api/pricing/customers/{$c2->code}/assign", ['list' => $archived['id']]), 'PRICE_LIST_ARCHIVED', [422]);
        $this->assertSame(0, PriceListCustomer::where('customer_id', $c2->id)->count());

        // several customers onto one list: each is its own decision
        $many = $this->expectOk($this->postAs('sales', "/api/pricing/lists/{$a['code']}/customers", ['customers' => [$c1->code, $c2->id, 'NO-SUCH-CUSTOMER'], 'note' => 'حملة']));
        $this->assertSame([2, 1, 'CUSTOMER_NOT_FOUND', $a['id']], [$many['assigned'], count($many['refused']), $many['refused'][0]['code'], $many['list']['id']]);
        $this->expectRejected($this->postAs('sales', "/api/pricing/lists/{$archived['id']}/customers", ['customers' => [$c3->code]]), 'PRICE_LIST_ARCHIVED', [422]);
        $this->expectRejected($this->postAs('proc', "/api/pricing/lists/{$a['id']}/customers", ['customers' => [$c3->code]]), 'FORBIDDEN', [403]);

        // who is on which list: search, filters, sort, paging
        $all = $this->expectOk($this->getAs('finance', "/api/pricing/customers?q={$tag}&sort=nameAr&order=asc"));
        $this->assertSame([3, $general['id'], false], [$all['total'], $all['defaultList']['id'], $all['follows']]);
        $this->assertSame([$c3->code, $c1->code, $c2->code], array_column($all['items'], 'code'));
        $this->assertSame(['default', 'assigned', 'assigned'], array_map(fn ($x) => $x['effective']['via'], $all['items']));
        $this->assertSame(2, $this->expectOk($this->getAs('sales', "/api/pricing/customers?q={$tag}&assigned=yes"))['total']);
        $this->assertSame([$c3->code], array_column($this->expectOk($this->getAs('sales', "/api/pricing/customers?q={$tag}&assigned=no"))['items'], 'code'));
        $this->assertSame(2, $this->expectOk($this->getAs('sales', "/api/pricing/customers?list={$a['code']}"))['total']);
        $this->assertSame(0, $this->expectOk($this->getAs('sales', "/api/pricing/customers?list={$b['code']}"))['total']);
        $this->assertSame(2, $this->expectOk($this->getAs('sales', "/api/pricing/customers?q={$tag}&basis=manual"))['total']);
        $this->assertSame(0, $this->expectOk($this->getAs('sales', "/api/pricing/customers?q={$tag}&basis=order"))['total']);
        $page2 = $this->expectOk($this->getAs('sales', "/api/pricing/customers?q={$tag}&pageSize=2&page=2"));
        $this->assertSame([3, 2, 1], [$page2['total'], $page2['pages'], count($page2['items'])]);
        $of = $this->expectOk($this->getAs('sales', "/api/pricing/lists/{$a['id']}/customers"));
        $this->assertEqualsCanonicalizing([$c1->code, $c2->code], array_column($of['items'], 'code'));
        $this->assertSame(2, $this->expectOk($this->getAs('sales', "/api/pricing/lists/{$a['id']}"))['customersCount']);
        $c3->update(['active' => false]);
        $this->assertSame(2, $this->expectOk($this->getAs('sales', "/api/pricing/customers?q={$tag}"))['total'], 'stopped customers are left out unless asked for');
        $this->assertSame(3, $this->expectOk($this->getAs('sales', "/api/pricing/customers?q={$tag}&active=all"))['total']);

        // a list outside its validity does not price: the customer falls back to the default list until it is valid again
        PriceList::whereKey($a['id'])->update(['valid_to' => Day::today()->subDay()->toDateString()]);
        $this->assertSame(['default', $a['id']], [$this->expectOk($this->getAs('sales', "/api/pricing/customers/{$c1->code}"))['effective']['via'], $this->expectOk($this->getAs('sales', "/api/pricing/customers/{$c1->code}"))['assignment']['listId']]);

        // taken off its list: back to the default one
        $off = $this->expectOk($this->postAs('sales', "/api/pricing/customers/{$c1->code}/unassign", ['note' => 'انتهى العقد']));
        $this->assertSame([null, 'default'], [$off['assignment'], $off['effective']['via']]);
        $this->assertSame(1, $audit('PRICING.UNASSIGN')->count());
        $this->expectOk($this->postAs('sales', "/api/pricing/customers/{$c1->code}/unassign"));
        $this->assertSame(1, $audit('PRICING.UNASSIGN')->count(), 'nothing to take off: nothing happens');
        $this->expectRejected($this->postAs('proc', "/api/pricing/customers/{$c2->code}/unassign"), 'FORBIDDEN', [403]);
    }

    public function test_odoo_decides_the_list_of_its_customers_while_the_section_follows_it(): void
    {
        $tag = self::uid();
        $n = (int) $tag * 10;
        $refs = $this->app->make(ExternalRefs::class);
        $odooList = PriceList::create(['code' => "PRL-O{$tag}", 'name' => "قائمة أودو {$tag}", 'currency' => 'SAR', 'active' => true, 'source_system' => 'odoo', 'external_ref' => "7{$tag}"]);
        $ours = $this->newList("قائمتنا {$tag}");
        $link = function (string $name, int $partnerId) use ($refs) {
            $c = $this->customer($name, ['source_system' => 'odoo']);
            $refs->link('odoo', 'customer', (string) $partnerId, $c->id, $c->code);
            $this->rec('res.partner', $partnerId, ['name' => $name, 'customer_rank' => 1], true);

            return $c;
        };
        $derived = $link("من الطلبات {$tag}", $n + 1);       // its list was read from its latest order
        $named = $link("مسمّى في أودو {$tag}", $n + 2);       // its list was named by Odoo's own field
        $plain = $link("على الافتراضية {$tag}", $n + 3);     // an Odoo customer without a list of its own
        $local = $this->customer("عميل النظام {$tag}");      // not an Odoo customer at all
        PriceListCustomer::create(['customer_id' => $derived->id, 'price_list_id' => $odooList->id, 'basis' => 'order', 'basis_ref' => 'S00012', 'basis_odoo_id' => 12, 'source_system' => 'odoo', 'external_ref' => (string) ($n + 1)]);
        PriceListCustomer::create(['customer_id' => $named->id, 'price_list_id' => $odooList->id, 'basis' => 'partner', 'source_system' => 'odoo', 'external_ref' => (string) ($n + 2)]);
        // the copy as it is today: partners are copied, without Odoo's own "price list of the partner"
        $this->odooModel('res.partner', true);
        OdooModel::where('model', 'res.partner')->update(['fields' => json_encode(['name' => 'char', 'customer_rank' => 'integer'])]);
        $this->followOdoo(true);

        // read from the orders: evidence — shown as such, and a person may correct it
        $shown = $this->expectOk($this->getAs('sales', "/api/pricing/customers/{$derived->code}"));
        $this->assertSame(['order', 'S00012', false, false], [$shown['assignment']['basis'], $shown['assignment']['basisRef'], $shown['assignment']['owned'], $shown['locked']]);
        $fixed = $this->expectOk($this->postAs('sales', "/api/pricing/customers/{$derived->code}/assign", ['list' => $ours['id'], 'note' => 'العميل انتقل إلى قائمتنا']));
        $this->assertSame(['manual', $ours['id'], null, null], [$fixed['assignment']['basis'], $fixed['assignment']['listId'], $fixed['assignment']['basisRef'], $fixed['assignment']['sourceSystem']]);

        // named by Odoo's own field: Odoo's — neither moved nor taken off here
        $shown = $this->expectOk($this->getAs('sales', "/api/pricing/customers/{$named->code}"));
        $this->assertSame(['partner', true, true], [$shown['assignment']['basis'], $shown['assignment']['owned'], $shown['locked']]);
        $refused = $this->expectRejected($this->postAs('sales', "/api/pricing/customers/{$named->code}/assign", ['list' => $ours['id']]), 'PRICING_OWNED_BY_SOURCE', [422]);
        $this->assertSame('assignment', $refused['details']['entity']);
        $this->expectRejected($this->postAs('sales', "/api/pricing/customers/{$named->code}/unassign"), 'PRICING_OWNED_BY_SOURCE', [422]);
        $this->assertSame($odooList->id, PriceListCustomer::where('customer_id', $named->id)->value('price_list_id'));

        // while the copy does not carry the field, an Odoo customer without a list may be given one here
        $this->assertFalse($this->expectOk($this->getAs('sales', "/api/pricing/customers/{$plain->code}"))['locked']);
        $many = $this->expectOk($this->postAs('sales', "/api/pricing/lists/{$ours['id']}/customers", ['customers' => [$named->code, $plain->code]]));
        $this->assertSame([1, 'PRICING_OWNED_BY_SOURCE', $named->code], [$many['assigned'], $many['refused'][0]['code'], $many['refused'][0]['customer']]);
        $this->expectOk($this->postAs('sales', "/api/pricing/customers/{$plain->code}/unassign"));

        // the copy starts carrying Odoo's own field: the list of every customer OPS shares with Odoo is Odoo's — and so is having none
        OdooModel::where('model', 'res.partner')->update(['fields' => json_encode(['name' => 'char', 'customer_rank' => 'integer', 'property_product_pricelist' => 'many2one'])]);
        $this->assertTrue($this->expectOk($this->getAs('sales', "/api/pricing/customers/{$plain->code}"))['locked']);
        $this->expectRejected($this->postAs('sales', "/api/pricing/customers/{$plain->code}/assign", ['list' => $ours['id']]), 'PRICING_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('sales', "/api/pricing/customers/{$derived->code}/unassign"), 'PRICING_OWNED_BY_SOURCE', [422]);
        $this->assertSame([0, 1], [PriceListCustomer::where('customer_id', $plain->id)->count(), PriceListCustomer::where('customer_id', $derived->id)->count()]);
        // a customer of OPS is OPS's — also on a list that came from Odoo
        $given = $this->expectOk($this->postAs('sales', "/api/pricing/customers/{$local->code}/assign", ['list' => $odooList->code]));
        $this->assertSame([$odooList->id, 'manual', false], [$given['assignment']['listId'], $given['assignment']['basis'], $given['locked']]);
        // the list of customers says whose list is Odoo's, so the screen offers no change that would be refused
        $rows = collect($this->expectOk($this->getAs('sales', "/api/pricing/customers?q={$tag}"))['items'])->keyBy('code');
        $this->assertSame([true, true, true, false], [$rows[$named->code]['locked'], $rows[$plain->code]['locked'], $rows[$derived->code]['locked'], $rows[$local->code]['locked']]);

        // handed over: every customer's list is decided here
        $this->followOdoo(false);
        $this->assertFalse(collect($this->expectOk($this->getAs('sales', "/api/pricing/customers?q={$tag}"))['items'])->contains('locked', true));
        $this->assertSame($ours['id'], $this->expectOk($this->postAs('sales', "/api/pricing/customers/{$named->code}/assign", ['list' => $ours['id']]))['assignment']['listId']);
        $this->assertSame($ours['id'], $this->expectOk($this->postAs('sales', "/api/pricing/customers/{$plain->code}/assign", ['list' => $ours['id']]))['assignment']['listId']);
    }

    public function test_the_list_price_of_a_product(): void
    {
        $tag = self::uid();
        $p1 = $this->product("تمر سكري {$tag}", ['purchase_price' => 30]);
        $p2 = $this->product("قهوة عربية {$tag}");
        $audit = fn (string $action, $p) => AuditLog::where('action', $action)->where('entity_type', 'Product')->where('entity_id', $p->id);

        $set = $this->expectOk($this->postAs('sales', '/api/pricing/list-prices', ['product' => $p1->sku, 'price' => 45.5]));
        $this->assertSame(['45.50', 'SAR', false, $p1->sku, 'sales'], [$set['listPrice']['price'], $set['listPrice']['currency'], $set['listPrice']['owned'], $set['sku'], $set['listPrice']['updatedBy']]);
        $this->expectOk($this->postAs('sales', '/api/pricing/list-prices', ['product' => $p1->id, 'price' => 45.5]));
        $this->assertSame(1, $audit('PRICING.LISTPRICE.SET', $p1)->count(), 'the same price again is not a change');
        $this->assertSame('48.125', $this->expectOk($this->postAs('sales', '/api/pricing/list-prices', ['product' => $p1->sku, 'price' => 48.125]))['listPrice']['price']);
        $this->assertSame([2, 1], [$audit('PRICING.LISTPRICE.SET', $p1)->count(), ProductListPrice::where('product_id', $p1->id)->count()]);
        $this->assertStringContainsString('45.5', (string) $audit('PRICING.LISTPRICE.SET', $p1)->orderByDesc('at')->orderByDesc('id')->value('old_value'));

        $this->expectRejected($this->postAs('sales', '/api/pricing/list-prices', ['product' => $p1->sku, 'price' => 0]), null, [400]);
        $this->expectRejected($this->postAs('sales', '/api/pricing/list-prices', ['product' => $p1->sku]), null, [400]);
        $this->expectRejected($this->postAs('sales', '/api/pricing/list-prices', ['product' => 'NO-SUCH-SKU', 'price' => 5]), 'PRODUCT_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('proc', '/api/pricing/list-prices', ['product' => $p2->sku, 'price' => 5]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('wm', '/api/pricing/list-prices'), 'FORBIDDEN', [403]);

        // what a customer without a rule pays
        $customer = $this->customer("عميل {$tag}");
        $q = $this->expectOk($this->getAs('sales', "/api/pricing/price?customer={$customer->code}&product={$p1->sku}"));
        $this->assertSame([48.125, 'list_price'], [$q['price'], $q['source']]);

        // the products with and without a list price; what the company pays is shown only to those who may see cost
        $rows = $this->expectOk($this->getAs('sales', "/api/pricing/list-prices?q={$tag}&sort=price&order=desc"));
        $this->assertSame([2, false, [$p1->sku, $p2->sku]], [$rows['total'], $rows['costVisible'], array_column($rows['items'], 'sku')]);
        $this->assertSame(['48.125', null], [$rows['items'][0]['listPrice']['price'], $rows['items'][1]['listPrice']]);
        $this->assertArrayNotHasKey('cost', $rows['items'][0]);
        $withCost = $this->expectOk($this->getAs('finance', "/api/pricing/list-prices?q={$tag}&has=yes"));
        $this->assertSame([1, true, '30.00', 37.7], [$withCost['total'], $withCost['costVisible'], $withCost['items'][0]['cost'], $withCost['items'][0]['marginPct']]);
        $this->assertSame([$p2->sku], array_column($this->expectOk($this->getAs('finance', "/api/pricing/list-prices?q={$tag}&has=no"))['items'], 'sku'));
        $this->assertSame(1, $this->expectOk($this->getAs('sales', "/api/pricing/list-prices?q={$tag}&source=ops"))['total']);
        $this->assertSame(0, $this->expectOk($this->getAs('sales', "/api/pricing/list-prices?q={$tag}&source=odoo"))['total']);

        // a pasted sheet: all or nothing
        $out = $this->expectOk($this->postAs('sales', '/api/pricing/list-prices/bulk', ['items' => [['sku' => $p1->sku, 'price' => 50], ['sku' => strtolower($p2->sku), 'price' => 12.25]]]));
        $this->assertSame(['created' => 1, 'updated' => 1, 'unchanged' => 0], $out);
        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 2], $this->expectOk($this->postAs('sales', '/api/pricing/list-prices/bulk', ['items' => [['sku' => $p1->sku, 'price' => 50], ['sku' => $p2->sku, 'price' => 12.25]]])));
        $faulty = $this->expectRejected($this->postAs('sales', '/api/pricing/list-prices/bulk', ['items' => [['sku' => $p1->sku, 'price' => 99], ['sku' => 'NO-SUCH', 'price' => 1], ['sku' => $p1->sku, 'price' => 98]]]), 'PRICE_LINES_INVALID', [400]);
        $this->assertSame(['items.1.sku', 'items.2.sku'], array_column($faulty['details'], 'path'));
        $this->assertSame(50.0, (float) ProductListPrice::where('product_id', $p1->id)->value('price'));
        $this->expectRejected($this->postAs('proc', '/api/pricing/list-prices/bulk', ['items' => [['sku' => $p1->sku, 'price' => 9]]]), 'FORBIDDEN', [403]);

        // Odoo's price is Odoo's while the section follows it
        ProductListPrice::where('product_id', $p2->id)->update(['source_system' => 'odoo', 'external_ref' => '4411']);
        $this->followOdoo(true);
        $this->assertTrue($this->expectOk($this->getAs('sales', "/api/pricing/list-prices?q={$tag}&source=odoo"))['items'][0]['listPrice']['owned']);
        $this->assertSame('listPrice', $this->expectRejected($this->postAs('sales', '/api/pricing/list-prices', ['product' => $p2->sku, 'price' => 13]), 'PRICING_OWNED_BY_SOURCE', [422])['details']['entity']);
        $this->expectRejected($this->postAs('sales', "/api/pricing/list-prices/{$p2->sku}/clear"), 'PRICING_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('sales', '/api/pricing/list-prices/bulk', ['items' => [['sku' => $p2->sku, 'price' => 13]]]), 'PRICE_LINES_INVALID', [400]);
        $this->assertSame('51.00', $this->expectOk($this->postAs('sales', '/api/pricing/list-prices', ['product' => $p1->sku, 'price' => 51]))['listPrice']['price'], 'an OPS price stays editable');
        $this->followOdoo(false);
        $this->assertSame('13.00', $this->expectOk($this->postAs('sales', '/api/pricing/list-prices', ['product' => $p2->sku, 'price' => 13]))['listPrice']['price']);

        // cleared: the product has no list price any more
        $cleared = $this->expectOk($this->postAs('sales', "/api/pricing/list-prices/{$p2->sku}/clear"));
        $this->assertSame([null, 0, 1], [$cleared['listPrice'], ProductListPrice::where('product_id', $p2->id)->count(), $audit('PRICING.LISTPRICE.CLEAR', $p2)->count()]);
        $this->expectOk($this->postAs('sales', "/api/pricing/list-prices/{$p2->sku}/clear"));
        $this->assertSame(1, $audit('PRICING.LISTPRICE.CLEAR', $p2)->count());
        $this->expectRejected($this->postAs('proc', "/api/pricing/list-prices/{$p1->sku}/clear"), 'FORBIDDEN', [403]);
    }
}
