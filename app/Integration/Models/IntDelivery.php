<?php

namespace App\Integration\Models;

use App\Models\BaseModel;
use App\Models\IntegrationEvent;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One delivery of an outbox event to one subscribing system. */
class IntDelivery extends BaseModel
{
    use HasUlids;

    protected $table = 'int_deliveries';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'attempts' => 'integer', 'last_status' => 'integer', 'response_ms' => 'integer', 'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime', 'created_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(IntegrationEvent::class, 'event_id', 'id');
    }
}
