<?php

namespace App\Insights\Support;

use Illuminate\Support\Facades\DB;

/** The section's own small settings and bookmarks (table `ins_settings`: key → JSON value). */
final class Settings
{
    /** The day up to which Odoo's invoices are the history (null = decided by SalesFacts::cutover()). */
    public const CUTOVER = 'history.cutover';

    /** Where the copy of Odoo's invoices stands (App\Insights\Services\OdooHistory). */
    public const HISTORY = 'history.state';

    public static function get(string $key, mixed $default = null): mixed
    {
        $raw = DB::table('ins_settings')->where('key', $key)->value('value');

        return $raw === null ? $default : json_decode((string) $raw, true);
    }

    public static function put(string $key, mixed $value, ?string $by = null): void
    {
        $row = ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_by' => $by, 'updated_at' => now()];
        if (DB::table('ins_settings')->where('key', $key)->exists()) {
            DB::table('ins_settings')->where('key', $key)->update($row);
        } else {
            DB::table('ins_settings')->insertOrIgnore(['key' => $key] + $row);
        }
    }
}
