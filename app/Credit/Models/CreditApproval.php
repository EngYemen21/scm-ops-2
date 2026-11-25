<?php

namespace App\Credit\Models;

use App\Credit\Support\Flow;
use App\Models\BaseModel;
use App\Support\AuthUser;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of the approval audit of a request (Odoo's `b2b.credit.approval`): who was asked at which stage, by when,
 * and what he answered.
 *
 * A line is opened when a request enters a stage — one per stage for "any user", one per person for "all users" — and
 * names a role (`role_key`), a person (`user_id`), both, or nobody (then any holder of the permission may answer).
 * While it waits (`pending`, or `escalated` once the escalation role was brought in) only the reminder and escalation
 * marks are written on it. The answer — approved, rejected, changes_requested, or skipped when the line was closed
 * without one — is written ONCE: a decided line is never edited and no line is ever deleted. The model itself refuses
 * both, whoever asks.
 */
class CreditApproval extends BaseModel
{
    use HasUlids;

    protected $table = 'credit_approvals';

    protected static function booted(): void
    {
        static::updating(function (self $line) {
            if (! in_array($line->getOriginal('state'), Flow::LINE_OPEN, true)) {
                throw new LogicException('A decided approval line is never edited.');
            }
        });
        static::deleting(function () {
            throw new LogicException('An approval line is never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'round' => 'integer', 'personal' => 'boolean', 'deadline' => 'datetime', 'decided_at' => 'datetime', 'reminder_count' => 'integer', 'last_reminder_at' => 'datetime',
            'escalated_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CreditStage::class, 'stage_id', 'id');
    }

    public function open(): bool
    {
        return in_array($this->state, Flow::LINE_OPEN, true);
    }

    /** Writes the answer of this line — once. `$by` is null when the system closes it (a stage that needed one approval got it). */
    public function decide(string $state, ?AuthUser $by, ?string $note = null): static
    {
        if (! $this->open()) {
            throw new LogicException('A decided approval line is never edited.');
        }
        $this->forceFill(['state' => $state, 'decided_by_id' => $by?->id, 'decided_by' => $by?->nameAr, 'decided_at' => now(), 'note' => $note !== null && trim($note) !== '' ? trim($note) : null])->save();

        return $this;
    }
}
