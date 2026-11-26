<?php

namespace Tests\Feature\ProductRequests;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductSupplier;
use App\Models\StatusHistory;
use App\ProductRequests\Models\ProductRequest;
use App\ProductRequests\Models\ProductRequestEvent;
use App\ProductRequests\Models\ProductRequestPicture;
use App\Services\Core\SettingsService;
use Tests\ApiTestCase;

/**
 * A new-product request from the form to the catalogue: who may read and do what, raising and editing, the review
 * (taken, corrected, sent back, approved, rejected), the product the approval creates through the catalogue's own
 * service, the pictures and the comments — and every illegal move refused with its code.
 */
class ProductRequestsFlowTest extends ApiTestCase
{
    use ProductRequestFixtures;

    public function test_reading_needs_page_preq_and_every_move_its_permission(): void
    {
        $r = $this->waiting();
        $before = [ProductRequest::count(), ProductRequestEvent::count(), Product::count()];

        // no page.preq: the section is closed, reads included
        foreach (['summary', 'lookups', 'board', '', $r['number'], 'duplicates?nameAr=test', 'estimate?nameAr=test', "{$r['id']}/pictures/x"] as $path) {
            $this->expectRejected($this->getAs('wm', '/api/product-requests'.($path === '' ? '' : "/{$path}")), 'FORBIDDEN', [403]);
        }
        $this->expectRejected($this->postAs('wm', '/api/product-requests', ['nameAr' => 'محاولة']), 'FORBIDDEN', [403]);
        // page.preq + preq.create (sales): reads everything, raises — and reviews nothing
        foreach (['summary', 'lookups', 'board', '', $r['number'], 'duplicates?nameAr=test', 'estimate?nameAr=test'] as $path) {
            $this->expectOk($this->getAs('sales', '/api/product-requests'.($path === '' ? '' : "/{$path}")));
        }
        $this->expectRejected($this->getAs('sales', '/api/product-requests/next-sku'), 'FORBIDDEN', [403]);
        foreach ([['start-review', []], ['send-back', ['reason' => 'س']], ['approve', []], ['reject', ['reason' => 'س']], ['link-product', ['product' => 'x']]] as [$move, $body]) {
            $this->expectRejected($this->postAs('sales', "/api/product-requests/{$r['id']}/{$move}", $body), 'FORBIDDEN', [403]);
        }
        // page.preq + preq.review (gm): reviews — and raises nothing
        $this->expectRejected($this->postAs('gm', '/api/product-requests', ['nameAr' => 'محاولة']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('gm', "/api/product-requests/{$r['id']}/submit"), 'FORBIDDEN', [403]);
        $this->expectOk($this->getAs('gm', '/api/product-requests/next-sku'));

        $this->assertSame($before, [ProductRequest::count(), ProductRequestEvent::count(), Product::count()], 'a refused request changes nothing');
        $this->assertSame('submitted', $this->show($r['id'])['status']);
        $this->assertGreaterThan(0, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->where('username', 'sales')->where('entity_id', 'like', '%api/product-requests/%')->count(), 'the denied attempts are on the audit trail');
        $this->expectRejected($this->getAs('sales', '/api/product-requests/PREQ-NOPE'), 'PREQ_NOT_FOUND', [404]);
    }

    public function test_a_request_is_raised_edited_and_audited(): void
    {
        $tag = self::tag();
        $category = $this->category();
        $supplier = $this->supplier();

        // validation: a name is needed, the records named must exist, a product code must look like one
        $this->expectRejected($this->postAs('sales', '/api/product-requests', ['brand' => 'بلا اسم']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/product-requests', ['nameAr' => 'س', 'costPrice' => -1]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/product-requests', ['nameAr' => "صنف {$tag}", 'category' => 'no-such-category']), 'PREQ_CATEGORY_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('sales', '/api/product-requests', ['nameAr' => "صنف {$tag}", 'supplier' => 'no-such-supplier']), 'PREQ_SUPPLIER_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('sales', '/api/product-requests', ['nameAr' => "صنف {$tag}", 'uom' => 'no-such-unit']), 'PREQ_UOM_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('sales', '/api/product-requests', ['nameAr' => "صنف {$tag}", 'customer' => 'no-such-customer']), 'PREQ_CUSTOMER_UNKNOWN', [400]);
        $this->expectRejected($this->postAs('sales', '/api/product-requests', ['nameAr' => "صنف {$tag}", 'proposedSku' => 'كود عربي']), 'PREQ_BAD_SKU', [400]);
        $this->assertSame(0, ProductRequest::where('name_ar', "صنف {$tag}")->count());

        $r = $this->expectOk($this->postAs('sales', '/api/product-requests', [
            'nameAr' => "عصير برتقال طازج {$tag} (250ml*24pcs)", 'nameEn' => "Fresh orange juice {$tag} (250ml*24pcs)", 'brand' => 'الربيع', 'category' => $category->code, 'supplier' => $supplier->code,
            'uom' => 'ctn', 'packText' => '250ml*24pcs', 'packQty' => 24, 'costPrice' => 30.4567, 'salePrice' => 38, 'barcode' => " 6281 {$tag} ", 'storageClass' => 'chilled',
            'shelfLifeDays' => 270, 'tracksExpiry' => true, 'reason' => 'طلبه ثلاثة عملاء هذا الشهر', 'customerName' => 'مطعم الواحة',
        ]));
        $this->assertMatchesRegularExpression('/^PREQ-\d{4}-\d{5}$/', $r['number']);
        $this->assertSame(['draft', 'new', "عصير برتقال طازج {$tag} (250ml*24pcs)", 'الربيع', '250ml*24pcs', '30.4567', '38.00', 'SAR', "6281{$tag}", 'chilled'],
            [$r['status'], $r['requestType'], $r['nameAr'], $r['brand'], $r['packText'], $r['costPrice'], $r['salePrice'], $r['currency'], $r['barcode'], $r['storageClass']]);
        $this->assertSame([$category->id, $category->code, $supplier->id, 'ctn', 24, 270, true, 'مطعم الواحة', null], [$r['category']['id'], $r['category']['code'], $r['supplier']['id'], $r['uom']['code'], $r['packQty'],
            $r['shelfLifeDays'], $r['tracksExpiry'], $r['customerName'], $r['customer']]);
        $this->assertSame([$this->userId('sales'), 'sales', false], [$r['requestedBy']['id'], $r['requestedBy']['username'], $r['requestedBy']['external']]);
        $this->assertSame([null, null, false, true, null], [$r['sourceSystem'], $r['externalRef'], $r['owned'], $r['mine'], $r['product']]);
        $this->assertSame(['edit' => true, 'submit' => true, 'startReview' => false, 'sendBack' => false, 'approve' => false, 'reject' => false, 'cancel' => true, 'comment' => true, 'addPicture' => true, 'linkProduct' => false], $r['can']);
        $this->assertSame(['weightKg', 'lengthCm', 'widthCm', 'heightCm'], $r['missing'], 'what the catalogue still needs is said from the start');
        $this->assertSame(['name', 6.0, 'chilled'], [$r['estimate']['basis'], (float) $r['estimate']['weightKg'], $r['estimate']['storageClass']], 'the name gives a first weight (250 ml × 24) and a storage class («طازج»)');
        $this->assertSame(['created'], self::story($r));
        $this->assertSame('sales', AuditLog::where('action', 'PREQ.CREATE')->where('entity_id', $r['id'])->value('username'));

        // by id or by number; an edit changes what was sent, clears what was sent empty, leaves the rest
        $edited = $this->expectOk($this->patchAs('sales', "/api/product-requests/{$r['number']}", ['salePrice' => 39.5, 'brand' => null, 'shelfLifeDays' => 365, 'supplier' => null, 'supplierName' => 'مورد جديد لم يُسجَّل بعد', 'weightKg' => 6.4]));
        $this->assertSame(['39.50', null, 365, null, 'مورد جديد لم يُسجَّل بعد', 6.4, '30.4567', $category->id], [$edited['salePrice'], $edited['brand'], $edited['shelfLifeDays'], $edited['supplier'], $edited['supplierName'],
            $edited['weightKg'], $edited['costPrice'], $edited['category']['id']]);
        $this->assertSame(['created', 'edited'], self::story($edited));
        $changes = collect($edited['events'][0]['meta']['changes'])->keyBy('field');
        $this->assertEqualsCanonicalizing(['salePrice', 'brand', 'shelfLifeDays', 'supplier', 'supplierName', 'weightKg'], $changes->keys()->all());
        $this->assertSame([$supplier->name_ar, null], [$changes['supplier']['old'], $changes['supplier']['new']], 'a linked record is told by its name');
        $audit = AuditLog::where('action', 'PREQ.UPDATE')->where('entity_id', $r['id'])->firstOrFail();
        $this->assertStringContainsString('sale_price', (string) $audit->new_value);
        // the same edit again changes nothing and writes nothing
        $this->expectOk($this->patchAs('sales', "/api/product-requests/{$r['id']}", ['salePrice' => 39.5, 'shelfLifeDays' => 365]));
        $this->assertSame([1, 2], [AuditLog::where('action', 'PREQ.UPDATE')->where('entity_id', $r['id'])->count(), ProductRequestEvent::where('request_id', $r['id'])->count()]);
        $this->expectRejected($this->patchAs('sales', "/api/product-requests/{$r['id']}", ['nameAr' => '']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->patchAs('sales', "/api/product-requests/{$r['id']}", ['weightKg' => 0]), 'INVALID_INPUT', [400]);
        // somebody else who raises requests may not touch it — he does not even see a draft that is not his
        $this->expectRejected($this->patchAs('proc', "/api/product-requests/{$r['id']}", ['brand' => 'س']), 'PREQ_NOT_FOUND', [404]);
    }

    public function test_a_draft_is_its_authors_until_it_is_sent(): void
    {
        $draft = $this->raise();
        $numbers = fn (string $as) => array_column($this->expectOk($this->getAs($as, '/api/product-requests?pageSize=200&status=draft'))['items'], 'number');

        $this->assertContains($draft['number'], $numbers('sales'));
        $this->assertNotContains($draft['number'], $numbers('proc'), 'a draft is not a request yet: other people do not see it');
        $this->expectRejected($this->getAs('proc', "/api/product-requests/{$draft['id']}"), 'PREQ_NOT_FOUND', [404]);
        $this->assertContains($draft['number'], $numbers('inv'), 'the reviewers see it');
        // … but a draft is its author's: a reviewer neither edits nor sends nor cancels it
        $this->expectRejected($this->patchAs('gm', "/api/product-requests/{$draft['id']}", ['brand' => 'س']), 'PREQ_NOT_EDITABLE', [422]);
        $this->expectRejected($this->postAs('inv', "/api/product-requests/{$draft['id']}/submit"), 'PREQ_NOT_YOURS', [403]);
        $this->expectRejected($this->postAs('gm', "/api/product-requests/{$draft['id']}/cancel", ['reason' => 'تنظيف']), 'PREQ_NOT_YOURS', [403]);
        foreach (['start-review' => [], 'approve' => [], 'send-back' => ['reason' => 'س'], 'reject' => ['reason' => 'س']] as $move => $body) {
            $this->expectRejected($this->postAs('gm', "/api/product-requests/{$draft['id']}/{$move}", $body), 'PREQ_TRANSITION', [422]);
        }
        $this->assertSame('draft', $this->row($draft['id'])->status);

        $sent = $this->expectOk($this->postAs('sales', "/api/product-requests/{$draft['id']}/submit")); // action button: no body
        $this->assertSame(['submitted', 0], [$sent['status'], $sent['ageDays']]);
        $this->assertNotNull($sent['submittedAt']);
        $this->assertSame($draft['number'], $this->show($draft['id'], 'proc')['number'], 'once sent, everybody who opens the section sees it');
        $this->assertSame(['edit' => false, 'submit' => false, 'startReview' => false, 'sendBack' => false, 'approve' => false, 'reject' => false, 'cancel' => true, 'comment' => true, 'addPicture' => true, 'linkProduct' => false], $sent['can']);
        $this->expectRejected($this->patchAs('sales', "/api/product-requests/{$draft['id']}", ['brand' => 'س']), 'PREQ_NOT_EDITABLE', [422]);
        $this->expectRejected($this->patchAs('proc', "/api/product-requests/{$draft['id']}", ['brand' => 'س']), 'PREQ_NOT_YOURS', [403]);
        $this->expectRejected($this->postAs('sales', "/api/product-requests/{$draft['id']}/submit"), 'PREQ_TRANSITION', [422]);
        // the reviewers are told, by role
        $this->assertGreaterThan(0, Notification::where('role_key', 'inv')->where('entity_number', $draft['number'])->count());
        $this->assertGreaterThan(0, Notification::where('role_key', 'gm')->where('entity_number', $draft['number'])->count());
        $this->assertSame(0, Notification::where('role_key', 'proc')->where('entity_number', $draft['number'])->count());
    }

    public function test_the_approval_creates_the_product_through_the_catalogue(): void
    {
        $tag = self::tag();
        $category = $this->category();
        $supplier = $this->supplier();
        $r = $this->raise(['nameAr' => "زيت زيتون بكر {$tag} (1ltr*12pcs)", 'nameEn' => "Extra virgin olive oil {$tag} (1ltr*12pcs)", 'brand' => 'الجوف', 'category' => $category->id, 'supplier' => $supplier->id, 'uom' => 'ctn',
            'packQty' => 12, 'costPrice' => 210.456, 'salePrice' => 260, 'barcode' => "6281{$tag}", 'storageClass' => 'ambient', 'shelfLifeDays' => 540, 'tracksExpiry' => true, 'submit' => true]);
        $this->assertSame(['submitted', ['created', 'submitted']], [$r['status'], self::story($r)]);

        // taken into review: it is that reviewer's
        $inReview = $this->expectOk($this->postAs('inv', "/api/product-requests/{$r['id']}/start-review"));
        $this->assertSame(['in_review', $this->userId('inv'), false], [$inReview['status'], $inReview['reviewer']['id'], $inReview['can']['startReview']]);
        $this->assertSame(['edit' => true, 'submit' => false, 'startReview' => false, 'sendBack' => true, 'approve' => true, 'reject' => true, 'cancel' => true, 'comment' => true, 'addPicture' => true, 'linkProduct' => false], $inReview['can']);
        $this->expectRejected($this->postAs('gm', "/api/product-requests/{$r['id']}/start-review"), 'PREQ_TRANSITION', [422]);

        // the reviewer corrects a value before deciding: told apart in the history
        $corrected = $this->expectOk($this->patchAs('inv', "/api/product-requests/{$r['id']}", ['salePrice' => 255]));
        $this->assertSame(['255.00', 'corrected', 'inv'], [$corrected['salePrice'], $corrected['events'][0]['kind'], AuditLog::where('action', 'PREQ.CORRECT')->where('entity_id', $r['id'])->value('username')]);

        // the catalogue needs a weight and a carton: said before anything is created
        $products = Product::count();
        $incomplete = $this->expectRejected($this->postAs('inv', "/api/product-requests/{$r['id']}/approve"), 'PREQ_INCOMPLETE', [422]);
        $this->assertSame(['weightKg', 'lengthCm', 'widthCm', 'heightCm'], array_column($incomplete['details'], 'path'));
        $this->assertSame([$products, 'in_review'], [Product::count(), $this->row($r['id'])->status]);
        $this->expectRejected($this->postAs('inv', "/api/product-requests/{$r['id']}/approve", ['sku' => 'x', 'changes' => self::physical()]), 'PREQ_BAD_SKU', [400]);
        $this->assertNull($this->row($r['id'])->weight_kg, 'a refused approval leaves the corrections it carried unwritten too');

        // approved with the reviewer's last corrections: the product is created with the next code of the series
        $next = $this->expectOk($this->getAs('inv', '/api/product-requests/next-sku'))['sku'];
        $done = $this->expectOk($this->postAs('inv', "/api/product-requests/{$r['id']}/approve", ['changes' => self::physical(), 'note' => 'مطابق لعرض المورد']));
        $this->assertSame(['approved', $next, 'created', $this->userId('inv'), null], [$done['status'], $done['productSku'], $done['productLink'], $done['decidedBy']['id'], $done['ageDays']]);
        $this->assertSame(['created', 'submitted', 'review_started', 'corrected', 'corrected', 'approved'], self::story($done));
        $this->assertSame(['in_review', 'approved', 'مطابق لعرض المورد', $next], [$done['events'][0]['fromStatus'], $done['events'][0]['toStatus'], $done['events'][0]['note'], $done['events'][0]['meta']['sku']]);
        $this->assertEquals(array_fill_keys(['edit', 'submit', 'startReview', 'sendBack', 'approve', 'reject', 'cancel', 'addPicture', 'linkProduct'], false) + ['comment' => true], $done['can']);
        $this->assertSame([[], null], [$done['missing'], $done['estimate']]);

        // the product, as the catalogue's own service made it
        $p = Product::where('sku', $next)->with(['category', 'baseUom'])->firstOrFail();
        $this->assertSame($p->id, $done['product']['id']);
        $this->assertSame(["زيت زيتون بكر {$tag} (1ltr*12pcs)", "Extra virgin olive oil {$tag} (1ltr*12pcs)", 'الجوف', $category->id, 'ctn', 'ambient', true, 540, 12, '210.46', $supplier->name_ar, true],
            [$p->name_ar, $p->name_en, $p->brand, $p->category_id, $p->baseUom->code, $p->storage_class, $p->tracks_expiry, $p->shelf_life_days, $p->pack_qty, $p->purchase_price, $p->preferred_supplier_name, $p->active]);
        $this->assertSame([6.5, 30.0, 20.0, 15.0], [$p->weight_kg, $p->length_cm, $p->width_cm, $p->height_cm]);
        $this->assertSame([$p->id, true], [ProductBarcode::where('barcode', "6281{$tag}")->value('product_id'), (bool) ProductBarcode::where('barcode', "6281{$tag}")->value('is_primary')]);
        $this->assertTrue((bool) ProductSupplier::where('product_id', $p->id)->where('supplier_id', $supplier->id)->value('preferred'));
        $created = AuditLog::where('action', 'PRODUCT.CREATE')->where('entity_id', $p->id)->firstOrFail();
        $this->assertSame('inv', $created->username);
        $this->assertStringContainsString($r['number'], (string) $created->new_value, 'the product says which request it came from');
        // the request's own records
        $history = StatusHistory::where('entity_type', 'ProductRequest')->where('entity_id', $r['id'])->orderBy('at')->orderBy('id')->get();
        $this->assertSame([['draft', 'submitted'], ['submitted', 'in_review'], ['in_review', 'approved']], $history->map(fn ($h) => [$h->from_status, $h->to_status])->all());
        $this->assertSame(1, AuditLog::where('action', 'PREQ.APPROVE')->where('entity_id', $r['id'])->count());
        $this->assertSame(1, Notification::where('user_id', $this->userId('sales'))->where('entity_number', $r['number'])->where('text_ar', 'like', "%{$next}%")->count(), 'the person who asked is told the code');

        // final: nothing more happens to it
        foreach (['approve' => [], 'reject' => ['reason' => 'س'], 'send-back' => ['reason' => 'س'], 'start-review' => []] as $move => $body) {
            $this->expectRejected($this->postAs('gm', "/api/product-requests/{$r['id']}/{$move}", $body), 'PREQ_TRANSITION', [422]);
        }
        $this->expectRejected($this->postAs('sales', "/api/product-requests/{$r['id']}/cancel"), 'PREQ_TRANSITION', [422]);
        $this->expectRejected($this->patchAs('inv', "/api/product-requests/{$r['id']}", ['brand' => 'س']), 'PREQ_CLOSED', [422]);
        $this->expectRejected($this->postAs('inv', "/api/product-requests/{$r['id']}/link-product", ['product' => $next]), 'PREQ_PRODUCT_LINKED', [422]);
        $this->assertSame($products + 1, Product::count());

        // decided straight from the queue, with the code the reviewer chose
        $second = $this->waiting(['nameAr' => "خل تفاح {$tag}"]);
        $direct = $this->expectOk($this->postAs('gm', "/api/product-requests/{$second['id']}/approve", ['sku' => "qa-{$tag}"]));
        $this->assertSame(['approved', strtoupper("qa-{$tag}"), ['created', 'submitted', 'approved']], [$direct['status'], $direct['productSku'], self::story($direct)]);
        $this->assertSame('ambient', Product::where('sku', strtoupper("qa-{$tag}"))->value('storage_class'));
    }

    public function test_sent_back_corrected_and_sent_again(): void
    {
        $r = $this->waiting();
        $this->expectRejected($this->postAs('inv', "/api/product-requests/{$r['id']}/send-back"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('inv', "/api/product-requests/{$r['id']}/send-back", ['reason' => '   ']), 'INVALID_INPUT', [400]);

        $back = $this->expectOk($this->postAs('inv', "/api/product-requests/{$r['id']}/send-back", ['reason' => 'أضف الباركود وسعر التكلفة']));
        $this->assertSame(['returned', 'أضف الباركود وسعر التكلفة', 1, $this->userId('inv')], [$back['status'], $back['returnReason'], $back['returnsCount'], $back['reviewer']['id']]);
        $this->assertSame(['returned', 'أضف الباركود وسعر التكلفة', 'submitted', 'returned'], [$back['events'][0]['kind'], $back['events'][0]['note'], $back['events'][0]['fromStatus'], $back['events'][0]['toStatus']]);
        $this->assertSame(1, Notification::where('user_id', $this->userId('sales'))->where('entity_number', $r['number'])->where('text_ar', 'like', '%للتصحيح%')->count());
        // it is its author's again: the reviewers wait
        $this->expectRejected($this->patchAs('inv', "/api/product-requests/{$r['id']}", ['brand' => 'س']), 'PREQ_NOT_EDITABLE', [422]);
        foreach (['approve' => [], 'reject' => ['reason' => 'س'], 'start-review' => [], 'send-back' => ['reason' => 'س']] as $move => $body) {
            $this->expectRejected($this->postAs('inv', "/api/product-requests/{$r['id']}/{$move}", $body), 'PREQ_TRANSITION', [422]);
        }
        $mine = $this->show($r['id']);
        $this->assertSame([true, true, true], [$mine['can']['edit'], $mine['can']['submit'], $mine['can']['cancel']]);

        $tag = self::tag();
        $this->expectOk($this->patchAs('sales', "/api/product-requests/{$r['id']}", ['barcode' => "6289{$tag}", 'costPrice' => 12.5]));
        $again = $this->expectOk($this->postAs('sales', "/api/product-requests/{$r['id']}/submit"));
        $this->assertSame(['submitted', 1, ['created', 'submitted', 'returned', 'edited', 'resubmitted']], [$again['status'], $again['returnsCount'], self::story($again)]);
        $this->assertSame($r['submittedAt'], $again['submittedAt'], 'the first sending keeps its date');
        $this->assertSame(1, Notification::where('user_id', $this->userId('inv'))->where('entity_number', $r['number'])->count(), 'the reviewer who sent it back is told it came back');

        // sent back a second time by another reviewer, straight from the queue
        $twice = $this->expectOk($this->postAs('gm', "/api/product-requests/{$r['id']}/send-back", ['reason' => 'السعر أعلى من السوق']));
        $this->assertSame(['returned', 2, $this->userId('gm')], [$twice['status'], $twice['returnsCount'], $twice['reviewer']['id']]);
    }

    public function test_rejected_with_a_reason_and_cancelled(): void
    {
        // ── rejected: a reason is needed; the product it duplicates may be named
        $existing = $this->catalogueProduct();
        $r = $this->waiting();
        $this->expectRejected($this->postAs('gm', "/api/product-requests/{$r['id']}/reject"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('gm', "/api/product-requests/{$r['id']}/reject", ['reason' => 'موجود', 'existingProduct' => 'NO-SUCH-SKU']), 'PREQ_PRODUCT_NOT_FOUND', [404]);
        $this->assertSame('submitted', $this->row($r['id'])->status);
        $rejected = $this->expectOk($this->postAs('gm', "/api/product-requests/{$r['id']}/reject", ['reason' => 'الصنف موجود في الكتالوج', 'existingProduct' => strtolower($existing['sku'])]));
        $this->assertSame(['rejected', 'الصنف موجود في الكتالوج', $existing['sku'], 'existing', $this->userId('gm')],
            [$rejected['status'], $rejected['rejectReason'], $rejected['productSku'], $rejected['productLink'], $rejected['decidedBy']['id']]);
        $this->assertSame(['rejected', $existing['sku']], [$rejected['events'][0]['kind'], $rejected['events'][0]['meta']['existingProduct']]);
        $this->assertSame(1, Notification::where('user_id', $this->userId('sales'))->where('entity_number', $r['number'])->where('text_ar', 'like', "%{$existing['sku']}%")->count());
        foreach (['approve' => [], 'reject' => ['reason' => 'س'], 'send-back' => ['reason' => 'س']] as $move => $body) {
            $this->expectRejected($this->postAs('gm', "/api/product-requests/{$r['id']}/{$move}", $body), 'PREQ_TRANSITION', [422]);
        }
        $this->expectRejected($this->postAs('sales', "/api/product-requests/{$r['id']}/submit"), 'PREQ_TRANSITION', [422]);
        $this->expectRejected($this->postAs('gm', "/api/product-requests/{$r['id']}/link-product", ['product' => $existing['sku']]), 'PREQ_NOT_APPROVED', [422]);

        // ── cancelled by its author: no reason needed, at any open moment
        $own = $this->raise();
        $this->assertSame('cancelled', $this->expectOk($this->postAs('sales', "/api/product-requests/{$own['id']}/cancel"))['status']); // a draft
        $inReview = $this->waiting();
        $this->expectOk($this->postAs('inv', "/api/product-requests/{$inReview['id']}/start-review"));
        $withdrawn = $this->expectOk($this->postAs('sales', "/api/product-requests/{$inReview['id']}/cancel", ['reason' => 'وجدنا بديلًا']));
        $this->assertSame(['cancelled', 'وجدنا بديلًا', 'in_review', 'cancelled'], [$withdrawn['status'], $withdrawn['cancelReason'], $withdrawn['events'][0]['fromStatus'], $withdrawn['events'][0]['toStatus']]);
        $this->assertSame(1, Notification::where('user_id', $this->userId('inv'))->where('entity_number', $inReview['number'])->count(), 'the reviewer who had it is told');
        $this->expectRejected($this->postAs('sales', "/api/product-requests/{$inReview['id']}/cancel"), 'PREQ_TRANSITION', [422]);

        // ── cancelled by a reviewer: somebody else's request, so the reason is written; another author may not
        $theirs = $this->waiting();
        $this->expectRejected($this->postAs('proc', "/api/product-requests/{$theirs['id']}/cancel", ['reason' => 'س']), 'PREQ_NOT_YOURS', [403]);
        $this->expectRejected($this->postAs('gm', "/api/product-requests/{$theirs['id']}/cancel"), 'PREQ_REASON_REQUIRED', [400]);
        $closed = $this->expectOk($this->postAs('gm', "/api/product-requests/{$theirs['id']}/cancel", ['reason' => 'طلب مكرر أُرسل مرتين']));
        $this->assertSame(['cancelled', 'طلب مكرر أُرسل مرتين'], [$closed['status'], $closed['cancelReason']]);
        $this->assertSame(1, Notification::where('user_id', $this->userId('sales'))->where('entity_number', $theirs['number'])->where('text_ar', 'like', '%أُلغي%')->count());
        $last = StatusHistory::where('entity_type', 'ProductRequest')->where('entity_id', $theirs['id'])->orderByDesc('at')->orderByDesc('id')->first();
        $this->assertSame(['submitted', 'cancelled', 'gm', 'طلب مكرر أُرسل مرتين'], [$last->from_status, $last->to_status, $last->username, $last->note]);
    }

    public function test_whoever_raised_a_request_does_not_approve_it_unless_the_policy_allows(): void
    {
        $settings = $this->app->make(SettingsService::class);
        $settings->set('preq.allowSelfApproval', false);
        $own = $this->waiting([], 'inv'); // inv raises and reviews
        $detail = $this->show($own['id'], 'inv');
        $this->assertSame([false, true, true, true], [$detail['can']['approve'], $detail['selfApprovalBlocked'], $detail['can']['sendBack'], $detail['can']['edit']]);
        $this->assertFalse($this->expectOk($this->getAs('inv', '/api/product-requests/lookups'))['selfApproval']);
        $this->expectRejected($this->postAs('inv', "/api/product-requests/{$own['id']}/approve"), 'PREQ_SELF_APPROVAL', [422]);
        $this->assertSame(['submitted', null], [$this->row($own['id'])->status, $this->row($own['id'])->product_id]);
        // another reviewer approves it
        $done = $this->expectOk($this->postAs('gm', "/api/product-requests/{$own['id']}/approve"));
        $this->assertSame(['approved', false], [$done['status'], isset($done['events'][0]['meta']['selfApproved'])]);

        // the policy switched on (a small company where one person keeps the catalogue): allowed, and said in the history
        try {
            $settings->set('preq.allowSelfApproval', true);
            $second = $this->waiting([], 'inv');
            $this->assertTrue($this->show($second['id'], 'inv')['can']['approve']);
            $self = $this->expectOk($this->postAs('inv', "/api/product-requests/{$second['id']}/approve"));
            $this->assertSame(['approved', true], [$self['status'], $self['events'][0]['meta']['selfApproved']]);
        } finally {
            $settings->set('preq.allowSelfApproval', false);
        }
    }

    public function test_pictures_are_images_kept_by_the_file_store(): void
    {
        $r = $this->raise();
        $png = self::proofImage();

        $with = $this->expectOk($this->postAs('sales', "/api/product-requests/{$r['id']}/pictures", ['fileName' => 'عبوة المنتج.jpeg', 'base64' => $png]));
        $this->assertCount(1, $with['pictures']);
        $pic = $with['pictures'][0];
        $this->assertSame(['عبوة_المنتج.png', 'image/png', 'ops', true, "/product-requests/{$r['id']}/pictures/{$pic['id']}"], [$pic['fileName'], $pic['mime'], $pic['origin'], $pic['available'], $pic['url']],
            'the type is read from the bytes, whatever the name says');
        $this->assertSame([$pic['id'], 1], [$with['picture']['id'], $with['pictureCount']]);
        $this->assertArrayNotHasKey('storageKey', $pic, 'where a file lives never leaves the server');
        $this->assertSame('picture_added', $with['events'][0]['kind']);
        // read back through the request, by whoever may see it
        $file = $this->get("/api/product-requests/{$r['id']}/pictures/{$pic['id']}", ['Authorization' => 'Bearer '.$this->token('sales')]);
        $file->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", $file->getContent());
        $this->expectRejected($this->getAs('proc', "/api/product-requests/{$r['id']}/pictures/{$pic['id']}"), 'PREQ_NOT_FOUND', [404]); // somebody else's draft
        $this->expectRejected($this->getAs('sales', "/api/product-requests/{$r['id']}/pictures/nope"), 'PREQ_PICTURE_NOT_FOUND', [404]);

        // not an image, nothing at all
        $this->expectRejected($this->postAs('sales', "/api/product-requests/{$r['id']}/pictures", ['fileName' => 'x.pdf', 'base64' => base64_encode('%PDF-1.4 not a picture')]), 'ATTACHMENT_INVALID', [400]);
        $this->expectRejected($this->postAs('sales', "/api/product-requests/{$r['id']}/pictures", ['fileName' => 'x.png']), 'INVALID_INPUT', [400]);
        $this->assertSame(1, ProductRequestPicture::where('request_id', $r['id'])->count());

        // once sent: another author may not add to it, a reviewer may
        $this->expectOk($this->postAs('sales', "/api/product-requests/{$r['id']}/submit"));
        $this->expectRejected($this->postAs('proc', "/api/product-requests/{$r['id']}/pictures", ['base64' => $png]), 'PREQ_NOT_YOURS', [403]);
        $two = $this->expectOk($this->postAs('gm', "/api/product-requests/{$r['id']}/pictures", ['base64' => $png]));
        $this->assertSame(['عبوة_المنتج.png', 'picture.png'], array_column($two['pictures'], 'fileName'));
        $second = $two['pictures'][1]['id'];
        // the answer says who may take which picture off: a reviewer any, its author his own, anybody else none
        $this->assertSame([[true, true], [true, false], [false, false]], [array_column($two['pictures'], 'canRemove'), array_column($this->show($r['id'])['pictures'], 'canRemove'), array_column($this->show($r['id'], 'proc')['pictures'], 'canRemove')]);
        // taken off by whoever added it, or by a reviewer — the row and the file stay
        $this->expectRejected($this->postAs('proc', "/api/product-requests/{$r['id']}/pictures/{$second}/remove"), 'PREQ_PICTURE_NOT_YOURS', [403]);
        $this->expectRejected($this->postAs('sales', "/api/product-requests/{$r['id']}/pictures/{$second}/remove"), 'PREQ_PICTURE_NOT_YOURS', [403]);
        $one = $this->expectOk($this->postAs('sales', "/api/product-requests/{$r['id']}/pictures/{$pic['id']}/remove", ['reason' => 'صورة غير واضحة']));
        $this->assertSame([[$second], 'picture_removed', 'صورة غير واضحة'], [array_column($one['pictures'], 'id'), $one['events'][0]['kind'], $one['events'][0]['note']]);
        $this->assertSame(2, ProductRequestPicture::where('request_id', $r['id'])->count());
        $this->expectRejected($this->postAs('sales', "/api/product-requests/{$r['id']}/pictures/{$pic['id']}/remove"), 'PREQ_PICTURE_REMOVED', [422]);
        $this->expectRejected($this->getAs('sales', "/api/product-requests/{$r['id']}/pictures/{$pic['id']}"), 'PREQ_PICTURE_REMOVED', [404]);
        $this->get("/api/product-requests/{$r['id']}/pictures/{$pic['id']}", ['Authorization' => 'Bearer '.$this->token('gm')])->assertOk(); // a reviewer still reads what was removed

        // a closed request takes no more pictures
        $this->expectOk($this->postAs('gm', "/api/product-requests/{$r['id']}/reject", ['reason' => 'لا حاجة']));
        $this->expectRejected($this->postAs('gm', "/api/product-requests/{$r['id']}/pictures", ['base64' => $png]), 'PREQ_CLOSED', [422]);
    }

    public function test_comments_are_the_conversation_of_a_request(): void
    {
        $r = $this->waiting();
        $this->expectRejected($this->postAs('gm', "/api/product-requests/{$r['id']}/comments", ['text' => '']), 'INVALID_INPUT', [400]);
        $asked = $this->expectOk($this->postAs('gm', "/api/product-requests/{$r['id']}/comments", ['text' => 'هل المورد يوفّر عبوة 5 كجم؟']));
        $this->assertSame(['comment', 'هل المورد يوفّر عبوة 5 كجم؟', $this->userId('gm'), 'submitted'], [$asked['events'][0]['kind'], $asked['events'][0]['note'], $asked['events'][0]['byId'], $asked['status']]);
        $this->assertSame(1, Notification::where('user_id', $this->userId('sales'))->where('entity_number', $r['number'])->where('text_ar', 'like', '%ملاحظة%')->count(), 'the author is told');
        $this->expectOk($this->postAs('proc', "/api/product-requests/{$r['id']}/comments", ['text' => 'نشتريه من مورد آخر بسعر أقل']));
        // the conversation goes on after the decision
        $this->expectOk($this->postAs('gm', "/api/product-requests/{$r['id']}/reject", ['reason' => 'السعر غير مناسب']));
        $after = $this->expectOk($this->postAs('sales', "/api/product-requests/{$r['id']}/comments", ['text' => 'سنعيد الطلب بعرض جديد']));
        $this->assertSame(['rejected', 'comment'], [$after['status'], $after['events'][0]['kind']]);
        $this->assertSame(3, ProductRequestEvent::where('request_id', $r['id'])->where('kind', 'comment')->count());
    }
}
