<?php

namespace App\Planning\Services;

use App\Planning\Models\Attribute;
use App\Planning\Models\WarehouseMap;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;

/**
 * What planning needs a person to say, never guesses:
 *   - which OPS warehouse each Odoo warehouse is (Odoo's history of an unmapped warehouse is left out of every run and
 *     counted in its diagnostics);
 *   - how critical a product or a customer is (critical · high · normal · low; no row = normal) — the budget
 *     allocation funds critical products first and weighs customers by it.
 */
class SettingsService
{
    public function __construct(private readonly AuditService $audit) {}

    /** Every Odoo warehouse of the copy with the OPS warehouse it is mapped to. */
    public function warehouseMaps(): array
    {
        $maps = WarehouseMap::where('system', 'odoo')->get()->keyBy('external_id');
        $ops = DB::table('warehouses')->get(['id', 'code', 'name_ar', 'name_en'])->keyBy('id');
        $out = [];
        foreach (DB::table('odoo_records')->where('model', 'stock.warehouse')->whereNull('deleted_at')->orderBy('odoo_id')->get(['odoo_id', 'display_name', 'data']) as $w) {
            $d = json_decode((string) $w->data, true) ?: [];
            $m = $maps[(string) $w->odoo_id] ?? null;
            $wh = $m && $m->warehouse_id ? ($ops[$m->warehouse_id] ?? null) : null;
            $out[] = ['odooId' => (int) $w->odoo_id, 'name' => $d['name'] ?? $w->display_name, 'code' => $d['code'] ?? null,
                'warehouse' => $wh ? ['id' => $wh->id, 'code' => $wh->code, 'nameAr' => $wh->name_ar, 'nameEn' => $wh->name_en] : null,
                'mappedBy' => $m?->updated_by, 'mappedAt' => $m?->updated_at?->toJSON()];
        }

        return ['items' => $out, 'unmapped' => count(array_filter($out, fn ($w) => $w['warehouse'] === null))];
    }

    public function mapWarehouse(AuthUser $user, int $odooId, ?string $warehouseId): array
    {
        $odoo = DB::table('odoo_records')->where('model', 'stock.warehouse')->where('odoo_id', $odooId)->first(['odoo_id', 'display_name', 'data'])
            ?? throw AppError::notFound('PLANNING_ODOO_WAREHOUSE_NOT_FOUND', 'مستودع أودو غير موجود في النسخة', 'Odoo warehouse not found in the copy');
        $wh = null;
        if ($warehouseId) {
            $wh = DB::table('warehouses')->where('id', $warehouseId)->orWhere('code', $warehouseId)->first(['id', 'code'])
                ?? throw AppError::notFound('WAREHOUSE_NOT_FOUND', 'المستودع غير موجود', 'Warehouse not found');
            $other = WarehouseMap::where('system', 'odoo')->where('warehouse_id', $wh->id)->where('external_id', '!=', (string) $odooId)->first();
            if ($other) {
                throw AppError::conflict('PLANNING_WAREHOUSE_MAPPED', "المستودع {$wh->code} مربوط بمستودع أودو آخر ({$other->external_name})", "Warehouse {$wh->code} is mapped to another Odoo warehouse ({$other->external_name})");
            }
        }
        DB::transaction(function () use ($user, $odoo, $wh) {
            $m = WarehouseMap::where('system', 'odoo')->where('external_id', (string) $odoo->odoo_id)->lockForUpdate()->first();
            $old = $m?->warehouse_id;
            $d = json_decode((string) $odoo->data, true) ?: [];
            WarehouseMap::updateOrCreate(['system' => 'odoo', 'external_id' => (string) $odoo->odoo_id],
                ['external_name' => mb_substr((string) ($d['name'] ?? $odoo->display_name), 0, 190), 'warehouse_id' => $wh?->id, 'updated_by' => $user->username]);
            $this->audit->log($user, ['action' => 'PLANNING.WAREHOUSE_MAP', 'entityType' => 'PlanningWarehouseMap', 'entityId' => (string) $odoo->odoo_id, 'entityNumber' => (string) ($d['code'] ?? $odoo->odoo_id),
                'oldValue' => ['warehouseId' => $old], 'newValue' => ['warehouseId' => $wh?->id, 'code' => $wh?->code]]);
        });

        return $this->warehouseMaps();
    }

    /** Products or customers with their criticality (only the ones a person set; search names the rest). */
    public function attributes(string $entity, Paging $page, array $f): array
    {
        $table = $entity === 'product' ? 'products' : 'customers';
        $q = DB::table("{$table} as t")->leftJoin('planning_attributes as a', fn ($j) => $j->on('a.entity_id', '=', 't.id')->where('a.entity', '=', $entity))
            ->select($entity === 'product' ? ['t.id', 't.sku as code', 't.name_ar', 't.name_en', 'a.criticality', 'a.updated_by', 'a.updated_at'] : ['t.id', 't.code', 't.name_ar', 't.name_en', 'a.criticality', 'a.updated_by', 'a.updated_at']);
        if (! empty($f['criticality'])) {
            $f['criticality'] === 'normal' ? $q->where(fn ($w) => $w->whereNull('a.criticality')->orWhere('a.criticality', 'normal')) : $q->where('a.criticality', $f['criticality']);
        } elseif (! $page->q) {
            $q->whereNotNull('a.criticality'); // the list of what was set; a search reaches everything
        }
        if ($page->q) {
            $like = '%'.$page->q.'%';
            $q->where(fn ($w) => $w->where($entity === 'product' ? 't.sku' : 't.code', 'like', $like)->orWhere('t.name_ar', 'like', $like)->orWhere('t.name_en', 'like', $like));
        }
        $q->orderByRaw("CASE a.criticality WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'low' THEN 3 ELSE 2 END")->orderBy($entity === 'product' ? 't.sku' : 't.code')->orderBy('t.id');
        $total = (clone $q)->count();

        return $page->wrap($q->forPage($page->page, $page->pageSize)->get()->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'nameAr' => $r->name_ar, 'nameEn' => $r->name_en,
            'criticality' => $r->criticality ?? 'normal', 'set' => $r->criticality !== null, 'updatedBy' => $r->updated_by])->all(), $total);
    }

    public function setAttribute(AuthUser $user, string $entity, string $id, string $criticality): array
    {
        $table = $entity === 'product' ? 'products' : 'customers';
        $row = DB::table($table)->where('id', $id)->orWhere($entity === 'product' ? 'sku' : 'code', $id)->first(['id'])
            ?? throw AppError::notFound($entity === 'product' ? 'PRODUCT_NOT_FOUND' : 'CUSTOMER_NOT_FOUND', 'غير موجود', 'Not found');
        DB::transaction(function () use ($user, $entity, $row, $criticality) {
            $a = Attribute::where('entity', $entity)->where('entity_id', $row->id)->lockForUpdate()->first();
            $old = $a?->criticality ?? 'normal';
            Attribute::updateOrCreate(['entity' => $entity, 'entity_id' => $row->id], ['criticality' => $criticality, 'updated_by' => $user->username]);
            $this->audit->log($user, ['action' => 'PLANNING.CRITICALITY', 'entityType' => $entity === 'product' ? 'Product' : 'Customer', 'entityId' => $row->id, 'field' => 'criticality', 'oldValue' => $old, 'newValue' => $criticality]);
        });

        return ['id' => $row->id, 'entity' => $entity, 'criticality' => $criticality];
    }
}
