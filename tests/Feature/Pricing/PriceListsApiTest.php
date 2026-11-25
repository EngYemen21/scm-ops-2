<?php

namespace Tests\Feature\Pricing;

use App\Models\AuditLog;
use App\Models\StatusHistory;
use App\Pricing\Models\PriceList;
use App\Pricing\Models\PriceListCustomer;
use App\Pricing\Models\PriceRule;
use Tests\ApiTestCase;

/**
 * Customer price lists and their rules over the API: what a list and a rule accept and refuse, who may change them,
 * what is audited, what is never deleted — and that a list which came from Odoo is read-only while the section
 * follows Odoo.
 */
class PriceListsApiTest extends ApiTestCase
{
    use PricingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->followOdoo(false);
    }

    private function newList(string $name, array $more = []): array
    {
        return $this->expectOk($this->postAs('sales', '/api/pricing/lists', ['name' => $name] + $more));
    }

    public function test_a_list_is_created_changed_and_listed(): void
    {
        $tag = self::uid();
        $lists = PriceList::count();
        $l = $this->postAs('sales', '/api/pricing/lists', ['name' => "مطاعم الرياض {$tag}", 'validFrom' => '2026-01-01', 'validTo' => '2026-12-31', 'note' => 'عقد 2026']);
        $l->assertStatus(201);
        $l = $l->json();
        $this->assertStringStartsWith('PRL-', $l['code']);
        $this->assertSame(['SAR', true, false, true, 0, 0, false, null, 'sales'], [$l['currency'], $l['active'], $l['isDefault'], $l['usable'], $l['rulesCount'], $l['customersCount'], $l['owned'], $l['sourceSystem'], $l['createdBy']]);
        $this->assertSame(['2026-01-01', '2026-12-31', 'valid', 'عقد 2026'], [$l['validFrom'], $l['validTo'], $l['period'], $l['note']]);
        $this->assertSame(1, AuditLog::where('action', 'PRICING.LIST.CREATE')->where('entity_id', $l['id'])->where('username', 'sales')->count());

        // what a list refuses to be
        $this->expectRejected($this->postAs('sales', '/api/pricing/lists', ['note' => 'بلا اسم']), null, [400]);
        $this->expectRejected($this->postAs('sales', '/api/pricing/lists', ['name' => 'x', 'validFrom' => '2026-05-01', 'validTo' => '2026-04-01']), 'PRICE_PERIOD', [400]);
        $this->expectRejected($this->postAs('sales', '/api/pricing/lists', ['name' => 'x', 'currency' => 'RIYAL']), null, [400]);
        // … and who may not make one: procurement holds the page, not the customer lists; the warehouse holds neither
        $this->expectRejected($this->postAs('proc', '/api/pricing/lists', ['name' => "قائمة مشتريات {$tag}"]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('wm', '/api/pricing/lists'), 'FORBIDDEN', [403]);
        $this->assertSame($lists + 1, PriceList::count(), 'a refused request creates nothing');

        // changed: only what really differs is written and audited
        $same = $this->expectOk($this->patchAs('sales', "/api/pricing/lists/{$l['code']}", ['name' => "مطاعم الرياض {$tag}", 'note' => 'عقد 2026']));
        $this->assertSame(0, AuditLog::where('action', 'PRICING.LIST.UPDATE')->where('entity_id', $l['id'])->count());
        $changed = $this->expectOk($this->patchAs('sales', "/api/pricing/lists/{$l['id']}", ['name' => "مطاعم الرياض والخرج {$tag}", 'currency' => 'usd', 'validTo' => null]));
        $this->assertSame(["مطاعم الرياض والخرج {$tag}", 'USD', null, '2026-01-01'], [$changed['name'], $changed['currency'], $changed['validTo'], $changed['validFrom']]);
        $this->assertSame([$same['id'], 'sales'], [$changed['id'], $changed['updatedBy']]);
        $this->assertSame(1, AuditLog::where('action', 'PRICING.LIST.UPDATE')->where('entity_id', $l['id'])->count());
        $this->expectRejected($this->patchAs('sales', "/api/pricing/lists/{$l['id']}", ['name' => '  ']), null, [400]);
        $this->expectRejected($this->patchAs('sales', '/api/pricing/lists/NO-SUCH-LIST', ['name' => 'x']), 'PRICE_LIST_NOT_FOUND', [404]);
        $this->expectRejected($this->patchAs('proc', "/api/pricing/lists/{$l['id']}", ['name' => 'x']), 'FORBIDDEN', [403]);

        // listed: search, state, source, sort, paging — readable by every holder of the page
        $second = $this->newList("كافيهات جدة {$tag}");
        $found = $this->expectOk($this->getAs('finance', '/api/pricing/lists?q='.urlencode("الخرج {$tag}")));
        $this->assertSame([1, $l['id']], [$found['total'], $found['items'][0]['id']]);
        $byCode = $this->expectOk($this->getAs('proc', "/api/pricing/lists?q={$second['code']}"));
        $this->assertSame([$second['id']], array_column($byCode['items'], 'id'));
        $sorted = $this->expectOk($this->getAs('sales', "/api/pricing/lists?q={$tag}&sort=name&order=desc&pageSize=1"));
        $this->assertSame([2, 2, 1, "مطاعم الرياض والخرج {$tag}"], [$sorted['total'], $sorted['pages'], count($sorted['items']), $sorted['items'][0]['name']]);
        $this->assertSame("كافيهات جدة {$tag}", $this->expectOk($this->getAs('sales', "/api/pricing/lists?q={$tag}&sort=name&order=desc&pageSize=1&page=2"))['items'][0]['name']);
        $this->assertSame(0, $this->expectOk($this->getAs('sales', "/api/pricing/lists?q={$tag}&state=archived"))['total']);
        $this->assertSame(0, $this->expectOk($this->getAs('sales', "/api/pricing/lists?q={$tag}&source=odoo"))['total']);
        $this->assertSame(2, $this->expectOk($this->getAs('sales', "/api/pricing/lists?q={$tag}&source=ops&state=all"))['total']);
        $this->assertSame(1, $this->expectOk($this->getAs('sales', "/api/pricing/lists?q={$tag}&currency=usd"))['total']);
        $this->assertSame($second['id'], $this->expectOk($this->getAs('finance', "/api/pricing/lists/{$second['code']}"))['id']);
    }

    public function test_rules_are_added_checked_and_never_doubled(): void
    {
        $tag = self::uid();
        $list = $this->newList("قواعد {$tag}");
        $milk = $this->product("حليب {$tag}");
        $this->listPriceOf($milk, 20);
        $dairy = $this->category("ألبان {$tag}");
        $rules = "/api/pricing/lists/{$list['id']}/rules";

        $r = $this->postAs('sales', $rules, ['appliesTo' => 'product', 'product' => $milk->sku, 'compute' => 'fixed', 'fixedPrice' => 12.5, 'note' => 'سعر العقد']);
        $r->assertStatus(201);
        $r = $r->json();
        $this->assertSame(['product', $milk->sku, 0, 'fixed', '12.50', 'Fixed price 12.50', true, false, false, 'سعر العقد'],
            [$r['appliesTo'], $r['product']['sku'], $r['minQty'], $r['compute'], $r['fixedPrice'], $r['text']['en'], $r['active'], $r['pending'], $r['owned'], $r['note']]);
        $this->assertSame(1, AuditLog::where('action', 'PRICING.RULE.CREATE')->where('entity_id', $r['id'])->count());

        // what a rule refuses to be
        $bad = fn (array $body, ?string $code, array $status) => $this->expectRejected($this->postAs('sales', $rules, $body), $code, $status);
        $this->assertSame('product', $bad(['appliesTo' => 'product', 'compute' => 'fixed', 'fixedPrice' => 5], 'PRICE_RULE_INVALID', [400])['details'][0]['path']);
        $bad(['appliesTo' => 'product', 'product' => 'NO-SUCH-SKU', 'compute' => 'fixed', 'fixedPrice' => 5], 'PRODUCT_NOT_FOUND', [404]);
        $bad(['appliesTo' => 'category', 'compute' => 'fixed', 'fixedPrice' => 5], 'PRICE_RULE_INVALID', [400]);
        $bad(['appliesTo' => 'all', 'compute' => 'fixed', 'fixedPrice' => 0], 'PRICE_RULE_INVALID', [400]);
        $bad(['appliesTo' => 'all', 'compute' => 'percent', 'percent' => 0], 'PRICE_RULE_INVALID', [400]);
        $bad(['appliesTo' => 'all', 'compute' => 'formula'], 'PRICE_RULE_INVALID', [400]);
        $bad(['appliesTo' => 'all', 'compute' => 'formula', 'discountPct' => 5, 'minMargin' => 3, 'maxMargin' => 1], 'PRICE_RULE_INVALID', [400]);
        $bad(['appliesTo' => 'all', 'compute' => 'fixed', 'fixedPrice' => 5, 'validFrom' => '2026-06-01', 'validTo' => '2026-05-01'], 'PRICE_RULE_INVALID', [400]);
        $bad(['appliesTo' => 'shelf', 'compute' => 'fixed', 'fixedPrice' => 5], null, [400]);
        $bad(['appliesTo' => 'all', 'compute' => 'magic'], null, [400]);
        $this->expectRejected($this->postAs('proc', $rules, ['appliesTo' => 'all', 'compute' => 'fixed', 'fixedPrice' => 5]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('sales', '/api/pricing/lists/NO-SUCH/rules', ['appliesTo' => 'all', 'compute' => 'fixed', 'fixedPrice' => 5]), 'PRICE_LIST_NOT_FOUND', [404]);

        // a second rule for the same product, quantity and period would never be used: refused, and the first is named
        $twin = $bad(['appliesTo' => 'product', 'product' => $milk->id, 'compute' => 'fixed', 'fixedPrice' => 11], 'PRICE_RULE_DUPLICATE', [409]);
        $this->assertSame($r['id'], $twin['details']['ruleId']);
        // a quantity tier is another rule; so are two periods that do not meet
        $tier = $this->expectOk($this->postAs('sales', $rules, ['appliesTo' => 'product', 'product' => $milk->sku, 'compute' => 'fixed', 'fixedPrice' => 11, 'minQty' => 10]));
        $this->assertSame(10, $tier['minQty']);
        $spring = $this->expectOk($this->postAs('sales', $rules, ['appliesTo' => 'product', 'product' => $milk->sku, 'compute' => 'fixed', 'fixedPrice' => 10, 'minQty' => 50, 'validFrom' => '2027-03-01', 'validTo' => '2027-05-31']));
        $this->expectOk($this->postAs('sales', $rules, ['appliesTo' => 'product', 'product' => $milk->sku, 'compute' => 'fixed', 'fixedPrice' => 9.75, 'minQty' => 50, 'validFrom' => '2027-06-01']));
        $bad(['appliesTo' => 'product', 'product' => $milk->sku, 'compute' => 'fixed', 'fixedPrice' => 9, 'minQty' => 50, 'validFrom' => '2027-05-31', 'validTo' => '2027-05-31'], 'PRICE_RULE_DUPLICATE', [409]);
        $this->assertSame(['2027-03-01', '2027-05-31', 'future'], [$spring['validFrom'], $spring['validTo'], $spring['period']]);

        // a discount for a category and a formula for everything
        $cat = $this->expectOk($this->postAs('sales', $rules, ['appliesTo' => 'category', 'category' => $dairy->code, 'compute' => 'percent', 'percent' => 10]));
        $this->assertSame(['category', $dairy->id, 'percent', 10, 'list', '10% off the list price', null], [$cat['appliesTo'], $cat['category']['id'], $cat['compute'], $cat['percent'], $cat['base'], $cat['text']['en'], $cat['fixedPrice']]);
        $all = $this->expectOk($this->postAs('sales', $rules, ['appliesTo' => 'all', 'compute' => 'formula', 'discountPct' => 5, 'roundTo' => 0.5, 'surcharge' => 1]));
        $this->assertSame(['all', 'formula', 5, '0.50', '1.00', 'Formula: The list price · − 5% · rounded to 0.50 · + 1.00'], [$all['appliesTo'], $all['compute'], $all['discountPct'], $all['roundTo'], $all['surcharge'], $all['text']['en']]);

        // the price check prices with them at once
        $price = fn ($qty) => $this->expectOk($this->getAs('sales', "/api/pricing/price?list={$list['code']}&product={$milk->sku}&qty={$qty}"));
        $this->assertSame([12.5, 11, $r['id']], [$price(1)['price'], $price(10)['price'], $price(1)['rule']['id']]);

        // changed: only what is sent; the whole rule is checked again
        $u = $this->expectOk($this->patchAs('sales', "/api/pricing/rules/{$r['id']}", ['fixedPrice' => 13.125]));
        $this->assertSame(['13.125', 1, $milk->id, 'سعر العقد'], [$u['fixedPrice'], $u['changed'], $u['productId'], $u['note']]);
        $this->assertSame(0, $this->expectOk($this->patchAs('sales', "/api/pricing/rules/{$r['id']}", ['fixedPrice' => 13.125]))['changed']);
        $this->assertSame(1, AuditLog::where('action', 'PRICING.RULE.UPDATE')->where('entity_id', $r['id'])->count());
        $this->expectRejected($this->patchAs('sales', "/api/pricing/rules/{$r['id']}", ['compute' => 'percent']), 'PRICE_RULE_INVALID', [400]);
        $this->expectRejected($this->patchAs('sales', "/api/pricing/rules/{$r['id']}", ['minQty' => 10]), 'PRICE_RULE_DUPLICATE', [409]);
        $this->expectRejected($this->patchAs('sales', '/api/pricing/rules/NO-SUCH-RULE', ['fixedPrice' => 3]), 'PRICE_RULE_NOT_FOUND', [404]);
        $pct = $this->expectOk($this->patchAs('sales', "/api/pricing/rules/{$tier['id']}", ['compute' => 'percent', 'percent' => 25]));
        $this->assertSame(['percent', 25, null, 15], [$pct['compute'], $pct['percent'], $pct['fixedPrice'], $price(10)['price']]);

        // archived, not deleted — and its place may then be taken; restoring it into a taken place is refused
        $gone = $this->expectOk($this->postAs('sales', "/api/pricing/rules/{$r['id']}/archive", ['reason' => 'انتهى العقد']));
        $this->assertFalse($gone['active']);
        $this->assertSame(1, StatusHistory::where('entity_type', 'PriceRule')->where('entity_id', $r['id'])->where('to_status', 'archived')->count());
        $this->assertSame(1, PriceRule::whereKey($r['id'])->count());
        $replacement = $this->expectOk($this->postAs('sales', $rules, ['appliesTo' => 'product', 'product' => $milk->sku, 'compute' => 'fixed', 'fixedPrice' => 14]));
        $this->assertSame($replacement['id'], $this->expectRejected($this->postAs('sales', "/api/pricing/rules/{$r['id']}/restore"), 'PRICE_RULE_DUPLICATE', [409])['details']['ruleId']);
        $this->expectOk($this->postAs('sales', "/api/pricing/rules/{$replacement['id']}/archive"));
        $this->assertTrue($this->expectOk($this->postAs('sales', "/api/pricing/rules/{$r['id']}/restore"))['active']);
        $this->expectRejected($this->postAs('proc', "/api/pricing/rules/{$r['id']}/archive"), 'FORBIDDEN', [403]);

        // the rules of the list: what covers the most first, search, filters, sort, paging
        $listed = $this->expectOk($this->getAs('finance', $rules));
        $this->assertSame([6, $list['id']], [$listed['total'], $listed['list']['id']]);
        $this->assertSame(['all', 'category'], array_slice(array_column($listed['items'], 'appliesTo'), 0, 2));
        $this->assertSame(1, $this->expectOk($this->getAs('sales', "{$rules}?state=archived"))['total']);
        $this->assertSame(7, $this->expectOk($this->getAs('sales', "{$rules}?state=all"))['total']);
        $this->assertSame([$cat['id']], array_column($this->expectOk($this->getAs('sales', "{$rules}?appliesTo=category"))['items'], 'id'));
        $this->assertSame(2, $this->expectOk($this->getAs('sales', "{$rules}?compute=percent"))['total']);
        $this->assertSame(4, $this->expectOk($this->getAs('sales', "{$rules}?q={$milk->sku}"))['total']);
        $this->assertSame(1, $this->expectOk($this->getAs('sales', $rules.'?q='.urlencode("ألبان {$tag}")))['total']);
        $this->assertSame(4, $this->expectOk($this->getAs('sales', "{$rules}?product={$milk->sku}"))['total']);
        $byPrice = $this->expectOk($this->getAs('sales', "{$rules}?compute=fixed&sort=fixedPrice&order=desc&pageSize=2"));
        $this->assertSame([3, 2, ['13.125', '10.00']], [$byPrice['total'], $byPrice['pages'], array_column($byPrice['items'], 'fixedPrice')]);
        $this->assertSame(6, $this->expectOk($this->getAs('sales', "/api/pricing/lists/{$list['id']}"))['activeRulesCount']);
    }

    public function test_a_rule_may_start_from_another_list_but_never_in_a_circle(): void
    {
        $tag = self::uid();
        $base = $this->newList("الأساس {$tag}");
        $franchise = $this->newList("فرنشايز {$tag}");
        $dollars = $this->newList("دولار {$tag}", ['currency' => 'USD']);
        $p = $this->product("قهوة {$tag}");
        $this->expectOk($this->postAs('sales', "/api/pricing/lists/{$base['id']}/rules", ['appliesTo' => 'product', 'product' => $p->sku, 'compute' => 'fixed', 'fixedPrice' => 80]));

        $r = $this->expectOk($this->postAs('sales', "/api/pricing/lists/{$franchise['id']}/rules", ['appliesTo' => 'all', 'compute' => 'percent', 'percent' => 10, 'base' => 'pricelist', 'baseList' => $base['code']]));
        $this->assertSame(['pricelist', $base['id'], "10% off the price on list “الأساس {$tag}”"], [$r['base'], $r['baseList']['id'], $r['text']['en']]);
        $this->assertSame(72, $this->expectOk($this->getAs('sales', "/api/pricing/price?list={$franchise['code']}&product={$p->sku}"))['price']);

        $rules = "/api/pricing/lists/{$base['id']}/rules";
        $this->expectRejected($this->postAs('sales', $rules, ['appliesTo' => 'all', 'compute' => 'percent', 'percent' => 5, 'base' => 'pricelist', 'baseList' => $franchise['id']]), 'PRICE_RULE_LOOP', [422]);
        $this->expectRejected($this->postAs('sales', $rules, ['appliesTo' => 'all', 'compute' => 'percent', 'percent' => 5, 'base' => 'pricelist', 'baseList' => $dollars['id']]), 'PRICE_RULE_CURRENCY', [422]);
        $this->expectRejected($this->postAs('sales', $rules, ['appliesTo' => 'all', 'compute' => 'percent', 'percent' => 5, 'base' => 'pricelist', 'baseList' => $base['id']]), 'PRICE_RULE_INVALID', [400]);
        $this->expectRejected($this->postAs('sales', $rules, ['appliesTo' => 'all', 'compute' => 'percent', 'percent' => 5, 'base' => 'pricelist']), 'PRICE_RULE_INVALID', [400]);
        $this->expectRejected($this->postAs('sales', $rules, ['appliesTo' => 'all', 'compute' => 'percent', 'percent' => 5, 'base' => 'pricelist', 'baseList' => 'NO-SUCH']), 'PRICE_LIST_NOT_FOUND', [404]);

        // a list others start from is not archived under them, and does not change its currency
        $this->assertSame(1, $this->expectOk($this->getAs('sales', "/api/pricing/lists/{$base['id']}"))['baseFor']);
        $this->assertSame(1, $this->expectRejected($this->postAs('sales', "/api/pricing/lists/{$base['id']}/archive"), 'PRICE_LIST_IS_BASE', [422])['details']['rules']);
        $this->expectRejected($this->patchAs('sales', "/api/pricing/lists/{$base['id']}", ['currency' => 'USD']), 'PRICE_LIST_CURRENCY_LOCKED', [422]);
        $this->expectOk($this->postAs('sales', "/api/pricing/rules/{$r['id']}/archive"));
        $this->assertFalse($this->expectOk($this->postAs('sales', "/api/pricing/lists/{$base['id']}/archive", ['reason' => 'لم تعد مستخدمة']))['active']);
    }

    public function test_the_default_list_archiving_copying_and_pasted_prices(): void
    {
        $tag = self::uid();
        $this->noDefaultList();
        $first = $this->newList("العامة {$tag}", ['isDefault' => true]);
        $this->assertTrue($first['isDefault']);
        $second = $this->newList("الجديدة {$tag}");

        // one default at most: making another the default takes the title from the first
        $second = $this->expectOk($this->postAs('sales', "/api/pricing/lists/{$second['id']}/default"));
        $this->assertSame([true, false, 1], [$second['isDefault'], PriceList::findOrFail($first['id'])->is_default, PriceList::where('is_default', true)->count()]);
        $this->assertSame($second['id'], $this->expectOk($this->getAs('sales', "/api/pricing/lists?q={$tag}"))['items'][0]['id'], 'the default list comes first');
        $this->expectRejected($this->postAs('proc', "/api/pricing/lists/{$first['id']}/default"), 'FORBIDDEN', [403]);

        // the default list is not archived; nor is a list customers are priced with
        $this->expectRejected($this->postAs('sales', "/api/pricing/lists/{$second['id']}/archive"), 'PRICE_LIST_IS_DEFAULT', [422]);
        $customer = $this->customer("عميل {$tag}");
        PriceListCustomer::create(['customer_id' => $customer->id, 'price_list_id' => $first['id'], 'basis' => 'manual']);
        $this->assertSame(1, $this->expectRejected($this->postAs('sales', "/api/pricing/lists/{$first['id']}/archive"), 'PRICE_LIST_IN_USE', [422])['details']['customers']);
        PriceListCustomer::where('customer_id', $customer->id)->delete();
        $archived = $this->expectOk($this->postAs('sales', "/api/pricing/lists/{$first['id']}/archive", ['reason' => 'دُمجت في الجديدة']));
        $this->assertSame([false, false], [$archived['active'], $archived['usable']]);
        $this->assertSame('دُمجت في الجديدة', StatusHistory::where('entity_type', 'PriceList')->where('entity_id', $first['id'])->where('to_status', 'archived')->value('note'));
        $this->expectRejected($this->postAs('sales', "/api/pricing/lists/{$first['id']}/default"), 'PRICE_LIST_ARCHIVED', [422]);
        $this->assertSame([$first['id']], array_column($this->expectOk($this->getAs('sales', "/api/pricing/lists?q={$tag}&state=archived"))['items'], 'id'));
        $this->assertTrue($this->expectOk($this->postAs('sales', "/api/pricing/lists/{$first['id']}/restore"))['active']);
        // no default at all is a choice too
        $this->assertFalse($this->expectOk($this->postAs('sales', "/api/pricing/lists/{$second['id']}/undefault"))['isDefault']);
        $this->assertSame(0, PriceList::where('is_default', true)->count());

        // pasted prices: one line per product — changed where it differs, added where it is new, all or nothing
        $a = $this->product("سكر {$tag}");
        $b = $this->product("شاي {$tag}");
        $paste = "/api/pricing/lists/{$second['id']}/prices";
        $out = $this->expectOk($this->postAs('sales', $paste, ['items' => [['sku' => $a->sku, 'price' => 10], ['sku' => strtolower($b->sku), 'price' => 20.5, 'minQty' => 12]]]));
        $this->assertSame([2, 0, 0], [$out['created'], $out['updated'], $out['unchanged']]);
        $out = $this->expectOk($this->postAs('sales', $paste, ['items' => [['sku' => $a->sku, 'price' => 11], ['sku' => $b->sku, 'price' => 20.5, 'minQty' => 12], ['sku' => $b->sku, 'price' => 22]]]));
        $this->assertSame([1, 1, 1], [$out['created'], $out['updated'], $out['unchanged']]);
        $pasted = $this->expectOk($this->getAs('sales', "/api/pricing/lists/{$second['id']}/rules?sort=fixedPrice&order=asc"))['items'];
        $this->assertSame([['11.00', 0], ['20.50', 12], ['22.00', 0]], array_map(fn ($x) => [$x['fixedPrice'], $x['minQty']], $pasted));
        $faulty = $this->expectRejected($this->postAs('sales', $paste, ['items' => [['sku' => $a->sku, 'price' => 99], ['sku' => 'NO-SUCH-SKU', 'price' => 5], ['sku' => $a->sku, 'price' => 98]]]), 'PRICE_LINES_INVALID', [400]);
        $this->assertSame(['items.1.sku', 'items.2.sku'], array_column($faulty['details'], 'path'));
        $this->assertSame(11.0, (float) PriceRule::where('price_list_id', $second['id'])->where('product_id', $a->id)->value('fixed_price'), 'a faulty sheet changes nothing');
        $this->expectRejected($this->postAs('sales', $paste, ['items' => [['sku' => $a->sku, 'price' => 0]]]), null, [400]);
        $this->expectRejected($this->postAs('sales', $paste, ['items' => []]), null, [400]);
        $this->expectRejected($this->postAs('proc', $paste, ['items' => [['sku' => $a->sku, 'price' => 5]]]), 'FORBIDDEN', [403]);

        // a copy: the active rules of the list in a list of its own
        $this->expectOk($this->postAs('sales', '/api/pricing/rules/'.PriceRule::where('price_list_id', $second['id'])->where('product_id', $b->id)->where('min_qty', 0)->value('id').'/archive'));
        $copy = $this->postAs('sales', "/api/pricing/lists/{$second['id']}/duplicate", ['name' => "نسخة {$tag}"]);
        $copy->assertStatus(201);
        $copy = $copy->json();
        $this->assertNotSame($second['code'], $copy['code']);
        $this->assertSame(["نسخة {$tag}", 2, false, false, null], [$copy['name'], $copy['rulesCount'], $copy['isDefault'], $copy['owned'], $copy['sourceSystem']]);
        $this->assertEqualsCanonicalizing(['11.00', '20.50'], array_column($this->expectOk($this->getAs('sales', "/api/pricing/lists/{$copy['id']}/rules"))['items'], 'fixedPrice'));
        $this->assertSame(1, AuditLog::where('action', 'PRICING.LIST.DUPLICATE')->where('entity_id', $copy['id'])->count());
        $this->assertStringContainsString($second['name'], $this->expectOk($this->postAs('sales', "/api/pricing/lists/{$second['id']}/duplicate"))['name'], 'a copy without a name is named after its source');
    }

    public function test_what_came_from_odoo_is_read_only_while_the_section_follows_odoo(): void
    {
        $tag = self::uid();
        $this->noDefaultList();
        $p = $this->product("أرز {$tag}");
        $odoo = PriceList::create(['code' => "PRL-O{$tag}", 'name' => "قائمة أودو {$tag}", 'currency' => 'SAR', 'active' => true, 'is_default' => true, 'source_system' => 'odoo', 'external_ref' => "9{$tag}"]);
        $rule = PriceRule::create(['price_list_id' => $odoo->id, 'applies_to' => 'product', 'product_id' => $p->id, 'compute' => 'fixed', 'fixed_price' => 33, 'seq' => 1, 'active' => true, 'source_system' => 'odoo', 'external_ref' => "8{$tag}"]);
        $ours = $this->newList("قائمتنا {$tag}");

        $this->followOdoo(true);
        $shown = $this->expectOk($this->getAs('sales', "/api/pricing/lists/{$odoo->id}"));
        $this->assertSame([true, 'odoo', "9{$tag}"], [$shown['owned'], $shown['sourceSystem'], $shown['externalRef']]);
        $this->assertTrue($this->expectOk($this->getAs('sales', "/api/pricing/lists/{$odoo->id}/rules"))['items'][0]['owned']);
        $this->assertTrue($this->expectOk($this->getAs('sales', '/api/pricing/lookups'))['follows']);
        foreach ([
            ['patch', "/api/pricing/lists/{$odoo->id}", ['name' => 'اسم آخر']],
            ['post', "/api/pricing/lists/{$odoo->id}/archive", []],
            ['post', "/api/pricing/lists/{$odoo->id}/undefault", []],
            ['post', "/api/pricing/lists/{$odoo->id}/rules", ['appliesTo' => 'all', 'compute' => 'fixed', 'fixedPrice' => 5]],
            ['post', "/api/pricing/lists/{$odoo->id}/prices", ['items' => [['sku' => $p->sku, 'price' => 30]]]],
            ['patch', "/api/pricing/rules/{$rule->id}", ['fixedPrice' => 30]],
            ['post', "/api/pricing/rules/{$rule->id}/archive", []],
            // Odoo's default list stands: an OPS list does not take the title while the section follows Odoo
            ['post', "/api/pricing/lists/{$ours['id']}/default", []],
        ] as [$method, $url, $body]) {
            $refused = $this->expectRejected($method === 'patch' ? $this->patchAs('sales', $url, $body) : $this->postAs('sales', $url, $body), 'PRICING_OWNED_BY_SOURCE', [422]);
            $this->assertContains($refused['details']['entity'], ['list', 'rule'], $url);
        }
        $this->assertSame(["قائمة أودو {$tag}", true, true, 33.0], [$odoo->refresh()->name, $odoo->active, $odoo->is_default, (float) $rule->refresh()->fixed_price]);

        // OPS's own list stays OPS's — and a copy of Odoo's list is the way to price differently
        $this->expectOk($this->patchAs('sales', "/api/pricing/lists/{$ours['id']}", ['note' => 'قائمة خاصة بالنظام']));
        $copy = $this->expectOk($this->postAs('sales', "/api/pricing/lists/{$odoo->id}/duplicate", ['name' => "نسخة أودو {$tag}"]));
        $this->assertSame([false, null, 1], [$copy['owned'], $copy['sourceSystem'], $copy['rulesCount']]);
        $copied = $this->expectOk($this->getAs('sales', "/api/pricing/lists/{$copy['id']}/rules"))['items'][0];
        $this->assertSame([false, '33.00'], [$copied['owned'], $copied['fixedPrice']]);
        $this->assertSame('30.00', $this->expectOk($this->patchAs('sales', "/api/pricing/rules/{$copied['id']}", ['fixedPrice' => 30]))['fixedPrice']);

        // the section is handed over: everything is edited here
        $this->followOdoo(false);
        $this->assertSame(false, $this->expectOk($this->getAs('sales', "/api/pricing/lists/{$odoo->id}"))['owned']);
        $this->assertSame('31.00', $this->expectOk($this->patchAs('sales', "/api/pricing/rules/{$rule->id}", ['fixedPrice' => 31]))['fixedPrice']);
        $this->assertTrue($this->expectOk($this->postAs('sales', "/api/pricing/lists/{$ours['id']}/default"))['isDefault']);
        $this->assertFalse($odoo->refresh()->is_default);
    }
}
