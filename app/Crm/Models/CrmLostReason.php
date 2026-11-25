<?php

namespace App\Crm\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** Why an opportunity was lost. */
class CrmLostReason extends BaseModel
{
    use HasUlids;

    protected $table = 'crm_lost_reasons';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
