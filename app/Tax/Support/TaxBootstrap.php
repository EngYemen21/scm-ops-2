<?php

namespace App\Tax\Support;

use App\Tax\Zatca\Codes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The first dictionary of the section, on a database that has none: the VAT group and the tax codes every OPS
 * document of the Kingdom needs to stand on a line of the return — sales at the standard rate and exports, purchases
 * at the standard rate, imports paid at customs, imports under the reverse charge, zero-rated and exempt purchases.
 * The two standard-rate codes are the defaults of their use (an OPS document carries a percentage, not a code).
 *
 * A sale that is zero-rated inside the Kingdom or exempt is NOT made here: the e-invoice must print the authority's
 * reason, and which reason applies is a decision of the taxpayer (medicines, qualifying metals, financial services…).
 *
 * Idempotent and safe on a database that follows Odoo: it writes only into an EMPTY table, and the projection adopts
 * these codes when Odoo holds the same treatment (same use, kind, rate and line) instead of making a second one.
 */
final class TaxBootstrap
{
    public const CODES = [
        // code, use, kind, rate, box, Arabic, English, default, exemption reason
        ['S-STD15', 'sale', 'standard', '15', 1, 'مبيعات خاضعة للنسبة الأساسية 15%', 'Standard rated sales 15%', true, null],
        ['S-EXP0', 'sale', 'zero', '0', 4, 'صادرات (نسبة صفرية)', 'Exports (zero rated)', false, 'VATEX-SA-32'],
        ['P-STD15', 'purchase', 'standard', '15', 7, 'مشتريات خاضعة للنسبة الأساسية 15%', 'Standard rated purchases 15%', true, null],
        ['P-IMP15', 'purchase', 'import', '15', 8, 'استيراد تُدفع ضريبته في الجمارك 15%', 'Imports, VAT paid at customs 15%', false, null],
        ['P-RC15', 'purchase', 'reverse_charge', '15', 9, 'استيراد بآلية الاحتساب العكسي 15%', 'Imports under the reverse charge 15%', false, null],
        ['P-ZERO0', 'purchase', 'zero', '0', 10, 'مشتريات خاضعة للنسبة الصفرية', 'Zero rated purchases', false, null],
        ['P-EXM0', 'purchase', 'exempt', '0', 11, 'مشتريات معفاة', 'Exempt purchases', false, null],
    ];

    public static function ensure(): void
    {
        if (! Schema::hasTable('tax_codes') || DB::table('tax_codes')->exists()) {
            return;
        }
        $now = now()->format('Y-m-d H:i:s.v');
        $group = DB::table('tax_groups')->where('name_en', 'VAT')->value('id');
        if (! $group) {
            $group = (string) Str::ulid();
            DB::table('tax_groups')->insert(['id' => $group, 'name_ar' => 'ضريبة القيمة المضافة', 'name_en' => 'VAT', 'sequence' => 10, 'active' => true, 'created_by' => 'system', 'created_at' => $now, 'updated_at' => $now]);
        }
        $rows = [];
        foreach (self::CODES as $i => [$code, $use, $kind, $rate, $box, $ar, $en, $default, $reason]) {
            $rows[] = [
                'id' => (string) Str::ulid(), 'code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'use' => $use, 'kind' => $kind, 'rate' => $rate, 'return_box' => $box,
                'category_code' => Codes::category($kind), 'exemption_reason_code' => $reason, 'exemption_reason_ar' => $reason ? Codes::EXEMPTIONS[$reason][2] : null,
                'exemption_reason_en' => $reason ? Codes::EXEMPTIONS[$reason][1] : null, 'scope' => null, 'price_include' => false, 'sequence' => ($i + 1) * 10,
                'is_default' => $default, 'active' => true, 'group_id' => $group, 'created_by' => 'system', 'updated_by' => 'system', 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('tax_codes')->insert($rows);
    }
}
