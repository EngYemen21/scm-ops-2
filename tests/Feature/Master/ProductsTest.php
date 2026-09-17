<?php

namespace Tests\Feature\Master;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StatusHistory;
use Tests\ApiTestCase;

/** Products (per-field audit, barcodes, supplier links, activation), the category tree and units of measure. */
class ProductsTest extends ApiTestCase
{
    private static string $n = '';

    private static function n(): string
    {
        return self::$n ?: self::$n = self::uid();
    }

    private static function sku(): string
    {
        return 'TST-'.self::n();
    }

    private static function body(array $over = []): array
    {
        return $over + ['sku' => strtolower(self::sku()), 'nameAr' => 'منتج اختبار '.self::n(), 'nameEn' => 'Test product '.self::n(), 'weightKg' => 2.5,
            'lengthCm' => 10, 'widthCm' => 20, 'heightCm' => 30, 'storageClass' => 'ambient', 'barcode' => '62900'.self::n().'1', 'uomCode' => 'ctn'];
    }

    // ───────────────────────────── products ─────────────────────────────

    public function test_create_product_with_primary_barcode_volume_and_storage_requirement(): void
    {
        $p = $this->expectOk($this->postAs('inv', '/api/products', self::body(['categoryCode' => 'dairy', 'homeWarehouseCode' => 'ryd', 'preferredSupplierCode' => 'SUP-019', 'purchasePrice' => '12.5'])));
        $this->assertSame(self::sku(), $p['sku'], 'the SKU is upper-cased');
        $this->assertSame('62900'.self::n().'1', $p['primaryBarcode']);
        $this->assertEqualsWithDelta(0.006, $p['volumeM3'], 1e-9);
        $this->assertSame('ambient', $p['storageReq']['storageClass']);
        $this->assertFalse($p['tracksExpiry']);
        $this->assertSame(0, $p['reorderMin']);
        $this->assertSame('dairy', $p['category']['code']);
        $this->assertArrayHasKey('parent', $p['category']);
        $this->assertSame('ctn', $p['baseUom']['code']);
        $this->assertSame(['id', 'code', 'nameAr', 'nameEn'], array_keys($p['homeWarehouse']));
        $this->assertSame('RYD', $p['homeWarehouse']['code']);
        $this->assertCount(1, $p['suppliers']);
        $this->assertTrue($p['suppliers'][0]['preferred']);
        $this->assertSame('SUP-019', $p['suppliers'][0]['supplier']['code']);
        $this->assertSame('مصنع مياه الينابيع', $p['preferredSupplierName']);
        $this->assertTrue($p['barcodes'][0]['isPrimary']);
        $this->assertSame('ctn', $p['barcodes'][0]['uom']['code']);
        $this->assertSame(1, AuditLog::where('entity_id', $p['id'])->where('action', 'PRODUCT.CREATE')->count());

        $cold = $this->expectOk($this->postAs('inv', '/api/products', self::body(['sku' => self::sku().'F', 'barcode' => null, 'storageClass' => 'frozen', 'tracksExpiry' => true])));
        $this->assertSame(-20, (int) $cold['storageReq']['minTempC']);
        $this->assertSame(-16, (int) $cold['storageReq']['maxTempC']);
        $this->assertTrue($cold['tracksExpiry']);
        $this->assertNull($cold['primaryBarcode']);
    }

    public function test_create_product_rejections(): void
    {
        $this->expectRejected($this->postAs('inv', '/api/products', self::body()), 'SKU_TAKEN', [409]);
        $this->expectRejected($this->postAs('inv', '/api/products', self::body(['sku' => self::sku().'B'])), 'BARCODE_TAKEN', [409]);
        $fresh = ['sku' => self::sku().'C', 'barcode' => null];
        $this->expectRejected($this->postAs('inv', '/api/products', self::body($fresh + ['weightKg' => 0])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('inv', '/api/products', self::body($fresh + ['lengthCm' => -1])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('inv', '/api/products', self::body($fresh + ['storageClass' => 'warm'])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('inv', '/api/products', ['sku' => 'AB']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('inv', '/api/products', self::body($fresh + ['categoryCode' => 'no-such-cat'])), 'BAD_CATEGORY', [400]);
        $this->expectRejected($this->postAs('inv', '/api/products', self::body($fresh + ['uomCode' => 'zz'])), 'BAD_UOM', [400]);
        $this->expectRejected($this->postAs('inv', '/api/products', self::body($fresh + ['homeWarehouseCode' => 'XXX'])), 'BAD_WAREHOUSE', [400]);
        $this->expectRejected($this->postAs('inv', '/api/products', self::body($fresh + ['preferredSupplierCode' => 'SUP-NOPE'])), 'BAD_SUPPLIER', [400]);
        $this->assertFalse(Product::where('sku', self::sku().'C')->exists());
    }

    public function test_edit_needs_a_reason_and_audits_each_changed_field(): void
    {
        $sku = self::sku();
        $body = $this->expectRejected($this->patchAs('inv', "/api/products/{$sku}", ['nameAr' => 'x', 'reason' => '']), 'INVALID_INPUT', [400]);
        $this->assertSame('reason', $body['details'][0]['path']);
        $this->assertSame('سبب التعديل مطلوب', $body['details'][0]['message']);
        $this->expectRejected($this->patchAs('inv', "/api/products/{$sku}", ['nameAr' => 'x']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->patchAs('inv', "/api/products/{$sku}", ['lengthCm' => 0, 'reason' => 'x']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->patchAs('inv', '/api/products/NOPE-SKU', ['nameAr' => 'x', 'reason' => 'x']), 'PRODUCT_NOT_FOUND', [404]);

        $r = $this->expectOk($this->patchAs('inv', "/api/products/{$sku}", ['nameAr' => 'منتج معدل '.self::n(), 'weightKg' => 3, 'heightCm' => 15, 'storageClass' => 'chilled', 'reason' => 'تصحيح الأبعاد']));
        $this->assertSame(4, $r['changed']);
        $this->assertSame('حُفظ التعديل — 4 حقل مسجل في Audit Trail', $r['messageAr']);
        $this->assertSame(['field' => 'heightCm', 'labelAr' => 'الارتفاع', 'old' => 30, 'new' => 15], collect($r['fields'])->firstWhere('field', 'heightCm'));

        $p = $this->expectOk($this->getAs('inv', "/api/products/{$sku}"));
        $rows = AuditLog::where('entity_type', 'Product')->where('entity_id', $p['id'])->where('action', 'PRODUCT.EDIT')->get();
        $fields = $rows->pluck('field')->sort()->values()->all();
        $this->assertSame(['heightCm', 'nameAr', 'storageClass', 'weightKg'], $fields);
        $height = $rows->firstWhere('field', 'heightCm');
        $this->assertSame('30', $height->old_value);
        $this->assertSame('15', $height->new_value);
        $summary = AuditLog::where('entity_id', $p['id'])->where('action', 'PRODUCT.UPDATE')->get();
        $this->assertCount(1, $summary);
        $this->assertStringContainsString('تصحيح الأبعاد', $summary[0]->new_value);
        $this->assertEqualsWithDelta(0.003, $p['volumeM3'], 1e-9);
        $this->assertSame('chilled', $p['storageClass']);
        $this->assertSame('chilled', $p['storageReq']['storageClass']);

        $again = $this->expectOk($this->patchAs('inv', "/api/products/{$sku}", ['nameAr' => 'منتج معدل '.self::n(), 'weightKg' => '3', 'reason' => 'لا شيء']));
        $this->assertSame(0, $again['changed']);
        $this->assertSame('No changes', $again['messageEn']);
    }

    public function test_edit_references_barcode_and_active_flag(): void
    {
        $sku = self::sku();
        $r = $this->expectOk($this->patchAs('inv', "/api/products/{$sku}", ['categoryCode' => 'cheese', 'uomCode' => 'box', 'barcode' => '62900'.self::n().'9', 'preferredSupplierCode' => '', 'active' => false, 'reason' => 'إعادة تصنيف']));
        $this->assertSame(['category', 'uom', 'preferredSupplier', 'active', 'barcode'], array_column($r['fields'], 'field'));
        $category = collect($r['fields'])->firstWhere('field', 'category');
        $this->assertSame(['dairy', 'cheese'], [$category['old'], $category['new']]);
        $p = $this->expectOk($this->getAs('inv', "/api/products/{$sku}"));
        $this->assertSame('cheese', $p['category']['code']);
        $this->assertSame('box', $p['baseUom']['code']);
        $this->assertSame('62900'.self::n().'9', $p['primaryBarcode']);
        $this->assertCount(1, $p['barcodes'], 'the primary barcode is replaced, not added');
        $this->assertNull($p['preferredSupplierName']);
        $this->assertFalse($p['active']);
        $this->assertSame(['inactive'], StatusHistory::where('entity_type', 'Product')->where('entity_id', $p['id'])->pluck('to_status')->all());

        $other = $this->expectOk($this->getAs('inv', '/api/products/'.self::sku().'F'));
        $this->expectRejected($this->patchAs('inv', "/api/products/{$other['id']}", ['barcode' => '62900'.self::n().'9', 'reason' => 'x']), 'BARCODE_TAKEN', [409]);
        $this->expectOk($this->patchAs('inv', "/api/products/{$sku}", ['barcode' => '62900'.self::n().'1', 'reason' => 'إرجاع الباركود']));
        $this->expectOk($this->postAs('inv', "/api/products/{$sku}/activate"));
    }

    public function test_barcodes_supplier_links_and_activation(): void
    {
        $sku = self::sku();
        $second = '62900'.self::n().'2';
        $bc = $this->expectOk($this->postAs('inv', "/api/products/{$sku}/barcodes", ['barcode' => $second, 'uomCode' => 'ctn']));
        $this->assertFalse($bc['isPrimary']);
        $this->assertSame($second, $bc['barcode']);
        $dup = $this->expectRejected($this->postAs('inv', "/api/products/{$sku}/barcodes", ['barcode' => $second]), 'BARCODE_TAKEN', [409]);
        $this->assertStringContainsString('مسجل لهذا المنتج', $dup['message']);
        $this->expectRejected($this->postAs('inv', "/api/products/{$sku}/barcodes", ['barcode' => $second.'7', 'uomCode' => 'zz']), 'BAD_UOM', [400]);
        $this->expectRejected($this->postAs('inv', "/api/products/{$sku}/barcodes", []), 'INVALID_INPUT', [400]);

        $this->expectRejected($this->postAs('inv', "/api/products/{$sku}/suppliers", ['supplierCode' => 'SUP-NOPE']), 'SUPPLIER_NOT_FOUND', [404]);
        $link = $this->expectOk($this->postAs('inv', "/api/products/{$sku}/suppliers", ['supplierCode' => 'SUP-019', 'price' => 12.5, 'leadDays' => 3, 'preferred' => true]));
        $this->assertTrue($link['preferred']);
        $this->assertEquals(12.5, $link['price']);
        $this->assertSame('SUP-019', $link['supplier']['code']);
        $p = $this->expectOk($this->getAs('inv', "/api/products/{$sku}"));
        $this->assertCount(2, $p['barcodes']);
        $this->assertCount(1, $p['suppliers']);
        $this->assertSame('مصنع مياه الينابيع', $p['preferredSupplierName']);
        $this->assertSame(1, AuditLog::where('entity_id', $p['id'])->where('action', 'PRODUCT.SUPPLIER_UPDATE')->count(), 'SUP-019 was already linked at creation');

        $bySupplier = $this->expectOk($this->getAs('inv', '/api/products?supplier=SUP-019&q='.self::n()));
        $this->assertContains($sku, array_column($bySupplier['items'], 'sku'));

        $this->expectOk($this->deleteAs('inv', "/api/products/{$sku}/barcodes/{$second}"));
        $this->expectRejected($this->deleteAs('inv', "/api/products/{$sku}/barcodes/{$second}"), 'BARCODE_NOT_FOUND', [404]);
        $this->expectOk($this->deleteAs('inv', "/api/products/{$sku}/suppliers/SUP-019"));
        $this->expectRejected($this->deleteAs('inv', "/api/products/{$sku}/suppliers/SUP-019"), 'LINK_NOT_FOUND', [404]);

        $off = $this->expectOk($this->postAs('inv', "/api/products/{$sku}/deactivate", ['reason' => 'إيقاف']));
        $this->assertFalse($off['active']);
        $this->expectRejected($this->postAs('inv', "/api/products/{$sku}/deactivate"), 'PRODUCT_STATE', [422]);
        $p = $this->expectOk($this->getAs('inv', "/api/products/{$sku}"));
        $this->assertFalse($p['active']);
        $this->assertCount(1, $p['barcodes']);
        $this->assertCount(0, $p['suppliers']);
        $this->assertNull($p['preferredSupplierName']);
        $this->assertSame('إيقاف', StatusHistory::where('entity_type', 'Product')->where('entity_id', $p['id'])->orderByDesc('id')->value('note'));
    }

    public function test_removing_the_primary_barcode_promotes_the_next_one(): void
    {
        $sku = self::sku();
        $first = '62900'.self::n().'1';
        $extra = '62900'.self::n().'3';
        $this->expectOk($this->postAs('inv', "/api/products/{$sku}/barcodes", ['barcode' => $extra]));
        $this->expectOk($this->deleteAs('inv', "/api/products/{$sku}/barcodes/{$first}"));
        $p = $this->expectOk($this->getAs('inv', "/api/products/{$sku}"));
        $this->assertSame($extra, $p['primaryBarcode']);
        $this->assertTrue($p['barcodes'][0]['isPrimary']);
        $made = $this->expectOk($this->postAs('inv', "/api/products/{$sku}/barcodes", ['barcode' => $first, 'isPrimary' => true]));
        $this->assertTrue($made['isPrimary']);
        $this->assertSame($first, $this->expectOk($this->getAs('inv', "/api/products/{$sku}"))['primaryBarcode']);
    }

    public function test_list_paging_search_and_filters(): void
    {
        $page = $this->expectOk($this->getAs('sales', '/api/products?pageSize=5'));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($page));
        $this->assertCount(5, $page['items']);
        $skus = array_column($page['items'], 'sku');
        $sorted = $skus;
        sort($sorted);
        $this->assertSame($sorted, $skus, 'default order is SKU ascending');
        foreach (['category', 'baseUom', 'homeWarehouse', 'barcodes', 'primaryBarcode'] as $key) {
            $this->assertArrayHasKey($key, $page['items'][0]);
        }

        $list = $this->expectOk($this->getAs('sales', '/api/products?active=false&q='.self::n()));
        $this->assertSame(1, $list['total']);
        $this->assertSame('62900'.self::n().'1', $list['items'][0]['primaryBarcode']);
        $this->assertCount(1, $list['items'][0]['barcodes']);
        $this->assertSame('box', $list['items'][0]['baseUom']['code']);
        $this->assertSame(1, $this->expectOk($this->getAs('sales', '/api/products?q=62900'.self::n().'3'))['total'], 'search also matches barcodes');

        $frozen = $this->expectOk($this->getAs('sales', '/api/products?storageClass=Frozen&pageSize=500'));
        $this->assertContains(self::sku().'F', array_column($frozen['items'], 'sku'));
        $this->assertSame(['frozen'], array_values(array_unique(array_column($frozen['items'], 'storageClass'))));
        $cheese = $this->expectOk($this->getAs('sales', '/api/products?category=cheese&warehouse=RYD&pageSize=500'));
        $this->assertContains(self::sku(), array_column($cheese['items'], 'sku'));
        $heavy = array_column($this->expectOk($this->getAs('sales', '/api/products?sort=weightKg&order=desc&pageSize=10'))['items'], 'weightKg');
        $expected = $heavy;
        rsort($expected);
        $this->assertSame($expected, $heavy);
        $this->expectRejected($this->getAs('sales', '/api/products?active=yes'), 'INVALID_INPUT', [400]);
    }

    public function test_product_changes_need_the_permission(): void
    {
        $sku = self::sku();
        $count = Product::count();
        $this->expectRejected($this->postAs('sales', '/api/products', self::body(['sku' => self::sku().'Z', 'barcode' => null])), 'FORBIDDEN', [403]);
        $this->expectRejected($this->patchAs('wm', "/api/products/{$sku}", ['nameAr' => 'مرفوض', 'reason' => 'x']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('worker', "/api/products/{$sku}/activate"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->deleteAs('sales', "/api/products/{$sku}/barcodes/62900".self::n().'1'), 'FORBIDDEN', [403]);
        $this->assertSame($count, Product::count());
        $p = $this->expectOk($this->getAs('worker', "/api/products/{$sku}"));
        $this->assertFalse($p['active']);
        $this->assertCount(2, $p['barcodes']);
    }

    // ───────────────────────────── categories ─────────────────────────────

    public function test_category_codes_tree_and_cycles(): void
    {
        $code = 'spec-'.self::n();
        $body = $this->expectRejected($this->postAs('inv', '/api/categories', ['code' => 'تصنيف', 'nameAr' => 'x']), 'INVALID_INPUT', [400]);
        $this->assertSame('الرمز حروف لاتينية وأرقام فقط', $body['details'][0]['message']);
        $parent = $this->expectOk($this->postAs('inv', '/api/categories', ['code' => strtoupper($code), 'nameAr' => 'تصنيف '.self::n()]));
        $this->assertSame($code, $parent['code'], 'codes are stored lower-case');
        $this->assertTrue($parent['active']);
        $child = $this->expectOk($this->postAs('inv', '/api/categories', ['code' => $code.'-sub', 'nameAr' => 'فرعي '.self::n(), 'parentId' => $parent['code']]));
        $this->assertSame($parent['id'], $child['parentId']);
        $this->assertStringContainsString('تحت', $child['messageAr']);

        $this->expectRejected($this->postAs('inv', '/api/categories', ['code' => $code, 'nameAr' => 'x']), 'CATEGORY_CODE_TAKEN', [409]);
        $this->expectRejected($this->postAs('inv', '/api/categories', ['code' => $code.'-x', 'nameAr' => 'x', 'parentId' => 'nope']), 'BAD_PARENT', [400]);
        $this->expectRejected($this->patchAs('inv', "/api/categories/{$parent['id']}", ['parentId' => $child['id']]), 'CYCLIC_PARENT', [400]);
        $this->expectRejected($this->patchAs('inv', "/api/categories/{$parent['id']}", ['parentId' => $parent['id']]), 'SELF_PARENT', [400]);
        $this->expectRejected($this->patchAs('inv', "/api/categories/{$child['id']}", ['code' => $code]), 'CATEGORY_CODE_TAKEN', [409]);
        $this->expectRejected($this->getAs('inv', '/api/categories/no-such'), 'CATEGORY_NOT_FOUND', [404]);

        $tree = $this->expectOk($this->getAs('sales', '/api/categories'));
        $node = collect($tree)->firstWhere('id', $parent['id']);
        $this->assertSame([$child['id']], array_column($node['children'], 'id'));
        $this->assertSame(['products' => 0], $node['_count']);
        $this->assertSame([], $node['children'][0]['children']);
        $this->assertNull(collect($tree)->firstWhere('id', $child['id']), 'children are nested, not repeated at the root');

        $detail = $this->expectOk($this->getAs('sales', "/api/categories/{$code}"));
        $this->assertNull($detail['parent']);
        $this->assertSame(['products' => 0], $detail['children'][0]['_count']);
        $flat = collect($this->expectOk($this->getAs('sales', '/api/categories?flat=true')));
        $this->assertSame(['products' => 0, 'children' => 1], $flat->firstWhere('id', $parent['id'])['_count']);
        $this->assertSame(['id' => $parent['id'], 'code' => $code, 'nameAr' => $parent['nameAr']], $flat->firstWhere('id', $child['id'])['parent']);

        // Deactivating a parent deactivates its children; the tree can hide inactive nodes.
        $this->expectOk($this->patchAs('inv', "/api/categories/{$code}", ['active' => false, 'nameAr' => 'تصنيف موقوف '.self::n()]));
        $this->assertFalse(ProductCategory::findOrFail($child['id'])->active);
        $this->assertNotNull(collect($this->expectOk($this->getAs('sales', '/api/categories')))->firstWhere('id', $parent['id']));
        $this->assertNull(collect($this->expectOk($this->getAs('sales', '/api/categories?includeInactive=false')))->firstWhere('id', $parent['id']));
        $this->assertSame(1, AuditLog::where('entity_id', $parent['id'])->where('action', 'CATEGORY.UPDATE')->count());

        $this->expectRejected($this->postAs('sales', '/api/categories', ['code' => $code.'-no', 'nameAr' => 'مرفوض']), 'FORBIDDEN', [403]);
        $this->assertFalse(ProductCategory::where('code', $code.'-no')->exists());
    }

    // ───────────────────────────── units of measure ─────────────────────────────

    public function test_units_of_measure(): void
    {
        $code = 'u'.substr(self::n(), -5);
        $u = $this->expectOk($this->postAs('inv', '/api/uoms', ['code' => strtoupper($code), 'nameAr' => 'وحدة اختبار']));
        $this->assertSame($code, $u['code']);
        $this->assertSame('وحدة اختبار', $u['nameEn']);
        $this->expectRejected($this->postAs('inv', '/api/uoms', ['code' => $code, 'nameAr' => 'x']), 'UOM_CODE_TAKEN', [409]);
        $this->expectRejected($this->postAs('inv', '/api/uoms', ['code' => str_repeat('x', 17), 'nameAr' => 'x']), 'INVALID_INPUT', [400]);

        $updated = $this->expectOk($this->patchAs('inv', "/api/uoms/{$code}", ['nameAr' => 'وحدة معدلة', 'nameEn' => 'Spec unit']));
        $this->assertSame(['وحدة معدلة', 'Spec unit'], [$updated['nameAr'], $updated['nameEn']]);
        $this->expectRejected($this->patchAs('inv', '/api/uoms/no-such', ['nameAr' => 'x']), 'UOM_NOT_FOUND', [404]);
        $this->assertSame(2, AuditLog::where('entity_type', 'Uom')->where('entity_id', $u['id'])->count());

        $list = collect($this->expectOk($this->getAs('worker', '/api/uoms')));
        $this->assertSame(['products' => 0], $list->firstWhere('code', $code)['_count']);
        $this->assertGreaterThan(0, $list->firstWhere('code', 'ctn')['_count']['products']);
        $this->expectRejected($this->postAs('sales', '/api/uoms', ['code' => $code.'z', 'nameAr' => 'مرفوض']), 'FORBIDDEN', [403]);
    }
}
