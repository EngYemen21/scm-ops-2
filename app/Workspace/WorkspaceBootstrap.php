<?php

namespace App\Workspace;

use App\Integration\Odoo\Projection\OdooProjection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A first help desk for an installation whose workspace does not follow Odoo: the stages a ticket passes through, the
 * usual kinds of ticket and one support team — only into tables that are still empty, so it never adds to what people
 * (or Odoo) already put there. Where the section follows Odoo nothing is written: teams, stages and types come from the
 * copy (`php artisan scm:odoo-project workspace --apply`), and a second set beside them would only be noise.
 * Projects bring their own stages when they are created, and folders are made by the documents manager.
 *
 * Idempotent. Runs from the migration; `defaults()` is also what a test calls to have a desk to work on.
 */
final class WorkspaceBootstrap
{
    /** [Arabic, English, a ticket standing here is closed] in the order a ticket passes through them. */
    public const TICKET_STAGES = [
        ['جديدة', 'New', false], ['قيد المعالجة', 'In progress', false], ['بانتظار صاحب الطلب', 'Waiting for the requester', false],
        ['تم الحل', 'Solved', true], ['ملغاة', 'Cancelled', true],
    ];

    public const TICKET_TYPES = [['استفسار', 'Question'], ['مشكلة', 'Issue'], ['طلب خدمة', 'Service request'], ['شكوى', 'Complaint']];

    /** The columns a new project's board starts with: [Arabic, English, a task that enters it is done]. */
    public const TASK_STAGES = [['للتنفيذ', 'To do', false], ['قيد التنفيذ', 'In progress', false], ['مكتملة', 'Done', true]];

    /** @return array{stages:int, types:int, teams:int} what was added */
    public static function ensure(): array
    {
        if (! Schema::hasTable('ws_ticket_stages') || OdooProjection::owns('workspace')) {
            return ['stages' => 0, 'types' => 0, 'teams' => 0];
        }

        return self::defaults();
    }

    /** Fills the tables that are still empty, whatever the section follows. @return array{stages:int, types:int, teams:int} */
    public static function defaults(): array
    {
        $out = ['stages' => 0, 'types' => 0, 'teams' => 0];
        $now = now();
        $row = fn (array $values) => $values + ['id' => (string) Str::ulid(), 'active' => true, 'created_by' => 'system', 'created_at' => $now, 'updated_at' => $now];
        if (DB::table('ws_ticket_stages')->count() === 0) {
            foreach (self::TICKET_STAGES as $i => [$ar, $en, $closing]) {
                DB::table('ws_ticket_stages')->insert($row(['name_ar' => $ar, 'name_en' => $en, 'sequence' => ($i + 1) * 10, 'is_closing' => $closing]));
                $out['stages']++;
            }
        }
        if (DB::table('ws_ticket_types')->count() === 0) {
            foreach (self::TICKET_TYPES as $i => [$ar, $en]) {
                DB::table('ws_ticket_types')->insert($row(['name_ar' => $ar, 'name_en' => $en, 'sequence' => ($i + 1) * 10]));
                $out['types']++;
            }
        }
        if (DB::table('ws_ticket_teams')->count() === 0) {
            DB::table('ws_ticket_teams')->insert($row(['name_ar' => 'الدعم', 'name_en' => 'Support', 'visibility' => 'all', 'first_response_hours' => 4, 'resolution_hours' => 48, 'sequence' => 10]));
            $out['teams']++;
        }

        return $out;
    }
}
