<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Driver phone tracking (from the native app, during an active trip only, after the driver's consent): the trail of
 * phone fixes and, on the driver row, the consent time and the last fix (for the maps and lists).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('drivers', 'phone_consent_at')) {
            Schema::table('drivers', function (Blueprint $table) {
                $table->dateTime('phone_consent_at', 3)->nullable(); // when the driver accepted phone tracking
                $table->double('phone_lat')->nullable();
                $table->double('phone_lng')->nullable();
                $table->double('phone_accuracy')->nullable();       // metres
                $table->double('phone_speed_kph')->nullable();
                $table->dateTime('phone_at', 3)->nullable();         // time of the last fix (phone clock, UTC)
                $table->ulid('phone_trip_id')->nullable();           // the trip that fix belongs to
                $table->string('phone_platform', 20)->nullable();    // android | ios
            });
        }
        if (! Schema::hasTable('driver_positions')) {
            Schema::create('driver_positions', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('driver_id');
                $table->ulid('trip_id')->nullable();
                $table->ulid('vehicle_id')->nullable();
                $table->double('lat');
                $table->double('lng');
                $table->double('accuracy')->nullable();
                $table->double('speed_kph')->nullable();
                $table->integer('heading')->nullable();
                $table->dateTime('at', 3);
                $table->unique(['driver_id', 'at'], 'driver_positions_driver_at_uq'); // a replayed batch inserts nothing twice
                $table->index(['trip_id', 'at'], 'driver_positions_trip_at_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_positions');
        if (Schema::hasColumn('drivers', 'phone_consent_at')) {
            Schema::table('drivers', fn (Blueprint $table) => $table->dropColumn(['phone_consent_at', 'phone_lat', 'phone_lng', 'phone_accuracy', 'phone_speed_kph', 'phone_at', 'phone_trip_id', 'phone_platform']));
        }
    }
};
