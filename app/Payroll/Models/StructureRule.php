<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A salary rule of a structure exactly as the source system holds it (code, category, condition, formula). It is
 * history and documentation: OPS never runs the formula — its own components compute a payslip — and names the
 * component that does the same work when there is one.
 */
class StructureRule extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_structure_rules';

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'appears_on_payslip' => 'boolean', 'active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'component_id', 'id');
    }
}
