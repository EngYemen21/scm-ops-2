<?php

namespace App\Services\Core;

use App\Models\SystemSetting;

/**
 * Configurable business policies. Defaults are the values validated with the business; an administrator can
 * override any of them from the Settings page (stored in system_settings).
 */
class SettingsService
{
    public const DEFAULTS = [
        'procurement.approvalTiers' => ['value' => [['max' => 5000, 'roles' => ['proc']], ['max' => 25000, 'roles' => ['proc', 'finance']], ['max' => null, 'roles' => ['proc', 'finance', 'gm']]], 'group' => 'procurement', 'description' => 'PO approval chain by total (SAR)'],
        'procurement.minSupplierScore' => ['value' => 65, 'group' => 'procurement', 'description' => 'Suppliers below this score need a procurement-manager exception for new POs'],
        'procurement.newSupplierScore' => ['value' => 70, 'group' => 'procurement', 'description' => 'Initial score for a new supplier'],
        'receiving.allowExcess' => ['value' => false, 'group' => 'receiving', 'description' => 'Allow receiving more than the open PO quantity'],
        'inventory.autoQuarantineDays' => ['value' => 7, 'group' => 'inventory', 'description' => 'Batches expiring within N days are auto-quarantined by the daily job'],
        'inventory.expiringSoonDays' => ['value' => 30, 'group' => 'inventory', 'description' => 'Dashboard "expiring soon" window'],
        'sales.vatPct' => ['value' => 15, 'group' => 'sales', 'description' => 'VAT percentage'],
        'sales.maxDiscountPct' => ['value' => 30, 'group' => 'sales', 'description' => 'Discount above this needs sales-manager approval'],
        'sales.enforceCreditLimit' => ['value' => true, 'group' => 'sales', 'description' => 'Reject SO when customer balance + order exceeds credit limit'],
        'delivery.autoReturnOnPartial' => ['value' => true, 'group' => 'delivery', 'description' => 'Partial delivery creates a customer return for the remainder'],
        'exceptions.sla' => ['value' => ['damage' => 48, 'rejected' => 48, 'shortage' => 24, 'wrongloc' => 8, 'wrongveh' => 2, 'capacity' => 1, 'faildel' => 4, 'partial' => 8, 'temp' => 2, 'transfer' => 24, 'other' => 24], 'group' => 'exceptions', 'description' => 'SLA hours per exception kind'],
        'fleet.docExpiryWarnDays' => ['value' => 30, 'group' => 'fleet', 'description' => 'Warn when vehicle/driver documents expire within N days'],
        'fleet.driverLicenseMinDays' => ['value' => 30, 'group' => 'fleet', 'description' => 'A driver whose license expires within N days cannot be activated/assigned'],
        'fleet.fuelMaxPricePerLiter' => ['value' => 4, 'group' => 'fleet', 'description' => 'Fuel record validation ceiling (SAR/L)'],
        'fleet.fuelAnomalyRatio' => ['value' => 0.75, 'group' => 'fleet', 'description' => 'km/L below this fraction of vehicle average is flagged'],
        'fleet.maintenanceSoonKm' => ['value' => 3000, 'group' => 'fleet', 'description' => 'Vehicle recommendation penalty when maintenance is due within N km'],
        'loading.cbmPerCarton' => ['value' => 0.06, 'group' => 'loading', 'description' => 'Fallback CBM per carton when an order has no volume'],
        'company' => ['value' => ['nameAr' => 'B2B', 'nameEn' => 'B2B', 'currency' => 'SAR'], 'group' => 'general', 'description' => 'Company profile'],
    ];

    /** @var array<string, mixed> per-process cache; cleared on set() and by flush() (tests). */
    private array $cache = [];

    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $row = SystemSetting::find($key);

        return $this->cache[$key] = $row ? $row->value : (self::DEFAULTS[$key]['value'] ?? null);
    }

    public function set(string $key, mixed $value, ?string $userId = null): void
    {
        $default = self::DEFAULTS[$key] ?? null;
        SystemSetting::updateOrCreate(['key' => $key], [
            'value' => $value,
            'group' => $default['group'] ?? 'general',
            'description' => $default['description'] ?? null,
            'updated_by_id' => $userId,
        ]);
        unset($this->cache[$key]);
    }

    public function all(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $d) {
            $out[$key] = ['value' => $d['value'], 'group' => $d['group'], 'description' => $d['description'], 'isDefault' => true];
        }
        foreach (SystemSetting::all() as $row) {
            $out[$row->key] = ['value' => $row->value, 'group' => $row->group, 'description' => $row->description, 'isDefault' => false, 'updatedAt' => $row->updated_at];
        }

        return $out;
    }

    public function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $key => $d) {
            SystemSetting::firstOrCreate(['key' => $key], ['value' => $d['value'], 'group' => $d['group'], 'description' => $d['description']]);
        }
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
