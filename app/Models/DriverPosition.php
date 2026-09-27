<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One fix from a driver's phone during an active trip (the phone trail next to the truck's GPS trail). */
class DriverPosition extends BaseModel
{
    use HasUlids;

    protected $table = 'driver_positions';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'accuracy' => 'float', 'speed_kph' => 'float', 'heading' => 'integer', 'at' => 'datetime'];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id', 'id');
    }
}
