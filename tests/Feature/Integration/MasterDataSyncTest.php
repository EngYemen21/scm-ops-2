<?php

namespace Tests\Feature\Integration;

use App\Integration\Models\IntException;
use App\Integration\Models\IntMirror;
use App\Integration\Services\ExternalRefs;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\Product;
use Tests\ApiTestCase;
use Tests\Feature\Sales\SalesFixtures;

/**
 * Phase 3 — master data. Customers and their branches come from Sales (system of record) and become OPS customers +
 * delivery sites; products are mapped to OPS SKUs by a person (never guessed), which closes the "unmapped" exception.
 */
class MasterDataSyncTest extends ApiTestCase
{
    use IntegrationTestHelpers;
    use SalesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableSystems();
    }

    private function customerEvent(string $id, string $type = 'customer.created', array $over = [], ?int $seq = null): array
    {
        return $this->envelope($type, $id, array_merge([
            'id' => $id, 'name' => 'مطاعم البلدة '.$id, 'cr' => '4030-'.$id, 'vat' => '3100'.$id, 'city' => 'الرياض', 'type' => 'مستقل', 'active' => true,
            'branches' => [
                ['key' => "{$id}:olaya", 'name' => 'فرع العليا', 'city' => 'الرياض', 'address' => 'طريق العليا', 'lat' => 24.705, 'lng' => 46.754],
                ['key' => "{$id}:malqa", 'name' => 'فرع الملقا', 'city' => 'الرياض', 'address' => 'حي الملقا'],
            ],
        ], $over), $seq);
    }

    public function test_a_sales_customer_becomes_an_ops_customer_with_its_branches(): void
    {
        $id = 'C'.self::uid();
        $r = $this->send($this->customerEvent($id, seq: 1))->json('results.0');
        $this->assertSame(['processed', true, 2], [$r['status'], $r['result']['created'], $r['result']['sites']['active']]);
        $cid = app(ExternalRefs::class)->internalId('sales', 'customer', $id);
        $c = Customer::find($cid);
        $this->assertSame(["مطاعم البلدة {$id}", "4030-{$id}", "3100{$id}", 'sales', $r['result']['customer']], [$c->name_ar, $c->cr, $c->vat_no, $c->source_system, $c->code]);
        $this->assertSame(2, CustomerSite::where('customer_id', $cid)->where('active', true)->count());
        $this->assertTrue(AuditLog::where('action', 'CUSTOMER.CREATE')->where('entity_id', $cid)->where('username', 'svc.sales')->exists());
        $this->assertNotNull(IntMirror::where('entity', 'customer')->where('external_id', $id)->first());

        // OPS verifies a site on its map; Sales later moves its pin and drops a branch, renames the customer
        $olaya = CustomerSite::where('customer_id', $cid)->where('external_key', "{$id}:olaya")->first();
        $olaya->update(['lat' => 24.7111, 'lng' => 46.6666, 'coords_verified' => true]);
        $upd = $this->customerEvent($id, 'customer.updated', ['name' => 'مطاعم البلدة الجديدة', 'branches' => [
            ['key' => "{$id}:olaya", 'name' => 'فرع العليا', 'city' => 'الرياض', 'address' => 'طريق العليا', 'lat' => 24.9, 'lng' => 46.9],
        ]], 2);
        $r2 = $this->send($upd)->json('results.0');
        $this->assertSame(['processed', false, 1], [$r2['status'], $r2['result']['created'], $r2['result']['sites']['deactivated']]);
        $this->assertSame('مطاعم البلدة الجديدة', $c->refresh()->name_ar);
        $this->assertSame([24.7111, 46.6666], [$olaya->refresh()->lat, $olaya->lng], 'a verified site keeps the coordinates OPS confirmed');
        $this->assertFalse(CustomerSite::where('external_key', "{$id}:malqa")->value('active'), 'a dropped branch is deactivated, not deleted');
        $this->assertSame(1, Customer::where('cr', "4030-{$id}")->count(), 'the update did not create a second customer');
        $this->assertTrue(AuditLog::where('action', 'CUSTOMER.SYNC')->where('entity_id', $cid)->exists());

        // the commercial identity is Sales' — OPS cannot edit it, only operational fields
        $this->expectRejected($this->patchAs('admin', "/api/customers/{$c->code}", ['nameAr' => 'تعديل محلي']), 'CUSTOMER_OWNED_BY_SOURCE', [422]);
        $this->expectOk($this->patchAs('admin', "/api/customers/{$c->code}", ['zone' => 'شمال الرياض']));
        $this->assertSame('شمال الرياض', $c->refresh()->zone);

        // invalid content is rejected and visible as an exception
        $bad = $this->send($this->customerEvent('C-BAD-'.self::uid(), over: ['name' => ' ']))->json('results.0');
        $this->assertSame(['rejected', 'CUSTOMER_NAME_MISSING'], [$bad['status'], $bad['code']]);
    }

    public function test_sales_products_are_mapped_to_ops_skus_by_a_person(): void
    {
        $pid = 'P-'.self::uid();
        $p = $this->makeProduct();
        $p->update(['name_ar' => 'أرز بسمتي هندي 40 كجم']);
        $evt = fn (string $type, int $seq) => $this->envelope($type, $pid, ['id' => $pid, 'name' => 'ارز بسمتي هندي', 'unit' => 'كيس 40 كجم', 'category' => 'حبوب', 'price' => 185.5, 'active' => true], $seq);

        $r = $this->send($evt('product.created', 1))->json('results.0');
        $this->assertSame(['processed', false], [$r['status'], $r['result']['mapped']]);
        $x = IntException::where('code', 'PRODUCT_UNMAPPED')->where('entity_ref', $pid)->where('status', 'open')->first();
        $this->assertSame('info', $x->severity);

        // the steward sees it unmapped, with name-based suggestions (only hints)
        $list = $this->expectOk($this->getAs('admin', "/api/integration/mappings/product?state=unmapped&q={$pid}"));
        $row = collect($list['items'])->firstWhere('externalId', $pid);
        $this->assertFalse($row['mapped']);
        $this->assertContains($p->sku, array_column($row['suggestions'], 'code'));

        // mapping by SKU closes the exception; the next update reports the link
        $m = $this->expectOk($this->postAs('admin', '/api/integration/mappings/product', ['externalId' => $pid, 'internal' => $p->sku]));
        $this->assertSame([$p->id, $p->sku], [$m['internalId'], $m['internalCode']]);
        $this->assertSame('resolved', $x->refresh()->status);
        $this->assertTrue(AuditLog::where('action', 'INTEGRATION.MAP')->where('entity_number', "sales:product:{$pid}")->where('username', 'admin')->exists());
        $this->assertSame(['processed', true, $p->sku], [($u = $this->send($evt('product.updated', 2))->json('results.0'))['status'], $u['result']['mapped'], $u['result']['sku']]);

        // one-to-one both ways: a second Sales product cannot take the same SKU; unknown records cannot be mapped
        $pid2 = 'P-'.self::uid().'9';
        $this->send($this->envelope('product.created', $pid2, ['id' => $pid2, 'name' => 'أرز آخر'], 1));
        $this->expectRejected($this->postAs('admin', '/api/integration/mappings/product', ['externalId' => $pid2, 'internal' => $p->sku]), 'REF_ALREADY_LINKED', [409]);
        $this->expectRejected($this->postAs('admin', '/api/integration/mappings/product', ['externalId' => 'P-NEVER-SENT', 'internal' => $p->sku]), 'EXTERNAL_RECORD_UNKNOWN', [404]);
        $this->expectRejected($this->postAs('admin', '/api/integration/mappings/product', ['externalId' => $pid2, 'internal' => 'NO-SUCH-SKU']), 'PRODUCT_NOT_FOUND', [404]);

        // least privilege: viewing needs integration.view, mapping needs integration.manage
        $this->expectRejected($this->getAs('sales', '/api/integration/mappings/product'), 'FORBIDDEN', [403]);
        $this->expectOk($this->getAs('gm', '/api/integration/mappings/product'));
        $this->expectRejected($this->postAs('gm', '/api/integration/mappings/product', ['externalId' => $pid2, 'internal' => $p->sku]), 'FORBIDDEN', [403]);

        // unlinking is audited and frees the SKU
        $this->expectOk($this->deleteAs('admin', "/api/integration/mappings/product/{$pid}"));
        $this->assertNull(app(ExternalRefs::class)->internalId('sales', 'product', $pid));
        $this->assertSame(1, Product::where('sku', $p->sku)->count());
    }
}
