<?php

namespace App\Hr;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Idempotent master data of the section: the expense categories an employee picks from, each tied to the account of
 * the chart it is charged to — through the account's posting ROLE, so a renumbered chart keeps working. Created only
 * while the table is empty (a company that renamed or removed a category keeps its own list). Runs from the migration.
 */
class HrBootstrap
{
    /** code, Arabic, English, the posting role of the account, proposed VAT % */
    private const CATEGORIES = [
        ['TRAVEL', 'سفر وتنقلات', 'Travel and transport', 'admin_expenses', 15],
        ['FUEL', 'وقود', 'Fuel', 'fuel', 15],
        ['TOLLS', 'رسوم طرق ومواقف', 'Tolls and parking', 'fleet_other', 15],
        ['MEALS', 'وجبات وضيافة', 'Meals and hospitality', 'admin_expenses', 15],
        ['LODGING', 'إقامة وسكن', 'Accommodation', 'admin_expenses', 15],
        ['SUPPLIES', 'مستلزمات مكتبية', 'Office supplies', 'admin_expenses', 15],
        ['COMMS', 'اتصالات وإنترنت', 'Phone and internet', 'admin_expenses', 15],
        ['GOVT', 'رسوم حكومية', 'Government fees', 'admin_expenses', 0],
        ['OTHER', 'مصروفات أخرى', 'Other expenses', 'admin_expenses', 0],
    ];

    /** @return array{categories:int} */
    public static function ensure(): array
    {
        if (! Schema::hasTable('hr_expense_categories') || DB::table('hr_expense_categories')->count() > 0) {
            return ['categories' => 0];
        }
        $byRole = Schema::hasTable('fin_accounts') ? DB::table('fin_accounts')->whereNotNull('role')->where('is_postable', true)->where('active', true)->pluck('code', 'role')->all() : [];
        $now = now();
        foreach (self::CATEGORIES as [$code, $ar, $en, $role, $vat]) {
            DB::table('hr_expense_categories')->insert(['id' => (string) Str::ulid(), 'code' => $code, 'name_ar' => $ar, 'name_en' => $en,
                'account_code' => $byRole[$role] ?? null, 'vat_pct' => $vat, 'active' => true, 'created_by' => 'system', 'created_at' => $now, 'updated_at' => $now]);
        }

        return ['categories' => count(self::CATEGORIES)];
    }
}
