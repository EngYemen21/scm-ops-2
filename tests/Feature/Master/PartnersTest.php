<?php

namespace Tests\Feature\Master;

use App\Models\AuditLog;
use App\Models\StatusHistory;
use App\Models\Supplier;
use Tests\ApiTestCase;

/** Suppliers + customers: numbering, CR/VAT and credit rules, paging / search / filters, audit, RBAC. */
class PartnersTest extends ApiTestCase
{
    private static string $n = '';

    private static array $supplier = [];

    private static array $customer = [];

    private static function n(): string
    {
        return self::$n ?: self::$n = self::uid();
    }

    private static function supplierBody(array $over = []): array
    {
        return $over + ['nameAr' => 'مورد اختبار '.self::n(), 'nameEn' => 'Spec supplier '.self::n(), 'contact' => '0500000000', 'category' => 'dry',
            'cr' => '101'.self::n(), 'vat' => '3'.self::n().'0000003'];
    }

    // ───────────────────────────── suppliers ─────────────────────────────

    public function test_supplier_cr_and_vat_must_have_the_right_length(): void
    {
        $body = $this->expectRejected($this->postAs('proc', '/api/suppliers', self::supplierBody(['cr' => '12345'])), 'INVALID_INPUT', [400]);
        $this->assertSame('cr', $body['details'][0]['path']);
        $this->assertSame('السجل التجاري يجب أن يكون 10 أرقام', $body['details'][0]['message']);
        $body = $this->expectRejected($this->postAs('proc', '/api/suppliers', self::supplierBody(['vat' => '3000'])), 'INVALID_INPUT', [400]);
        $this->assertSame('الرقم الضريبي يجب أن يكون 15 رقمًا', $body['details'][0]['message']);
        $this->expectRejected($this->postAs('proc', '/api/suppliers', ['nameAr' => 'بدون بيانات']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', '/api/suppliers', self::supplierBody(['email' => 'not-an-email'])), 'INVALID_INPUT', [400]);
    }

    public function test_create_supplier_numbers_it_scores_it_and_audits_it(): void
    {
        $cr = '101'.self::n();
        $s = $this->expectOk($this->postAs('proc', '/api/suppliers', self::supplierBody(['cr' => substr($cr, 0, 4).' '.substr($cr, 4), 'iban' => 'sa03 8000 0000 6080 1016 7519'])));
        self::$supplier = $s;
        $this->assertMatchesRegularExpression('/^SUP-\d{3,}$/', $s['code']);
        $this->assertEquals(70, $s['score']);
        $this->assertTrue($s['isNew']);
        $this->assertSame($cr, $s['cr'], 'whitespace is stripped from the CR');
        $this->assertSame('أغذية جافة', $s['category']);
        $this->assertSame('Dry food', $s['categoryEn']);
        $this->assertSame(5, $s['leadDays']);
        $this->assertSame('SA0380000000608010167519', $s['iban']);
        $this->assertStringContainsString('تقييم مبدئي 70', $s['messageAr']);
        $this->assertSame(1, AuditLog::where('entity_type', 'Supplier')->where('entity_id', $s['id'])->where('action', 'SUPPLIER.CREATE')->count());

        $dup = $this->expectRejected($this->postAs('proc', '/api/suppliers', self::supplierBody(['vat' => '3'.self::n().'0000013'])), 'SUPPLIER_DUPLICATE', [422]);
        $this->assertStringContainsString('السجل التجاري', $dup['message']);
        $dup = $this->expectRejected($this->postAs('proc', '/api/suppliers', self::supplierBody(['cr' => '102'.self::n()])), 'SUPPLIER_DUPLICATE', [422]);
        $this->assertStringContainsString('الرقم الضريبي', $dup['message']);
    }

    public function test_get_supplier_by_code_or_id_with_counts(): void
    {
        $got = $this->expectOk($this->getAs('sales', '/api/suppliers/'.self::$supplier['code']));
        $this->assertSame(self::$supplier['id'], $got['id']);
        $this->assertSame(0, $got['recentPosCount']);
        $this->assertSame(0, $got['openPosCount']);
        $this->assertSame([], $got['recentPos']);
        $this->assertFalse($got['blocksPo']);
        $this->assertSame([], $got['products']);
        $this->assertSame(['pos', 'quotations', 'rfqs', 'shipments', 'grns', 'returns'], array_keys($got['_count']));
        $this->assertArrayNotHasKey('posCount', $got);
        $this->assertSame(self::$supplier['code'], $this->expectOk($this->getAs('sales', '/api/suppliers/'.self::$supplier['id']))['code']);

        $seeded = $this->expectOk($this->getAs('proc', '/api/suppliers/SUP-019'));
        $this->assertGreaterThan(0, $seeded['_count']['pos']);
        $this->assertLessThanOrEqual(5, count($seeded['recentPos']));
        $this->assertSame(['id', 'number', 'status', 'total', 'dueDate', 'createdAt'], array_keys($seeded['recentPos'][0]));
        $this->expectRejected($this->getAs('proc', '/api/suppliers/SUP-NOPE'), 'SUPPLIER_NOT_FOUND', [404]);
    }

    public function test_supplier_list_paging_search_sort_and_filters(): void
    {
        $page = $this->expectOk($this->getAs('sales', '/api/suppliers?pageSize=3&page=2'));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($page));
        $this->assertCount(3, $page['items']);
        $this->assertSame(2, $page['page']);
        $this->assertSame((int) ceil($page['total'] / 3), $page['pages']);
        $this->assertArrayHasKey('products', $page['items'][0]['_count']);
        $this->assertArrayHasKey('pos', $page['items'][0]['_count']);

        $codes = array_column($this->expectOk($this->getAs('sales', '/api/suppliers?pageSize=500'))['items'], 'code');
        $sorted = $codes;
        sort($sorted);
        $this->assertSame($sorted, $codes, 'default order is code ascending');
        $scores = array_column($this->expectOk($this->getAs('sales', '/api/suppliers?sort=score&order=desc'))['items'], 'score');
        $expected = $scores;
        rsort($expected);
        $this->assertSame($expected, $scores);

        $found = $this->expectOk($this->getAs('sales', '/api/suppliers?q='.self::$supplier['cr']));
        $this->assertSame(1, $found['total']);
        $this->assertSame(self::$supplier['code'], $found['items'][0]['code']);
        $new = $this->expectOk($this->getAs('sales', '/api/suppliers?isNew=true&category=dry&pageSize=500'));
        $this->assertContains(self::$supplier['code'], array_column($new['items'], 'code'));
        foreach ($new['items'] as $row) {
            $this->assertTrue($row['isNew']);
            $this->assertSame('أغذية جافة', $row['category']);
        }
        $this->expectRejected($this->getAs('sales', '/api/suppliers?active=maybe'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->getAs('sales', '/api/suppliers?pageSize=0'), 'INVALID_INPUT', [400]);
    }

    public function test_update_supplier_audits_only_real_changes(): void
    {
        $code = self::$supplier['code'];
        $same = $this->expectOk($this->patchAs('proc', "/api/suppliers/{$code}", ['nameAr' => self::$supplier['nameAr'], 'leadDays' => 5]));
        $this->assertSame(0, $same['changed']);
        $this->assertSame('لا تغييرات', $same['messageAr']);

        $res = $this->expectOk($this->patchAs('proc', "/api/suppliers/{$code}", ['leadDays' => 9, 'category' => 'تصنيف حر', 'email' => 'sales@example.com', 'active' => false]));
        $this->assertSame(5, $res['changed']);
        $row = Supplier::where('code', $code)->firstOrFail();
        $this->assertSame(9, $row->lead_days);
        $this->assertSame('تصنيف حر', $row->category_en);
        $this->assertFalse($row->active);
        $audit = AuditLog::where('entity_id', $row->id)->where('action', 'SUPPLIER.UPDATE')->latest('at')->firstOrFail();
        $this->assertStringContainsString('"leadDays":9', $audit->new_value);
        $this->assertStringContainsString('"leadDays":5', $audit->old_value);
        $this->assertSame(['inactive'], StatusHistory::where('entity_type', 'Supplier')->where('entity_id', $row->id)->pluck('to_status')->all());

        $inactive = $this->expectOk($this->getAs('proc', '/api/suppliers?active=false&pageSize=500'));
        $this->assertContains($code, array_column($inactive['items'], 'code'));
        $this->assertNotContains($code, array_column($this->expectOk($this->getAs('proc', '/api/suppliers?active=1&pageSize=500'))['items'], 'code'));
        $this->assertNotContains($row->id, array_column($this->expectOk($this->getAs('proc', '/api/master/lookups'))['suppliers'], 'id'), 'lookups list active suppliers only');

        $this->expectRejected($this->patchAs('proc', "/api/suppliers/{$code}", ['cr' => '123']), 'INVALID_INPUT', [400]);
        $other = Supplier::where('code', '!=', $code)->whereNotNull('cr')->first();
        if ($other) {
            $this->expectRejected($this->patchAs('proc', "/api/suppliers/{$code}", ['cr' => $other->cr]), 'SUPPLIER_DUPLICATE', [422]);
        }
        $this->expectRejected($this->patchAs('proc', '/api/suppliers/SUP-NOPE', ['leadDays' => 1]), 'SUPPLIER_NOT_FOUND', [404]);
    }

    public function test_supplier_changes_need_the_permission(): void
    {
        $total = Supplier::count();
        $this->expectRejected($this->postAs('sales', '/api/suppliers', self::supplierBody(['cr' => '109'.self::n(), 'vat' => '3'.self::n().'0000093'])), 'FORBIDDEN', [403]);
        $this->expectRejected($this->patchAs('worker', '/api/suppliers/'.self::$supplier['code'], ['leadDays' => 1]), 'FORBIDDEN', [403]);
        $this->assertSame($total, Supplier::count());
        $this->assertSame(9, Supplier::where('code', self::$supplier['code'])->value('lead_days'));
    }

    // ───────────────────────────── customers ─────────────────────────────

    public function test_credit_customers_need_a_positive_limit(): void
    {
        $base = ['nameAr' => 'عميل اختبار '.self::n(), 'zone' => 'العليا', 'contact' => '05x', 'cr' => '2020'.self::n(), 'terms' => 'آجل 30 يومًا'];
        $body = $this->expectRejected($this->postAs('sales', '/api/customers', $base + ['creditLimit' => 0]), 'INVALID_INPUT', [400]);
        $this->assertSame('creditLimit', $body['details'][0]['path']);
        $this->assertSame('العميل الآجل يحتاج حدًا ائتمانيًا أكبر من صفر', $body['details'][0]['message']);
        $this->expectRejected($this->postAs('sales', '/api/customers', ['nameAr' => 'ناقص']), 'INVALID_INPUT', [400]);

        $c = $this->expectOk($this->postAs('sales', '/api/customers', $base + ['creditLimit' => 50000, 'city' => 'الرياض']));
        self::$customer = $c;
        $this->assertMatchesRegularExpression('/^CUS-\d{4,}$/', $c['code']);
        $this->assertEquals(50000, $c['creditLimit']);
        $this->assertEquals(0, $c['balance']);
        $this->assertSame($base['nameAr'], $c['nameEn'], 'nameEn falls back to nameAr');
        $this->assertStringContainsString($c['code'], $c['messageAr']);
        $this->assertSame(1, AuditLog::where('entity_id', $c['id'])->where('action', 'CUSTOMER.CREATE')->count());

        $cash = $this->expectOk($this->postAs('sales', '/api/customers', ['nameAr' => 'عميل نقدي '.self::n(), 'zone' => 'الملز', 'contact' => '05x', 'cr' => '3030'.self::n()]));
        $this->assertSame('نقدي / محفظة', $cash['terms']);
        $this->assertNull($this->expectOk($this->getAs('sales', '/api/customers/'.$cash['code']))['availableCredit']);
    }

    public function test_get_and_update_customer(): void
    {
        $code = self::$customer['code'];
        $got = $this->expectOk($this->getAs('wm', "/api/customers/{$code}"));
        $this->assertSame(0, $got['openSosCount']);
        $this->assertEquals(50000, $got['availableCredit']);
        $this->assertSame([], $got['recentOrders']);
        $this->assertSame(['orders', 'quotations', 'returns', 'fos'], array_keys($got['_count']));

        $seeded = $this->expectOk($this->getAs('wm', '/api/customers/CUS-1001'));
        $this->assertEquals(150000 - 42300, $seeded['availableCredit']);
        if ($seeded['recentOrders']) {
            $this->assertSame(['id', 'number', 'status', 'dueDate', 'date', 'warehouse'], array_keys($seeded['recentOrders'][0]));
            $this->assertArrayHasKey('code', $seeded['recentOrders'][0]['warehouse']);
        }

        $this->expectRejected($this->patchAs('sales', "/api/customers/{$code}", ['creditLimit' => 0]), 'CUSTOMER_CREDIT_LIMIT', [422]);
        $this->expectRejected($this->patchAs('sales', '/api/customers/CUS-1001', ['creditLimit' => 1000]), 'CUSTOMER_LIMIT_BELOW_BALANCE', [422]);
        $this->expectRejected($this->patchAs('sales', '/api/customers/CUS-NOPE', ['city' => 'x']), 'CUSTOMER_NOT_FOUND', [404]);

        $res = $this->expectOk($this->patchAs('sales', "/api/customers/{$code}", ['creditLimit' => 75000, 'city' => 'جدة', 'zone' => 'العليا']));
        $this->assertSame(2, $res['changed'], 'an unchanged value is not counted');
        $this->assertEquals(75000, $this->expectOk($this->getAs('sales', "/api/customers/{$code}"))['creditLimit']);
        $this->assertSame(0, $this->expectOk($this->patchAs('sales', "/api/customers/{$code}", ['city' => 'جدة']))['changed']);
    }

    public function test_customer_list_filters_and_available_credit(): void
    {
        $code = self::$customer['code'];
        $jeddah = $this->expectOk($this->getAs('sales', '/api/customers?city='.urlencode('جدة').'&pageSize=500'));
        $this->assertContains($code, array_column($jeddah['items'], 'code'));
        foreach ($jeddah['items'] as $row) {
            $this->assertSame('جدة', $row['city']);
        }
        $cash = $this->expectOk($this->getAs('sales', '/api/customers?terms=cash&pageSize=500'));
        $this->assertNotContains($code, array_column($cash['items'], 'code'));
        $credit = $this->expectOk($this->getAs('sales', '/api/customers?terms=credit&pageSize=500'));
        $this->assertContains($code, array_column($credit['items'], 'code'));
        $mine = collect($credit['items'])->firstWhere('code', $code);
        $this->assertEquals(75000, $mine['availableCredit']);
        $this->assertSame(['orders', 'quotations'], array_keys($mine['_count']));

        $found = $this->expectOk($this->getAs('sales', '/api/customers?q='.$code));
        $this->assertSame(1, $found['total']);
        $page = $this->expectOk($this->getAs('sales', '/api/customers?pageSize=2&sort=balance&order=desc'));
        $this->assertCount(2, $page['items']);
        $this->assertGreaterThanOrEqual((float) $page['items'][1]['balance'], (float) $page['items'][0]['balance']);
    }

    public function test_customer_changes_need_the_permission(): void
    {
        $this->expectRejected($this->postAs('proc', '/api/customers', ['nameAr' => 'مرفوض '.self::n(), 'zone' => 'x', 'contact' => 'x', 'cr' => '1']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->patchAs('worker', '/api/customers/'.self::$customer['code'], ['city' => 'الدمام']), 'FORBIDDEN', [403]);
        $this->assertSame('جدة', $this->expectOk($this->getAs('worker', '/api/customers/'.self::$customer['code']))['city']);
    }
}
