<?php

namespace App\Integration\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** The latest copy of another system's master record (read-only here) — e.g. a Sales product waiting to be mapped. */
class IntMirror extends BaseModel
{
    use HasUlids;

    protected $table = 'int_mirror';

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
