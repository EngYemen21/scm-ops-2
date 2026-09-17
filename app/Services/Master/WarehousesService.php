<?php

namespace App\Services\Master;

use App\Models\Bin;
use App\Models\DockAppointment;
use App\Models\Product;
use App\Models\Rack;
use App\Models\Route;
use App\Models\StaffAssignment;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Warehouses and their layout (zones → racks → bins), staff assignments, dock appointments and delivery routes.
 * Payload arrays are camelCase and validated by the controller; a present key with a null value means "sent empty".
 */
class WarehousesService
{
    private const BIN_WITH = [
        'zone:id,code,name_ar,name_en,type,pick_strategy', 'rack:id,code,aisle,rack_no,levels', 'fixedProduct:id,sku,name_ar,name_en',
    ];

    private const WAREHOUSE_BRIEF = 'warehouse:id,code,name_ar';

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
    ) {}

    /** Rack code `<Z>-<AA>-<R>` (e.g. A-01-3). */
    public static function rackCode(string $zone, int $aisle, int $rack): string
    {
        return mb_strtoupper($zone).'-'.str_pad((string) $aisle, 2, '0', STR_PAD_LEFT).'-'.$rack;
    }

    /** Bin code `<Z>-<AA>-<R>-B<n>` (e.g. A-01-3-B2). */
    public static function binCode(string $zone, int $aisle, int $rack, int $shelf): string
    {
        return self::rackCode($zone, $aisle, $rack).'-B'.$shelf;
    }

    // ───────────────────────────── warehouses ─────────────────────────────

    /** @param  array{active?:?string, city?:?string, type?:?string}  $filters */
    public function list(Paging $page, array $filters): array
    {
        $relations = ['zones', 'bins', 'users', 'staff'];
        $query = Warehouse::query()->withCount($relations)->orderBy('code');
        $active = PartnersService::flag($filters['active'] ?? null);
        if ($active !== null) {
            $query->where('active', $active);
        }
        if (! empty($filters['city'])) {
            $query->where('city', $filters['city']);
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if ($page->q) {
            $q = $page->q;
            $query->where(function ($w) use ($q) {
                foreach (['code', 'name_ar', 'name_en', 'city'] as $column) {
                    $w->orWhere($column, 'like', "%{$q}%");
                }
            });
        }

        return $page->paginate($query, function (Warehouse $w) use ($relations) {
            $row = Shape::withCounts($w, $relations);

            return $row + ['zonesCount' => $row['_count']['zones'], 'binsCount' => $row['_count']['bins']];
        });
    }

    public function findWarehouse(string $idOrCode): Warehouse
    {
        return Warehouse::where('id', $idOrCode)->orWhere('code', mb_strtoupper($idOrCode))->first()
            ?? throw AppError::notFound('WAREHOUSE_NOT_FOUND', 'المستودع غير موجود', 'Warehouse not found');
    }

    public function get(string $idOrCode): array
    {
        $w = $this->findWarehouse($idOrCode);
        $counts = [];
        foreach (['zones', 'bins', 'users', 'staff', 'docks_', 'vehicles'] as $relation) {
            $counts[$relation] = $w->{$relation}()->count();
        }
        $binsByStatus = Bin::where('warehouse_id', $w->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        return $w->toArray() + ['zones' => $this->zonesOf($w), 'counts' => $counts, 'binsByStatus' => (object) $binsByStatus];
    }

    /** Warehouse code: 3 uppercase Latin letters, unique. */
    public function create(AuthUser $actor, array $dto): array
    {
        $code = mb_strtoupper(trim((string) ($dto['code'] ?? '')));
        if (! preg_match('/^[A-Z]{3}$/', $code)) {
            throw AppError::validation('WAREHOUSE_CODE', 'الرمز 3 أحرف لاتينية', 'Code must be 3 Latin letters');
        }
        if (Warehouse::where('code', $code)->exists()) {
            throw AppError::conflict('WAREHOUSE_CODE_TAKEN', 'الرمز مستخدم', "Warehouse code {$code} already exists");
        }
        if (! ((float) ($dto['areaM2'] ?? 0) > 0)) {
            throw AppError::validation('WAREHOUSE_AREA', 'المساحة يجب أن تكون أكبر من صفر', 'Area must be greater than zero');
        }
        $w = DB::transaction(function () use ($actor, $dto, $code) {
            $r = Warehouse::create([
                'code' => $code, 'name_ar' => $dto['nameAr'], 'name_en' => ($dto['nameEn'] ?? null) ?: $dto['nameAr'], 'city' => $dto['city'], 'type' => ($dto['type'] ?? null) ?: 'dc',
                'area_m2' => $dto['areaM2'], 'docks' => $dto['docks'] ?? 4, 'temp_zones' => ($dto['tempZones'] ?? null) ?: 'all', 'hours' => ($dto['hours'] ?? null) ?: '06:00 – 22:00',
                'open_date' => empty($dto['openDate']) ? null : self::day($dto['openDate'], 'WAREHOUSE_DATE'),
            ]);
            $this->audit->log($actor, ['action' => 'WAREHOUSE.CREATE', 'entityType' => 'Warehouse', 'entityId' => $r->id, 'entityNumber' => $code, 'newValue' => ['code' => $code] + $dto]);

            return $r->refresh();
        });

        return $w->toArray() + [
            'messageAr' => "أُنشئ مستودع {$w->name_ar} ({$code}) — أضف مناطقه ومواقعه ثم عيّن الفريق",
            'messageEn' => "Warehouse {$code} created — add zones, bins and staff",
        ];
    }

    public function update(AuthUser $actor, string $idOrCode, array $dto): array
    {
        $w = $this->findWarehouse($idOrCode);
        $data = [];
        foreach (['nameAr', 'city', 'type', 'areaM2', 'docks', 'tempZones', 'hours', 'active'] as $k) {
            if (isset($dto[$k]) || ($k === 'hours' && array_key_exists($k, $dto))) {
                $data[$k] = $dto[$k];
            }
        }
        if (array_key_exists('nameEn', $dto)) {
            $data['nameEn'] = $dto['nameEn'] ?: (($dto['nameAr'] ?? null) ?: $w->name_ar);
        }
        if (array_key_exists('openDate', $dto)) {
            $data['openDate'] = $dto['openDate'] ? self::day($dto['openDate'], 'WAREHOUSE_DATE') : null;
        }
        if (isset($dto['areaM2']) && ! ((float) $dto['areaM2'] > 0)) {
            throw AppError::validation('WAREHOUSE_AREA', 'المساحة يجب أن تكون أكبر من صفر', 'Area must be greater than zero');
        }
        DB::transaction(function () use ($actor, $w, $dto, $data) {
            $old = ['nameAr' => $w->name_ar, 'nameEn' => $w->name_en, 'city' => $w->city, 'type' => $w->type, 'areaM2' => $w->area_m2, 'docks' => $w->docks, 'tempZones' => $w->temp_zones, 'hours' => $w->hours, 'active' => $w->active];
            $w->update(Shape::snake($data));
            $this->audit->log($actor, ['action' => 'WAREHOUSE.UPDATE', 'entityType' => 'Warehouse', 'entityId' => $w->id, 'entityNumber' => $w->code, 'oldValue' => $old, 'newValue' => $data]);
            if (isset($dto['active']) && (bool) $dto['active'] !== (bool) $old['active']) {
                $this->audit->status($actor, 'Warehouse', $w->id, $w->code, $old['active'] ? 'active' : 'inactive', $dto['active'] ? 'active' : 'inactive');
            }
        });

        return ['ok' => true];
    }

    // ───────────────────────────── zones ─────────────────────────────

    public function zones(string $idOrCode): array
    {
        return $this->zonesOf($this->findWarehouse($idOrCode));
    }

    /** @return array{0: Warehouse, 1: Zone} */
    public function findZone(string $warehouseIdOrCode, string $zoneIdOrCode): array
    {
        $w = $this->findWarehouse($warehouseIdOrCode);
        $z = Zone::where('warehouse_id', $w->id)->where(fn ($q) => $q->where('id', $zoneIdOrCode)->orWhere('code', mb_strtoupper($zoneIdOrCode)))->first()
            ?? throw AppError::notFound('ZONE_NOT_FOUND', 'المنطقة غير موجودة في هذا المستودع', 'Zone not found in warehouse');

        return [$w, $z];
    }

    /** Creates the zone and generates its racks + bins (`aisles × racks × bins`) in one transaction. */
    public function createZone(AuthUser $actor, string $warehouseIdOrCode, array $dto): array
    {
        $w = $this->findWarehouse($warehouseIdOrCode);
        $code = mb_strtoupper(trim((string) ($dto['code'] ?? '')));
        if (! preg_match('/^[A-Z]{1,2}$/', $code)) {
            throw AppError::validation('ZONE_CODE', 'رمز المنطقة حرف أو حرفان', 'Zone code must be 1–2 letters');
        }
        if (Zone::where('warehouse_id', $w->id)->where('code', $code)->exists()) {
            throw AppError::conflict('ZONE_CODE_TAKEN', "الرمز {$code} مستخدم في هذا المستودع", "Zone {$code} already exists in {$w->code}");
        }
        $aisles = (int) ($dto['aisles'] ?? 4);
        $racks = (int) ($dto['racks'] ?? 3);
        $bins = (int) ($dto['bins'] ?? 4);
        if (min($aisles, $racks, $bins) < 1) {
            throw AppError::validation('ZONE_LAYOUT', 'عدد الممرات والرفوف والمواقع يجب أن يكون 1 على الأقل', 'Aisles, racks and bins must be ≥ 1');
        }
        if ($aisles * $racks * $bins > 20000) {
            throw AppError::validation('ZONE_TOO_LARGE', 'عدد المواقع المولّدة يتجاوز الحد (20,000)', 'Generated bin count exceeds 20,000');
        }
        $type = self::zoneType($dto['type'] ?? null);
        $temps = self::defaultTemps($type);
        $capacity = $dto['capacityUnits'] ?? 400;
        $maxKg = $dto['maxKg'] ?? 800;

        $result = DB::transaction(function () use ($actor, $w, $dto, $code, $aisles, $racks, $bins, $type, $temps, $capacity, $maxKg) {
            $zone = Zone::create([
                'warehouse_id' => $w->id, 'code' => $code, 'name_ar' => $dto['nameAr'], 'name_en' => ($dto['nameEn'] ?? null) ?: $dto['nameAr'], 'type' => $type,
                'pick_strategy' => ($dto['pickStrategy'] ?? null) ?: 'fefo', 'min_temp_c' => $dto['minTempC'] ?? $temps['minTempC'], 'max_temp_c' => $dto['maxTempC'] ?? $temps['maxTempC'],
                'capacity_units' => $capacity, 'max_kg' => $maxKg,
            ]);
            $rackRows = [];
            $binRows = [];
            for ($a = 1; $a <= $aisles; $a++) {
                for ($r = 1; $r <= $racks; $r++) {
                    $rackId = strtolower((string) Str::ulid());
                    $rackRows[] = ['id' => $rackId, 'zone_id' => $zone->id, 'aisle' => $a, 'rack_no' => $r, 'code' => self::rackCode($code, $a, $r), 'levels' => $bins];
                    for ($b = 1; $b <= $bins; $b++) {
                        $binRows[] = ['id' => strtolower((string) Str::ulid()), 'warehouse_id' => $w->id, 'zone_id' => $zone->id, 'rack_id' => $rackId, 'code' => self::binCode($code, $a, $r, $b),
                            'shelf_no' => $b, 'type' => 'shelf', 'capacity_units' => $capacity, 'max_kg' => $maxKg];
                    }
                }
            }
            foreach (array_chunk($rackRows, 500) as $chunk) {
                Rack::insert($chunk);
            }
            foreach (array_chunk($binRows, 500) as $chunk) {
                Bin::insert($chunk);
            }
            $this->audit->log($actor, ['action' => 'ZONE.CREATE', 'entityType' => 'Zone', 'entityId' => $zone->id, 'entityNumber' => "{$w->code}/{$code}",
                'newValue' => ['code' => $code, 'type' => $type, 'racks' => $aisles * $racks, 'bins' => count($binRows)] + $dto]);

            return ['zone' => $zone->refresh()->toArray(), 'racksCount' => $aisles * $racks, 'binsCount' => count($binRows), 'firstBin' => $binRows[0]['code'], 'lastBin' => $binRows[count($binRows) - 1]['code']];
        });

        return $result + [
            'messageAr' => "أُنشئت المنطقة {$code} بـ {$result['binsCount']} موقعًا مولّدًا تلقائيًا ({$result['firstBin']} … {$result['lastBin']}) — ملصقات الباركود جاهزة للطباعة",
            'messageEn' => "Zone {$code} created with {$result['binsCount']} bins ({$result['firstBin']} … {$result['lastBin']})",
        ];
    }

    public function updateZone(AuthUser $actor, string $warehouseIdOrCode, string $zoneIdOrCode, array $dto): array
    {
        [$w, $z] = $this->findZone($warehouseIdOrCode, $zoneIdOrCode);
        $data = [];
        foreach (['nameAr', 'pickStrategy', 'capacityUnits', 'maxKg', 'active'] as $k) {
            if (isset($dto[$k])) {
                $data[$k] = $dto[$k];
            }
        }
        foreach (['minTempC', 'maxTempC'] as $k) { // nullable: an explicit null clears the limit
            if (array_key_exists($k, $dto)) {
                $data[$k] = $dto[$k];
            }
        }
        if (array_key_exists('nameEn', $dto)) {
            $data['nameEn'] = $dto['nameEn'] ?: (($dto['nameAr'] ?? null) ?: $z->name_ar);
        }
        if (isset($dto['type'])) {
            $type = self::zoneType($dto['type']);
            $data['type'] = $type;
            if ($type !== $z->type && ! array_key_exists('minTempC', $dto) && ! array_key_exists('maxTempC', $dto)) {
                $data = array_merge($data, self::defaultTemps($type));
            }
        }
        DB::transaction(function () use ($actor, $w, $z, $dto, $data) {
            $old = ['nameAr' => $z->name_ar, 'type' => $z->type, 'pickStrategy' => $z->pick_strategy, 'capacityUnits' => $z->capacity_units, 'maxKg' => $z->max_kg, 'minTempC' => $z->min_temp_c, 'maxTempC' => $z->max_temp_c, 'active' => $z->active];
            $z->update(Shape::snake($data));
            if (($dto['active'] ?? null) === false) {
                Bin::where('zone_id', $z->id)->where('status', 'active')->update(['status' => 'inactive']);
            }
            $this->audit->log($actor, ['action' => 'ZONE.UPDATE', 'entityType' => 'Zone', 'entityId' => $z->id, 'entityNumber' => "{$w->code}/{$z->code}", 'oldValue' => $old, 'newValue' => $data]);
        });

        return ['ok' => true];
    }

    /** Racks (with their bins) of a zone: by zone id alone, or by zone id/code inside a warehouse. */
    public function racks(string $zoneId, ?string $warehouseIdOrCode = null): array
    {
        $z = $warehouseIdOrCode !== null
            ? $this->findZone($warehouseIdOrCode, $zoneId)[1]
            : (Zone::find($zoneId) ?? throw AppError::notFound('ZONE_NOT_FOUND', 'المنطقة غير موجودة', 'Zone not found'));
        $racks = Rack::where('zone_id', $z->id)->withCount('bins')
            ->with(['bins' => fn ($q) => $q->select(['id', 'rack_id', 'code', 'shelf_no', 'status', 'type', 'fixed_product_id'])->orderBy('shelf_no')->orderBy('id')])
            ->orderBy('aisle')->orderBy('rack_no')->orderBy('id')->get();

        return [
            'zone' => $z->toArray(),
            'racks' => $racks->map(function (Rack $r) {
                $r->bins->each->makeHidden('rack_id');
                $row = Shape::withCounts($r, ['bins']);

                return $row + ['binsCount' => $row['_count']['bins']];
            })->values()->all(),
        ];
    }

    // ───────────────────────────── bins ─────────────────────────────

    /** @param  array{warehouse?:?string, zone?:?string, status?:?string, type?:?string, rack?:?string, fixed?:?string}  $filters */
    public function listBins(Paging $page, array $filters): array
    {
        $query = Bin::query()->with([...self::BIN_WITH, self::WAREHOUSE_BRIEF])->withCount('balances')->orderBy('warehouse_id')->orderBy('code')->orderBy('id');
        foreach (['warehouse' => 'warehouse', 'zone' => 'zone', 'rack' => 'rack'] as $param => $relation) {
            if (! empty($filters[$param])) {
                $value = $filters[$param];
                $query->whereHas($relation, fn ($r) => $r->where(fn ($w) => $w->where('code', mb_strtoupper($value))->orWhere('id', $value)));
            }
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (PartnersService::flag($filters['fixed'] ?? null) === true) {
            $query->whereNotNull('fixed_product_id');
        }
        if ($page->q) {
            $q = $page->q;
            $query->where(fn ($w) => $w->where('code', 'like', '%'.mb_strtoupper($q).'%')
                ->orWhereHas('fixedProduct', fn ($p) => $p->where(fn ($x) => $x->where('sku', 'like', "%{$q}%")->orWhere('name_ar', 'like', "%{$q}%"))));
        }

        return $page->paginate($query, fn (Bin $b) => Shape::withCounts($b, ['balances']));
    }

    public function getBin(string $idOrCode, ?string $warehouseIdOrCode = null): array
    {
        return $this->findBin($idOrCode, $warehouseIdOrCode)->toArray();
    }

    /** Single bin: code `<ZONE>-<AA>-<R>-B<n>`, unique per warehouse; the rack row is created on demand. */
    public function createBin(AuthUser $actor, array $dto): array
    {
        $w = $this->findWarehouse($dto['warehouseCode']);
        $zoneCode = mb_strtoupper(trim((string) ($dto['zone'] ?? '')));
        $z = Zone::where('warehouse_id', $w->id)->where('code', $zoneCode)->first()
            ?? throw AppError::validation('ZONE_NOT_FOUND', "المنطقة {$zoneCode} غير موجودة في {$w->code} — أنشئ المنطقة أولًا", "Zone {$zoneCode} not found in {$w->code}");
        [$aisle, $rack, $shelf] = [(int) $dto['aisle'], (int) $dto['rack'], (int) $dto['shelf']];
        if (min($aisle, $rack, $shelf) < 1) {
            throw AppError::validation('BIN_CODE', 'الممر والرف والرقم أعداد صحيحة موجبة', 'Aisle, rack and shelf must be positive integers');
        }
        $code = self::binCode($zoneCode, $aisle, $rack, $shelf);
        if (Bin::where('warehouse_id', $w->id)->where('code', $code)->exists()) {
            throw AppError::conflict('BIN_EXISTS', "الموقع {$code} موجود مسبقًا", "Bin {$code} already exists");
        }
        $fixed = null;
        if (! empty($dto['fixedSku'])) {
            $fixed = $this->fixedProduct($dto['fixedSku']);
            $this->checkStorageFit($fixed->storage_class, $z->type, $code);
        }
        $bin = DB::transaction(function () use ($actor, $w, $z, $dto, $zoneCode, $aisle, $rack, $shelf, $code, $fixed) {
            $rc = self::rackCode($zoneCode, $aisle, $rack);
            $rackRow = Rack::where('zone_id', $z->id)->where('code', $rc)->first()
                ?? Rack::create(['zone_id' => $z->id, 'aisle' => $aisle, 'rack_no' => $rack, 'code' => $rc, 'levels' => $shelf]);
            if ($rackRow->levels < $shelf) {
                $rackRow->update(['levels' => $shelf]);
            }
            $r = Bin::create([
                'warehouse_id' => $w->id, 'zone_id' => $z->id, 'rack_id' => $rackRow->id, 'code' => $code, 'shelf_no' => $shelf, 'type' => ($dto['type'] ?? null) ?: 'shelf',
                'capacity_units' => $dto['capacityUnits'] ?? 400, 'max_kg' => $dto['maxKg'] ?? 800, 'fixed_product_id' => $fixed?->id,
            ]);
            $this->audit->log($actor, ['action' => 'BIN.CREATE', 'entityType' => 'Bin', 'entityId' => $r->id, 'entityNumber' => "{$w->code}/{$code}", 'newValue' => ['code' => $code, 'fixed' => $fixed?->id] + $dto]);

            return $r->refresh()->load(self::BIN_WITH);
        });

        return $bin->toArray() + ['messageAr' => "أُنشئ الموقع {$code}".($fixed ? ' مخصصًا لـ '.$fixed->name_ar : '').' — طُبع ملصق الباركود', 'messageEn' => "Bin {$code} created"];
    }

    public function updateBin(AuthUser $actor, string $idOrCode, array $dto, ?string $warehouseIdOrCode = null): array
    {
        $b = $this->findBin($idOrCode, $warehouseIdOrCode);
        $data = [];
        foreach (['type', 'capacityUnits', 'maxKg'] as $k) {
            if (isset($dto[$k])) {
                $data[$k] = $dto[$k];
            }
        }
        if (array_key_exists('fixedSku', $dto)) {
            if (! $dto['fixedSku']) {
                $data['fixedProductId'] = null;
            } else {
                $p = $this->fixedProduct($dto['fixedSku']);
                $this->checkStorageFit($p->storage_class, $b->zone->type, $b->code);
                $data['fixedProductId'] = $p->id;
            }
        }
        DB::transaction(function () use ($actor, $b, $data) {
            $old = ['type' => $b->type, 'capacityUnits' => $b->capacity_units, 'maxKg' => $b->max_kg, 'fixedProductId' => $b->fixed_product_id];
            if ($data) {
                Bin::where('id', $b->id)->update(Shape::snake($data));
            }
            $this->audit->log($actor, ['action' => 'BIN.UPDATE', 'entityType' => 'Bin', 'entityId' => $b->id, 'entityNumber' => "{$b->warehouse->code}/{$b->code}", 'oldValue' => $old, 'newValue' => $data]);
        });

        return ['ok' => true];
    }

    /** active | blocked | full | inactive — recorded as a status transition. Blocked/inactive bins are skipped by allocation. */
    public function setBinStatus(AuthUser $actor, string $idOrCode, string $status, ?string $note = null, ?string $warehouseIdOrCode = null): array
    {
        $b = $this->findBin($idOrCode, $warehouseIdOrCode);
        if ($b->status === $status) {
            throw AppError::rule('BIN_STATUS_SAME', "الموقع {$b->code} بالحالة «{$status}» بالفعل", "Bin already {$status}");
        }
        if ($status === 'inactive' && $b->balances->contains(fn ($x) => $x->on_hand > 0)) {
            throw AppError::rule('BIN_NOT_EMPTY', "الموقع {$b->code} يحتوي مخزونًا — انقل المخزون قبل إيقافه", 'Bin still holds stock');
        }
        DB::transaction(function () use ($actor, $b, $status, $note) {
            Bin::where('id', $b->id)->update(['status' => $status]);
            $this->audit->status($actor, 'Bin', $b->id, "{$b->warehouse->code}/{$b->code}", $b->status, $status, $note ?: null);
        });

        return ['ok' => true, 'status' => $status, 'messageAr' => "حالة الموقع {$b->code}: {$status}"];
    }

    // ───────────────────────────── staff assignments ─────────────────────────────

    public function staff(string $idOrCode): array
    {
        $w = $this->findWarehouse($idOrCode);

        return StaffAssignment::where('warehouse_id', $w->id)->orderByDesc('created_at')->orderByDesc('id')->get()->all();
    }

    /** Forklift role needs a forklift licence; HZ zone access needs a chemical-safety permit. */
    public function assignStaff(AuthUser $actor, string $idOrCode, array $dto): array
    {
        $w = $this->findWarehouse($idOrCode);
        $cert = (string) ($dto['cert'] ?? '');
        $zones = ($dto['zones'] ?? null) ?: 'all';
        $shift = ($dto['shift'] ?? null) ?: 'am';
        if ($dto['role'] === 'forklift' && ! preg_match('/رافعة|forklift/iu', $cert)) {
            throw AppError::rule('STAFF_FORKLIFT_CERT', 'مشغّل الرافعة يحتاج رخصة رافعة مسجلة', 'Forklift operator needs a registered forklift licence');
        }
        if ($zones === 'hz' && ! preg_match('/سلامة|HZ|كيماو/iu', $cert)) {
            throw AppError::rule('STAFF_HZ_CERT', 'دخول HZ يحتاج تصريح سلامة كيماويات', 'HZ access needs a chemical-safety permit');
        }
        if (StaffAssignment::where('warehouse_id', $w->id)->where('who', $dto['who'])->where('role', $dto['role'])->where('shift', $shift)->exists()) {
            throw AppError::conflict('STAFF_DUPLICATE', "{$dto['who']} معيّن بالفعل في {$w->code} بدور {$dto['role']} لهذه الوردية", 'Assignment already exists');
        }
        $fromDate = self::day($dto['fromDate'], 'STAFF_DATE');
        $s = DB::transaction(function () use ($actor, $w, $dto, $zones, $shift, $fromDate) {
            $r = StaffAssignment::create(['warehouse_id' => $w->id, 'who' => $dto['who'], 'role' => $dto['role'], 'zones' => $zones, 'shift' => $shift, 'from_date' => $fromDate, 'cert' => ($dto['cert'] ?? null) ?: null]);
            $this->audit->log($actor, ['action' => 'STAFF.ASSIGN', 'entityType' => 'StaffAssignment', 'entityId' => $r->id, 'entityNumber' => "{$w->code}/{$dto['who']}", 'newValue' => $dto]);

            return $r->refresh();
        });

        return $s->toArray() + [
            'messageAr' => "عُيّن {$dto['who']} في {$w->code} بدور {$dto['role']} — مهام الـ Scan تُوجَّه له حسب مناطقه وورديته",
            'messageEn' => "{$dto['who']} assigned to {$w->code} as {$dto['role']}",
        ];
    }

    public function removeStaff(AuthUser $actor, string $idOrCode, string $id): array
    {
        $w = $this->findWarehouse($idOrCode);
        $s = StaffAssignment::where('id', $id)->where('warehouse_id', $w->id)->first() ?? throw AppError::notFound('STAFF_NOT_FOUND', 'التعيين غير موجود', 'Assignment not found');
        DB::transaction(function () use ($actor, $w, $s) {
            $s->delete();
            $this->audit->log($actor, ['action' => 'STAFF.UNASSIGN', 'entityType' => 'StaffAssignment', 'entityId' => $s->id, 'entityNumber' => "{$w->code}/{$s->who}",
                'oldValue' => ['who' => $s->who, 'role' => $s->role, 'zones' => $s->zones, 'shift' => $s->shift]]);
        });

        return ['ok' => true];
    }

    // ───────────────────────────── dock appointments ─────────────────────────────

    /** @param  array{date?:?string, from?:?string, to?:?string, dock?:?string}  $filters */
    public function docks(string $idOrCode, array $filters): array
    {
        $w = $this->findWarehouse($idOrCode);
        $query = DockAppointment::where('warehouse_id', $w->id);
        if (! empty($filters['date'])) {
            $query->where('date', self::day($filters['date'], 'DOCK_DATE'));
        } else {
            if (! empty($filters['from'])) {
                $query->where('date', '>=', self::day($filters['from'], 'DOCK_DATE'));
            }
            if (! empty($filters['to'])) {
                $query->where('date', '<=', self::day($filters['to'], 'DOCK_DATE'));
            }
        }
        if (! empty($filters['dock'])) {
            $query->where('dock', $filters['dock']);
        }

        return $query->orderBy('date')->orderBy('slot')->orderBy('dock')->orderBy('id')->get()->all();
    }

    /** No double booking of the same (warehouse, dock, date, slot) — enforced by a check and by the unique index. */
    public function bookDock(AuthUser $actor, string $idOrCode, array $dto): array
    {
        $w = $this->findWarehouse($idOrCode);
        $date = self::day($dto['date'], 'DOCK_DATE');
        $booked = fn () => AppError::conflict('DOCK_BOOKED', "الرصيف {$dto['dock']} محجوز في هذه الفترة — اختر فترة أخرى", "Dock {$dto['dock']} already booked for that slot");
        if (DockAppointment::where('warehouse_id', $w->id)->where('dock', $dto['dock'])->where('date', $date)->where('slot', $dto['slot'])->exists()) {
            throw $booked();
        }
        try {
            $d = DB::transaction(function () use ($actor, $w, $dto, $date) {
                $number = $this->numbering->next('DK');
                $r = DockAppointment::create(['number' => $number, 'warehouse_id' => $w->id, 'dock' => $dto['dock'], 'type' => ($dto['type'] ?? null) ?: 'in', 'reference' => $dto['reference'],
                    'date' => $date, 'slot' => $dto['slot'], 'carrier' => ($dto['carrier'] ?? null) ?: null]);
                $this->audit->log($actor, ['action' => 'DOCK.BOOK', 'entityType' => 'DockAppointment', 'entityId' => $r->id, 'entityNumber' => $number, 'newValue' => $dto + ['warehouse' => $w->code]]);

                return $r->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw $booked();
        }
        $day = $dto['date'];

        return $d->toArray() + [
            'messageAr' => "حُجز {$dto['dock']} يوم {$day} للمرجع {$dto['reference']} — أُشعر المورد/السائق بالموعد",
            'messageEn' => "Dock {$dto['dock']} booked on {$day} for {$dto['reference']}",
        ];
    }

    public function cancelDock(AuthUser $actor, string $idOrCode, string $idOrNumber): array
    {
        $w = $this->findWarehouse($idOrCode);
        $d = DockAppointment::where('warehouse_id', $w->id)->where(fn ($q) => $q->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('DOCK_NOT_FOUND', 'الحجز غير موجود', 'Appointment not found');
        DB::transaction(function () use ($actor, $d) {
            $d->delete();
            $this->audit->log($actor, ['action' => 'DOCK.CANCEL', 'entityType' => 'DockAppointment', 'entityId' => $d->id, 'entityNumber' => $d->number,
                'oldValue' => ['dock' => $d->dock, 'date' => $d->date?->toJSON(), 'slot' => $d->slot, 'reference' => $d->reference]]);
        });

        return ['ok' => true];
    }

    // ───────────────────────────── routes ─────────────────────────────

    /** @param  array{warehouse?:?string, tempNeed?:?string}  $filters */
    public function listRoutes(Paging $page, array $filters): array
    {
        $query = Route::query()->orderBy('code')->orderBy('id');
        if (! empty($filters['warehouse'])) {
            $query->where('warehouse_id', $this->findWarehouse($filters['warehouse'])->id);
        }
        if (! empty($filters['tempNeed'])) {
            $query->where('temp_need', $filters['tempNeed']);
        }
        if ($page->q) {
            $q = $page->q;
            $query->where(fn ($w) => $w->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")->orWhere('zones', 'like', "%{$q}%"));
        }
        $warehouses = Warehouse::get(['id', 'code', 'name_ar'])->keyBy('id');

        return $page->paginate($query, fn (Route $r) => $r->toArray() + [
            'warehouse' => $r->warehouse_id ? $warehouses->get($r->warehouse_id)?->toArray() : null,
            'zoneList' => self::zoneList($r->zones),
        ]);
    }

    public function getRoute(string $idOrCode): array
    {
        $r = $this->findRoute($idOrCode);
        $warehouse = $r->warehouse_id ? Warehouse::where('id', $r->warehouse_id)->first(['id', 'code', 'name_ar']) : null;

        return $r->toArray() + ['warehouse' => $warehouse?->toArray(), 'zoneList' => self::zoneList($r->zones)];
    }

    public function createRoute(AuthUser $actor, array $dto): array
    {
        $w = ! empty($dto['warehouseCode']) ? $this->findWarehouse($dto['warehouseCode']) : null;
        if (Route::where('name', $dto['name'])->where('warehouse_id', $w?->id)->exists()) {
            throw AppError::conflict('ROUTE_NAME_TAKEN', "المسار «{$dto['name']}» موجود مسبقًا", 'Route name already exists');
        }
        $r = DB::transaction(function () use ($actor, $dto, $w) {
            $code = $this->numbering->next('RT');
            $row = Route::create(['code' => $code, 'name' => $dto['name'], 'warehouse_id' => $w?->id, 'zones' => $dto['zones'], 'days' => ($dto['days'] ?? null) ?: 'الأحد – الخميس',
                'window' => ($dto['window'] ?? null) ?: '08:00 – 14:00', 'temp_need' => ($dto['tempNeed'] ?? null) ?: 'dry']);
            $this->audit->log($actor, ['action' => 'ROUTE.CREATE', 'entityType' => 'Route', 'entityId' => $row->id, 'entityNumber' => $code, 'newValue' => $dto + ['warehouse' => $w?->code]]);

            return $row->refresh();
        });

        return $r->toArray() + ['messageAr' => "أُنشئ المسار «{$dto['name']}» — متاح عند إنشاء الرحلات", 'messageEn' => "Route \"{$dto['name']}\" created"];
    }

    public function updateRoute(AuthUser $actor, string $idOrCode, array $dto): array
    {
        $r = $this->findRoute($idOrCode);
        $data = [];
        foreach (['name', 'zones', 'days', 'window', 'tempNeed'] as $k) {
            if (isset($dto[$k])) {
                $data[$k] = $dto[$k];
            }
        }
        if (array_key_exists('warehouseCode', $dto)) {
            $data['warehouseId'] = $dto['warehouseCode'] ? $this->findWarehouse($dto['warehouseCode'])->id : null;
        }
        DB::transaction(function () use ($actor, $r, $data) {
            $old = ['name' => $r->name, 'zones' => $r->zones, 'days' => $r->days, 'window' => $r->window, 'tempNeed' => $r->temp_need, 'warehouseId' => $r->warehouse_id];
            $r->update(Shape::snake($data));
            $this->audit->log($actor, ['action' => 'ROUTE.UPDATE', 'entityType' => 'Route', 'entityId' => $r->id, 'entityNumber' => $r->code, 'oldValue' => $old, 'newValue' => $data]);
        });

        return ['ok' => true];
    }

    public function deleteRoute(AuthUser $actor, string $idOrCode): array
    {
        $r = $this->findRoute($idOrCode);
        DB::transaction(function () use ($actor, $r) {
            $r->delete();
            $this->audit->log($actor, ['action' => 'ROUTE.DELETE', 'entityType' => 'Route', 'entityId' => $r->id, 'entityNumber' => $r->code, 'oldValue' => ['name' => $r->name, 'zones' => $r->zones]]);
        });

        return ['ok' => true];
    }

    // ───────────────────────────── helpers ─────────────────────────────

    private function zonesOf(Warehouse $w): array
    {
        return Zone::where('warehouse_id', $w->id)->withCount(['racks', 'bins'])->orderBy('code')->get()->map(function (Zone $z) {
            $row = Shape::withCounts($z, ['racks', 'bins']);

            return $row + ['racksCount' => $row['_count']['racks'], 'binsCount' => $row['_count']['bins']];
        })->values()->all();
    }

    private function findBin(string $idOrCode, ?string $warehouseIdOrCode = null): Bin
    {
        $query = Bin::query()->with([...self::BIN_WITH, self::WAREHOUSE_BRIEF, 'balances.product:id,sku,name_ar', 'balances.batch:id,batch_no,expiry_date']);
        if ($warehouseIdOrCode !== null) {
            $query->where('warehouse_id', $this->findWarehouse($warehouseIdOrCode)->id);
        }
        $bin = $query->where(fn ($w) => $w->where('id', $idOrCode)->orWhere('code', mb_strtoupper($idOrCode)))->orderBy('warehouse_id')->first()
            ?? throw AppError::notFound('BIN_NOT_FOUND', 'الموقع غير موجود', 'Bin not found');
        foreach ($bin->balances as $balance) {
            $balance->product?->makeHidden('id');
            $balance->batch?->makeHidden('id');
        }

        return $bin;
    }

    private function findRoute(string $idOrCode): Route
    {
        return Route::where('id', $idOrCode)->orWhere('code', mb_strtoupper($idOrCode))->first()
            ?? throw AppError::notFound('ROUTE_NOT_FOUND', 'المسار غير موجود', 'Route not found');
    }

    private function fixedProduct(string $skuOrId): Product
    {
        return Product::where('sku', $skuOrId)->orWhere('id', $skuOrId)->first(['id', 'name_ar', 'storage_class'])
            ?? throw AppError::validation('BAD_PRODUCT', 'المنتج المخصص غير موجود', 'Fixed product not found');
    }

    private function checkStorageFit(string $storageClass, string $zoneType, string $code): void
    {
        if ($storageClass === 'frozen' && $zoneType !== 'frozen') {
            throw AppError::rule('STORAGE_MISMATCH', "منتج مجمد لا يُخزن خارج منطقة FZ ({$code})", 'Frozen product outside frozen zone');
        }
        if ($storageClass === 'chilled' && ! in_array($zoneType, ['chilled', 'frozen'], true)) {
            throw AppError::rule('STORAGE_MISMATCH', "منتج مبرد لا يُخزن خارج CH ({$code})", 'Chilled product outside chilled zone');
        }
    }

    private static function zoneType(?string $type): string
    {
        return $type === 'chill' ? 'chilled' : ($type ?: 'ambient');
    }

    /** @return array{minTempC:?int, maxTempC:?int} */
    private static function defaultTemps(string $type): array
    {
        return match ($type) {
            'chilled' => ['minTempC' => 2, 'maxTempC' => 6],
            'frozen' => ['minTempC' => -20, 'maxTempC' => -16],
            default => ['minTempC' => null, 'maxTempC' => null],
        };
    }

    /** 'YYYY-MM-DD…' → that day at 00:00 UTC. */
    private static function day(string $value, string $errorCode): Carbon
    {
        try {
            $day = substr($value, 0, 10);
            $date = Carbon::createFromFormat('!Y-m-d', $day, 'UTC');
            if ($date === null || $date->format('Y-m-d') !== $day) {
                throw new InvalidArgumentException('invalid date');
            }

            return $date;
        } catch (Throwable) {
            throw AppError::validation($errorCode, 'التاريخ غير صالح', 'Invalid date');
        }
    }

    /** @return string[] */
    private static function zoneList(?string $zones): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,،]/u', (string) $zones)), fn ($s) => $s !== ''));
    }
}
