<?php

namespace App\Hr\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something of an employee that expires: one OPEN alert per document / identity / passport / contract, moved through
 * the stages 60 → 30 → 7 days → expired as the date comes closer and resolved by itself when it is renewed
 * (App\Hr\Services\AlertsService — the pattern of the fleet's document alerts).
 */
class HrAlert extends BaseModel
{
    use HasUlids;

    protected $table = 'hr_alerts';

    /** stage → the most days left at which it starts; the order is from the mildest to the most urgent */
    public const STAGES = ['d60' => 60, 'd30' => 30, 'd7' => 7, 'expired' => -1];

    public const CATEGORIES = ['document', 'identity', 'passport', 'contract'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'is_private' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }

    /** The stage of a date that is `$days` days away (negative = past); null when it is further than 60 days. */
    public static function stageOf(int $days): ?string
    {
        return match (true) {
            $days < 0 => 'expired', $days <= 7 => 'd7', $days <= 30 => 'd30', $days <= 60 => 'd60', default => null
        };
    }

    public static function rank(?string $stage): int
    {
        return $stage === null ? -1 : (int) array_search($stage, array_keys(self::STAGES), true);
    }
}
