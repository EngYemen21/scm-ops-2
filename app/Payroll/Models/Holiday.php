<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A public holiday: days nobody (or one department, or named people) is expected at work. */
class Holiday extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_holidays';

    public const SCOPES = ['company', 'department', 'employee'];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'scope_ids' => 'array', 'active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
