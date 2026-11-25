<?php

namespace Tests\Feature\Pricing;

use App\Models\Customer;
use App\Pricing\Models\PriceList;
use App\Pricing\Models\PriceListCustomer;
use App\Pricing\Models\PriceRule;
use App\Pricing\Services\PriceService;
use App\Pricing\Support\Day;
use App\Support\AppError;
use Tests\ApiTestCase;

/**
 * THE price service: "the price of product P for customer C, quantity Q, on day D, and the rule that decided it".
 * Lists and rules are written straight into the tables here (the screens that write them have their own story), so
 * what is proven is the pricing itself: which list, which rule, how the price is worked out, and what is said when
 * there is none.
 */
class PriceServiceTest extends ApiTestCase
{
    use PricingFixtures;

    private PriceService $prices;

    private static int $seq = 5000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->followOdoo(false);
        $this->noDefaultList();
        $this->prices = $this->app->make(PriceService::class);
    }

    private function plist(string $name, array $more = []): PriceList
    {
        return PriceList::create($more + ['code' => 'PRL-T'.self::uid().substr(md5($name.microtime()), 0, 4), 'name' => $name, 'currency' => 'SAR', 'active' => true]);
    }

    private function rule(PriceList $l, array $more): PriceRule
    {
        return PriceRule::create($more + ['price_list_id' => $l->id, 'applies_to' => 'product', 'compute' => 'fixed', 'base' => 'list', 'min_qty' => 0, 'seq' => ++self::$seq, 'active' => true]);
    }

    private function give(Customer $c, PriceList $l, string $basis = 'manual'): void
    {
        PriceListCustomer::updateOrCreate(['customer_id' => $c->id], ['price_list_id' => $l->id, 'basis' => $basis]);
    }

    public function test_the_customers_list_and_its_rule_decide_the_price_and_say_why(): void
    {
        $c = $this->customer('مطعم الواحة');
        $list = $this->plist('أسعار الواحة');
        $this->give($c, $list);
        $p = $this->product('حليب كامل الدسم 1 لتر');
        $this->listPriceOf($p, 60);
        $rule = $this->rule($list, ['product_id' => $p->id, 'fixed_price' => 47.5]);

        $q = $this->prices->customerPrice($c->id, $p->id, 5, null, true);
        $this->assertSame([true, 47.5, 'SAR', 'rule', 5, null], [$q['found'], $q['price'], $q['currency'], $q['source'], $q['qty'], $q['problem']]);
        $this->assertSame([$list->id, 'assigned', $rule->id, 60.0, 20.83], [$q['priceList']['id'], $q['priceList']['via'], $q['rule']['id'], $q['listPrice'], $q['discountPct']]);
        $this->assertSame(['manual', $c->code, $p->sku, Day::today()->toDateString()], [$q['assignment']['basis'], $q['customer']['code'], $q['product']['sku'], $q['date']]);
        $this->assertStringContainsString('أسعار الواحة', $q['reason']['ar']);
        $this->assertStringContainsString('Fixed price 47.50', $q['reason']['en']);
        $this->assertSame(['fixed', 47.5], [$q['steps'][0]['kind'], $q['steps'][0]['value']]);
        $this->assertSame(['applied', $rule->id], [$q['considered'][0]['outcome'], $q['considered'][0]['rule']['id']]);

        // the codes people type find the same records
        $byCode = $this->prices->customerPrice($c->code, $p->sku, 5);
        $this->assertSame([47.5, $rule->id], [$byCode['price'], $byCode['rule']['id']]);
        $this->assertArrayNotHasKey('steps', $byCode, 'the steps are worked out only when asked for');

        // no rule for the product: its list price — and the answer says that is what it is
        $other = $this->product('زيت زيتون 5 لتر');
        $this->listPriceOf($other, 120.125);
        $q = $this->prices->customerPrice($c->id, $other->id, 1);
        $this->assertSame([true, 120.125, 'list_price', null, 0.0], [$q['found'], $q['price'], $q['source'], $q['rule'], $q['discountPct']]);
        $this->assertStringContainsString('the product\'s list price', $q['reason']['en']);

        // no rule and no list price: no price — never a guess
        $bare = $this->product('صنف بلا سعر');
        $q = $this->prices->customerPrice($c->id, $bare->id, 1);
        $this->assertSame([false, null, 'none', 'NO_PRICE', null], [$q['found'], $q['price'], $q['source'], $q['problem'], $q['listPrice']]);
    }

    public function test_the_most_specific_rule_wins_then_the_highest_quantity_then_the_newest(): void
    {
        $c = $this->customer('فندق النخيل');
        $list = $this->plist('أسعار الفنادق');
        $this->give($c, $list);
        $food = $this->category('أغذية');
        $dairy = $this->category('ألبان', $food->id);
        $milk = $this->product('حليب', ['category_id' => $dairy->id]);
        $cheese = $this->product('جبنة', ['category_id' => $dairy->id]);
        $rice = $this->product('أرز', ['category_id' => $food->id]);
        $cups = $this->product('أكواب ورقية');

        $all = $this->rule($list, ['applies_to' => 'all', 'fixed_price' => 90]);
        $onFood = $this->rule($list, ['applies_to' => 'category', 'category_id' => $food->id, 'fixed_price' => 80]);
        $onDairy = $this->rule($list, ['applies_to' => 'category', 'category_id' => $dairy->id, 'fixed_price' => 70]);
        $one = $this->rule($list, ['product_id' => $milk->id, 'fixed_price' => 60]);
        $older = $this->rule($list, ['product_id' => $milk->id, 'fixed_price' => 99, 'min_qty' => 10]);
        $newer = $this->rule($list, ['product_id' => $milk->id, 'fixed_price' => 55, 'min_qty' => 10]); // its twin, written later: the one that is used

        $price = fn ($product, $qty) => $this->prices->customerPrice($c->id, $product->id, $qty);
        $this->assertSame([60.0, $one->id], [$price($milk, 1)['price'], $price($milk, 1)['rule']['id']], 'the product rule beats its category');
        $this->assertSame([60.0, 55.0, 55.0], [$price($milk, 9)['price'], $price($milk, 10)['price'], $price($milk, 250)['price']], 'the tier starts at its quantity');
        $this->assertSame($newer->id, $price($milk, 10)['rule']['id'], 'of two twins the newer decides');
        $this->assertSame([70.0, $onDairy->id], [$price($cheese, 1)['price'], $price($cheese, 1)['rule']['id']], 'the nearest category first');
        $this->assertSame([80.0, $onFood->id], [$price($rice, 1)['price'], $price($rice, 1)['rule']['id']], 'a parent category covers its children');
        $this->assertSame([90.0, $all->id], [$price($cups, 1)['price'], $price($cups, 1)['rule']['id']], 'a product without a category meets only the rule of everything');

        // why each of the others lost
        $seen = collect($this->prices->customerPrice($c->id, $milk->id, 1, null, true)['considered'])->mapWithKeys(fn ($x) => [$x['rule']['id'] => $x['outcome']]);
        $this->assertSame(['belowMinQty', 'belowMinQty', 'applied', 'outranked', 'outranked', 'outranked'],
            [$seen[$newer->id], $seen[$older->id], $seen[$one->id], $seen[$onDairy->id], $seen[$onFood->id], $seen[$all->id]]);
        $twin = collect($this->prices->customerPrice($c->id, $milk->id, 10, null, true)['considered'])->firstWhere('rule.id', $older->id);
        $this->assertSame('outranked', $twin['outcome']);
        $this->assertStringContainsString('never used', $twin['why']['en']);

        // an archived rule is out of the way
        $onDairy->update(['active' => false]);
        $this->assertSame(80.0, $price($cheese, 1)['price']);
    }

    public function test_periods_of_rules_and_of_lists(): void
    {
        $today = Day::today();
        $c = $this->customer('كافيه الصباح');
        $own = $this->plist('أسعار الكافيه');
        $this->give($c, $own);
        $p = $this->product('قهوة مختصة 250 جم');
        $this->listPriceOf($p, 100);
        $later = $this->rule($own, ['product_id' => $p->id, 'fixed_price' => 70, 'valid_from' => $today->copy()->addDays(10)->toDateString(), 'valid_to' => $today->copy()->addDays(20)->toDateString()]);
        $past = $this->rule($own, ['product_id' => $p->id, 'fixed_price' => 65, 'valid_to' => $today->copy()->subDay()->toDateString()]);

        $q = $this->prices->customerPrice($c->id, $p->id, 1, null, true);
        $this->assertSame([100.0, 'list_price'], [$q['price'], $q['source']], 'today neither rule holds');
        $seen = collect($q['considered'])->mapWithKeys(fn ($x) => [$x['rule']['id'] => $x['outcome']]);
        $this->assertSame(['notStarted', 'expired'], [$seen[$later->id], $seen[$past->id]]);
        $q = $this->prices->customerPrice($c->id, $p->id, 1, $today->copy()->addDays(10)->toDateString());
        $this->assertSame([70.0, $later->id], [$q['price'], $q['rule']['id']], 'a period starts on its first day');
        $this->assertSame(70.0, $this->prices->customerPrice($c->id, $p->id, 1, $today->copy()->addDays(20))['price'], 'the last day of a period is inside it');
        $this->assertSame(100.0, $this->prices->customerPrice($c->id, $p->id, 1, $today->copy()->addDays(21)->toDateString())['price']);
        $this->assertSame(65.0, $this->prices->customerPrice($c->id, $p->id, 1, $today->copy()->subDay()->toDateString())['price']);

        // the customer's list is out of its validity: the default list prices instead, and the answer says so
        $default = $this->plist('القائمة العامة', ['is_default' => true]);
        $this->rule($default, ['product_id' => $p->id, 'fixed_price' => 95]);
        $own->update(['valid_to' => $today->copy()->subDay()->toDateString()]);
        $q = $this->prices->customerPrice($c->id, $p->id, 1);
        $this->assertSame([95.0, $default->id, 'default'], [$q['price'], $q['priceList']['id'], $q['priceList']['via']]);
        $this->assertStringContainsString('outside its validity', $q['notes'][0]['en']);
        $this->assertSame(65.0, $this->prices->customerPrice($c->id, $p->id, 1, $today->copy()->subDay()->toDateString())['price'], 'on a day the list was valid it prices');

        // … and so does an archived one
        $own->update(['valid_to' => null, 'active' => false]);
        $q = $this->prices->customerPrice($c->id, $p->id, 1);
        $this->assertSame([95.0, 'default'], [$q['price'], $q['priceList']['via']]);
        $this->assertStringContainsString('archived', $q['notes'][0]['en']);

        // without a default list: the product's list price, with no list named
        $default->update(['is_default' => false]);
        $q = $this->prices->customerPrice($c->id, $p->id, 1);
        $this->assertSame([100.0, 'list_price', null], [$q['price'], $q['source'], $q['priceList']]);

        try {
            $this->prices->customerPrice($c->id, $p->id, 1, '18/09/2026');
            $this->fail('expected INVALID_DATE');
        } catch (AppError $e) {
            $this->assertSame('INVALID_DATE', $e->errorCode);
        }
    }

    public function test_discounts_and_formulas_start_from_the_list_price_the_cost_or_another_list(): void
    {
        $p = $this->product('عسل طبيعي 1 كجم', ['avg_cost' => 120]);
        $this->listPriceOf($p, 200);
        $price = function (PriceList $l, $product = null) use ($p) {
            return $this->prices->listPrice($l->id, ($product ?? $p)->id, 1, null, true);
        };

        // a discount on the list price; a mark-up on the cost
        $off = $this->plist('خصم 10');
        $this->rule($off, ['applies_to' => 'all', 'compute' => 'percent', 'percent' => 10]);
        $this->assertSame([180.0, 'given', ['list', 'discount']], [$price($off)['price'], $price($off)['priceList']['via'], array_column($price($off)['steps'], 'kind')]);
        $plus = $this->plist('التكلفة + 5');
        $this->rule($plus, ['applies_to' => 'all', 'compute' => 'percent', 'percent' => -5, 'base' => 'cost']);
        $this->assertSame([126.0, ['cost', 'discount']], [$price($plus)['price'], array_column($price($plus)['steps'], 'kind')]);

        // the formula, in Odoo's order: discount → rounding → surcharge → floor → ceiling
        $formula = $this->plist('معادلة');
        $this->rule($formula, ['applies_to' => 'all', 'compute' => 'formula', 'discount_pct' => 12.5, 'round_to' => 10, 'surcharge' => 2.5]);
        $q = $price($formula);
        $this->assertSame([182.5, [200.0, 175.0, 180.0, 182.5]], [$q['price'], array_column($q['steps'], 'value')]);
        $this->assertSame(['list', 'discount', 'round', 'surcharge'], array_column($q['steps'], 'kind'));
        $floor = $this->plist('حد أدنى');
        $this->rule($floor, ['applies_to' => 'all', 'compute' => 'formula', 'discount_pct' => 30, 'min_margin' => -50]);
        $this->assertSame([150.0, 'floor'], [$price($floor)['price'], last($price($floor)['steps'])['kind']]);
        $ceiling = $this->plist('حد أعلى');
        $this->rule($ceiling, ['applies_to' => 'all', 'compute' => 'formula', 'discount_pct' => -10, 'max_margin' => 15]);
        $this->assertSame([215.0, 'ceiling'], [$price($ceiling)['price'], last($price($ceiling)['steps'])['kind']]);

        // from another list: the franchise pays the formula list less 10 %
        $franchise = $this->plist('فرنشايز');
        $this->rule($franchise, ['applies_to' => 'all', 'compute' => 'percent', 'percent' => 10, 'base' => 'pricelist', 'base_list_id' => $formula->id]);
        $q = $price($franchise);
        $this->assertSame([164.25, 'pricelist', 182.5], [$q['price'], $q['steps'][0]['kind'], $q['steps'][0]['value']]);
        $this->assertStringContainsString('معادلة', $q['reason']['ar']);

        // what a rule cannot start from is said, and no price is made up
        $uncosted = $this->product('صنف بلا تكلفة');
        $this->listPriceOf($uncosted, 50);
        $this->assertSame([false, 'NO_COST', null], [$price($plus, $uncosted)['found'], $price($plus, $uncosted)['problem'], $price($plus, $uncosted)['price']]);
        $unlisted = $this->product('صنف بلا سعر معلن', ['purchase_price' => 30]);
        $this->assertSame([false, 'NO_LIST_PRICE'], [$price($off, $unlisted)['found'], $price($off, $unlisted)['problem']]);
        $this->assertSame(31.5, $price($plus, $unlisted)['price'], 'the purchase price stands in where no average cost was ever worked out');
        $this->assertSame([false, 'NO_BASE_PRICE'], [$price($franchise, $unlisted)['found'], $price($franchise, $unlisted)['problem']]);

        // two lists that start from each other
        $a = $this->plist('حلقة أ');
        $b = $this->plist('حلقة ب');
        $this->rule($a, ['applies_to' => 'all', 'compute' => 'percent', 'percent' => 5, 'base' => 'pricelist', 'base_list_id' => $b->id]);
        $this->rule($b, ['applies_to' => 'all', 'compute' => 'percent', 'percent' => 5, 'base' => 'pricelist', 'base_list_id' => $a->id]);
        $this->assertFalse($price($a)['found']);
        $this->assertContains($price($a)['problem'], ['RULE_LOOP', 'NO_BASE_PRICE']);

        // another currency is never converted
        $usd = $this->plist('دولار', ['currency' => 'USD']);
        $this->assertSame([false, 'CURRENCY_MISMATCH'], [$price($usd)['found'], $price($usd)['problem']]);
        $this->rule($usd, ['applies_to' => 'all', 'compute' => 'percent', 'percent' => 10]);
        $this->assertSame([false, 'CURRENCY_MISMATCH'], [$price($usd)['found'], $price($usd)['problem']]);
        $this->rule($usd, ['product_id' => $p->id, 'fixed_price' => 48]);
        $this->assertSame([48.0, 'USD'], [$price($usd)['price'], $price($usd)['currency']], 'a fixed price is in the currency of its list');

        // a zero price is given as it is, with a warning
        $free = $this->plist('عينات');
        $this->rule($free, ['product_id' => $p->id, 'fixed_price' => 0]);
        $q = $price($free);
        $this->assertSame([true, 0.0], [$q['found'], $q['price']]);
        $this->assertStringContainsString('zero', $q['notes'][0]['en']);
    }

    public function test_the_default_list_and_the_lines_of_a_document(): void
    {
        $default = $this->plist('القائمة العامة', ['is_default' => true]);
        $c = $this->customer('بقالة الحي');
        $a = $this->product('سكر 10 كجم');
        $b = $this->product('شاي 100 كيس');
        $this->listPriceOf($b, 18);
        $this->rule($default, ['product_id' => $a->id, 'fixed_price' => 40]);
        $this->rule($default, ['product_id' => $a->id, 'fixed_price' => 37.25, 'min_qty' => 20]);

        $q = $this->prices->customerPrice($c->id, $a->id, 2);
        $this->assertSame([40.0, $default->id, 'default', null], [$q['price'], $q['priceList']['id'], $q['priceList']['via'], $q['assignment']]);

        $lines = $this->prices->customerPrices($c->id, [['sku' => $a->sku, 'qty' => 2], ['productId' => $b->id, 'qty' => 3], ['sku' => 'NO-SUCH-SKU', 'qty' => 1], ['sku' => strtolower($a->sku), 'qty' => 20]]);
        $this->assertCount(4, $lines);
        $this->assertSame([40.0, 18.0, null, 37.25], array_column($lines, 'price'));
        $this->assertSame(['rule', 'list_price', 'none', 'rule'], array_column($lines, 'source'));
        $this->assertSame([false, 'PRODUCT_NOT_FOUND'], [$lines[2]['found'], $lines[2]['problem']]);
        foreach ([[0, $a, 2], [1, $b, 3], [3, $a, 20]] as [$i, $product, $qty]) {
            $single = $this->prices->customerPrice($c->id, $product->id, $qty);
            $this->assertSame([$single['price'], $single['source'], $single['rule']['id'] ?? null, $single['reason']], [$lines[$i]['price'], $lines[$i]['source'], $lines[$i]['rule']['id'] ?? null, $lines[$i]['reason']], "line {$i} is priced like a single question");
        }
    }

    public function test_the_price_check_over_http(): void
    {
        $c = $this->customer('مطبخ مركزي');
        $list = $this->plist('أسعار المطابخ');
        $this->give($c, $list);
        $p = $this->product('دجاج مجمد 10 كجم', ['avg_cost' => 100]);
        $this->listPriceOf($p, 150);
        $rule = $this->rule($list, ['product_id' => $p->id, 'compute' => 'percent', 'percent' => -20, 'base' => 'cost']); // cost + 20 %

        $q = $this->expectOk($this->getAs('sales', "/api/pricing/price?customer={$c->code}&product={$p->sku}&qty=4"));
        $this->assertSame([true, 120, 'rule', $rule->id, 4, 'assigned'], [$q['found'], $q['price'], $q['source'], $q['rule']['id'], $q['qty'], $q['priceList']['via']]);
        // a salesperson sees the price, not what the company paid
        $this->assertSame(['cost', null, true], [$q['steps'][0]['kind'], $q['steps'][0]['value'], $q['steps'][0]['hidden']]);
        $q = $this->expectOk($this->getAs('finance', "/api/pricing/price?customer={$c->code}&product={$p->sku}&qty=4"));
        $this->assertSame(['cost', 100], [$q['steps'][0]['kind'], $q['steps'][0]['value']]);

        // a list on its own, without a customer
        $q = $this->expectOk($this->getAs('finance', "/api/pricing/price?list={$list->code}&product={$p->id}"));
        $this->assertSame([120, 'given', null], [$q['price'], $q['priceList']['via'], $q['customer']]);

        // the lines of a document in one call
        $out = $this->expectOk($this->postAs('sales', '/api/pricing/prices', ['customer' => $c->code, 'lines' => [['sku' => $p->sku, 'qty' => 1], ['sku' => 'NO-SUCH', 'qty' => 2]]]));
        $this->assertSame([120, null], array_column($out['items'], 'price'));

        $this->expectRejected($this->getAs('sales', "/api/pricing/price?product={$p->sku}"), 'CUSTOMER_REQUIRED', [400]);
        $this->expectRejected($this->getAs('sales', "/api/pricing/price?customer={$c->code}"), null, [400]);
        $this->expectRejected($this->getAs('sales', "/api/pricing/price?customer={$c->code}&product={$p->sku}&qty=0"), null, [400]);
        $this->expectRejected($this->getAs('sales', "/api/pricing/price?customer=NOBODY&product={$p->sku}"), 'CUSTOMER_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('sales', "/api/pricing/price?customer={$c->code}&product=NOTHING"), 'PRODUCT_NOT_FOUND', [404]);
        // the section is closed to those who do not hold its page
        $this->expectRejected($this->getAs('wm', "/api/pricing/price?customer={$c->code}&product={$p->sku}"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('wm', '/api/pricing/prices', ['customer' => $c->code, 'lines' => [['sku' => $p->sku]]]), 'FORBIDDEN', [403]);

        // what the screens start from
        $lookups = $this->expectOk($this->getAs('sales', '/api/pricing/lookups'));
        $this->assertSame([false, false], [$lookups['follows'], $lookups['costVisible']]);
        $this->assertContains($list->id, array_column($lookups['lists'], 'id'));
        $this->assertSame(['product', 'category', 'all'], $lookups['applies']);
        $summary = $this->expectOk($this->getAs('sales', '/api/pricing/summary'));
        $this->assertGreaterThanOrEqual(1, $summary['lists']['active']);
        $this->assertGreaterThanOrEqual(1, $summary['customers']['manual']);
        $this->assertNull($summary['supplierPrices'], 'supplier figures are purchase costs');
        $this->assertIsArray($this->expectOk($this->getAs('finance', '/api/pricing/summary'))['supplierPrices']);
        $this->expectRejected($this->getAs('wm', '/api/pricing/summary'), 'FORBIDDEN', [403]);
    }
}
