<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The vehicle trail is filled from the provider's message history (every fix the device sent), not only from the
 * fixes a map refresh happened to sample. The vehicle keeps the provider's unit id (to ask for its history) and the
 * time span already copied; a fix is stored once per (vehicle, time).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vehicles', 'gps_unit_id')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->string('gps_unit_id', 40)->nullable();       // the provider's unit id, as matched
                $table->dateTime('gps_history_from', 3)->nullable(); // history copied for [from, until]
                $table->dateTime('gps_history_until', 3)->nullable();
            });
        }
        // the sampled fixes may hold the same (vehicle, time) twice: keep one before the unique index
        $dupes = DB::table('vehicle_positions')->select('vehicle_id', 'at')->groupBy('vehicle_id', 'at')->havingRaw('count(*) > 1')->get();
        foreach ($dupes as $d) {
            $ids = DB::table('vehicle_positions')->where('vehicle_id', $d->vehicle_id)->where('at', $d->at)->orderBy('id')->pluck('id');
            DB::table('vehicle_positions')->whereIn('id', $ids->slice(1)->all())->delete();
        }
        Schema::table('vehicle_positions', function (Blueprint $table) {
            $table->unique(['vehicle_id', 'at'], 'vehicle_positions_vehicle_at_uq');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_positions', fn (Blueprint $table) => $table->dropUnique('vehicle_positions_vehicle_at_uq'));
        Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn(['gps_unit_id', 'gps_history_from', 'gps_history_until']));
    }
};
