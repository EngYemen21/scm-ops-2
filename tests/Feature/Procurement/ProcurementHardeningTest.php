<?php

namespace Tests\Feature\Procurement;

use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\GrnLine;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Warehouse;
use App\Services\Core\SettingsService;
use Tests\ApiTestCase;

/**
 * Operational hardening 2026-11, workstream W7 (docs/ops-hardening/SPEC.md), procurement side: discount and shipping
 * of an order billed over several invoices · price control from the requisition estimate to the purchase order ·
 * "incoming" in replenishment uses the one open quantity · the policies the screens read.
 */
class ProcurementHardeningTest extends ApiTestCase
{
    use ProcurementFixtures;

    /** A sent PO (approved by the administrator) with `accepted` units already on a receipt; returns the PO body. */
    private function sentPo(array $charges, int $qty = 10, float $price = 100): array
    {
        $po = $this->makePo($this->makeSupplier()->code, $this->makeProduct()->sku, $qty, $price, $charges);
        $this->expectOk($this->postAs('admin', "/api/procurement/po/{$po['number']}/approve"));
        $po['shipment'] = $this->expectOk($this->postAs('proc', "/api/procurement/po/{$po['number']}/send"))['shipment'];

        return $po;
    }

    private function accept(array $po, int $qty): GoodsReceipt
    {
        $grn = GoodsReceipt::create(['number' => self::code('GRNH'), 'shipment_id' => $po['shipment']['id'], 'po_id' => $po['id'], 'supplier_id' => $po['supplierId'], 'warehouse_id' => $po['warehouseId'], 'posted_by' => 'test', 'posted_at' => now()]);
        GrnLine::create(['grn_id' => $grn->id, 'line_no' => 1, 'po_line_id' => $po['lines'][0]['id'], 'product_id' => $po['lines'][0]['productId'], 'received_qty' => $qty, 'accepted_qty' => $qty]);

        return $grn;
    }

    private function invoice(array $po, array $body): array
    {
        return $this->expectOk($this->postAs('proc', '/api/procurement/invoices', $body + ['po' => $po['number'], 'supplierInvoiceNo' => self::code('SI'), 'invoiceDate' => now()->toDateString(), 'vatPct' => 15]));
    }

    private function charges(array $po): array
    {
        return $this->expectOk($this->getAs('proc', "/api/procurement/po/{$po['number']}"))['charges'];
    }

    // ───────────────────────── 6. charges on partial invoices ─────────────────────────

    public function test_discount_and_shipping_are_matched_against_what_is_left_of_the_order(): void
    {
        $po = $this->sentPo(['discount' => 40, 'shippingCost' => 60, 'vatPct' => 15]);
        $this->accept($po, 4);
        $this->assertEquals(['discount' => ['po' => 40, 'invoiced' => 0, 'remaining' => 40], 'shipping' => ['po' => 60, 'invoiced' => 0, 'remaining' => 60]], $this->charges($po));

        // a partial invoice carries its share of the charges: billing less than the order's figure is not a variance
        $first = $this->invoice($po, ['discount' => 16, 'shippingCost' => 24, 'lines' => [['lineNo' => 1, 'qty' => 4, 'price' => 100]]]);
        $this->assertSame(['matched', null], [$first['status'], $first['variances']]);
        $this->assertEquals(['po' => 60, 'invoiced' => 24, 'remaining' => 36], $this->charges($po)['shipping'], 'the form prefills what is left');
        $this->assertEquals(['po' => 40, 'invoiced' => 16, 'remaining' => 24], $this->charges($po)['discount']);

        // the second invoice repeats the FULL shipping charge — it used to pass as "matched" and be paid twice
        $this->accept($po, 6);
        $double = $this->invoice($po, ['discount' => 24, 'shippingCost' => 60, 'lines' => [['lineNo' => 1, 'qty' => 6, 'price' => 100]]]);
        $this->assertSame('disputed', $double['status']);
        $this->assertSame(['charges'], array_column($double['variances'], 'kind'));
        $v = $double['variances'][0];
        $this->assertSame(['shipping'], $v['fields']);
        $this->assertEquals([36, 60, 60, 24], [$v['expected']['shipping'], $v['actual']['shipping'], $v['po']['shipping'], $v['previouslyInvoiced']['shipping']]);
        $this->assertStringContainsString('36', $v['textAr']);

        // a disputed invoice still occupies the order's charges until it is cancelled
        $this->assertEquals(0, $this->charges($po)['shipping']['remaining']);
        $this->expectOk($this->postAs('proc', "/api/procurement/invoices/{$double['number']}/cancel", ['reason' => 'شحن مكرر']));
        $this->assertEquals(36, $this->charges($po)['shipping']['remaining']);

        // the corrected invoice bills exactly the remainder
        $second = $this->invoice($po, ['discount' => 24, 'shippingCost' => 36, 'lines' => [['lineNo' => 1, 'qty' => 6, 'price' => 100]]]);
        $this->assertSame(['matched', null], [$second['status'], $second['variances']]);
        $this->assertEquals([0, 0], [$this->charges($po)['shipping']['remaining'], $this->charges($po)['discount']['remaining']]);
    }

    public function test_charges_above_the_remainder_are_disputed_beyond_the_tolerance_only(): void
    {
        // tolerance = procurement.invoiceTolerancePct (2%) of the ORDER's figure: 100 → 2
        $po = $this->sentPo(['shippingCost' => 100]);
        $this->accept($po, 10);
        $line = ['lines' => [['lineNo' => 1, 'qty' => 2, 'price' => 100]]];
        $within = $this->invoice($po, ['shippingCost' => 102] + $line);
        $this->assertSame('matched', $within['status']);
        $this->expectOk($this->postAs('proc', "/api/procurement/invoices/{$within['number']}/cancel", ['reason' => 'اختبار السماح']));
        $beyond = $this->invoice($po, ['shippingCost' => 102.5] + $line);
        $this->assertSame(['disputed', ['shipping']], [$beyond['status'], $beyond['variances'][0]['fields']]);
        $this->expectOk($this->postAs('proc', "/api/procurement/invoices/{$beyond['number']}/cancel", ['reason' => 'اختبار السماح']));

        // a discount the order never had, and one above what is left of it
        $extra = $this->invoice($po, ['discount' => 30] + $line);
        $this->assertSame(['disputed', ['discount']], [$extra['status'], $extra['variances'][0]['fields']]);
        $this->expectOk($this->postAs('proc', "/api/procurement/invoices/{$extra['number']}/cancel", ['reason' => 'خصم غير متفق عليه']));

        // the whole order on one invoice with its full charges is still a clean match
        $full = $this->invoice($po, ['shippingCost' => 100, 'lines' => [['lineNo' => 1, 'qty' => 10, 'price' => 100]]]);
        $this->assertSame(['matched', '1265.00'], [$full['status'], $full['total']]); // (1000 + 100) × 1.15

        // an order without charges: any shipping on the invoice is a variance, as before
        $plain = $this->sentPo([]);
        $this->accept($plain, 10);
        $this->assertSame('disputed', $this->invoice($plain, ['shippingCost' => 50] + $line)['status']);
    }

    // ───────────────────────── 7. price control from requisition to order ─────────────────────────

    /** An approved requisition (raised by the warehouse, approved by procurement) of one line. */
    private function approvedPr(string $sku, int $qty, ?float $estimate): array
    {
        $line = ['sku' => $sku, 'qty' => $qty] + ($estimate !== null ? ['price' => $estimate] : []);
        $pr = $this->expectOk($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'RYD', 'justification' => 'ضبط السعر من الطلب إلى الأمر', 'lines' => [$line]]));
        $this->expectOk($this->postAs('wm', "/api/procurement/pr/{$pr['number']}/submit"));

        return $this->expectOk($this->postAs('proc', "/api/procurement/pr/{$pr['number']}/approve"));
    }

    public function test_a_price_above_the_requisition_estimate_needs_a_reason_and_finance(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct(40);
        $toPo = fn (array $pr, float $price, array $extra = [], string $as = 'proc') => $this->postAs($as, "/api/procurement/pr/{$pr['number']}/to-po",
            $extra + ['supplierCode' => $supplier->code, 'dueDate' => self::future(5), 'prices' => [['sku' => $product->sku, 'price' => $price]]]);

        // within the tolerance (10%): nothing extra is asked, the estimate is kept on the line
        $near = $this->expectOk($toPo($this->approvedPr($product->sku, 10, 40), 43));
        $this->assertSame([false, null, '40.0000', '43.00'], [$near['priceChanged'], $near['priceNote'], $near['lines'][0]['prEstPrice'], $near['lines'][0]['price']]);
        $this->assertSame(['proc'], array_column($near['approvals'], 'roleKey'));
        $detail = $this->expectOk($this->getAs('proc', "/api/procurement/po/{$near['number']}"));
        $this->assertEquals([7.5, false, 10], [$detail['lines'][0]['priceChangePct'], $detail['lines'][0]['priceAboveEstimate'], $detail['prPriceTolerancePct']]);

        // beyond it: refused without a written reason, and the requisition is not consumed
        $pr = $this->approvedPr($product->sku, 10, 40);
        $body = $this->expectRejected($toPo($pr, 50), 'PR_PRICE_REASON', [422]);
        $this->assertEquals([10, 1, 40, 50, 25], [$body['details']['tolerancePct'], count($body['details']['lines']), $body['details']['lines'][0]['estimate'], $body['details']['lines'][0]['price'], $body['details']['lines'][0]['pct']]);
        $this->expectRejected($toPo($pr, 50, ['priceReason' => 'غلا']), 'PR_PRICE_REASON', [422]);
        $this->assertSame('approved', PurchaseRequisition::find($pr['id'])->status);

        // with the reason: the order is flagged, the reason is on it, and finance joins a chain that the value alone gave to procurement
        $po = $this->expectOk($toPo($pr, 50, ['priceReason' => 'ارتفاع سعر المورد بعد اعتماد الطلب']));
        $this->assertSame([true, 'ارتفاع سعر المورد بعد اعتماد الطلب', '500.00'], [$po['priceChanged'], $po['priceNote'], $po['total']]);
        $this->assertSame(['proc', 'finance'], array_column($po['approvals'], 'roleKey'));
        $this->assertSame([1, 2], array_column($po['approvals'], 'step'));
        $detail = $this->expectOk($this->getAs('finance', "/api/procurement/po/{$po['number']}"));
        $this->assertEquals([25, true, '40.0000'], [$detail['lines'][0]['priceChangePct'], $detail['lines'][0]['priceAboveEstimate'], $detail['lines'][0]['prEstPrice']]);
        $this->assertSame(1, AuditLog::where('action', 'PO.PRICE_ABOVE_ESTIMATE')->where('entity_number', $po['number'])->count());
        $created = json_decode((string) AuditLog::where('action', 'PO.CREATE')->where('entity_number', $po['number'])->value('new_value'), true);
        $this->assertEquals([['lineNo' => 1, 'estimate' => 40, 'price' => 50]], $created['prPrices'], 'the requisition price is on the audit trail');
        $this->assertSame(['proc', 'finance'], $created['chain']);

        // both steps are real: procurement first (not the creator), then finance
        $this->expectRejected($this->postAs('finance', "/api/procurement/po/{$po['number']}/approve"), 'PO_APPROVAL_ROLE', [422]);
        $this->expectOk($this->postAs('admin', "/api/procurement/po/{$po['number']}/approve"));
        $this->assertSame('approved', $this->expectOk($this->postAs('finance', "/api/procurement/po/{$po['number']}/approve"))['status']);

        // the requisition was converted once; a second conversion of any kind is refused
        $this->expectRejected($toPo($pr, 50, ['priceReason' => 'محاولة ثانية للتحويل']), 'PR_TRANSITION', [422]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/pr/{$pr['number']}/to-rfq", ['closeDate' => self::future(3), 'supplierCodes' => [$supplier->code], 'invitedRule' => 'manual']), 'PR_TRANSITION', [422]);
        $this->assertSame(1, PurchaseOrder::where('reference', $pr['number'])->count());
    }

    public function test_price_control_follows_the_policy_and_never_duplicates_finance(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct(40);
        $body = fn (float $price, array $extra = []) => $extra + ['supplierCode' => $supplier->code, 'dueDate' => self::future(5), 'prices' => [['sku' => $product->sku, 'price' => $price]]];

        // a value tier that already has finance: the price change adds no second finance step
        $big = $this->approvedPr($product->sku, 200, 40); // 200 × 50 = 10,000 → procurement + finance by value
        $po = $this->expectOk($this->postAs('proc', "/api/procurement/pr/{$big['number']}/to-po", $body(50, ['priceReason' => 'تغيّر سعر الصرف'])));
        $this->assertSame([true, ['proc', 'finance']], [$po['priceChanged'], array_column($po['approvals'], 'roleKey')]);

        // a lower price is never questioned
        $cheaper = $this->expectOk($this->postAs('proc', "/api/procurement/pr/{$this->approvedPr($product->sku, 10, 40)['number']}/to-po", $body(30)));
        $this->assertSame([false, ['proc']], [$cheaper['priceChanged'], array_column($cheaper['approvals'], 'roleKey')]);
        $this->assertEquals(-25, $this->expectOk($this->getAs('proc', "/api/procurement/po/{$cheaper['number']}"))['lines'][0]['priceChangePct']);

        // the tolerance is the policy, not a constant
        $settings = $this->app->make(SettingsService::class);
        $settings->set('procurement.prPriceTolerancePct', 30);
        try {
            $this->assertEquals(30, $this->expectOk($this->getAs('wm', '/api/procurement/policy'))['prPriceTolerancePct']);
            $loose = $this->expectOk($this->postAs('proc', "/api/procurement/pr/{$this->approvedPr($product->sku, 10, 40)['number']}/to-po", $body(50)));
            $this->assertFalse($loose['priceChanged'], '+25% is inside a 30% tolerance');
        } finally {
            $settings->set('procurement.prPriceTolerancePct', 10);
        }

        // an order typed by hand has no requisition estimate: the rule does not apply to it
        $manual = $this->makePo($supplier->code, $product->sku, 10, 500);
        $this->assertSame([false, null], [$manual['priceChanged'], $manual['lines'][0]['prEstPrice']]);
        $this->assertNull($this->makePo($supplier->code, $product->sku, 1, 99, ['priceReason' => 'x', 'lines' => [['sku' => $product->sku, 'qty' => 1, 'price' => 99, 'prEstPrice' => 1]]])['lines'][0]['prEstPrice'], 'the estimate cannot be injected through the PO form');
    }

    // ───────────────────────── policies the screens read · one open quantity everywhere ─────────────────────────

    public function test_the_policy_endpoint_and_incoming_use_the_one_open_quantity(): void
    {
        $policy = $this->expectOk($this->getAs('worker', '/api/procurement/policy'));
        $this->assertSame(['minSupplierScore', 'prPriceTolerancePct', 'invoiceTolerancePct', 'rejectedStaysOpen', 'allowSelfApproval', 'allowExcessReceipt'], array_keys($policy));
        $this->assertEquals([65, 10, 2, true, false, false], array_values($policy));

        // 100 ordered: 60 accepted, 5 damaged (arrived — not coming again), 10 rejected (still owed) → 35 incoming
        $supplier = $this->makeSupplier();
        $product = $this->makeProduct(40, 500);
        $ryd = Warehouse::where('code', 'RYD')->firstOrFail();
        $po = PurchaseOrder::create(['number' => self::code('PO-H'), 'supplier_id' => $supplier->id, 'warehouse_id' => $ryd->id, 'status' => 'partial', 'total' => 4000, 'open_qty' => 35, 'sent_at' => now()]);
        PoLine::create(['po_id' => $po->id, 'line_no' => 1, 'product_id' => $product->id, 'qty' => 100, 'price' => 40, 'received_qty' => 60, 'damaged_qty' => 5, 'rejected_qty' => 10]);
        $incoming = fn () => $this->expectOk($this->getAs('proc', "/api/procurement/suggestions?sku={$product->sku}&warehouse=RYD"))['items'][0]['incoming'];
        $this->assertSame(35, $incoming());
        $this->assertSame(['ordered' => 100, 'received' => 60, 'damaged' => 5, 'rejected' => 10, 'open' => 35, 'waived' => 0], $this->expectOk($this->getAs('proc', "/api/procurement/po/{$po->number}"))['receipt']);

        $settings = $this->app->make(SettingsService::class);
        $settings->set('procurement.rejectedStaysOpen', false);
        try {
            $this->assertSame(25, $incoming(), 'with the policy off a rejection counts as fulfilled');
        } finally {
            $settings->set('procurement.rejectedStaysOpen', true);
        }

        // closing the order short takes it out of "incoming"; a fully received order closes with nothing waived
        $closed = $this->expectOk($this->postAs('proc', "/api/procurement/po/{$po->number}/close", ['reason' => 'المورد أنهى التوريد']));
        $this->assertSame(['closed', 0], [$closed['status'], $closed['openQty']]);
        $this->assertSame(0, $incoming());
        $done = PurchaseOrder::create(['number' => self::code('PO-H'), 'supplier_id' => $supplier->id, 'warehouse_id' => $ryd->id, 'status' => 'received', 'total' => 400, 'open_qty' => 0, 'sent_at' => now()]);
        PoLine::create(['po_id' => $done->id, 'line_no' => 1, 'product_id' => $product->id, 'qty' => 10, 'price' => 40, 'received_qty' => 10]);
        $this->assertSame('closed', $this->expectOk($this->postAs('proc', "/api/procurement/po/{$done->number}/close", ['reason' => 'إقفال إداري بعد اكتمال الاستلام']))['status']);
        $this->assertSame(0, $this->expectOk($this->getAs('proc', "/api/procurement/po/{$done->number}"))['receipt']['waived']);
    }
}
