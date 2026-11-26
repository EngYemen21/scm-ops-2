<?php

namespace App\Crm\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A column of the pipeline. Entering a stage marked `is_won` wins the opportunity. */
class CrmStage extends BaseModel
{
    use HasUlids;

    protected $table = 'crm_stages';

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'is_won' => 'boolean', 'fold' => 'boolean', 'probability' => 'float', 'active' => 'boolean'];
    }

    /** The order of the board: by sequence; at the same sequence the stages of the source system come first, then by age. */
    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sequence')->orderByRaw('source_system IS NULL')->orderBy('created_at')->orderBy('id');
    }
}
