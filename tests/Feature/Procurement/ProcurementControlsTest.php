<?php

namespace Tests\Feature\Procurement;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\GrnLine;
use App\Models\PurchaseOrder;
use App\Services\Core\SettingsService;
use App\Services\Procurement\ProcurementHousekeeping;
use Tests\ApiTestCase;

/**
 * Controls added from the procurement review (2026-10-11): requisition approval tiers + segregation of duties,
 * order-level charges and gross total, late-delivery follow-up with automatic alerts, supplier status and
 * qualification documents as a PO gate, three-way-matched supplier invoices, purchase pack on products.
 */
class ProcurementControlsTest extends ApiTestCase
{
    use ProcurementFixtures;

    public function test_requisition_tiers_follow_the_settings_and_the_requester_never_approves(): void
    {
        $product = $this->makeProduct(40);
        $settings = $this->app->make(SettingsService::class);
        $original = $settings->get('procurement.prApprovalTiers');
        $settings->set('procurement.prApprovalTiers', [['max' => 1000, 'roles' => ['proc']], ['max' => null, 'roles' => ['proc', 'finance']]]);
        try {
            $small = $this->expectOk($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'RYD', 'justification' => 'طلب صغير', 'lines' => [['sku' => $product->sku, 'qty' => 10]]])); // 400
            $this->expectOk($this->postAs('wm', "/api/procurement/pr/{$small['number']}/submit"));
            $big = $this->expectOk($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'RYD', 'priority' => 'emergency', 'justification' => 'طلب كبير', 'lines' => [['sku' => $product->sku, 'qty' => 125]]])); // 5,000
            $big = $this->expectOk($this->postAs('wm', "/api/procurement/pr/{$big['number']}/submit"));
            $this->assertSame(['proc'], array_column($this->expectOk($this->getAs('wm', "/api/procurement/pr/{$small['number']}"))['approvals'], 'roleKey'));
            $this->assertSame(['proc', 'finance'], array_column($big['approvals'], 'roleKey'), 'the estimate picks the tier');
            $this->assertSame('emergency', $big['priority']);

            // stock context on every line: the approver sees why the request is needed
            $detail = $this->expectOk($this->getAs('proc', "/api/procurement/pr/{$big['number']}"));
            $this->assertSame(['onHand', 'reserved', 'available', 'shortfall'], array_keys($detail['lines'][0]['stock']));
            $this->assertSame(125, $detail['lines'][0]['stock']['shortfall'], 'a brand-new product has no stock, so the whole quantity is short');

            // segregation of duties: a buyer who raises a requisition does not approve it
            $own = $this->expectOk($this->postAs('proc', '/api/procurement/pr', ['warehouseCode' => 'RYD', 'justification' => 'طلب المشتريات نفسها', 'lines' => [['sku' => $product->sku, 'qty' => 2]]]));
            $this->expectOk($this->postAs('proc', "/api/procurement/pr/{$own['number']}/submit"));
            $this->expectRejected($this->postAs('proc', "/api/procurement/pr/{$own['number']}/approve"), 'PR_SELF_APPROVAL', [422]);
            $this->assertSame('approved', $this->expectOk($this->postAs('admin', "/api/procurement/pr/{$own['number']}/approve"))['status']);
        } finally {
            $settings->set('procurement.prApprovalTiers', $original);
        }
    }

    public function test_order_level_charges_give_a_gross_total_while_tiers_stay_on_goods(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct();
        $po = $this->makePo($supplier->code, $product->sku, 10, 100, ['discount' => 100, 'shippingCost' => 50, 'vatPct' => 15]);
        $this->assertSame(['1000.00', '100.00', '50.00', '1092.50'], [$po['total'], $po['discount'], $po['shippingCost'], $po['grossTotal']], '(1000 − 100 + 50) × 1.15');
        $this->assertEquals(15, $po['vatPct']);
        $this->assertSame(['proc'], array_column($po['approvals'], 'roleKey'), 'approval tier uses the net goods value');
        $detail = $this->expectOk($this->getAs('proc', "/api/procurement/po/{$po['number']}"));
        $this->assertSame([0, false, '1092.50'], [$detail['receivedPct'], $detail['late'], $detail['grossTotal']]);

        $this->expectRejected($this->postAs('proc', '/api/procurement/po', ['supplierCode' => $supplier->code, 'warehouseCode' => 'RYD', 'dueDate' => self::future(5), 'discount' => 5000, 'lines' => [['sku' => $product->sku, 'qty' => 1, 'price' => 10]]]), 'PO_CHARGES', [400, 422]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/po', ['supplierCode' => $supplier->code, 'warehouseCode' => 'RYD', 'dueDate' => self::future(5), 'shippingCost' => -1, 'lines' => [['sku' => $product->sku, 'qty' => 1, 'price' => 10]]]), null, [400, 422]);
    }

    public function test_late_orders_are_flagged_followed_up_and_alerted_once(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct();
        $po = $this->makePo($supplier->code, $product->sku, 10, 100, ['dueDate' => self::future(1)]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/po/{$po['number']}/follow-up", ['note' => 'تواصلت']), 'PO_NOT_OPEN', [422]); // still pending approval
        $this->expectOk($this->postAs('admin', "/api/procurement/po/{$po['number']}/approve"));
        $this->expectOk($this->postAs('proc', "/api/procurement/po/{$po['number']}/send"));
        $this->assertFalse($this->expectOk($this->getAs('proc', "/api/procurement/po/{$po['number']}"))['late']);

        PurchaseOrder::where('id', $po['id'])->update(['due_date' => now()->subDays(3)->toDateString()]);
        $detail = $this->expectOk($this->getAs('proc', "/api/procurement/po/{$po['number']}"));
        $this->assertTrue($detail['late']);
        $this->assertSame([$po['number']], array_column($this->expectOk($this->getAs('proc', "/api/procurement/po?late=1&supplier={$supplier->code}"))['items'], 'number'));
        $this->assertSame(0, $this->expectOk($this->getAs('proc', "/api/procurement/po?late=1&supplier={$supplier->code}&status=pending"))['total']);

        $this->expectRejected($this->postAs('proc', "/api/procurement/po/{$po['number']}/follow-up", []), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('worker', "/api/procurement/po/{$po['number']}/follow-up", ['note' => 'المورد وعد بالتسليم غدًا']), 'FORBIDDEN', [403]);
        $followed = $this->expectOk($this->postAs('proc', "/api/procurement/po/{$po['number']}/follow-up", ['note' => 'المورد وعد بالتسليم غدًا']));
        $this->assertSame(['المورد وعد بالتسليم غدًا', 'proc'], [$followed['followUpNote'], $followed['followedUpBy']]);
        $this->assertNotNull($followed['followedUpAt']);
        $this->assertSame(1, AuditLog::where('entity_id', $po['id'])->where('action', 'PO.FOLLOW_UP')->count());

        // the automatic alert fires once per order, then stays quiet
        $hk = $this->app->make(ProcurementHousekeeping::class);
        $first = $hk->runNow();
        $this->assertTrue($first['ran']);
        $this->assertGreaterThanOrEqual(1, $first['latePos']);
        $this->assertSame(1, ActivityLog::where('entity_type', 'PurchaseOrder')->where('entity_id', $po['id'])->where('text_ar', 'like', 'تأخر توريد%')->count());
        $this->assertNotNull(PurchaseOrder::find($po['id'])->late_alerted_at);
        $this->assertSame(0, $hk->runNow()['latePos']);
        $this->assertSame(1, ActivityLog::where('entity_type', 'PurchaseOrder')->where('entity_id', $po['id'])->where('text_ar', 'like', 'تأخر توريد%')->count());

        $dash = $this->expectOk($this->getAs('proc', '/api/procurement/dashboard'));
        $this->assertGreaterThanOrEqual(1, $dash['latePos']['count']);
        $this->assertGreaterThanOrEqual(1000, (float) $dash['openPoValue']['total']);
    }

    public function test_supplier_status_and_qualification_documents_gate_new_orders(): void
    {
        $product = $this->makeProduct();
        $review = $this->makeSupplier(90, ['status' => 'review']);
        $suspended = $this->makeSupplier(90, ['status' => 'suspended']);
        $this->expectRejected($this->postAs('proc', '/api/procurement/po', ['supplierCode' => $review->code, 'warehouseCode' => 'RYD', 'dueDate' => self::future(5), 'lines' => [['sku' => $product->sku, 'qty' => 1, 'price' => 10]]]), 'SUPPLIER_UNDER_REVIEW', [422]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/po', ['supplierCode' => $suspended->code, 'warehouseCode' => 'RYD', 'dueDate' => self::future(5), 'lines' => [['sku' => $product->sku, 'qty' => 1, 'price' => 10]]]), 'SUPPLIER_INACTIVE', [422]);

        // status ⇄ active stay consistent through the master-data API
        $this->expectOk($this->patchAs('proc', "/api/suppliers/{$review->code}", ['status' => 'active', 'bankName' => 'بنك الرياض', 'bankAccountName' => 'مورد اختبار', 'address' => 'الرياض، المنطقة الصناعية', 'creditLimit' => 50000]));
        $patched = $this->expectOk($this->getAs('proc', "/api/suppliers/{$review->code}"));
        $this->assertSame(['active', true, 'بنك الرياض', 'مورد اختبار', '50000.00'], [$patched['status'], $patched['active'], $patched['bankName'], $patched['bankAccountName'], $patched['creditLimit']]);
        $state = function () use ($review) {
            $s = $this->expectOk($this->getAs('proc', "/api/suppliers/{$review->code}"));

            return [$s['status'], $s['active']];
        };
        $this->expectOk($this->patchAs('proc', "/api/suppliers/{$review->code}", ['active' => false]));
        $this->assertSame(['suspended', false], $state());
        $this->expectOk($this->patchAs('proc', "/api/suppliers/{$review->code}", ['active' => true]));
        $this->assertSame(['active', true], $state());
        $this->expectOk($this->patchAs('proc', "/api/suppliers/{$review->code}", ['status' => 'review']));
        $this->assertSame(['review', true], $state(), 'under review stays usable in master data but gets no new POs');
        $this->expectRejected($this->patchAs('proc', "/api/suppliers/{$review->code}", ['status' => 'blocked']), null, [400]);

        // documents: an expired mandatory one blocks new POs unless a po.approve holder records the exception
        $supplier = $this->makeSupplier();
        $docs = "/api/suppliers/{$supplier->code}/documents";
        $this->expectRejected($this->postAs('worker', $docs, ['type' => 'cr']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('proc', $docs, ['type' => 'passport']), null, [400]);
        $this->expectRejected($this->postAs('proc', $docs, ['type' => 'cr', 'issuedAt' => self::future(2), 'expiresAt' => self::future(1)]), 'DOC_DATES', [400]);
        $cr = $this->expectOk($this->postAs('proc', $docs, ['type' => 'cr', 'number' => '1010123456', 'expiresAt' => now()->subDay()->toDateString()]));
        $this->assertSame(['cr', true, 'expired'], [$cr['type'], $cr['mandatory'], $cr['state']], 'CR is mandatory by default');
        $iso = $this->expectOk($this->postAs('proc', $docs, ['type' => 'iso', 'expiresAt' => self::future(10)]));
        $this->assertSame([false, 'expiring', 10], [$iso['mandatory'], $iso['state'], $iso['daysLeft']]);
        $list = $this->expectOk($this->getAs('proc', $docs));
        $this->assertSame([2, 1, 1, 30], [count($list['documents']), $list['expired'], $list['expiringSoon'], $list['warnDays']]);

        $body = ['supplierCode' => $supplier->code, 'warehouseCode' => 'RYD', 'dueDate' => self::future(5), 'lines' => [['sku' => $product->sku, 'qty' => 1, 'price' => 10]]];
        $this->expectRejected($this->postAs('proc', '/api/procurement/po', $body), 'SUPPLIER_DOCS_EXPIRED', [422]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/po', $body + ['overrideDocuments' => true]), 'SUPPLIER_DOCS_OVERRIDE_DENIED', [403]); // the reason is mandatory
        $po = $this->expectOk($this->postAs('proc', '/api/procurement/po', $body + ['overrideDocuments' => true, 'documentsReason' => 'السجل قيد التجديد — إيصال الغرفة التجارية مرفق']));
        $this->assertSame('pending', $po['status']);
        $this->assertSame(1, AuditLog::where('entity_id', $po['id'])->where('action', 'PO.SUPPLIER_DOCS_OVERRIDE')->count());

        // the expiry alert goes out once; removing the expired document lifts the gate
        $hk = $this->app->make(ProcurementHousekeeping::class);
        $this->assertGreaterThanOrEqual(2, $hk->runNow()['expiringDocs']);
        $this->assertSame(2, ActivityLog::where('entity_type', 'Supplier')->where('entity_id', $supplier->id)->count());
        $this->assertSame(0, $hk->runNow()['expiringDocs']);
        $this->expectRejected($this->deleteAs('proc', "{$docs}/nope"), 'DOC_NOT_FOUND', [404]);
        $this->expectOk($this->deleteAs('proc', "{$docs}/{$cr['id']}"));
        $this->assertSame('pending', $this->expectOk($this->postAs('proc', '/api/procurement/po', $body))['status']);
        $profile = $this->expectOk($this->getAs('proc', "/api/suppliers/{$supplier->code}"));
        $this->assertSame([0, 1], [$profile['expiredDocuments'], $profile['documentsCount']]);
    }

    public function test_supplier_invoices_are_three_way_matched_approved_by_someone_else_and_paid_with_a_reference(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct();
        $po = $this->makePo($supplier->code, $product->sku, 10, 100);
        $register = fn (array $extra) => $this->postAs('proc', '/api/procurement/invoices', $extra + ['po' => $po['number'], 'invoiceDate' => now()->toDateString(), 'lines' => [['lineNo' => 1, 'qty' => 8, 'price' => 100]]]);
        $this->expectRejected($register(['supplierInvoiceNo' => 'INV-A']), 'INVOICE_PO_STATE', [422]); // nothing sent yet
        $this->expectOk($this->postAs('admin', "/api/procurement/po/{$po['number']}/approve"));
        $shipment = $this->expectOk($this->postAs('proc', "/api/procurement/po/{$po['number']}/send"))['shipment'];
        $grn = GoodsReceipt::create(['number' => self::code('GRNT'), 'shipment_id' => $shipment['id'], 'po_id' => $po['id'], 'supplier_id' => $supplier->id, 'warehouse_id' => $po['warehouseId'], 'posted_by' => 'test', 'posted_at' => now()]);
        GrnLine::create(['grn_id' => $grn->id, 'line_no' => 1, 'po_line_id' => $po['lines'][0]['id'], 'product_id' => $product->id, 'received_qty' => 8, 'accepted_qty' => 8]);

        $this->expectRejected($this->postAs('worker', '/api/procurement/invoices', ['po' => $po['number']]), 'FORBIDDEN', [403]);
        $this->expectRejected($register([]), 'INVALID_INPUT', [400]); // supplier invoice number is required
        $matched = $this->expectOk($register(['supplierInvoiceNo' => 'INV-A']));
        $this->assertStringStartsWith('INV-', $matched['number']);
        $this->assertSame(['matched', null, '800.00', '120.00', '920.00', 'ok'], [$matched['status'], $matched['variances'], $matched['subtotal'], $matched['vatAmount'], $matched['total'], $matched['lines'][0]['match']]);
        $this->assertSame([8, 0], [$matched['lines'][0]['receivedQty'], $matched['lines'][0]['previouslyInvoicedQty']]);
        $this->assertNotNull($matched['dueDate'], 'due date derives from the supplier terms (آجل 30 يومًا)');
        $this->expectRejected($register(['supplierInvoiceNo' => 'INV-A']), 'INVOICE_DUPLICATE', [409]);
        $this->expectRejected($register(['supplierInvoiceNo' => 'inv-a ']), 'INVOICE_DUPLICATE', [409]);

        // over-invoicing (received 8, already invoiced 8) and a price above tolerance → disputed, never silently accepted
        $disputed = $this->expectOk($register(['supplierInvoiceNo' => 'INV-B', 'lines' => [['lineNo' => 1, 'qty' => 2, 'price' => 103]]]));
        $this->assertSame('disputed', $disputed['status']);
        $this->assertSame(['qty', 'price'], array_column($disputed['variances'], 'kind'));
        $this->assertSame('both', $disputed['lines'][0]['match']);

        // approval: not the registrar; a dispute needs a written decision
        $this->expectRejected($this->postAs('proc', "/api/procurement/invoices/{$matched['number']}/approve"), 'FORBIDDEN', [403]); // buyers register, finance/GM approve
        $this->expectRejected($this->postAs('worker', "/api/procurement/invoices/{$matched['number']}/approve"), 'FORBIDDEN', [403]);
        $byFinance = $this->expectOk($this->postAs('finance', '/api/procurement/invoices', ['po' => $po['number'], 'supplierInvoiceNo' => 'INV-C', 'invoiceDate' => now()->toDateString(), 'lines' => [['lineNo' => 1, 'qty' => 1, 'price' => 100]]]));
        $this->expectRejected($this->postAs('finance', "/api/procurement/invoices/{$byFinance['number']}/approve", ['note' => 'أعتمدها بنفسي']), 'INVOICE_SELF_APPROVAL', [422]);
        $this->expectOk($this->postAs('proc', "/api/procurement/invoices/{$byFinance['number']}/cancel", ['reason' => 'سُجلت بالخطأ']));
        $this->expectRejected($this->postAs('finance', "/api/procurement/invoices/{$disputed['number']}/approve"), 'INVOICE_DECISION_REQUIRED', [400]);
        $approved = $this->expectOk($this->postAs('finance', "/api/procurement/invoices/{$matched['number']}/approve"));
        $this->assertSame(['approved', 'finance'], [$approved['status'], $approved['approvedBy']]);
        $this->expectRejected($this->postAs('finance', "/api/procurement/invoices/{$matched['number']}/approve"), 'INVOICE_STATE', [422]);

        // payment: finance only, with the bank reference
        $this->expectRejected($this->postAs('proc', "/api/procurement/invoices/{$matched['number']}/pay", ['reference' => 'TRF-1']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('finance', "/api/procurement/invoices/{$matched['number']}/pay", []), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('finance', "/api/procurement/invoices/{$disputed['number']}/pay", ['reference' => 'TRF-2']), 'INVOICE_STATE', [422]);
        $paid = $this->expectOk($this->postAs('finance', "/api/procurement/invoices/{$matched['number']}/pay", ['reference' => 'TRF-2026-0001']));
        $this->assertSame(['paid', 'TRF-2026-0001', 'finance'], [$paid['status'], $paid['paymentRef'], $paid['paidBy']]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/invoices/{$matched['number']}/cancel", ['reason' => 'خطأ']), 'INVOICE_PAID', [422]);

        $cancelled = $this->expectOk($this->postAs('proc', "/api/procurement/invoices/{$disputed['number']}/cancel", ['reason' => 'المورد أصدر إشعار دائن']));
        $this->assertSame('cancelled', $cancelled['status']);

        $list = $this->expectOk($this->getAs('finance', "/api/procurement/invoices?po={$po['number']}"));
        $this->assertSame([$byFinance['number'], $disputed['number'], $matched['number']], array_column($list['items'], 'number'));
        $this->assertSame(['cancelled', 'cancelled', 'paid'], array_column($list['items'], 'status'));
        $this->assertSame([$matched['number']], array_column($this->expectOk($this->getAs('finance', "/api/procurement/invoices?po={$po['number']}&status=paid"))['items'], 'number'));
        $summary = $this->expectOk($this->getAs('finance', '/api/procurement/invoices/summary'));
        $this->assertSame(['disputed', 'awaitingApproval', 'openTotal', 'overdue', 'dueThisWeek'], array_keys($summary));
        $this->assertSame([$matched['number'], $disputed['number'], $byFinance['number']], array_column($this->expectOk($this->getAs('proc', "/api/procurement/po/{$po['number']}"))['invoices'], 'number'));
        $this->assertSame(1, AuditLog::where('entity_type', 'SupplierInvoice')->where('entity_id', $matched['id'])->where('action', 'INVOICE.PAY')->count());
    }

    public function test_products_carry_a_purchase_pack_without_changing_the_base_unit(): void
    {
        $sku = self::code('PKG');
        $p = $this->expectOk($this->postAs('admin', '/api/products', ['sku' => $sku, 'nameAr' => 'كرتون عصير', 'nameEn' => 'Juice carton', 'weightKg' => 1, 'lengthCm' => 10, 'widthCm' => 10, 'heightCm' => 10, 'packQty' => 12, 'packUomCode' => 'ctn']));
        $this->assertSame([12, 'ctn'], [$p['packQty'], $p['packUom']['code']]);
        $this->assertSame(1, $this->expectOk($this->postAs('admin', '/api/products', ['sku' => $sku.'B', 'nameAr' => 'بدون عبوة', 'nameEn' => 'No pack', 'weightKg' => 1, 'lengthCm' => 1, 'widthCm' => 1, 'heightCm' => 1]))['packQty'], 'default pack is one base unit');
        $this->expectRejected($this->postAs('admin', '/api/products', ['sku' => $sku.'C', 'nameAr' => 'x', 'nameEn' => 'x', 'weightKg' => 1, 'lengthCm' => 1, 'widthCm' => 1, 'heightCm' => 1, 'packUomCode' => 'nope']), 'BAD_UOM', [400]);

        $u = $this->expectOk($this->patchAs('admin', "/api/products/{$sku}", ['packQty' => 24, 'reason' => 'المورد غيّر الكرتون']));
        $this->assertSame([1, 'packQty'], [$u['changed'], $u['fields'][0]['field']]);
        $this->assertSame([24, 'ctn'], [$this->expectOk($this->getAs('proc', "/api/products/{$sku}"))['packQty'], $this->expectOk($this->getAs('proc', "/api/products/{$sku}"))['packUom']['code']]);
    }
}
