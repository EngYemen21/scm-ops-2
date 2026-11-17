<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational hardening, workstream W3 (picking / packing / loading) — the two facts the foundation migration has no
 * column for. Additive only.
 *  - a package that was unpacked is VOID, not deleted: its number and label were printed and may still be scanned;
 *  - the structured reason of a short pick / batch substitution (the free note lives in pick_tasks.note).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $t) {
            $t->string('status')->default('packed');        // packed | void
            $t->dateTime('voided_at', 3)->nullable();
            $t->string('voided_by')->nullable();
            $t->text('void_reason')->nullable();
        });
        Schema::table('pick_tasks', function (Blueprint $t) {
            $t->string('short_reason')->nullable();         // notfound | damaged | expired | other
        });
    }

    public function down(): void
    {
        Schema::table('pick_tasks', fn (Blueprint $t) => $t->dropColumn(['short_reason']));
        Schema::table('packages', fn (Blueprint $t) => $t->dropColumn(['status', 'voided_at', 'voided_by', 'void_reason']));
    }
};
