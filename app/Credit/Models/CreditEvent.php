<?php

namespace App\Credit\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use LogicException;

/**
 * One thing that happened to a credit request, kept in the order it happened: the timeline people read. Comments are
 * events too. Append-only — the model refuses to change or remove a row. `data` carries the facts of the event; a
 * personal value never stands in it (names of fields and counts only).
 */
class CreditEvent extends BaseModel
{
    use HasUlids;

    protected $table = 'credit_events';

    public $timestamps = false;

    public const KINDS = [
        'created', 'edited', 'submitted', 'stage', 'approved', 'rejected', 'changes_requested', 'skipped', 'approvers_refreshed', 'withdrawn', 'cancelled', 'reopened',
        'assessed', 'comment', 'document_added', 'document_removed', 'terms_accepted', 'envelope', 'reminder', 'escalated',
        'applied', 'apply_blocked', 'customer_restored',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('The timeline of a credit request is append-only.');
        });
        static::deleting(function () {
            throw new LogicException('The timeline of a credit request is append-only.');
        });
    }

    protected function casts(): array
    {
        return ['data' => 'array', 'at' => 'datetime'];
    }
}
