<?php

namespace Tests\Feature\Insights;

use App\Finance\Services\FinanceReportsService;
use App\Services\Platform\ReportsService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;
use Tests\Feature\Inventory\InventoryFixtures;

/**
 * The operations insights: what overlaps the existing reports agrees with them to the halala (computed at the same
 * moment, so other stories' rows count on both sides); what is new — slow-moving products, invoice counts, Odoo's open
 * invoices — is checked on this story's own records or as a difference from a baseline.
 */
class OperationsInsightsTest extends ApiTestCase
{
    use InsightsFixtures;
    use InventoryFixtures;

    public function test_figures_agree_with_the_existing_reports(): void
    {
        $c = $this->makeCustomer();
        $p = $this->makeBrandProduct(null, 30);
        $this->invoice($c, '2026-09-02', [[$p, 4, 125.5, 60]], 'invoice', '2026-08-01');   // overdue, in the ageing
        $this->invoice($c, '2026-09-03', [[$p, 1, 125.5, 60]], 'credit_note');

        $sum = $this->expectOk($this->getAs('admin', '/api/insights/operations/summary?from=2026-09-01&to=2026-09-30'));
        $finance = app(FinanceReportsService::class);
        $this->assertEquals($finance->incomeStatement('2026-09-01', '2026-09-30')['totals'], $sum['pnl']);
        $aging = $finance->aging('customer');
        $this->assertEquals($aging['totals'] + ['customers' => count($aging['rows'])], $sum['ageing']);
        $margin = $this->expectOk($this->getAs('admin', '/api/insights/operations/customer-margin?from=2026-09-01&to=2026-09-30'));
        $prof = $finance->profitability('customer', '2026-09-01', '2026-09-30');
        $this->assertEquals($prof['totals'], $margin['totals']);
        $mine = collect($margin['rows'])->firstWhere('code', $c->code);
        $this->assertEqualsWithDelta(376.50, $mine['revenue'], 0.001, '4 × 125.50 − 1 × 125.50');
        $this->assertEqualsWithDelta(180.00, $mine['cogs'], 0.001);
        $ageing = $this->expectOk($this->getAs('admin', '/api/insights/operations/ageing'));
        $this->assertEquals($aging['totals'], $ageing['ops']['totals']);
        $this->assertEqualsWithDelta(577.30, collect($ageing['ops']['rows'])->firstWhere('code', $c->code)['d60'], 0.001, '502 + 15 % VAT, 48 days late');
        // the expiry buckets are the existing expiry report, bucket by bucket
        $buckets = collect($this->expectOk($this->getAs('admin', '/api/insights/operations/expiry-buckets'))['buckets'])->keyBy('bucket');
        $reports = app(ReportsService::class);
        $admin = new AuthUser('x', 'admin', 'a', 'a', ['super'], ['inventory.view_cost'], [], null, null);
        foreach (['expired', '0-7', '8-30', '31-90', '90+'] as $b) {
            $r = $reports->run($admin, 'expiry', new Paging(1, 1, null, null, 'desc'), ['status' => $b]);
            $this->assertSame($r['total'], $buckets[$b]['lots']);
            $this->assertEqualsWithDelta((float) ($r['totals']['qty'] ?? 0), $buckets[$b]['qty'], 0.001);
            $this->assertEqualsWithDelta((float) ($r['totals']['value'] ?? 0), $buckets[$b]['value'], 0.001);
        }
    }

    public function test_slow_moving_invoice_counts_odoo_ageing_and_permissions(): void
    {
        $tag = self::tag();
        $zone = $this->makeZone('RYD', "O{$tag}", ["O{$tag}-1"]);
        $bin = $zone['bins']["O{$tag}-1"];
        $make = fn (string $k) => $this->makeProduct("{$k}{$tag}");
        ['product' => $slow, 'batch' => $sb] = $make('SL');
        ['product' => $sold, 'batch' => $db] = $make('SD');
        ['product' => $new, 'batch' => $nb] = $make('NW');
        foreach ([[$slow, $sb, 6], [$sold, $db, 9], [$new, $nb, 2]] as [$p, $b, $q]) {
            $this->stock($p, $b, $zone['wh'], $bin, $q, "OPEN-{$tag}");
        }
        $this->expectOk($this->postAs('wm', '/api/inventory/adjust', ['sku' => $sold->sku, 'warehouseCode' => 'RYD', 'binCode' => $bin->code, 'batchNo' => $db->batch_no, 'qtyDelta' => -1, 'reason' => 'صرف']));
        // received 120 days ago; the sale 10 days ago; the third product arrived today
        DB::table('inventory_movements')->whereIn('product_id', [$slow->id, $sold->id])->whereNull('src_bin_id')->update(['created_at' => now()->subDays(120)]);
        DB::table('inventory_movements')->where('product_id', $sold->id)->whereNotNull('src_bin_id')->update(['created_at' => now()->subDays(10)]);

        $list = fn (string $sku, string $as = 'admin') => $this->expectOk($this->getAs($as, "/api/insights/operations/slow-moving?days=90&q={$sku}"));
        $s = $list($slow->sku);
        $this->assertSame(1, $s['total']);
        $this->assertSame([6, null, 60.0], [$s['items'][0]['qty'], $s['items'][0]['daysSinceOut'], (float) $s['items'][0]['value']]);
        $this->assertSame(0, $list($sold->sku)['total'], 'left stock 10 days ago');
        $this->assertSame(0, $list($new->sku)['total'], 'arrived today');
        $this->assertSame(1, $this->expectOk($this->getAs('admin', "/api/insights/operations/slow-moving?days=5&q={$sold->sku}"))['total']);
        $noCost = $this->makeUser(['page.insights']);
        $this->assertArrayNotHasKey('value', $list($slow->sku, $noCost->username)['items'][0]);

        // invoice counts: the difference this story makes
        $before = collect($this->expectOk($this->getAs('admin', '/api/insights/operations/invoice-counts?from=2026-07-01&to=2026-07-31'))['rows'])->keyBy('month')->get('2026-07');
        $c = $this->makeCustomer();
        $p = $this->makeBrandProduct(null);
        $this->invoice($c, '2026-07-04', [[$p, 2, 50, 20]]);
        $this->invoice($c, '2026-07-05', [[$p, 1, 30, 20]]);
        $this->invoice($c, '2026-07-06', [[$p, 1, 50, 20]], 'credit_note');
        $this->invoice($c, '2026-07-07', [[$p, 1, 50, 20]], 'invoice', null, 0, 'cancelled');
        $partner = 600000 + (int) self::uid() % 100000;
        $this->odooInvoice($partner, '2026-07-08', [[1, 1, 200]], 'out_invoice', '2026-07-23', 230);   // still open, 57 days late
        $this->odooInvoice($partner, '2026-07-09', [[1, 1, 20]], 'out_refund');
        $after = collect($this->expectOk($this->getAs('admin', '/api/insights/operations/invoice-counts?from=2026-07-01&to=2026-07-31'))['rows'])->keyBy('month')['2026-07'];
        $delta = fn (string $k) => $after[$k] - ($before[$k] ?? 0);
        $this->assertSame([2, 1, 1, 1, 1], [$delta('opsInvoices'), $delta('opsCreditNotes'), $delta('opsCancelled'), $delta('odooInvoices'), $delta('odooCreditNotes')]);
        $this->assertEqualsWithDelta(80.0, $delta('opsAmount'), 0.001, '100 + 30 − 50');
        $this->assertEqualsWithDelta(180.0, $delta('odooAmount'), 0.001);
        $odoo = collect($this->expectOk($this->getAs('admin', '/api/insights/operations/ageing'))['odoo']['rows'])->firstWhere('partnerRef', (string) $partner);
        $this->assertEquals(['current' => 0, 'd30' => 0, 'd60' => 230, 'd90' => 0, 'd90p' => 0, 'total' => 230, 'invoices' => 1],
            array_intersect_key($odoo, array_flip(['current', 'd30', 'd60', 'd90', 'd90p', 'total', 'invoices'])));

        // finance figures need fin.report, like the finance reports
        $plain = $this->makeUser(['page.insights']);
        foreach (['customer-margin', 'pnl', 'ageing', 'invoice-counts'] as $what) {
            $this->expectRejected($this->getAs($plain->username, "/api/insights/operations/{$what}"), 'FORBIDDEN', [403]);
        }
        $sum = $this->expectOk($this->getAs($plain->username, '/api/insights/operations/summary'));
        $this->assertFalse($sum['finance']);
        $this->assertArrayNotHasKey('pnl', $sum);
        $this->assertArrayHasKey('expiry', $sum);
        $this->assertReconciled();
    }
}
