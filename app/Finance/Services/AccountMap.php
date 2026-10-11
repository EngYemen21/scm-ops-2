<?php

namespace App\Finance\Services;

use App\Finance\Models\Account;
use App\Finance\Models\CostCenter;
use App\Support\AppError;

/**
 * Resolves the posting rules' control roles (ar, ap, inventory …) to accounts, and business objects to cost centers.
 * Roles live on the accounts themselves (`fin_accounts.role`, unique), so the finance team re-maps a role by moving it
 * to another account in the chart — no code change, no settings JSON.
 */
class AccountMap
{
    /** @var array<string, Account> per-request cache */
    private array $byRole = [];

    /** @var array<string, Account> */
    private array $byCode = [];

    public function role(string $role): Account
    {
        if (! isset($this->byRole[$role])) {
            $a = Account::where('role', $role)->where('active', true)->first()
                ?? throw AppError::rule('ACCOUNT_ROLE_UNMAPPED', "لا يوجد حساب مرتبط بالدور المحاسبي «{$role}» في دليل الحسابات — اربط الدور بحساب قبل الترحيل", "No account is mapped to the posting role '{$role}'");
            if (! $a->is_postable) {
                throw AppError::rule('ACCOUNT_NOT_POSTABLE', "الحساب {$a->code} رئيسي لا يقبل الترحيل", "Account {$a->code} is a header");
            }
            $this->byRole[$role] = $a;
        }

        return $this->byRole[$role];
    }

    public function code(string $code): Account
    {
        if (! isset($this->byCode[$code])) {
            $a = Account::where('code', $code)->orWhere('id', $code)->first()
                ?? throw AppError::notFound('ACCOUNT_NOT_FOUND', "الحساب {$code} غير موجود", "Account {$code} not found");
            $this->byCode[$code] = $a;
        }

        return $this->byCode[$code];
    }

    public function forWarehouse(?string $warehouseId): ?CostCenter
    {
        return $warehouseId ? CostCenter::where('warehouse_id', $warehouseId)->first() : null;
    }

    /** The vehicle's own cost center when finance created one, otherwise the fleet cost center. */
    public function forVehicle(?string $vehicleId): ?CostCenter
    {
        return ($vehicleId ? CostCenter::where('vehicle_id', $vehicleId)->first() : null) ?? CostCenter::where('code', 'FLEET')->first();
    }

    public function costCenter(string $code): ?CostCenter
    {
        return CostCenter::where('code', $code)->orWhere('id', $code)->first();
    }

    public function flush(): void
    {
        $this->byRole = [];
        $this->byCode = [];
    }
}
