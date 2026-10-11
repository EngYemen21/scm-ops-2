<?php

namespace App\Finance;

use App\Finance\Support\Coa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Idempotent finance master data: the default chart of accounts (only when the chart is empty), one cost center per
 * warehouse + a fleet cost center, and the cash box / main bank account rows that point at the chart.
 * Runs from the finance migration and the seeder (the snapshot import replaces nothing here, but a fresh seed needs it).
 * Posts NO balances.
 */
class FinanceBootstrap
{
    public static function ensure(): array
    {
        if (! Schema::hasTable('fin_accounts')) {
            return ['accounts' => 0];
        }
        $out = ['accounts' => 0, 'costCenters' => 0, 'bankAccounts' => 0];
        $now = now();
        if (DB::table('fin_accounts')->count() === 0) {
            $ids = [];
            foreach (Coa::defaults() as $i => $a) {
                $id = (string) Str::ulid();
                $ids[$a['code']] = $id;
                DB::table('fin_accounts')->insert([
                    'id' => $id, 'code' => $a['code'], 'name_ar' => $a['ar'], 'name_en' => $a['en'], 'type' => $a['type'], 'subtype' => $a['subtype'] ?? null,
                    'parent_id' => isset($a['parent']) ? ($ids[$a['parent']] ?? null) : null, 'is_postable' => empty($a['header']), 'role' => $a['role'] ?? null,
                    'active' => true, 'sort' => $i, 'created_at' => $now,
                ]);
                $out['accounts']++;
            }
        }
        // cost centers: one per warehouse (code = warehouse code) + FLEET + ADMIN + SALES
        if (Schema::hasTable('warehouses')) {
            foreach (DB::table('warehouses')->get(['id', 'code', 'name_ar', 'name_en']) as $w) {
                if (! DB::table('fin_cost_centers')->where('code', $w->code)->exists()) {
                    DB::table('fin_cost_centers')->insert(['id' => (string) Str::ulid(), 'code' => $w->code, 'name_ar' => 'مستودع '.$w->name_ar, 'name_en' => 'Warehouse '.$w->name_en, 'kind' => 'warehouse', 'warehouse_id' => $w->id, 'active' => true, 'created_at' => $now]);
                    $out['costCenters']++;
                }
            }
        }
        foreach ([['FLEET', 'الأسطول والنقل', 'Fleet and transport', 'fleet'], ['SALES', 'المبيعات', 'Sales', 'sales'], ['ADMIN', 'الإدارة العامة', 'Administration', 'admin']] as [$code, $ar, $en, $kind]) {
            if (! DB::table('fin_cost_centers')->where('code', $code)->exists()) {
                DB::table('fin_cost_centers')->insert(['id' => (string) Str::ulid(), 'code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'kind' => $kind, 'active' => true, 'created_at' => $now]);
                $out['costCenters']++;
            }
        }
        // cash box + main bank account rows (master data only; IBAN is entered by finance)
        $cash = DB::table('fin_accounts')->where('role', 'cash')->value('id');
        $bank = DB::table('fin_accounts')->where('role', 'bank')->value('id');
        foreach ([['CASH', 'الصندوق الرئيسي', 'Main cash box', 'cash', $cash], ['BANK', 'البنك الرئيسي', 'Main bank account', 'bank', $bank]] as [$code, $ar, $en, $kind, $acct]) {
            if ($acct && ! DB::table('fin_bank_accounts')->where('code', $code)->exists()) {
                DB::table('fin_bank_accounts')->insert(['id' => (string) Str::ulid(), 'code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'kind' => $kind, 'account_id' => $acct, 'currency' => 'SAR', 'active' => true, 'created_at' => $now]);
                $out['bankAccounts']++;
            }
        }

        return $out;
    }
}
