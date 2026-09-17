<?php

namespace Tests\Feature\Master;

use App\Models\AuditLog;
use App\Models\Bin;
use App\Models\DockAppointment;
use App\Models\InventoryBalance;
use App\Models\Rack;
use App\Models\Route;
use App\Models\StatusHistory;
use App\Models\Warehouse;
use App\Models\Zone;
use Tests\ApiTestCase;

/** Warehouses, generated zone layouts, bins, staff certificates, dock booking, delivery routes and the lookups payload. */
class WarehousesTest extends ApiTestCase
{
    private const WH = 'TQA';

    private static string $n = '';

    private static function n(): string
    {
        return self::$n ?: self::$n = self::uid();
    }

    /** @return array{0:string, 1:string} warehouse code + bin code of a seeded, active shelf bin that holds stock */
    private static function binWithStock(): array
    {
        $balance = InventoryBalance::with(['bin', 'warehouse'])->where('on_hand', '>', 0)
            ->whereHas('bin', fn ($b) => $b->where('status', 'active')->where('type', 'shelf'))->orderBy('id')->firstOrFail();

        return [$balance->warehouse->code, $balance->bin->code];
    }

    // ───────────────────────────── warehouses ─────────────────────────────

    public function test_create_warehouse_validates_code_and_area(): void
    {
        $base = ['nameAr' => 'مستودع اختبار '.self::n(), 'city' => 'الرياض', 'areaM2' => 1000, 'docks' => 2];
        $body = $this->expectRejected($this->postAs('wm', '/api/warehouses', $base + ['code' => 'AB1']), 'INVALID_INPUT', [400]);
        $this->assertSame('الرمز 3 أحرف لاتينية', $body['details'][0]['message']);
        $this->expectRejected($this->postAs('wm', '/api/warehouses', ['code' => self::WH, 'areaM2' => 0] + $base), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/warehouses', ['code' => self::WH, 'type' => 'shed'] + $base), 'INVALID_INPUT', [400]);

        $w = $this->expectOk($this->postAs('wm', '/api/warehouses', $base + ['code' => strtolower(self::WH), 'openDate' => '2026-10-01']));
        $this->assertSame(self::WH, $w['code']);
        $this->assertSame('dc', $w['type']);
        $this->assertSame('all', $w['tempZones']);
        $this->assertSame('06:00 – 22:00', $w['hours']);
        $this->assertSame(2, $w['docks']);
        $this->assertStringStartsWith('2026-10-01T00:00:00', $w['openDate']);
        $this->assertStringContainsString(self::WH, $w['messageAr']);
        $this->assertSame(1, AuditLog::where('entity_id', $w['id'])->where('action', 'WAREHOUSE.CREATE')->count());

        $dup = $this->expectRejected($this->postAs('wm', '/api/warehouses', $base + ['code' => self::WH]), 'WAREHOUSE_CODE_TAKEN', [409]);
        $this->assertSame('الرمز مستخدم', $dup['message']);
    }

    public function test_zone_creation_generates_racks_and_bins(): void
    {
        $wh = self::WH;
        $r = $this->expectOk($this->postAs('wm', "/api/warehouses/{$wh}/zones", ['code' => 'q', 'nameAr' => 'منطقة Q', 'type' => 'chill', 'aisles' => 2, 'racks' => 3, 'bins' => 4]));
        $this->assertSame('Q', $r['zone']['code']);
        $this->assertSame('chilled', $r['zone']['type']);
        $this->assertEquals(2, $r['zone']['minTempC']);
        $this->assertEquals(6, $r['zone']['maxTempC']);
        $this->assertSame('fefo', $r['zone']['pickStrategy']);
        $this->assertSame([6, 24, 'Q-01-1-B1', 'Q-02-3-B4'], [$r['racksCount'], $r['binsCount'], $r['firstBin'], $r['lastBin']]);
        $this->assertSame(24, Bin::where('zone_id', $r['zone']['id'])->count());
        $this->assertSame(6, Rack::where('zone_id', $r['zone']['id'])->count());
        $bin = Bin::with('rack')->where('zone_id', $r['zone']['id'])->where('code', 'Q-02-3-B4')->firstOrFail();
        $this->assertSame('Q-02-3', $bin->rack->code);
        $this->assertSame(4, $bin->shelf_no);
        $this->assertSame(400, $bin->capacity_units);

        $taken = $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/zones", ['code' => 'Q', 'nameAr' => 'x']), 'ZONE_CODE_TAKEN', [409]);
        $this->assertSame('الرمز Q مستخدم في هذا المستودع', $taken['message']);
        $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/zones", ['code' => 'QQQ', 'nameAr' => 'x']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/zones", ['code' => 'Z', 'nameAr' => 'x', 'aisles' => 0]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/zones", ['code' => 'Z', 'nameAr' => 'x', 'aisles' => 99, 'racks' => 50, 'bins' => 50]), 'ZONE_TOO_LARGE', [400]);
        $this->expectRejected($this->postAs('wm', '/api/warehouses/XXX/zones', ['code' => 'Z', 'nameAr' => 'x']), 'WAREHOUSE_NOT_FOUND', [404]);
        $this->assertSame(1, Zone::where('warehouse_id', $r['zone']['warehouseId'])->count());

        // A default layout: 4 aisles × 3 racks × 4 bins.
        $d = $this->expectOk($this->postAs('wm', "/api/warehouses/{$wh}/zones", ['code' => 'D', 'nameAr' => 'منطقة جافة']));
        $this->assertSame([12, 48, 'ambient'], [$d['racksCount'], $d['binsCount'], $d['zone']['type']]);
        $this->assertNull($d['zone']['minTempC']);
    }

    public function test_warehouse_detail_list_zones_and_racks(): void
    {
        $wh = self::WH;
        $detail = $this->expectOk($this->getAs('sales', '/api/warehouses/'.strtolower($wh)));
        $q = collect($detail['zones'])->firstWhere('code', 'Q');
        $this->assertSame([24, 6], [$q['binsCount'], $q['racksCount']]);
        $this->assertSame(['racks' => 6, 'bins' => 24], $q['_count']);
        $this->assertSame(['zones' => 2, 'bins' => 72, 'users' => 0, 'staff' => 0, 'docks_' => 0, 'vehicles' => 0], $detail['counts']);
        $this->assertSame(['active' => 72], $detail['binsByStatus']);

        $list = $this->expectOk($this->getAs('sales', "/api/warehouses?q={$wh}"));
        $this->assertSame(1, $list['total']);
        $this->assertSame([2, 72], [$list['items'][0]['zonesCount'], $list['items'][0]['binsCount']]);
        $this->assertSame(['zones', 'bins', 'users', 'staff'], array_keys($list['items'][0]['_count']));
        $codes = array_column($this->expectOk($this->getAs('sales', '/api/warehouses?active=true'))['items'], 'code');
        $this->assertSame(['DMM', 'JED', 'RYD', $wh], $codes);
        $this->assertSame(2, $this->expectOk($this->getAs('sales', '/api/warehouses?pageSize=2&page=2'))['pages']);

        $zones = $this->expectOk($this->getAs('sales', "/api/warehouses/{$wh}/zones"));
        $this->assertSame(['D', 'Q'], array_column($zones, 'code'));
        $racks = $this->expectOk($this->getAs('sales', "/api/warehouses/{$wh}/zones/q/racks"));
        $this->assertSame('Q', $racks['zone']['code']);
        $this->assertCount(6, $racks['racks']);
        $this->assertSame(['Q-01-1', 'Q-01-2', 'Q-01-3', 'Q-02-1'], array_slice(array_column($racks['racks'], 'code'), 0, 4));
        $this->assertSame(4, $racks['racks'][0]['binsCount']);
        $this->assertSame(['id', 'code', 'shelfNo', 'status', 'type', 'fixedProductId'], array_keys($racks['racks'][0]['bins'][0]));
        $byId = $this->expectOk($this->getAs('sales', "/api/zones/{$racks['zone']['id']}/racks"));
        $this->assertCount(6, $byId['racks']);
        $this->expectRejected($this->getAs('sales', '/api/zones/Q/racks'), 'ZONE_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('sales', "/api/warehouses/{$wh}/zones/ZZ/racks"), 'ZONE_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('sales', '/api/warehouses/XXX'), 'WAREHOUSE_NOT_FOUND', [404]);
    }

    public function test_update_warehouse_and_zone(): void
    {
        $wh = self::WH;
        $this->expectOk($this->patchAs('wm', "/api/warehouses/{$wh}", ['city' => 'الخرج', 'docks' => 3, 'active' => false]));
        $w = Warehouse::where('code', $wh)->firstOrFail();
        $this->assertSame(['الخرج', 3, false], [$w->city, $w->docks, $w->active]);
        $this->assertSame(['inactive'], StatusHistory::where('entity_type', 'Warehouse')->where('entity_id', $w->id)->pluck('to_status')->all());
        $this->assertNotContains($wh, array_column($this->expectOk($this->getAs('wm', '/api/master/lookups'))['warehouses'], 'code'));
        $this->expectOk($this->patchAs('wm', "/api/warehouses/{$wh}", ['active' => true]));
        $this->expectRejected($this->patchAs('wm', "/api/warehouses/{$wh}", ['areaM2' => 0]), 'INVALID_INPUT', [400]);

        // Changing the zone type resets the temperature band; deactivating a zone parks its active bins.
        $this->expectOk($this->patchAs('wm', "/api/warehouses/{$wh}/zones/D", ['type' => 'frozen', 'pickStrategy' => 'fifo']));
        $zone = Zone::where('warehouse_id', $w->id)->where('code', 'D')->firstOrFail();
        $this->assertSame(['frozen', 'fifo', -20.0, -16.0], [$zone->type, $zone->pick_strategy, $zone->min_temp_c, $zone->max_temp_c]);
        $this->expectOk($this->patchAs('wm', "/api/warehouses/{$wh}/zones/{$zone->id}", ['active' => false]));
        $this->assertSame(48, Bin::where('zone_id', $zone->id)->where('status', 'inactive')->count());
        $this->expectRejected($this->patchAs('wm', "/api/warehouses/{$wh}/zones/ZZ", ['active' => false]), 'ZONE_NOT_FOUND', [404]);
        $this->expectRejected($this->patchAs('wm', "/api/warehouses/{$wh}/zones/D", ['pickStrategy' => 'random']), 'INVALID_INPUT', [400]);
    }

    // ───────────────────────────── bins ─────────────────────────────

    public function test_bins_codes_status_and_filters(): void
    {
        $wh = self::WH;
        $dup = $this->expectRejected($this->postAs('wm', '/api/bins', ['warehouseCode' => $wh, 'zone' => 'Q', 'aisle' => 1, 'rack' => 1, 'shelf' => 1]), 'BIN_EXISTS', [409]);
        $this->assertSame('الموقع Q-01-1-B1 موجود مسبقًا', $dup['message']);
        $b = $this->expectOk($this->postAs('wm', '/api/bins', ['warehouseCode' => $wh, 'zone' => 'q', 'aisle' => '1', 'rack' => 1, 'shelf' => 9, 'type' => 'pallet']));
        $this->assertSame('Q-01-1-B9', $b['code']);
        $this->assertSame('Q-01-1', $b['rack']['code']);
        $this->assertSame(9, $b['rack']['levels'], 'the rack grows to hold the new shelf');
        $this->assertSame('pallet', $b['type']);
        $this->assertSame('Q', $b['zone']['code']);
        $this->assertNull($b['fixedProduct']);
        $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/bins", ['zone' => 'Q', 'aisle' => 1, 'rack' => 1, 'shelf' => 9]), 'BIN_EXISTS', [409]);
        $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/bins", ['zone' => 'Z', 'aisle' => 1, 'rack' => 1, 'shelf' => 1]), 'ZONE_NOT_FOUND', [400]);
        $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/bins", ['zone' => 'Q', 'aisle' => 0, 'rack' => 1, 'shelf' => 1]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/bins', ['zone' => 'Q', 'aisle' => 1, 'rack' => 1, 'shelf' => 2]), 'INVALID_INPUT', [400]);

        // A new rack is created on demand; a chilled product fits a chilled zone, a frozen one does not.
        $fixed = $this->expectOk($this->postAs('wm', "/api/warehouses/{$wh}/bins", ['zone' => 'Q', 'aisle' => 7, 'rack' => 2, 'shelf' => 1, 'fixedSku' => 'P01557']));
        $this->assertSame('Q-07-2-B1', $fixed['code']);
        $this->assertSame('P01557', $fixed['fixedProduct']['sku']);
        $this->assertStringContainsString('مخصصًا لـ', $fixed['messageAr']);
        $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/bins", ['zone' => 'Q', 'aisle' => 7, 'rack' => 2, 'shelf' => 2, 'fixedSku' => 'P01568']), 'STORAGE_MISMATCH', [422]);
        $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/bins", ['zone' => 'Q', 'aisle' => 7, 'rack' => 2, 'shelf' => 2, 'fixedSku' => 'NOPE']), 'BAD_PRODUCT', [400]);

        $st = $this->expectOk($this->patchAs('wm', "/api/bins/{$b['id']}/status", ['status' => 'blocked', 'note' => 'اختبار']));
        $this->assertSame('blocked', $st['status']);
        $history = StatusHistory::where('entity_type', 'Bin')->where('entity_id', $b['id'])->get();
        $this->assertSame([['active', 'blocked', 'اختبار']], $history->map(fn ($h) => [$h->from_status, $h->to_status, $h->note])->all());
        $this->expectRejected($this->patchAs('wm', "/api/bins/{$b['id']}/status", ['status' => 'blocked']), 'BIN_STATUS_SAME', [422]);
        $this->expectRejected($this->patchAs('wm', "/api/bins/{$b['id']}/status", ['status' => 'broken']), 'INVALID_INPUT', [400]);
        $this->expectOk($this->patchAs('wm', "/api/warehouses/{$wh}/bins/Q-07-2-B1/status", ['status' => 'full']));
        [$stockWh, $stockBin] = self::binWithStock();
        $this->expectRejected($this->patchAs('wm', "/api/warehouses/{$stockWh}/bins/{$stockBin}/status", ['status' => 'inactive']), 'BIN_NOT_EMPTY', [422]);
        $this->assertSame('active', Bin::where('code', $stockBin)->whereHas('warehouse', fn ($w) => $w->where('code', $stockWh))->value('status'));
        $this->expectRejected($this->patchAs('wm', "/api/warehouses/{$wh}/bins/{$stockBin}/status", ['status' => 'full']), 'BIN_NOT_FOUND', [404]);

        $bins = $this->expectOk($this->getAs('worker', "/api/bins?warehouse={$wh}&zone=Q&status=blocked"));
        $this->assertSame(1, $bins['total']);
        $this->assertSame('Q', $bins['items'][0]['zone']['code']);
        $this->assertSame(['id' => $b['warehouseId'], 'code' => $wh, 'nameAr' => 'مستودع اختبار '.self::n()], $bins['items'][0]['warehouse']);
        $this->assertSame(['balances' => 0], $bins['items'][0]['_count']);
        $scoped = $this->expectOk($this->getAs('worker', "/api/warehouses/{$wh}/bins?rack=Q-01-1&pageSize=3&warehouse=RYD"));
        $this->assertSame(5, $scoped['total'], 'the path warehouse wins over the query string');
        $this->assertSame(['Q-01-1-B1', 'Q-01-1-B2', 'Q-01-1-B3'], array_column($scoped['items'], 'code'));
        $this->assertSame(['Q-07-2-B1'], array_column($this->expectOk($this->getAs('worker', "/api/warehouses/{$wh}/bins?fixed=1"))['items'], 'code'));
        $this->assertSame(['Q-07-2-B1'], array_column($this->expectOk($this->getAs('worker', "/api/warehouses/{$wh}/bins?q=p01557"))['items'], 'code'));
        $this->expectRejected($this->getAs('worker', '/api/bins?status=nope'), 'INVALID_INPUT', [400]);
    }

    public function test_bin_detail_and_update(): void
    {
        $wh = self::WH;
        [$stockWh, $stockBin] = self::binWithStock();
        $stock = $this->expectOk($this->getAs('worker', "/api/warehouses/{$stockWh}/bins/".strtolower($stockBin)));
        $this->assertSame($stockWh, $stock['warehouse']['code']);
        $this->assertNotEmpty($stock['balances']);
        $this->assertSame(['sku', 'nameAr'], array_keys($stock['balances'][0]['product']));
        $this->assertArrayHasKey('onHand', $stock['balances'][0]);

        $bin = $this->expectOk($this->getAs('worker', "/api/warehouses/{$wh}/bins/Q-01-1-B2"));
        $this->expectOk($this->patchAs('wm', "/api/bins/{$bin['id']}", ['type' => 'flow', 'capacityUnits' => 120, 'maxKg' => 250.5, 'fixedSku' => 'P01557']));
        $row = Bin::findOrFail($bin['id']);
        $this->assertSame(['flow', 120, 250.5], [$row->type, $row->capacity_units, $row->max_kg]);
        $this->assertNotNull($row->fixed_product_id);
        $this->expectRejected($this->patchAs('wm', "/api/bins/{$bin['id']}", ['fixedSku' => 'P01568']), 'STORAGE_MISMATCH', [422]);
        $this->expectOk($this->patchAs('wm', "/api/bins/{$bin['id']}", ['fixedSku' => null]));
        $this->assertNull(Bin::findOrFail($bin['id'])->fixed_product_id);
        $this->assertSame(2, AuditLog::where('entity_id', $bin['id'])->where('action', 'BIN.UPDATE')->count());
        $this->expectRejected($this->patchAs('wm', '/api/bins/NOPE-BIN', ['type' => 'flow']), 'BIN_NOT_FOUND', [404]);
        $this->expectRejected($this->patchAs('wm', "/api/bins/{$bin['id']}", ['type' => 'cupboard']), 'INVALID_INPUT', [400]);
    }

    // ───────────────────────────── staff + docks ─────────────────────────────

    public function test_staff_certificate_rules(): void
    {
        $wh = self::WH;
        $forklift = $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/staff", ['who' => 'سالم', 'role' => 'forklift', 'fromDate' => '2026-09-14']), 'STAFF_FORKLIFT_CERT', [422]);
        $this->assertSame('مشغّل الرافعة يحتاج رخصة رافعة مسجلة', $forklift['message']);
        $hz = $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/staff", ['who' => 'سالم', 'role' => 'picker', 'zones' => 'hz', 'fromDate' => '2026-09-14']), 'STAFF_HZ_CERT', [422]);
        $this->assertSame('دخول HZ يحتاج تصريح سلامة كيماويات', $hz['message']);
        $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/staff", ['who' => 'سالم', 'role' => 'pilot', 'fromDate' => '2026-09-14']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/staff", ['who' => 'سالم', 'role' => 'picker', 'fromDate' => '14/09/2026']), 'INVALID_INPUT', [400]);

        $body = ['who' => 'سالم', 'role' => 'forklift', 'zones' => 'hz', 'fromDate' => '2026-09-14', 'cert' => 'رخصة رافعة شوكية + تصريح سلامة كيماويات'];
        $ok = $this->expectOk($this->postAs('wm', "/api/warehouses/{$wh}/staff", $body));
        $this->assertSame(['forklift', 'hz', 'am'], [$ok['role'], $ok['zones'], $ok['shift']]);
        $this->assertStringStartsWith('2026-09-14T00:00:00', $ok['fromDate']);
        $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/staff", $body), 'STAFF_DUPLICATE', [409]);
        $this->expectOk($this->postAs('wm', "/api/warehouses/{$wh}/staff", ['shift' => 'pm'] + $body));

        $list = $this->expectOk($this->getAs('sales', "/api/warehouses/{$wh}/staff"));
        $this->assertCount(2, $list);
        $this->expectRejected($this->deleteAs('wm', "/api/warehouses/RYD/staff/{$ok['id']}"), 'STAFF_NOT_FOUND', [404]);
        $this->expectOk($this->deleteAs('wm', "/api/warehouses/{$wh}/staff/{$ok['id']}"));
        $this->assertCount(1, $this->expectOk($this->getAs('sales', "/api/warehouses/{$wh}/staff")));
        $this->assertSame(1, AuditLog::where('entity_id', $ok['id'])->where('action', 'STAFF.UNASSIGN')->count());
    }

    public function test_dock_appointments_cannot_be_double_booked(): void
    {
        $wh = self::WH;
        $dk = $this->expectOk($this->postAs('wm', "/api/warehouses/{$wh}/docks", ['dock' => 'D1', 'reference' => 'PO-TEST', 'date' => '2026-09-20', 'slot' => '08']));
        $this->assertMatchesRegularExpression('/^DK-\d{3,}$/', $dk['number']);
        $this->assertSame('in', $dk['type']);
        $this->assertStringStartsWith('2026-09-20T00:00:00', $dk['date']);
        $dup = $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/docks", ['dock' => 'D1', 'reference' => 'PO-OTHER', 'date' => '2026-09-20', 'slot' => '08']), 'DOCK_BOOKED', [409]);
        $this->assertSame('الرصيف D1 محجوز في هذه الفترة — اختر فترة أخرى', $dup['message']);
        $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/docks", ['dock' => 'D1', 'reference' => 'PO-X', 'date' => '2026-13-45', 'slot' => '08']), 'DOCK_DATE', [400]);
        $this->expectRejected($this->postAs('wm', "/api/warehouses/{$wh}/docks", ['dock' => 'D1', 'date' => '2026-09-20', 'slot' => '08']), 'INVALID_INPUT', [400]);
        $this->expectOk($this->postAs('wm', "/api/warehouses/{$wh}/docks", ['dock' => 'D2', 'type' => 'out', 'reference' => 'TRP-TEST', 'date' => '2026-09-20', 'slot' => '08']));
        $this->expectOk($this->postAs('wm', "/api/warehouses/{$wh}/docks", ['dock' => 'D1', 'reference' => 'PO-LATER', 'date' => '2026-09-22', 'slot' => '10']));

        $this->assertCount(2, $this->expectOk($this->getAs('sales', "/api/warehouses/{$wh}/docks?date=2026-09-20")));
        $this->assertSame(['D1'], array_column($this->expectOk($this->getAs('sales', "/api/warehouses/{$wh}/docks?date=2026-09-20&dock=D1")), 'dock'));
        $this->assertCount(1, $this->expectOk($this->getAs('sales', "/api/warehouses/{$wh}/docks?from=2026-09-21&to=2026-09-30")));
        $this->assertCount(3, $this->expectOk($this->getAs('sales', "/api/warehouses/{$wh}/docks")));
        $this->expectRejected($this->getAs('sales', "/api/warehouses/{$wh}/docks?date=tomorrow"), 'INVALID_INPUT', [400]);

        $this->expectRejected($this->deleteAs('wm', "/api/warehouses/RYD/docks/{$dk['number']}"), 'DOCK_NOT_FOUND', [404]);
        $this->expectOk($this->deleteAs('wm', "/api/warehouses/{$wh}/docks/{$dk['number']}"));
        $this->assertFalse(DockAppointment::where('id', $dk['id'])->exists());
        $this->expectOk($this->postAs('wm', "/api/warehouses/{$wh}/docks", ['dock' => 'D1', 'reference' => 'PO-AGAIN', 'date' => '2026-09-20', 'slot' => '08']));
    }

    public function test_layout_changes_need_the_permission(): void
    {
        $wh = self::WH;
        $before = [Warehouse::count(), Zone::count(), Bin::count(), DockAppointment::count()];
        $this->expectRejected($this->postAs('sales', '/api/warehouses', ['code' => 'TQB', 'nameAr' => 'مرفوض', 'city' => 'x', 'areaM2' => 10]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('worker', "/api/warehouses/{$wh}/zones", ['code' => 'W', 'nameAr' => 'مرفوض']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('inv', '/api/bins', ['warehouseCode' => $wh, 'zone' => 'Q', 'aisle' => 9, 'rack' => 9, 'shelf' => 9]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->patchAs('sales', "/api/warehouses/{$wh}/bins/Q-01-1-B1/status", ['status' => 'blocked']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('proc', "/api/warehouses/{$wh}/docks", ['dock' => 'D9', 'reference' => 'x', 'date' => '2026-09-20', 'slot' => '08']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('proc', "/api/warehouses/{$wh}/staff", ['who' => 'x', 'role' => 'picker', 'fromDate' => '2026-09-14']), 'FORBIDDEN', [403]);
        $this->assertSame($before, [Warehouse::count(), Zone::count(), Bin::count(), DockAppointment::count()]);
        $this->assertSame('active', Bin::where('code', 'Q-01-1-B1')->value('status'));
    }

    // ───────────────────────────── routes + lookups ─────────────────────────────

    public function test_delivery_routes(): void
    {
        $name = 'مسار '.self::n();
        $r = $this->expectOk($this->postAs('disp', '/api/routes', ['name' => $name, 'warehouseCode' => 'RYD', 'zones' => 'العليا, الملقا، النخيل']));
        $this->assertMatchesRegularExpression('/^RT-\d{2,}$/', $r['code']);
        $this->assertSame(['الأحد – الخميس', '08:00 – 14:00', 'dry'], [$r['days'], $r['window'], $r['tempNeed']]);
        $this->expectRejected($this->postAs('disp', '/api/routes', ['name' => $name, 'warehouseCode' => 'RYD', 'zones' => 'x']), 'ROUTE_NAME_TAKEN', [409]);
        $this->expectRejected($this->postAs('disp', '/api/routes', ['name' => $name.' ب', 'warehouseCode' => 'XXX', 'zones' => 'x']), 'WAREHOUSE_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('disp', '/api/routes', ['name' => $name.' ب']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('disp', '/api/routes', ['name' => $name.' ب', 'zones' => 'x', 'tempNeed' => 'hot']), 'INVALID_INPUT', [400]);

        $got = $this->expectOk($this->getAs('sales', '/api/routes/'.strtolower($r['code'])));
        $this->assertSame(['العليا', 'الملقا', 'النخيل'], $got['zoneList']);
        $this->assertSame('RYD', $got['warehouse']['code']);
        $this->assertSame(['id', 'code', 'nameAr'], array_keys($got['warehouse']));

        $this->expectOk($this->patchAs('disp', "/api/routes/{$r['code']}", ['tempNeed' => 'chill', 'warehouseCode' => '']));
        $row = Route::findOrFail($r['id']);
        $this->assertSame(['chill', null], [$row->temp_need, $row->warehouse_id]);
        $this->assertNull($this->expectOk($this->getAs('sales', "/api/routes/{$r['id']}"))['warehouse']);
        $this->expectOk($this->patchAs('disp', "/api/routes/{$r['code']}", ['warehouseCode' => 'JED']));

        $list = $this->expectOk($this->getAs('sales', '/api/routes?warehouse=JED&tempNeed=chill&q='.self::n()));
        $this->assertSame(1, $list['total']);
        $this->assertSame('JED', $list['items'][0]['warehouse']['code']);
        $this->assertCount(3, $list['items'][0]['zoneList']);
        $all = $this->expectOk($this->getAs('sales', '/api/routes?pageSize=2'));
        $this->assertCount(2, $all['items']);
        $this->assertGreaterThanOrEqual(4, $all['total']);

        $this->expectRejected($this->postAs('wm', '/api/routes', ['name' => $name.' ج', 'zones' => 'x']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->deleteAs('sales', "/api/routes/{$r['code']}"), 'FORBIDDEN', [403]);
        $this->assertTrue(Route::where('id', $r['id'])->exists());
        $this->expectOk($this->deleteAs('disp', "/api/routes/{$r['code']}"));
        $this->expectRejected($this->getAs('sales', "/api/routes/{$r['code']}"), 'ROUTE_NOT_FOUND', [404]);
        $this->assertSame(['ROUTE.CREATE', 'ROUTE.UPDATE', 'ROUTE.UPDATE', 'ROUTE.DELETE'], AuditLog::where('entity_id', $r['id'])->orderBy('at')->orderBy('id')->pluck('action')->all());
    }

    public function test_lookups_payload(): void
    {
        $lk = $this->expectOk($this->getAs('driver', '/api/master/lookups'));
        $this->assertSame(['warehouses', 'categories', 'uoms', 'suppliers', 'customers', 'supplierCategories', 'dockSlots'], array_keys($lk));
        $ryd = collect($lk['warehouses'])->firstWhere('code', 'RYD');
        $this->assertContains('A', array_column($ryd['zones'], 'code'));
        $this->assertArrayNotHasKey('warehouseId', $ryd['zones'][0]);
        $this->assertCount($ryd['docks'], $ryd['dockList']);
        $this->assertSame('D1', $ryd['dockList'][0]);
        $mine = collect($lk['warehouses'])->firstWhere('code', self::WH);
        $this->assertSame(['Q'], array_column($mine['zones'], 'code'), 'inactive zones are left out');
        $this->assertNotEmpty($lk['uoms']);
        $this->assertArrayHasKey('pathAr', $lk['categories'][0]);
        $this->assertIsArray($lk['categories'][0]['children']);
        $this->assertIsBool($lk['suppliers'][0]['blocksPo']);
        $this->assertTrue(is_float($lk['customers'][0]['creditLimit']) || is_int($lk['customers'][0]['creditLimit']), 'customer money is numeric in lookups');
        $this->assertSame(['key' => 'dry', 'ar' => 'أغذية جافة', 'en' => 'Dry food'], $lk['supplierCategories'][0]);
        $this->assertSame(['key' => '06', 'label' => '06:00 – 08:00'], $lk['dockSlots'][0]);
    }
}
