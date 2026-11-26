<?php

namespace Tests\Feature\Pricing;

use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Services\ExternalRefs;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Pricing\Models\PriceList;
use App\Pricing\Models\PriceListCustomer;
use App\Pricing\Models\PriceRule;
use App\Pricing\Models\ProductListPrice;
use App\Pricing\Models\SupplierPrice;
use App\Pricing\OdooPricingProjection;
use App\Pricing\Services\PriceService;
use App\Support\AppError;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * From the copy of Odoo's prices to «قوائم الأسعار» (OdooPricingProjection). The copy is built here by hand — rows of
 * `odoo_models` / `odoo_records` shaped like the ones the mirror writes — and Odoo itself is never called.
 * What is proven: the plan writes nothing; the apply makes every list, rule, list price, customer list and supplier
 * price once; the price service then answers as Odoo would; a change in Odoo arrives through the feed, and what the
 * feed does not carry (a product's sales price, an order, a partner's own price list, a new link) arrives beside it.
 */
class OdooPricingProjectionTest extends ApiTestCase
{
    use PricingFixtures;

    private const FEED = ['product.pricelist', 'product.pricelist.item', 'product.supplierinfo'];

    private string $tag;

    /** @var array<string, Product> */
    private array $p = [];

    /** @var array<string, Customer> */
    private array $c = [];

    /** @var array<string, Supplier> */
    private array $s = [];

    private ProductCategory $dairy;

    private ExternalRefs $refs;

    protected function setUp(): void
    {
        parent::setUp();
        // what an earlier story left: the copy, the links to it, and everything that was projected from it
        foreach (['odoo_changes', 'odoo_records', 'odoo_models', 'pricing_sync_marks'] as $table) {
            DB::table($table)->delete();
        }
        IntExternalRef::where('system', 'odoo')->delete();
        $lists = PriceList::where('source_system', 'odoo')->pluck('id');
        PriceListCustomer::whereIn('price_list_id', $lists)->orWhere('source_system', 'odoo')->delete();
        PriceRule::whereIn('price_list_id', $lists)->orWhere('source_system', 'odoo')->delete();
        PriceList::whereIn('id', $lists)->delete();
        SupplierPrice::where('source_system', 'odoo')->delete();
        ProductListPrice::where('source_system', 'odoo')->delete();
        $this->noDefaultList();
        $this->followOdoo(true);
        $this->refs = $this->app->make(ExternalRefs::class);
        $this->tag = $tag = self::uid();

        // ── OPS master data, linked to Odoo the way the master-data projection links it (the product is the variant)
        $this->dairy = $this->category("ألبان {$tag}");
        $this->refs->link('odoo', 'category', '31', $this->dairy->id, $this->dairy->code);
        foreach (['milk' => 201, 'oil' => 202, 'rice' => 203, 'cups' => 204, 'shirtS' => 206, 'shirtL' => 207] as $key => $variant) {
            $this->p[$key] = $this->product("{$key} {$tag}", $key === 'rice' ? ['category_id' => $this->dairy->id] : []);
            $this->refs->link('odoo', 'product', (string) $variant, $this->p[$key]->id, $this->p[$key]->sku);
        }
        foreach (['c1' => 501, 'c2' => 502, 'c3' => 503, 'c4' => 504, 'c5' => 507] as $key => $partner) {
            $this->c[$key] = $this->customer("عميل {$key} {$tag}", ['source_system' => 'odoo']);
            $this->refs->link('odoo', 'customer', (string) $partner, $this->c[$key]->id, $this->c[$key]->code);
        }
        foreach (['s1' => 601, 's2' => 602] as $key => $partner) {
            $this->s[$key] = $this->supplier("مورد {$key} {$tag}");
            $this->refs->link('odoo', 'supplier', (string) $partner, $this->s[$key]->id, $this->s[$key]->code);
        }

        // ── the copy
        foreach ([...self::FEED, 'product.template', 'product.product', 'res.partner', 'sale.order'] as $model) {
            $this->odooModel($model, in_array($model, ['product.pricelist', 'product.template', 'product.product', 'res.partner'], true));
        }
        // price lists: #1 is Odoo's general fallback (lowest sequence among the lists not tied to a country group)
        $list = fn (int $id, string $name, int $sequence, bool $active = true, array $more = []) => $this->rec('product.pricelist', $id,
            $more + ['name' => $name, 'sequence' => $sequence, 'currency_id' => [151, 'SAR'], 'company_id' => [1, 'شركة سعر للتجارة'], 'discount_policy' => 'with_discount'], $active);
        $list(1, 'قائمة الأسعار الافتراضية SAR', 10);
        $list(2, "مطاعم الواحة {$tag}", 16);
        $list(3, "فنادق {$tag}", 12);
        $list(4, "قديمة {$tag}", 19, false);
        $list(5, "خارجية {$tag}", 5, true, ['country_group_ids' => [3]]);
        // rules, in every shape Odoo has: a variant, a template (twice: twins), a product OPS lacks, a category, everything, a
        // formula from another list, a template with two variants, a rule of a list that does not exist
        $item = fn (int $id, int $listId, array $more) => $this->rec('product.pricelist.item', $id, $more + ['pricelist_id' => [$listId, "list {$listId} (SAR)"], 'compute_price' => 'fixed', 'base' => 'list_price',
            'min_quantity' => 0.0, 'currency_id' => [151, 'SAR'], 'company_id' => [1, 'شركة سعر للتجارة']]);
        $item(11, 2, ['applied_on' => '0_product_variant', 'product_id' => [201, '[MILK] حليب'], 'product_tmpl_id' => [101, '[MILK] حليب'], 'fixed_price' => 47.0, 'min_quantity' => 1.0]);
        $item(12, 2, ['applied_on' => '1_product', 'product_tmpl_id' => [102, '[OIL] زيت'], 'fixed_price' => 103.5]);
        $item(13, 2, ['applied_on' => '1_product', 'product_tmpl_id' => [102, '[OIL] زيت'], 'fixed_price' => 99.0]);
        $item(14, 2, ['applied_on' => '1_product', 'product_tmpl_id' => [105, '[NEW] صنف ليس في النظام'], 'fixed_price' => 20.0]);
        $item(15, 3, ['applied_on' => '2_product_category', 'categ_id' => [31, 'All / ألبان'], 'compute_price' => 'percentage', 'percent_price' => 10.0]);
        $item(16, 3, ['applied_on' => '3_global', 'compute_price' => 'formula', 'price_discount' => 5.0, 'price_round' => 0.5, 'price_surcharge' => 1.0]);
        $item(17, 4, ['applied_on' => '0_product_variant', 'product_id' => [203, '[RICE] أرز'], 'product_tmpl_id' => [103, '[RICE] أرز'], 'fixed_price' => 12.0]);
        $item(18, 3, ['applied_on' => '1_product', 'product_tmpl_id' => [106, '[SHIRT] قميص'], 'fixed_price' => 30.0, 'min_quantity' => 5.0, 'date_start' => '2026-01-01 21:00:00']);
        $item(19, 3, ['applied_on' => '0_product_variant', 'product_id' => [201, '[MILK] حليب'], 'product_tmpl_id' => [101, '[MILK] حليب'], 'compute_price' => 'formula', 'base' => 'pricelist',
            'base_pricelist_id' => [2, 'مطاعم الواحة (SAR)'], 'price_discount' => 10.0]);
        $item(20, 99, ['applied_on' => '3_global', 'fixed_price' => 1.0]);
        // products: the sales price is on the template, the OPS product is linked to the variant
        foreach ([[101, [201], 60.0, 'MILK'], [102, [202], 120.0, 'OIL'], [103, [203], 15.125, 'RICE'], [104, [204], 0.0, 'CUPS'], [105, [205], 33.0, 'NEW'], [106, [206, 207], 40.0, 'SHIRT']] as [$template, $variants, $price, $code]) {
            $this->rec('product.template', $template, ['name' => $code, 'list_price' => $price], true);
            foreach ($variants as $variant) {
                $this->rec('product.product', $variant, ['default_code' => "{$code}-{$variant}", 'product_tmpl_id' => [$template, "[{$code}]"]], true);
            }
        }
        // supplier prices (Odoo keeps them on the template)
        $price = fn (int $id, int $partner, int $template, float $amount, array $more = []) => $this->rec('product.supplierinfo', $id, $more + ['partner_id' => [$partner, "مورد {$partner}"],
            'product_tmpl_id' => [$template, "template {$template}"], 'price' => $amount, 'min_qty' => 1.0, 'delay' => 0, 'discount' => 0.0, 'sequence' => 1, 'currency_id' => [151, 'SAR']]);
        $price(31, 601, 101, 38.5);
        $price(32, 602, 101, 40.0, ['delay' => 3, 'discount' => 5.0, 'sequence' => 2, 'product_code' => 'SUP-MILK', 'date_start' => '2026-01-01', 'date_end' => '2027-12-31']);
        $price(33, 603, 102, 100.0);
        $price(34, 601, 105, 18.0);
        $price(35, 601, 106, 25.0);
        // partners — as the copy holds them today: without Odoo's own "price list of the partner"
        foreach ([501, 502, 503, 504, 506, 507] as $id) {
            $this->rec('res.partner', $id, ['name' => "عميل {$id}", 'customer_rank' => 1], true);
        }
        $this->rec('res.partner', 505, ['name' => 'مسؤول المشتريات', 'parent_id' => [504, 'عميل 504']], true);
        foreach ([601, 602, 603] as $id) {
            $this->rec('res.partner', $id, ['name' => "مورد {$id}", 'supplier_rank' => 1], true);
        }
        OdooModel::where('model', 'res.partner')->update(['fields' => json_encode(['name' => 'char', 'parent_id' => 'many2one', 'customer_rank' => 'integer'])]);
        // orders: what each customer was last priced with
        $this->order(901, 501, 2, '2026-08-01 10:00:00');
        $this->order(902, 501, 3, '2026-08-20 10:00:00', 'cancel');
        $this->order(903, 501, 2, '2026-08-10 09:00:00');
        $this->order(904, 502, 1, '2026-08-15 09:00:00');
        $this->order(905, 505, 3, '2026-08-05 09:00:00', 'draft');
        $this->order(906, 506, 3, '2026-08-06 09:00:00');
        $this->order(907, 503, 4, '2026-08-07 09:00:00');
    }

    private function order(int $id, int $partner, int $list, string $date, string $state = 'sale', int $ago = 7200): void
    {
        $this->rec('sale.order', $id, ['name' => 'S'.str_pad((string) $id, 5, '0', STR_PAD_LEFT), 'partner_id' => [$partner, "عميل {$partner}"], 'pricelist_id' => [$list, "list {$list}"], 'date_order' => $date, 'state' => $state], null, $ago);
    }

    private function projection(): OdooPricingProjection
    {
        return $this->app->make(OdooPricingProjection::class);
    }

    private function opsList(int $odooId): PriceList
    {
        return PriceList::where('source_system', 'odoo')->where('external_ref', (string) $odooId)->firstOrFail();
    }

    private function listOf(string $customer): ?PriceListCustomer
    {
        return PriceListCustomer::with('priceList')->where('customer_id', $this->c[$customer]->id)->first();
    }

    private function price(string $customer, string $product, int $qty = 1): array
    {
        return $this->app->make(PriceService::class)->customerPrice($this->c[$customer]->id, $this->p[$product]->id, $qty);
    }

    /** What a first load finds in this copy. */
    private const FIRST = [
        'lists.default' => 1, 'lists.created' => 5,
        'rules.created' => 10, 'rules.waitingProduct' => 1, 'rules.skippedNoList' => 1,
        'supplierPrices.created' => 6, 'supplierPrices.waitingSupplier' => 1, 'supplierPrices.waitingProduct' => 1,
        'listPrices.created' => 5, 'listPrices.skippedZero' => 1, 'listPrices.skippedNoProduct' => 1,
        'assignments.fromOrders' => 3, 'assignments.created' => 3, 'assignments.default' => 1, 'assignments.noEvidence' => 1,
    ];

    public function test_plan_then_apply_and_the_price_service_answers_as_odoo_would(): void
    {
        $projection = $this->projection();
        $this->assertSame(OdooPricingProjection::class, config('odoo.projections.pricing'), 'the runs find the class under its registered name');
        $this->assertSame(['pricing', true, true, self::FEED], [$projection->key(), $projection->enabled(), OdooProjection::owns('pricing'), $projection->models()]);

        // the plan counts and writes nothing
        $before = [PriceList::count(), PriceRule::count(), SupplierPrice::count(), ProductListPrice::count(), PriceListCustomer::count(), AuditLog::count(), DB::table('pricing_sync_marks')->count()];
        $plan = $projection->plan();
        $this->assertTrue($plan['dryRun']);
        $this->assertEquals(self::FIRST, $plan['counts']);
        $this->assertSame($before, [PriceList::count(), PriceRule::count(), SupplierPrice::count(), ProductListPrice::count(), PriceListCustomer::count(), AuditLog::count(), DB::table('pricing_sync_marks')->count()]);
        $this->assertStringContainsString('#1 قائمة الأسعار الافتراضية SAR', $plan['notes']['lists.default'][0]);
        $this->assertStringContainsString('[NEW]', $plan['notes']['rules.waitingProduct'][0]);
        $this->assertStringContainsString('#20', $plan['notes']['rules.skippedNoList'][0]);
        $this->assertStringContainsString('مورد 603', $plan['notes']['supplierPrices.waitingSupplier'][0]);

        // the apply does what the plan said
        $applied = $projection->apply();
        $this->assertFalse($applied['dryRun']);
        $this->assertEquals(self::FIRST, $applied['counts']);
        $this->assertArrayNotHasKey('failed', $applied);

        // ── price lists
        $lists = PriceList::where('source_system', 'odoo')->orderBy('sequence')->get();
        $this->assertSame([5, 10, 12, 16, 19], $lists->pluck('sequence')->all());
        $this->assertSame([false, true, false, false, false], $lists->pluck('is_default')->all(), 'the list tied to a country group is not the general default');
        $this->assertSame([true, true, true, true, false], $lists->pluck('active')->all());
        $general = $this->opsList(1);
        $this->assertStringStartsWith('PRL-', $general->code);
        $this->assertSame(['قائمة الأسعار الافتراضية SAR', 'SAR', 'with_discount', 'شركة سعر للتجارة', 'svc.odoo'], [$general->name, $general->currency, $general->extra['discountPolicy'], $general->extra['company'], $general->created_by]);
        $this->assertSame(5, $lists->pluck('code')->unique()->count());

        // ── rules: every one is there, also the one that waits for its product
        $rules = PriceRule::where('source_system', 'odoo')->get()->keyBy('external_ref');
        $this->assertEqualsCanonicalizing(['11', '12', '13', '14', '15', '16', '17', '18', '18/207', '19'], $rules->keys()->map(fn ($k) => (string) $k)->all());
        $this->assertSame(['product', $this->p['milk']->id, 1.0, 47.0, 'fixed', 11, null], [$rules['11']->applies_to, $rules['11']->product_id, $rules['11']->min_qty, $rules['11']->fixed_price, $rules['11']->compute, $rules['11']->seq, $rules['11']->pending_label]);
        $this->assertSame([null, true, '[NEW] صنف ليس في النظام'], [$rules['14']->product_id, $rules['14']->pending(), $rules['14']->pending_label]);
        $this->assertSame(['category', $this->dairy->id, 'percent', 10.0, 'list'], [$rules['15']->applies_to, $rules['15']->category_id, $rules['15']->compute, $rules['15']->percent, $rules['15']->base]);
        $this->assertSame(['all', 'formula', 5.0, 0.5, 1.0], [$rules['16']->applies_to, $rules['16']->compute, $rules['16']->discount_pct, $rules['16']->round_to, $rules['16']->surcharge]);
        $this->assertSame([$this->p['shirtS']->id, $this->p['shirtL']->id, 5.0, '2026-01-02'], [$rules['18']->product_id, $rules['18/207']->product_id, $rules['18/207']->min_qty, $rules['18']->valid_from->format('Y-m-d')], 'a template rule covers each variant; an Odoo moment becomes the business day');
        $this->assertSame(['formula', 'pricelist', $this->opsList(2)->id, 10.0], [$rules['19']->compute, $rules['19']->base, $rules['19']->base_list_id, $rules['19']->discount_pct]);
        $this->assertSame(10, AuditLog::where('username', 'svc.odoo')->where('action', 'PRICING.RULE.CREATE')->whereIn('entity_id', $rules->pluck('id'))->count());

        // ── list prices, the list of each customer, supplier prices
        $this->assertSame([60.0, 120.0, 15.125, null, 40.0, 40.0], array_map(fn ($k) => ProductListPrice::where('product_id', $this->p[$k]->id)->value('price'), ['milk', 'oil', 'rice', 'cups', 'shirtS', 'shirtL']));
        $this->assertSame(['odoo', '101'], [ProductListPrice::where('product_id', $this->p['milk']->id)->value('source_system'), ProductListPrice::where('product_id', $this->p['milk']->id)->value('external_ref')]);
        $c1 = $this->listOf('c1');
        $this->assertSame([$this->opsList(2)->id, 'order', 'S00903', 903, '2026-08-10', 'odoo', '501'],
            [$c1->price_list_id, $c1->basis, $c1->basis_ref, $c1->basis_odoo_id, $c1->basis_at->format('Y-m-d'), $c1->source_system, $c1->external_ref], 'the latest order that was not cancelled');
        $this->assertNull($this->listOf('c2'), 'a customer on Odoo\'s default list has no list of its own here either');
        $this->assertSame([$this->opsList(4)->id, $this->opsList(3)->id, null], [$this->listOf('c3')->price_list_id, $this->listOf('c4')->price_list_id, $this->listOf('c5')], 'an order placed by a contact speaks for its company');
        $prices = SupplierPrice::where('source_system', 'odoo')->get()->keyBy('external_ref');
        $this->assertEqualsCanonicalizing(['31', '32', '33', '34', '35', '35/207'], $prices->keys()->map(fn ($k) => (string) $k)->all());
        $this->assertSame([$this->s['s2']->id, $this->p['milk']->id, 40.0, 5.0, 38.0, 3, 'SUP-MILK', '2026-01-01', '2027-12-31', 2],
            [$prices['32']->supplier_id, $prices['32']->product_id, $prices['32']->price, $prices['32']->discount_pct, $prices['32']->net(), $prices['32']->lead_days, $prices['32']->supplier_sku, $prices['32']->valid_from->format('Y-m-d'), $prices['32']->valid_to->format('Y-m-d'), $prices['32']->sequence]);
        $this->assertSame([null, 1.0], [$prices['31']->lead_days, $prices['31']->min_qty], 'a delay of zero is "not stated"');
        $this->assertSame([null, $this->p['oil']->id, true], [$prices['33']->supplier_id, $prices['33']->product_id, $prices['33']->pending()]);
        $this->assertStringContainsString('مورد 603', $prices['33']->pending_label);
        $this->assertSame([$this->s['s1']->id, null], [$prices['34']->supplier_id, $prices['34']->product_id]);
        $this->assertSame([$this->p['shirtS']->id, $this->p['shirtL']->id], [$prices['35']->product_id, $prices['35/207']->product_id]);

        // ── the price service answers as Odoo would
        $this->assertSame([47.0, 'rule', 'assigned'], [$this->price('c1', 'milk')['price'], $this->price('c1', 'milk')['source'], $this->price('c1', 'milk')['priceList']['via']]);
        $this->assertSame([99.0, $rules['13']->id], [$this->price('c1', 'oil')['price'], $this->price('c1', 'oil')['rule']['id']], 'of two rules for one product Odoo takes the newer');
        $this->assertSame([15.125, 'list_price'], [$this->price('c1', 'rice')['price'], $this->price('c1', 'rice')['source']], 'no rule: the sales price of the product');
        $onDefault = $this->price('c2', 'milk');
        $this->assertSame([60.0, 'list_price', $general->id, 'default'], [$onDefault['price'], $onDefault['source'], $onDefault['priceList']['id'], $onDefault['priceList']['via']]);
        $this->assertSame(13.6125, $this->price('c4', 'rice')['price'], 'the category rule: 10 % off the sales price');
        $this->assertSame(42.3, $this->price('c4', 'milk')['price'], 'a formula that starts from another list: 47 less 10 %');
        $this->assertSame([39.0, 30.0, 30.0], [$this->price('c4', 'shirtS')['price'], $this->price('c4', 'shirtS', 5)['price'], $this->price('c4', 'shirtL', 7)['price']], 'the formula of everything, then the tier of both variants');
        $this->assertSame([false, 'NO_LIST_PRICE'], [$this->price('c4', 'cups')['found'], $this->price('c4', 'cups')['problem']]);
        $archived = $this->price('c3', 'rice');
        $this->assertSame([15.125, 'default'], [$archived['price'], $archived['priceList']['via']], 'a customer whose list was archived in Odoo is priced with the default list, as in Odoo');
        $this->assertStringContainsString('archived', $archived['notes'][0]['en']);
        $best = $this->app->make(PriceService::class)->bestSupplierPrice($this->p['milk']->id, 1);
        $this->assertSame([38.0, $this->s['s2']->code, 3, 2], [$best['price'], $best['supplier']['code'], $best['leadDays'], count($best['offers'])]);
        $this->assertFalse($this->app->make(PriceService::class)->bestSupplierPrice($this->p['oil']->id, 1, null, false)['found'], 'a price that waits for its supplier is not offered');

        // ── a second pass changes nothing
        $again = $projection->apply();
        $this->assertEquals([
            'lists.default' => 1, 'lists.unchanged' => 5, 'rules.unchanged' => 10, 'rules.waitingProduct' => 1, 'rules.skippedNoList' => 1,
            'supplierPrices.unchanged' => 6, 'supplierPrices.waitingSupplier' => 1, 'supplierPrices.waitingProduct' => 1,
            'listPrices.unchanged' => 5, 'listPrices.skippedZero' => 1, 'listPrices.skippedNoProduct' => 1,
            'assignments.fromOrders' => 3, 'assignments.unchanged' => 3, 'assignments.default' => 1, 'assignments.noEvidence' => 1,
        ], $again['counts']);
        $this->assertSame([5, 10, 6, 5, 3], [PriceList::where('source_system', 'odoo')->count(), PriceRule::where('source_system', 'odoo')->count(), SupplierPrice::where('source_system', 'odoo')->count(),
            ProductListPrice::where('source_system', 'odoo')->count(), PriceListCustomer::where('source_system', 'odoo')->count()]);
        $this->assertSame([0, 0], [$projection->consume()['changes'], $projection->waiting()], 'nothing waits after a whole pass');
    }

    public function test_before_the_first_load_a_run_only_follows_the_feed(): void
    {
        $projection = $this->projection();
        $this->odooWrites('product.pricelist.item', 11, ['fixed_price' => 45.0]);

        // the rule the feed names is taken, with its list; the thousands of records of a first load are not the work of a run
        $out = $projection->consume();
        $this->assertSame(1, $out['changes']);
        $this->assertEquals(['lists.created' => 1, 'rules.created' => 1], $out['counts']);
        $this->assertSame([1, 1, 0, 0, 0], [PriceList::where('source_system', 'odoo')->count(), PriceRule::where('source_system', 'odoo')->count(), ProductListPrice::where('source_system', 'odoo')->count(),
            PriceListCustomer::where('source_system', 'odoo')->count(), DB::table('pricing_sync_marks')->count()]);
        $this->assertSame(['changes' => 0, 'counts' => []], array_intersect_key($projection->consume(), ['changes' => 1, 'counts' => 1]));

        // the first load completes the picture and keeps what the run already took
        $counts = $projection->apply()['counts'];
        $this->assertSame([4, 1, 9, 1, 5, 3], [$counts['lists.created'], $counts['lists.unchanged'], $counts['rules.created'], $counts['rules.unchanged'], $counts['listPrices.created'], $counts['assignments.created']]);
        $this->assertSame(45.0, $this->price('c1', 'milk')['price']);
        $this->assertGreaterThanOrEqual(5, DB::table('pricing_sync_marks')->count());
    }

    public function test_a_change_in_odoo_arrives_through_the_feed(): void
    {
        $projection = $this->projection();
        $projection->apply();
        $tag = $this->tag;

        $this->odooWrites('product.pricelist.item', 11, ['fixed_price' => 45.0]);
        $this->odooDeletes('product.pricelist.item', 13);
        $this->odooWrites('product.pricelist.item', 21, ['pricelist_id' => [2, 'مطاعم الواحة (SAR)'], 'applied_on' => '0_product_variant', 'product_id' => [203, '[RICE] أرز'], 'product_tmpl_id' => [103, '[RICE] أرز'],
            'compute_price' => 'fixed', 'base' => 'list_price', 'fixed_price' => 14.0, 'min_quantity' => 0.0]);
        $this->odooWrites('product.pricelist', 3, ['name' => "فنادق ومنتجعات {$tag}"]);
        $this->odooWrites('product.pricelist', 4, [], true, true); // restored in Odoo
        $this->odooWrites('product.supplierinfo', 31, ['price' => 36.0, 'delay' => 2]);
        $this->odooDeletes('product.supplierinfo', 32);
        $this->assertSame(7, $projection->waiting());

        $out = $projection->consume();
        $this->assertSame(7, $out['changes']);
        $this->assertEquals(['rules.updated' => 1, 'rules.archived' => 1, 'rules.created' => 1, 'lists.updated' => 2, 'supplierPrices.updated' => 1, 'supplierPrices.archived' => 1], $out['counts']);
        $this->assertArrayNotHasKey('failed', $out);
        $this->assertSame([0, 0], [$projection->waiting(), OdooChange::whereIn('model', self::FEED)->whereNull('processed_at')->count()]);

        $this->assertSame(45.0, $this->price('c1', 'milk')['price']);
        $gone = PriceRule::where('source_system', 'odoo')->where('external_ref', '13')->firstOrFail();
        $this->assertSame([false, true], [$gone->active, $gone->source_deleted_at !== null], 'a rule deleted in Odoo is archived here, not removed');
        $this->assertSame(103.5, $this->price('c1', 'oil')['price'], 'its older twin decides again');
        $this->assertSame([14.0, 'rule'], [$this->price('c1', 'rice')['price'], $this->price('c1', 'rice')['source']]);
        $this->assertSame(["فنادق ومنتجعات {$tag}", true], [$this->opsList(3)->name, $this->opsList(4)->active]);
        $this->assertSame(1, AuditLog::where('username', 'svc.odoo')->where('action', 'PRICING.LIST.UPDATE')->where('entity_id', $this->opsList(3)->id)->count());
        $best = $this->app->make(PriceService::class)->bestSupplierPrice($this->p['milk']->id, 1);
        $this->assertSame([36.0, $this->s['s1']->code, 2, 1], [$best['price'], $best['supplier']['code'], $best['leadDays'], count($best['offers'])]);
        $this->assertFalse(SupplierPrice::where('external_ref', '32')->where('source_system', 'odoo')->value('active'));

        // nothing new: nothing happens, and the run has no line to write
        $this->assertSame(['changes' => 0, 'counts' => []], array_intersect_key($projection->consume(), ['changes' => 1, 'counts' => 1]));

        // a rule the feed names before its list was ever taken brings its list along
        $this->rec('product.pricelist', 6, ['name' => "جديدة {$tag}", 'sequence' => 30, 'currency_id' => [151, 'SAR']], true, 0);
        $this->odooWrites('product.pricelist.item', 22, ['pricelist_id' => [6, 'جديدة (SAR)'], 'applied_on' => '3_global', 'compute_price' => 'fixed', 'fixed_price' => 5.0]);
        $out = $projection->consume();
        $this->assertSame([1, 1], [$out['counts']['lists.created'], $out['counts']['rules.created']]);
        $this->assertSame($this->opsList(6)->id, PriceRule::where('external_ref', '22')->where('source_system', 'odoo')->value('price_list_id'));
    }

    public function test_what_the_feed_does_not_carry_arrives_beside_it(): void
    {
        $projection = $this->projection();
        $projection->apply();
        $newOrder = fn (int $id, int $partner, int $list, string $date, string $state = 'sale') => $this->order($id, $partner, $list, $date, $state, 0);

        // a product's sales price changes in Odoo; a customer orders with another list — neither is in the section's feed
        $this->odooWrites('product.template', 101, ['list_price' => 66.0], false);
        $newOrder(908, 501, 3, '2026-09-01 08:00:00');
        $newOrder(909, 503, 2, '2026-07-01 08:00:00');            // older than the order c3's list was read from
        $newOrder(910, 502, 3, '2026-09-02 08:00:00', 'cancel');  // cancelled: says nothing
        $this->assertSame(0, $projection->waiting());
        $out = $projection->consume();
        $this->assertSame(2, $out['changes'], 'what was written beside the feed is the run\'s line');
        $this->assertEquals(['listPrices.updated' => 1, 'assignments.updated' => 1], $out['counts']);
        $this->assertSame(66.0, $this->price('c2', 'milk')['price']);
        $c1 = $this->listOf('c1');
        $this->assertSame([$this->opsList(3)->id, 'S00908', 908], [$c1->price_list_id, $c1->basis_ref, $c1->basis_odoo_id]);
        $this->assertSame([$this->opsList(4)->id, null], [$this->listOf('c3')->price_list_id, $this->listOf('c2')]);
        $this->assertSame(1, AuditLog::where('username', 'svc.odoo')->where('action', 'PRICING.ASSIGN')->where('entity_id', $this->c['c1']->id)->where('new_value', 'like', '%S00908%')->count());

        // a person corrects what was only read from the orders — and a later order does not undo the correction
        $ours = PriceList::create(['code' => "PRL-M{$this->tag}", 'name' => "قائمتنا {$this->tag}", 'currency' => 'SAR', 'active' => true]);
        $fixed = $this->expectOk($this->postAs('sales', "/api/pricing/customers/{$this->c['c4']->code}/assign", ['list' => $ours->id]));
        $this->assertSame(['manual', false], [$fixed['assignment']['basis'], $fixed['locked']]);
        $newOrder(911, 504, 2, '2026-09-05 08:00:00');
        $out = $projection->consume();
        $this->assertSame([0, 1], [$out['changes'], $out['counts']['assignments.keptManual']]);
        $this->assertSame([$ours->id, 'manual'], [$this->listOf('c4')->price_list_id, $this->listOf('c4')->basis]);

        // the order a list was read from changes its price list: the customer follows
        $this->order(908, 501, 2, '2026-09-01 08:00:00', 'sale', 0);
        $this->assertSame(1, $projection->consume()['counts']['assignments.updated']);
        $this->assertSame($this->opsList(2)->id, $this->listOf('c1')->price_list_id);

        // the latest order uses Odoo's default list: the customer has no list of its own any more
        $newOrder(912, 501, 1, '2026-09-10 08:00:00');
        $this->assertSame(1, $projection->consume()['counts']['assignments.removed']);
        $this->assertNull($this->listOf('c1'));
        $this->assertSame([66.0, 'default'], [$this->price('c1', 'milk')['price'], $this->price('c1', 'milk')['priceList']['via']]);
        // … and an older order that changes later (it is delivered, invoiced) does not bring the list back
        $this->order(901, 501, 2, '2026-08-01 10:00:00', 'sale', 0);
        $this->assertSame(0, $projection->consume()['changes']);
        $this->assertNull($this->listOf('c1'));
    }

    public function test_what_waited_for_a_link_is_completed_when_the_link_appears(): void
    {
        $projection = $this->projection();
        $projection->apply();
        $prices = $this->app->make(PriceService::class);

        // the master data makes Odoo's product, supplier and customer OPS records
        $new = $this->product("صنف جديد {$this->tag}");
        $this->refs->link('odoo', 'product', '205', $new->id, $new->sku);
        $s3 = $this->supplier("مورد جديد {$this->tag}");
        $this->refs->link('odoo', 'supplier', '603', $s3->id, $s3->code);
        $c6 = $this->customer("عميل جديد {$this->tag}", ['source_system' => 'odoo']);
        $this->refs->link('odoo', 'customer', '506', $c6->id, $c6->code);

        $out = $projection->consume();
        $this->assertSame(5, $out['changes']);
        $this->assertSame([1, 2, 1, 1], [$out['counts']['rules.updated'], $out['counts']['supplierPrices.updated'], $out['counts']['listPrices.created'], $out['counts']['assignments.created']]);
        $this->assertArrayNotHasKey('rules.waitingProduct', $out['counts']);
        $rule = PriceRule::where('source_system', 'odoo')->where('external_ref', '14')->firstOrFail();
        $this->assertSame([$new->id, null, false], [$rule->product_id, $rule->pending_label, $rule->pending()]);
        $this->assertSame([20.0, 33.0], [$prices->customerPrice($this->c['c1']->id, $new->id)['price'], $prices->customerPrice($this->c['c2']->id, $new->id)['price']]);
        $this->assertSame([100.0, $s3->code], [$prices->bestSupplierPrice($this->p['oil']->id)['price'], $prices->bestSupplierPrice($this->p['oil']->id)['supplier']['code']]);
        $this->assertSame([18.0, $this->s['s1']->code], [$prices->bestSupplierPrice($new->id)['price'], $prices->bestSupplierPrice($new->id)['supplier']['code']]);
        $this->assertSame([0, 0], [SupplierPrice::where('source_system', 'odoo')->where(fn ($w) => $w->whereNull('supplier_id')->orWhereNull('product_id'))->count(), PriceRule::where('source_system', 'odoo')->whereNull('product_id')->where('applies_to', 'product')->count()]);
        $list = PriceListCustomer::where('customer_id', $c6->id)->firstOrFail();
        $this->assertSame([$this->opsList(3)->id, 'order', 'S00906'], [$list->price_list_id, $list->basis, $list->basis_ref]);

        // no new link: the look is not repeated
        $this->assertSame(['changes' => 0, 'counts' => []], array_intersect_key($projection->consume(), ['changes' => 1, 'counts' => 1]));
    }

    public function test_odoos_own_field_replaces_what_was_read_from_the_orders(): void
    {
        $projection = $this->projection();
        $projection->apply(); // read from the orders: c1 → list 2, c3 → list 4 (archived), c4 → list 3
        $ours = PriceList::create(['code' => "PRL-M{$this->tag}", 'name' => "قائمتنا {$this->tag}", 'currency' => 'SAR', 'active' => true]);
        $this->expectOk($this->postAs('sales', "/api/pricing/customers/{$this->c['c4']->code}/assign", ['list' => $ours->id])); // a person's correction

        // the copy starts carrying the partner's own price list (the field is among the copied fields, the partners were read again)
        OdooModel::where('model', 'res.partner')->update(['fields' => json_encode(['name' => 'char', 'parent_id' => 'many2one', 'customer_rank' => 'integer', 'property_product_pricelist' => 'many2one'])]);
        $this->odooWrites('res.partner', 501, ['property_product_pricelist' => [3, 'فنادق (SAR)']], false);
        $this->odooWrites('res.partner', 502, ['property_product_pricelist' => [1, 'قائمة الأسعار الافتراضية SAR (SAR)']], false);
        $this->odooWrites('res.partner', 504, ['property_product_pricelist' => [2, 'مطاعم الواحة (SAR)']], false);
        $this->odooWrites('res.partner', 507, ['property_product_pricelist' => [2, 'مطاعم الواحة (SAR)']], false);
        // 503 carries nothing: in Odoo it has no list of its own

        $out = $projection->consume();
        $this->assertSame(4, $out['changes']);
        $this->assertEquals(['assignments.updated' => 2, 'assignments.created' => 1, 'assignments.removed' => 1, 'assignments.default' => 1], array_intersect_key($out['counts'], ['assignments.updated' => 1, 'assignments.created' => 1, 'assignments.removed' => 1, 'assignments.default' => 1, 'assignments.keptManual' => 1]));
        $this->assertSame([$this->opsList(3)->id, 'partner', null, 'odoo'], [$this->listOf('c1')->price_list_id, $this->listOf('c1')->basis, $this->listOf('c1')->basis_ref, $this->listOf('c1')->source_system]);
        $this->assertSame([null, null], [$this->listOf('c2'), $this->listOf('c3')], 'the default list, and no list of its own: no row');
        $this->assertSame([$this->opsList(2)->id, 'partner'], [$this->listOf('c4')->price_list_id, $this->listOf('c4')->basis], 'Odoo\'s own field stands against a correction made here');
        $this->assertSame([$this->opsList(2)->id, 'partner'], [$this->listOf('c5')->price_list_id, $this->listOf('c5')->basis]);

        // it is Odoo's now: shown as such and not changed here
        $shown = $this->expectOk($this->getAs('sales', "/api/pricing/customers/{$this->c['c1']->code}"));
        $this->assertSame(['partner', true, true], [$shown['assignment']['basis'], $shown['assignment']['owned'], $shown['locked']]);
        $this->expectRejected($this->postAs('sales', "/api/pricing/customers/{$this->c['c1']->code}/assign", ['list' => $ours->id]), 'PRICING_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('sales', "/api/pricing/customers/{$this->c['c3']->code}/assign", ['list' => $ours->id]), 'PRICING_OWNED_BY_SOURCE', [422]);

        // an order no longer moves anything; a change of the partner does
        $this->order(913, 501, 2, '2026-09-12 08:00:00', 'sale', 0);
        $this->odooWrites('res.partner', 502, ['property_product_pricelist' => [3, 'فنادق (SAR)']], false);
        $this->rec('res.partner', 507, ['name' => 'عميل 507', 'customer_rank' => 1], true, 0); // its list was taken off in Odoo
        $out = $projection->consume();
        $this->assertSame([2, 1, 1], [$out['changes'], $out['counts']['assignments.created'], $out['counts']['assignments.removed']]);
        $this->assertSame([$this->opsList(3)->id, $this->opsList(3)->id, null], [$this->listOf('c1')->price_list_id, $this->listOf('c2')->price_list_id, $this->listOf('c5')]);

        // a whole pass says the same
        $plan = $projection->plan()['counts'];
        $this->assertSame([3, 3, 2], [$plan['assignments.fromPartner'], $plan['assignments.unchanged'], $plan['assignments.default']]);
        $this->assertArrayNotHasKey('assignments.fromOrders', $plan);
    }

    public function test_a_copy_that_is_not_complete_and_a_section_that_was_handed_over(): void
    {
        $projection = $this->projection();
        // the lists, rules or supplier prices are still being copied: nothing is guessed from half a picture
        OdooModel::where('model', 'product.supplierinfo')->update(['full_state' => 'running']);
        $this->assertFalse($projection->ready());
        foreach (['plan', 'apply'] as $method) {
            try {
                $projection->{$method}();
                $this->fail('expected ODOO_COPY_INCOMPLETE');
            } catch (AppError $e) {
                $this->assertSame('ODOO_COPY_INCOMPLETE', $e->errorCode);
            }
        }
        $this->assertSame(0, PriceList::where('source_system', 'odoo')->count());
        OdooModel::where('model', 'product.supplierinfo')->update(['full_state' => 'done']);

        // what is read beside the feed waits for its own model: the rest is taken
        OdooModel::whereIn('model', ['product.template', 'sale.order'])->update(['full_state' => 'running']);
        $counts = $projection->apply()['counts'];
        $this->assertSame([5, 10, 6, 1, 5], [$counts['lists.created'], $counts['rules.created'], $counts['supplierPrices.created'], $counts['listPrices.copyNotReady'], $counts['assignments.noEvidence']]);
        $this->assertSame([0, 0], [ProductListPrice::where('source_system', 'odoo')->count(), PriceListCustomer::where('source_system', 'odoo')->count()]);
        OdooModel::whereIn('model', ['product.template', 'sale.order'])->update(['full_state' => 'done']);
        $counts = $projection->apply()['counts'];
        $this->assertSame([5, 3, 5, 10], [$counts['listPrices.created'], $counts['assignments.created'], $counts['lists.unchanged'], $counts['rules.unchanged']]);
        OdooModel::where('model', 'res.partner')->update(['full_state' => 'running']);
        $this->assertSame(1, $projection->plan()['counts']['assignments.copyNotReady']);
        OdooModel::where('model', 'res.partner')->update(['full_state' => 'done']);

        // reading a customer's list from its orders can be switched off: only Odoo's own field then decides
        PriceListCustomer::where('source_system', 'odoo')->delete();
        config(['odoo.pricing.assign_from_orders' => false]);
        $counts = $projection->plan()['counts'];
        $this->assertSame(5, $counts['assignments.noEvidence']);
        $this->assertArrayNotHasKey('assignments.created', $counts);
        config(['odoo.pricing.assign_from_orders' => true]);
        $projection->apply();

        // while the section follows Odoo, what came from it is read-only over the API …
        $list = $this->opsList(2);
        $rule = PriceRule::where('source_system', 'odoo')->where('external_ref', '11')->firstOrFail();
        $price = SupplierPrice::where('source_system', 'odoo')->where('external_ref', '31')->firstOrFail();
        $shown = $this->expectOk($this->getAs('sales', "/api/pricing/lists/{$list->code}"));
        $this->assertSame([true, 'odoo', '2', 4, 1, 1], [$shown['owned'], $shown['sourceSystem'], $shown['externalRef'], $shown['activeRulesCount'], $shown['customersCount'], $shown['pendingRules']]);
        $waiting = $this->expectOk($this->getAs('sales', "/api/pricing/lists/{$list->code}/rules?state=pending"));
        $this->assertSame([1, true, '[NEW] صنف ليس في النظام', null], [$waiting['total'], $waiting['items'][0]['pending'], $waiting['items'][0]['pendingLabel'], $waiting['items'][0]['product']]);
        $this->assertSame(5, $this->expectOk($this->getAs('sales', '/api/pricing/lists?source=odoo&state=all'))['total']);
        $this->expectRejected($this->patchAs('sales', "/api/pricing/lists/{$list->id}", ['name' => 'x']), 'PRICING_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->patchAs('sales', "/api/pricing/rules/{$rule->id}", ['fixedPrice' => 1]), 'PRICING_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('sales', '/api/pricing/list-prices', ['product' => $this->p['milk']->sku, 'price' => 1]), 'PRICING_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->patchAs('proc', "/api/pricing/supplier-prices/{$price->id}", ['price' => 1]), 'PRICING_OWNED_BY_SOURCE', [422]);
        $pendingPrices = $this->expectOk($this->getAs('proc', '/api/pricing/supplier-prices?state=pending&source=odoo'));
        $this->assertSame(2, $pendingPrices['total']);
        $summary = $this->expectOk($this->getAs('finance', '/api/pricing/summary'));
        $this->assertSame([true, true, 0], [$summary['odoo']['follows'], $summary['odoo']['ready'], $summary['odoo']['waiting']]);
        $this->assertSame([$this->opsList(1)->id, 5, 1, 3, 2], [$summary['defaultList']['id'], $summary['lists']['fromOdoo'], $summary['rules']['pending'], $summary['customers']['order'], $summary['supplierPrices']['pending']]);
        $customers = $this->expectOk($this->getAs('sales', "/api/pricing/customers?q={$this->tag}&basis=order&sort=code"));
        $this->assertSame([3, 'S00903'], [$customers['total'], collect($customers['items'])->firstWhere('code', $this->c['c1']->code)['assignment']['basisRef']]);

        // … and an OPS price typed for a product that is Odoo's is taken over by the next pass
        ProductListPrice::where('product_id', $this->p['milk']->id)->update(['source_system' => null, 'external_ref' => null, 'price' => 77]);
        $this->assertSame(1, $projection->apply()['counts']['listPrices.adopted']);
        $this->assertSame([60.0, 'odoo'], [ProductListPrice::where('product_id', $this->p['milk']->id)->value('price'), ProductListPrice::where('product_id', $this->p['milk']->id)->value('source_system')]);

        // … handed over: the switch is off, the runs leave the section alone and everything is edited here
        $this->followOdoo(false);
        $this->assertSame([false, false], [$projection->enabled(), OdooProjection::owns('pricing')]);
        $this->assertSame('أسعار الواحة', $this->expectOk($this->patchAs('sales', "/api/pricing/lists/{$list->id}", ['name' => 'أسعار الواحة']))['name']);
        $this->assertSame('44.00', $this->expectOk($this->patchAs('sales', "/api/pricing/rules/{$rule->id}", ['fixedPrice' => 44]))['fixedPrice']);
        $this->assertSame('35.00', $this->expectOk($this->patchAs('proc', "/api/pricing/supplier-prices/{$price->id}", ['price' => 35]))['price']);
    }
}
