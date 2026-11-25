<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A pay structure: the group of people who are paid the same way — above all, under which social-insurance scheme
 * they contribute. The rules a structure had in Odoo are kept beside it to be read (StructureRule).
 */
class Structure extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_structures';

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(StructureRule::class, 'structure_id', 'id')->orderBy('sequence')->orderBy('code');
    }
}
