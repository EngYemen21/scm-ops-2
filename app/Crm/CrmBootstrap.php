<?php

namespace App\Crm;

use App\Crm\Support\Owned;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A first pipeline for an installation whose section does not follow Odoo: five stages, the usual lost reasons and one
 * sales team — only into tables that are still empty, so it never adds to what people (or Odoo) already put there.
 * Where the section follows Odoo nothing is written: the stages, teams and reasons come from the copy
 * (`php artisan scm:odoo-project crm --apply`), and a second set beside them would only be noise on the board.
 *
 * Idempotent. Runs from the migration; `defaults()` is also what a test calls to have a pipeline to work on.
 */
final class CrmBootstrap
{
    /** [Arabic, English, is won, default probability] in the order of the board. */
    public const STAGES = [
        ['جديدة', 'New', false, 10], ['زيارة', 'Visit', false, 25], ['مؤهَّلة', 'Qualified', false, 50], ['عرض سعر', 'Proposition', false, 75], ['مكسوبة', 'Won', true, 100],
    ];

    public const LOST_REASONS = [
        ['السعر مرتفع', 'Too expensive'], ['اختار موردًا آخر', 'Chose another supplier'], ['غير مهتم', 'Not interested'], ['غير مستهدف', 'Not a target customer'],
        ['المنتج المطلوب غير متوفر', 'Requested product not available'], ['تعذّر الوصول إليه', 'Could not be reached'], ['مكرر', 'Duplicate'],
    ];

    /** @return array{stages:int, reasons:int, teams:int} what was added */
    public static function ensure(): array
    {
        if (! Schema::hasTable('crm_stages') || Owned::following()) {
            return ['stages' => 0, 'reasons' => 0, 'teams' => 0];
        }

        return self::defaults();
    }

    /** Fills the tables that are still empty, whatever the section follows. @return array{stages:int, reasons:int, teams:int} */
    public static function defaults(): array
    {
        $out = ['stages' => 0, 'reasons' => 0, 'teams' => 0];
        $now = now();
        if (DB::table('crm_stages')->count() === 0) {
            foreach (self::STAGES as $i => [$ar, $en, $won, $probability]) {
                DB::table('crm_stages')->insert(['id' => (string) Str::ulid(), 'name_ar' => $ar, 'name_en' => $en, 'sequence' => ($i + 1) * 10, 'is_won' => $won, 'fold' => false,
                    'probability' => $probability, 'active' => true, 'created_by' => 'system', 'created_at' => $now, 'updated_at' => $now]);
                $out['stages']++;
            }
        }
        if (DB::table('crm_lost_reasons')->count() === 0) {
            foreach (self::LOST_REASONS as [$ar, $en]) {
                DB::table('crm_lost_reasons')->insert(['id' => (string) Str::ulid(), 'name_ar' => $ar, 'name_en' => $en, 'active' => true, 'created_by' => 'system', 'created_at' => $now, 'updated_at' => $now]);
                $out['reasons']++;
            }
        }
        if (DB::table('crm_teams')->count() === 0) {
            DB::table('crm_teams')->insert(['id' => (string) Str::ulid(), 'name_ar' => 'المبيعات', 'name_en' => 'Sales', 'sequence' => 10, 'pipeline' => true, 'active' => true,
                'created_by' => 'system', 'created_at' => $now, 'updated_at' => $now]);
            $out['teams']++;
        }

        return $out;
    }
}
