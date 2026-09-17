<?php

namespace Tests\Feature\Platform;

use App\Services\Core\NotifyService;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/** Dashboard + Control Tower: every figure comes from the real tables; on the pristine seed they equal the reference's. */
class DashboardTest extends ApiTestCase
{
    /** Asserts exact demo-data figures, so it must not see rows created by other test classes. */
    protected const PRISTINE_SEED = true;

    private const KPI_KEYS = ['expectedInbounds', 'receivingToday', 'waitingQc', 'waitingPutaway', 'availableUnits', 'availableValue', 'lowStock', 'expiringSoon', 'expired', 'ordersWaitingAllocation', 'picking', 'packing', 'readyForDispatch',
        'tripsToday', 'vehiclesAvailable', 'vehiclesOnRoute', 'vehiclesMaintenance', 'deliveriesCompletedToday', 'deliveriesFailedToday', 'returnsOpen', 'exceptionsOpen', 'exceptionsCritical', 'slaBreached'];

    private function kpi(array $body, string $key): ?array
    {
        return collect($body['kpis'])->firstWhere('key', $key);
    }

    public function test_dashboard_figures_match_the_reference_on_the_seed(): void
    {
        $d = $this->expectOk($this->getAs('admin', '/api/dashboard'));
        $this->assertSame(['generatedAt', 'costVisible', 'expiringSoonDays', 'kpis', 'actions', 'inventoryByWarehouse', 'recentActivity', 'pendingApprovals'], array_keys($d));
        $this->assertSame(self::KPI_KEYS, array_column($d['kpis'], 'key'));
        foreach ($d['kpis'] as $k) {
            $this->assertTrue(is_int($k['value']) || is_float($k['value']), "{$k['key']} must be a JSON number");
            $this->assertNotEmpty($k['labelAr']);
            $this->assertNotEmpty($k['labelEn']);
            $this->assertStringStartsWith('/', $k['link']);
        }

        // The reference's figures on its pristine seed.
        $this->assertSame(2227, $this->kpi($d, 'availableUnits')['value']);
        $this->assertEquals(172619, $this->kpi($d, 'availableValue')['value']);
        $this->assertSame(34, $this->kpi($d, 'lowStock')['value']);
        $this->assertSame(5, $this->kpi($d, 'exceptionsOpen')['value']);
        $this->assertSame(2, $this->kpi($d, 'exceptionsCritical')['value']);
        $this->assertSame(3, $this->kpi($d, 'returnsOpen')['value']);

        // …and they are real aggregates, not constants: cross-check against the tables.
        $this->assertSame(DB::table('exceptions')->where('status', '<>', 'resolved')->count(), $this->kpi($d, 'exceptionsOpen')['value']);
        $this->assertSame(DB::table('returns')->whereNotIn('status', ['closed', 'rejected'])->count(), $this->kpi($d, 'returnsOpen')['value']);
        $this->assertSame((int) DB::table('putaway_tasks')->where('status', 'open')->sum('qty'), $this->kpi($d, 'waitingPutaway')['value']);
        $this->assertSame($this->kpi($d, 'availableUnits')['value'], array_sum(array_column($d['inventoryByWarehouse'], 'available')));
        $this->assertSame((int) DB::table('inventory_balances')->where('on_hand', '>', 0)->sum('on_hand'), array_sum(array_column($d['inventoryByWarehouse'], 'onHand')));
        $this->assertEqualsWithDelta(100, array_sum(array_column($d['inventoryByWarehouse'], 'sharePct')), 0.5);

        $this->assertTrue($d['costVisible']);
        $this->assertSame(30, $d['expiringSoonDays']);
        $this->assertLessThanOrEqual(8, count($d['recentActivity']));
        app(NotifyService::class)->activity(null, 'Trip', null, 'TRP-DASH', 'نشاط لاختبار لوحة المعلومات', 'Dashboard test activity');
        $recent = $this->expectOk($this->getAs('admin', '/api/dashboard'))['recentActivity'];
        $this->assertSame('TRP-DASH', $recent[0]['entityNumber'], 'latest first, camelCase');
        $this->assertLessThanOrEqual(8, count($recent));
        foreach ($d['actions'] as $a) {
            // exception rows also carry their real status, so the client never offers "acknowledge" twice
            $isExc = in_array($a['kind'], ['exception', 'sla_breach'], true);
            $this->assertSame(['kind', 'number', 'textAr', 'textEn', 'owner', 'path', ...($isExc ? ['status'] : [])], array_keys($a));
            if ($isExc) {
                $this->assertContains($a['status'], ['open', 'ack']);
            }
            $this->assertStringStartsWith('/', $a['path']);
        }
        $this->assertSame(['pos', 'prs'], array_keys($d['pendingApprovals']));
    }

    public function test_cost_is_hidden_without_inventory_view_cost(): void
    {
        $d = $this->expectOk($this->getAs('sales', '/api/dashboard'));
        $this->assertFalse($d['costVisible']);
        $this->assertNull($this->kpi($d, 'availableValue'));
        $this->assertArrayNotHasKey('value', $d['inventoryByWarehouse'][0]);
        $this->assertSame(2227, $this->kpi($d, 'availableUnits')['value']);

        $t = $this->expectOk($this->getAs('sales', '/api/tower'));
        $this->assertArrayNotHasKey('expiredValue', $t['stockAtRisk']);
    }

    public function test_pending_approvals_only_list_steps_waiting_on_the_callers_role(): void
    {
        foreach (['proc', 'finance', 'gm', 'sales'] as $username) {
            $approvals = $this->expectOk($this->getAs($username, '/api/dashboard'))['pendingApprovals'];
            foreach ($approvals['pos'] as $a) {
                $this->assertSame($username, $a['roleKey']);
                $this->assertSame("/po/{$a['number']}", $a['path']);
                $this->assertIsNumeric($a['total']);
            }
            foreach ($approvals['prs'] as $a) {
                $this->assertSame($username, $a['roleKey']);
            }
        }
    }

    public function test_tower_breakdowns_lists_and_honest_integrations(): void
    {
        $t = $this->expectOk($this->getAs('admin', '/api/tower'));
        $this->assertSame(['generatedAt', 'costVisible', 'kpis', 'exceptions', 'slaBreaches', 'lateTrips', 'inboundAtRisk', 'stockAtRisk', 'transfersInTransit', 'returnsAwaitingInspection', 'throughputToday', 'integrations'], array_keys($t));
        $this->assertSame(['critical', 'high', 'medium', 'slaBreached', 'lateTrips', 'inboundAtRisk', 'transfersInTransit', 'returnsAwaitingInspection', 'expiredQty', 'quarantineQty'], array_column($t['kpis'], 'key'));

        $sev = $t['exceptions']['bySeverity'];
        $this->assertSame(2, $sev['c']);
        $this->assertSame(5, $sev['c'] + $sev['w'] + $sev['i']);
        $this->assertSame(5, array_sum(array_column($t['exceptions']['byKind'], 'count')));
        $this->assertSame(DB::table('exceptions')->count(), array_sum($t['exceptions']['byStatus']));
        $this->assertCount(5, $t['exceptions']['queue']);
        $this->assertSame('c', $t['exceptions']['queue'][0]['severity'], 'critical first');
        $this->assertIsBool($t['exceptions']['queue'][0]['breached']);
        $this->assertStringStartsWith('/exc/', $t['exceptions']['queue'][0]['path']);

        $this->assertSame($t['slaBreaches']['count'], collect($t['kpis'])->firstWhere('key', 'slaBreached')['value']);
        $this->assertSame(min(10, $t['slaBreaches']['count']), count($t['slaBreaches']['items']));
        foreach ($t['slaBreaches']['items'] as $item) {
            $this->assertGreaterThan(0, $item['overdueMin']);
        }
        $this->assertSame(DB::table('trips')->where('delay_min', '>', 0)->whereIn('status', ['dispatched', 'onroute', 'partial', 'returning'])->count(), $t['lateTrips']['count']);
        foreach ($t['lateTrips']['items'] as $trip) {
            $this->assertArrayHasKey('code', $trip['warehouse']);
            $this->assertSame("/trip/{$trip['number']}", $trip['path']);
        }
        foreach ($t['inboundAtRisk']['items'] as $s) {
            $this->assertGreaterThanOrEqual(0, $s['daysLate']);
        }
        $this->assertSame(['expiredQty', 'quarantineQty', 'damagedQty', 'blockedQty', 'expiredValue', 'quarantineValue'], array_keys($t['stockAtRisk']));
        $this->assertSame(DB::table('warehouses')->where('active', true)->count(), count($t['throughputToday']));
        $this->assertIsInt($t['throughputToday'][0]['grnQty']);

        // Nothing is connected: every integration says so.
        $this->assertEqualsCanonicalizing(['b2b', 'erp', 'gps', 'maps', 'storage', 'whatsapp'], array_column($t['integrations'], 'key'));
        foreach ($t['integrations'] as $i) {
            $this->assertSame('integration_pending', $i['status']);
            $this->assertNotEmpty($i['configVar']);
        }
    }
}
