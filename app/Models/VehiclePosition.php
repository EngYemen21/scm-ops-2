<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One GPS fix of a vehicle, as reported by the telematics provider (the trail behind the live marker). */
class VehiclePosition extends BaseModel
{
    use HasUlids;

    protected $table = 'vehicle_positions';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'speed_kph' => 'float', 'course' => 'integer', 'at' => 'datetime'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'id');
    }
}
