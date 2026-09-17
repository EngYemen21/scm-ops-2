<?php

namespace App\Services\Master;

use App\Models\Customer;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Models\Zone;

/**
 * Cross-entity master-data helpers: the compact lookup payload every form select needs
 * (warehouses + zones, categories, UoMs, suppliers, customers). Domain logic lives in
 * ProductsService / PartnersService / WarehousesService.
 */
class MasterDataService
{
    public function lookups(): array
    {
        $warehouses = Warehouse::where('active', true)->orderBy('code')->get(['id', 'code', 'name_ar', 'name_en', 'city', 'type', 'temp_zones', 'docks']);
        $zones = Zone::where('active', true)->orderBy('code')->orderBy('id')->get(['id', 'warehouse_id', 'code', 'name_ar', 'name_en', 'type', 'pick_strategy']);
        // Roots last, like the reference (PostgreSQL sorts NULLs after values on ASC).
        $categories = ProductCategory::orderByRaw('parent_id is null')->orderBy('parent_id')->orderBy('name_ar')->orderBy('id')->get(['id', 'code', 'name_ar', 'name_en', 'parent_id', 'active']);
        $uoms = Uom::orderBy('code')->get(['id', 'code', 'name_ar', 'name_en']);
        $suppliers = Supplier::where('active', true)->orderBy('code')->get(['id', 'code', 'name_ar', 'name_en', 'score', 'lead_days', 'is_new', 'category']);
        $customers = Customer::where('active', true)->orderBy('code')->get(['id', 'code', 'name_ar', 'name_en', 'city', 'terms', 'credit_limit', 'balance', 'price_list']);

        $byId = $categories->keyBy('id');
        $childIds = [];
        foreach ($categories as $c) {
            if ($c->parent_id) {
                $childIds[$c->parent_id][] = $c->id;
            }
        }
        $supplierCategories = [];
        foreach (PartnersService::SUPPLIER_CATEGORIES as $key => [$ar, $en]) {
            $supplierCategories[] = ['key' => $key, 'ar' => $ar, 'en' => $en];
        }

        return [
            'warehouses' => $warehouses->map(fn (Warehouse $w) => $w->toArray() + [
                'dockList' => array_map(fn (int $i) => 'D'.$i, $w->docks > 0 ? range(1, $w->docks) : []),
                'zones' => $zones->where('warehouse_id', $w->id)->map(function (Zone $z) {
                    $row = $z->toArray();
                    unset($row['warehouseId']);

                    return $row;
                })->values()->all(),
            ])->values()->all(),
            'categories' => $categories->map(fn (ProductCategory $c) => $c->toArray() + [
                'pathAr' => $c->parent_id ? ($byId->get($c->parent_id)?->name_ar ?? '').' ← '.$c->name_ar : $c->name_ar,
                'children' => $childIds[$c->id] ?? [],
            ])->values()->all(),
            'uoms' => $uoms->values()->all(),
            'suppliers' => $suppliers->map(fn (Supplier $s) => $s->toArray() + ['blocksPo' => $s->score < 65])->values()->all(),
            'customers' => $customers->map(fn (Customer $c) => ['creditLimit' => (float) $c->credit_limit, 'balance' => (float) $c->balance] + $c->toArray())->values()->all(),
            'supplierCategories' => $supplierCategories,
            'dockSlots' => [
                ['key' => '06', 'label' => '06:00 – 08:00'], ['key' => '08', 'label' => '08:00 – 10:00'], ['key' => '10', 'label' => '10:00 – 12:00'],
                ['key' => '13', 'label' => '13:00 – 15:00'], ['key' => '15', 'label' => '15:00 – 17:00'],
            ],
        ];
    }
}
