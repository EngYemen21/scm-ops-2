<?php

use App\Support\PermissionSync;
use App\Workspace\WorkspaceBootstrap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «الدعم والمشاريع والمستندات» — the workspace (docs/odoo/WAVE3.md, P5): three small tools under one page.
 *
 *   tickets    ws_ticket_teams (+ members) · ws_ticket_stages (+ the teams a stage is for) · ws_ticket_types · ws_tickets
 *   projects   ws_projects (+ members) · ws_task_stages · ws_task_tags · ws_tasks (+ assignees, tags, checklist)
 *   documents  ws_folders · ws_doc_tags · ws_documents (+ tags)
 *   shared     ws_messages (the conversation of a ticket, the comments of a task, and what happened to them)
 *              ws_files    (a file on a ticket, a message, a task — or one VERSION of a document)
 *              ws_state    (what the section remembers between runs: how far the Odoo conversation was read)
 *
 * A record that came from Odoo carries `source_system = 'odoo'` + `external_ref` (the Odoo id): while the section
 * follows Odoo it is Odoo's and OPS only adds its own replies and comments (App\Workspace\Support\Owned).
 * A person is an OPS user (`…_id`) or, when Odoo names somebody who has no OPS user, a name (`…_name`).
 * Nothing here is deleted: a ticket, a project, a task, a folder or a document is archived, a file is taken off with
 * who did it, and a document keeps every version it ever had.
 */
return new class extends Migration
{
    private const TABLES = [
        'ws_state', 'ws_files', 'ws_messages',
        'ws_document_tags', 'ws_documents', 'ws_doc_tags', 'ws_folders',
        'ws_task_checklist', 'ws_task_tag_links', 'ws_task_assignees', 'ws_tasks', 'ws_task_tags', 'ws_task_stages', 'ws_project_members', 'ws_projects',
        'ws_tickets', 'ws_ticket_types', 'ws_ticket_stage_teams', 'ws_ticket_stages', 'ws_ticket_team_members', 'ws_ticket_teams',
    ];

    public function up(): void
    {
        $source = function (Blueprint $t, string $table) {
            $t->string('source_system', 20)->nullable();    // odoo | null (made in OPS)
            $t->string('external_ref', 60)->nullable();     // the id in the source system
            $t->unique(['source_system', 'external_ref'], "{$table}_source_uq");
        };
        $stamps = function (Blueprint $t) {
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
        };
        // somebody of a list (a member, an assignee): an OPS user, or the name Odoo gives for a person without one
        $person = function (Blueprint $t) {
            $t->ulid('user_id')->nullable();
            $t->string('person_name', 190)->nullable();
            $t->string('person_ref', 40)->nullable();       // that person's id in the source system
        };
        $archive = function (Blueprint $t) {
            $t->boolean('archived')->default(false);
            $t->dateTime('archived_at', 3)->nullable();
            $t->string('archived_by')->nullable();
            $t->text('archive_reason')->nullable();
        };
        // a link to another record of OPS, by what people call it: its kind and its number
        $record = function (Blueprint $t) {
            $t->string('ref_type', 30)->nullable();
            $t->string('ref_number', 80)->nullable();
        };

        // ───────────── tickets ─────────────

        Schema::create('ws_ticket_teams', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('name_ar', 120);
            $t->string('name_en', 120);
            $t->text('description')->nullable();
            $t->string('visibility', 10)->default('all');            // all (everybody who opens the section) | members
            $t->decimal('first_response_hours', 7, 2)->nullable();   // target: hours until the first answer (null = none)
            $t->decimal('resolution_hours', 7, 2)->nullable();       // target: hours until it is closed → a ticket's due date
            $t->ulid('default_assignee_id')->nullable();             // who gets a new ticket nobody was named for
            $t->integer('sequence')->default(0);
            $t->boolean('active')->default(true);
            $source($t, 'ws_ticket_teams');
            $stamps($t);
        });

        Schema::create('ws_ticket_team_members', function (Blueprint $t) use ($person) {
            $t->ulid('id')->primary();
            $t->ulid('team_id');
            $person($t);
            $t->index(['team_id'], 'ws_ticket_team_members_team_ix');
            $t->index(['user_id'], 'ws_ticket_team_members_user_ix');
        });

        Schema::create('ws_ticket_stages', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('name_ar', 120);
            $t->string('name_en', 120);
            $t->integer('sequence')->default(0);
            $t->boolean('is_closing')->default(false);               // a ticket that stands here is closed
            $t->boolean('active')->default(true);
            $source($t, 'ws_ticket_stages');
            $stamps($t);
            $t->index(['active', 'sequence'], 'ws_ticket_stages_order_ix');
        });

        // the teams a stage is for; a stage without a row here is for every team
        Schema::create('ws_ticket_stage_teams', function (Blueprint $t) {
            $t->ulid('stage_id');
            $t->ulid('team_id');
            $t->primary(['stage_id', 'team_id']);
            $t->index(['team_id'], 'ws_ticket_stage_teams_team_ix');
        });

        Schema::create('ws_ticket_types', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('name_ar', 120);
            $t->string('name_en', 120);
            $t->integer('sequence')->default(0);
            $t->boolean('active')->default(true);
            $source($t, 'ws_ticket_types');
            $stamps($t);
        });

        Schema::create('ws_tickets', function (Blueprint $t) use ($source, $archive, $record) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();                      // TKT-YYYY-NNNNN
            $t->string('subject', 250);
            $t->text('description')->nullable();
            $t->ulid('team_id');
            $t->ulid('stage_id')->nullable();
            $t->ulid('type_id')->nullable();
            $t->smallInteger('priority')->default(0);                // 0 normal · 1 medium · 2 high · 3 urgent
            $t->string('status', 10)->default('open');               // open | closed
            $archive($t);
            // who asked: an OPS customer, a member of staff, or somebody who is neither (free text)
            $t->string('requester_kind', 10)->default('staff');      // customer | staff | other
            $t->ulid('customer_id')->nullable();
            $t->ulid('requester_user_id')->nullable();
            $t->string('requester_name', 190)->nullable();
            $t->string('requester_email', 190)->nullable();
            $t->string('requester_phone', 40)->nullable();
            // who works it
            $t->ulid('assignee_id')->nullable();
            $t->string('assignee_name', 190)->nullable();
            // the team's targets, as moments
            $t->dateTime('due_at', 3)->nullable();                   // to be closed by
            $t->boolean('due_manual')->default(false);               // a person set the due date: the team's target no longer moves it
            $t->dateTime('first_response_due_at', 3)->nullable();
            $t->dateTime('first_response_at', 3)->nullable();        // the first reply of somebody who is not the requester
            // how it ended
            $t->dateTime('closed_at', 3)->nullable();
            $t->string('closed_by')->nullable();
            $t->text('resolution')->nullable();
            $record($t);
            $t->integer('message_count')->default(0);                // replies and notes (not the events)
            $t->dateTime('last_message_at', 3)->nullable();
            $t->string('source_number', 60)->nullable();             // the number people know it by in the source system
            $t->json('extra')->nullable();                           // what the source system holds and OPS has no column for
            $source($t, 'ws_tickets');
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->ulid('created_by_id')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['status', 'archived', 'team_id'], 'ws_tickets_state_ix');
            $t->index(['assignee_id', 'status'], 'ws_tickets_assignee_ix');
            $t->index(['requester_user_id'], 'ws_tickets_requester_ix');
            $t->index(['customer_id'], 'ws_tickets_customer_ix');
            $t->index(['due_at'], 'ws_tickets_due_ix');
            $t->index(['created_at'], 'ws_tickets_created_ix');
        });

        // ───────────── projects and tasks ─────────────

        Schema::create('ws_projects', function (Blueprint $t) use ($source, $archive) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();                      // PRJ-YYYY-NNNNN
            $t->string('name', 250);
            $t->text('description')->nullable();
            $t->string('status', 10)->default('active');             // active | on_hold | done
            $archive($t);
            $t->string('visibility', 10)->default('all');            // all | members
            $t->ulid('manager_id')->nullable();
            $t->string('manager_name', 190)->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->json('extra')->nullable();
            $source($t, 'ws_projects');
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['status', 'archived'], 'ws_projects_state_ix');
            $t->index(['manager_id'], 'ws_projects_manager_ix');
        });

        Schema::create('ws_project_members', function (Blueprint $t) use ($person) {
            $t->ulid('id')->primary();
            $t->ulid('project_id');
            $person($t);
            $t->index(['project_id'], 'ws_project_members_project_ix');
            $t->index(['user_id'], 'ws_project_members_user_ix');
        });

        // the columns of a project's board
        Schema::create('ws_task_stages', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->ulid('project_id');
            $t->string('name_ar', 120);
            $t->string('name_en', 120);
            $t->integer('sequence')->default(0);
            $t->boolean('is_closing')->default(false);               // a task that enters it is done
            $t->boolean('active')->default(true);
            $source($t, 'ws_task_stages');
            $stamps($t);
            $t->index(['project_id', 'active', 'sequence'], 'ws_task_stages_project_ix');
        });

        Schema::create('ws_task_tags', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('name', 80);
            $t->smallInteger('color')->default(0);                   // 0..11, the palette index people picked
            $t->boolean('active')->default(true);
            $source($t, 'ws_task_tags');
            $stamps($t);
        });

        Schema::create('ws_tasks', function (Blueprint $t) use ($source, $archive, $record) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();                      // TSK-YYYY-NNNNN
            $t->ulid('project_id')->nullable();                      // null = a personal task: its creator and its assignees only
            $t->ulid('stage_id')->nullable();
            $t->string('title', 250);
            $t->text('description')->nullable();
            $t->smallInteger('priority')->default(0);                // 0 normal · 1 medium · 2 high · 3 urgent
            $t->string('status', 10)->default('open');               // open | done | cancelled
            $archive($t);
            $t->date('deadline')->nullable();
            $record($t);
            $t->dateTime('done_at', 3)->nullable();
            $t->string('done_by')->nullable();
            $t->dateTime('cancelled_at', 3)->nullable();
            $t->string('cancelled_by')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->dateTime('stage_changed_at', 3)->nullable();
            $t->integer('comment_count')->default(0);
            $t->json('extra')->nullable();
            $source($t, 'ws_tasks');
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->ulid('created_by_id')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['project_id', 'status', 'archived', 'stage_id'], 'ws_tasks_board_ix');
            $t->index(['status', 'deadline'], 'ws_tasks_deadline_ix');
            $t->index(['created_by_id'], 'ws_tasks_creator_ix');
            $t->index(['created_at'], 'ws_tasks_created_ix');
        });

        Schema::create('ws_task_assignees', function (Blueprint $t) use ($person) {
            $t->ulid('id')->primary();
            $t->ulid('task_id');
            $person($t);
            $t->index(['task_id'], 'ws_task_assignees_task_ix');
            $t->index(['user_id', 'task_id'], 'ws_task_assignees_user_ix');
        });

        Schema::create('ws_task_tag_links', function (Blueprint $t) {
            $t->ulid('task_id');
            $t->ulid('tag_id');
            $t->primary(['task_id', 'tag_id']);
            $t->index(['tag_id'], 'ws_task_tag_links_tag_ix');
        });

        Schema::create('ws_task_checklist', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('task_id');
            $t->string('text', 500);
            $t->boolean('done')->default(false);
            $t->string('done_by')->nullable();
            $t->dateTime('done_at', 3)->nullable();
            $t->integer('position')->default(0);
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['task_id', 'position'], 'ws_task_checklist_task_ix');
        });

        // ───────────── documents ─────────────

        Schema::create('ws_folders', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->ulid('parent_id')->nullable();
            $t->string('name', 190);
            $t->text('description')->nullable();
            $t->integer('sequence')->default(0);
            // who may read / write, by role: `all` = everybody who opens the section, `roles` = the listed roles only
            // (an empty list = the documents manager alone). Writing includes reading.
            $t->string('read_scope', 8)->default('all');
            $t->json('read_roles')->nullable();
            $t->string('write_scope', 8)->default('all');
            $t->json('write_roles')->nullable();
            $t->boolean('own_read')->default(false);                 // a reader sees only the documents he owns
            $t->boolean('own_write')->default(false);                // a writer changes only the documents he owns
            $t->boolean('archived')->default(false);
            $t->dateTime('archived_at', 3)->nullable();
            $t->string('archived_by')->nullable();
            $t->json('extra')->nullable();
            $source($t, 'ws_folders');
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['parent_id'], 'ws_folders_parent_ix');
        });

        Schema::create('ws_doc_tags', function (Blueprint $t) use ($source, $stamps) {
            $t->ulid('id')->primary();
            $t->string('name', 120);
            $t->string('group_name', 120)->nullable();               // the heading the tag stands under (status, kind of paper…)
            $t->smallInteger('color')->default(0);
            $t->ulid('folder_id')->nullable();                       // a tag of one folder (null = of every folder)
            $t->integer('sequence')->default(0);
            $t->boolean('active')->default(true);
            $source($t, 'ws_doc_tags');
            $stamps($t);
        });

        Schema::create('ws_documents', function (Blueprint $t) use ($source, $archive, $record) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();                      // DOC-YYYY-NNNNN
            $t->ulid('folder_id');
            $t->string('name', 250);
            $t->string('kind', 6)->default('file');                  // file | link
            $t->text('url')->nullable();                             // of a link
            $t->text('description')->nullable();
            $t->ulid('owner_id')->nullable();
            $t->string('owner_name', 190)->nullable();
            $t->ulid('current_file_id')->nullable();                 // ws_files.id of the version in force
            $t->integer('version')->default(0);                      // its number (0 = no content yet)
            $t->string('mime', 150)->nullable();                     // of the version in force, for the list
            $t->bigInteger('size')->default(0);
            $record($t);
            $archive($t);
            $t->json('extra')->nullable();
            $source($t, 'ws_documents');
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['folder_id', 'archived'], 'ws_documents_folder_ix');
            $t->index(['owner_id'], 'ws_documents_owner_ix');
            $t->index(['ref_type', 'ref_number'], 'ws_documents_record_ix');
            $t->index(['updated_at'], 'ws_documents_updated_ix');
        });

        Schema::create('ws_document_tags', function (Blueprint $t) {
            $t->ulid('document_id');
            $t->ulid('tag_id');
            $t->primary(['document_id', 'tag_id']);
            $t->index(['tag_id'], 'ws_document_tags_tag_ix');
        });

        // ───────────── shared ─────────────

        // the conversation of a ticket (replies, internal notes), the comments of a task, and what happened to either
        Schema::create('ws_messages', function (Blueprint $t) use ($source) {
            $t->ulid('id')->primary();
            $t->string('thread_type', 8);                            // ticket | task
            $t->ulid('thread_id');
            $t->string('kind', 8);                                   // reply | note | comment | event
            $t->string('event', 20)->nullable();                     // created · assigned · moved · closed · reopened · …
            $t->text('body')->nullable();
            $t->json('data')->nullable();                            // the facts of an event: from / to, the reason
            $t->ulid('author_id')->nullable();
            $t->string('author_name', 190)->nullable();
            $source($t, 'ws_messages');
            $t->dateTime('at', 3)->useCurrent();
            $t->dateTime('removed_at', 3)->nullable();               // it no longer exists in the system it came from
            $t->index(['thread_type', 'thread_id', 'at'], 'ws_messages_thread_ix');
        });

        // A file of the section. Its bytes are kept by FileStore (`storage = ops`: object storage when configured, the
        // database otherwise) or — for an attachment that came from Odoo — by the Odoo backup (`storage = odoo`: table
        // odoo_files, by the attachment id in `external_ref`; linked, never copied). A file is read only through
        // GET /api/workspace/files/{id}, which asks the permission of what it hangs on.
        Schema::create('ws_files', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('owner_type', 10);                            // ticket | message | task | document
            $t->ulid('owner_id');
            $t->integer('version_no')->nullable();                   // of a document: which version this file is
            $t->string('note', 500)->nullable();                     // of a version: what changed
            $t->string('name', 255);
            $t->string('mime', 150)->nullable();
            $t->bigInteger('size')->default(0);
            $t->string('storage', 6)->default('ops');                // ops | odoo
            $t->string('storage_key', 255)->nullable();
            $t->string('storage_status', 12)->nullable();            // stored | uploaded (FileStore::KEPT)
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 60)->nullable();              // the attachment id in the source system
            $t->string('created_by')->nullable();
            $t->ulid('created_by_id')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('removed_at', 3)->nullable();
            $t->string('removed_by')->nullable();
            $t->string('remove_reason', 500)->nullable();
            $t->index(['owner_type', 'owner_id'], 'ws_files_owner_ix');
            $t->index(['source_system', 'external_ref'], 'ws_files_source_ix');
        });

        Schema::create('ws_state', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->text('value')->nullable();
            $t->dateTime('updated_at', 3)->nullable();
        });

        $this->caseInsensitiveText();
        PermissionSync::ensure();        // page.workspace · ticket.manage · project.manage · doc.manage, on a database that predates them
        WorkspaceBootstrap::ensure();    // a first help desk where the section does not follow Odoo
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * PostgreSQL only — a no-op on MySQL. The application compares text without regard to letter case
     * (2026_09_17_000000_pgsql_compatibility): the text and id columns of these tables get the same CITEXT type as the
     * tables they are joined with. Columns already converted report USER-DEFINED and are left alone.
     */
    private function caseInsensitiveText(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        $marks = implode(', ', array_fill(0, count(self::TABLES), '?'));
        $columns = DB::select("SELECT table_name, column_name FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name IN ({$marks}) AND data_type IN ('character', 'character varying', 'text')
            ORDER BY table_name, ordinal_position", self::TABLES);
        $byTable = [];
        foreach ($columns as $c) {
            $byTable[$c->table_name][] = sprintf('ALTER COLUMN "%1$s" TYPE citext USING rtrim("%1$s")::citext', $c->column_name);
        }
        foreach ($byTable as $table => $changes) {
            DB::statement(sprintf('ALTER TABLE "%s" %s', $table, implode(', ', $changes)));
        }
    }
};
