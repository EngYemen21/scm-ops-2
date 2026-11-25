<?php

namespace App\Tax\Support;

/**
 * The lines of the Saudi VAT return, by the number each carries on the authority's form (the same numbers the tax
 * report of the Saudi localisation of Odoo uses for its tags: "+1. Standard Rates 15% (Base)" … "+11. Exempt
 * Purchases (Base)"). Lines 6, 12, 13 and 16 are sums; 14 are the corrections of earlier periods; 15 is the credit
 * brought forward. A tax code feeds exactly one line — or none (out of scope, withholding).
 */
final class Boxes
{
    public const CORRECTIONS = 14;

    /** number → [key, side, Arabic, English, the line carries a VAT amount] */
    public const LINES = [
        1 => ['key' => 'std_sales', 'side' => 'sale', 'ar' => 'المبيعات الخاضعة للنسبة الأساسية', 'en' => 'Standard rated sales', 'vat' => true],
        2 => ['key' => 'citizen_services', 'side' => 'sale', 'ar' => 'المبيعات للمواطنين (الخدمات الصحية الخاصة، التعليم الأهلي الخاص، المسكن الأول)', 'en' => 'Private healthcare / private education / first house sales to citizens', 'vat' => false],
        3 => ['key' => 'zero_domestic', 'side' => 'sale', 'ar' => 'المبيعات المحلية الخاضعة للنسبة الصفرية', 'en' => 'Zero rated domestic sales', 'vat' => false],
        4 => ['key' => 'exports', 'side' => 'sale', 'ar' => 'الصادرات', 'en' => 'Exports', 'vat' => false],
        5 => ['key' => 'exempt_sales', 'side' => 'sale', 'ar' => 'المبيعات المعفاة', 'en' => 'Exempt sales', 'vat' => false],
        7 => ['key' => 'std_purchases', 'side' => 'purchase', 'ar' => 'المشتريات الخاضعة للنسبة الأساسية', 'en' => 'Standard rated domestic purchases', 'vat' => true],
        8 => ['key' => 'imports_customs', 'side' => 'purchase', 'ar' => 'الاستيرادات الخاضعة للضريبة التي تُدفع في الجمارك', 'en' => 'Imports subject to VAT paid at customs', 'vat' => true],
        9 => ['key' => 'imports_rc', 'side' => 'purchase', 'ar' => 'الاستيرادات الخاضعة للضريبة بآلية الاحتساب العكسي', 'en' => 'Imports subject to VAT accounted for through the reverse charge mechanism', 'vat' => true],
        10 => ['key' => 'zero_purchases', 'side' => 'purchase', 'ar' => 'المشتريات الخاضعة للنسبة الصفرية', 'en' => 'Zero rated purchases', 'vat' => false],
        11 => ['key' => 'exempt_purchases', 'side' => 'purchase', 'ar' => 'المشتريات المعفاة', 'en' => 'Exempt purchases', 'vat' => false],
    ];

    /** The lines the form computes or takes from a person. */
    public const SUMS = [
        6 => ['key' => 'total_sales', 'ar' => 'إجمالي المبيعات', 'en' => 'Total sales'],
        12 => ['key' => 'total_purchases', 'ar' => 'إجمالي المشتريات', 'en' => 'Total purchases'],
        13 => ['key' => 'vat_due_period', 'ar' => 'إجمالي ضريبة القيمة المضافة المستحقة عن الفترة الحالية', 'en' => 'Total VAT due for current period'],
        14 => ['key' => 'corrections', 'ar' => 'تصحيحات من الفترات السابقة (بين ±5,000 ريال)', 'en' => 'Corrections from previous period (between SAR ±5,000)'],
        15 => ['key' => 'credit_carried', 'ar' => 'ضريبة القيمة المضافة المرحَّلة من الفترات السابقة', 'en' => 'VAT credit carried forward from previous period(s)'],
        16 => ['key' => 'net_due', 'ar' => 'صافي الضريبة المستحقة (أو المستردة)', 'en' => 'Net VAT due (or reclaimed)'],
    ];

    /** What a tax code of a use and a kind may feed. `null` = it is not on the return. */
    public const ALLOWED = [
        'sale' => ['standard' => [1], 'zero' => [3, 4, 2], 'exempt' => [5], 'out_of_scope' => [null]],
        'purchase' => ['standard' => [7], 'import' => [8], 'reverse_charge' => [9], 'zero' => [10], 'exempt' => [11], 'out_of_scope' => [null], 'withholding' => [null]],
    ];

    public const KINDS = [
        'standard' => ['النسبة الأساسية', 'Standard rated'], 'zero' => ['النسبة الصفرية', 'Zero rated'], 'exempt' => ['معفاة', 'Exempt'],
        'out_of_scope' => ['خارج نطاق الضريبة', 'Out of scope'], 'reverse_charge' => ['احتساب عكسي', 'Reverse charge'],
        'import' => ['استيراد (تُدفع في الجمارك)', 'Import (paid at customs)'], 'withholding' => ['ضريبة استقطاع', 'Withholding tax'],
    ];

    /** The corrections a later return may take without amending the earlier one (the form says so on its line 14). */
    public const CORRECTION_LIMIT_MINOR = 500000;

    public static function side(int $box): ?string
    {
        return self::LINES[$box]['side'] ?? null;
    }

    public static function exists(?int $box): bool
    {
        return $box !== null && isset(self::LINES[$box]);
    }

    /** May a code of this use and kind feed this line? */
    public static function allowed(string $use, string $kind, ?int $box): bool
    {
        return in_array($box, self::ALLOWED[$use][$kind] ?? [], true);
    }

    /** The line a code of this use and kind feeds when nobody says otherwise. */
    public static function natural(string $use, string $kind): ?int
    {
        return self::ALLOWED[$use][$kind][0] ?? null;
    }

    /** The kind of tax the tag of a line stands for (how a tax of Odoo is read). */
    public static function kindOf(int $box): ?string
    {
        return match ($box) {
            1, 7 => 'standard', 2, 3, 4, 10 => 'zero', 5, 11 => 'exempt', 8 => 'import', 9 => 'reverse_charge', default => null,
        };
    }

    /** The lines as the screens list them. */
    public static function catalogue(): array
    {
        $out = [];
        foreach (self::LINES as $no => $l) {
            $out[] = ['box' => $no, 'key' => $l['key'], 'side' => $l['side'], 'ar' => $l['ar'], 'en' => $l['en'], 'vat' => $l['vat'], 'sum' => false];
        }
        foreach (self::SUMS as $no => $l) {
            $out[] = ['box' => $no, 'key' => $l['key'], 'side' => $no === 6 ? 'sale' : ($no === 12 ? 'purchase' : null), 'ar' => $l['ar'], 'en' => $l['en'], 'vat' => true, 'sum' => true];
        }
        usort($out, fn ($a, $b) => $a['box'] <=> $b['box']);

        return $out;
    }
}
