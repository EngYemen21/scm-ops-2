<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real-world coordinates for the map: a warehouse is where every trip starts, a stop is where it delivers.
 * (`trip_stops.map_x / map_y` are the prototype's schematic positions and stay untouched; customers and vehicles
 * already have lat / lng.) A stop copies its customer's coordinates when the trip is created and falls back to the
 * customer's current ones when it has none.
 */
return new class extends Migration
{
    private const TABLES = ['warehouses', 'trip_stops'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasColumn($name, 'lat')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->double('lat')->nullable();
                    $table->double('lng')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasColumn($name, 'lat')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['lat', 'lng']));
            }
        }
    }
};
