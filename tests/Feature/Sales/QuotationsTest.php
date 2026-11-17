<?php

namespace Tests\Feature\Sales;

use App\Models\AuditLog;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Services\Core\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Tests\ApiTestCase;

/**
 * Customer quotations: create / send / approve / reject / duplicate / convert (acceptance step 4 of the reference),
 * the discount rule (above the limit → a holder of discount.approve) and validity (expired quotations, extension).
 */
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
        // where it stands against the two rules a quotation is checked for
        $this->assertEquals(['maxPct' => 30, 'linePct' => 5, 'aboveMax' => false, 'approvedBy' => null, 'needsApproval' => false], $qt['discount']);
        $this->assertSame(['expired' => false, 'daysLeft' => 30], $qt['validity']);

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
        $this->assertNull($so['discountApprovedBy']);
        $this->assertSame(380, $this->inventory()->availability($product->id, $this->ryd()->id)['total']);

        // "already converted" is checked before "not approved"
        $this->expectRejected($this->postAs('sales', "/api/sales/quotations/{$qt['number']}/convert"), 'QT_CONVERTED', [422]);
        $after = $this->expectOk($this->getAs('sales', "/api/sales/quotations/{$qt['number']}"));
        $this->assertSame('converted', $after['status']);
        $this->assertSame(['number' => $so['number'], 'status' => 'allocated'], $after['so']);
        $this->assertSame(['approved', 'converted'], array_column($after['history'], 'toStatus'));
        $this->expectRejected($this->postAs('sales', "/api/sales/quotations/{$qt['number']}/reject"), 'QT_TRANSITION', [422]);
        $this->expectRejected($this->postAs('sales', "/api/sales/quotations/{$qt['number']}/extend", ['validUntil' => now()->addDays(40)->toDateString()]), 'QT_CLOSED', [422]);
        $this->assertReconciles();
    }

    public function test_convert_with_a_shortage_is_refused_then_accepted_with_a_backorder(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-1-B3', 6);
        $qt = $this->expectOk($this->postAs('sales', '/api/sales/quotations', ['customerCode' => $customer->code, 'validUntil' => now()->addDays(5)->toDateString(), 'lines' => [['sku' => $product->sku, 'qty' => 10, 'price' => 10]]]));
        $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$qt['number']}/approve"));

        $short = $this->expectRejected($this->postAs('sales', "/api/sales/quotations/{$qt['number']}/convert"), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame([6, 10, true], [$short['details']['available'], $short['details']['requested'], $short['details']['backorderAllowed']]);
        $this->assertSame('approved', Quotation::where('number', $qt['number'])->value('status'), 'the refusal left the quotation convertible');

        $so = $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$qt['number']}/convert", ['allowBackorder' => true]));
        $this->assertSame(['allocated', 6, 10, 4], [$so['status'], $so['lines'][0]['qty'], $so['lines'][0]['orderedQty'], $so['lines'][0]['shortQty']]);
        $this->assertCount(1, $so['backorders']);
        $this->assertSame('converted', Quotation::where('number', $qt['number'])->value('status'));
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
        $this->assertFalse($list['items'][0]['discount']['needsApproval']);
        $this->assertFalse($list['items'][0]['validity']['expired']);
        $this->assertSame(1, $this->expectOk($this->getAs('sales', "/api/sales/quotations?q={$draft['number']}"))['total']);
    }

    public function test_business_rules_and_validation(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $body = fn (array $over = [], array $line = []) => $over + ['customerCode' => $customer->code, 'validUntil' => now()->addDays(10)->toDateString(), 'lines' => [$line + ['sku' => $product->sku, 'qty' => 1, 'price' => 10]]];

        // a discount above the limit cannot go straight to the customer from a user who may not approve it
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body(['action' => 'sent'], ['discPct' => 45])), 'DISCOUNT_APPROVAL', [422]);
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body(['validUntil' => now()->subDays(2)->toDateString()])), 'VALID_PAST', [400]);
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body([], ['sku' => 'NO-SUCH-SKU'])), 'SKU_NOT_FOUND', [400]);
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body(['customerCode' => 'CUS-NOPE'])), 'CUSTOMER_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body(['lines' => []])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body([], ['price' => 0])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body([], ['discPct' => 101])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body(['validUntil' => '15/10/2026'])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/sales/quotations', $body(['action' => 'approved'])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->getAs('sales', '/api/sales/quotations/QT-NOPE'), 'QT_NOT_FOUND', [404]);
        $this->assertSame(0, Quotation::where('customer_id', $customer->id)->count(), 'a rejected quotation leaves nothing behind');
    }

    /** Rewritten for the 2026-11 rule: the draft is saved; sending / approving waits for the discount approval. */
    public function test_a_discount_above_the_limit_waits_for_the_discount_approver(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-2-B1', 20);
        $create = fn (string $user, float $disc, string $action = 'draft') => $this->postAs($user, '/api/sales/quotations', ['customerCode' => $customer->code, 'validUntil' => now()->addDays(10)->toDateString(),
            'action' => $action, 'lines' => [['sku' => $product->sku, 'qty' => 2, 'price' => 100, 'discPct' => $disc]]]);
        $this->assertEquals(['maxDiscountPct' => 30, 'vatPct' => 15, 'allowBackorder' => true, 'zeroCreditLimitUnlimited' => true], $this->expectOk($this->getAs('sales', '/api/sales/policy')));

        $qt = $this->expectOk($create('sales', 45));
        $this->assertSame('draft', $qt['status']);
        $this->assertEquals(['maxPct' => 30, 'linePct' => 45, 'aboveMax' => true, 'approvedBy' => null, 'needsApproval' => true], $qt['discount']);
        $url = "/api/sales/quotations/{$qt['number']}";

        // the approver's work list
        $pending = fn () => array_column($this->expectOk($this->getAs('gm', "/api/sales/quotations?discount=pending&customer={$customer->code}"))['items'], 'number');
        $this->assertSame([$qt['number']], $pending());

        // it cannot reach the customer, by either door
        $refused = $this->expectRejected($this->postAs('sales', "{$url}/send"), 'DISCOUNT_APPROVAL', [422]);
        $this->assertEquals(['discountPct' => 45, 'maxPct' => 30], $refused['details']);
        $this->expectRejected($this->postAs('sales', "{$url}/approve"), 'DISCOUNT_APPROVAL', [422]);
        $this->assertSame('draft', Quotation::where('number', $qt['number'])->value('status'));
        // the seller cannot approve the discount himself
        $this->expectRejected($this->postAs('sales', "{$url}/approve-discount"), 'FORBIDDEN', [403]);
        $this->assertNull(Quotation::where('number', $qt['number'])->value('discount_approved_by'));

        // the holder of discount.approve allows it (action button: no body) — once
        $ok = $this->expectOk($this->postAs('gm', "{$url}/approve-discount"));
        $this->assertSame(['gm', false, 'draft'], [$ok['discount']['approvedBy'], $ok['discount']['needsApproval'], $ok['status']]);
        $this->assertSame('gm', $this->expectOk($this->postAs('admin', "{$url}/approve-discount", ['note' => 'مرة ثانية']))['discount']['approvedBy'], 'idempotent: the first approver stays');
        $this->assertSame(1, AuditLog::where('action', 'QT.DISCOUNT_APPROVE')->where('entity_number', $qt['number'])->count());
        $this->assertSame([], $pending());

        // now the normal path is open, and the order remembers who allowed the discount
        $this->assertSame('sent', $this->expectOk($this->postAs('sales', "{$url}/send"))['status']);
        $this->assertSame('approved', $this->expectOk($this->postAs('sales', "{$url}/approve"))['status']);
        $so = $this->expectOk($this->postAs('sales', "{$url}/convert"));
        $this->assertSame('gm', $so['discountApprovedBy']);
        $this->assertEquals(126.5, $so['totals']['total']);
        $this->expectRejected($this->postAs('gm', "{$url}/approve-discount"), 'QT_CLOSED', [422]);

        // nothing to approve on a quotation within the limit; an approver's own quotation is stamped at creation
        $within = $this->expectOk($create('sales', 30));
        $this->expectRejected($this->postAs('gm', "/api/sales/quotations/{$within['number']}/approve-discount"), 'DISCOUNT_NOT_NEEDED', [422]);
        $own = $this->expectOk($create('admin', 45, 'sent'));
        $this->assertSame(['sent', 'admin'], [$own['status'], $own['discount']['approvedBy']]);
        // a copy is a new offer: its discount is approved again
        $copy = $this->expectOk($this->postAs('sales', "{$url}/duplicate"));
        $this->assertTrue($copy['discount']['needsApproval']);

        // the limit is a policy: when it is lowered, an already approved quotation above the new limit is held at conversion
        $old = $this->expectOk($create('sales', 20));
        $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$old['number']}/approve"));
        $settings = $this->app->make(SettingsService::class);
        $settings->set('sales.maxDiscountPct', 10);
        try {
            $this->assertEquals(10, $this->expectOk($this->getAs('sales', '/api/sales/policy'))['maxDiscountPct']);
            $held = $this->expectRejected($this->postAs('sales', "/api/sales/quotations/{$old['number']}/convert"), 'DISCOUNT_APPROVAL', [422]);
            $this->assertEquals(['discountPct' => 20, 'maxPct' => 10], $held['details']);
            $this->assertSame('approved', Quotation::where('number', $old['number'])->value('status'));
            $this->expectOk($this->postAs('gm', "/api/sales/quotations/{$old['number']}/approve-discount"));
            $this->assertSame('gm', $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$old['number']}/convert"))['discountApprovedBy']);
        } finally {
            $settings->set('sales.maxDiscountPct', 30);
        }
        $this->assertReconciles();
    }

    public function test_a_quotation_past_its_validity_expires_and_can_be_extended(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-2-B2', 20);
        $new = fn (string $action = 'draft') => $this->expectOk($this->postAs('sales', '/api/sales/quotations', ['customerCode' => $customer->code, 'validUntil' => now()->addDays(10)->toDateString(),
            'action' => $action, 'lines' => [['sku' => $product->sku, 'qty' => 2, 'price' => 10]]]));
        $lapse = fn (array $qt, int $days = 3) => Quotation::where('number', $qt['number'])->update(['valid_until' => now()->subDays($days)]); // time passes

        // valid through its last day
        $today = $new('sent');
        Quotation::where('number', $today['number'])->update(['valid_until' => now()->startOfDay()]);
        $this->assertSame(['expired' => false, 'daysLeft' => 0], $this->expectOk($this->getAs('sales', "/api/sales/quotations/{$today['number']}"))['validity']);
        $this->assertSame('approved', $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$today['number']}/approve"))['status']);

        // approve / send / convert refuse a lapsed quotation — and the refusal leaves it expired, not as it was
        $sent = $new('sent');
        $url = "/api/sales/quotations/{$sent['number']}";
        $lapse($sent);
        $this->assertTrue($this->expectOk($this->getAs('sales', $url))['validity']['expired'], 'shown as expired even before anything moved it');
        $body = $this->expectRejected($this->postAs('sales', "{$url}/approve"), 'QT_EXPIRED', [422]);
        $this->assertSame(now()->subDays(3)->toDateString(), $body['details']['validUntil']);
        $this->assertSame('expired', Quotation::where('number', $sent['number'])->value('status'));
        $this->expectRejected($this->postAs('sales', "{$url}/approve"), 'QT_EXPIRED', [422]);
        $this->expectRejected($this->postAs('sales', "{$url}/send"), 'QT_EXPIRED', [422]);
        $this->expectRejected($this->postAs('sales', "{$url}/convert"), 'QT_EXPIRED', [422]);
        $draft = $new();
        $lapse($draft);
        $this->expectRejected($this->postAs('sales', "/api/sales/quotations/{$draft['number']}/send"), 'QT_EXPIRED', [422]);
        $approved = $new();
        $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$approved['number']}/approve"));
        $lapse($approved);
        $this->expectRejected($this->postAs('sales', "/api/sales/quotations/{$approved['number']}/convert"), 'QT_EXPIRED', [422]);
        $this->assertSame(['expired', 'expired'], Quotation::whereIn('number', [$draft['number'], $approved['number']])->pluck('status')->all());
        $this->assertSame(0, SalesOrder::where('customer_id', $customer->id)->count());
        $this->assertSame(0, $this->reserved($product));
        // rejecting an expired quotation is not a way out either: the exit is the extension
        $this->expectRejected($this->postAs('sales', "{$url}/reject"), 'QT_TRANSITION', [422]);

        // extension: rules, then expired → draft with the new date, and the normal path again
        $this->expectRejected($this->postAs('sales', "{$url}/extend"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', "{$url}/extend", ['validUntil' => now()->subDay()->toDateString()]), 'VALID_PAST', [400]);
        $this->expectRejected($this->postAs('worker', "{$url}/extend", ['validUntil' => now()->addDays(7)->toDateString()]), 'FORBIDDEN', [403]);
        $this->assertSame('expired', Quotation::where('number', $sent['number'])->value('status'));
        $back = $this->expectOk($this->postAs('sales', "{$url}/extend", ['validUntil' => now()->addDays(7)->toDateString()]));
        $this->assertSame(['draft', ['expired' => false, 'daysLeft' => 7]], [$back['status'], $back['validity']]);
        $this->assertSame(['expired', 'draft'], array_column($back['history'], 'toStatus'));
        $this->assertTrue(AuditLog::where('action', 'QT.EXTEND')->where('entity_number', $sent['number'])->exists());
        $this->expectOk($this->postAs('sales', "{$url}/send"));
        $this->expectOk($this->postAs('sales', "{$url}/approve"));
        $this->assertSame('allocated', $this->expectOk($this->postAs('sales', "{$url}/convert"))['status']);
        // an open quotation simply gets the new date
        $open = $new('sent');
        $later = $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$open['number']}/extend", ['validUntil' => now()->addDays(40)->toDateString()]));
        $this->assertSame(['sent', 40, []], [$later['status'], $later['validity']['daysLeft'], $later['history']]);
        $this->assertReconciles();
    }

    public function test_the_daily_command_expires_every_open_quotation_past_its_date(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $new = fn (string $action = 'draft') => $this->expectOk($this->postAs('sales', '/api/sales/quotations', ['customerCode' => $customer->code, 'validUntil' => now()->addDays(10)->toDateString(),
            'action' => $action, 'lines' => [['sku' => $product->sku, 'qty' => 1, 'price' => 10]]]))['number'];
        [$draft, $sent, $approved, $valid, $rejected] = [$new(), $new('sent'), $new(), $new('sent'), $new()];
        $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$approved}/approve"));
        $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$rejected}/reject"));
        Quotation::whereIn('number', [$draft, $sent, $approved, $rejected])->update(['valid_until' => now()->subDay()]);

        $this->assertSame(0, Artisan::call('scm:quotations-expire'));
        $out = json_decode(trim(Artisan::output()), true);
        $this->assertEmpty(array_diff([$draft, $sent, $approved], $out['numbers']));
        $this->assertNotContains($valid, $out['numbers']);
        $this->assertNotContains($rejected, $out['numbers']);
        $status = fn (string $n) => Quotation::where('number', $n)->value('status');
        $this->assertSame(['expired', 'expired', 'expired', 'sent', 'rejected'], array_map($status, [$draft, $sent, $approved, $valid, $rejected]));
        $history = $this->expectOk($this->getAs('sales', "/api/sales/quotations/{$sent}"))['history'];
        $this->assertSame(['sent', 'expired', 'system'], [$history[0]['fromStatus'], $history[0]['toStatus'], $history[0]['username']]);
        $this->assertSame(0, Artisan::call('scm:quotations-expire'));
        $this->assertNotContains($draft, json_decode(trim(Artisan::output()), true)['numbers'], 'nothing is expired twice');
        $this->assertEqualsCanonicalizing([$draft, $sent, $approved], array_column($this->expectOk($this->getAs('sales', "/api/sales/quotations?customer={$customer->code}&status=expired"))['items'], 'number'));
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
