<?php

namespace Tests\Feature\ProductRequests;

use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooFile;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Services\ExternalRefs;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StatusHistory;
use App\Models\User;
use App\ProductRequests\Models\ProductRequest;
use App\ProductRequests\Models\ProductRequestEvent;
use App\ProductRequests\Models\ProductRequestPicture;
use App\ProductRequests\OdooProductRequestsProjection;
use App\Support\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\ApiTestCase;

/**
 * From the copy of Odoo's two new-product request models to the OPS section (OdooProductRequestsProjection). The copy
 * is built here by hand — rows of `odoo_models` / `odoo_records` shaped like the ones the mirror writes, taken from the
 * real shapes of `b2b.new.product` and `hub.product.request` — and Odoo itself is never called.
 * What is proven: the plan writes nothing; the apply makes every request once, with its state, people, pictures and
 * the product it became; who owns what afterwards; how a change in Odoo reaches the request; and the hand-over.
 */
class OdooProductRequestsProjectionTest extends ApiTestCase
{
    use ProductRequestFixtures;

    private const STAFF = 'b2b.new.product';

    private const PORTAL = 'hub.product.request';

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01 a picture kept by the backup";

    private string $tag;

    private User $requester;

    private ProductCategory $category;

    private Customer $customer;

    /** OPS products that Odoo's products already are (what the master-data projection linked). @var array<string, array> */
    private array $ops = [];

    protected function setUp(): void
    {
        parent::setUp();
        // what an earlier story left: the copy, and the requests it projected (Odoo's only)
        foreach (['odoo_changes', 'odoo_files', 'odoo_records', 'odoo_models'] as $table) {
            DB::table($table)->delete();
        }
        ProductRequest::where('source_system', 'odoo')->delete(); // their story and pictures go with them
        IntExternalRef::where('system', 'odoo')->delete();
        Storage::fake('local');
        $this->followOdoo(true);
        $this->tag = $tag = self::tag();

        // ── OPS: a user who IS an Odoo user (same e-mail as the login there), a category, a customer and products
        $this->requester = User::create(['username' => "buyer{$tag}", 'email' => "shawa{$tag}@b2b.example", 'password_hash' => Hash::make(Str::random(20)), 'name_ar' => "مشتريات {$tag}", 'name_en' => "Buyer {$tag}",
            'initials' => 'BY', 'active' => true, 'must_change_password' => false]);
        $this->category = ProductCategory::create(['code' => "od-13-{$tag}", 'name_ar' => "حلويات ومخبوزات {$tag}", 'name_en' => 'Sweets and bakery', 'active' => true]);
        $this->customer = Customer::create(['code' => "CUS-P{$tag}", 'name_ar' => "شركة الورود الفاخرة {$tag}", 'name_en' => 'Al Wurood', 'zone' => 'الرياض', 'contact' => '0551112222', 'terms' => 'نقدي / محفظة']);
        $refs = $this->app->make(ExternalRefs::class);
        $refs->link('odoo', 'category', '13', $this->category->id, $this->category->code);
        $refs->link('odoo', 'customer', '765', $this->customer->id, $this->customer->code);
        foreach (['juice' => 5001, 'plate' => 5003, 'honey' => 5010, 'rice' => 5011] as $key => $variant) {
            $this->ops[$key] = $this->catalogueProduct();
            $refs->link('odoo', 'product', (string) $variant, $this->ops[$key]['id'], $this->ops[$key]['sku']);
        }

        foreach ([self::STAFF, self::PORTAL] as $model) {
            OdooModel::create(['model' => $model, 'status' => 'active', 'full_state' => 'done', 'has_active' => false]);
        }
        // ── Odoo's people (other models of the copy the projection only looks things up in)
        $this->rec('res.users', 27, ['login' => strtoupper("shawa{$tag}@b2b.example"), 'partner_id' => [697, 'مدير الإمداد']]);
        $this->rec('res.users', 6, ['login' => "rep.first{$tag}@b2b.example", 'partner_id' => [698, 'مندوب أول']]);
        $this->rec('res.users', 51, ['login' => "portal{$tag}@alwurood.example", 'partner_id' => [765, 'شركة الورود الفاخرة']]);
        $this->rec('res.users', 18, ['login' => "bdr{$tag}", 'partner_id' => [699, 'bdr']]);

        // ── Odoo's products: a template and its variant; the variant carries the link to OPS
        $product = function (int $template, int $variant, string $name, string $created, int $by = 6) {
            $this->rec('product.template', $template, ['name' => $name, 'create_date' => $created, 'create_uid' => [$by, 'x'], 'default_code' => "P0{$template}"]);
            $this->rec('product.product', $variant, ['product_tmpl_id' => [$template, $name], 'default_code' => "P0{$template}"]);
        };
        $product(4001, 5001, $this->odooName('juice'), '2026-03-02 10:00:00');
        $product(3990, 4990, $this->odooName('ketchup'), '2025-01-10 08:00:00');   // an older product of the same name: not the one the request made
        $product(4002, 5002, $this->odooName('ketchup'), '2026-03-02 10:00:03');   // made by the approval — and not an OPS product yet
        $product(4004, 5004, $this->odooName('sugar'), '2026-03-02 10:00:00');     // made twice in the same second,
        $product(4005, 5005, $this->odooName('sugar'), '2026-03-02 10:00:00');     // neither is an OPS product: nobody can tell
        $product(4011, 5011, $this->odooName('rice'), '2026-03-02 10:00:00');      // made twice as well —
        $product(4012, 5012, $this->odooName('rice'), '2026-03-02 10:00:00');      // but only one of the two is an OPS product
        $product(4003, 5003, "صحن وسط {$tag}", '2026-09-02 08:00:00');
        $product(4010, 5010, 'عسل الشفاء الطبيعي - Al Shifa natural honey (1ltr*6ps)', '2024-07-20 08:00:00');

        // ── the staff flow: one request in every state Odoo has
        $this->staff(1, 'approved', 'juice', ['tracking' => 'lot', 'use_expiration_date' => true, 'product_barcode' => " 6281000{$tag} ",
            'supply_chain_manager_id' => [27, 'مدير الإمداد'], 'sales_manager_id' => [6, 'مندوب أول'], 'system_admin_id' => [6, 'مندوب أول']]);
        $this->staff(2, 'approved', 'ketchup', ['supply_chain_manager_id' => [27, 'مدير الإمداد'], 'sales_manager_id' => [6, 'مندوب أول'], 'system_admin_id' => [6, 'مندوب أول']]);
        $this->staff(3, 'approved', 'salt', ['system_admin_id' => [6, 'مندوب أول']]);
        $this->staff(4, 'approved', 'sugar', ['system_admin_id' => [6, 'مندوب أول']]);
        $this->staff(5, 'rejected', 'vinegar', ['supply_chain_manager_id' => [27, 'مدير الإمداد']]);
        $this->staff(6, 'sales_approval', 'tahini', ['supply_chain_manager_id' => [27, 'مدير الإمداد'], 'write_uid' => [27, 'مدير الإمداد']]);
        $this->staff(7, 'supply_chain_approval', 'oats', ['write_uid' => [27, 'مدير الإمداد'], 'write_date' => '2026-03-01 09:20:00']);
        $this->staff(8, 'draft', 'cocoa', ['category_id' => [48, 'cafe 24'], 'write_uid' => [27, 'مدير الإمداد'], 'write_date' => '2026-03-01 09:15:00', 'sale_price' => 0, 'purchase_price' => 0]);
        $this->staff(9, 'system_admin_approval', 'yeast', ['supply_chain_manager_id' => [27, 'مدير الإمداد'], 'sales_manager_id' => [6, 'مندوب أول']]);
        $this->staff(10, 'approved', 'gone', []);
        OdooRecord::where('model', self::STAFF)->where('odoo_id', 10)->update(['deleted_at' => now()]); // deleted in Odoo before OPS ever had it
        $this->staff(11, 'approved', 'rice', ['system_admin_id' => [6, 'مندوب أول']]);

        // ── what customers asked for through the portal
        $this->portal(1, 'cancelled', ['request_type' => 'existing', 'product_id' => [5010, '[P00737] عسل الشفاء الطبيعي - Al Shifa natural honey (1ltr*6ps)'], 'company_partner_id' => [643, 'Croi Bakehouse'],
            'write_uid' => [18, 'bdr'], 'write_date' => '2026-09-30 12:05:40']);
        $this->portal(2, 'pending', ['product_name' => "صابون موبي {$tag}", 'customer_note' => 'نحتاجه شهريًا لفروعنا الثلاثة', 'suggested_price' => 12.5, 'product_image_filename' => 'soap.jpg', 'category_hint' => 'منظفات', 'uom_hint' => 'كرتون']);
        $this->portal(3, 'approved', ['product_name' => "صحن وسط {$tag}", 'created_product_id' => [5003, "[P04003] صحن وسط {$tag}"], 'approved_by_id' => [18, 'bdr'], 'approval_date' => '2026-09-02 08:00:00',
            'approved_price' => 7.25, 'suggested_price' => 6, 'write_uid' => [18, 'bdr'], 'write_date' => '2026-09-02 08:00:00']);
        $this->portal(4, 'rejected', ['product_name' => "صابون أرضيات بودرة {$tag}", 'rejection_reason' => 'غير متوفر لدى الموردين', 'approved_by_id' => [18, 'bdr'], 'approval_date' => '2026-09-03 09:30:00',
            'write_uid' => [18, 'bdr'], 'write_date' => '2026-09-03 09:30:00']);
        $this->portal(5, 'pending', ['request_type' => 'existing', 'product_id' => [5010, '[P00737] عسل الشفاء الطبيعي - Al Shifa natural honey (1ltr*6ps)']]); // a catalogue product he wants on his price list

        // ── the files Odoo lists: the picture of a request fills its `product_image` field
        $this->attachment(9001, self::STAFF, 1, 'image/jpeg', strlen(self::JPEG));
        $this->attachment(9002, self::STAFF, 6, 'image/png', 175142);
        $this->attachment(9003, self::PORTAL, 2, 'image/jpeg', 2708);
        $this->attachment(9004, self::STAFF, 1, 'application/pdf', 90000, ['name' => 'عرض المورد.pdf', 'res_field' => false]); // a document, not a picture
        $this->attachment(9005, 'sale.order', 1, 'image/jpeg', 500, ['res_field' => false]);                                 // somebody else's
        // … and the one whose content the backup already keeps
        Storage::disk('local')->put('odoo-files/001/9001', self::JPEG);
        OdooFile::create(['attachment_id' => 9001, 'res_model' => self::STAFF, 'res_id' => 1, 'name' => 'product_image', 'mimetype' => 'image/jpeg', 'size' => strlen(self::JPEG),
            'checksum' => sha1(self::JPEG), 'status' => 'stored', 'disk' => 'local', 'path' => 'odoo-files/001/9001', 'stored_at' => now()]);
    }

    // ───────────── building the copy ─────────────

    /** Odoo holds ONE name: "Arabic English (pack)". */
    private function odooName(string $key): string
    {
        return [
            'juice' => "عصير الربيع برتقال {$this->tag} Al Rabie Orange Juice {$this->tag} (125ml*18pcs)",
            'ketchup' => "كاتشب هاينز {$this->tag}  Heinz Ketchup {$this->tag} (340gm*12pcs)",
            'salt' => "ملح خشن {$this->tag}",
            'sugar' => "سكر ناعم {$this->tag} Fine Sugar {$this->tag}",
            'rice' => "أرز بسمتي {$this->tag} Basmati Rice {$this->tag} (5kg*4pcs)",
            'vinegar' => "خل أبيض {$this->tag}",
            'tahini' => "طحينة سائلة {$this->tag} Liquid Tahini {$this->tag} (1kg*12pcs)",
            'oats' => "شوفان {$this->tag} Oats {$this->tag}",
            'cocoa' => "كاكاو بودرة {$this->tag}",
            'yeast' => "خميرة فورية {$this->tag}",
            'gone' => "محذوف {$this->tag}",
        ][$key];
    }

    /** One record of the copy, as the mirror would hold it. */
    private function rec(string $model, int $id, array $data): void
    {
        $data += ['write_date' => '2026-03-02 10:00:00'];
        OdooRecord::updateOrCreate(['model' => $model, 'odoo_id' => $id], ['display_name' => is_string($data['name'] ?? null) ? $data['name'] : null, 'write_date' => $data['write_date'], 'active' => null,
            'data' => $data, 'hash' => sha1(json_encode($data)), 'first_seen_at' => now(), 'changed_at' => now(), 'deleted_at' => null]);
    }

    private function staff(int $id, string $state, string $nameKey, array $more = []): void
    {
        $this->rec(self::STAFF, $id, $more + [
            'name' => sprintf('NPR/%05d', $id), 'state' => $state, 'product_name' => $this->odooName($nameKey), 'product_type' => 'product', 'tracking' => 'none', 'use_expiration_date' => false,
            'sale_price' => 20.5, 'purchase_price' => 17.0833, 'category_id' => [13, 'الحلويات والمخبوزات والكيك'], 'product_barcode' => false, 'description' => false,
            'create_uid' => [27, 'مدير الإمداد'], 'write_uid' => [6, 'مندوب أول'], 'create_date' => '2026-03-01 09:15:00', 'write_date' => '2026-03-02 10:00:00',
            'supply_chain_manager_id' => false, 'sales_manager_id' => false, 'system_admin_id' => false,
        ]);
    }

    private function portal(int $id, string $state, array $more = []): void
    {
        $this->rec(self::PORTAL, $id, $more + [
            'name' => sprintf('PRQ/2026/%05d', $id), 'state' => $state, 'request_type' => 'new', 'product_name' => false, 'product_id' => false, 'created_product_id' => false, 'product_tmpl_id' => false,
            'company_partner_id' => [765, 'شركة الورود الفاخرة للتجارة 1'], 'company_id' => [1, 'شركة سعر للتجارة'], 'currency_id' => [151, 'SAR'], 'branch_id' => false,
            'requested_by_id' => [51, 'شركة الورود الفاخرة للتجارة 1'], 'create_uid' => [51, 'شركة الورود الفاخرة للتجارة 1'], 'write_uid' => [51, 'شركة الورود الفاخرة للتجارة 1'],
            'request_date' => '2026-08-31 22:54:02', 'create_date' => '2026-08-31 22:54:02', 'write_date' => '2026-08-31 22:54:02', 'approved_price' => 0, 'suggested_price' => 0,
            'customer_note' => false, 'note' => false, 'rejection_reason' => false, 'approval_date' => false, 'approved_by_id' => false, 'category_id' => false, 'uom_id' => false,
        ]);
    }

    private function attachment(int $id, string $model, int $resId, string $mime, int $size, array $more = []): void
    {
        $this->rec('ir.attachment', $id, $more + ['name' => 'product_image', 'type' => 'binary', 'res_model' => $model, 'res_id' => $resId, 'res_field' => 'product_image', 'mimetype' => $mime, 'file_size' => $size,
            'create_uid' => [6, 'مندوب أول'], 'create_date' => '2026-03-01 09:16:00']);
    }

    /** What changes in Odoo: the record of the copy is rewritten and the feed says so. */
    private function odooWrites(string $model, int $id, array $values): void
    {
        $current = OdooRecord::where('model', $model)->where('odoo_id', $id)->first();
        $model === self::STAFF && ! $current ? $this->staff($id, $values['state'] ?? 'draft', 'cocoa', $values) : $this->rec($model, $id, $values + (array) ($current?->data ?? []));
        OdooChange::create(['model' => $model, 'odoo_id' => $id, 'kind' => $current ? 'updated' : 'created', 'seen_at' => now()]);
    }

    private function odooDeletes(string $model, int $id): void
    {
        OdooRecord::where('model', $model)->where('odoo_id', $id)->update(['deleted_at' => now()]);
        OdooChange::create(['model' => $model, 'odoo_id' => $id, 'kind' => 'deleted', 'seen_at' => now()]);
    }

    private function projection(): OdooProductRequestsProjection
    {
        return $this->app->make(OdooProductRequestsProjection::class);
    }

    private function odoo(string $model, int $id): ProductRequest
    {
        return ProductRequest::where('source_system', 'odoo')->where('source_model', $model)->where('external_ref', (string) $id)->firstOrFail();
    }

    /** What a whole pass over this copy finds, before anything of it is in OPS. */
    private static function firstPass(): array
    {
        return [
            'request.created' => 15, 'request.skippedDeleted' => 1,
            'link.restored' => 3, 'link.existing' => 2, 'link.productNotInOps' => 1, 'link.productNotFound' => 1, 'link.ambiguous' => 1,
            'people.matched' => 1, 'people.keptByName' => 3, 'category.linked' => 1, 'category.keptByName' => 1, 'customer.linked' => 1, 'customer.keptByName' => 1,
            'picture.created' => 3, 'picture.contentKept' => 1, 'picture.contentNotArchivedYet' => 2,
        ];
    }

    // ───────────── the stories ─────────────

    public function test_the_plan_counts_and_writes_nothing(): void
    {
        $projection = $this->projection();
        $this->assertSame(['preq', [self::STAFF, self::PORTAL], true, true], [$projection->key(), $projection->models(), $projection->ready(), OdooProjection::owns('preq')]);
        $before = [ProductRequest::count(), ProductRequestEvent::count(), ProductRequestPicture::count(), IntExternalRef::count(), AuditLog::count(), DB::table('odoo_records')->count()];

        $plan = $projection->plan();
        $this->assertTrue($plan['dryRun']);
        $this->assertEquals(self::firstPass(), $plan['counts']);
        $this->assertArrayNotHasKey('failed', $plan);
        $this->assertSame($before, [ProductRequest::count(), ProductRequestEvent::count(), ProductRequestPicture::count(), IntExternalRef::count(), AuditLog::count(), DB::table('odoo_records')->count()], 'a plan writes nothing');
        // what a person has to look at is named
        $this->assertStringContainsString('NPR/00003', $plan['notes']['link.productNotFound'][0]);
        $this->assertStringContainsString('NPR/00004', $plan['notes']['link.ambiguous'][0]);

        // the copy is not complete: nothing is guessed from half a picture
        OdooModel::where('model', self::PORTAL)->update(['full_state' => 'running']);
        try {
            $projection->plan();
            $this->fail('expected ODOO_COPY_INCOMPLETE');
        } catch (AppError $e) {
            $this->assertSame('ODOO_COPY_INCOMPLETE', $e->errorCode);
        }
    }

    public function test_the_apply_makes_every_request_once_with_its_state_people_pictures_and_product(): void
    {
        $tag = $this->tag;
        $notifications = Notification::count();
        $applied = $this->projection()->apply();
        $this->assertFalse($applied['dryRun']);
        $this->assertEquals(self::firstPass(), $applied['counts']);
        $this->assertSame([15, 3], [ProductRequest::where('source_system', 'odoo')->count(), ProductRequestPicture::where('source_system', 'odoo')->count()]);
        $this->assertSame($notifications, Notification::count(), 'nobody is notified by a first load: it is history');

        // ── a request the staff raised and Odoo approved in three steps
        $r = $this->show('NPR-00001');
        $this->assertSame(['NPR-00001', 'NPR/00001', 'new', 'approved', 'odoo', self::STAFF, '1', true, false], [$r['number'], $r['sourceNumber'], $r['requestType'], $r['status'], $r['sourceSystem'], $r['sourceModel'], $r['externalRef'], $r['owned'], $r['mine']]);
        $this->assertSame(["عصير الربيع برتقال {$tag} (125ml*18pcs)", "Al Rabie Orange Juice {$tag} (125ml*18pcs)", '125ml*18pcs'], [$r['nameAr'], $r['nameEn'], $r['packText']], 'Odoo\'s one name is split the way product names are');
        $this->assertSame([$this->category->id, 'الحلويات والمخبوزات والكيك', '17.0833', '20.50', 'SAR', "6281000{$tag}", true], [$r['category']['id'], $r['categoryName'], $r['costPrice'], $r['salePrice'], $r['currency'], $r['barcode'], $r['tracksExpiry']]);
        $this->assertSame([$this->requester->id, "buyer{$tag}", false], [$r['requestedBy']['id'], $r['requestedBy']['username'], $r['requestedBy']['external']], 'the Odoo user whose login is this OPS user\'s e-mail');
        $this->assertSame([null, 'مندوب أول', true, $this->requester->id], [$r['decidedBy']['id'], $r['decidedBy']['nameAr'], $r['decidedBy']['external'], $r['reviewer']['id']], 'whoever has no OPS user is kept by name');
        $this->assertSame(['2026-03-01T09:15:00.000000Z', '2026-03-01T09:15:00.000000Z', '2026-03-02T10:00:00.000000Z', null], [$r['createdAt'], $r['submittedAt'], $r['decidedAt'], $r['ageDays']], 'the dates are Odoo\'s');
        $this->assertSame([$this->ops['juice']['id'], $this->ops['juice']['sku'], 'restored', null], [$r['product']['id'], $r['productSku'], $r['productLink'], $r['sourceProductRef']], 'the product Odoo made in the transaction of the approval, which is an OPS product');
        $this->assertSame(['key' => 'approved', 'ar' => 'معتمد', 'en' => 'Approved'], $r['sourceState']);
        $this->assertEquals(['supplyChainManager' => 'مدير الإمداد', 'salesManager' => 'مندوب أول', 'systemAdmin' => 'مندوب أول'], $r['sourceMeta']['approvals'], 'who approved each of Odoo\'s three steps');
        $this->assertSame(['lot', ['kind' => 'new_product_request', 'odooId' => 1]], [$r['sourceMeta']['tracking'], $r['archive']]);
        $this->assertSame(['created', 'submitted', 'approved'], self::story($r));
        $this->assertSame(['odoo', 'submitted', 'approved', 'مندوب أول', '2026-03-02T10:00:00.000000Z'], [$r['events'][0]['origin'], $r['events'][0]['fromStatus'], $r['events'][0]['toStatus'], $r['events'][0]['by'], $r['events'][0]['at']]);
        // its picture: listed from Odoo's attachments (the PDF is not a picture), served from the backup, never copied
        $this->assertSame([['product_image.jpg'], ['image/jpeg'], ['odoo'], [true], 1], [array_column($r['pictures'], 'fileName'), array_column($r['pictures'], 'mime'), array_column($r['pictures'], 'origin'), array_column($r['pictures'], 'available'), $r['pictureCount']]);
        $picture = $this->get("/api{$r['pictures'][0]['url']}", ['Authorization' => 'Bearer '.$this->token('sales')]);
        $picture->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(self::JPEG, $picture->streamedContent());
        $this->assertNull(ProductRequestPicture::where('external_ref', '9001')->value('storage_key'), 'linked through odoo_files: no second copy of the file');
        $this->assertSame('svc.odoo', AuditLog::where('action', 'PREQ.IMPORT')->where('entity_id', $r['id'])->value('username'));

        // ── every state of the staff flow, with Odoo's own word beside it
        $states = fn (string $number) => [($x = $this->show($number))['status'], $x['sourceState']['key'], self::story($x)];
        $this->assertSame(['rejected', 'rejected', ['created', 'submitted', 'rejected']], $states('NPR-00005'));
        $this->assertSame(['in_review', 'sales_approval', ['created', 'submitted', 'review_started']], $states('NPR-00006'));
        $this->assertSame(['submitted', 'supply_chain_approval', ['created', 'submitted']], $states('NPR-00007'));
        $this->assertSame(['draft', 'draft', ['created']], $states('NPR-00008'));
        $this->assertSame(['in_review', 'system_admin_approval', ['created', 'submitted', 'review_started']], $states('NPR-00009'));
        $rejected = $this->show('NPR-00005');
        $this->assertSame(['مندوب أول', null, null], [$rejected['decidedBy']['nameAr'], $rejected['rejectReason'], $rejected['product']], 'Odoo\'s staff flow records no reason');
        $draft = $this->show('NPR-00008');
        $this->assertSame([null, 'cafe 24', null, null, 'كاكاو بودرة '.$tag, null], [$draft['category'], $draft['categoryName'], $draft['costPrice'], $draft['salePrice'], $draft['nameAr'], $draft['nameEn']], 'a category OPS does not have is kept by name; a price of zero is no price');
        $pending = $this->show('NPR-00006');
        $this->assertSame([[false], 'product_image.png'], [array_column($pending['pictures'], 'available'), $pending['pictures'][0]['fileName']], 'listed in Odoo, its content not in the backup yet');
        $this->expectRejected($this->getAs('sales', "/api{$pending['pictures'][0]['url']}"), 'ODOO_FILE_NOT_ARCHIVED', [404]);
        $this->assertSame(0, ProductRequest::where('external_ref', '10')->where('source_model', self::STAFF)->count(), 'deleted in Odoo before OPS ever had it');

        // ── the product a request became is linked by identity only
        $ketchup = $this->show('NPR-00002');
        $this->assertSame([null, null, '5002', ['id' => 5002, 'templateId' => 4002]], [$ketchup['product'], $ketchup['productLink'], $ketchup['sourceProductRef'], $ketchup['sourceMeta']['odooProduct']],
            'the product made by the approval (not the older one of the same name) is not an OPS product yet: remembered, not linked');
        $salt = $this->show('NPR-00003', 'gm');
        $this->assertSame([null, null, 'productNotFound', true], [$salt['product'], $salt['sourceProductRef'], $salt['sourceMeta']['productLookup'], $salt['can']['linkProduct']], 'no Odoo product of that name: a reviewer may say which product it is');
        $this->assertSame([null, 'ambiguous'], [($sugar = $this->show('NPR-00004'))['product'], $sugar['sourceMeta']['productLookup']], 'two products could be it: a person decides');
        $this->assertSame([$this->ops['rice']['sku'], 'restored'], [($rice = $this->show('NPR-00011'))['productSku'], $rice['productLink']], 'made twice in Odoo, and one of the two is an OPS product');

        // ── what customers asked for through the portal
        $honey = $this->show('PRQ-2026-00001');
        $this->assertSame(['existing', 'cancelled', 'cancelled', 'عسل الشفاء الطبيعي (1ltr*6ps)', 'Al Shifa natural honey (1ltr*6ps)', $this->ops['honey']['sku'], 'existing', null, 'Croi Bakehouse'],
            [$honey['requestType'], $honey['status'], $honey['sourceState']['key'], $honey['nameAr'], $honey['nameEn'], $honey['productSku'], $honey['productLink'], $honey['customer'], $honey['customerName']]);
        $this->assertSame([['created', 'submitted', 'cancelled'], 'bdr', ['kind' => 'portal_product_request', 'odooId' => 1]], [self::story($honey), $honey['events'][0]['by'], $honey['archive']]);
        $soap = $this->show('PRQ-2026-00002');
        $this->assertSame(['new', 'submitted', "صابون موبي {$tag}", 'نحتاجه شهريًا لفروعنا الثلاثة', '12.50', $this->customer->id, 'منظفات', 'كرتون', 'شركة الورود الفاخرة للتجارة 1', true],
            [$soap['requestType'], $soap['status'], $soap['nameAr'], $soap['reason'], $soap['salePrice'], $soap['customer']['id'], $soap['categoryName'], $soap['uomName'], $soap['requestedBy']['nameAr'], $soap['requestedBy']['external']]);
        $this->assertSame(['2026-08-31T22:54:02.000000Z', '12.50', 'soap.jpg', [false]], [$soap['statusAt'], $soap['sourceMeta']['suggestedPrice'], $soap['sourceMeta']['pictureFileName'], array_column($soap['pictures'], 'available')]);
        $this->assertGreaterThan(0, $soap['ageDays'], 'it has been waiting since the customer asked');
        $plate = $this->show('PRQ-2026-00003');
        $this->assertSame(['approved', $this->ops['plate']['sku'], 'restored', 'bdr', '2026-09-02T08:00:00.000000Z', '7.25', '6.00'],
            [$plate['status'], $plate['productSku'], $plate['productLink'], $plate['decidedBy']['nameAr'], $plate['decidedAt'], $plate['salePrice'], $plate['sourceMeta']['suggestedPrice']]);
        $refused = $this->show('PRQ-2026-00004');
        $this->assertSame(['rejected', 'غير متوفر لدى الموردين', 'غير متوفر لدى الموردين'], [$refused['status'], $refused['rejectReason'], $refused['events'][0]['note']]);

        // ── they are in the lists like any request, told apart by where they came from
        $list = fn (string $query) => $this->expectOk($this->getAs('sales', "/api/product-requests?pageSize=50&{$query}"));
        $this->assertSame([15, 10, 5, 2], [$list('origin=odoo')['total'], $list('origin=odoo_staff')['total'], $list('origin=odoo_portal')['total'], $list('origin=odoo&type=existing')['total']]);
        $this->assertSame(['NPR-00007'], array_column($list('q='.rawurlencode('NPR/00007'))['items'], 'number'), 'found by the reference people know it by in Odoo');
        $this->assertContains('NPR-00008', array_column($list('origin=odoo&status=draft')['items'], 'number'), 'a draft of Odoo was never private');
        $board = $this->expectOk($this->getAs('gm', '/api/product-requests/board?origin=odoo'));
        $this->assertSame([3, 2, 0], array_column($board['columns'], 'count'));

        // ── a second pass changes nothing
        $events = ProductRequestEvent::count();
        $audit = AuditLog::count();
        $again = $this->projection()->apply();
        $this->assertEquals(['request.unchanged' => 15, 'picture.unchanged' => 3] + array_diff_key(self::firstPass(), array_flip(['request.created', 'picture.created', 'picture.contentKept', 'picture.contentNotArchivedYet'])), $again['counts']);
        $this->assertSame([15, 3, $events, $audit], [ProductRequest::where('source_system', 'odoo')->count(), ProductRequestPicture::where('source_system', 'odoo')->count(), ProductRequestEvent::count(), AuditLog::count()]);
    }

    public function test_what_odoo_owns_is_not_edited_here_while_ops_adds_its_own(): void
    {
        $this->projection()->apply();
        $waiting = $this->odoo(self::STAFF, 7);   // waits for its first approval in Odoo
        $before = $waiting->only(['status', 'name_ar', 'cost_price', 'updated_at']);
        $products = Product::count();

        $detail = $this->show($waiting->number, 'gm');
        $this->assertSame([true, 'submitted'], [$detail['owned'], $detail['status']]);
        $this->assertSame(['edit' => false, 'submit' => false, 'startReview' => false, 'sendBack' => false, 'approve' => false, 'reject' => false, 'cancel' => false, 'comment' => true, 'addPicture' => true, 'linkProduct' => false], $detail['can']);
        // every change of what Odoo holds, and every decision, is refused with the same clear answer
        $owned = $this->expectRejected($this->patchAs('gm', "/api/product-requests/{$waiting->id}", ['salePrice' => 1]), 'PREQ_OWNED_BY_SOURCE', [422]);
        $this->assertSame(['odoo', self::STAFF, '7', 'NPR/00007'], [$owned['details']['sourceSystem'], $owned['details']['sourceModel'], $owned['details']['externalRef'], $owned['details']['sourceNumber']]);
        foreach (['start-review' => [], 'send-back' => ['reason' => 'س'], 'approve' => ['changes' => self::physical()], 'reject' => ['reason' => 'س'], 'cancel' => ['reason' => 'س']] as $move => $body) {
            $this->expectRejected($this->postAs('gm', "/api/product-requests/{$waiting->id}/{$move}", $body), 'PREQ_OWNED_BY_SOURCE', [422]);
        }
        $this->expectRejected($this->postAs('admin', "/api/product-requests/{$this->odoo(self::STAFF, 8)->id}/submit"), 'PREQ_OWNED_BY_SOURCE', [422]);
        $this->assertEquals([$before, $products], [$waiting->refresh()->only(['status', 'name_ar', 'cost_price', 'updated_at']), Product::count()], 'a refused change changes nothing');

        // ── what OPS adds is OPS's: a comment, a picture — by a reviewer or by anybody who raises requests
        $noted = $this->expectOk($this->postAs('sales', "/api/product-requests/{$waiting->id}/comments", ['text' => 'العميل يسأل عن موعد التوفر']));
        $this->assertSame(['comment', null], [$noted['events'][0]['kind'], $noted['events'][0]['origin']]);
        $pictured = $this->expectOk($this->postAs('sales', "/api/product-requests/{$waiting->id}/pictures", ['fileName' => 'shelf.png', 'base64' => self::proofImage()]));
        $this->assertSame([['ops'], ['shelf.png']], [array_column($pictured['pictures'], 'origin'), array_column($pictured['pictures'], 'fileName')]);
        // a picture of Odoo is Odoo's; the one added here is taken off here
        $juice = $this->odoo(self::STAFF, 1);
        $odooPicture = ProductRequestPicture::where('request_id', $juice->id)->firstOrFail();
        $this->assertFalse($this->show($juice->number, 'gm')['pictures'][0]['canRemove'], 'the page offers no button the server would refuse');
        $this->expectRejected($this->postAs('gm', "/api/product-requests/{$juice->id}/pictures/{$odooPicture->id}/remove"), 'PREQ_OWNED_BY_SOURCE', [422]);
        $this->assertCount(0, $this->expectOk($this->postAs('sales', "/api/product-requests/{$waiting->id}/pictures/{$pictured['pictures'][0]['id']}/remove"))['pictures']);

        // ── which OPS product an approved request is, when the copy could not tell: a person says so, and it stays
        $salt = $this->odoo(self::STAFF, 3);
        $product = $this->catalogueProduct();
        $this->expectRejected($this->postAs('sales', "/api/product-requests/{$salt->id}/link-product", ['product' => $product['sku']]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('gm', "/api/product-requests/{$waiting->id}/link-product", ['product' => $product['sku']]), 'PREQ_NOT_APPROVED', [422]);
        $this->expectRejected($this->postAs('gm', "/api/product-requests/{$salt->id}/link-product", ['product' => 'NO-SUCH']), 'PREQ_PRODUCT_NOT_FOUND', [404]);
        $linked = $this->expectOk($this->postAs('gm', "/api/product-requests/{$salt->id}/link-product", ['product' => strtolower($product['sku'])]));
        $this->assertSame([$product['sku'], 'manual', 'product_linked', $product['sku']], [$linked['productSku'], $linked['productLink'], $linked['events'][0]['kind'], $linked['events'][0]['meta']['to']]);
        $this->assertSame('gm', AuditLog::where('action', 'PREQ.LINK_PRODUCT')->where('entity_id', $salt->id)->value('username'));

        // a pass leaves all of it alone
        $again = $this->projection()->apply();
        $this->assertSame([15, 1, 0], [$again['counts']['request.unchanged'], $again['counts']['link.keptAsDecidedHere'], $again['counts']['link.productNotFound'] ?? 0]);
        $this->assertSame([$product['id'], 'manual'], [$salt->refresh()->product_id, $salt->product_link]);
        $this->assertSame([1, 1, 1], [ProductRequestEvent::where('request_id', $waiting->id)->where('kind', 'comment')->count(), ProductRequestPicture::where('request_id', $waiting->id)->whereNull('source_system')->count(),
            ProductRequestPicture::where('request_id', $juice->id)->whereNull('removed_at')->count()]);

        // ── and a request raised in OPS works fully while the section follows Odoo. Its product takes the next code of
        //    the series — after the highest code Odoo has given (P04012 in this copy), not only the highest in OPS
        $own = $this->waiting();
        $done = $this->expectOk($this->postAs('gm', "/api/product-requests/{$own['id']}/approve"));
        $this->assertSame([false, 'approved', 'P04013', 'created'], [$own['owned'], $done['status'], $done['productSku'], $done['productLink']]);
    }

    public function test_a_change_in_odoo_reaches_the_request(): void
    {
        $projection = $this->projection();
        $projection->apply();
        $tahini = $this->odoo(self::STAFF, 6);
        $this->expectOk($this->postAs('sales', "/api/product-requests/{$tahini->id}/comments", ['text' => 'ملاحظة من العمليات']));
        $this->assertSame(0, $projection->waiting());

        // ── the next of Odoo's three approvals: a step of its flow, not a change of status here
        $this->odooWrites(self::STAFF, 6, ['state' => 'system_admin_approval', 'sales_manager_id' => [6, 'مندوب أول'], 'write_uid' => [6, 'مندوب أول'], 'write_date' => '2026-03-05 08:00:00', 'sale_price' => 22.75]);
        $this->assertSame(1, $projection->waiting());
        $out = $projection->consume();
        $this->assertSame([1, ['request.updated' => 1]], [$out['changes'], array_intersect_key($out['counts'], ['request.updated' => 1, 'request.created' => 1])]);
        $step = $this->show($tahini->number);
        $told = self::line($step, 'source_step');
        $this->assertSame(['in_review', 'system_admin_approval', '22.75', 'sales_approval', 'system_admin_approval', 'مندوب أول', 'odoo', '2026-03-05T08:00:00.000000Z'],
            [$step['status'], $step['sourceState']['key'], $step['salePrice'], $told['meta']['from'], $told['meta']['to'], $told['by'], $told['origin'], $told['at']], 'told at the time Odoo gives for it');
        $this->assertSame(['salesManager' => 'مندوب أول', 'supplyChainManager' => 'مدير الإمداد'], collect($step['sourceMeta']['approvals'])->sortKeys()->all());
        $this->assertStringContainsString('sale_price', (string) AuditLog::where('action', 'PREQ.UPDATE')->where('entity_id', $tahini->id)->where('username', 'svc.odoo')->value('new_value'));
        $this->assertSame(0, $projection->waiting(), 'what was handled is marked');

        // ── approved in Odoo: Odoo makes the product in the same transaction — it is not an OPS product yet
        $this->rec('product.template', 4020, ['name' => $this->odooName('tahini'), 'create_date' => '2026-03-06 11:30:00', 'create_uid' => [6, 'مندوب أول']]);
        $this->rec('product.product', 5020, ['product_tmpl_id' => [4020, 'x']]);
        $this->odooWrites(self::STAFF, 6, ['state' => 'approved', 'system_admin_id' => [6, 'مندوب أول'], 'write_date' => '2026-03-06 11:30:00']);
        // … its picture was replaced there, a draft was dropped there, and a new request was raised there
        OdooRecord::where('model', 'ir.attachment')->where('odoo_id', 9002)->update(['deleted_at' => now()]);
        $this->attachment(9006, self::STAFF, 6, 'image/webp', 4000);
        $this->odooDeletes(self::STAFF, 7);
        $this->odooWrites(self::STAFF, 12, ['state' => 'supply_chain_approval', 'product_name' => "زعتر مجفف {$this->tag}"]);
        $out = $projection->consume();
        $this->assertSame(3, $out['changes']);
        $this->assertEquals(['request.updated' => 2, 'request.created' => 1, 'link.productNotInOps' => 1, 'picture.created' => 1, 'picture.removed' => 1, 'picture.contentNotArchivedYet' => 1],
            array_intersect_key($out['counts'], array_flip(['request.updated', 'request.created', 'link.productNotInOps', 'picture.created', 'picture.removed', 'picture.contentNotArchivedYet', 'request.unchanged'])));

        $approved = $this->show($tahini->number);
        $this->assertSame(['approved', 'approved', null, '5020', 'مندوب أول', '2026-03-06T11:30:00.000000Z'], [$approved['status'], $approved['sourceState']['key'], $approved['product'], $approved['sourceProductRef'], $approved['decidedBy']['nameAr'], $approved['decidedAt']]);
        $told = self::line($approved, 'approved');
        $this->assertSame(['in_review', 'approved', 'odoo', 'مندوب أول', '2026-03-06T11:30:00.000000Z'], [$told['fromStatus'], $told['toStatus'], $told['origin'], $told['by'], $told['at']]);
        $this->assertSame(['ملاحظة من العمليات', null], [self::line($approved, 'comment')['note'], self::line($approved, 'comment')['origin']], 'what OPS added stays');
        $history = StatusHistory::where('entity_type', 'ProductRequest')->where('entity_id', $tahini->id)->orderByDesc('at')->orderByDesc('id')->first();
        $this->assertSame(['in_review', 'approved', 'svc.odoo'], [$history->from_status, $history->to_status, $history->username]);
        $this->assertSame([['product_image.webp'], 1], [array_column($approved['pictures'], 'fileName'), ProductRequestPicture::where('request_id', $tahini->id)->whereNotNull('removed_at')->where('removed_by', 'Odoo')->count()]);

        // deleted in Odoo: not deleted here — what was still open is closed, with the reason
        $dropped = $this->show('NPR-00007');
        $this->assertSame(['cancelled', 'حُذف الطلب من أودو', 'cancelled', 'submitted', 'Odoo'], [$dropped['status'], $dropped['cancelReason'], $dropped['events'][0]['kind'], $dropped['events'][0]['fromStatus'], $dropped['events'][0]['by']]);
        $this->assertNotNull($dropped['sourceDeletedAt']);
        $new = $this->show('NPR-00012');
        $this->assertSame(['submitted', "زعتر مجفف {$this->tag}", true], [$new['status'], $new['nameAr'], $new['owned']]);

        // ── the day the master data makes that Odoo product an OPS product, the request finds it by itself
        $this->assertSame([0, []], [($idle = $projection->consume())['changes'], $idle['counts']]);
        $product = $this->catalogueProduct();
        $this->app->make(ExternalRefs::class)->link('odoo', 'product', '5020', $product['id'], $product['sku']);
        $out = $projection->consume();
        $this->assertSame([0, 1], [$out['changes'], $out['counts']['link.restoredLater']]);
        $linked = $this->show($tahini->number);
        $this->assertSame([$product['sku'], 'restored', null, $product['sku'], 'Odoo'], [$linked['productSku'], $linked['productLink'], $linked['sourceProductRef'], self::line($linked, 'product_linked')['meta']['to'], self::line($linked, 'product_linked')['by']]);
        $this->assertSame(0, $projection->consume()['counts']['link.restoredLater'] ?? 0, 'once');
        // … and a whole pass agrees with everything the feed did
        $pass = $projection->apply();
        $this->assertSame([16, null, null], [$pass['counts']['request.unchanged'], $pass['counts']['request.updated'] ?? null, $pass['counts']['request.created'] ?? null]);
    }

    /** The line of a request's history of that kind (the newest, when there are several). */
    private static function line(array $request, string $kind): array
    {
        return collect($request['events'])->firstWhere('kind', $kind) ?? self::fail("no «{$kind}» line in the history");
    }

    public function test_once_the_section_is_handed_over_everything_is_worked_here(): void
    {
        $this->projection()->apply();
        $oats = $this->odoo(self::STAFF, 7);
        $soap = $this->odoo(self::PORTAL, 2);
        $this->followOdoo(false);
        $this->assertFalse(OdooProjection::owns('preq'));

        // a request Odoo left waiting is reviewed, corrected and approved in OPS: the product is created here
        $detail = $this->show($oats->number, 'gm');
        $this->assertSame([false, true, true, true], [$detail['owned'], $detail['can']['edit'], $detail['can']['approve'], $detail['can']['reject']]);
        $this->expectOk($this->postAs('inv', "/api/product-requests/{$oats->id}/start-review"));
        $this->expectOk($this->patchAs('inv', "/api/product-requests/{$oats->id}", ['brand' => 'كويكر', 'storageClass' => 'ambient']));
        $done = $this->expectOk($this->postAs('gm', "/api/product-requests/{$oats->id}/approve", ['changes' => self::physical(), 'sku' => "OAT{$this->tag}"]));
        $this->assertSame(['approved', "OAT{$this->tag}", 'created', 'كويكر'], [$done['status'], $done['productSku'], $done['productLink'], $done['brand']]);
        $p = Product::where('sku', "OAT{$this->tag}")->firstOrFail();
        $this->assertSame(["شوفان {$this->tag}", "Oats {$this->tag}", $this->category->id, '17.08'], [$p->name_ar, $p->name_en, $p->category_id, $p->purchase_price]);
        // a customer's request is refused here, with the reason
        $refused = $this->expectOk($this->postAs('gm', "/api/product-requests/{$soap->id}/reject", ['reason' => 'خارج نشاطنا']));
        $this->assertSame(['rejected', 'خارج نشاطنا'], [$refused['status'], $refused['rejectReason']]);
        // a request about a product the catalogue has is approved without creating anything: it points at that product
        $count = Product::count();
        $honey = $this->expectOk($this->postAs('gm', "/api/product-requests/{$this->odoo(self::PORTAL, 5)->id}/approve"));
        $this->assertSame(['existing', 'approved', $this->ops['honey']['sku'], 'existing', $count], [$honey['requestType'], $honey['status'], $honey['productSku'], $honey['productLink'], Product::count()]);

        // a pass now only adds what is missing and leaves every request as OPS has it — whatever Odoo still says
        $this->odooWrites(self::STAFF, 7, ['state' => 'rejected', 'write_date' => '2026-04-01 08:00:00']);
        $this->odooWrites(self::STAFF, 13, ['state' => 'draft', 'product_name' => "سماق {$this->tag}"]);
        $out = $this->projection()->consume();
        $this->assertSame([2, 1, 1], [$out['changes'], $out['counts']['request.kept'], $out['counts']['request.created']]);
        $this->assertSame(['approved', $p->id], [$oats->refresh()->status, $oats->product_id]);
        $pass = $this->projection()->apply();
        $this->assertSame([16, null, null], [$pass['counts']['request.kept'], $pass['counts']['request.updated'] ?? null, $pass['counts']['request.created'] ?? null]);
        $this->assertSame(['rejected', 'خارج نشاطنا'], [$soap->refresh()->status, $soap->reject_reason]);
        // … and the request that arrived after the hand-over is OPS's from the start
        $this->assertTrue($this->show('NPR-00013', 'gm')['can']['edit'] === false && $this->show('NPR-00013', 'gm')['owned'] === false);
    }
}
