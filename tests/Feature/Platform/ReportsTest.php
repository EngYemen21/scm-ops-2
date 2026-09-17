<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Services\Platform\ReportSupport;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/** Reports catalogue, execution of every report type, cost gate and CSV export. */
class ReportsTest extends ApiTestCase
{
    /** Asserts exact demo-data figures, so it must not see rows created by other test classes. */
    protected const PRISTINE_SEED = true;

    public function test_catalogue_lists_every_report(): void
    {
        $list = $this->expectOk($this->getAs('sales', '/api/reports'));
        $this->assertSame(ReportSupport::REPORT_NAMES, array_column($list, 'name'));
        $this->assertCount(15, $list);
        $inventory = $list[0];
        $this->assertSame(['name' => 'inventory', 'titleAr' => 'أرصدة المخزون', 'titleEn' => 'Inventory balances', 'group' => 'inventory', 'snapshot' => true, 'defaultDays' => 0], $inventory);
        $this->assertSame(['name', 'titleAr', 'titleEn', 'group', 'defaultDays'], array_keys($list[1]));
    }

    public function test_every_report_runs_and_returns_its_declared_columns(): void
    {
        $withRows = 0;
        foreach (ReportSupport::REPORT_NAMES as $name) {
            $page = $this->expectOk($this->getAs('admin', "/api/reports/{$name}?pageSize=10&from=2020-01-01"));
            $this->assertSame(['columns', 'rows', 'totals', 'total', 'page', 'pageSize', 'pages', 'generatedAt'], array_keys($page), $name);
            $this->assertGreaterThan(2, count($page['columns']), $name);
            $keys = array_column($page['columns'], 'key');
            $types = array_column($page['columns'], 'type', 'key');
            foreach ($page['columns'] as $column) {
                $this->assertSame(['key', 'labelAr', 'labelEn', 'type'], array_keys($column));
                $this->assertNotEmpty($column['labelAr']);
                $this->assertContains($column['type'], ['text', 'number', 'date', 'datetime', 'money', 'pct', 'status', 'bool']);
            }
            $this->assertLessThanOrEqual(10, count($page['rows']));
            $this->assertSame(10, $page['pageSize']);
            $this->assertSame(max(1, (int) ceil($page['total'] / 10)), $page['pages']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $page['generatedAt']);
            foreach ($page['rows'] as $row) {
                $withRows++;
                $this->assertSame($keys, array_keys($row), "{$name}: a row carries exactly the declared columns, in order");
                foreach ($row as $key => $value) {
                    if ($value === null) {
                        continue;
                    }
                    match ($types[$key]) {
                        'number', 'money', 'pct' => $this->assertTrue(is_int($value) || is_float($value), "{$name}.{$key} must be a JSON number"),
                        'bool' => $this->assertIsBool($value),
                        'date', 'datetime' => $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $value),
                        default => $this->assertIsString($value),
                    };
                }
            }
            foreach ($page['totals'] as $key => $value) {
                $this->assertContains($key, $keys, $name);
                $this->assertTrue(is_int($value) || is_float($value), "{$name} total {$key} must be a JSON number");
            }
        }
        $this->assertGreaterThan(30, $withRows, 'the seed gives the reports real rows to aggregate');
    }

    public function test_inventory_report_totals_equal_the_balances_whatever_the_page_size(): void
    {
        $page = $this->expectOk($this->getAs('admin', '/api/reports/inventory?pageSize=5'));
        $sum = DB::table('inventory_balances')->where('on_hand', '>', 0)->selectRaw('SUM(on_hand) AS on_hand, SUM(reserved) AS reserved, SUM(allocated) AS allocated, COUNT(*) AS n')->first();
        $this->assertCount(5, $page['rows']);
        $this->assertSame((int) $sum->n, $page['total']);
        $this->assertSame((int) $sum->on_hand, $page['totals']['onHand']);
        $this->assertSame((int) $sum->reserved, $page['totals']['reserved']);
        $this->assertSame((int) $sum->allocated, $page['totals']['allocated']);
        $this->assertContains(['key' => 'value', 'labelAr' => 'القيمة (ر.س)', 'labelEn' => 'Value (SAR)', 'type' => 'money'], $page['columns']);

        // Same "available" semantics as the dashboard for sellable rows, minus expired batches.
        $available = $this->expectOk($this->getAs('admin', '/api/reports/inventory?status=available&pageSize=1'));
        $this->assertLessThanOrEqual(2227, $available['totals']['available']);
        $this->assertGreaterThan(0, $available['totals']['available']);
        $this->assertSame('available', $available['rows'][0]['status']);

        // Second page continues the first; filters narrow the totals.
        $next = $this->expectOk($this->getAs('admin', '/api/reports/inventory?pageSize=5&page=2'));
        $this->assertSame(2, $next['page']);
        $this->assertNotEquals($page['rows'], $next['rows']);
        $warehouse = DB::table('warehouses')->orderBy('code')->value('code');
        $one = $this->expectOk($this->getAs('admin', '/api/reports/inventory?pageSize=5&warehouse='.strtolower($warehouse)));
        $this->assertLessThanOrEqual($page['totals']['onHand'], $one['totals']['onHand']);
        $this->assertSame([$warehouse], array_values(array_unique(array_column($one['rows'], 'warehouse'))));
        $bySku = $this->expectOk($this->getAs('admin', '/api/reports/inventory?q=P01552'));
        $this->assertSame(['P01552'], array_values(array_unique(array_column($bySku['rows'], 'sku'))));
    }

    public function test_cost_gate_strips_money_columns(): void
    {
        $page = $this->expectOk($this->getAs('sales', '/api/reports/inventory?pageSize=5'));
        $this->assertNotContains('value', array_column($page['columns'], 'key'));
        $this->assertArrayNotHasKey('value', $page['rows'][0]);
        $this->assertArrayNotHasKey('value', $page['totals']);
        $this->assertArrayHasKey('onHand', $page['totals']);
        $this->assertNotContains('money', array_column($this->expectOk($this->getAs('sales', '/api/reports/purchase-orders?from=2020-01-01'))['columns'], 'type'));
    }

    public function test_purchase_orders_report_filters_by_status_and_range(): void
    {
        $all = $this->expectOk($this->getAs('admin', '/api/reports/purchase-orders?from=2020-01-01'));
        $this->assertSame(DB::table('purchase_orders')->count(), $all['total']);
        $this->assertEquals(round((float) DB::table('purchase_orders')->sum('total'), 2), round($all['totals']['total'], 2));
        $status = DB::table('purchase_orders')->value('status');
        $some = $this->expectOk($this->getAs('admin', "/api/reports/purchase-orders?from=2020-01-01&status={$status}"));
        $this->assertSame(DB::table('purchase_orders')->where('status', $status)->count(), $some['total']);
        $this->assertSame([$status], array_values(array_unique(array_column($some['rows'], 'status'))));
        $this->assertSame(0, $this->expectOk($this->getAs('admin', '/api/reports/purchase-orders?from=2019-01-01&to=2019-12-31'))['total']);
    }

    public function test_unknown_report_bad_range_and_bad_warehouse_are_rejected(): void
    {
        $this->expectRejected($this->getAs('admin', '/api/reports/nope'), 'REPORT_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('admin', '/api/reports/grn?from=2026-02-01&to=2026-01-01'), 'INVALID_RANGE', [400]);
        $this->expectRejected($this->getAs('admin', '/api/reports/grn?from=yesterday-ish'), 'INVALID_DATE', [400]);
        $this->expectRejected($this->getAs('admin', '/api/reports/inventory?warehouse=NOPE'), 'WAREHOUSE_NOT_FOUND', [400]);
        $this->expectRejected($this->getAs('admin', '/api/reports/inventory?format=xlsx'), 'INVALID_INPUT', [400]);
    }

    public function test_csv_export_works_and_requires_report_export(): void
    {
        $res = $this->getAs('inv', '/api/reports/inventory?format=csv&pageSize=20');
        $res->assertOk();
        $this->assertStringStartsWith('text/csv', $res->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/^attachment; filename="inventory-\d{4}-\d{2}-\d{2}\.csv"$/', $res->headers->get('Content-Disposition'));
        $body = $res->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'UTF-8 BOM for Excel');
        $lines = explode("\r\n", substr($body, 3));
        $header = str_getcsv($lines[0]);
        foreach (['SKU', 'الرصيد', 'المستودع', 'القيمة (ر.س)'] as $label) {
            $this->assertContains($label, $header);
        }
        $this->assertCount(22, $lines, 'header + 20 rows + trailing line break');
        $this->assertSame('', $lines[21]);
        $this->assertCount(count($header), str_getcsv($lines[1]));

        // Same rows as the JSON page.
        $json = $this->expectOk($this->getAs('inv', '/api/reports/inventory?pageSize=20'));
        $this->assertSame($json['rows'][0]['sku'], str_getcsv($lines[1])[0]);

        // `worker` holds no report.export: the JSON report is readable, the export is denied and audited.
        $this->expectOk($this->getAs('worker', '/api/reports/inventory?pageSize=1'));
        $denied = AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->where('username', 'worker')->count();
        $this->expectRejected($this->getAs('worker', '/api/reports/inventory?format=csv'), 'FORBIDDEN', [403]);
        $this->assertSame($denied + 1, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->where('username', 'worker')->count());
    }
}
