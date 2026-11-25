<?php

namespace App\Crm\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A label people put on leads (restaurant, café, hotel…). `color` is the index of the palette they picked (0..11). */
class CrmTag extends BaseModel
{
    use HasUlids;

    protected $table = 'crm_tags';

    public const COLORS = 12;

    protected function casts(): array
    {
        return ['color' => 'integer', 'active' => 'boolean'];
    }
}
