<?php

namespace Tests\Feature\Procurement;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\InboundShipment;
use App\Models\IntegrationEvent;
use App\Models\Notification;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\StatusHistory;
use App\Services\Procurement\PrService;
use App\Support\AppError;
use Tests\ApiTestCase;

/** Acceptance story: PR → approval → RFQ → quotations → comparison → award → PO → approvals by tier → send → confirm. */
class ProcurementFlowTest extends ApiTestCase
{
    use ProcurementFixtures;

    public function test_full_cycle_from_requisition_to_confirmed_purchase_order(): void
    {
        $good = $this->makeSupplier(92);
        $slow = $this->makeSupplier(80);
        $product = $this->makeProduct(40);

        // ── PR: draft → submitted → approved ──
        $pr = $this->expectOk($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'ryd', 'needDate' => self::future(10), 'priority' => 'urgent', 'justification' => 'سيناريو القبول', 'lines' => [['sku' => $product->sku, 'qty' => 500]]]));
        $this->assertStringStartsWith('PR-', $pr['number']);
        $this->assertSame('draft', $pr['status']);
        $this->assertSame('RYD', $pr['warehouse']['code']);
        $this->assertSame([], $pr['approvals']);
        $this->assertSame('40.00', $pr['lines'][0]['estPrice'], 'estimate falls back to the product purchase price');
        $this->assertSame($product->sku, $pr['lines'][0]['product']['sku']);

        $submitted = $this->expectOk($this->postAs('wm', "/api/procurement/pr/{$pr['number']}/submit"));
        $this->assertSame('submitted', $submitted['status']);
        $this->assertSame(['proc'], array_column($submitted['approvals'], 'roleKey'));
        $this->expectRejected($this->postAs('wm', "/api/procurement/pr/{$pr['number']}/submit"), 'PR_TRANSITION', [422]);

        $this->expectRejected($this->postAs('driver', "/api/procurement/pr/{$pr['number']}/approve"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/pr/{$pr['number']}/to-rfq", ['closeDate' => self::future(3)]), 'PR_TRANSITION', [422]);
        $approved = $this->expectOk($this->postAs('proc', "/api/procurement/pr/{$pr['id']}/approve", ['note' => 'معتمد']));
        $this->assertSame('approved', $approved['status']);
        $this->assertNotNull($approved['approvedAt']);
        $this->assertSame(['approved', 'proc', 'معتمد'], [$approved['approvals'][0]['decision'], $approved['approvals'][0]['approver'], $approved['approvals'][0]['note']]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/pr/{$pr['number']}/approve"), 'PR_NOT_PENDING', [422]);

        $detail = $this->expectOk($this->getAs('wm', "/api/procurement/pr/{$pr['number']}"));
        $this->assertEquals(20000, $detail['estTotal']);
        $this->assertNull($detail['currentStep']);
        $this->assertNull($detail['rfq']);

        // ── RFQ from the PR, manual invitation ──
        $rfq = $this->expectOk($this->postAs('proc', "/api/procurement/pr/{$pr['number']}/to-rfq", ['closeDate' => self::future(3), 'supplierCodes' => [$good->code, $slow->code], 'invitedRule' => 'manual']));
        $this->assertStringStartsWith('RFQ-', $rfq['number']);
        $this->assertSame('open', $rfq['status']);
        $this->assertSame($pr['number'], $rfq['pr']['number']);
        $this->assertSame('RYD', $rfq['warehouse']['code'], 'delivery warehouse defaults to the PR warehouse');
        $this->assertEqualsCanonicalizing([$good->code, $slow->code], array_map(fn ($s) => $s['supplier']['code'], $rfq['suppliers']));
        $this->assertSame(500, $rfq['lines'][0]['qty']);
        $this->assertSame('converted', PurchaseRequisition::find($pr['id'])->status);
        $sent = IntegrationEvent::where('type', 'RFQ_SENT')->latest('id')->first();
        $this->assertSame($rfq['number'], $sent->payload['rfq']);

        // ── quotations ──
        $q1 = $this->expectOk($this->postAs('proc', '/api/procurement/quotations', ['supplierCode' => $good->code, 'rfqNumber' => $rfq['number'], 'supplierRef' => 'Q-A', 'validUntil' => self::future(30), 'leadDays' => 5, 'paymentTerms' => 'آجل 30 يومًا', 'attachmentName' => 'quote.pdf', 'lines' => [['sku' => $product->sku, 'qty' => 500, 'price' => 38, 'vatPct' => 15]]]));
        $this->assertStringStartsWith('SQ-', $q1['number']);
        $this->assertSame(['PDF', 'received', '38.00'], [$q1['attachmentType'], $q1['status'], $q1['lines'][0]['price']]);
        $this->assertSame('quoted', $this->expectOk($this->getAs('proc', "/api/procurement/rfq/{$rfq['number']}"))['status']);
        $q2 = $this->expectOk($this->postAs('proc', '/api/procurement/quotations', ['supplierCode' => $slow->code, 'rfqNumber' => $rfq['number'], 'supplierRef' => 'Q-B', 'validUntil' => self::future(30), 'leadDays' => 12, 'paymentTerms' => 'مقدم 100%', 'minOrder' => 900, 'attachmentName' => 'quote.JPEG', 'lines' => [['sku' => $product->sku, 'price' => 37.5]]]));
        $this->assertSame('JPG', $q2['attachmentType']);
        $this->assertEquals(15, $q2['lines'][0]['vatPct'], 'vatPct defaults to 15');

        $sqDetail = $this->expectOk($this->getAs('proc', "/api/procurement/quotations/{$q1['number']}"));
        $this->assertSame(['quote.pdf', 'application/pdf', 'integration_pending'], [$sqDetail['attachments'][0]['fileName'], $sqDetail['attachments'][0]['mime'], $sqDetail['attachments'][0]['status']]);
        $this->assertSame($rfq['number'], $sqDetail['rfq']['number']);

        // ── comparison: 50% price / 30% lead / 20% score ──
        $cmp = $this->expectOk($this->getAs('proc', "/api/procurement/rfq/{$rfq['number']}/comparison"));
        $this->assertSame(['rfq', 'need', 'invited', 'quotes', 'rec', 'weights'], array_keys($cmp));
        $this->assertSame('compared', $cmp['rfq']['status']);
        $this->assertSame(['price' => 0.5, 'lead' => 0.3, 'score' => 0.2], $cmp['weights']);
        $this->assertCount(2, $cmp['quotes']);
        $this->assertCount(2, $cmp['invited']);
        $this->assertSame(500, $cmp['need'][0]['qty']);
        $byId = array_column($cmp['quotes'], null, 'quotationId');
        $this->assertEquals(19000, $byId[$q1['id']]['price']);
        $this->assertEquals(38, $byId[$q1['id']]['unitPrice']);
        $this->assertEquals(18750, $byId[$q2['id']]['price']);
        // q1: 0.5×(19000/18750) + 0.3×1 + 0.2×1 = 1.01 ; q2: 0.5 + 0.3×(13/6) + 0.2×(92/80) = 1.38
        $this->assertEquals(1.01, $byId[$q1['id']]['weighted']);
        $this->assertEquals(1.38, $byId[$q2['id']]['weighted']);
        $this->assertSame($q1['id'], $cmp['rec']);
        $this->assertTrue($byId[$q1['id']]['rec']);
        $this->assertStringContainsString('توصية النظام', $byId[$q1['id']]['reasons'][0]);
        $this->assertContains('أسرع توريد', $byId[$q1['id']]['reasons']);
        $this->assertContains('السعر الأدنى', $byId[$q2['id']]['reasons']);
        $this->assertTrue($byId[$q2['id']]['moqExceedsNeed']);
        $this->assertTrue(collect($byId[$q2['id']]['reasons'])->contains(fn ($t) => str_contains($t, 'يفوق الحاجة')));
        $this->assertContains('دفع مقدم — أثر على السيولة', $byId[$q2['id']]['reasons']);
        $stored = $this->expectOk($this->getAs('proc', "/api/procurement/quotations?rfq={$rfq['number']}"));
        $this->assertSame(2, $stored['total']);
        $this->assertSame([$q1['number']], array_values(array_column(array_filter($stored['items'], fn ($q) => $q['recommended']), 'number')), 'the recommendation is persisted on the quotation');

        // ── award → PO pending (19,000 SAR → proc + finance) ──
        $this->expectRejected($this->postAs('wm', "/api/procurement/rfq/{$rfq['number']}/award", ['quotation' => $q1['number']]), 'FORBIDDEN', [403]);
        $award = $this->expectOk($this->postAs('proc', "/api/procurement/rfq/{$rfq['number']}/award", ['quotation' => $q1['number'], 'warehouseCode' => 'RYD', 'dueDate' => self::future(9)]));
        $this->assertSame(['rfq', 'po'], array_keys($award));
        $this->assertSame(['awarded', $q1['id']], [$award['rfq']['status'], $award['rfq']['awardedQuotationId']]);
        $this->assertSame(['awarded', 'lost'], [collect($award['rfq']['quotations'])->firstWhere('id', $q1['id'])['status'], collect($award['rfq']['quotations'])->firstWhere('id', $q2['id'])['status']]);
        $poNumber = $award['po']['number'];
        $this->assertStringStartsWith('PO-', $poNumber);
        $this->assertSame(['pending', '19000.00', $rfq['id'], "{$rfq['number']} / {$q1['number']}"], [$award['po']['status'], $award['po']['total'], $award['po']['rfqId'], $award['po']['reference']]);
        $this->assertSame('آجل 30 يومًا', $award['po']['paymentTerms'], 'payment terms come from the awarded quotation');
        $this->assertSame(['proc', 'finance'], array_column($award['po']['approvals'], 'roleKey'));
        $this->assertSame(['مدير المشتريات', 'المالية'], array_column($award['po']['approvals'], 'labelAr'));
        $this->expectRejected($this->postAs('proc', "/api/procurement/rfq/{$rfq['number']}/award", ['quotation' => $q2['number']]), 'RFQ_NOT_AWARDABLE', [422]);
        $this->assertSame($poNumber, $this->expectOk($this->getAs('wm', "/api/procurement/pr/{$pr['number']}"))['po']['number']);

        $po = $this->expectOk($this->getAs('proc', "/api/procurement/po/{$poNumber}"));
        $this->assertSame(500, array_sum(array_column($po['lines'], 'qty')));
        $this->assertSame(500, $po['openQty']);
        $this->assertSame('proc', $po['currentStep']['roleKey']);
        $this->assertSame($rfq['number'], $po['sources']['rfq']['number']);
        $this->assertSame([$pr['number']], array_column($po['sources']['prs'], 'number'));
        $this->assertSame([], $po['trace']);

        // ── approvals by tier ──
        $this->expectRejected($this->postAs('worker', "/api/procurement/po/{$poNumber}/approve"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/po/{$poNumber}/send"), 'PO_TRANSITION', [422]);
        $denied = $this->expectRejected($this->postAs('finance', "/api/procurement/po/{$poNumber}/approve"), 'PO_APPROVAL_ROLE', [422]);
        $this->assertSame(['step' => 1, 'requiredRole' => 'proc'], $denied['details']);
        $s1 = $this->expectOk($this->postAs('proc', "/api/procurement/po/{$poNumber}/approve", ['note' => 'اعتماد المشتريات']));
        $this->assertSame(['pending', 1], [$s1['status'], $s1['approvalStep']]);
        $s2 = $this->expectOk($this->postAs('finance', "/api/procurement/po/{$poNumber}/approve"));
        $this->assertSame(['approved', 99], [$s2['status'], $s2['approvalStep']]);
        $this->assertNotNull($s2['approvedAt']);
        $this->expectRejected($this->postAs('gm', "/api/procurement/po/{$poNumber}/approve"), 'PO_NOT_PENDING', [422]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/po/{$poNumber}/confirm"), 'PO_TRANSITION', [422]);

        // ── send: exactly one expected inbound shipment ──
        $sent = $this->expectOk($this->postAs('proc', "/api/procurement/po/{$poNumber}/send"));
        $this->assertSame(['po', 'shipment', 'created'], array_keys($sent));
        $this->assertTrue($sent['created']);
        $this->assertSame('sent', $sent['po']['status']);
        $this->assertNotNull($sent['po']['sentAt']);
        $this->assertStringStartsWith('SHP-', $sent['shipment']['number']);
        $this->assertSame('expected', $sent['shipment']['status']);
        $this->assertSame([500, $award['po']['lines'][0]['id'], $product->sku], [$sent['shipment']['lines'][0]['orderedQty'], $sent['shipment']['lines'][0]['poLineId'], $sent['shipment']['lines'][0]['product']['sku']]);
        $this->assertSame([], $sent['shipment']['grns']);
        $again = $this->expectOk($this->postAs('proc', "/api/procurement/po/{$poNumber}/send"));
        $this->assertFalse($again['created']);
        $this->assertSame($sent['shipment']['id'], $again['shipment']['id']);
        $this->assertSame(1, InboundShipment::where('po_id', $po['id'])->count());
        $this->assertSame(1, IntegrationEvent::where('type', 'PO_SENT')->where('payload->po', $poNumber)->count());
        $this->assertSame(1, Notification::where('entity_number', $poNumber)->where('role_key', 'wm')->count());

        // ── confirm, then no way back ──
        $confirmed = $this->expectOk($this->postAs('proc', "/api/procurement/po/{$poNumber}/confirm", ['supplierRef' => 'SO-7781']));
        $this->assertSame('confirmed', $confirmed['status']);
        $this->assertNotNull($confirmed['confirmedAt']);
        $this->assertStringContainsString('تأكيد المورد: SO-7781', $confirmed['notes']);
        $this->expectRejected($this->postAs('proc', "/api/procurement/po/{$poNumber}/cancel", ['reason' => 'تغيير الخطة']), 'PO_CANCEL_AFTER_SEND', [422]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/po/{$poNumber}/confirm"), 'PO_TRANSITION', [422]);
        $stillOne = $this->expectOk($this->postAs('proc', "/api/procurement/po/{$poNumber}/send"));
        $this->assertFalse($stillOne['created']);
        $this->assertSame('confirmed', PurchaseOrder::find($po['id'])->status);

        $final = $this->expectOk($this->getAs('proc', "/api/procurement/po/{$poNumber}"));
        $this->assertSame([['shipment' => $sent['shipment']['number'], 'status' => 'expected', 'eta' => $final['dueDate'], 'grns' => []]], $final['trace']);
        $this->assertNull($final['currentStep']);

        // ── audit trail ──
        $this->assertSame(['pending', 'approved', 'sent', 'confirmed'], StatusHistory::where('entity_type', 'PurchaseOrder')->where('entity_id', $po['id'])->orderBy('at')->orderBy('id')->pluck('to_status')->all());
        $this->assertSame(2, AuditLog::where('entity_type', 'PurchaseOrder')->where('entity_id', $po['id'])->where('action', 'PO.APPROVE')->count());
        $this->assertSame(['open', 'quoted', 'compared', 'awarded'], StatusHistory::where('entity_type', 'Rfq')->where('entity_id', $rfq['id'])->orderBy('at')->orderBy('id')->pluck('to_status')->all());
        $this->assertSame(['draft', 'submitted', 'approved', 'converted'], StatusHistory::where('entity_type', 'PurchaseRequisition')->where('entity_id', $pr['id'])->orderBy('at')->orderBy('id')->pluck('to_status')->all());
        $this->assertGreaterThanOrEqual(5, ActivityLog::where('entity_number', $poNumber)->count());
    }

    public function test_requisition_rejection_conversion_to_po_and_role_checked_steps(): void
    {
        $supplier = $this->makeSupplier(90);
        $product = $this->makeProduct(0);

        // reject needs a reason, and closes the PR
        $pr = $this->expectOk($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'RYD', 'justification' => 'طلب سيُرفض', 'lines' => [['sku' => $product->sku, 'qty' => 10, 'price' => 12.345]]]));
        $this->assertSame(['normal', '12.35'], [$pr['priority'], $pr['lines'][0]['estPrice']]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/pr/{$pr['number']}/approve"), 'PR_NOT_PENDING', [422]);
        $this->expectOk($this->postAs('wm', "/api/procurement/pr/{$pr['number']}/submit"));
        $this->expectRejected($this->postAs('proc', "/api/procurement/pr/{$pr['number']}/reject"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/pr/{$pr['number']}/reject", ['reason' => 'لا']), 'INVALID_INPUT', [400]);
        $rejected = $this->expectOk($this->postAs('proc', "/api/procurement/pr/{$pr['number']}/reject", ['reason' => 'خارج الميزانية']));
        $this->assertSame(['rejected', 'rejected', 'خارج الميزانية'], [$rejected['status'], $rejected['approvals'][0]['decision'], $rejected['approvals'][0]['note']]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/pr/{$pr['number']}/to-po", ['supplierCode' => $supplier->code, 'dueDate' => self::future(5)]), 'PR_TRANSITION', [422]);

        // a step can only be taken by its role, even when the caller holds the permission
        $pr2 = $this->expectOk($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'RYD', 'justification' => 'نافد تمامًا', 'lines' => [['sku' => $product->sku, 'qty' => 30]]]));
        $this->expectOk($this->postAs('wm', "/api/procurement/pr/{$pr2['number']}/submit"));
        try {
            $this->app->make(PrService::class)->approve($this->fakeUser('wm', ['wm'], ['pr.approve']), $pr2['id']);
            $this->fail('a non-proc approver must be refused');
        } catch (AppError $e) {
            $this->assertSame(['PR_APPROVAL_ROLE', 'BUSINESS_RULE'], [$e->errorCode, $e->category]);
        }
        $this->expectOk($this->postAs('admin', "/api/procurement/pr/{$pr2['number']}/approve")); // super passes every step

        // the product has no price → the conversion must be priced
        $this->expectRejected($this->postAs('proc', "/api/procurement/pr/{$pr2['number']}/to-po", ['supplierCode' => $supplier->code, 'dueDate' => self::future(5)]), 'PRICE_REQUIRED', [400]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/pr/{$pr2['number']}/to-po", ['dueDate' => self::future(5)]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/pr/{$pr2['number']}/to-po", ['supplierCode' => 'SUP-NOPE', 'dueDate' => self::future(5), 'prices' => [['sku' => $product->sku, 'price' => 200]]]), 'SUPPLIER_NOT_FOUND', [404]);
        $this->assertSame('approved', PurchaseRequisition::find($pr2['id'])->status, 'a failed conversion changes nothing');
        $po = $this->expectOk($this->postAs('proc', "/api/procurement/pr/{$pr2['number']}/to-po", ['supplierCode' => $supplier->code, 'dueDate' => self::future(5), 'prices' => [['sku' => $product->sku, 'price' => 200]]]));
        $this->assertSame(['pending', '6000.00', $pr2['number']], [$po['status'], $po['total'], $po['reference']]);
        $this->assertCount(2, $po['approvals']);
        $this->assertSame('آجل 30 يومًا', $po['paymentTerms'], 'payment terms default to the supplier terms');
        $after = $this->expectOk($this->getAs('wm', "/api/procurement/pr/{$pr2['id']}"));
        $this->assertSame(['converted', $po['number']], [$after['status'], $after['po']['number']]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/pr/{$pr2['number']}/to-po", ['supplierCode' => $supplier->code, 'dueDate' => self::future(5)]), 'PR_TRANSITION', [422]);
    }

    public function test_requisition_validation_permission_and_listing(): void
    {
        $product = $this->makeProduct(15);
        $inactive = $this->makeProduct(15, 0, ['active' => false]);
        $total = PurchaseRequisition::count();

        $this->expectRejected($this->postAs('sales', '/api/procurement/pr', ['warehouseCode' => 'RYD', 'justification' => 'غير مصرح', 'lines' => [['sku' => $product->sku, 'qty' => 1]]]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'RYD', 'lines' => [['sku' => $product->sku, 'qty' => 1]]]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'RYD', 'justification' => 'x', 'lines' => []]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'RYD', 'justification' => 'x', 'lines' => [['sku' => $product->sku, 'qty' => 0]]]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'RYD', 'justification' => 'x', 'priority' => 'asap', 'lines' => [['sku' => $product->sku, 'qty' => 1]]]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'RYD', 'justification' => 'x', 'needDate' => '25/09/2026', 'lines' => [['sku' => $product->sku, 'qty' => 1]]]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'NOPE', 'justification' => 'x', 'lines' => [['sku' => $product->sku, 'qty' => 1]]]), 'WAREHOUSE_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'RYD', 'justification' => 'x', 'lines' => [['sku' => 'NO-SUCH-SKU', 'qty' => 1]]]), 'PRODUCT_NOT_FOUND', [400]);
        $this->expectRejected($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'RYD', 'justification' => 'x', 'lines' => [['sku' => $inactive->sku, 'qty' => 1]]]), 'PRODUCT_INACTIVE', [422]);
        $this->expectRejected($this->getAs('wm', '/api/procurement/pr/PR-0000-NOPE'), 'PR_NOT_FOUND', [404]);
        $this->assertSame($total, PurchaseRequisition::count(), 'rejected requests create nothing');

        $pr = $this->expectOk($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'JED', 'priority' => 'low', 'justification' => 'قائمة '.$product->sku, 'lines' => [['sku' => $product->sku, 'qty' => 4], ['sku' => $product->sku, 'qty' => 6, 'price' => 10]]]));
        $this->expectOk($this->postAs('wm', "/api/procurement/pr/{$pr['number']}/submit"));
        $list = $this->expectOk($this->getAs('sales', "/api/procurement/pr?status=submitted,review&warehouse=jed&priority=low&q={$product->sku}&pageSize=5"));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($list));
        $this->assertSame([1, 1, 5], [$list['total'], $list['page'], $list['pageSize']]);
        $this->assertSame($pr['number'], $list['items'][0]['number']);
        $this->assertEquals(120, $list['items'][0]['estTotal']);
        $this->assertSame('proc', $list['items'][0]['currentStep']['roleKey']);
        $this->assertSame([1, 2], array_column($list['items'][0]['lines'], 'lineNo'));
        $this->assertSame(0, $this->expectOk($this->getAs('sales', "/api/procurement/pr?status=draft&q={$product->sku}"))['total']);
    }
}
