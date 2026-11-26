<?php

namespace App\Planning\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One decision of an allocation: what a month paid of a requirement — or that nothing could be paid (App\Planning\Engine\Allocator). */
class Allocation extends BaseModel
{
    use HasUlids;

    protected $table = 'planning_allocations';

    public $timestamps = false;

    public const STATUSES = ['funded', 'partial', 'deferred'];

    protected function casts(): array
    {
        return [
            'month' => 'date', 'order_date' => 'date', 'rank' => 'integer', 'must_fund' => 'boolean', 'score' => 'float', 'score_urgency' => 'float', 'score_stockout' => 'float',
            'score_criticality' => 'float', 'score_velocity' => 'float', 'score_confidence' => 'float', 'score_customer' => 'float', 'score_lead_time' => 'float', 'required_qty' => 'float',
            'funded_qty' => 'float', 'required_value' => 'decimal:2', 'funded_value' => 'decimal:2', 'carried_from' => 'date', 'at_risk' => 'boolean',
        ];
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class, 'requirement_id', 'id');
    }
}
