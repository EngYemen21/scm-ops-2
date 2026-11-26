<?php

namespace App\Payroll\Models;

use App\Hr\Models\Department;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The attendance sheets of one department (or the whole company) for one period: draft → generated → submitted → closed. */
class SheetBatch extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_sheet_batches';

    public const STATES = ['draft', 'generated', 'submitted', 'closed'];

    /** A batch only moves forward; a single sheet of it can still be reopened on its own. */
    public const TRANSITIONS = ['draft' => ['generated'], 'generated' => ['submitted'], 'submitted' => ['closed'], 'closed' => []];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'skipped' => 'array', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(Sheet::class, 'batch_id', 'id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }
}
