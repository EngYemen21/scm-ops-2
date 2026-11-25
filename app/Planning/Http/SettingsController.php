<?php

namespace App\Planning\Http;

use App\Http\Controllers\Controller;
use App\Planning\Models\Run;
use App\Planning\Services\SettingsService;
use App\Planning\Support\Owned;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** What the page needs to draw its forms, the Odoo-warehouse mapping and the criticality of products and customers. */
class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function lookups(): array
    {
        $user = AuthUser::current();

        return [
            'warehouses' => DB::table('warehouses')->where('active', true)->orderBy('code')->get(['id', 'code', 'name_ar', 'name_en'])->map(fn ($w) => ['id' => $w->id, 'code' => $w->code, 'nameAr' => $w->name_ar, 'nameEn' => $w->name_en])->all(),
            'methods' => Run::METHODS, 'statuses' => Run::STATUSES, 'following' => Owned::following(),
            'odooWarehouses' => $this->settings->warehouseMaps()['unmapped'],
            'can' => ['forecast' => $user->can('forecast.manage'), 'plan' => $user->can('purchaseplan.manage'), 'approve' => $user->can('purchaseplan.approve'), 'request' => $user->can('pr.create'), 'export' => $user->can('report.export')],
        ];
    }

    public function warehouseMaps(): array
    {
        return $this->settings->warehouseMaps();
    }

    public function mapWarehouse(Request $request, int $odooId): array
    {
        return $this->settings->mapWarehouse(AuthUser::current(), $odooId, $request->validate(['warehouseId' => 'present|nullable|string|max:40'])['warehouseId']);
    }

    public function attributes(Request $request, string $entity): array
    {
        $f = $request->validate(['criticality' => 'nullable|in:critical,high,normal,low', 'page' => 'sometimes|integer|min:1', 'pageSize' => 'sometimes|integer|min:1|max:200', 'q' => 'nullable|string|max:120']);

        return $this->settings->attributes($entity, Paging::from($request, 50), $f);
    }

    public function setAttribute(Request $request, string $entity, string $id): array
    {
        return $this->settings->setAttribute(AuthUser::current(), $entity, $id, $request->validate(['criticality' => 'required|in:critical,high,normal,low'])['criticality']);
    }
}
