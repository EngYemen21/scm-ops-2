<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each OPS section that follows Odoo stands (App\Integration\Odoo\Projection\OdooProjection): when it took its
 * first whole load from the copy, when the runs last fed it a change, and its own place in the change feed.
 *
 * A section is fed by the runs only after its first whole load — a person looks at the plan and applies it once — so
 * no section is ever half-filled by the changes that happen to arrive first. And every section keeps its OWN place in
 * the feed (`cursor_id`), so two sections that read the same Odoo model each get every change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odoo_projections', function (Blueprint $t) {
            $t->string('key', 40)->primary();                    // the area: config odoo.projections
            $t->unsignedBigInteger('cursor_id')->default(0);     // the last change of the feed (odoo_changes.id) the section took
            $t->dateTime('first_applied_at', 3)->nullable();     // the first whole load
            $t->dateTime('last_applied_at', 3)->nullable();      // the latest whole load
            $t->dateTime('last_fed_at', 3)->nullable();          // the latest change a run applied
            $t->json('last_counts')->nullable();                 // what the latest whole load did
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_projections');
    }
};
