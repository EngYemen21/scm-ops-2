<?php

namespace Tests\Feature\Transport;

use App\Finance\Models\Journal;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\FleetAlert;
use App\Models\FuelRecord;
use App\Models\MaintenanceOrder;
use App\Models\OpsRequest;
use App\Models\StatusHistory;
use App\Models\Trip;
use App\Models\TripCost;
use App\Models\Vehicle;
use Tests\ApiTestCase;

/**
 * Corrections of fleet facts — nothing is ever deleted: a fuel record is corrected or voided with a reason (its journal
 * is reversed), an open maintenance order is edited or cancelled, a vehicle odometer goes down only with a reason, a
 * driver's documents are renewed and the block follows them, and a driver's fuel request becomes a real fuel record.
 */
class FleetCorrectionsTest extends ApiTestCase
{
    use TransportFixtures;

    private const OPEN = ['open', 'assigned', 'snoozed'];

    /** A trip created through the API and put on the vehicle straight in the table (assignment has its own tests). */
    private function tripOn(array $vehicle, float $km = 100): string
    {
        $trip = $this->mkTrip([$this->mkFo(100)->number], ['km' => $km]);
        Trip::where('number', $trip['number'])->update(['vehicle_id' => $vehicle['id']]);

        return $trip['number'];
    }

    private function cost(string $trip): TripCost
    {
        return TripCost::findOrFail(Trip::where('number', $trip)->value('id'));
    }

    private function fuel(array $vehicle, int $odometer, float $liters, float $cost, array $extra = []): array
    {
        return $this->expectOk($this->postAs('disp', '/api/transport/fuel', $extra + ['vehicleCode' => $vehicle['code'], 'date' => '2026-09-15', 'odometer' => $odometer, 'liters' => $liters, 'cost' => $cost]));
    }

    /** Net amount the ledger carries for a document: Σ debit of its posted + reversal journals on expense lines nets to this. */
    private function ledger(string $fuelId): float
    {
        $net = 0.0;
        foreach (Journal::where('source_type', 'FuelRecord')->where('source_id', $fuelId)->get() as $j) {
            $net += $j->kind === 'reversal' ? -(float) $j->total_debit : (float) $j->total_debit;
        }

        return round($net, 2);
    }

    // ───────── fuel: correction ─────────
    public function test_fuel_correction_recomputes_the_chain_the_ledger_the_odometer_and_the_trips(): void
    {
        $v = $this->mkVehicle('dry'); // odometer 1000, average 6 km/L
        $t1 = $this->tripOn($v);
        $t2 = $this->tripOn($v);
        $a = $this->fuel($v, 1060, 10, 30, ['tripNumber' => $t1]);  // 60 km ÷ 10 L = 6 km/L
        $b = $this->fuel($v, 1160, 20, 50);                         // 100 km ÷ 20 L = 5 km/L
        $this->assertEquals([6, 5], [$a['kmPerL'], $b['kmPerL']]);
        $this->assertEquals(30, $this->cost($t1)->fuel);
        $url = fn (array $rec) => "/api/transport/fuel/{$rec['number']}";

        // rejections — a reason is mandatory, the reading stays between its neighbours, the price ceiling still holds
        $invalid = $this->expectRejected($this->patchAs('disp', $url($a), ['cost' => 28]), 'INVALID_INPUT', [400]);
        $this->assertSame(['reason'], array_column($invalid['details'], 'path'));
        $this->expectRejected($this->patchAs('disp', $url($a), ['cost' => 28, 'reason' => 'x']), 'INVALID_INPUT', [400]);
        $body = $this->expectRejected($this->patchAs('disp', $url($a), ['odometer' => 1200, 'reason' => 'تصحيح']), 'FUEL_ODOMETER_ABOVE_NEXT', [422]);
        $this->assertStringContainsString($b['number'], $body['message']);
        $body = $this->expectRejected($this->patchAs('disp', $url($b), ['odometer' => 1050, 'reason' => 'تصحيح']), 'FUEL_ODOMETER_BELOW_PREVIOUS', [422]);
        $this->assertStringContainsString($a['number'], $body['message']);
        $this->expectRejected($this->patchAs('disp', $url($a), ['cost' => 400, 'reason' => 'تصحيح']), 'FUEL_PRICE', [422]);
        $this->expectRejected($this->patchAs('disp', $url($a), ['tripNumber' => 'TRP-NOPE', 'reason' => 'تصحيح']), 'TRIP_NOT_FOUND', [404]);
        $this->expectRejected($this->patchAs('disp', $url($a), ['tripNumber' => $this->tripOn($this->mkVehicle('dry')), 'reason' => 'تصحيح']), 'FUEL_TRIP_VEHICLE', [422]);
        $this->expectRejected($this->patchAs('disp', '/api/transport/fuel/FL-NOPE', ['cost' => 1, 'reason' => 'تصحيح']), 'FUEL_NOT_FOUND', [404]);
        $this->expectRejected($this->patchAs('disp', $url($a), ['date' => '2026-02-31', 'reason' => 'تصحيح']), 'BAD_DATE', [400]);
        $this->expectRejected($this->patchAs('disp', $url($a), ['liters' => 0, 'reason' => 'تصحيح']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->patchAs('driver', $url($a), ['cost' => 28, 'reason' => 'تصحيح']), 'FORBIDDEN', [403]);
        $fresh = FuelRecord::findOrFail($a['id']);
        $this->assertEquals([1060, 10, 30, null], [$fresh->odometer, $fresh->liters, $fresh->cost, $fresh->corrected_at], 'a refused correction changes nothing');
        $this->assertSame(1, Journal::where('source_id', $a['id'])->count());

        // the correction: reading, litres, amount, station and trip in one go
        $c = $this->expectOk($this->patchAs('disp', $url($a), ['odometer' => 1040, 'liters' => 8, 'cost' => 28, 'station' => 'محطة الدائري', 'tripNumber' => $t2, 'reason' => 'خطأ إدخال الإيصال']));
        $this->assertEquals([1040, 8, 28, 5, 0.7], [$c['odometer'], $c['liters'], $c['cost'], $c['kmPerL'], $c['costPerKm']], '40 km ÷ 8 L, 28 ÷ 40');
        $this->assertSame(['محطة الدائري', $t2, 'disp', 'خطأ إدخال الإيصال'], [$c['station'], $c['tripNumber'], $c['correctedBy'], $c['correctionNote']]);
        $this->assertNotNull($c['correctedAt']);
        $this->assertStringContainsString('صُحّح سجل الوقود', $c['message']);
        // … the next fill is re-measured from the corrected reading: 120 km ÷ 20 L
        $next = FuelRecord::findOrFail($b['id']);
        $this->assertEquals([6, 1040], [$next->km_per_l, $next->prev_odometer]);
        $this->assertNull($next->corrected_at, 'only the corrected record is stamped');
        // … the vehicle keeps the reading of the fill that carries it
        $this->assertSame(1160, Vehicle::where('code', $v['code'])->value('odometer'));
        // … the ledger: the old journal is reversed, the record is posted again under a new event key
        $journals = Journal::where('source_type', 'FuelRecord')->where('source_id', $a['id'])->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame(['reversed', 'posted', 'posted'], $journals->pluck('status')->all());
        $this->assertSame(['auto', 'reversal', 'auto'], $journals->pluck('kind')->all());
        $this->assertSame(["fuel:{$a['id']}", "reversal:{$journals[0]->id}", "fuel:{$a['id']}:v2"], $journals->pluck('event_key')->all());
        $this->assertEquals(28, $this->ledger($a['id']));
        $this->assertSame($journals[2]->id, $c['journalId']);
        $this->assertSame($journals[2]->number, $c['reposted']);
        // … both trips: the old one loses the fuel, the new one carries it
        $this->assertEquals(28, $this->cost($t2)->fuel);
        $this->assertSame('actual', $this->cost($t2)->basis['fuel']['source']);
        $this->assertNotSame('actual', $this->cost($t1)->basis['fuel']['source'], 'no fuel record on the first trip any more');
        // … audit + activity
        $audit = AuditLog::where('action', 'FUEL.CORRECT')->where('entity_id', $a['id'])->get();
        $this->assertCount(1, $audit);
        $this->assertStringContainsString('خطأ إدخال الإيصال', $audit[0]->new_value);
        $this->assertSame(2, ActivityLog::where('entity_type', 'FuelRecord')->where('entity_id', $a['id'])->count(), 'recorded + corrected');

        // a change that is not financial posts nothing; the same values again change nothing at all
        $this->expectOk($this->patchAs('disp', $url($a), ['station' => 'محطة أخرى', 'reason' => 'اسم المحطة']));
        $this->assertSame(3, Journal::where('source_id', $a['id'])->count());
        $same = $this->expectOk($this->patchAs('disp', $url($a), ['station' => 'محطة أخرى', 'cost' => 28, 'tripNumber' => $t2, 'reason' => 'بلا تغيير']));
        $this->assertSame('لا تغيير — السجل كما هو', $same['message']);
        $this->assertSame('اسم المحطة', FuelRecord::findOrFail($a['id'])->correction_note);
        $this->assertSame(2, AuditLog::where('action', 'FUEL.CORRECT')->where('entity_id', $a['id'])->count());

        // a second amount correction: v3
        $this->expectOk($this->patchAs('disp', $url($a), ['cost' => 26, 'reason' => 'خصم المحطة']));
        $this->assertSame("fuel:{$a['id']}:v3", Journal::where('source_id', $a['id'])->where('status', 'posted')->where('kind', 'auto')->value('event_key'));
        $this->assertEquals(26, $this->ledger($a['id']));
        $this->assertEquals(26, $this->cost($t2)->fuel);
        // the day is a financial fact too (it decides the period): moving it re-posts on the new day
        $this->expectOk($this->patchAs('disp', $url($a), ['date' => '2026-09-14', 'reason' => 'يوم الإيصال']));
        $posted = Journal::where('source_id', $a['id'])->where('status', 'posted')->where('kind', 'auto')->firstOrFail();
        $this->assertSame(["fuel:{$a['id']}:v4", '2026-09-14'], [$posted->event_key, $posted->journal_date->toDateString()]);
        $this->assertEquals(26, $this->ledger($a['id']));

        // the fill that carries the vehicle's reading: correcting it moves the odometer, and a consumption that is now
        // far below the average raises the fuel alert — putting it back clears the alert
        $low = $this->expectOk($this->patchAs('disp', $url($b), ['odometer' => 1100, 'reason' => 'قراءة خاطئة']));
        $this->assertEquals(3, $low['kmPerL'], '60 km ÷ 20 L');
        $this->assertTrue($low['anomaly']);
        $this->assertSame('fuel', $low['alert']['category']);
        $this->assertSame(1100, $low['vehicleOdometer']);
        $this->assertSame(1100, Vehicle::where('code', $v['code'])->value('odometer'));
        $this->assertSame(1, Journal::where('source_id', $b['id'])->count(), 'the amount did not change: nothing is re-posted');
        $back = $this->expectOk($this->patchAs('disp', $url($b), ['odometer' => 1160, 'reason' => 'القراءة الأولى كانت صحيحة']));
        $this->assertFalse($back['anomaly']);
        $this->assertSame(1160, Vehicle::where('code', $v['code'])->value('odometer'));
        $this->assertSame(0, FleetAlert::where('entity_code', $v['code'])->where('category', 'fuel')->whereIn('status', self::OPEN)->count(), 'no anomalous fill left: the alert is resolved');
    }

    // ───────── fuel: void ─────────
    public function test_fuel_void_reverses_the_journal_and_counts_nowhere(): void
    {
        $v = $this->mkVehicle('dry'); // odometer 1000
        $trip = $this->tripOn($v);
        $a = $this->fuel($v, 1060, 10, 30, ['tripNumber' => $trip]);
        $b = $this->fuel($v, 1160, 50, 100); // 2 km/L → anomaly + alert
        $this->assertTrue($b['anomaly']);
        $this->assertSame(1, FleetAlert::where('entity_code', $v['code'])->where('category', 'fuel')->whereIn('status', self::OPEN)->count());
        $void = fn (array $rec, array $body = []) => $this->postAs('disp', "/api/transport/fuel/{$rec['number']}/void", $body);

        $this->expectRejected($void($b), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('driver', "/api/transport/fuel/{$b['number']}/void", ['reason' => 'مكرر']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('disp', '/api/transport/fuel/FL-NOPE/void', ['reason' => 'مكرر']), 'FUEL_NOT_FOUND', [404]);
        $this->assertSame(405, $this->deleteAs('disp', "/api/transport/fuel/{$b['number']}")->getStatusCode(), 'a posted record is voided, never deleted');
        $this->assertNull(FuelRecord::findOrFail($b['id'])->voided_at);

        $res = $void($b, ['reason' => 'سُجل مرتين']);
        $this->assertSame(201, $res->getStatusCode());
        $r = $this->expectOk($res);
        $this->assertSame(['disp', 'سُجل مرتين', 1060], [$r['voidedBy'], $r['voidReason'], $r['vehicleOdometer']]);
        $this->assertNotNull($r['voidedAt']);
        $this->assertNotNull($r['reversal']);
        $this->assertStringContainsString('عُكس القيد', $r['message']);
        // the journal is reversed, the odometer is back on the highest remaining reading, the alert is gone
        $this->assertSame(['reversed', 'posted'], Journal::where('source_id', $b['id'])->orderBy('created_at')->orderBy('id')->pluck('status')->all());
        $this->assertEquals(0, $this->ledger($b['id']));
        $this->assertSame(1060, Vehicle::where('code', $v['code'])->value('odometer'));
        $this->assertSame(0, FleetAlert::where('entity_code', $v['code'])->where('category', 'fuel')->whereIn('status', self::OPEN)->count());
        $this->assertSame(1, AuditLog::where('action', 'FUEL.VOID')->where('entity_id', $b['id'])->count());
        // it stays in the list (struck in the client) but counts in no total, anomaly filter or KPI
        $list = $this->expectOk($this->getAs('sales', "/api/transport/fuel?vehicle={$v['code']}"));
        $this->assertSame(2, $list['total']);
        $this->assertEquals(['records' => 1, 'liters' => 10, 'cost' => 30, 'voided' => 1], $list['totals']);
        $this->assertSame(0, $this->expectOk($this->getAs('sales', "/api/transport/fuel?vehicle={$v['code']}&anomaly=true"))['total']);
        $this->assertSame([$b['number']], array_column($this->expectOk($this->getAs('sales', "/api/transport/fuel?vehicle={$v['code']}&status=voided"))['items'], 'number'));
        $this->assertSame([$a['number']], array_column($this->expectOk($this->getAs('sales', "/api/transport/fuel?vehicle={$v['code']}&status=active"))['items'], 'number'));
        $this->assertSame([$a['number']], array_column($this->expectOk($this->getAs('sales', "/api/transport/fuel?trip={$trip}"))['items'], 'number'));
        $kpis = $this->expectOk($this->getAs('sales', '/api/transport/fleet/kpis'))['fuel'];
        $this->assertSame(FuelRecord::whereNull('voided_at')->where('anomaly', true)->count(), $kpis['anomalies']);
        $this->assertEquals(FuelRecord::whereNull('voided_at')->where('date', '>=', now()->subDays(30))->sum('cost'), $kpis['cost30d']);

        // a void is final
        $this->expectRejected($void($b, ['reason' => 'مرة أخرى']), 'FUEL_VOIDED', [422]);
        $this->expectRejected($this->patchAs('disp', "/api/transport/fuel/{$b['number']}", ['cost' => 90, 'reason' => 'تصحيح']), 'FUEL_VOIDED', [422]);
        $this->assertSame(2, Journal::where('source_id', $b['id'])->count());

        // a third fill, then the FIRST one is voided: the later fill is re-measured from the reading before the voided
        // one, the vehicle keeps its reading, and the trip loses the fuel
        $c = $this->fuel($v, 1200, 20, 50); // from 1060: 140 km ÷ 20 L = 7 km/L
        $this->assertEquals(7, $c['kmPerL']);
        $this->expectOk($void($a, ['reason' => 'مركبة خاطئة']));
        $third = FuelRecord::findOrFail($c['id']);
        $this->assertEquals([10, 1000], [$third->km_per_l, $third->prev_odometer], '200 km from the vehicle\'s reading before the voided fill');
        $this->assertSame(1200, Vehicle::where('code', $v['code'])->value('odometer'));
        $this->assertEquals(0, $this->ledger($a['id']));
        $this->assertNotSame('actual', $this->cost($trip)->basis['fuel']['source']);

        // voiding the last live fill puts the vehicle back on the reading it had before any of them
        $this->expectOk($void($c, ['reason' => 'إيصال ملغى']));
        $this->assertSame(1000, Vehicle::where('code', $v['code'])->value('odometer'));
        $this->assertEquals(0, $this->cost($trip)->fuel);
        $again = $this->fuel($v, 1010, 2, 5); // and the next real reading is accepted
        $this->assertEquals(5, $again['kmPerL']);
    }

    /** Fuel records written before this release have no stored "reading before" (`prev_odometer` null): their distance is kept. */
    public function test_older_fuel_records_keep_the_distance_they_measured(): void
    {
        $v = $this->mkVehicle('dry'); // odometer 1000
        $a = $this->fuel($v, 1060, 10, 30); // 60 km → 6 km/L
        FuelRecord::where('id', $a['id'])->update(['prev_odometer' => null]);
        $c = $this->expectOk($this->patchAs('disp', "/api/transport/fuel/{$a['number']}", ['liters' => 12, 'reason' => 'لترات الإيصال']));
        $this->assertEquals(5, $c['kmPerL'], 'still 60 km, now on 12 L');
        $this->assertSame(1000, FuelRecord::findOrFail($a['id'])->prev_odometer, 'the reading it started from is stored with the correction');

        $w = $this->mkVehicle('dry');
        $b = $this->fuel($w, 1090, 30, 60); // 90 km → 3 km/L
        FuelRecord::where('id', $b['id'])->update(['prev_odometer' => null]);
        $r = $this->expectOk($this->postAs('disp', "/api/transport/fuel/{$b['number']}/void", ['reason' => 'مركبة خاطئة']));
        $this->assertSame(1000, $r['vehicleOdometer'], 'back to the reading the record started from (1090 − 90 km)');
    }

    // ───────── maintenance: edit / cancel ─────────
    public function test_open_maintenance_order_is_edited_and_cancelled(): void
    {
        $v = $this->mkVehicle('dry');
        $mk = fn (array $extra = []) => $this->expectOk($this->postAs('disp', '/api/transport/maintenance', $extra + ['vehicleCode' => $v['code'], 'desc' => 'فحص', 'shop' => 'ورشة أ', 'startDate' => '2026-09-15']));
        $first = $mk(['block' => true]);
        $second = $mk();
        $this->assertSame('maintenance', Vehicle::where('code', $v['code'])->value('state'));
        $url = "/api/transport/maintenance/{$first['number']}";

        // edit
        $this->expectRejected($this->patchAs('disp', $url, ['kind' => 'paint']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->patchAs('disp', '/api/transport/maintenance/MNT-NOPE', ['shop' => 'x']), 'MAINT_NOT_FOUND', [404]);
        $this->expectRejected($this->patchAs('wm', $url, ['shop' => 'x']), 'FORBIDDEN', [403]);
        $e = $this->expectOk($this->patchAs('disp', $url, ['kind' => 'brake', 'desc' => 'تغيير فرامل', 'shop' => 'ورشة ب', 'startDate' => '2026-09-16', 'odometer' => 1500, 'cost' => 300, 'downDays' => 3, 'nextKm' => 20000]));
        $this->assertSame(['brake', 'فرامل', 'Brakes', 'تغيير فرامل', 'ورشة ب', 1500, 3, '3 يوم', 20000, 'open'], [$e['kind'], $e['typeAr'], $e['typeEn'], $e['descAr'], $e['shop'], $e['odometer'], $e['downDays'], $e['downLabel'], $e['nextKm'], $e['status']]);
        $this->assertEquals(300, $e['cost']);
        $this->assertStringStartsWith('2026-09-16', $e['startDate']);
        $this->assertSame(['code', 'plateAr', 'state'], array_keys($e['vehicle']));
        $this->assertSame(8, AuditLog::where('action', 'MAINT.UPDATE')->where('entity_id', $first['id'])->count(), 'one audit row per changed field');
        $this->assertSame('لا تغيير — الأمر كما هو', $this->expectOk($this->patchAs('disp', $url, ['shop' => 'ورشة ب']))['message']);
        $this->assertSame(8, AuditLog::where('action', 'MAINT.UPDATE')->where('entity_id', $first['id'])->count());
        $this->assertSame([], Journal::where('source_type', 'MaintenanceOrder')->where('source_id', $first['id'])->get()->all(), 'an open order posts nothing');

        // cancel: a reason, the permission, and the vehicle is released only when no other order is open
        $this->expectRejected($this->postAs('disp', "{$url}/cancel"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/cancel", ['reason' => 'خطأ']), 'FORBIDDEN', [403]);
        $this->assertSame(405, $this->deleteAs('disp', $url)->getStatusCode());
        $res = $this->postAs('disp', "{$url}/cancel", ['reason' => 'فُتح بالخطأ']);
        $this->assertSame(201, $res->getStatusCode());
        $c = $this->expectOk($res);
        $this->assertSame(['cancelled', 'disp', 'فُتح بالخطأ', false], [$c['status'], $c['cancelledBy'], $c['cancelReason'], $c['vehicleReleased']]);
        $this->assertNotNull($c['cancelledAt']);
        $this->assertSame('maintenance', Vehicle::where('code', $v['code'])->value('state'), 'the second order is still open');
        $released = $this->expectOk($this->postAs('disp', "/api/transport/maintenance/{$second['number']}/cancel", ['reason' => 'لن تُنفذ']));
        $this->assertTrue($released['vehicleReleased']);
        $this->assertSame('available', $released['vehicle']['state']);
        $this->assertStringContainsString('عادت «متاحة»', $released['message']);
        $this->assertSame(['open', 'cancelled'], [StatusHistory::where('entity_type', 'MaintenanceOrder')->where('entity_id', $second['id'])->value('from_status'), StatusHistory::where('entity_type', 'MaintenanceOrder')->where('entity_id', $second['id'])->value('to_status')]);
        $this->assertSame('maintenance', StatusHistory::where('entity_type', 'Vehicle')->where('entity_id', $v['id'])->orderByDesc('at')->orderByDesc('id')->value('from_status'), 'the vehicle left «maintenance» through the state machine');
        $this->assertTrue($this->expectOk($this->getAs('disp', "/api/transport/vehicles/{$v['code']}"))['canAssign']['ok']);

        // a cancelled order is final: no edit, no second cancel, no close — and nothing was posted
        $this->expectRejected($this->patchAs('disp', $url, ['shop' => 'x']), 'MAINT_NOT_OPEN', [422]);
        $this->expectRejected($this->postAs('disp', "{$url}/cancel", ['reason' => 'ثانية']), 'MAINT_NOT_OPEN', [422]);
        $this->expectRejected($this->postAs('disp', "{$url}/close", ['endDate' => '2026-09-17']), 'MAINT_CANCELLED', [422]);
        $this->assertSame(0, Journal::where('source_type', 'MaintenanceOrder')->whereIn('source_id', [$first['id'], $second['id']])->count());
        $this->assertSame(0, MaintenanceOrder::where('vehicle_id', $v['id'])->where('status', 'open')->count());

        // a closed order has posted its cost: it is neither edited nor cancelled
        $third = $mk();
        $this->expectOk($this->postAs('disp', "/api/transport/maintenance/{$third['number']}/close", ['endDate' => '2026-09-17', 'cost' => 120]));
        $this->expectRejected($this->patchAs('disp', "/api/transport/maintenance/{$third['number']}", ['shop' => 'x']), 'MAINT_NOT_OPEN', [422]);
        $this->expectRejected($this->postAs('disp', "/api/transport/maintenance/{$third['number']}/cancel", ['reason' => 'خطأ']), 'MAINT_NOT_OPEN', [422]);

        // lists: the status filter knows «cancelled»; without a filter open orders come first
        $fourth = $mk();
        $all = $this->expectOk($this->getAs('sales', "/api/transport/maintenance?vehicle={$v['code']}"));
        $this->assertSame([$fourth['number']], array_slice(array_column($all['items'], 'number'), 0, 1));
        $this->assertSame(['open', 'closed', 'cancelled', 'cancelled'], array_column($all['items'], 'status'));
        $this->assertSame(2, $this->expectOk($this->getAs('sales', "/api/transport/maintenance?vehicle={$v['code']}&status=cancelled"))['total']);
    }

    // ───────── driver fuel / maintenance requests ─────────
    public function test_processed_fuel_request_is_a_real_fuel_record(): void
    {
        $v = $this->mkVehicle('dry'); // odometer 1000
        $d = $this->mkDriver();
        $trip = $this->tripOn($v);
        $request = fn (array $extra) => $this->expectOk($this->postAs('admin', '/api/transport/ops-requests', $extra + ['driverCode' => $d['code'], 'vehicleCode' => $v['code'], 'desc' => 'طلب']));
        $process = fn (array $r, array $body = []) => $this->postAs('disp', "/api/transport/ops-requests/{$r['number']}/status", $body + ['to' => 'processed']);
        $approve = fn (array $r) => $this->expectOk($this->postAs('disp', "/api/transport/ops-requests/{$r['number']}/status", ['to' => 'approved']));

        // no litres on the receipt: cost only, a partial fill outside the km/L average — but in the ledger and on the trip
        $r1 = $request(['type' => 'fuel', 'amount' => 233, 'attachment' => 'r1.jpg', 'location' => 'أرامكو', 'tripNumber' => $trip]);
        $approve($r1);
        $p = $this->expectOk($process($r1));
        $f = $p['fuelRecord'];
        $this->assertEquals([0, 233, 1000], [$f['liters'], $f['cost'], $f['odometer']]);
        $this->assertSame([false, null, null, 'أرامكو', 'r1.jpg', $trip, $d['code']], [$f['full'], $f['kmPerL'], $f['costPerKm'], $f['station'], $f['receipt'], $f['tripNumber'], $f['driver']['code']]);
        $journal = Journal::findOrFail($f['journalId']);
        $this->assertSame(["fuel:{$f['id']}", 'posted'], [$journal->event_key, $journal->status]);
        $this->assertEquals(233, $journal->total_debit);
        $this->assertEquals(233, $this->cost($trip)->fuel);
        $this->assertSame(1000, Vehicle::where('code', $v['code'])->value('odometer'), 'no reading was taken: the odometer does not move');
        $this->assertStringContainsString($r1['number'], AuditLog::where('action', 'FUEL.CREATE')->where('entity_id', $f['id'])->value('new_value'));
        $this->assertStringContainsString('بلا لترات', $p['message']);

        // litres and the reading typed from the receipt: a full fuel record, measured like any other
        $r2 = $request(['type' => 'fuel', 'amount' => 240, 'attachment' => 'r2.jpg']);
        $approve($r2);
        $this->expectRejected($process($r2, ['liters' => 0]), 'INVALID_INPUT', [400]);
        $this->expectRejected($process($r2, ['liters' => 50, 'odometer' => 900]), 'FUEL_ODOMETER', [422]);
        $this->expectRejected($process($r2, ['liters' => 10, 'odometer' => 1600]), 'FUEL_PRICE', [422]);
        $this->assertSame('approved', OpsRequest::findOrFail($r2['id'])->status, 'a refused fuel record leaves the request where it was');
        $this->assertSame(1, FuelRecord::where('vehicle_id', $v['id'])->count());
        $f2 = $this->expectOk($process($r2, ['liters' => 100, 'odometer' => 1600]))['fuelRecord'];
        $this->assertEquals([100, 240, 1600, 6, 0.4], [$f2['liters'], $f2['cost'], $f2['odometer'], $f2['kmPerL'], $f2['costPerKm']], '600 km since the reading of the fill before it');
        $this->assertNull($f2['tripNumber']);
        $this->assertSame(1600, Vehicle::where('code', $v['code'])->value('odometer'));
        $this->assertNotNull($f2['journalId']);

        // the request's trip is charged only when it runs on the fuelled vehicle
        $elsewhere = $this->tripOn($this->mkVehicle('dry'));
        $r3 = $request(['type' => 'fuel', 'amount' => 50, 'attachment' => 'r3.jpg', 'tripNumber' => $elsewhere]);
        $approve($r3);
        $this->assertNull($this->expectOk($process($r3))['fuelRecord']['tripNumber']);

        // a maintenance request opens an order through the one maintenance path: workshop when given, never a made-up one
        $r4 = $request(['type' => 'tire', 'desc' => 'إطار تالف']);
        $approve($r4);
        $mo = $this->expectOk($process($r4, ['shop' => 'ورشة الإطارات']))['maintenanceOrder'];
        $this->assertSame(['tire', 'open', 'ورشة الإطارات'], [$mo['kind'], $mo['status'], $mo['shop']]);
        $r5 = $request(['type' => 'maint', 'desc' => 'صوت في المحرك']);
        $approve($r5);
        $p5 = $this->expectOk($process($r5));
        $this->assertNull($p5['maintenanceOrder']['shop']);
        $this->assertStringContainsString($p5['maintenanceOrder']['number'], $p5['message']);
        $fixed = $this->expectOk($this->patchAs('disp', "/api/transport/maintenance/{$p5['maintenanceOrder']['number']}", ['shop' => 'ورشة المحركات']));
        $this->assertSame('ورشة المحركات', $fixed['shop']);
    }

    // ───────── vehicle: odometer rule, cost card, document renewal ─────────
    public function test_vehicle_odometer_goes_down_only_with_a_reason_and_documents_renew_at_once(): void
    {
        $v = $this->mkVehicle('dry'); // odometer 1000
        $url = "/api/transport/vehicles/{$v['code']}";

        // a higher reading is a plain update; a lower one needs its reason
        $this->assertSame(1500, $this->expectOk($this->patchAs('disp', $url, ['odometer' => 1500]))['odometer']);
        $body = $this->expectRejected($this->patchAs('disp', $url, ['odometer' => 1200]), 'VEHICLE_ODOMETER_LOWER', [422]);
        $this->assertStringContainsString('1,500', $body['message']);
        $this->expectRejected($this->patchAs('disp', $url, ['odometer' => 1200, 'odometerReason' => ' ']), 'VEHICLE_ODOMETER_LOWER', [422]);
        $this->expectRejected($this->patchAs('disp', $url, ['odometer' => 1200, 'odometerReason' => 'ab', 'maxKg' => 9999]), 'VEHICLE_ODOMETER_LOWER', [422]);
        $fresh = Vehicle::where('code', $v['code'])->first();
        $this->assertEquals([1500, 3000], [$fresh->odometer, $fresh->max_kg], 'the refused call changed nothing — not even the other fields it carried');
        $this->assertSame(0, AuditLog::where('action', 'VEHICLE.ODOMETER_CORRECTION')->where('entity_id', $v['id'])->count());

        $fixed = $this->expectOk($this->patchAs('disp', $url, ['odometer' => 1200, 'odometerReason' => 'قراءة أُدخلت بخطأ']));
        $this->assertSame(1200, $fixed['odometer']);
        $correction = AuditLog::where('action', 'VEHICLE.ODOMETER_CORRECTION')->where('entity_id', $v['id'])->get();
        $this->assertCount(1, $correction);
        $this->assertSame('1500', $correction[0]->old_value);
        $this->assertStringContainsString('قراءة أُدخلت بخطأ', $correction[0]->new_value);
        $this->assertSame(1, AuditLog::where('action', 'VEHICLE.UPDATE')->where('entity_id', $v['id'])->where('field', 'odometer')->count(), 'only the raise is a plain update');
        $this->assertSame(1, ActivityLog::where('entity_type', 'Vehicle')->where('entity_id', $v['id'])->where('text_ar', 'like', '%صُحّح عداد%')->count());
        // a reason sent with a reading that is not lower is ignored
        $this->expectOk($this->patchAs('disp', $url, ['odometer' => 1300, 'odometerReason' => 'غير لازم']));
        $this->assertSame(1, AuditLog::where('action', 'VEHICLE.ODOMETER_CORRECTION')->where('entity_id', $v['id'])->count());

        // cost card of the trip cost sheet: purchase cost, salvage value, useful life
        $audits = AuditLog::where('action', 'VEHICLE.UPDATE')->where('entity_id', $v['id'])->count();
        $card = $this->expectOk($this->patchAs('disp', $url, ['purchaseCost' => 120000, 'salvageValue' => 20000, 'lifeKm' => 400000]));
        $this->assertSame(['120000.00', '20000.00', 400000], [$card['purchaseCost'], $card['salvageValue'], $card['lifeKm']]);
        $this->assertSame($audits + 3, AuditLog::where('action', 'VEHICLE.UPDATE')->where('entity_id', $v['id'])->count());
        $this->expectOk($this->patchAs('disp', $url, ['purchaseCost' => '120000.00', 'salvageValue' => 20000, 'lifeKm' => 400000, 'warehouseCode' => 'RYD']));
        $this->assertSame($audits + 3, AuditLog::where('action', 'VEHICLE.UPDATE')->where('entity_id', $v['id'])->count(), 'the same card and the same warehouse again: no audit noise');
        $this->expectRejected($this->patchAs('disp', $url, ['salvageValue' => 130000]), 'VEHICLE_SALVAGE', [400]);
        $this->expectRejected($this->patchAs('disp', $url, ['lifeKm' => 0]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->patchAs('disp', $url, ['purchaseCost' => -1]), 'INVALID_INPUT', [400]);
        $cleared = $this->expectOk($this->patchAs('disp', $url, ['purchaseCost' => null, 'salvageValue' => null, 'lifeKm' => null]));
        $this->assertSame([null, null, null], [$cleared['purchaseCost'], $cleared['salvageValue'], $cleared['lifeKm']]);
        $this->expectRejected($this->postAs('disp', '/api/transport/vehicles', ['code' => self::code('TV-S'), 'plateAr' => 'x', 'vin' => 'x', 'brand' => 'x', 'maxKg' => 1, 'maxCbm' => 1,
            'regExpiry' => self::FAR, 'insuranceExpiry' => self::FAR, 'inspectionExpiry' => self::FAR, 'purchaseCost' => 100, 'salvageValue' => 200]), 'VEHICLE_SALVAGE', [400]);

        // renewing a document clears its alert at once (no need to wait for the next alert sweep) and is an activity line
        Vehicle::where('code', $v['code'])->update(['insurance_expiry' => now()->subDays(2)]);
        $this->expectOk($this->postAs('disp', '/api/transport/alerts/generate'));
        $alert = FleetAlert::where('entity_code', $v['code'])->where('category', 'vehdoc')->whereIn('status', self::OPEN)->firstOrFail();
        $this->assertFalse($this->expectOk($this->getAs('disp', $url))['canAssign']['ok']);
        $renewed = $this->expectOk($this->patchAs('disp', $url, ['insuranceExpiry' => self::FAR]));
        $this->assertTrue($renewed['canAssign']['ok']);
        $this->assertSame([], $renewed['alerts']);
        $this->assertSame('resolved', $alert->refresh()->status);
        $this->assertSame(1, ActivityLog::where('entity_type', 'Vehicle')->where('entity_id', $v['id'])->where('text_ar', 'like', '%حُدّثت وثائق%التأمين%')->count());
    }

    // ───────── driver: edit, the block follows the documents, the alert says what really happens ─────────
    public function test_driver_documents_block_follows_licence_and_iqama_and_the_medical_certificate_only_warns(): void
    {
        $d = $this->mkDriver(['medicalExpiry' => self::FAR]);
        $url = "/api/transport/drivers/{$d['code']}";
        $alert = fn () => FleetAlert::where('entity_code', $d['code'])->where('category', 'driverdoc')->whereIn('status', self::OPEN)->first();
        $this->assertSame(['blocks', 'blocks', 'warns'], array_column($d['docs'], 'effect'));
        $this->assertNull($d['docBlock']);

        // master data: hourly cost of the trip cost sheet, mobile, licence, shift
        $u = $this->expectOk($this->patchAs('disp', $url, ['hourlyCost' => 35, 'mobile' => '0555111222', 'licenseNo' => 'L-NEW', 'licenseType' => 'ثقيل', 'shift' => 'pm']));
        $this->assertSame(['35.00', '0555111222', 'L-NEW', 'ثقيل', 'PM'], [$u['hourlyCost'], $u['mobile'], $u['licenseNo'], $u['licenseType'], $u['shiftEn']]);
        $audits = AuditLog::where('action', 'DRIVER.UPDATE')->where('entity_id', $d['id'])->count();
        $this->expectOk($this->patchAs('disp', $url, ['hourlyCost' => '35.00', 'defaultVehicleCode' => null]));
        $this->assertSame($audits, AuditLog::where('action', 'DRIVER.UPDATE')->where('entity_id', $d['id'])->count(), 'same values: no audit noise');
        $this->assertNull($this->expectOk($this->patchAs('disp', $url, ['hourlyCost' => null]))['hourlyCost']);
        $this->expectRejected($this->patchAs('disp', $url, ['hourlyCost' => -5]), 'INVALID_INPUT', [400]);

        // the medical certificate about to expire: a warning, never a block
        $this->expectOk($this->patchAs('disp', $url, ['medicalExpiry' => now()->addDays(3)->toDateString()]));
        $a = $alert();
        $this->assertNotNull($a, 'the alert is raised with the change, not at the next sweep');
        $this->assertSame('w', $a->severity);
        $this->assertSame('الشهادة الصحية: تنبيه فقط — لا توقف الإسناد', $a->recommend_ar);
        $drv = $this->expectOk($this->getAs('disp', $url));
        $this->assertSame([false, true, null], [$drv['blocked'], $drv['canAssign']['ok'], $drv['docBlock']]);

        // an expired iqama stops assignment: blocked at once, critical alert, and the block cannot be lifted by hand
        $expired = $this->expectOk($this->patchAs('disp', $url, ['iqamaExpiry' => now()->subDays(3)->toDateString()]));
        $this->assertTrue($expired['blocked']);
        $this->assertFalse($expired['canAssign']['ok']);
        $this->assertSame('iqamaExpiry', $expired['docBlock']['doc']);
        $a = $alert();
        $this->assertSame('c', $a->severity);
        $this->assertStringContainsString('الإقامة / الهوية منتهية: الإسناد موقوف حتى التجديد', $a->recommend_ar);
        $this->assertStringContainsString('الشهادة الصحية: تنبيه فقط', $a->recommend_ar);
        $this->assertSame(1, AuditLog::where('action', 'DRIVER.BLOCK')->where('entity_id', $d['id'])->count());
        $body = $this->expectRejected($this->patchAs('disp', $url, ['blocked' => false]), 'DRIVER_DOCS_BLOCK', [422]);
        $this->assertStringContainsString('الإقامة / الهوية منتهية', $body['message']);
        $this->assertTrue((bool) Driver::where('code', $d['code'])->value('blocked'));

        // renewing it lifts the block in the same call
        $ok = $this->expectOk($this->patchAs('disp', $url, ['iqamaExpiry' => self::FAR, 'medicalExpiry' => self::FAR]));
        $this->assertSame([false, true, null], [$ok['blocked'], $ok['canAssign']['ok'], $ok['docBlock']]);
        $this->assertNull($alert(), 'nothing is due any more: the alert is resolved');
        $this->assertSame(1, ActivityLog::where('entity_type', 'Driver')->where('entity_id', $d['id'])->where('text_ar', 'like', '%رُفع إيقاف الإسناد%')->count());

        // a licence under fleet.driverLicenseMinDays (30) stops assignment too, expired or not
        $soon = $this->expectOk($this->patchAs('disp', $url, ['licenseExpiry' => now()->addDays(10)->toDateString()]));
        $this->assertTrue($soon['blocked']);
        $this->assertSame(['licenseExpiry', 10], [$soon['docBlock']['doc'], $soon['docBlock']['days']]);
        $a = $alert();
        $this->assertSame('c', $a->severity);
        $this->assertSame('الرخصة: الإسناد موقوف حتى التجديد (أقل من 30 يومًا)', $a->recommend_ar);
        $this->assertStringContainsString('License: assignment blocked until renewed', $a->recommend_en);
        // a manual block is still possible, and is the only one a person may lift
        $free = $this->expectOk($this->patchAs('disp', $url, ['licenseExpiry' => self::FAR]));
        $this->assertFalse($free['blocked']);
        $this->assertTrue($this->expectOk($this->patchAs('disp', $url, ['blocked' => true]))['blocked']);
        $this->assertFalse($this->expectOk($this->patchAs('disp', $url, ['blocked' => false]))['blocked']);

        // the sweep says the same thing for every driver, and a driver created with an expired iqama starts blocked
        $late = $this->mkDriver(['iqamaExpiry' => now()->subDay()->toDateString()]);
        $this->assertTrue($late['blocked']);
        $this->assertFalse($late['canAssign']['ok']);
        $this->expectOk($this->postAs('disp', '/api/transport/alerts/generate'));
        foreach ([$late['code'] => 'الإقامة / الهوية منتهية: الإسناد موقوف حتى التجديد', 'DRV-11' => 'الرخصة: الإسناد موقوف حتى التجديد'] as $code => $advice) { // DRV-11: demo driver, licence ends 2026-09-21
            $open = FleetAlert::where('entity_code', $code)->where('category', 'driverdoc')->whereIn('status', self::OPEN)->firstOrFail();
            $this->assertStringContainsString($advice, (string) $open->recommend_ar);
            $this->assertSame('c', $open->severity);
        }
        $this->assertSame(0, FleetAlert::where('category', 'driverdoc')->where('recommend_ar', 'like', '%الإسناد موقوف آليًا%')->whereIn('status', self::OPEN)->whereIn('entity_code', Driver::where('active', true)->pluck('code'))->count(), 'the old blanket sentence is gone');
    }
}
