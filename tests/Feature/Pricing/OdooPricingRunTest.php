<?php

namespace Tests\Feature\Pricing;

use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Models\OdooRun;
use App\Integration\Odoo\OdooSync;
use App\Pricing\Models\PriceList;
use App\Pricing\Models\PriceListCustomer;
use App\Pricing\Models\PriceRule;
use App\Pricing\Models\ProductListPrice;
use App\Pricing\Models\SupplierPrice;
use App\Pricing\OdooPricingProjection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;
use Tests\Feature\Integration\FakeOdoo;

/**
 * The section inside a real run of the mirror: an Odoo that lives in memory is copied by the mirror itself, and the
 * run hands what changed to the projection registered under `odoo.projections.pricing` — nobody calls it by hand.
 * (How each kind of record is taken is the story of OdooPricingProjectionTest; this one proves the plumbing: the class
 * is found, built, asked, and its answer is the line the run keeps.) The fake Odoo records every call that is not a
 * read: there must be none.
 */
class OdooPricingRunTest extends ApiTestCase
{
    use PricingFixtures;

    public function test_a_run_of_the_mirror_feeds_the_section_by_itself(): void
    {
        foreach (['odoo_runs', 'odoo_changes', 'odoo_records', 'odoo_models', 'pricing_sync_marks'] as $table) {
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
        Cache::flush();
        $feed = ['product.pricelist', 'product.pricelist.item', 'product.supplierinfo'];
        config(['odoo' => FakeOdoo::config(['priority' => $feed, 'projections' => ['pricing' => OdooPricingProjection::class], 'project' => ['master' => false, 'pricing' => true]])]);
        $odoo = (new FakeOdoo)->install();
        $odoo->model('product.pricelist', ['name' => 'char', 'sequence' => 'integer', 'active' => 'boolean', 'currency_id' => 'many2one'], 'Pricelist');
        $odoo->model('product.pricelist.item', ['pricelist_id' => 'many2one', 'applied_on' => 'selection', 'compute_price' => 'selection', 'base' => 'selection', 'fixed_price' => 'float', 'percent_price' => 'float',
            'min_quantity' => 'float', 'date_start' => 'datetime', 'date_end' => 'datetime'], 'Pricelist Rule');
        $odoo->model('product.supplierinfo', ['partner_id' => 'many2one', 'product_tmpl_id' => 'many2one', 'price' => 'float', 'min_qty' => 'float', 'delay' => 'integer'], 'Supplier Pricelist');
        $tag = self::uid();
        $list = $odoo->create('product.pricelist', ['name' => "قائمة الجملة {$tag}", 'sequence' => 10, 'currency_id' => [151, 'SAR']]);
        $rule = $odoo->create('product.pricelist.item', ['pricelist_id' => [$list, 'قائمة الجملة (SAR)'], 'applied_on' => '3_global', 'compute_price' => 'fixed', 'base' => 'list_price', 'fixed_price' => 9.5, 'min_quantity' => 0.0]);
        $sync = fn () => $this->app->make(OdooSync::class)->run(30, 'test');

        // the first copy is not a stream of changes: the section stays empty until its first load
        $sync();
        $projection = $this->app->make(config('odoo.projections.pricing'));
        $this->assertInstanceOf(OdooPricingProjection::class, $projection);
        $this->assertSame([true, true, 0, 0], [$projection->enabled(), $projection->ready(), $projection->waiting(), PriceList::where('source_system', 'odoo')->count()]);
        $applied = $projection->apply();
        $this->assertSame([1, 1, 1], [$applied['counts']['lists.created'], $applied['counts']['rules.created'], $applied['counts']['lists.default']]);
        $opsList = PriceList::where('source_system', 'odoo')->where('external_ref', (string) $list)->firstOrFail();
        $this->assertSame(["قائمة الجملة {$tag}", true, 'SAR'], [$opsList->name, $opsList->is_default, $opsList->currency]);
        $this->assertSame(9.5, PriceRule::where('source_system', 'odoo')->where('external_ref', (string) $rule)->value('fixed_price'));

        // people change Odoo; the next run of the mirror brings it into the section, and says so in its own line
        $odoo->write('product.pricelist.item', $rule, ['fixed_price' => 8.75]);
        $tier = $odoo->create('product.pricelist.item', ['pricelist_id' => [$list, 'قائمة الجملة (SAR)'], 'applied_on' => '3_global', 'compute_price' => 'percentage', 'base' => 'list_price', 'percent_price' => 12.5, 'min_quantity' => 10.0]);
        $run = $sync();
        $step = OdooRun::findOrFail($run['runId'])->detail['models']['→ OPS pricing'];
        $this->assertSame([2, 1, 1], [$step['changes'], $step['counts']['rules.updated'], $step['counts']['rules.created']]);
        $this->assertArrayNotHasKey('error', $step);
        $this->assertSame(8.75, PriceRule::where('source_system', 'odoo')->where('external_ref', (string) $rule)->value('fixed_price'));
        $new = PriceRule::where('source_system', 'odoo')->where('external_ref', (string) $tier)->firstOrFail();
        $this->assertSame(['percent', 12.5, 10.0, 'all', $opsList->id], [$new->compute, $new->percent, $new->min_qty, $new->applies_to, $new->price_list_id]);
        $this->assertSame(0, $projection->waiting());

        // the section is handed over: the mirror keeps copying, the section is left alone
        config(['odoo.project.pricing' => false]);
        $odoo->write('product.pricelist.item', $rule, ['fixed_price' => 7.0]);
        $run = $sync();
        $this->assertArrayNotHasKey('→ OPS pricing', OdooRun::findOrFail($run['runId'])->detail['models']);
        $this->assertSame([8.75, 1], [PriceRule::where('source_system', 'odoo')->where('external_ref', (string) $rule)->value('fixed_price'), $projection->waiting()]);

        // nothing was ever written to Odoo
        $this->assertSame([], $odoo->writes);
    }
}
