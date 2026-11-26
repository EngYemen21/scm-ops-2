<?php

namespace App\Banking\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One import of a file into a statement: which file (its name and the hash of its content), how many rows were taken,
 * were duplicates, were refused — and for the rows that were not taken, their number in the file and why.
 * It holds no amount and no text of the file.
 */
class ImportRun extends BaseModel
{
    use HasUlids;

    protected $table = 'bank_imports';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['rows_total' => 'integer', 'rows_taken' => 'integer', 'rows_duplicate' => 'integer', 'rows_refused' => 'integer', 'report' => 'array', 'created_at' => 'datetime'];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(Statement::class, 'statement_id', 'id');
    }
}
