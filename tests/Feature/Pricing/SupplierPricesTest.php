<?php

namespace Tests\Feature\Pricing;

use App\Models\AuditLog;
use App\Models\ProductSupplier;
use App\Models\StatusHistory;
use App\Pricing\Models\SupplierPrice;
use App\Pricing\Services\PriceService;
use App\Pricing\Support\Day;
use Tests\ApiTestCase;

/**
 * Supplier price lists and "the best supplier price for product P, quantity Q, on day D": what a supplier price
 * accepts and refuses, who may write and who may even read it (it is a purchase cost), and which offer wins.
 */
class SupplierPricesTest extends ApiTestCase
{
    use PricingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->followOdoo(false);
    }

    public function test_supplier_prices_are_kept_per_supplier_and_product(): void
    {
        $tag = self::uid();
        $s1 = $this->supplier("مورد الألبان {$tag}");
        $s2 = $this->supplier("مورد الجملة {$tag}");
        $milk = $this->product("حليب {$tag}");
        $rice = $this->product("أرز {$tag}");
        $url = '/api/pricing/supplier-prices';

        $r = $this->postAs('proc', $url, ['supplier' => $s1->code, 'product' => $milk->sku, 'price' => 40.125, 'discountPct' => 4, 'minQty' => 1, 'leadDays' => 3, 'supplierSku' => 'DAIRY-77', 'note' => 'عرض 2026']);
        $r->assertStatus(201);
        $r = $r->json();
        $this->assertSame([$s1->code, $milk->sku, '40.125', 4, '38.52', 1, 3, 'DAIRY-77', 'SAR', true, false, false, 'valid', 1],
            [$r['supplier']['code'], $r['product']['sku'], $r['price'], $r['discountPct'], $r['netPrice'], $r['minQty'], $r['leadDays'], $r['supplierSku'], $r['currency'], $r['active'], $r['pending'], $r['owned'], $r['period'], $r['sequence']]);
        $this->assertSame(1, AuditLog::where('action', 'PRICING.SUPPLIERPRICE.CREATE')->where('entity_id', $r['id'])->where('username', 'proc')->count());

        // what a supplier price refuses to be
        $bad = fn (array $body, ?string $code, array $status) => $this->expectRejected($this->postAs('proc', $url, $body), $code, $status);
        $bad(['supplier' => $s1->code, 'product' => $milk->sku], null, [400]);
        $bad(['supplier' => $s1->code, 'product' => $milk->sku, 'price' => 0], null, [400]);
        $bad(['supplier' => $s1->code, 'product' => $milk->sku, 'price' => 5, 'discountPct' => 100], null, [400]);
        $bad(['supplier' => $s1->code, 'product' => $milk->sku, 'price' => 5, 'minQty' => 500, 'validFrom' => '2027-02-01', 'validTo' => '2027-01-01'], 'SUPPLIER_PRICE_INVALID', [400]);
        $bad(['supplier' => 'NO-SUCH', 'product' => $milk->sku, 'price' => 5], 'SUPPLIER_NOT_FOUND', [404]);
        $bad(['supplier' => $s1->code, 'product' => 'NO-SUCH', 'price' => 5], 'PRODUCT_NOT_FOUND', [404]);
        // the same supplier, product, quantity and period twice: the buyer could not tell which holds
        $this->assertSame($r['id'], $bad(['supplier' => $s1->id, 'product' => $milk->id, 'price' => 39, 'minQty' => 1], 'SUPPLIER_PRICE_DUPLICATE', [409])['details']['id']);
        // sales maintains customer lists, not supplier prices — and does not read what the company pays
        $this->expectRejected($this->postAs('sales', $url, ['supplier' => $s1->code, 'product' => $rice->sku, 'price' => 5]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('sales', $url), 'PRICING_COST_HIDDEN', [403]);
        $this->expectRejected($this->getAs('sales', "{$url}/{$r['id']}"), 'PRICING_COST_HIDDEN', [403]);
        $this->expectRejected($this->getAs('sales', "{$url}/suppliers"), 'PRICING_COST_HIDDEN', [403]);
        $this->expectRejected($this->getAs('sales', "/api/pricing/supplier-price?product={$milk->sku}"), 'PRICING_COST_HIDDEN', [403]);
        $this->expectRejected($this->getAs('wm', $url), 'FORBIDDEN', [403]);
        // finance reads cost, and does not write supplier prices
        $this->assertSame($r['id'], $this->expectOk($this->getAs('finance', "{$url}/{$r['id']}"))['id']);
        $this->expectRejected($this->postAs('finance', $url, ['supplier' => $s1->code, 'product' => $rice->sku, 'price' => 5]), 'FORBIDDEN', [403]);
        $this->assertSame(1, SupplierPrice::whereIn('supplier_id', [$s1->id, $s2->id])->count());

        // a quantity tier, a later period, another supplier, another product
        $tier = $this->expectOk($this->postAs('proc', $url, ['supplier' => $s1->code, 'product' => $milk->sku, 'price' => 37, 'minQty' => 100]));
        $next = $this->expectOk($this->postAs('proc', $url, ['supplier' => $s1->code, 'product' => $milk->sku, 'price' => 36, 'minQty' => 100.5, 'validFrom' => '2027-01-01']));
        $other = $this->expectOk($this->postAs('proc', $url, ['supplier' => $s2->code, 'product' => $milk->sku, 'price' => 39.5]));
        $this->expectOk($this->postAs('proc', $url, ['supplier' => $s2->code, 'product' => $rice->sku, 'price' => 120, 'validTo' => Day::today()->subDays(3)->toDateString()]));
        $this->assertSame([2, 3, 4, 'future', null, 0], [$tier['sequence'], $next['sequence'], $other['sequence'], $next['period'], $other['leadDays'], $other['minQty']]);

        // changed: only what is sent; the whole price is checked again
        $u = $this->expectOk($this->patchAs('proc', "{$url}/{$r['id']}", ['price' => 41, 'discountPct' => 0, 'leadDays' => null]));
        $this->assertSame(['41.00', 0, '41.00', null, 3, 'DAIRY-77'], [$u['price'], $u['discountPct'], $u['netPrice'], $u['leadDays'], $u['changed'], $u['supplierSku']]);
        $this->assertSame(0, $this->expectOk($this->patchAs('proc', "{$url}/{$r['id']}", ['price' => 41]))['changed']);
        $this->assertSame(1, AuditLog::where('action', 'PRICING.SUPPLIERPRICE.UPDATE')->where('entity_id', $r['id'])->count());
        $this->expectRejected($this->patchAs('proc', "{$url}/{$tier['id']}", ['minQty' => 1]), 'SUPPLIER_PRICE_DUPLICATE', [409]);
        $this->expectRejected($this->patchAs('proc', "{$url}/NO-SUCH", ['price' => 4]), 'SUPPLIER_PRICE_NOT_FOUND', [404]);
        $this->expectRejected($this->patchAs('sales', "{$url}/{$r['id']}", ['price' => 4]), 'FORBIDDEN', [403]);

        // archived, not deleted; its place may be taken, and then it does not come back
        $gone = $this->expectOk($this->postAs('proc', "{$url}/{$tier['id']}/archive", ['reason' => 'انتهى العرض']));
        $this->assertFalse($gone['active']);
        $this->assertSame('انتهى العرض', StatusHistory::where('entity_type', 'SupplierPrice')->where('entity_id', $tier['id'])->value('note'));
        $replacement = $this->expectOk($this->postAs('proc', $url, ['supplier' => $s1->code, 'product' => $milk->sku, 'price' => 36.5, 'minQty' => 100]));
        $this->expectRejected($this->postAs('proc', "{$url}/{$tier['id']}/restore"), 'SUPPLIER_PRICE_DUPLICATE', [409]);
        $this->expectOk($this->postAs('proc', "{$url}/{$replacement['id']}/archive"));
        $this->assertTrue($this->expectOk($this->postAs('proc', "{$url}/{$tier['id']}/restore"))['active']);
        $this->expectRejected($this->postAs('finance', "{$url}/{$tier['id']}/archive"), 'FORBIDDEN', [403]);

        // listed: by supplier, by product, by period, by state, searched, sorted, paged
        $bySupplier = $this->expectOk($this->getAs('finance', "{$url}?supplier={$s1->code}"));
        $this->assertSame([3, false], [$bySupplier['total'], $bySupplier['follows']]);
        $this->assertSame(4, $this->expectOk($this->getAs('proc', "{$url}?product={$milk->sku}"))['total']);
        $this->assertSame(5, $this->expectOk($this->getAs('proc', "{$url}?product={$milk->sku}&state=all"))['total']);
        $this->assertSame([$replacement['id']], array_column($this->expectOk($this->getAs('proc', "{$url}?product={$milk->sku}&state=archived"))['items'], 'id'));
        $this->assertSame(3, $this->expectOk($this->getAs('proc', "{$url}?product={$milk->sku}&period=valid"))['total']);
        $this->assertSame([$next['id']], array_column($this->expectOk($this->getAs('proc', "{$url}?q={$tag}&period=future"))['items'], 'id'));
        $this->assertSame(1, $this->expectOk($this->getAs('proc', "{$url}?q={$tag}&period=expired"))['total']);
        $this->assertSame(1, $this->expectOk($this->getAs('proc', "{$url}?q=DAIRY-77"))['total']);
        $this->assertSame(2, $this->expectOk($this->getAs('proc', $url.'?q='.urlencode("مورد الجملة {$tag}")))['total']);
        $byPrice = $this->expectOk($this->getAs('proc', "{$url}?product={$milk->sku}&sort=price&order=asc&pageSize=3"));
        $this->assertSame([4, 2, ['36.00', '37.00', '39.50']], [$byPrice['total'], $byPrice['pages'], array_column($byPrice['items'], 'price')]);
        $this->assertSame(0, $this->expectOk($this->getAs('proc', "{$url}?q={$tag}&source=odoo"))['total']);
        $this->assertSame(0, $this->expectOk($this->getAs('proc', "{$url}?q={$tag}&state=pending"))['total']);

        // one "price list" per supplier
        $suppliers = $this->expectOk($this->getAs('finance', "{$url}/suppliers?q={$tag}"));
        $this->assertSame(2, $suppliers['total']);
        $this->assertSame([[$s1->code, 3, 1, 0], [$s2->code, 2, 2, 1]], array_map(fn ($x) => [$x['code'], $x['prices'], $x['products'], $x['expired']], $suppliers['items']));

        // what came from Odoo is read-only while the section follows it; OPS's own prices stay OPS's
        SupplierPrice::whereKey($other['id'])->update(['source_system' => 'odoo', 'external_ref' => "5{$tag}"]);
        $this->followOdoo(true);
        $this->assertTrue($this->expectOk($this->getAs('proc', "{$url}/{$other['id']}"))['owned']);
        $this->assertSame('supplierPrice', $this->expectRejected($this->patchAs('proc', "{$url}/{$other['id']}", ['price' => 1]), 'PRICING_OWNED_BY_SOURCE', [422])['details']['entity']);
        $this->expectRejected($this->postAs('proc', "{$url}/{$other['id']}/archive"), 'PRICING_OWNED_BY_SOURCE', [422]);
        $this->assertSame('41.50', $this->expectOk($this->patchAs('proc', "{$url}/{$r['id']}", ['price' => 41.5]))['price']);
        $this->followOdoo(false);
        $this->assertSame('39.00', $this->expectOk($this->patchAs('proc', "{$url}/{$other['id']}", ['price' => 39]))['price']);
    }

    public function test_the_best_supplier_price_for_a_quantity_on_a_day(): void
    {
        $tag = self::uid();
        $today = Day::today();
        $prices = $this->app->make(PriceService::class);
        $p = $this->product("زيت نباتي 18 لتر {$tag}", ['purchase_price' => 99]);
        $near = $this->supplier("مورد قريب {$tag}", ['lead_days' => 2]);
        $cheap = $this->supplier("مورد أرخص {$tag}", ['lead_days' => 9]);
        $late = $this->supplier("مورد موقوف {$tag}", ['status' => 'suspended']);
        $review = $this->supplier("مورد تحت المراجعة {$tag}", ['status' => 'review']);
        $row = fn ($s, float $price, array $more = []) => SupplierPrice::create($more + ['supplier_id' => $s->id, 'product_id' => $p->id, 'price' => $price, 'currency' => 'SAR', 'active' => true]);

        // nothing on the supplier price lists yet: what procurement used before — the supplier link, then the product's purchase price
        $q = $prices->bestSupplierPrice($p->id, 10);
        $this->assertSame([true, 99.0, 'purchase_price', null, []], [$q['found'], $q['price'], $q['source'], $q['supplier'], $q['offers']]);
        ProductSupplier::create(['product_id' => $p->id, 'supplier_id' => $near->id, 'price' => 97.5, 'lead_days' => 4, 'preferred' => true]);
        $q = $prices->bestSupplierPrice($p->id, 10);
        $this->assertSame([97.5, 'product_supplier', $near->code, 4], [$q['price'], $q['source'], $q['supplier']['code'], $q['leadDays']]);
        $this->assertSame([false, null, 'none'], array_values(array_intersect_key($prices->bestSupplierPrice($p->id, 10, null, false), ['found' => 1, 'price' => 1, 'source' => 1])));

        // the price lists decide: the lowest after discount, for the quantity asked
        $a = $row($near, 100, ['sequence' => 1]);
        $b = $row($cheap, 104, ['discount_pct' => 5, 'sequence' => 2, 'lead_days' => 6]);      // 98.80 after discount
        $bulk = $row($near, 92, ['min_qty' => 50, 'sequence' => 1]);
        $q = $prices->bestSupplierPrice($p->id, 10);
        $this->assertSame([true, 98.8, 'supplier_price', $cheap->code, 6, $b->id], [$q['found'], $q['price'], $q['source'], $q['supplier']['code'], $q['leadDays'], $q['best']['id']]);
        $this->assertSame([[$b->id, 98.8, true, true], [$a->id, 100.0, false, true]], array_map(fn ($o) => [$o['id'], $o['net'], $o['best'], $o['orderable']], $q['offers']));
        $this->assertSame([[$bulk->id, 'belowMinQty']], array_map(fn ($o) => [$o['id'], $o['outcome']], $q['others']));
        $this->assertStringContainsString('2', $q['reason']['en']);
        $q = $prices->bestSupplierPrice($p->id, 50);
        $this->assertSame([92.0, $near->code, 2, $bulk->id], [$q['price'], $q['supplier']['code'], $q['leadDays'], $q['best']['id']], 'the tier wins from its quantity, with the supplier\'s own lead time');
        $this->assertSame('tier', collect($q['others'])->firstWhere('id', $a->id)['outcome']);

        // a supplier that cannot be ordered from is shown and never chosen
        $c = $row($late, 80, ['sequence' => 3]);
        $d = $row($review, 85, ['sequence' => 4]);
        $q = $prices->bestSupplierPrice($p->id, 10);
        $this->assertSame([98.8, $b->id], [$q['price'], $q['best']['id']]);
        $this->assertSame([$b->id, $a->id, $c->id, $d->id], array_column($q['offers'], 'id'));
        $this->assertSame([false, 'The supplier is suspended', false, 'The supplier is under review'],
            [$q['offers'][2]['orderable'], $q['offers'][2]['notOrderableWhy']['en'], $q['offers'][3]['orderable'], $q['offers'][3]['notOrderableWhy']['en']]);

        // periods: a price that ended or has not started is out; the day asked for decides
        $b->update(['valid_to' => $today->copy()->subDay()->toDateString()]);
        $promo = $row($cheap, 90, ['valid_from' => $today->copy()->addDays(5)->toDateString(), 'valid_to' => $today->copy()->addDays(9)->toDateString(), 'sequence' => 2]);
        $q = $prices->bestSupplierPrice($p->id, 10);
        $this->assertSame([100.0, $a->id], [$q['price'], $q['best']['id']]);
        $this->assertEqualsCanonicalizing([[$b->id, 'expired'], [$promo->id, 'future'], [$bulk->id, 'belowMinQty']], array_map(fn ($o) => [$o['id'], $o['outcome']], $q['others']));
        $onPromo = $prices->bestSupplierPrice($p->id, 10, $today->copy()->addDays(7)->toDateString());
        $this->assertSame([90.0, $promo->id, $cheap->code], [$onPromo['price'], $onPromo['best']['id'], $onPromo['supplier']['code']]);
        $this->assertSame(98.8, $prices->bestSupplierPrice($p->id, 10, $today->copy()->subDays(2))['price']);

        // one supplier's price — and when it has none on the lists, its link price; another supplier has neither
        $q = $prices->supplierPrice($near->id, $p->id, 60);
        $this->assertSame([92.0, 'supplier_price', 1], [$q['price'], $q['source'], count($q['offers'])]);
        $this->assertSame([80.0, false], [$prices->supplierPrice($late->code, $p->sku, 1)['offers'][0]['net'], $prices->supplierPrice($late->code, $p->sku, 1)['found']]);
        $a->update(['active' => false]);
        $bulk->update(['active' => false]);
        $q = $prices->supplierPrice($near->id, $p->id, 10);
        $this->assertSame([97.5, 'product_supplier'], [$q['price'], $q['source']]);
        $nobody = $this->supplier("مورد بلا سعر {$tag}");
        $this->assertSame([false, 'none'], [$prices->supplierPrice($nobody->id, $p->id, 10)['found'], $prices->supplierPrice($nobody->id, $p->id, 10)['source']]);

        // every orderable supplier is out for today: no price is made up from the lists, and the answer says why
        $q = $prices->bestSupplierPrice($p->id, 10, null, false);
        $this->assertSame([false, null], [$q['found'], $q['price']]);
        $this->assertStringContainsString('suspended or under review', $q['reason']['en']);

        // over HTTP, for those who may see cost
        $http = $this->expectOk($this->getAs('proc', "/api/pricing/supplier-price?product={$p->sku}&qty=10&date=".$today->copy()->addDays(7)->toDateString()));
        $this->assertSame([true, 90, $cheap->code, $promo->id], [$http['found'], $http['price'], $http['supplier']['code'], $http['best']['id']]);
        $one = $this->expectOk($this->getAs('finance', "/api/pricing/supplier-price?product={$p->sku}&supplier={$near->code}&qty=10&fallback=0"));
        $this->assertSame([false, 'none'], [$one['found'], $one['source']]);
        $this->expectRejected($this->getAs('proc', '/api/pricing/supplier-price?qty=10'), null, [400]);
        $this->expectRejected($this->getAs('proc', '/api/pricing/supplier-price?product=NO-SUCH'), 'PRODUCT_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('proc', "/api/pricing/supplier-price?product={$p->sku}&supplier=NO-SUCH"), 'SUPPLIER_NOT_FOUND', [404]);
    }
}
