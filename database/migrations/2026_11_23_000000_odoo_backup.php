<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Odoo copy as a backup (docs/odoo/ARCHITECTURE.ar.md §8): the content of Odoo's attachments kept on a disk of
 * OPS, and the record of each check that the copy is complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Odoo trims the answers of a few models AFTER limiting them (messages: what the user may not read is dropped
        // from the page), so a short page is not the end there. Such a model is walked by fixed id ranges.
        Schema::table('odoo_models', function (Blueprint $t) {
            $t->boolean('ranged')->default(false);
        });

        // one row per Odoo attachment whose content was looked at: kept on the disk, or left out with the reason
        Schema::create('odoo_files', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('attachment_id')->unique();          // ir.attachment id in Odoo
            $t->string('res_model', 120)->nullable();            // what the file hangs on
            $t->bigInteger('res_id')->nullable();
            $t->string('name', 255)->nullable();
            $t->string('mimetype', 150)->nullable();
            $t->bigInteger('size')->default(0);                  // bytes kept
            $t->string('checksum', 64)->nullable();              // sha1 of the content, as Odoo states it and as verified here
            $t->string('status', 12);                            // stored | skipped | failed
            $t->string('note', 255)->nullable();                 // why it was skipped or what failed
            $t->string('disk', 40)->nullable();
            $t->string('path', 255)->nullable();
            $t->dateTime('stored_at', 3);
            $t->index(['res_model', 'res_id'], 'odoo_files_parent_ix');
            $t->index(['status'], 'odoo_files_status_ix');
        });

        // each time the copy was compared with Odoo: counts per model and a sample of records read again
        Schema::create('odoo_verifications', function (Blueprint $t) {
            $t->id();
            $t->string('trigger', 30);
            $t->dateTime('started_at', 3);
            $t->dateTime('finished_at', 3)->nullable();
            $t->integer('models')->default(0);                   // models compared
            $t->bigInteger('records_odoo')->default(0);
            $t->bigInteger('records_copy')->default(0);
            $t->integer('missing')->default(0);                  // in Odoo and not in the copy, beyond what the next run brings
            $t->integer('extra')->default(0);                    // in the copy and no longer in Odoo (waiting for the deletion check)
            $t->integer('sampled')->default(0);
            $t->integer('differing')->default(0);                // sampled records whose values differ although Odoo did not write them since
            $t->boolean('complete')->default(false);
            $t->json('detail')->nullable();                      // the models that are not in step, with their figures
            $t->index(['started_at'], 'odoo_verifications_started_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_verifications');
        Schema::dropIfExists('odoo_files');
        Schema::table('odoo_models', fn (Blueprint $t) => $t->dropColumn('ranged'));
    }
};
