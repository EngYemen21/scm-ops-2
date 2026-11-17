<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational hardening — returns (W5), additive only: where the per-line inspection note and the shipping details of a
 * purchase return are kept (the foundation migration has no column for them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $t) {
            $t->string('carrier')->nullable();              // who carried a purchase return back to the supplier
            $t->text('ship_note')->nullable();
        });
        Schema::table('return_lines', function (Blueprint $t) {
            $t->text('inspect_note')->nullable();           // what the inspector wrote about this line
        });
    }

    public function down(): void
    {
        Schema::table('return_lines', fn (Blueprint $t) => $t->dropColumn(['inspect_note']));
        Schema::table('returns', fn (Blueprint $t) => $t->dropColumn(['carrier', 'ship_note']));
    }
};
