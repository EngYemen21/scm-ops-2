<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational hardening · W1 fleet (additive): the vehicle's odometer just before a fuel record was entered.
 * Voiding or correcting a fuel record that carried the vehicle's highest reading puts the odometer back to this value,
 * and the first fuel record of a vehicle measures its distance from it. Null on rows written before this release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_records', function (Blueprint $t) {
            $t->integer('prev_odometer')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fuel_records', fn (Blueprint $t) => $t->dropColumn(['prev_odometer']));
    }
};
