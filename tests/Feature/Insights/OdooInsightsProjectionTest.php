<?php

namespace Tests\Feature\Insights;

use App\Insights\Models\BrandAlert;
use App\Insights\Models\BrandOwner;
use App\Insights\Models\ExpiryLevel;
use App\Insights\Models\ExpiryLog;
use App\Insights\Models\Franchisee;
use App\Insights\OdooInsightsProjection;
use App\Models\Batch;
use App\Models\ProductCategory;
use App\Support\AppError;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * The five models Odoo refuses to the connection user (docs/odoo/shapes/unreadable.txt) — brand.owner,
 * brand.franchisee.mapping, brand.owner.dashboard.alert, expiry.notification.level, expiry.notification.log — as
 * the copy WILL hold them the day the access is given: records of exactly that shape, written here by hand.
 * Proven: while the copy has none the plan answers ODOO_COPY_INCOMPLETE; the plan writes nothing; the apply loads
 * once and a second one changes nothing; links by identity only; what Odoo owns is refused here; the feed follows.
 */
class OdooInsightsProjectionTest extends ApiTestCase
{
    use InsightsFixtures;
    use OdooFixtures;

    private const MODELS = ['expiry.notification.level', 'brand.owner', 'brand.franchisee.mapping', 'brand.owner.dashboard.alert', 'expiry.notification.log'];

    private function projection(): OdooInsightsProjection
    {
        return $this->app->make(OdooInsightsProjection::class);
    }

    private function tables(): array
    {
        return [BrandOwner::count(), Franchisee::count(), BrandAlert::count(), ExpiryLevel::count(), ExpiryLog::count(), DB::table('ins_brand_owner_products')->count(), DB::table('audit_logs')->count()];
    }

    public function test_from_the_shapes_to_the_section(): void
    {
        config(['odoo.enabled' => true, 'odoo.project.insights' => true]);
        // today: Odoo refuses these records — the copy has the models as skipped
        foreach (self::MODELS as $m) {
            $this->odooModel($m, 'skipped', 'pending');
        }
        try {
            $this->projection()->plan();
            $this->fail('a plan over a copy that does not hold the area');
        } catch (AppError $e) {
            $this->assertSame('ODOO_COPY_INCOMPLETE', $e->errorCode);
        }

        // the day the access is given
        foreach (self::MODELS as $m) {
            $this->odooModel($m);
        }
        $tag = self::tag();
        $b = 700_000 + ((int) self::uid() % 100000);
        $owner = $this->makeUser(['page.insights']);
        $c1 = $this->makeCustomer();
        $c2 = $this->makeCustomer();
        $p = $this->makeBrandProduct(null);
        $cat = ProductCategory::create(['code' => "QCAT-{$tag}", 'name_ar' => "فئة {$tag}", 'name_en' => "Cat {$tag}", 'active' => true]);
        $batch = Batch::create(['product_id' => $p->id, 'batch_no' => "LOT-{$tag}", 'expiry_date' => '2026-10-01 00:00:00']);
        $this->linkOdoo('customer', $b + 1, $c1->id);
        $this->linkOdoo('customer', $b + 2, $c2->id);
        $this->linkOdoo('product', $b + 10, $p->id);
        $this->linkOdoo('category', $b + 20, $cat->id);
        $this->rec('res.users', $b + 30, ['login' => strtoupper($owner->username), 'partner_id' => [$b + 31, 'x']], true);
        $this->rec('res.users', $b + 32, ['login' => "nobody{$tag}@corp.example", 'partner_id' => [$b + 33, 'y']], true);
        $this->rec('stock.lot', $b + 40, ['name' => "LOT-{$tag}", 'product_id' => [$b + 10, 'p'], 'expiration_date' => '2026-10-01 00:00:00']);
        $this->rec('stock.lot', $b + 41, ['name' => "GONE-{$tag}", 'product_id' => [$b + 10, 'p'], 'expiration_date' => '2026-09-25 00:00:00']);

        $this->rec('expiry.notification.level', $b, ['name' => "شهر {$tag}", 'days_before_expiry' => 30, 'notification_type' => 'chatter', 'user_ids' => [$b + 30, $b + 32], 'sequence' => 1, 'color' => 3, 'company_id' => [1, 'C']], true);
        $this->rec('brand.owner', $b, ['name' => "مالك أودو {$tag}", 'user_id' => [$b + 30, 'المالك'], 'partner_id' => [$b + 1, 'جهة'], 'brand_name' => "QA-O-{$tag}", 'state' => 'active',
            'date_start' => '2026-01-01', 'franchisee_ids' => [$b + 1, $b + 2, $b + 3], 'product_ids' => [$b + 10, $b + 11], 'product_category_ids' => [$b + 20],
            'allow_invoice_access' => true, 'allow_payment_access' => false, 'allow_return_access' => true, 'allow_margin_access' => false, 'notes' => '<p>عقد <b>2026</b></p>'], true);
        $this->rec('brand.franchisee.mapping', $b, ['brand_owner_id' => [$b, 'x'], 'partner_id' => [$b + 1, 'x'], 'region' => 'الوسطى', 'city' => 'الرياض', 'date_start' => '2026-02-01',
            'monthly_purchase_target' => 1500.504, 'currency_id' => [148, 'SAR'], 'status' => 'weak', 'notes' => false], true);
        $this->rec('brand.owner.dashboard.alert', $b, ['brand_owner_id' => [$b, 'x'], 'partner_id' => [$b + 1, 'x'], 'alert_type' => 'overdue_invoice', 'name' => 'فاتورة متأخرة', 'description' => '<p>45 يومًا</p>',
            'severity' => 'danger', 'date' => '2026-08-30', 'is_read' => false, 'action_model' => 'account.move', 'action_res_id' => 991]);
        $this->rec('expiry.notification.log', $b, ['lot_id' => [$b + 40, 'LOT'], 'level_id' => [$b, 'x'], 'notification_date' => '2026-09-01 06:00:00', 'notification_type' => 'chatter', 'user_ids' => [$b + 30], 'product_qty' => 12.0]);
        $this->rec('expiry.notification.log', $b + 1, ['lot_id' => [$b + 41, 'GONE'], 'level_id' => [$b, 'x'], 'notification_date' => '2026-09-01 06:00:00', 'notification_type' => 'both', 'user_ids' => [], 'product_qty' => 3.0]);

        $p1 = $this->projection();
        $this->assertSame(['insights', true, true], [$p1->key(), $p1->enabled(), $p1->ready()]);
        $before = $this->tables();
        $plan = $p1->plan();
        $this->assertTrue($plan['dryRun']);
        $this->assertSame($before, $this->tables(), 'the plan writes nothing');
        $this->assertSame(1, $plan['counts']['owners.created']);
        $this->assertSame(1, $plan['counts']['levels.created']);
        $this->assertSame(1, $plan['counts']['alerts.created']);
        $this->assertSame(2, $plan['counts']['log.created']);

        $applied = $this->projection()->apply();
        $c = $applied['counts'];
        $this->assertSame([1, 1, 1, 2, 2, 1, 1], [$c['owners.created'], $c['levels.created'], $c['alerts.created'], $c['log.created'], $c['franchisees.fromOwner'], $c['franchisees.adopted'], $c['franchisees.skippedNoCustomer']], json_encode($c));
        $this->assertSame(1, $c['owners.productNotLinked']);

        $o = BrandOwner::where('source_system', 'odoo')->where('external_ref', (string) $b)->firstOrFail();
        $this->assertSame([$owner->id, 'active', $c1->id, "QA-O-{$tag}"], [$o->user_id, $o->state, $o->contact_customer_id, $o->brand_name]);
        $this->assertSame('عقد 2026', $o->notes);
        $this->assertSame([$p->id], $o->products()->pluck('products.id')->all());
        $this->assertSame([$cat->id], $o->categories()->pluck('product_categories.id')->all());
        $fr = Franchisee::where('owner_id', $o->id)->get()->keyBy('customer_id');
        $this->assertSame([$c1->id, $c2->id], collect([$c1->id, $c2->id])->filter(fn ($id) => isset($fr[$id]))->values()->all(), 'the unlinked partner is skipped');
        $this->assertCount(2, $fr);
        $this->assertSame(['weak', '1500.50', 'الوسطى', (string) $b], [$fr[$c1->id]->status, $fr[$c1->id]->monthly_target, $fr[$c1->id]->region, $fr[$c1->id]->external_ref], 'the mapping adopts the owner\'s row');
        $this->assertSame('active', $fr[$c2->id]->status);
        $alert = BrandAlert::where('owner_id', $o->id)->firstOrFail();
        $this->assertSame(['overdue_invoice', 'danger', "odoo:{$b}", '45 يومًا', $c1->id], [$alert->alert_type, $alert->severity, $alert->occurrence_key, $alert->description_ar, $alert->customer_id]);
        $level = ExpiryLevel::where('external_ref', (string) $b)->firstOrFail();
        $this->assertSame(['inapp', 30, [$owner->id]], [$level->notification_type, $level->days_before_expiry, $level->recipients()->pluck('users.id')->all()]);
        $this->assertCount(1, $level->recipients_kept, 'the Odoo recipient without an OPS user, by name');
        $logs = ExpiryLog::where('level_id', $level->id)->orderBy('external_ref')->get();
        $this->assertSame([$batch->id, null], $logs->pluck('batch_id')->all());
        $this->assertSame([12, 'odoo', 30], [$logs[0]->qty_on_hand, $logs[0]->email_status, $logs[0]->days_left]);
        $this->assertSame("GONE-{$tag}", $logs[1]->batch_no);

        // a second apply changes nothing
        $again = $this->tables();
        $second = $this->projection()->apply();
        $this->assertSame($again, $this->tables());
        $this->assertArrayNotHasKey('owners.created', $second['counts']);
        $this->assertArrayNotHasKey('owners.updated', $second['counts']);

        // what Odoo owns is refused here while the section follows Odoo; OPS may still add a franchisee and read alerts
        $this->expectRejected($this->patchAs('admin', "/api/insights/brands/{$o->id}", ['allowMargin' => true]), 'INSIGHTS_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('admin', "/api/insights/brands/{$o->id}/state", ['to' => 'suspended', 'reason' => 'x']), 'INSIGHTS_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('admin', "/api/insights/franchisees/{$fr[$c1->id]->id}/status", ['to' => 'active']), 'INSIGHTS_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->patchAs('admin', "/api/insights/expiry/levels/{$level->id}", ['daysBeforeExpiry' => 10]), 'INSIGHTS_OWNED_BY_SOURCE', [422]);
        $c3 = $this->makeCustomer();
        $this->expectOk($this->postAs('admin', "/api/insights/brands/{$o->id}/franchisees", ['customerId' => $c3->id]));
        $this->assertTrue($this->expectOk($this->postAs($owner->username, "/api/insights/alerts/{$alert->id}/read"))['read']);
        $this->assertSame($o->id, $this->expectOk($this->getAs($owner->username, '/api/insights/me'))['brandOwner']['id'], 'the Odoo owner\'s user is his OPS user');

        // the feed: a target changed in Odoo, the owner deleted there
        $this->odooWrites('brand.franchisee.mapping', $b, ['monthly_purchase_target' => 2000.0]);
        $this->odooWrites('brand.owner', $b, [], delete: true);
        $fed = $this->projection()->consume();
        $this->assertGreaterThanOrEqual(2, $fed['changes']);
        $this->assertSame('2000.00', $fr[$c1->id]->fresh()->monthly_target);
        $this->assertSame(['archived', false], [$o->fresh()->state, $o->fresh()->active], 'deleted in Odoo: archived here, never deleted');

        // handed over: everything is OPS's
        config(['odoo.project.insights' => false]);
        $this->expectOk($this->patchAs('admin', "/api/insights/expiry/levels/{$level->id}", ['daysBeforeExpiry' => 10]));
    }
}
