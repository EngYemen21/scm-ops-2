<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odoo → OPS live mirror (docs/odoo/ARCHITECTURE.ar.md). Its own `odoo_*` tables: the raw copy of what Odoo holds,
 * kept current by reading only. Nothing here is written back to Odoo, and no OPS table is touched by the mirror
 * itself — the projections that feed OPS documents read the change feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // one row per Odoo model: what is mirrored, how far the copy got, when it was last looked at
        Schema::create('odoo_models', function (Blueprint $t) {
            $t->id();
            $t->string('model', 120)->unique();
            $t->string('label', 190)->nullable();              // Odoo's own name of the model
            $t->string('status', 20)->default('new');           // new | active | paused | skipped | error
            $t->string('reason', 190)->nullable();              // why it is skipped or paused
            $t->boolean('has_write_date')->default(true);
            $t->boolean('has_active')->default(false);
            $t->json('fields')->nullable();                     // mirrored field → type
            $t->json('skipped_fields')->nullable();             // field → why it is not copied
            $t->integer('priority')->default(1000);             // lower = earlier; < 1000 = synchronised on every run
            $t->integer('page_size')->default(500);
            $t->bigInteger('remote_count')->nullable();
            $t->bigInteger('mirrored_count')->default(0);
            // the first full copy walks the ids upwards and can stop and resume
            $t->string('full_state', 20)->default('pending');   // pending | running | done
            $t->bigInteger('full_cursor_id')->default(0);
            $t->string('full_high_water', 30)->nullable();      // newest write_date in Odoo when the copy started
            $t->dateTime('full_started_at', 3)->nullable();
            $t->dateTime('full_finished_at', 3)->nullable();
            // afterwards: everything written since the cursor
            $t->string('cursor_write_date', 30)->nullable();    // Odoo time (UTC), as Odoo prints it
            // the window being read right now (a long one survives the end of a run and is resumed)
            $t->string('window_since', 30)->nullable();
            $t->string('window_high', 30)->nullable();
            $t->bigInteger('window_cursor_id')->default(0);
            $t->boolean('window_deep')->default(false);
            $t->dateTime('last_synced_at', 3)->nullable();
            $t->dateTime('last_deep_at', 3)->nullable();
            $t->dateTime('last_changed_at', 3)->nullable();
            // deletions are found by comparing ids
            $t->bigInteger('deletion_cursor_id')->default(0);
            $t->dateTime('last_deletion_check_at', 3)->nullable();
            $t->integer('consecutive_errors')->default(0);
            $t->text('last_error')->nullable();
            $t->dateTime('next_attempt_at', 3)->nullable();
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index(['status', 'priority'], 'odoo_models_status_ix');
        });

        // the copy itself: one row per Odoo record, its values as Odoo returned them
        Schema::create('odoo_records', function (Blueprint $t) {
            $t->id();
            $t->string('model', 120);
            $t->bigInteger('odoo_id');
            $t->string('display_name', 255)->nullable();
            $t->string('write_date', 30)->nullable();           // Odoo time (UTC)
            $t->boolean('active')->nullable();                   // null = the model has no archive flag
            $t->jsonb('data');
            $t->string('hash', 40);                              // of the values: an unchanged record costs no write
            $t->integer('version')->default(1);                  // how many different states of it were seen
            $t->dateTime('first_seen_at', 3);
            $t->dateTime('changed_at', 3);                       // last time its values changed here
            $t->dateTime('deleted_at', 3)->nullable();           // it no longer exists in Odoo
            $t->unique(['model', 'odoo_id'], 'odoo_records_model_id_uq');
            $t->index(['model', 'changed_at'], 'odoo_records_changed_ix');
        });

        // what happened in Odoo, in the order OPS learned it — the feed the OPS projections consume
        Schema::create('odoo_changes', function (Blueprint $t) {
            $t->id();
            $t->string('model', 120);
            $t->bigInteger('odoo_id');
            $t->string('kind', 12);                              // created | updated | archived | restored | deleted
            $t->json('fields')->nullable();                      // names of the values that changed
            $t->string('display_name', 255)->nullable();
            $t->string('write_date', 30)->nullable();
            $t->bigInteger('run_id')->nullable();
            $t->dateTime('seen_at', 3);
            $t->dateTime('processed_at', 3)->nullable();         // consumed by the projections
            $t->index(['model', 'odoo_id'], 'odoo_changes_record_ix');
            $t->index(['model', 'processed_at', 'id'], 'odoo_changes_pending_ix'); // what a projection has not consumed yet, per model
            $t->index(['seen_at'], 'odoo_changes_seen_ix');
        });

        Schema::create('odoo_runs', function (Blueprint $t) {
            $t->id();
            $t->string('trigger', 30);
            $t->dateTime('started_at', 3);
            $t->dateTime('finished_at', 3)->nullable();
            $t->integer('ms')->nullable();
            $t->integer('models')->default(0);
            $t->integer('calls')->default(0);
            $t->integer('pulled')->default(0);
            $t->integer('created')->default(0);
            $t->integer('updated')->default(0);
            $t->integer('deleted')->default(0);
            $t->integer('errors')->default(0);
            $t->json('detail')->nullable();
            $t->index(['started_at'], 'odoo_runs_started_ix');
        });
    }

    public function down(): void
    {
        foreach (['odoo_runs', 'odoo_changes', 'odoo_records', 'odoo_models'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
