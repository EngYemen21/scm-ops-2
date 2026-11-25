<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A kind of ticket (question, issue, complaint…). */
class TicketType extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_ticket_types';

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'active' => 'boolean'];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sequence')->orderBy('name_ar')->orderBy('id');
    }
}
