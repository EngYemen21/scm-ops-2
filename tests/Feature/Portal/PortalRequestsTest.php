<?php

namespace Tests\Feature\Portal;

use App\Models\AuditLog;
use App\Models\Quotation;
use App\Portal\Models\LineChange;
use App\Portal\Models\OrderRequest;
use App\Portal\Models\OrderRequestLine;
use Tests\ApiTestCase;

/**
 * Order requests and the log of order-line changes: a request is entered, its lines are corrected (every correction is
 * logged with who, what, before and after), it is approved into a draft quotation or refused — and the log is read by
 * page with its filters.
 */
class PortalRequestsTest extends ApiTestCase
{
    use PortalFixtures;

    private function act(string $id, string $action, array $body = [], string $as = 'sales')
    {
        return $this->postAs($as, "/api/portal/requests/{$id}/{$action}", $body);
    }

    private function log(string $query): array
    {
        return $this->expectOk($this->getAs('gm', '/api/portal/line-changes?'.$query));
    }

    public function test_a_request_is_entered_corrected_and_approved_into_a_quotation(): void
    {
        $customer = $this->makeCustomer();
        $branch = $this->expectOk($this->postAs('sales', '/api/portal/branches', ['customerId' => $customer->code, 'name' => 'فرع الطلبات']));
        [$a, $b, $c, $d] = [$this->makeProduct(), $this->makeProduct(), $this->makeProduct(), $this->makeProduct()];
        $body = ['customerId' => $customer->code, 'branchId' => $branch['id'], 'requestedBy' => 'مدير الفرع', 'note' => 'تسليم صباحي',
            'lines' => [['sku' => $a->sku, 'qty' => 10, 'price' => 12.5], ['sku' => $b->sku, 'qty' => 4, 'price' => 30], ['sku' => $c->sku, 'qty' => 2]]];

        $this->expectRejected($this->postAs('gm', '/api/portal/requests', $body), 'FORBIDDEN', [403]);
        $r = $this->expectOk($this->postAs('sales', '/api/portal/requests', $body));
        $this->assertStringStartsWith('ORQ-', $r['number']);
        $this->assertSame(['pending', 'مدير الفرع', '245.00', 'SAR', 3, null, null], [$r['status'], $r['requestedBy'], $r['amountTotal'], $r['currency'], $r['linesCount'], $r['order'], $r['quotation']]);
        $this->assertSame([$customer->code, $branch['id']], [$r['customer']['code'], $r['branch']['id']]);
        $this->assertSame([[1, $a->sku, '10', '12.50', '125.00'], [2, $b->sku, '4', '30.00', '120.00'], [3, $c->sku, '2', '0.00', '0.00']],
            array_map(fn (array $l) => [$l['lineNo'], $l['product']['sku'], $l['qty'], $l['price'], $l['subtotal']], $r['lines']));
        $this->assertSame(['editLines' => true, 'approve' => true, 'reject' => true, 'cancel' => true, 'note' => true], $r['can']);
        $this->assertSame([], $r['changes'], 'entering a request is not a change of its lines');
        $this->assertSame($r['id'], $this->expectOk($this->getAs('gm', "/api/portal/requests/{$r['number']}"))['id'], 'a request OPS numbered answers to its number');

        // the rules of a request's lines
        $bad = fn (array $lines, array $more = []) => $this->postAs('sales', '/api/portal/requests', $more + ['lines' => $lines] + $body);
        $this->expectRejected($bad([]), 'INVALID_INPUT', [400]);
        $this->expectRejected($bad([['sku' => $a->sku, 'qty' => 1.5, 'price' => 3]]), 'INVALID_INPUT', [400]);
        $this->expectRejected($bad([['sku' => 'NO-SUCH-SKU', 'qty' => 1]]), 'SKU_NOT_FOUND', [400]);
        $this->expectRejected($bad([['sku' => $a->sku, 'qty' => 1], ['sku' => $a->sku, 'qty' => 2]]), 'PORTAL_LINE_TWICE', [400]);
        $this->expectRejected($bad([['sku' => $this->makeProduct(['active' => false])->sku, 'qty' => 1]]), 'SKU_INACTIVE', [400]);
        $stranger = $this->expectOk($this->postAs('sales', '/api/portal/branches', ['customerId' => $this->makeCustomer()->code, 'name' => 'فرع عميل آخر']));
        $this->expectRejected($bad($body['lines'], ['branchId' => $stranger['id']]), 'PORTAL_BRANCH_CUSTOMER', [422]);
        $this->assertSame(1, OrderRequest::where('customer_id', $customer->id)->count(), 'a refused request leaves nothing behind');

        // a line without a price cannot become a quotation
        $noPrice = $this->expectRejected($this->act($r['id'], 'approve'), 'PORTAL_REQUEST_LINE', [422]);
        $this->assertSame(3, $noPrice['details']['line']);

        // corrected: A's quantity, B's price, C goes, D comes in — four lines of the log, each with before and after
        $this->expectRejected($this->patchAs('gm', "/api/portal/requests/{$r['id']}/lines", ['lines' => $body['lines']]), 'FORBIDDEN', [403]);
        $e = $this->expectOk($this->patchAs('sales', "/api/portal/requests/{$r['id']}/lines", ['lines' => [
            ['sku' => $a->sku, 'qty' => 12, 'price' => 12.5], ['sku' => $b->sku, 'qty' => 4, 'price' => 28], ['sku' => $d->sku, 'qty' => 5, 'price' => 8],
        ]]));
        $this->assertSame(['302.00', 3], [$e['amountTotal'], $e['linesCount']]);
        $this->assertSame([$a->sku, $b->sku, $d->sku], array_map(fn (array $l) => $l['product']['sku'], $e['lines']));
        $got = array_map(fn (array $x) => [$x['type'], $x['field'], $x['product']['sku'], $x['oldValue'], $x['newValue']], $e['changes']);
        $this->assertEqualsCanonicalizing([
            ['modified', 'qty', $a->sku, '10', '12'], ['modified', 'price', $b->sku, '30.00', '28.00'], ['added', null, $d->sku, null, '5'], ['removed', null, $c->sku, '2', null],
        ], $got);
        $one = $e['changes'][0];
        $this->assertSame([$r['id'], $r['number'], $customer->code, $branch['id'], null], [$one['request']['id'], $one['request']['number'], $one['customer']['code'], $one['branch']['id'], $one['order']]);
        $this->assertNotNull($one['changedBy']);
        $this->assertSame(['lines_changed', 'created'], array_column($e['events'], 'kind'));
        $this->assertSame([4, '245.00', '302.00'], [$e['events'][0]['data']['changes'], $e['events'][0]['data']['totalFrom'], $e['events'][0]['data']['totalTo']]);
        $this->assertTrue(AuditLog::where('action', 'PORTAL.REQUEST.LINES')->where('entity_id', $r['id'])->where('username', 'sales')->exists());
        // sending the same lines again changes nothing and logs nothing
        $this->expectOk($this->patchAs('sales', "/api/portal/requests/{$r['id']}/lines", ['lines' => [
            ['sku' => $a->sku, 'qty' => 12, 'price' => 12.5], ['sku' => $b->sku, 'qty' => 4, 'price' => 28], ['sku' => $d->sku, 'qty' => 5, 'price' => 8],
        ]]));
        $this->assertSame(4, LineChange::where('request_id', $r['id'])->count());

        // the log, read through its filters
        $mine = $this->log("request={$r['id']}");
        $this->assertSame([4, ['added' => 1, 'modified' => 2, 'removed' => 1]], [$mine['total'], $mine['totals']]);
        $this->assertSame([2, ['added' => 1, 'modified' => 2, 'removed' => 1]], [$this->log("request={$r['id']}&type=modified")['total'], $this->log("request={$r['id']}&type=modified")['totals']]);
        $this->assertSame([$b->sku], array_map(fn (array $x) => $x['product']['sku'], $this->log("request={$r['id']}&field=price")['items']));
        $this->assertSame(['removed'], array_column($this->log("request={$r['id']}&product={$c->sku}")['items'], 'type'));
        $this->assertSame(4, $this->log("customer={$customer->code}&origin=ops")['total']);
        $this->assertSame(0, $this->log("customer={$customer->code}&origin=odoo")['total']);
        $this->assertSame(4, $this->log('by='.urlencode($one['changedBy'])."&request={$r['id']}")['total']);

        // approving makes a quotation of the sales module: it needs its permission as well
        $quotations = Quotation::count();
        $this->expectRejected($this->act($r['id'], 'approve', [], $this->portalOnlyUser()), 'FORBIDDEN', [403]);
        $this->assertSame([$quotations, 'pending'], [Quotation::count(), OrderRequest::find($r['id'])->status]);
        $this->assertTrue(AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->where('username', 'portalonly')->where('new_value', 'denied: sales.manage')->exists());
        $done = $this->expectOk($this->act($r['id'], 'approve', ['note' => 'الأسعار مطابقة لقائمة العميل']));
        $this->assertSame(['approved', 'draft'], [$done['status'], $done['quotation']['status']]);
        $this->assertStringStartsWith('QT-', $done['quotation']['number']);
        $this->assertNotNull($done['decidedAt']);
        $this->assertIsNumeric($done['decisionHours']);
        $qt = $this->expectOk($this->getAs('sales', "/api/sales/quotations/{$done['quotation']['number']}"));
        $this->assertSame([$customer->code, 3], [$qt['customer']['code'], count($qt['lines'])]);
        $this->assertEqualsWithDelta(302 * 1.15, $qt['totals']['total'], 0.01);
        $this->assertStringContainsString($r['number'], $qt['notes']);
        $this->assertSame(['approved', $done['quotation']['number']], [$done['events'][0]['kind'], $done['events'][0]['data']['quotation']]);
        $this->assertSame(['editLines' => false, 'approve' => false, 'reject' => false, 'cancel' => false, 'note' => true], $done['can']);

        // decided is decided
        $this->expectRejected($this->act($r['id'], 'approve'), 'PORTAL_REQUEST_TRANSITION', [422]);
        $this->expectRejected($this->act($r['id'], 'reject', ['reason' => 'تراجع']), 'PORTAL_REQUEST_TRANSITION', [422]);
        $this->expectRejected($this->act($r['id'], 'cancel'), 'PORTAL_REQUEST_TRANSITION', [422]);
        $this->expectRejected($this->patchAs('sales', "/api/portal/requests/{$r['id']}/lines", ['lines' => [['sku' => $a->sku, 'qty' => 1, 'price' => 1]]]), 'PORTAL_REQUEST_DECIDED', [422]);
        $this->assertSame($quotations + 1, Quotation::count());
    }

    public function test_refusing_cancelling_and_the_lists(): void
    {
        $tag = self::tag();
        $customer = $this->makeCustomer(['name_ar' => "فندق الأفق {$tag}"]);
        $p = $this->makeProduct();
        $new = fn () => $this->expectOk($this->postAs('sales', '/api/portal/requests', ['customerId' => $customer->code, 'lines' => [['sku' => $p->sku, 'qty' => 3, 'price' => 20]]]));
        [$waiting, $refused, $cancelled] = [$new(), $new(), $new()];

        $this->expectRejected($this->act($refused['id'], 'reject'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->act($refused['id'], 'reject', ['reason' => 'خارج الحد الائتماني'], 'gm'), 'FORBIDDEN', [403]);
        $r = $this->expectOk($this->act($refused['id'], 'reject', ['reason' => 'خارج الحد الائتماني']));
        $this->assertSame(['rejected', 'خارج الحد الائتماني', null], [$r['status'], $r['rejectionReason'], $r['quotation']]);
        $this->assertNotNull($r['decidedBy']);
        $c = $this->expectOk($this->act($cancelled['id'], 'cancel')); // action button: no body
        $this->assertSame(['cancelled', ['cancelled', 'created']], [$c['status'], array_column($c['events'], 'kind')]);
        $this->expectRejected($this->act($cancelled['id'], 'approve'), 'PORTAL_REQUEST_TRANSITION', [422]);
        $n = $this->expectOk($this->act($cancelled['id'], 'notes', ['text' => 'ألغاه العميل هاتفيًا']));
        $this->assertSame(['note', 'ألغاه العميل هاتفيًا'], [$n['events'][0]['kind'], $n['events'][0]['note']]);
        $this->assertSame(2, AuditLog::where('entity_type', 'PortalOrderRequest')->whereIn('entity_id', [$refused['id'], $cancelled['id']])->where('action', 'STATUS')->count());

        $list = fn (string $query) => $this->expectOk($this->getAs('gm', "/api/portal/requests?customer={$customer->code}&".$query));
        $this->assertSame(3, $list('')['total']);
        $this->assertSame([$waiting['id']], array_column($list('status=pending')['items'], 'id'));
        $this->assertEqualsCanonicalizing([$refused['id'], $cancelled['id']], array_column($list('status=rejected,cancelled')['items'], 'id'));
        $this->assertSame(0, $list('origin=odoo')['total']);
        $this->assertSame([$waiting['id'], $refused['id'], $cancelled['id']], array_column($list('sort=number&order=asc')['items'], 'id'));
        $this->assertSame([[$cancelled['id']], 3, 2], [array_column($list('sort=number&order=asc&pageSize=2&page=2')['items'], 'id'), $list('pageSize=2')['total'], $list('pageSize=2')['pages']]);
        $this->assertSame(3, $this->expectOk($this->getAs('gm', '/api/portal/requests?q='.urlencode("الأفق {$tag}")))['total'], 'a request is found by its customer\'s name');
        $this->assertSame([$refused['id']], array_column($this->expectOk($this->getAs('gm', "/api/portal/requests?q={$refused['number']}"))['items'], 'id'));
        $today = now()->toDateString();
        $this->assertSame([3, 0], [$list("from={$today}&to={$today}")['total'], $list('to='.now()->subDay()->toDateString())['total']]);
        $this->expectRejected($this->getAs('wm', '/api/portal/requests'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('wm', '/api/portal/line-changes'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('gm', '/api/portal/requests/01NOSUCHREQUEST000000000000'), 'PORTAL_REQUEST_NOT_FOUND', [404]);
    }

    public function test_a_request_of_odoo_is_shown_with_its_order_and_what_was_changed_on_it(): void
    {
        $tag = self::tag();
        $customer = $this->makeCustomer();
        $p = $this->makeProduct();
        $orderRef = (string) random_int(100000, 999999);
        $r = OrderRequest::create(['number' => 'REQ/2026/'.random_int(10000, 99999), 'status' => 'approved', 'customer_id' => $customer->id, 'customer_ref' => '77', 'customer_name' => 'عميل أودو',
            'requested_by' => 'موظف العميل', 'request_date' => now()->subDays(3), 'decided_by' => 'مدير المبيعات', 'decided_at' => now()->subDays(2), 'amount_total' => 90, 'currency' => 'SAR',
            'order_ref' => $orderRef, 'order_number' => "S{$tag}", 'lines_count' => 2, 'source_system' => 'odoo', 'external_ref' => (string) random_int(100000, 999999)]);
        OrderRequestLine::create(['request_id' => $r->id, 'line_no' => 1, 'product_id' => $p->id, 'description' => 'صنف مربوط', 'qty' => 3, 'price' => 20, 'subtotal' => 60, 'source_system' => 'odoo', 'external_ref' => (string) random_int(100000, 999999)]);
        // a product of Odoo that OPS does not have yet keeps Odoo's id and name
        OrderRequestLine::create(['request_id' => $r->id, 'line_no' => 2, 'product_ref' => '4242', 'product_name' => 'صنف غير مربوط', 'description' => '[X1] صنف غير مربوط', 'qty' => 1.5, 'price' => 20, 'subtotal' => 30,
            'source_system' => 'odoo', 'external_ref' => (string) random_int(100000, 999999)]);
        // what was changed on the order it became — in Odoo, by the customer and by the sales team
        foreach ([['added', null, null, null, '3', 0], ['modified', 'qty', 'Quantity', '3', '5', 1], ['modified', 'price', 'Unit Price', '20.0', '18.5', 2], ['removed', null, null, '5', null, 3]] as [$type, $field, $label, $old, $new, $i]) {
            LineChange::create(['changed_at' => now()->subHours(10 - $i), 'change_type' => $type, 'field' => $field, 'field_label' => $label, 'old_value' => $old, 'new_value' => $new, 'changed_by' => $i < 2 ? 'موظف العميل' : 'مندوب المبيعات',
                'order_ref' => $orderRef, 'order_number' => "S{$tag}", 'customer_id' => $customer->id, 'customer_ref' => '77', 'customer_name' => 'عميل أودو',
                'product_id' => $i === 3 ? null : $p->id, 'product_ref' => $i === 3 ? '4242' : '31', 'product_name' => $i === 3 ? 'صنف غير مربوط' : 'اسم الصنف وقت التغيير',
                'source_system' => 'odoo', 'external_ref' => (string) random_int(1000000, 9999999)]);
        }

        $this->followOdoo(true);
        $shown = $this->expectOk($this->getAs('gm', "/api/portal/requests/{$r->id}"));
        $this->assertSame([true, 'approved', $orderRef, "S{$tag}"], [$shown['owned'], $shown['status'], $shown['order']['ref'], $shown['order']['number']]);
        $this->assertSame([[true, $p->sku, '3'], [false, null, '1.5']], array_map(fn (array $l) => [$l['product']['linked'], $l['product']['sku'], $l['qty']], $shown['lines']));
        $this->assertSame(['4242', 'صنف غير مربوط'], [$shown['lines'][1]['product']['ref'], $shown['lines'][1]['product']['nameAr']]);
        $this->assertSame(['removed', 'modified', 'modified', 'added'], array_column($shown['changes'], 'type'), 'the changes of the order it became, newest first');
        $this->assertSame(['note' => true], array_filter($shown['can']));
        $this->expectRejected($this->act($r->id, 'cancel'), 'PORTAL_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->patchAs('sales', "/api/portal/requests/{$r->id}/lines", ['lines' => [['sku' => $p->sku, 'qty' => 1, 'price' => 1]]]), 'PORTAL_OWNED_BY_SOURCE', [422]);
        $this->expectOk($this->act($r->id, 'notes', ['text' => 'راجعنا التغييرات مع العميل']));

        // the log: by order, by who, by what, by product — also one OPS does not have —, by day, by words
        $byOrder = $this->log("orderRef={$orderRef}");
        $this->assertSame([4, ['added' => 1, 'modified' => 2, 'removed' => 1]], [$byOrder['total'], $byOrder['totals']]);
        $this->assertSame(4, $this->log('orderRef='.urlencode("S{$tag}"))['total'], 'an order answers to its number too');
        $this->assertSame(['removed', 'modified', 'modified', 'added'], array_column($byOrder['items'], 'type'));
        $this->assertSame(['added', 'modified', 'modified', 'removed'], array_column($this->log("orderRef={$orderRef}&order=asc")['items'], 'type'), 'oldest first when asked');
        $price = $this->log("orderRef={$orderRef}&field=price")['items'];
        $this->assertSame([['Unit Price', '20.0', '18.5', 'مندوب المبيعات', $p->sku, 'اسم الصنف وقت التغيير']],
            array_map(fn (array $x) => [$x['fieldLabel'], $x['oldValue'], $x['newValue'], $x['changedBy'], $x['product']['sku'], $x['productName']], $price));
        $this->assertSame(2, $this->log("orderRef={$orderRef}&by=".urlencode('موظف العميل'))['total']);
        $this->assertSame(3, $this->log("orderRef={$orderRef}&product={$p->sku}")['total']);
        $unlinked = $this->log("orderRef={$orderRef}&product=ref:4242")['items'];
        $this->assertSame([[false, '4242', 'صنف غير مربوط']], array_map(fn (array $x) => [$x['product']['linked'], $x['product']['ref'], $x['product']['nameAr']], $unlinked));
        $this->assertSame(4, $this->log("customer={$customer->code}&origin=odoo")['total']);
        $this->assertSame(4, $this->log('q='.urlencode("S{$tag}"))['total']);
        $this->assertSame([4, 0], [$this->log("orderRef={$orderRef}&from=".now()->subDay()->toDateString())['total'], $this->log("orderRef={$orderRef}&to=".now()->subDays(2)->toDateString())['total']]);
        $page = $this->log("orderRef={$orderRef}&pageSize=3&page=2");
        $this->assertSame([1, 4, 2, 3], [count($page['items']), $page['total'], $page['pages'], $page['pageSize']]);
        $this->expectRejected($this->getAs('gm', '/api/portal/line-changes?type=nonsense'), 'INVALID_INPUT', [400]);
    }
}
