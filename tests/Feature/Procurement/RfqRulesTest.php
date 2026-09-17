<?php

namespace Tests\Feature\Procurement;

use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use Tests\ApiTestCase;

/** RFQ invitation rules, quotation intake, comparison edge cases and award rejections. */
class RfqRulesTest extends ApiTestCase
{
    use ProcurementFixtures;

    private function quote(string $supplierCode, ?string $rfqNumber, array $lines, array $extra = []): array
    {
        return $extra + ['supplierCode' => $supplierCode, 'rfqNumber' => $rfqNumber, 'supplierRef' => 'REF-'.self::code('Q'), 'validUntil' => self::future(30), 'leadDays' => 4, 'attachmentName' => 'offer.pdf', 'lines' => $lines];
    }

    public function test_invitation_rules(): void
    {
        $preferred = $this->makeSupplier(99.5);
        $sameCategory = $this->makeSupplier(60, ['category' => $preferred->category]);
        $product = $this->makeProduct(40);
        $this->linkPreferred($product, $preferred, 39, 3);
        $lines = [['sku' => $product->sku, 'qty' => 110]];

        // default rule = cat: every active supplier of the preferred supplier's category
        $cat = $this->expectOk($this->postAs('proc', '/api/procurement/rfq', ['lines' => $lines, 'closeDate' => self::future(3), 'deliveryWarehouseCode' => 'RYD', 'terms' => 'net30']));
        $this->assertSame(['open', 'cat', 'net30', null], [$cat['status'], $cat['invitedRule'], $cat['terms'], $cat['pr']]);
        $this->assertEqualsCanonicalizing([$preferred->code, $sameCategory->code], array_map(fn ($s) => $s['supplier']['code'], $cat['suppliers']));
        $this->assertSame(['id', 'code', 'nameAr', 'nameEn', 'category', 'categoryEn', 'score', 'otif', 'fillRate', 'leadDays', 'isNew', 'terms'], array_keys($cat['suppliers'][0]['supplier']));

        // top3 = best three active suppliers by score
        $top = $this->expectOk($this->postAs('proc', '/api/procurement/rfq', ['lines' => $lines, 'invitedRule' => 'top3', 'closeDate' => self::future(3)]));
        $this->assertCount(3, $top['suppliers']);
        $expected = Supplier::where('active', true)->orderByDesc('score')->orderBy('code')->limit(3)->pluck('code')->all();
        $this->assertEqualsCanonicalizing($expected, array_map(fn ($s) => $s['supplier']['code'], $top['suppliers']));
        $this->assertContains($preferred->code, $expected);
        $this->assertNull($top['warehouse']);

        // pref = preferred supplier + alternates (same category first) up to three
        $pref = $this->expectOk($this->postAs('proc', '/api/procurement/rfq', ['lines' => $lines, 'invitedRule' => 'pref', 'closeDate' => self::future(3)]));
        $codes = array_map(fn ($s) => $s['supplier']['code'], $pref['suppliers']);
        $this->assertCount(3, $codes);
        $this->assertContains($preferred->code, $codes);
        $this->assertContains($sameCategory->code, $codes);

        // manual needs codes; unknown / inactive suppliers are refused and nothing is created
        $count = Rfq::count();
        $this->expectRejected($this->postAs('proc', '/api/procurement/rfq', ['lines' => $lines, 'invitedRule' => 'manual', 'closeDate' => self::future(3)]), 'SUPPLIERS_REQUIRED', [400]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/rfq', ['lines' => $lines, 'invitedRule' => 'manual', 'supplierCodes' => ['SUP-NOPE'], 'closeDate' => self::future(3)]), 'SUPPLIER_NOT_FOUND', [404]);
        $inactive = $this->makeSupplier(90, ['active' => false]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/rfq', ['lines' => $lines, 'invitedRule' => 'manual', 'supplierCodes' => [$inactive->code], 'closeDate' => self::future(3)]), 'SUPPLIER_INACTIVE', [422]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/rfq', ['lines' => $lines, 'closeDate' => '2020-01-01']), 'RFQ_CLOSE_DATE_PAST', [422]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/rfq', ['lines' => $lines, 'closeDate' => self::future(3), 'prNumber' => 'PR-0000-NOPE']), 'PR_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/rfq', ['lines' => $lines]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/rfq', ['lines' => $lines, 'closeDate' => self::future(3), 'invitedRule' => 'everyone']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/rfq', ['lines' => [], 'closeDate' => self::future(3)]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/procurement/rfq', ['lines' => $lines, 'closeDate' => self::future(3)]), 'FORBIDDEN', [403]);
        $this->assertSame($count, Rfq::count());

        // invite more suppliers later (default rule = manual); inviting twice does not duplicate
        $extra = $this->makeSupplier(70);
        $this->expectRejected($this->postAs('proc', "/api/procurement/rfq/{$cat['number']}/invite"), 'SUPPLIERS_REQUIRED', [400]);
        $invited = $this->expectOk($this->postAs('proc', "/api/procurement/rfq/{$cat['number']}/invite", ['supplierCodes' => [$extra->code, $preferred->code]]));
        $this->assertCount(3, $invited['suppliers']);
        $this->assertSame(1, AuditLog::where('entity_id', $cat['id'])->where('action', 'RFQ.INVITE')->count());

        // list: filters, counters
        $list = $this->expectOk($this->getAs('wm', "/api/procurement/rfq?status=open,quoted&q={$product->sku}"));
        $this->assertSame(3, $list['total']);
        $row = collect($list['items'])->firstWhere('number', $cat['number']);
        $this->assertSame(['suppliers' => 3, 'quotations' => 0], $row['_count']);
        $this->assertSame(['code' => 'RYD', 'nameAr' => $cat['warehouse']['nameAr'], 'nameEn' => $cat['warehouse']['nameEn']], $row['warehouse']);
        $this->assertSame($product->sku, $row['lines'][0]['product']['sku']);

        // cancel: body-less action; a cancelled RFQ accepts nothing
        $cancelled = $this->expectOk($this->postAs('proc', "/api/procurement/rfq/{$top['number']}/cancel"));
        $this->assertSame('cancelled', $cancelled['status']);
        $this->expectRejected($this->postAs('proc', "/api/procurement/rfq/{$top['number']}/cancel", ['reason' => 'مرة أخرى']), 'RFQ_NOT_OPEN', [422]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/rfq/{$top['number']}/invite", ['supplierCodes' => [$extra->code]]), 'RFQ_CLOSED', [422]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/quotations', $this->quote($preferred->code, $top['number'], [['sku' => $product->sku, 'price' => 10]])), 'RFQ_CLOSED', [422]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/rfq/{$top['number']}/award", ['quotation' => 'SQ-NOPE']), 'RFQ_NOT_AWARDABLE', [422]);
        $this->expectRejected($this->getAs('proc', '/api/procurement/rfq/RFQ-0000-NOPE'), 'RFQ_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('proc', '/api/procurement/rfq/RFQ-0000-NOPE/comparison'), 'RFQ_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/rfq/RFQ-0000-NOPE/award', ['quotation' => 'SQ-1']), 'RFQ_NOT_FOUND', [404]);
    }

    public function test_quotation_intake_rules(): void
    {
        $supplier = $this->makeSupplier(90);
        $product = $this->makeProduct(40);
        $line = [['sku' => $product->sku, 'price' => 15.2]];
        $count = SupplierQuotation::count();

        $this->expectRejected($this->postAs('wm', '/api/procurement/quotations', $this->quote($supplier->code, null, $line)), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/quotations', $this->quote($supplier->code, null, $line, ['validUntil' => '2020-01-01'])), 'QUOTE_EXPIRED', [422]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/quotations', $this->quote($supplier->code, null, $line, ['attachmentName' => 'offer.docx'])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/quotations', $this->quote($supplier->code, null, [['sku' => $product->sku, 'price' => 0]])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/quotations', $this->quote($supplier->code, null, $line, ['leadDays' => null])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/quotations', $this->quote($supplier->code, null, $line, ['supplierRef' => ''])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/quotations', $this->quote($supplier->code, null, [])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/quotations', $this->quote('SUP-NOPE', null, $line)), 'SUPPLIER_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/quotations', $this->quote($supplier->code, 'RFQ-0000-NOPE', $line)), 'RFQ_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('proc', '/api/procurement/quotations', $this->quote($supplier->code, null, [['sku' => 'NO-SUCH', 'price' => 5]])), 'PRODUCT_NOT_FOUND', [400]);
        $this->assertSame($count, SupplierQuotation::count());

        // a spontaneous quotation (no RFQ) is stored on its own
        $sq = $this->expectOk($this->postAs('proc', '/api/procurement/quotations', $this->quote($supplier->code, null, $line, ['attachmentName' => 'scan.PNG', 'date' => '2026-01-15', 'deliveryTerms' => 'DAP'])));
        $this->assertSame([null, 'PNG', 0, 4, 'DAP', '15.20'], [$sq['rfq'], $sq['attachmentType'], $sq['minOrder'], $sq['leadDays'], $sq['deliveryTerms'], $sq['lines'][0]['price']]);
        $this->assertStringStartsWith('2026-01-15', $sq['date']);
        $this->assertSame($supplier->code, $sq['supplier']['code']);
        $this->assertSame(1, $this->expectOk($this->getAs('proc', "/api/procurement/quotations?supplier={$supplier->code}&status=received"))['total']);
        $this->assertSame(1, $this->expectOk($this->getAs('proc', "/api/procurement/quotations?q={$sq['supplierRef']}"))['total']);
        $this->assertSame('image/png', $this->expectOk($this->getAs('proc', "/api/procurement/quotations/{$sq['id']}"))['attachments'][0]['mime']);
        $this->expectRejected($this->getAs('proc', '/api/procurement/quotations/SQ-0000-NOPE'), 'QUOTE_NOT_FOUND', [404]);

        // a supplier that was not invited joins the RFQ by quoting
        $invitedOnly = $this->makeSupplier(85);
        $rfq = $this->expectOk($this->postAs('proc', '/api/procurement/rfq', ['lines' => [['sku' => $product->sku, 'qty' => 10]], 'invitedRule' => 'manual', 'supplierCodes' => [$invitedOnly->code], 'closeDate' => self::future(3)]));
        $this->expectOk($this->postAs('proc', '/api/procurement/quotations', $this->quote($supplier->code, $rfq['id'], $line)));
        $after = $this->expectOk($this->getAs('proc', "/api/procurement/rfq/{$rfq['number']}"));
        $this->assertSame('quoted', $after['status']);
        $this->assertCount(2, $after['suppliers']);
        $this->assertCount(1, $after['quotations']);
        $this->assertSame([], $after['pos']);
    }

    public function test_comparison_edge_cases_and_award_rejections(): void
    {
        $good = $this->makeSupplier(90, ['otif' => 80]);
        $low = $this->makeSupplier(50, ['otif' => 70, 'is_new' => true]);
        $partial = $this->makeSupplier(88);
        $a = $this->makeProduct(40);
        $b = $this->makeProduct(20);
        $rfq = $this->expectOk($this->postAs('proc', '/api/procurement/rfq', ['lines' => [['sku' => $a->sku, 'qty' => 10], ['sku' => $b->sku, 'qty' => 20]], 'invitedRule' => 'manual', 'supplierCodes' => [$good->code, $low->code, $partial->code], 'closeDate' => self::future(3)]));

        $empty = $this->expectOk($this->getAs('proc', "/api/procurement/rfq/{$rfq['number']}/comparison"));
        $this->assertSame([[], null], [$empty['quotes'], $empty['rec']]);
        $this->assertNull($empty['rfq']['warehouse']);

        $qGood = $this->expectOk($this->postAs('proc', '/api/procurement/quotations', $this->quote($good->code, $rfq['number'], [['sku' => $a->sku, 'price' => 40], ['sku' => $b->sku, 'price' => 20, 'leadDays' => 9]], ['leadDays' => 0])));
        $qLow = $this->expectOk($this->postAs('proc', '/api/procurement/quotations', $this->quote($low->code, $rfq['number'], [['sku' => $a->sku, 'price' => 30], ['sku' => $b->sku, 'price' => 15]], ['leadDays' => 2])));
        $qPartial = $this->expectOk($this->postAs('proc', '/api/procurement/quotations', $this->quote($partial->code, $rfq['number'], [['sku' => $a->sku, 'price' => 1]])));

        $cmp = $this->expectOk($this->getAs('proc', "/api/procurement/rfq/{$rfq['number']}/comparison"));
        $this->assertSame('compared', $cmp['rfq']['status']);
        $rows = array_column($cmp['quotes'], null, 'quotationId');
        $this->assertSame([$qGood['id'], $qLow['id'], $qPartial['id']], array_column($cmp['quotes'], 'quotationId'), 'quotes keep their arrival order');
        $this->assertEquals([800, 9, true], [$rows[$qGood['id']]['price'], $rows[$qGood['id']]['lead'], $rows[$qGood['id']]['complete']], 'lead falls back to the slowest line');
        $this->assertEquals([600, 20, 2], [$rows[$qLow['id']]['price'], $rows[$qLow['id']]['unitPrice'], $rows[$qLow['id']]['lead']]);
        $this->assertFalse($rows[$qPartial['id']]['complete']);
        $this->assertNull($rows[$qPartial['id']]['weighted']);
        $this->assertContains('العرض لا يغطي كل بنود الطلب', $rows[$qPartial['id']]['reasons']);
        // low: 0.5 + 0.3 + 0.2×(90/50) = 1.16 ; good: 0.5×(800/600) + 0.3×(10/3) + 0.2 = 1.87
        $this->assertEquals([1.16, 1.87], [$rows[$qLow['id']]['weighted'], $rows[$qGood['id']]['weighted']]);
        $this->assertSame($qLow['id'], $cmp['rec']);
        $this->assertContains('تقييم المورد 50 دون الحد الأدنى (65) — OTIF 70% وتأخر سابق', $rows[$qLow['id']]['reasons']);
        $this->assertContains('مورد جديد قيد التقييم', $rows[$qLow['id']]['reasons']);
        $this->assertContains('أعلى من السعر الأدنى بـ 33%', $rows[$qGood['id']]['reasons']);
        $this->assertContains('مهلة توريد 9 يومًا (الأسرع 2)', $rows[$qGood['id']]['reasons']);
        $this->assertContains('أعلى تقييم مورد (90)', $rows[$qGood['id']]['reasons']);
        $this->assertContains('OTIF 80% منخفض', $rows[$qGood['id']]['reasons']);

        // award rejections — each one leaves the RFQ untouched
        $pos = PurchaseOrder::count();
        $foreign = $this->expectOk($this->postAs('proc', '/api/procurement/quotations', $this->quote($good->code, null, [['sku' => $a->sku, 'price' => 40]])));
        $award = "/api/procurement/rfq/{$rfq['number']}/award";
        $this->expectRejected($this->postAs('proc', $award), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', $award, ['quotation' => $foreign['number'], 'warehouseCode' => 'RYD']), 'QUOTE_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('proc', $award, ['quotation' => $qGood['number']]), 'WAREHOUSE_REQUIRED', [400]);
        $this->expectRejected($this->postAs('proc', $award, ['quotation' => $qGood['number'], 'warehouseCode' => 'NOPE']), 'WAREHOUSE_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('proc', $award, ['quotation' => $qPartial['number'], 'warehouseCode' => 'RYD']), 'QUOTE_INCOMPLETE', [422]);
        $this->expectRejected($this->postAs('proc', $award, ['quotation' => $qLow['number'], 'warehouseCode' => 'RYD']), 'SUPPLIER_SCORE_LOW', [422]);
        SupplierQuotation::where('id', $qGood['id'])->update(['valid_until' => now()->subDays(2)]);
        $this->expectRejected($this->postAs('proc', $award, ['quotation' => $qGood['id'], 'warehouseCode' => 'RYD']), 'QUOTE_EXPIRED', [422]);
        $expired = $this->expectOk($this->getAs('proc', "/api/procurement/rfq/{$rfq['number']}/comparison"));
        $this->assertTrue(array_column($expired['quotes'], null, 'quotationId')[$qGood['id']]['expired']);
        $this->assertContains('العرض منتهي الصلاحية', array_column($expired['quotes'], null, 'quotationId')[$qGood['id']]['reasons']);
        $this->assertSame($pos, PurchaseOrder::count());
        $this->assertSame(['compared', null], [Rfq::find($rfq['id'])->status, Rfq::find($rfq['id'])->awarded_quotation_id]);
        $this->assertSame(['received', 'received', 'received'], SupplierQuotation::where('rfq_id', $rfq['id'])->orderBy('created_at')->orderBy('id')->pluck('status')->all());

        // documented override: award the low-score supplier; due date defaults to today + quotation lead days
        $out = $this->expectOk($this->postAs('proc', $award, ['quotation' => $qLow['number'], 'warehouseCode' => 'dmm', 'overrideSupplierScore' => true, 'overrideReason' => 'أفضل سعر ومهلة', 'notes' => 'ترسية باستثناء']));
        $this->assertSame(['pending', '600.00', 'DMM', 'ترسية باستثناء'], [$out['po']['status'], $out['po']['total'], $out['po']['warehouse']['code'], $out['po']['notes']]);
        $this->assertStringStartsWith(self::future(2), $out['po']['dueDate']);
        $this->assertSame([[1, 10, '30.00'], [2, 20, '15.00']], array_map(fn ($l) => [$l['lineNo'], $l['qty'], $l['price']], $out['po']['lines']));
        $this->assertSame('awarded', $out['rfq']['status']);
        $this->assertNotNull($out['rfq']['awardedAt']);
        $this->assertSame(1, AuditLog::where('entity_id', $out['po']['id'])->where('action', 'PO.SUPPLIER_SCORE_OVERRIDE')->where('new_value', 'like', '%أفضل سعر ومهلة%')->count());
        $this->assertSame(1, AuditLog::where('entity_id', $rfq['id'])->where('action', 'RFQ.AWARD')->count());
        $detail = $this->expectOk($this->getAs('proc', "/api/procurement/rfq/{$rfq['number']}"));
        $this->assertSame([$out['po']['number']], array_column($detail['pos'], 'number'));
        $this->assertSame(1, $this->expectOk($this->getAs('proc', "/api/procurement/quotations?rfq={$rfq['number']}&status=awarded"))['total']);
        $this->assertSame(2, $this->expectOk($this->getAs('proc', "/api/procurement/quotations?rfq={$rfq['number']}&status=lost"))['total']);
        $this->expectRejected($this->postAs('proc', '/api/procurement/quotations', $this->quote($good->code, $rfq['number'], [['sku' => $a->sku, 'price' => 40]])), 'RFQ_CLOSED', [422]);
    }
}
