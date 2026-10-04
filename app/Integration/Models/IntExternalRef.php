<?php

namespace App\Integration\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** Identity link: (system, entity, their id) ↔ our id. Unique in both directions. */
class IntExternalRef extends BaseModel
{
    use HasUlids;

    protected $table = 'int_external_refs';

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }
}
