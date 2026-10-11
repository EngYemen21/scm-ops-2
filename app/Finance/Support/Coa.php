<?php

namespace App\Finance\Support;

/**
 * Default chart of accounts (Saudi-style numbering) with the control roles the posting rules resolve.
 * The finance team can renumber / rename accounts and re-map roles in the UI; the rules never reference codes.
 * Roles (unique): ar, ap, inventory, inventory_in_transit, goods_in_transit, vat_input, vat_output, grni, accrued_expenses,
 * cash, bank, vehicles, accumulated_depreciation, capital, retained_earnings, opening_balance_equity, revenue, sales_returns,
 * cogs, ppv, freight_in, inventory_adjustment, inventory_loss, fuel, maintenance, fleet_other, depreciation, admin_expenses.
 */
final class Coa
{
    /** @return array<int, array{code:string, ar:string, en:string, type:string, subtype?:string, parent?:string, header?:bool, role?:string}> */
    public static function defaults(): array
    {
        $h = fn (string $code, string $ar, string $en, string $type, ?string $parent = null) => ['code' => $code, 'ar' => $ar, 'en' => $en, 'type' => $type, 'header' => true, 'parent' => $parent];
        $a = fn (string $code, string $ar, string $en, string $type, string $subtype, string $parent, ?string $role = null) => ['code' => $code, 'ar' => $ar, 'en' => $en, 'type' => $type, 'subtype' => $subtype, 'parent' => $parent, 'role' => $role];

        return [
            $h('1000', 'الأصول', 'Assets', 'asset'),
            $h('1100', 'الأصول المتداولة', 'Current assets', 'asset', '1000'),
            $a('1101', 'الصندوق الرئيسي', 'Main cash box', 'asset', 'cash', '1100', 'cash'),
            $a('1111', 'البنك الرئيسي', 'Main bank account', 'asset', 'bank', '1100', 'bank'),
            $a('1120', 'ذمم العملاء', 'Accounts receivable', 'asset', 'receivable', '1100', 'ar'),
            $a('1130', 'المخزون', 'Inventory', 'asset', 'inventory', '1100', 'inventory'),
            $a('1135', 'مخزون في الطريق بين المستودعات', 'Inventory in transit (transfers)', 'asset', 'inventory', '1100', 'inventory_in_transit'),
            $a('1140', 'بضاعة في الطريق إلى العملاء', 'Goods in transit to customers', 'asset', 'inventory', '1100', 'goods_in_transit'),
            $a('1160', 'ضريبة القيمة المضافة — مدخلات', 'VAT input', 'asset', 'tax', '1100', 'vat_input'),
            $h('1500', 'الأصول الثابتة', 'Fixed assets', 'asset', '1000'),
            $a('1510', 'المركبات', 'Vehicles', 'asset', 'fixed_asset', '1500', 'vehicles'),
            $a('1520', 'المعدات', 'Equipment', 'asset', 'fixed_asset', '1500'),
            $a('1590', 'مجمع إهلاك الأصول الثابتة', 'Accumulated depreciation', 'asset', 'contra_asset', '1500', 'accumulated_depreciation'),

            $h('2000', 'الالتزامات', 'Liabilities', 'liability'),
            $h('2100', 'الالتزامات المتداولة', 'Current liabilities', 'liability', '2000'),
            $a('2110', 'ذمم الموردين', 'Accounts payable', 'liability', 'payable', '2100', 'ap'),
            $a('2120', 'بضاعة مستلمة غير مفوترة (GRNI)', 'Goods received not invoiced', 'liability', 'accrual', '2100', 'grni'),
            $a('2130', 'ضريبة القيمة المضافة — مخرجات', 'VAT output', 'liability', 'tax', '2100', 'vat_output'),
            $a('2150', 'مصروفات مستحقة', 'Accrued expenses', 'liability', 'accrual', '2100', 'accrued_expenses'),

            $h('3000', 'حقوق الملكية', 'Equity', 'equity'),
            $a('3100', 'رأس المال', 'Share capital', 'equity', 'equity', '3000', 'capital'),
            $a('3200', 'الأرباح المبقاة', 'Retained earnings', 'equity', 'equity', '3000', 'retained_earnings'),
            $a('3900', 'أرصدة افتتاحية', 'Opening balance equity', 'equity', 'equity', '3000', 'opening_balance_equity'),

            $h('4000', 'الإيرادات', 'Revenue', 'revenue'),
            $a('4100', 'مبيعات', 'Sales revenue', 'revenue', 'revenue', '4000', 'revenue'),
            $a('4200', 'مردودات ومسموحات المبيعات', 'Sales returns and allowances', 'revenue', 'contra_revenue', '4000', 'sales_returns'),

            $h('5000', 'تكلفة المبيعات', 'Cost of sales', 'expense'),
            $a('5100', 'تكلفة البضاعة المباعة', 'Cost of goods sold', 'expense', 'cogs', '5000', 'cogs'),
            $a('5150', 'فروقات أسعار الشراء', 'Purchase price variance', 'expense', 'cogs', '5000', 'ppv'),
            $a('5160', 'مصاريف الشراء والشحن الوارد', 'Freight-in and purchase charges', 'expense', 'cogs', '5000', 'freight_in'),
            $a('5200', 'فروقات الجرد والتسويات', 'Inventory count variances', 'expense', 'cogs', '5000', 'inventory_adjustment'),
            $a('5210', 'خسائر المخزون (تالف / منتهٍ / إتلاف)', 'Inventory losses (damage / expiry / scrap)', 'expense', 'cogs', '5000', 'inventory_loss'),

            $h('6000', 'المصروفات التشغيلية', 'Operating expenses', 'expense'),
            $h('6100', 'مصاريف الأسطول والنقل', 'Fleet and transport', 'expense', '6000'),
            $a('6110', 'وقود', 'Fuel', 'expense', 'opex', '6100', 'fuel'),
            $a('6120', 'صيانة المركبات', 'Vehicle maintenance', 'expense', 'opex', '6100', 'maintenance'),
            $a('6130', 'رسوم طرق ومواقف ومصاريف أسطول أخرى', 'Tolls, parking and other fleet costs', 'expense', 'opex', '6100', 'fleet_other'),
            $a('6190', 'إهلاك المركبات والمعدات', 'Depreciation', 'expense', 'opex', '6100', 'depreciation'),
            $h('6200', 'مصاريف إدارية وعمومية', 'General and administrative', 'expense', '6000'),
            $a('6210', 'مصاريف إدارية', 'Administrative expenses', 'expense', 'opex', '6200', 'admin_expenses'),
            $a('6220', 'إيجارات ومرافق المستودعات', 'Warehouse rent and utilities', 'expense', 'opex', '6200'),
            $a('6230', 'رواتب وأجور', 'Salaries and wages', 'expense', 'opex', '6200'),
        ];
    }

    /** Every role the posting rules may ask for (used by the bootstrap check). */
    public const ROLES = ['ar', 'ap', 'inventory', 'inventory_in_transit', 'goods_in_transit', 'vat_input', 'vat_output', 'grni', 'accrued_expenses', 'cash', 'bank',
        'vehicles', 'accumulated_depreciation', 'capital', 'retained_earnings', 'opening_balance_equity', 'revenue', 'sales_returns', 'cogs', 'ppv', 'freight_in',
        'inventory_adjustment', 'inventory_loss', 'fuel', 'maintenance', 'fleet_other', 'depreciation', 'admin_expenses'];
}
