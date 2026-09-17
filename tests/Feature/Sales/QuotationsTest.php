<?php

namespace Tests\Feature\Sales;

use App\Models\AuditLog;
use App\Models\Quotation;
use App\Models\SalesOrder;
use Tests\ApiTestCase;

/** Customer quotations: create / send / approve / reject / duplicate / convert (acceptance step 4 of the reference). */
class QuotationsTest extends ApiTestCase
{
    use SalesFixtures;

    public function test_lifecycle_create_approve_convert_reserves_stock(): void
    {
        $customer = $this->makeCustomer(200000);
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-1-B1', 480);

        $qt = $this->expectOk($this->postAs('sales', '/api/sales/quotations', [
            'customerCode' => $customer->code, 'validUntil' => now()->addDays(30)->toDateString(), 'terms' => 'آجل 30 يومًا', 'delivery' => 'توصيل — نافذة 09–13',
            'action' => 'sent', 'lines' => [['sku' => $product->sku, 'qty' => 100, 'price' => 60, 'discPct' => 5]],
        ]));
        $this->assertStringStartsWith('QT-', $qt['number']);
        $this->assertSame('sent', $qt['status']);
        $this->assertEqualsWithDelta(100 * 60 * 0.95, $qt['totals']['sub'], 0.001);
        $this->assertEquals(15, $qt['totals']['vatPct']);
        $this->assertEqualsWithDelta(855.0, $qt['totals']['vat'], 0.001);
        $this->assertEqualsWithDelta(100 * 60 * 0.95 * 1.15, $qt['totals']['total'], 0.001);
        $this->assertSame($customer->code, $qt['customer']['code']);
        $this->assertSame(['sku', 'nameAr', 'nameEn', 'baseUom'], array_keys($qt['lines'][0]['product']));
        $this->assertSame('60.00', $qt['lines'][0]['price'], 'Decimal columns serialise as strings');
        $this->assertNull($qt['so']);
        $this->assertSame([], $qt['history']);

        // conversion rules: not approved yet
        $this->expectRejected($this->postAs('sales', "/api/sales/quotations/{$qt['number']}/convert"), 'QT_NOT_APPROVED', [422]);
        $approved = $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$qt['number']}/approve")); // action button: no body
        $this->assertSame('approved', $approved['status']);

        $so = $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$qt['id']}/convert", ['warehouseCode' => 'RYD', 'dueDate' => now()->addDays(2)->toDateString()]));
        $this->assertStringStartsWith('SO-', $so['number']);
        $this->assertSame('allocated', $so['status']);
        $this->assertSame(100, $so['lines'][0]['reservedQty']);
        $this->assertSame('A-07-1-B1', $so['lines'][0]['allocations'][0]['bin']['code']);
        $this->assertSame($qt['number'], $so['quotation']['number']);
        $this->assertSame('09:00–13:00', $so['window']);
        $this->assertSame(380, $this->inventory()->availability($product->id, $this->ryd()->id)['total']);

        // "already converted" is checked before "not approved"
        $this->expectRejected($this->postAs('sales', "/api/sales/quotations/{$qt['number']}/convert"), 'QT_CONVERTED', [422]);
        $after = $this->expectOk($this->getAs('sales', "/api/sales/quotations/{$qt['number']}"));
        $this->assertSame('converted', $after['status']);
        $this->assertSame(['number' => $so['number'], 'status' => 'allocated'], $after['so']);
        $this->assertSame(['approved', 'converted'], array_column($after['history'], 'toStatus'));
        $this->expectRejected($this->postAs('sales', "/api/sales/quotations/{$qt['number']}/reject"), 'QT_TRANSITION', [422]);
        $this->assertReconciles();
    }

    public function test_send_reject_and_duplicate(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $draft = $this->expectOk($this->postAs('sales', '/api/sales/quotations', [
            'customerCode' => $customer->code, 'validUntil' => now()->addDays(10)->toDateString(), 'notes' => 'ملاحظة', 'lines' => [['sku' => $product->sku, 'qty' => '3', 'price' => '12.50']],
        ]));
        $this->assertSame('draft', $draft['status'], 'action defaults to draft');
        $this->assertSame(3, $draft['lines'][0]['qty'], 'numeric strings are coerced');

        $this->assertSame('sent', $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$draft['number']}/send"))['status']);
        $this->expectRejected($this->postAs('sales', "/api/sales/quotations/{$draft['number']}/send"), 'QT_TRANSITION', [422]);
        $this->assertSame('rejected', $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$draft['number']}/reject", ['reason' => 'السعر مرتفع']))['status']);
        $this->expectRejected($this->postAs('sales', "/api/sales/quotations/{$draft['number']}/approve"), 'QT_TRANSITION', [422]);
        $detail = $this->expectOk($this->getAs('sales', "/api/sales/quotations/{$draft['number']}"));
        $this->assertSame(['sent', 'rejected'], array_column($detail['history'], 'toStatus'));
        $this->assertSame('السعر مرتفع', $detail['history'][1]['note']);

        $copy = $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$draft['number']}/duplicate"));
        $this->assertNotSame($draft['number'], $copy['number']);
        $this->assertSame('draft', $copy['status']);
        $this->assertSame('ملاحظة', $copy['notes']);
        $this->assertSame([$product->sku, 3, '12.50'], [$copy['lines'][0]['product']['sku'], $copy['lines'][0]['qty'], $copy['lines'][0]['price']]);

        $list = $this->expectOk($this->getAs('sales', "/api/sales/quotations?customer={$customer->code}&status=draft"));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($list));
        $this->assertSame([$copy['number']], array_column($list['items'], 'number'));
        $this->assertSame(['code', 'nameAr', 'nameEn'], array_keys($list['items'][0]['customer']));
        $this->assertArrayHasKey('total', $list['items'][0]['totals']);
        $this->assertSame(1, $this->expectOk($this->getAs('sales', "/api/sales/quotations?q={$draft['number']}"))['total']);
    }

    public function test_business_rules_and_validation(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $body = fn (array $over = [], array $line = []) => $over + ['customerCode' => $customer->code, 'validUntil' => now()->addDays(10)->toDateString(), 'lines' => [$line + ['sku' => $product->sku, 'qty' => 1, 'price' => 10]]];

        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body([], ['discPct' => 45])), 'DISCOUNT_APPROVAL', [422]);
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body(['validUntil' => now()->subDays(2)->toDateString()])), 'VALID_PAST', [400]);
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body([], ['sku' => 'NO-SUCH-SKU'])), 'SKU_NOT_FOUND', [400]);
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body(['customerCode' => 'CUS-NOPE'])), 'CUSTOMER_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body(['lines' => []])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body([], ['price' => 0])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body(['validUntil' => '15/10/2026'])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body(['action' => 'approved'])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->getAs('sales', '/api/sales/quotations/QT-NOPE'), 'QT_NOT_FOUND', [404]);
        $this->assertSame(0, Quotation::where('customer_id', $customer->id)->count(), 'a rejected quotation leaves nothing behind');
    }

    public function test_permission_denied_changes_nothing(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-1-B2', 20);
        $denied = AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count();

        $this->expectRejected($this->postAs('worker', '/api/sales/quotations', ['customerCode' => $customer->code, 'validUntil' => now()->addDays(5)->toDateString(), 'lines' => [['sku' => $product->sku, 'qty' => 1, 'price' => 10]]]), 'FORBIDDEN', [403]);
        $this->assertSame(0, Quotation::where('customer_id', $customer->id)->count());

        $qt = $this->expectOk($this->postAs('sales', '/api/sales/quotations', ['customerCode' => $customer->code, 'validUntil' => now()->addDays(5)->toDateString(), 'lines' => [['sku' => $product->sku, 'qty' => 5, 'price' => 10]]]));
        $this->expectRejected($this->postAs('wm', "/api/sales/quotations/{$qt['number']}/approve"), 'FORBIDDEN', [403]);
        $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$qt['number']}/approve"));
        // converting needs so.reserve: the warehouse manager has no sales permissions
        $this->expectRejected($this->postAs('wm', "/api/sales/quotations/{$qt['number']}/convert"), 'FORBIDDEN', [403]);
        $this->assertSame('approved', Quotation::where('number', $qt['number'])->value('status'));
        $this->assertSame(0, SalesOrder::where('customer_id', $customer->id)->count());
        $this->assertSame(0, $this->reserved($product));
        $this->assertSame($denied + 3, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count(), 'every denial is audited');
        // reading stays open to every signed-in user
        $this->expectOk($this->getAs('worker', "/api/sales/quotations/{$qt['number']}"));
    }
}
