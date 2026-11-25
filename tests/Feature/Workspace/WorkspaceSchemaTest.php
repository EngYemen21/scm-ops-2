<?php

namespace Tests\Feature\Workspace;

use App\Workspace\Models\TicketStage;
use App\Workspace\Models\TicketTeam;
use App\Workspace\Models\TicketType;
use App\Workspace\WorkspaceBootstrap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\ApiTestCase;

/** The tables of the section exist after a fresh build, with the first help desk the migration creates. */
class WorkspaceSchemaTest extends ApiTestCase
{
    public function test_the_migration_builds_the_section_and_a_first_help_desk(): void
    {
        foreach ([
            'ws_ticket_teams', 'ws_ticket_team_members', 'ws_ticket_stages', 'ws_ticket_stage_teams', 'ws_ticket_types', 'ws_tickets',
            'ws_projects', 'ws_project_members', 'ws_task_stages', 'ws_task_tags', 'ws_tasks', 'ws_task_assignees', 'ws_task_tag_links', 'ws_task_checklist',
            'ws_folders', 'ws_doc_tags', 'ws_documents', 'ws_document_tags', 'ws_messages', 'ws_files', 'ws_state',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "table {$table} is missing");
        }
        // every table Odoo's records are projected into says where a record came from
        foreach (['ws_ticket_teams', 'ws_ticket_stages', 'ws_ticket_types', 'ws_tickets', 'ws_projects', 'ws_task_stages', 'ws_task_tags', 'ws_tasks', 'ws_folders', 'ws_doc_tags', 'ws_documents', 'ws_messages', 'ws_files'] as $table) {
            $this->assertSame(['source_system', 'external_ref'], array_values(array_intersect(Schema::getColumnListing($table), ['source_system', 'external_ref'])), $table);
        }
        // the permissions of the section are in place and granted as config/scm.php says
        foreach (['page.workspace', 'ticket.manage', 'project.manage', 'doc.manage'] as $permission) {
            $this->assertTrue(DB::table('permissions')->where('key', $permission)->exists(), $permission);
        }

        // where the section follows Odoo the desk comes from the copy: the migration adds nothing of its own
        config(['odoo.enabled' => true, 'odoo.project.workspace' => true]);
        $this->assertSame(['stages' => 0, 'types' => 0, 'teams' => 0], WorkspaceBootstrap::ensure());

        // where it does not: a first desk — but only into tables that are still empty (the stories of this run may have
        // filled them already: what people or Odoo put there is never added to)
        config(['odoo.enabled' => false]);
        $had = [TicketStage::count(), TicketType::count(), TicketTeam::count()];
        $this->assertSame(['stages' => $had[0] ? 0 : count(WorkspaceBootstrap::TICKET_STAGES), 'types' => $had[1] ? 0 : count(WorkspaceBootstrap::TICKET_TYPES), 'teams' => $had[2] ? 0 : 1], WorkspaceBootstrap::ensure());
        $this->assertGreaterThan(0, min(TicketStage::count(), TicketType::count(), TicketTeam::count()), 'either way a desk exists now');
        // the path the first desk starts with: it begins open and it can close
        $this->assertFalse(WorkspaceBootstrap::TICKET_STAGES[0][2], 'a ticket starts in a stage that is open');
        $this->assertContains(true, array_column(WorkspaceBootstrap::TICKET_STAGES, 2));
        $this->assertSame([false, false, true], array_column(WorkspaceBootstrap::TASK_STAGES, 2), 'a new project\'s board ends in a column that finishes a task');
        if (! $had[0]) {
            $stages = TicketStage::query()->ordered()->get();
            $this->assertSame(array_column(WorkspaceBootstrap::TICKET_STAGES, 1), $stages->pluck('name_en')->all());
            $this->assertNotNull(TicketTeam::firstOrFail()->resolution_hours, 'the first team gives its tickets a due date');
        }

        // idempotent: a second call adds nothing
        $before = [TicketStage::count(), TicketType::count(), TicketTeam::count()];
        $this->assertSame(['stages' => 0, 'types' => 0, 'teams' => 0], WorkspaceBootstrap::ensure());
        $this->assertSame($before, [TicketStage::count(), TicketType::count(), TicketTeam::count()]);
    }
}
