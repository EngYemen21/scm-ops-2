<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A label of documents. `group_name` is the heading it stands under (status, kind of paper…); `folder_id` ties it to one folder. */
class DocTag extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_doc_tags';

    public const COLORS = 12;

    protected function casts(): array
    {
        return ['color' => 'integer', 'sequence' => 'integer', 'active' => 'boolean'];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('group_name IS NULL')->orderBy('group_name')->orderBy('sequence')->orderBy('name')->orderBy('id');
    }
}
