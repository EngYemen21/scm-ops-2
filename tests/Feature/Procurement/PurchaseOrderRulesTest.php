<?php

namespace Tests\Feature\Procurement;

use App\Models\AuditLog;
use App\Models\InboundShipment;
use App\Models\PoApproval;
use App\Models\PurchaseOrder;
use App\Models\StatusHistory;
use App\Services\Core\SettingsService;
use App\Services\Procurement\PoService;
use App\Support\AppError;
use Tests\ApiTestCase;

/** Purchase-order business rules: approval tiers, role-checked steps, supplier score gate, send idempotency, cancel rules. */
class PurchaseOrderRulesTest extends ApiTestCase
{
    use ProcurementFixtures;

    public function test_approval_tiers_come_from_the_settings(): void
    {
        $supplier = $this->makeSupplier(97);
        $product = $this->makeProduct(200);

        $small = $this->makePo($supplier->code, $product->sku, 20, 200);
        $this->assertSame(['4000.00', 'pending', 0, 20], [$small['total'], $small['status'], $small['approvalStep'], $small['openQty']]);
        $this->assertSame(['proc'], array_column($small['approvals'], 'roleKey'));
        $this->assertSame(['proc', 'Procurement', 'مدير المشتريات'], [$small['createdBy'], $small['approvals'][0]['labelEn'], $small['approvals'][0]['labelAr']]);

        $exactlyAtTheLimit = $this->makePo($supplier->code, $product->sku, 25, 200);
        $this->assertSame(['proc'], array_column($exactlyAtTheLimit['approvals'], 'roleKey'), 'total = max stays in the tier');

        $medium = $this->makePo($supplier->code, $product->sku, 100, 200);
        $this->assertSame('20000.00', $medium['total']);
        $this->assertSame(['proc', 'finance'], array_column($medium['approvals'], 'roleKey'));

        $large = $this->makePo($supplier->code, $product->sku, 150, 200);
        $this->assertSame('30000.00', $large['total']);
        $this->assertSame(['proc', 'finance', 'gm'], array_column($large['approvals'], 'roleKey'));
        $this->assertSame(['pending', 'pending', 'pending'], array_column($large['approvals'], 'decision'));
        $this->assertSame([1, 2, 3], array_column($large['approvals'], 'step'));

        // an administrator changes the policy → new POs follow it
        $settings = $this->app->make(SettingsService::class);
        $original = $settings->get('procurement.approvalTiers');
        $settings->set('procurement.approvalTiers', [['max' => 1000, 'roles' => ['proc']], ['max' => null, 'roles' => ['gm', 'finance']]]);
        try {
            $custom = $this->makePo($supplier->code, $product->sku, 20, 200);
            $this->assertSame(['gm', 'finance'], array_column($custom['approvals'], 'roleKey'));
        } finally {
            $settings->set('procurement.approvalTiers', $original);
        }

        // rounding like the reference: price is rounded to 2 decimals before the total
        $rounded = $this->makePo($supplier->code, $product->sku, 3, 10.005);
        $this->assertSame(['30.03', '10.01'], [$rounded['total'], $rounded['lines'][0]['price']]);
    }

    public function test_each_step_needs_its_role_and_the_chain_ends_approved(): void
    {
        $supplier = $this->makeSupplier(97);
        $product = $this->makeProduct(200);
        $po = $this->makePo($supplier->code, $product->sku, 150, 200); // 3 steps
        $url = "/api/procurement/po/{$po['number']}/approve";

        $this->expectRejected($this->postAs('worker', $url), 'FORBIDDEN', [403]);
        $this->assertSame('pending', PurchaseOrder::find($po['id'])->status);
        $this->assertSame(0, PoApproval::where('po_id', $po['id'])->where('decision', '!=', 'pending')->count(), 'a denied attempt changes nothing');

        $body = $this->expectRejected($this->postAs('finance', $url), 'PO_APPROVAL_ROLE', [422]);
        $this->assertSame('BUSINESS_RULE', $body['category']);
        $this->expectRejected($this->postAs('gm', $url), 'PO_APPROVAL_ROLE', [422]);
        $s1 = $this->expectOk($this->postAs('proc', $url, ['note' => 'ok']));
        $this->assertSame(['pending', 1], [$s1['status'], $s1['approvalStep']]);
        $this->assertSame(['approved', 'proc', 'ok'], [$s1['approvals'][0]['decision'], $s1['approvals'][0]['approver'], $s1['approvals'][0]['note']]);
        $this->assertNotNull($s1['approvals'][0]['decidedAt']);

        $this->expectRejected($this->postAs('proc', $url), 'PO_APPROVAL_ROLE', [422]); // step 2 belongs to finance
        $s2 = $this->expectOk($this->postAs('finance', $url));
        $this->assertSame(['pending', 2], [$s2['status'], $s2['approvalStep']]);
        $pendingForGm = $this->expectOk($this->getAs('gm', "/api/procurement/po?step=gm&supplier={$supplier->code}"));
        $this->assertSame([$po['number']], array_column($pendingForGm['items'], 'number'));
        $this->assertSame(0, $this->expectOk($this->getAs('gm', "/api/procurement/po?step=proc&supplier={$supplier->code}"))['total']);
        $s3 = $this->expectOk($this->postAs('gm', $url));
        $this->assertSame(['approved', 99], [$s3['status'], $s3['approvalStep']]);
        $this->assertNotNull($s3['approvedAt']);

        $this->assertSame(3, AuditLog::where('entity_type', 'PurchaseOrder')->where('entity_id', $po['id'])->where('action', 'PO.APPROVE')->count());
        $this->assertSame(['pending', 'approved'], StatusHistory::where('entity_type', 'PurchaseOrder')->where('entity_id', $po['id'])->orderBy('at')->orderBy('id')->pluck('to_status')->all());
        $this->expectRejected($this->postAs('proc', $url), 'PO_NOT_PENDING', [422]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/po/{$po['number']}/reject", ['reason' => 'متأخر جدًا']), 'PO_NOT_PENDING', [422]);

        // super passes any step
        $other = $this->makePo($supplier->code, $product->sku, 100, 200);
        $this->expectOk($this->postAs('admin', "/api/procurement/po/{$other['id']}/approve"));
        $done = $this->expectOk($this->postAs('admin', "/api/procurement/po/{$other['id']}/approve"));
        $this->assertSame('approved', $done['status']);
    }

    public function test_reject_cancels_the_po_with_the_reason(): void
    {
        $supplier = $this->makeSupplier(97);
        $product = $this->makeProduct(200);
        $po = $this->makePo($supplier->code, $product->sku, 10, 200);
        $url = "/api/procurement/po/{$po['number']}/reject";

        $this->expectRejected($this->postAs('proc', $url), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', $url, ['reason' => '  x ']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('finance', $url, ['reason' => 'ليست خطوتي']), 'PO_APPROVAL_ROLE', [422]);
        $rejected = $this->expectOk($this->postAs('proc', $url, ['reason' => 'السعر أعلى من السوق']));
        $this->assertSame('cancelled', $rejected['status']);
        $this->assertNotNull($rejected['closedAt']);
        $this->assertSame(['rejected', 'السعر أعلى من السوق', 'proc'], [$rejected['approvals'][0]['decision'], $rejected['approvals'][0]['note'], $rejected['approvals'][0]['approver']]);
        $this->assertSame(1, AuditLog::where('entity_id', $po['id'])->where('action', 'PO.REJECT')->count());
        $this->expectRejected($this->postAs('proc', "/api/procurement/po/{$po['number']}/approve"), 'PO_NOT_PENDING', [422]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/po/{$po['number']}/send"), 'PO_TRANSITION', [422]);
    }

    public function test_supplier_score_gate_and_documented_override(): void
    {
        $low = $this->makeSupplier(63);
        $borderline = $this->makeSupplier(65);
        $inactive = $this->makeSupplier(90, ['active' => false]);
        $product = $this->makeProduct(200);
        $count = PurchaseOrder::count();

        $body = $this->expectRejected($this->postAs('proc', '/api/procurement/po', ['supplierCode' => $low->code, 'warehouseCode' => 'RYD', 'dueDate' => self::future(7), 'lines' => [['sku' => $product->sku, 'qty' => 10, 'price' => 200]]]), 'SUPPLIER_SCORE_LOW', [422]);
        $this->assertSame('BUSINESS_RULE', $body['category']);
        $this->assertEquals(['score' => 63, 'minScore' => 65], $body['details']);
        $this->expectRejected($this->postAs('proc', '/api/procurement/po', ['supplierCode' => $inactive->code, 'warehouseCode' => 'RYD', 'dueDate' => self::future(7), 'lines' => [['sku' => $product->sku, 'qty' => 10, 'price' => 200]]]), 'SUPPLIER_INACTIVE', [422]);
        $this->assertSame($count, PurchaseOrder::count());

        // the override needs po.approve — a buyer who can only create POs is refused
        try {
            $this->app->make(PoService::class)->create($this->fakeUser('wm', ['wm'], ['po.create']), ['supplierCode' => $low->code, 'warehouseCode' => 'RYD', 'dueDate' => self::future(7), 'lines' => [['sku' => $product->sku, 'qty' => 10, 'price' => 200]], 'overrideSupplierScore' => true, 'overrideReason' => 'x']);
            $this->fail('override without po.approve must be refused');
        } catch (AppError $e) {
            $this->assertSame(['SUPPLIER_SCORE_OVERRIDE_DENIED', 'FORBIDDEN', 403], [$e->errorCode, $e->category, $e->status()]);
        }
        $this->assertSame($count, PurchaseOrder::count());

        $po = $this->makePo($low->code, $product->sku, 10, 200, ['overrideSupplierScore' => true, 'overrideReason' => 'المورد الوحيد للصنف']);
        $this->assertSame('pending', $po['status']);
        $audit = AuditLog::where('entity_type', 'PurchaseOrder')->where('entity_id', $po['id'])->where('action', 'PO.SUPPLIER_SCORE_OVERRIDE')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('المورد الوحيد للصنف', $audit->new_value);
        $this->assertSame('63 < 65', $audit->old_value);

        $this->assertSame('pending', $this->makePo($borderline->code, $product->sku, 1, 200)['status'], 'score = minimum is accepted');
    }

    public function test_send_creates_the_inbound_shipment_exactly_once(): void
    {
        $supplier = $this->makeSupplier(97);
        $product = $this->makeProduct(200);
        $other = $this->makeProduct(50);
        $po = $this->expectOk($this->postAs('proc', '/api/procurement/po', ['supplierCode' => $supplier->code, 'warehouseCode' => 'JED', 'dueDate' => self::future(6), 'lines' => [['sku' => $product->sku, 'qty' => 20, 'price' => 200], ['sku' => $other->sku, 'qty' => 5, 'price' => 50]]]));
        $send = "/api/procurement/po/{$po['number']}/send";

        $this->expectRejected($this->postAs('proc', $send), 'PO_TRANSITION', [422]); // not approved yet
        $this->expectRejected($this->postAs('finance', $send), 'FORBIDDEN', [403]);
        $this->assertSame(0, InboundShipment::where('po_id', $po['id'])->count());
        $this->expectOk($this->postAs('proc', "/api/procurement/po/{$po['number']}/approve"));

        $first = $this->expectOk($this->postAs('proc', $send));
        $this->assertTrue($first['created']);
        $this->assertSame(['sent', 'expected', $po['warehouseId'], $po['supplierId']], [$first['po']['status'], $first['shipment']['status'], $first['shipment']['warehouseId'], $first['shipment']['supplierId']]);
        $this->assertSame($first['po']['dueDate'], $first['shipment']['eta']);
        $this->assertSame([[1, 20, $po['lines'][0]['id']], [2, 5, $po['lines'][1]['id']]], array_map(fn ($l) => [$l['lineNo'], $l['orderedQty'], $l['poLineId']], $first['shipment']['lines']));

        $second = $this->expectOk($this->postAs('proc', $send));
        $third = $this->expectOk($this->postAs('proc', $send, [], ['Idempotency-Key' => 'send-'.self::uid()]));
        $this->assertSame([false, false], [$second['created'], $third['created']]);
        $this->assertSame([$first['shipment']['id'], $first['shipment']['id']], [$second['shipment']['id'], $third['shipment']['id']]);
        $this->assertSame(1, InboundShipment::where('po_id', $po['id'])->count());
        $this->assertSame(1, AuditLog::where('action', 'SHIPMENT.EXPECTED')->where('new_value', 'like', "%{$po['number']}%")->count());
        $this->assertSame(['expected'], StatusHistory::where('entity_type', 'InboundShipment')->where('entity_id', $first['shipment']['id'])->pluck('to_status')->all());

        $full = $this->expectOk($this->getAs('wm', "/api/procurement/po/{$po['number']}"));
        $this->assertCount(1, $full['trace']);
        $this->assertSame($first['shipment']['number'], $full['trace'][0]['shipment']);
        $this->assertSame($first['shipment']['number'], $full['shipments'][0]['number']);
        $this->assertCount(2, $full['shipments'][0]['lines']);

        $listed = $this->expectOk($this->getAs('wm', "/api/procurement/po?status=sent&supplier={$supplier->code}&warehouse=jed"));
        $this->assertSame(1, $listed['total']);
        $this->assertSame(['lines' => 2, 'grns' => 0], $listed['items'][0]['_count']);
        $this->assertSame([['number' => $first['shipment']['number'], 'status' => 'expected', 'eta' => $first['shipment']['eta']]], $listed['items'][0]['shipments']);
        $this->assertNull($listed['items'][0]['currentStep']);
    }

    public function test_cancel_rules(): void
    {
        $supplier = $this->makeSupplier(97);
        $product = $this->makeProduct(200);
        $po = $this->makePo($supplier->code, $product->sku, 100, 200); // proc + finance
        $cancel = "/api/procurement/po/{$po['number']}/cancel";

        $this->expectRejected($this->postAs('proc', $cancel), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', $cancel, ['reason' => 'لا']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('finance', $cancel, ['reason' => 'تغيير الخطة']), 'FORBIDDEN', [403]);
        $this->assertSame('pending', PurchaseOrder::find($po['id'])->status);

        $this->expectOk($this->postAs('proc', "/api/procurement/po/{$po['number']}/approve"));
        $cancelled = $this->expectOk($this->postAs('proc', $cancel, ['reason' => 'تغيير الخطة']));
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertNotNull($cancelled['closedAt']);
        $this->assertSame(['approved', 'cancelled'], array_column($cancelled['approvals'], 'decision'), 'only the pending steps are cancelled');
        $this->assertSame('تغيير الخطة', $cancelled['approvals'][1]['note']);
        $this->expectRejected($this->postAs('proc', $cancel, ['reason' => 'مرة أخرى']), 'PO_TRANSITION', [422]);
        $this->expectRejected($this->postAs('finance', "/api/procurement/po/{$po['number']}/approve"), 'PO_NOT_PENDING', [422]);

        // approved (not sent yet) can still be cancelled; sent cannot
        $approved = $this->makePo($supplier->code, $product->sku, 5, 200);
        $this->expectOk($this->postAs('proc', "/api/procurement/po/{$approved['number']}/approve"));
        $this->assertSame('cancelled', $this->expectOk($this->postAs('proc', "/api/procurement/po/{$approved['number']}/cancel", ['reason' => 'ألغى المورد الصنف']))['status']);

        $sent = $this->makePo($supplier->code, $product->sku, 5, 200);
        $this->expectOk($this->postAs('proc', "/api/procurement/po/{$sent['number']}/approve"));
        $this->expectOk($this->postAs('proc', "/api/procurement/po/{$sent['number']}/send"));
        $this->expectRejected($this->postAs('proc', "/api/procurement/po/{$sent['number']}/cancel", ['reason' => 'تغيير الخطة']), 'PO_CANCEL_AFTER_SEND', [422]);
        $this->assertSame('sent', PurchaseOrder::find($sent['id'])->status);
    }

    public function test_validation_permission_idempotency_and_not_found(): void
    {
        $supplier = $this->makeSupplier(97);
        $product = $this->makeProduct(200);
        $valid = ['supplierCode' => $supplier->code, 'warehouseCode' => 'RYD', 'dueDate' => self::future(7), 'lines' => [['sku' => $product->sku, 'qty' => 2, 'price' => 10]]];
        $count = PurchaseOrder::count();

        $before = AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count();
        $this->expectRejected($this->postAs('sales', '/api/procurement/po', $valid), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('wm', '/api/procurement/po', $valid), 'FORBIDDEN', [403]);
        $this->assertSame($before + 2, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count());

        foreach ([
            ['supplierCode' => null], ['warehouseCode' => null], ['dueDate' => null], ['dueDate' => 'غدًا'], ['lines' => []],
            ['lines' => [['sku' => $product->sku, 'qty' => 2]]], ['lines' => [['sku' => $product->sku, 'qty' => 2, 'price' => 0]]],
            ['lines' => [['sku' => $product->sku, 'qty' => 1.5, 'price' => 10]]], ['lines' => [['qty' => 2, 'price' => 10]]], ['overrideSupplierScore' => 'maybe'],
        ] as $broken) {
            $body = $this->expectRejected($this->postAs('proc', '/api/procurement/po', array_merge($valid, $broken)), 'INVALID_INPUT', [400]);
            $this->assertSame('VALIDATION', $body['category']);
            $this->assertNotEmpty($body['details']);
        }
        $this->expectRejected($this->postAs('proc', '/api/procurement/po', ['supplierCode' => 'SUP-NOPE'] + $valid), 'SUPPLIER_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/po', ['warehouseCode' => 'XXX'] + $valid), 'WAREHOUSE_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/po', ['lines' => [['sku' => 'NO-SUCH', 'qty' => 1, 'price' => 1]]] + $valid), 'PRODUCT_NOT_FOUND', [400]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/po', ['lines' => [['sku' => $product->sku, 'qty' => 1, 'price' => 0.001]]] + $valid), 'PO_EMPTY', [422]);
        $this->assertSame($count, PurchaseOrder::count(), 'rejected requests create nothing');

        foreach (['', '/submit', '/approve', '/send', '/confirm'] as $suffix) {
            $res = $suffix === '' ? $this->getAs('proc', '/api/procurement/po/PO-0000-NOPE') : $this->postAs('proc', "/api/procurement/po/PO-0000-NOPE{$suffix}");
            $this->expectRejected($res, 'PO_NOT_FOUND', [404]);
        }

        // a double click with the same Idempotency-Key creates one PO
        $key = ['Idempotency-Key' => 'po-'.self::uid()];
        $first = $this->postAs('proc', '/api/procurement/po', $valid, $key);
        $second = $this->postAs('proc', '/api/procurement/po', $valid, $key);
        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame($this->expectOk($first)['number'], $this->expectOk($second)['number']);
        $this->assertSame('true', $second->headers->get('X-Idempotent-Replay'));
        $this->assertSame($count + 1, PurchaseOrder::count());

        // list sorting + search
        $this->makePo($supplier->code, $product->sku, 50, 10, ['reference' => 'REF-'.$supplier->code]);
        $byTotal = $this->expectOk($this->getAs('proc', "/api/procurement/po?supplier={$supplier->code}&sort=total&order=asc"));
        $this->assertSame(['20.00', '500.00'], array_column($byTotal['items'], 'total'));
        $this->assertSame($supplier->code, $byTotal['items'][0]['supplier']['code']);
        $this->assertSame(['code', 'nameAr', 'nameEn', 'score'], array_keys($byTotal['items'][0]['supplier']));
        $this->assertSame(1, $this->expectOk($this->getAs('proc', "/api/procurement/po?q=REF-{$supplier->code}"))['total']);
        $this->assertSame(2, $this->expectOk($this->getAs('proc', '/api/procurement/po?q='.urlencode($supplier->name_ar)))['total']);
    }
}
