<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live vehicle tracking: the last fix the telematics provider reported (on the vehicle row, for the maps and lists)
 * and the trail of fixes behind it (one row per new position, pruned to the last days by the sync).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vehicles', 'gps_at')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dateTime('gps_at', 3)->nullable();   // time of the position (provider clock, UTC)
                $table->double('speed_kph')->nullable();
                $table->integer('course')->nullable();       // heading 0–359
                $table->string('gps_unit_name')->nullable(); // the provider's unit name, as matched
            });
        }
        if (! Schema::hasTable('vehicle_positions')) {
            Schema::create('vehicle_positions', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('vehicle_id');
                $table->double('lat');
                $table->double('lng');
                $table->double('speed_kph')->nullable();
                $table->integer('course')->nullable();
                $table->dateTime('at', 3);
                $table->index(['vehicle_id', 'at'], 'vehicle_positions_vehicle_at_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_positions');
        if (Schema::hasColumn('vehicles', 'gps_at')) {
            Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn(['gps_at', 'speed_kph', 'course', 'gps_unit_name']));
        }
    }
};
