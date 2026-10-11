<?php

namespace App\Finance\Services;

use App\Finance\Models\BankAccount;
use App\Finance\Models\DepreciationEntry;
use App\Finance\Models\FixedAsset;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fixed assets register (vehicles first). Registration posts the acquisition (asset ↔ bank / supplier / opening equity),
 * `depreciate(period)` posts one straight-line month per active asset (idempotent per asset × period), disposal posts the
 * book value against proceeds. Nothing is invented: an asset exists only when finance registers it with its real cost.
 */
class FixedAssetsService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountMap $map,
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
    ) {}

    /** @param  array{nameAr:string, nameEn?:?string, category?:string, vehicle?:?string, acquiredOn:string, cost:float|int|string, salvageValue?:float|int|string|null, usefulLifeMonths:int|string, fundedBy?:string, bankAccount?:?string, supplier?:?string, notes?:?string, accumulated?:float|int|string|null, depreciatedThrough?:?string}  $dto */
    public function register(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $cost = round((float) $dto['cost'], 2);
            $salvage = round((float) ($dto['salvageValue'] ?? 0), 2);
            $life = (int) $dto['usefulLifeMonths'];
            if ($cost <= 0 || $life <= 0 || $salvage < 0 || $salvage >= $cost) {
                throw AppError::validation('ASSET_VALUES', 'التكلفة والعمر الإنتاجي يجب أن يكونا أكبر من صفر والقيمة التخريدية أقل من التكلفة', 'Invalid cost / life / salvage');
            }
            $vehicle = ! empty($dto['vehicle']) ? (Vehicle::where('code', $dto['vehicle'])->orWhere('id', $dto['vehicle'])->first() ?? throw AppError::notFound('VEHICLE_NOT_FOUND', 'المركبة غير موجودة', 'Vehicle not found')) : null;
            if ($vehicle && FixedAsset::where('vehicle_id', $vehicle->id)->where('status', 'active')->exists()) {
                throw AppError::conflict('ASSET_EXISTS', "المركبة {$vehicle->code} مسجلة أصلًا ثابتًا", 'Vehicle already registered as an asset');
            }
            $category = $dto['category'] ?? ($vehicle ? 'vehicle' : 'equipment');
            $assetAcct = $category === 'vehicle' ? $this->map->role('vehicles') : $this->map->code('1520');
            $accum = $this->map->role('accumulated_depreciation');
            $expense = $this->map->role('depreciation');
            $cc = $vehicle ? $this->map->forVehicle($vehicle->id) : $this->map->costCenter('ADMIN');
            $acquired = Carbon::parse($dto['acquiredOn'])->startOfDay();
            $accumulated = round((float) ($dto['accumulated'] ?? 0), 2); // already-depreciated amount when registering an existing asset
            $through = ! empty($dto['depreciatedThrough']) ? Carbon::parse($dto['depreciatedThrough'])->endOfMonth() : null;
            if ($accumulated > 0 && ! $through) {
                throw AppError::validation('ASSET_ACCUMULATED', 'حدد آخر شهر أُهلك عند تسجيل مجمع إهلاك سابق', 'Provide the last depreciated month');
            }
            $a = FixedAsset::create([
                'code' => $this->numbering->next('FA'), 'name_ar' => trim($dto['nameAr']), 'name_en' => trim($dto['nameEn'] ?? $dto['nameAr']), 'category' => $category, 'vehicle_id' => $vehicle?->id,
                'acquired_on' => $acquired->toDateString(), 'cost' => $cost, 'salvage_value' => $salvage, 'useful_life_months' => $life, 'method' => 'straight_line',
                'accumulated_depreciation' => $accumulated, 'depreciated_through' => $through?->toDateString(), 'asset_account_id' => $assetAcct->id, 'accum_account_id' => $accum->id, 'expense_account_id' => $expense->id,
                'cost_center_id' => $cc?->id, 'status' => 'active', 'notes' => ($dto['notes'] ?? null) ?: null, 'created_by' => $user->username,
            ]);
            // acquisition: funded by bank, by a supplier account, or carried in from before the system (opening balances)
            $funded = $dto['fundedBy'] ?? 'opening';
            $dims = ['vehicleId' => $vehicle?->id, 'costCenterId' => $cc?->id, 'referenceType' => 'FixedAsset', 'referenceId' => $a->id];
            $credits = [];
            if ($funded === 'bank') {
                $bank = BankAccount::where('code', $dto['bankAccount'] ?? 'BANK')->orWhere('id', $dto['bankAccount'] ?? '')->first() ?? throw AppError::notFound('BANK_ACCOUNT_NOT_FOUND', 'الحساب البنكي غير موجود', 'Bank account not found');
                $credits[] = ['account' => $bank->account_id, 'credit' => $cost, 'description' => "شراء {$a->code}"] + $dims;
            } elseif ($funded === 'supplier') {
                $sup = Supplier::where('code', $dto['supplier'] ?? '')->orWhere('id', $dto['supplier'] ?? '')->first() ?? throw AppError::notFound('SUPPLIER_NOT_FOUND', 'المورد غير موجود', 'Supplier not found');
                $credits[] = ['role' => 'ap', 'credit' => $cost, 'description' => "شراء {$a->code}", 'partnerType' => 'supplier', 'partnerId' => $sup->id] + $dims;
            } else {
                $credits[] = ['role' => 'opening_balance_equity', 'credit' => round($cost - $accumulated, 2), 'description' => "رصيد افتتاحي {$a->code}"] + $dims;
                if ($accumulated > 0) {
                    $credits[] = ['account' => $accum->id, 'credit' => $accumulated, 'description' => "مجمع إهلاك سابق {$a->code}"] + $dims;
                }
            }
            $j = $this->ledger->post($user, [
                'eventKey' => "fa:acq:{$a->id}", 'date' => $funded === 'opening' ? now() : $acquired, 'kind' => $funded === 'opening' ? 'opening' : 'auto',
                'source' => ['type' => 'FixedAsset', 'id' => $a->id, 'number' => $a->code], 'memo' => "تسجيل أصل {$a->code} — {$a->name_ar}",
                'lines' => array_merge([['account' => $assetAcct->id, 'debit' => $cost, 'description' => $a->name_ar] + $dims], $credits),
            ]);
            $a->update(['acquisition_journal_id' => $j->id]);
            $this->audit->log($user, ['action' => 'ASSET.REGISTER', 'entityType' => 'FixedAsset', 'entityId' => $a->id, 'entityNumber' => $a->code, 'newValue' => ['cost' => $cost, 'life' => $life, 'vehicle' => $vehicle?->code, 'fundedBy' => $funded, 'journal' => $j->number]]);

            return $this->get($a->id);
        });
    }

    /** Monthly straight-line depreciation for every active asset up to (and including) the period; idempotent. */
    public function depreciate(AuthUser $user, string $periodCode): array
    {
        $end = Carbon::createFromFormat('Y-m', $periodCode)->endOfMonth();

        return DB::transaction(function () use ($user, $periodCode, $end) {
            $posted = [];
            $skipped = 0;
            foreach (FixedAsset::where('status', 'active')->lockForUpdate()->get() as $a) {
                $monthly = round(((float) $a->cost - (float) $a->salvage_value) / $a->useful_life_months, 2);
                $cursor = $a->depreciated_through ? Carbon::parse($a->depreciated_through)->addDay()->startOfMonth() : Carbon::parse($a->acquired_on)->startOfMonth();
                while ($cursor->lte($end)) {
                    $code = $cursor->format('Y-m');
                    $remaining = round((float) $a->cost - (float) $a->salvage_value - (float) $a->accumulated_depreciation, 2);
                    if ($remaining <= 0 || DepreciationEntry::where('asset_id', $a->id)->where('period_code', $code)->exists()) {
                        $skipped++;
                        $cursor->addMonth();

                        continue;
                    }
                    $amount = min($monthly, $remaining);
                    $dims = ['vehicleId' => $a->vehicle_id, 'costCenterId' => $a->cost_center_id, 'referenceType' => 'FixedAsset', 'referenceId' => $a->id];
                    $j = $this->ledger->post($user, [
                        'eventKey' => "fa:dep:{$a->id}:{$code}", 'date' => $cursor->copy()->endOfMonth(), 'source' => ['type' => 'FixedAsset', 'id' => $a->id, 'number' => $a->code],
                        'memo' => "إهلاك {$a->code} — {$a->name_ar} — {$code}",
                        'lines' => [['account' => $a->expense_account_id, 'debit' => $amount, 'description' => "إهلاك {$code}"] + $dims, ['account' => $a->accum_account_id, 'credit' => $amount, 'description' => "مجمع إهلاك {$a->code}"] + $dims],
                    ]);
                    DepreciationEntry::create(['asset_id' => $a->id, 'period_code' => $code, 'amount' => $amount, 'journal_id' => $j->id]);
                    $a->update(['accumulated_depreciation' => round((float) $a->accumulated_depreciation + $amount, 2), 'depreciated_through' => $cursor->copy()->endOfMonth()->toDateString()]);
                    $a->refresh();
                    $posted[] = ['asset' => $a->code, 'period' => $code, 'amount' => $amount, 'journal' => $j->number];
                    $cursor->addMonth();
                }
            }
            if ($posted) {
                $this->audit->log($user, ['action' => 'ASSET.DEPRECIATE', 'entityType' => 'FiscalPeriod', 'entityId' => null, 'entityNumber' => $periodCode, 'newValue' => ['entries' => count($posted), 'total' => round(array_sum(array_column($posted, 'amount')), 2)]]);
            }

            return ['period' => $periodCode, 'posted' => $posted, 'skipped' => $skipped, 'total' => round(array_sum(array_column($posted, 'amount')), 2)];
        });
    }

    /** @param  array{disposedOn?:?string, proceeds?:float|int|string|null, bankAccount?:?string, reason:string}  $dto */
    public function dispose(AuthUser $user, string $code, array $dto): array
    {
        return DB::transaction(function () use ($user, $code, $dto) {
            $a = FixedAsset::where('code', $code)->orWhere('id', $code)->lockForUpdate()->first() ?? throw AppError::notFound('ASSET_NOT_FOUND', 'الأصل غير موجود', 'Asset not found');
            if ($a->status !== 'active') {
                throw AppError::rule('ASSET_STATE', 'الأصل مستبعد مسبقًا', 'Asset already disposed');
            }
            $date = ! empty($dto['disposedOn']) ? Carbon::parse($dto['disposedOn'])->startOfDay() : now()->startOfDay();
            $proceeds = round((float) ($dto['proceeds'] ?? 0), 2);
            $nbv = round((float) $a->cost - (float) $a->accumulated_depreciation, 2);
            $dims = ['vehicleId' => $a->vehicle_id, 'costCenterId' => $a->cost_center_id, 'referenceType' => 'FixedAsset', 'referenceId' => $a->id];
            $lines = [['account' => $a->accum_account_id, 'debit' => (float) $a->accumulated_depreciation, 'description' => "إقفال مجمع إهلاك {$a->code}"] + $dims];
            if ($proceeds > 0) {
                $bank = BankAccount::where('code', $dto['bankAccount'] ?? 'BANK')->orWhere('id', $dto['bankAccount'] ?? '')->first() ?? throw AppError::notFound('BANK_ACCOUNT_NOT_FOUND', 'الحساب البنكي غير موجود', 'Bank account not found');
                $lines[] = ['account' => $bank->account_id, 'debit' => $proceeds, 'description' => "متحصلات بيع {$a->code}"] + $dims;
            }
            $gain = round($proceeds - $nbv, 2);
            if ($gain != 0.0) {
                $lines[] = ['role' => 'admin_expenses', $gain < 0 ? 'debit' : 'credit' => abs($gain), 'description' => ($gain < 0 ? 'خسارة' : 'ربح')." استبعاد {$a->code}"] + $dims;
            }
            $lines[] = ['account' => $a->asset_account_id, 'credit' => (float) $a->cost, 'description' => "استبعاد {$a->code}"] + $dims;
            $j = $this->ledger->post($user, ['eventKey' => "fa:disp:{$a->id}", 'date' => $date, 'source' => ['type' => 'FixedAsset', 'id' => $a->id, 'number' => $a->code], 'memo' => "استبعاد أصل {$a->code}: {$dto['reason']}", 'lines' => array_filter($lines, fn ($l) => ($l['debit'] ?? 0) > 0 || ($l['credit'] ?? 0) > 0)]);
            $a->update(['status' => 'disposed', 'disposed_on' => $date->toDateString(), 'disposal_proceeds' => $proceeds]);
            $this->audit->log($user, ['action' => 'ASSET.DISPOSE', 'entityType' => 'FixedAsset', 'entityId' => $a->id, 'entityNumber' => $a->code, 'newValue' => ['proceeds' => $proceeds, 'nbv' => $nbv, 'journal' => $j->number, 'reason' => $dto['reason']]]);

            return $this->get($a->id);
        });
    }

    public function get(string $code): array
    {
        $a = FixedAsset::with(['vehicle:id,code,plate_ar', 'costCenter:id,code,name_ar,name_en', 'depreciation' => fn ($q) => $q->orderBy('period_code'), 'depreciation.journal:id,number'])
            ->where(fn ($w) => $w->where('code', $code)->orWhere('id', $code))->first() ?? throw AppError::notFound('ASSET_NOT_FOUND', 'الأصل غير موجود', 'Asset not found');
        $row = $a->toArray();
        $row['netBookValue'] = round((float) $a->cost - (float) $a->accumulated_depreciation, 2);
        $row['monthlyDepreciation'] = round(((float) $a->cost - (float) $a->salvage_value) / max(1, $a->useful_life_months), 2);

        return $row;
    }

    public function list(Paging $page, array $f): array
    {
        $q = FixedAsset::with(['vehicle:id,code,plate_ar', 'costCenter:id,code,name_ar,name_en']);
        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['category'])) {
            $q->where('category', $f['category']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like));
        }
        $q->orderBy('code', $page->order);

        return $page->paginate($q, fn (FixedAsset $a) => $a->toArray() + ['netBookValue' => round((float) $a->cost - (float) $a->accumulated_depreciation, 2)]);
    }
}
