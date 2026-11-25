<?php

namespace App\Pos;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What the section needs on its first day: the Saudi riyal coins and notes the cash is counted in, when the table is
 * empty. Odoo's own denominations (`pos.bill`) are adopted by value by the projection — never doubled.
 */
final class PosBootstrap
{
    /** value => [kind, label] */
    public const SAR = [
        '0.05' => ['coin', '5 هللات'], '0.10' => ['coin', '10 هللات'], '0.25' => ['coin', '25 هللة'], '0.50' => ['coin', '50 هللة'],
        '1.00' => ['coin', '1 ريال'], '2.00' => ['coin', '2 ريال'], '5.00' => ['note', '5 ريال'], '10.00' => ['note', '10 ريال'], '20.00' => ['note', '20 ريال'],
        '50.00' => ['note', '50 ريال'], '100.00' => ['note', '100 ريال'], '200.00' => ['note', '200 ريال'], '500.00' => ['note', '500 ريال'],
    ];

    public static function ensure(): void
    {
        if (! Schema::hasTable('pos_denominations') || DB::table('pos_denominations')->exists()) {
            return;
        }
        $now = now();
        foreach (self::SAR as $value => [$kind, $label]) {
            DB::table('pos_denominations')->insert(['id' => (string) Str::ulid(), 'value' => $value, 'kind' => $kind, 'label' => $label, 'active' => true, 'created_by' => 'system', 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
