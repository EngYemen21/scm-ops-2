<?php

namespace App\Integration\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** An event received from another system (deduplicated by event_id), with its processing outcome. */
class IntInbox extends BaseModel
{
    use HasUlids;

    protected $table = 'int_inbox';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'data' => 'array', 'result' => 'array', 'attempts' => 'integer', 'sequence' => 'integer', 'schema_version' => 'integer',
            'duration_ms' => 'integer', 'event_time' => 'datetime', 'next_attempt_at' => 'datetime', 'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
