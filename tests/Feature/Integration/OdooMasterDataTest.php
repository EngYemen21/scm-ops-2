<?php

namespace Tests\Feature\Integration;

use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooRun;
use App\Integration\Odoo\OdooMasterData;
use App\Integration\Odoo\OdooSync;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Services\Master\ProductsService;
use App\Support\AuthUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * From the Odoo mirror to the OPS master data (OdooMasterData): what is created, what is linked and by which identity,
 * what is left for a person, who owns which value afterwards, and how an edit in Odoo reaches the OPS record.
 */
class OdooMasterDataTest extends ApiTestCase
{
    private FakeOdoo $odoo;

    private Product $opsProduct;

    private Supplier $opsSupplier;

    private Customer $opsCustomer;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['odoo_runs', 'odoo_changes', 'odoo_records', 'odoo_models'] as $table) {
            DB::table($table)->delete();
        }
        IntExternalRef::where('system', 'odoo')->delete();
        Cache::flush();
        config(['odoo' => FakeOdoo::config(['priority' => ['product.category', 'product.template', 'product.product', 'res.partner'], 'project' => ['master' => false]])]);
        $this->odoo = (new FakeOdoo)->install();

        // three OPS records Odoo also knows: a product by its code, a supplier by its VAT number, a customer only by name
        $tag = self::uid();
        $this->opsProduct = Product::create(['sku' => "PX{$tag}", 'name_ar' => 'اسم قديم في العمليات', 'name_en' => 'Old OPS name', 'storage_class' => 'frozen', 'weight_kg' => 7.5,
            'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 10, 'tracks_expiry' => false, 'active' => true]);
        $this->opsSupplier = Supplier::create(['code' => "SUP-T{$tag}", 'name_ar' => 'مورد العمليات', 'name_en' => 'OPS supplier', 'vat' => "3{$tag}0000003", 'score' => 88, 'status' => 'active', 'active' => true]);
        $this->opsCustomer = Customer::create(['code' => "CUS-T{$tag}", 'name_ar' => "مطعم مكرر {$tag}", 'name_en' => 'Twin', 'zone' => 'الرياض', 'contact' => '0500000000', 'terms' => 'نقدي / محفظة']);

        $m2o = fn (string $relation) => ['type' => 'many2one', 'relation' => $relation];
        $this->odoo->model('product.category', ['name' => 'char', 'parent_id' => $m2o('product.category')], 'Product Category');
        $this->odoo->model('product.template', ['name' => 'char', 'active' => 'boolean', 'categ_id' => $m2o('product.category'), 'detailed_type' => 'selection',
            'use_expiration_date' => 'boolean', 'expiration_time' => 'integer'], 'Product');
        $this->odoo->model('product.product', ['default_code' => 'char', 'barcode' => 'char', 'active' => 'boolean', 'product_tmpl_id' => $m2o('product.template')], 'Product Variant');
        $this->odoo->model('res.partner', ['name' => 'char', 'active' => 'boolean', 'customer_rank' => 'integer', 'supplier_rank' => 'integer', 'vat' => 'char', 'phone' => 'char',
            'city' => 'char', 'street' => 'char', 'email' => 'char', 'parent_id' => $m2o('res.partner')], 'Contact');

        $this->odoo->create('product.category', ['id' => 5, 'name' => "أجبان اختبار {$tag}"]);
        $tpl = fn (int $id, string $name, array $more = []) => $this->odoo->create('product.template', $more + ['id' => $id, 'name' => $name, 'categ_id' => [5, 'x'], 'detailed_type' => 'product']);
        $var = fn (int $id, int $tplId, array $more = []) => $this->odoo->create('product.product', ['id' => $id, 'product_tmpl_id' => [$tplId, 'x']] + $more);
        $tpl(10, 'جبنة شيدر مبشورة Shredded Cheddar (2kg*6pcs)', ['use_expiration_date' => true, 'expiration_time' => 180]);
        $var(100, 10, ['default_code' => "PN{$tag}", 'barcode' => "62810{$tag}"]);
        $tpl(11, 'خدمة توصيل', ['detailed_type' => 'service']);
        $var(101, 11, ['default_code' => 'SRV01']);
        $tpl(12, 'صنف بلا رمز');
        $var(102, 12);
        $tpl(13, 'أرز بسمتي Basmati Rice (5kg*4pcs)');
        $var(103, 13, ['default_code' => strtolower($this->opsProduct->sku)]); // the code is the same product, whatever its letter case
        $tpl(14, 'صنف مؤرشف Archived item');
        $var(104, 14, ['default_code' => "PA{$tag}", 'active' => false]);

        $this->odoo->create('res.partner', ['id' => 201, 'name' => "مطعم الواحة {$tag} Al Waha Restaurant", 'customer_rank' => 3, 'vat' => '3000-0000-0000-003', 'phone' => '0551112222', 'city' => 'جدة', 'street' => 'شارع الأمير']);
        $this->odoo->create('res.partner', ['id' => 202, 'name' => 'شركة التوريد المتحدة United Supply', 'supplier_rank' => 5, 'vat' => $this->opsSupplier->vat, 'email' => 'sales@united.example']);
        $this->odoo->create('res.partner', ['id' => 203, 'name' => "مؤسسة بلا رقم ضريبي {$tag}", 'supplier_rank' => 1, 'phone' => '0112223333']);
        $this->odoo->create('res.partner', ['id' => 204, 'name' => 'أحمد — مسؤول المشتريات', 'customer_rank' => 1, 'parent_id' => [201, 'x']]);
        $this->odoo->create('res.partner', ['id' => 205, 'name' => $this->opsCustomer->name_ar, 'customer_rank' => 1]);
        $this->odoo->create('res.partner', ['id' => 206, 'name' => "تاجر ومورد {$tag}", 'customer_rank' => 1, 'supplier_rank' => 1]);
    }

    private function sync(): array
    {
        return $this->app->make(OdooSync::class)->run(30, 'test');
    }

    private function ref(string $entity, int $odooId): ?string
    {
        return IntExternalRef::where('system', 'odoo')->where('entity', $entity)->where('external_id', (string) $odooId)->value('internal_id');
    }

    public function test_names_and_pack_weights_are_read_from_the_way_odoo_writes_them(): void
    {
        $this->assertSame(['عصير الربيع برتقال (125ml*18pcs)', 'Al Rabie Orange Juice (125ml*18pcs)'], OdooMasterData::names('[P02697] عصير الربيع برتقال Al Rabie Orange Juice (125ml*18pcs)'));
        $this->assertSame(['بيدر مخلل خيار (1kg*12pcs)', 'Pickled cucumber baidar (1kg*12pcs)'], OdooMasterData::names('بيدر مخلل خيار Pickled cucumber baidar (1kg*12pcs) '));
        $this->assertSame(['زيت زيتون 5L', 'زيت زيتون 5L'], OdooMasterData::names('زيت زيتون 5L'), 'no English name: the one name serves both');
        $this->assertSame(['Nescafe Gold نسكافيه', 'Nescafe Gold نسكافيه'], OdooMasterData::names('Nescafe Gold نسكافيه'), 'Latin first: the name is kept whole');
        $this->assertSame(['Plain English', 'Plain English'], OdooMasterData::names('Plain English'));
        // the pack text before the English name: a unit inside it is not the start of the English name
        $this->assertSame(['هيل الصقر درجة 3 (5 kg)', 'Hill The Falcon Grade 3 (5 kg)'], OdooMasterData::names('هيل الصقر درجة 3 (5 kg) Hill The Falcon Grade 3'));
        $this->assertSame(['ماء نوفا 330 ml x 40', 'Nova Water'], OdooMasterData::names('ماء نوفا 330 ml x 40 Nova Water'));
        $this->assertSame(6.48, OdooMasterData::packWeightKg('COFFEE JOY BISCUIT (45g*12ps*12box)'), 'every count multiplies');
        $this->assertSame([12.0, 2.25, 7.92, 5.0, 0.5, null], [OdooMasterData::packWeightKg('(1kg*12pcs)'), OdooMasterData::packWeightKg('125ml*18pcs'),
            OdooMasterData::packWeightKg('24 x 330 ml'), OdooMasterData::packWeightKg('Olive oil 5 L'), OdooMasterData::packWeightKg('500 gm'), OdooMasterData::packWeightKg('بلا قياس')]);
    }

    public function test_the_plan_changes_nothing_and_apply_creates_links_and_leaves_doubt_to_a_person(): void
    {
        $this->sync();
        $master = $this->app->make(OdooMasterData::class);
        $before = [Product::count(), Customer::count(), Supplier::count(), IntExternalRef::where('system', 'odoo')->count()];

        $plan = $master->plan();
        $this->assertTrue($plan['dryRun']);
        $this->assertSame(['created' => 1, 'skippedService' => 1, 'skippedNoCode' => 1, 'linked' => 1, 'skippedArchived' => 1], $plan['counts']['products']);
        $this->assertSame(['created' => 2, 'needsReview' => 1], $plan['counts']['customers']);
        $this->assertSame(['linked' => 1, 'created' => 2], $plan['counts']['suppliers']);
        $this->assertSame(['created' => 1], $plan['counts']['categories']);
        $this->assertStringContainsString($this->opsCustomer->code, $plan['notes']['customers.needsReview'][0]);
        $this->assertSame($before, [Product::count(), Customer::count(), Supplier::count(), IntExternalRef::where('system', 'odoo')->count()], 'a plan writes nothing');

        $applied = $master->apply();
        $this->assertFalse($applied['dryRun']);
        $this->assertSame($plan['counts']['products'], $applied['counts']['products']);

        // ── a new product: Odoo's identity, OPS estimates for what Odoo does not hold
        $p = Product::findOrFail($this->ref('product', 100));
        $this->assertSame(['جبنة شيدر مبشورة (2kg*6pcs)', 'Shredded Cheddar (2kg*6pcs)', true, 180, 12.0, 'chilled', true],
            [$p->name_ar, $p->name_en, (bool) $p->tracks_expiry, $p->shelf_life_days, (float) $p->weight_kg, $p->storage_class, (bool) $p->active]);
        $this->assertGreaterThan(0, $p->length_cm);
        $this->assertSame($p->id, ProductBarcode::where('barcode', $this->odoo->models['product.product']['rows'][100]['barcode'])->value('product_id'));
        $category = ProductCategory::findOrFail($p->category_id);
        $this->assertSame(['od-5', $this->odoo->models['product.category']['rows'][5]['name']], [$category->code, $category->name_ar]);
        $audit = AuditLog::where('action', 'PRODUCT.CREATE')->where('entity_id', $p->id)->firstOrFail();
        $this->assertSame('svc.odoo', $audit->username);
        $this->assertStringContainsString('estimates', json_encode($audit->new_value));

        // ── an OPS product with the same code is the same product: linked, Odoo's name, OPS's measurements untouched
        $this->assertSame($this->opsProduct->id, $this->ref('product', 103));
        $linked = $this->opsProduct->refresh();
        $this->assertSame(['أرز بسمتي (5kg*4pcs)', 'Basmati Rice (5kg*4pcs)', 'frozen', 7.5, $category->id], [$linked->name_ar, $linked->name_en, $linked->storage_class, (float) $linked->weight_kg, $linked->category_id]);

        // ── not products of the warehouse: a service, a product with no code, an archived one
        $this->assertSame([null, null, null], [$this->ref('product', 101), $this->ref('product', 102), $this->ref('product', 104)]);

        // ── customers: created as Odoo's (OPS cannot rename them), the contact of a company is not a customer
        $c = Customer::findOrFail($this->ref('customer', 201));
        $this->assertSame(['odoo', '300000000000003', 'جدة', '0551112222', 'Al Waha Restaurant'], [$c->source_system, $c->vat_no, $c->city, $c->contact, $c->name_en]);
        $this->assertMatchesRegularExpression('/^CUS-/', $c->code);
        $refused = $this->expectRejected($this->patchAs('admin', "/api/customers/{$c->code}", ['nameAr' => 'تعديل محلي']), 'CUSTOMER_OWNED_BY_SOURCE', [422]);
        $this->assertStringContainsString('أودو', $refused['message']);
        $this->expectOk($this->patchAs('admin', "/api/customers/{$c->code}", ['zone' => 'جدة — الشمال'])); // what is operational stays OPS's
        $this->assertNull($this->ref('customer', 204));
        // the same name as an unlinked OPS customer: nobody guessed, a person decides
        $this->assertNull($this->ref('customer', 205));
        $this->assertSame(1, Customer::where('name_ar', $this->opsCustomer->name_ar)->count());

        // ── suppliers: the VAT number is the identity; without one a supplier is new; a partner can be both
        $this->assertSame($this->opsSupplier->id, $this->ref('supplier', 202));
        $s = $this->opsSupplier->refresh();
        $this->assertSame(['شركة التوريد المتحدة', 'United Supply', 88.0], [$s->name_ar, $s->name_en, (float) $s->score], 'the score is OPS\'s own');
        $new = Supplier::findOrFail($this->ref('supplier', 203));
        $this->assertSame([null, '0112223333', 'active', true], [$new->vat, $new->contact, $new->status, (bool) $new->is_new]);
        $this->assertNotNull($this->ref('customer', 206));
        $this->assertNotNull($this->ref('supplier', 206));

        // ── again: nothing new, nothing changed
        $again = $master->apply();
        $this->assertSame(['unchanged' => 2, 'skippedService' => 1, 'skippedNoCode' => 1, 'skippedArchived' => 1], $again['counts']['products']);
        $this->assertSame(['unchanged' => 2, 'needsReview' => 1], $again['counts']['customers']);
        $this->assertSame(['unchanged' => 3], $again['counts']['suppliers']);
        $this->assertSame([], $this->odoo->writes);
    }

    public function test_an_edit_in_odoo_reaches_the_ops_record_only_when_the_projection_is_on(): void
    {
        $this->sync();
        $this->app->make(OdooMasterData::class)->apply();
        $product = Product::findOrFail($this->ref('product', 100));
        $supplier = Supplier::findOrFail($this->ref('supplier', 203));
        $customer = Customer::findOrFail($this->ref('customer', 201));
        // OPS people measured the product meanwhile
        $product->update(['weight_kg' => 13.4, 'storage_class' => 'frozen']);

        // Odoo: the product is renamed and stops expiring, a supplier is archived, a customer moves, a product is new
        $this->odoo->write('product.template', 10, ['name' => 'جبنة شيدر فاخرة Premium Cheddar (2kg*6pcs)', 'use_expiration_date' => false]);
        $this->odoo->write('res.partner', 203, ['active' => false]);
        $this->odoo->write('res.partner', 201, ['city' => 'مكة']);
        $this->odoo->create('product.template', ['id' => 20, 'name' => 'ماء معدني Mineral Water (330ml*24pcs)', 'categ_id' => [5, 'x'], 'detailed_type' => 'product']);
        $this->odoo->create('product.product', ['id' => 120, 'product_tmpl_id' => [20, 'x'], 'default_code' => 'PW'.self::uid()]);

        // off: the mirror knows, OPS master data waits for a person
        $this->sync();
        $this->assertSame('جبنة شيدر مبشورة (2kg*6pcs)', $product->refresh()->name_ar);
        $this->assertGreaterThan(0, OdooChange::whereIn('model', OdooMasterData::MODELS)->whereNull('processed_at')->count());

        // on: the next run applies what the feed reported
        config(['odoo.project.master' => true]);
        $run = $this->sync();
        $this->assertSame(0, $run['errors']);
        $this->assertSame(['جبنة شيدر فاخرة (2kg*6pcs)', 'Premium Cheddar (2kg*6pcs)', false, 13.4, 'frozen'],
            [$product->refresh()->name_ar, $product->name_en, (bool) $product->tracks_expiry, (float) $product->weight_kg, $product->storage_class], 'Odoo\'s values changed, OPS\'s stayed');
        $this->assertSame([false, 'inactive'], [(bool) $supplier->refresh()->active, $supplier->status]);
        $this->assertSame('مكة', $customer->refresh()->city);
        $water = Product::findOrFail($this->ref('product', 120));
        $this->assertSame([7.92, 'ماء معدني (330ml*24pcs)'], [(float) $water->weight_kg, $water->name_ar]);
        $this->assertSame(0, OdooChange::whereIn('model', OdooMasterData::MODELS)->whereNull('processed_at')->count());
        $this->assertSame('svc.odoo', AuditLog::where('action', 'SUPPLIER.UPDATE')->where('entity_id', $supplier->id)->orderByDesc('at')->value('username'));

        // an archived product is archived in OPS too; a quiet run changes nothing
        $this->odoo->write('product.product', 100, ['active' => false]);
        $this->sync();
        $this->assertFalse((bool) $product->refresh()->active);
        $edits = AuditLog::where('entity_id', $product->id)->count();
        $this->sync();
        $this->assertSame($edits, AuditLog::where('entity_id', $product->id)->count());
        $this->assertSame([], $this->odoo->writes);
    }

    public function test_who_may_see_the_plan_and_who_may_apply_it(): void
    {
        $this->expectRejected($this->getAs('worker', '/api/integration/odoo/master-data'), 'FORBIDDEN', [403]);
        $notReady = $this->expectOk($this->getAs('gm', '/api/integration/odoo/master-data?plan=1'));
        $this->assertSame([false, false, null], [$notReady['ready'], $notReady['continuous'], $notReady['counts']]);
        $this->expectRejected($this->postAs('admin', '/api/integration/odoo/master-data/apply'), 'ODOO_COPY_INCOMPLETE', [422]);

        $this->sync();
        // what the screen shows at once is cheap: what is linked and what waits; the plan is worked out when asked for
        $state = $this->expectOk($this->getAs('gm', '/api/integration/odoo/master-data'));
        $this->assertSame([true, null, null, ['products' => 0, 'customers' => 0, 'suppliers' => 0, 'categories' => 0], OdooMasterData::WEB_APPLY_LIMIT],
            [$state['ready'], $state['counts'], $state['work'], $state['linked'], $state['applyLimit']]);
        $plan = $this->expectOk($this->getAs('gm', '/api/integration/odoo/master-data?plan=1'));
        // what an apply writes: 1 + 1 products, 2 customers, 2 + 1 suppliers — and the category, unless OPS already has it
        $work = 7 + ($plan['counts']['categories']['created'] ?? 0);
        $this->assertSame([true, 1, 2, $work], [$plan['ready'], $plan['counts']['products']['created'], $plan['counts']['suppliers']['created'], $plan['work']]);
        $this->assertSame(0, IntExternalRef::where('system', 'odoo')->count());
        $this->expectRejected($this->postAs('gm', '/api/integration/odoo/master-data/apply'), 'FORBIDDEN', [403]);
        $done = $this->expectOk($this->postAs('admin', '/api/integration/odoo/master-data/apply'));
        $this->assertSame([1, $work], [$done['counts']['products']['created'], $done['work']]);
        $this->assertNotNull($this->ref('product', 100));
        $after = $this->expectOk($this->getAs('gm', '/api/integration/odoo/master-data?plan=1'));
        $this->assertSame([['products' => 2, 'customers' => 2, 'suppliers' => 3, 'categories' => 1], 0, 0], [$after['linked'], $after['work'], $after['waiting']]);
    }

    public function test_one_code_on_two_odoo_products_and_a_record_that_fails_do_not_stop_the_rest(): void
    {
        // a second Odoo product carries the code of the first: the first is the product, the second is reported
        $this->odoo->create('product.template', ['id' => 30, 'name' => 'نسخة مكررة Duplicate (1kg*6pcs)', 'categ_id' => [5, 'x'], 'detailed_type' => 'product']);
        $this->odoo->create('product.product', ['id' => 130, 'product_tmpl_id' => [30, 'x'], 'default_code' => strtolower($this->odoo->models['product.product']['rows'][100]['default_code'])]);
        // …and a third carries a sentence where its code should be: that is not a product code on either database engine
        $this->odoo->create('product.product', ['id' => 131, 'product_tmpl_id' => [30, 'x'], 'default_code' => str_repeat('X', 300)]);
        $this->sync();
        $master = $this->app->make(OdooMasterData::class);
        $plan = $master->plan();
        $this->assertSame([1, 1, 1], [$plan['counts']['products']['created'], $plan['counts']['products']['duplicateCode'], $plan['counts']['products']['badCode']], 'the plan says what the apply will do');
        $applied = $master->apply();
        $this->assertSame($plan['counts']['products'], $applied['counts']['products']);
        $this->assertSame([null, null], [$this->ref('product', 130), $this->ref('product', 131)]);
        $this->assertSame(1, Product::where('sku', mb_strtoupper($this->odoo->models['product.product']['rows'][100]['default_code']))->count());
        $this->assertSame(0, Product::whereRaw('length(sku) > 64')->count());

        // the feed: a record that cannot be applied (here the product service fails for it) is reported once and
        // marked, and the change after it still arrives
        config(['odoo.project.master' => true]);
        $this->app->instance(ProductsService::class, new class extends ProductsService
        {
            public function __construct() {}

            public function create(AuthUser $actor, array $dto): array
            {
                throw new \RuntimeException('the product could not be stored');
            }
        });
        $this->odoo->create('product.template', ['id' => 40, 'name' => 'صنف يتعذر حفظه Unstorable item', 'categ_id' => [5, 'x'], 'detailed_type' => 'product']);
        $this->odoo->create('product.product', ['id' => 140, 'product_tmpl_id' => [40, 'x'], 'default_code' => 'PF'.self::uid()]);
        $this->odoo->write('res.partner', 203, ['name' => 'مؤسسة باسم جديد New Name Est']);
        $products = Product::count();
        $run = $this->sync();
        $this->assertSame(1, $run['errors']);
        $this->assertSame([$products, null], [Product::count(), $this->ref('product', 140)]);
        $this->assertSame('مؤسسة باسم جديد', Supplier::findOrFail($this->ref('supplier', 203))->name_ar);
        $this->assertSame(0, OdooChange::whereIn('model', OdooMasterData::MODELS)->whereNull('processed_at')->count(), 'nothing is left to block the next run');
        $step = OdooRun::findOrFail($run['runId'])->detail['models'][OdooSync::MASTER_STEP];
        $this->assertSame([3, 1, 1], [$step['changes'], $step['failed'], $step['counts']['products']['failed']], 'the template and its variant are one failure, not two');
        $this->assertArrayNotHasKey('created', $step['counts']['products']);
        $this->assertStringContainsString('product.', $step['notes']['products.failed'][0]);
        $this->assertSame(0, $this->sync()['errors'], 'the next run is quiet');
        $this->assertSame([], $this->odoo->writes);
    }
}
