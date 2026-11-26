<?php

use App\Crm\CrmBootstrap;
use App\Support\PermissionSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «الفرص والعملاء المحتملون» — the sales pipeline (docs/odoo/WAVE2.md §6): stages, sales teams, lost reasons and tags,
 * the leads / opportunities themselves, their to-dos, their timeline (stage moves, won / lost / reopened, notes) and
 * the quotations made for them.
 *
 * Records that came from Odoo carry `source_system = 'odoo'` + `external_ref` (the Odoo id): while the section follows
 * Odoo they are Odoo's and OPS only adds what is its own (notes, to-dos, the customer a won opportunity became).
 * Nothing here is deleted: a stage, a reason, a tag or a team is archived, a to-do is cancelled, a lead is closed.
 */
return new class extends Migration
{
    private const TABLES = ['crm_lead_quotations', 'crm_lead_events', 'crm_activities', 'crm_lead_tags', 'crm_leads', 'crm_tags', 'crm_lost_reasons', 'crm_teams', 'crm_stages'];

    public function up(): void
    {
        Schema::create('crm_stages', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name_ar', 120);
            $t->string('name_en', 120);
            $t->integer('sequence')->default(0);            // the order of the columns of the board
            $t->boolean('is_won')->default(false);           // entering it wins the opportunity
            $t->boolean('fold')->default(false);             // shown folded on the board
            $t->decimal('probability', 5, 2)->nullable();    // the chance an opportunity gets when it enters the stage (null = left as it is)
            $t->text('requirements')->nullable();            // what must be true before an opportunity enters it
            $t->ulid('team_id')->nullable();                 // a stage of one team only (null = every team)
            $t->boolean('active')->default(true);
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'crm_stages_source_uq');
            $t->index(['active', 'sequence'], 'crm_stages_order_ix');
        });

        Schema::create('crm_teams', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name_ar', 120);
            $t->string('name_en', 120);
            $t->ulid('leader_id')->nullable();               // the OPS user who leads the team …
            $t->string('leader_name', 190)->nullable();      // … or the name Odoo gives when that person has no OPS user
            $t->integer('sequence')->default(0);
            $t->boolean('pipeline')->default(true);          // the team works opportunities (offered in the forms)
            $t->boolean('active')->default(true);
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'crm_teams_source_uq');
        });

        Schema::create('crm_lost_reasons', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name_ar', 190);
            $t->string('name_en', 190);
            $t->boolean('active')->default(true);
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'crm_lost_reasons_source_uq');
        });

        Schema::create('crm_tags', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name', 80);
            $t->smallInteger('color')->default(0);           // 0..11, the palette index people picked
            $t->boolean('active')->default(true);
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'crm_tags_source_uq');
            $t->index(['name'], 'crm_tags_name_ix');
        });

        Schema::create('crm_leads', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('number', 40)->unique();              // OPP-YYYY-NNNNN
            $t->string('name', 250);                         // what the opportunity is about
            $t->string('kind', 12)->default('opportunity');  // lead | opportunity
            $t->ulid('stage_id')->nullable();                // a lead made in OPS always stands in a stage; Odoo lets one stand in none
            $t->string('status', 8)->default('open');        // open | won | lost
            $t->boolean('archived')->default(false);         // put away without being lost (archived or deleted in Odoo)
            $t->smallInteger('priority')->default(0);        // 0 normal · 1 medium · 2 high · 3 very high
            $t->decimal('expected_revenue', 16, 2)->default(0);
            $t->decimal('probability', 5, 2)->default(0);    // 0..100
            // who it is
            $t->string('contact_name', 190)->nullable();
            $t->string('company_name', 190)->nullable();
            $t->string('job_title', 120)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('mobile', 40)->nullable();
            $t->string('email', 190)->nullable();
            $t->string('website', 500)->nullable();
            $t->string('street', 250)->nullable();
            $t->string('street2', 250)->nullable();
            $t->string('city', 120)->nullable();
            $t->string('region', 120)->nullable();
            $t->string('zip', 20)->nullable();
            $t->string('country', 120)->nullable();
            $t->string('vat_no', 40)->nullable();            // digits only: the identity a customer is recognised by
            $t->ulid('customer_id')->nullable();             // the OPS customer it belongs to / became
            $t->string('partner_ref', 40)->nullable();       // the Odoo partner it points at
            // who works it
            $t->ulid('owner_id')->nullable();                // the salesperson, an OPS user …
            $t->string('owner_name', 190)->nullable();       // … or kept by name when that person has no OPS user
            $t->ulid('team_id')->nullable();
            // where it came from
            $t->string('source', 120)->nullable();
            $t->string('medium', 120)->nullable();
            $t->string('campaign', 190)->nullable();
            $t->string('referred_by', 190)->nullable();
            $t->text('description')->nullable();
            // how it ended
            $t->ulid('lost_reason_id')->nullable();
            $t->text('lost_note')->nullable();
            $t->date('deadline')->nullable();                // expected closing
            // the earliest open to-do, kept here for the board, the sort and the "overdue" filter
            $t->date('next_activity_date')->nullable();
            $t->string('next_activity_type', 20)->nullable();
            $t->string('next_activity_summary', 250)->nullable();
            $t->dateTime('opened_at', 3)->nullable();        // given to a salesperson
            $t->dateTime('stage_changed_at', 3)->nullable();
            $t->dateTime('closed_at', 3)->nullable();        // won or lost
            $t->dateTime('converted_at', 3)->nullable();     // a person made / named its OPS customer
            $t->string('converted_by')->nullable();
            $t->json('extra')->nullable();                   // what Odoo holds and OPS has no column for
            $t->string('source_system', 20)->nullable();
            $t->string('external_ref', 40)->nullable();
            $t->dateTime('source_updated_at', 3)->nullable();
            $t->dateTime('source_deleted_at', 3)->nullable(); // it no longer exists in the system it came from
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->unique(['source_system', 'external_ref'], 'crm_leads_source_uq');
            $t->index(['status', 'archived', 'stage_id'], 'crm_leads_state_ix');
            $t->index(['owner_id', 'status'], 'crm_leads_owner_ix');
            $t->index(['team_id', 'status'], 'crm_leads_team_ix');
            $t->index(['customer_id'], 'crm_leads_customer_ix');
            $t->index(['next_activity_date'], 'crm_leads_next_ix');
            $t->index(['created_at'], 'crm_leads_created_ix');
            $t->index(['closed_at'], 'crm_leads_closed_ix');
        });

        Schema::create('crm_lead_tags', function (Blueprint $t) {
            $t->ulid('lead_id');
            $t->ulid('tag_id');
            $t->primary(['lead_id', 'tag_id']);
            $t->index(['tag_id'], 'crm_lead_tags_tag_ix');
        });

        // a to-do on a lead: done with a note, or cancelled — never removed
        Schema::create('crm_activities', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('lead_id');
            $t->string('type', 20)->default('todo');         // call | visit | meeting | email | message | sample | todo
            $t->string('summary', 250);
            $t->text('note')->nullable();
            $t->date('due_date');
            $t->ulid('user_id');                             // whose to-do it is
            $t->string('status', 10)->default('open');       // open | done | cancelled
            $t->dateTime('done_at', 3)->nullable();
            $t->string('done_by')->nullable();
            $t->text('done_note')->nullable();
            $t->dateTime('cancelled_at', 3)->nullable();
            $t->string('cancelled_by')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['lead_id', 'status', 'due_date'], 'crm_activities_lead_ix');
            $t->index(['user_id', 'status', 'due_date'], 'crm_activities_user_ix');
            $t->index(['status', 'due_date'], 'crm_activities_due_ix');
        });

        // the timeline of a lead: created · stage · won · lost · reopened · assigned · note · converted · quotation · activity · archived
        Schema::create('crm_lead_events', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('lead_id');
            $t->string('kind', 20);
            $t->ulid('from_stage_id')->nullable();
            $t->ulid('to_stage_id')->nullable();
            $t->text('note')->nullable();
            $t->json('data')->nullable();                    // the facts of the event: reason, customer, quotation, owner
            $t->ulid('user_id')->nullable();
            $t->string('username')->nullable();
            $t->string('origin', 20)->nullable();            // 'odoo' when the event happened there
            $t->dateTime('at', 3)->useCurrent();
            $t->index(['lead_id', 'at'], 'crm_lead_events_lead_ix');
        });

        Schema::create('crm_lead_quotations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('lead_id');
            $t->ulid('quotation_id');
            $t->string('created_by')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->unique(['quotation_id'], 'crm_lead_quotations_quotation_uq'); // a quotation answers one opportunity
            $t->index(['lead_id'], 'crm_lead_quotations_lead_ix');
        });

        $this->caseInsensitiveText();
        PermissionSync::ensure();   // page.crm · crm.manage with their default role grants, on a database that predates them
        CrmBootstrap::ensure();     // a first pipeline where the section does not follow Odoo
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
