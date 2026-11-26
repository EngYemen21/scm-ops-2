<?php

namespace App\Tax\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The VAT return of one period. draft → reviewed → filed → locked; a reviewed return goes back to draft and a filed
 * one back to reviewed with a written reason; a locked return is final (what changes later is a correction of a later
 * return). A return made by mistake is cancelled while it is a draft — it stays, the period is free again.
 */
class VatReturn extends BaseModel
{
    use HasUlids;

    protected $table = 'tax_returns';

    public const STATES = ['draft', 'reviewed', 'filed', 'locked', 'cancelled'];

    /** from → the states it may move to */
    public const TRANSITIONS = [
        'draft' => ['reviewed', 'cancelled'],
        'reviewed' => ['filed', 'draft'],
        'filed' => ['locked', 'reviewed'],
        'locked' => [],
        'cancelled' => [],
    ];

    public const LABELS = [
        'draft' => ['مسودة', 'Draft'], 'reviewed' => ['تمت المراجعة', 'Reviewed'], 'filed' => ['مُقدَّم', 'Filed'], 'locked' => ['مُقفل', 'Locked'], 'cancelled' => ['ملغى', 'Cancelled'],
    ];

    /** States whose figures are frozen: the entries are the record taken at the review. */
    public const FROZEN = ['reviewed', 'filed', 'locked'];

    /** States after which the documents of a period are declared: what is booked into it later is a correction. */
    public const DECLARED = ['filed', 'locked'];

    protected function casts(): array
    {
        return [
            'period_from' => 'date', 'period_to' => 'date', 'figures' => 'array',
            'output_vat' => 'decimal:2', 'input_vat' => 'decimal:2', 'corrections_vat' => 'decimal:2', 'credit_carried' => 'decimal:2', 'net_due' => 'decimal:2',
            'ledger_output' => 'decimal:2', 'ledger_input' => 'decimal:2', 'unexplained' => 'decimal:2',
            'computed_at' => 'datetime', 'reviewed_at' => 'datetime', 'filed_on' => 'date', 'filed_at' => 'datetime', 'locked_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(VatReturnEntry::class, 'return_id', 'id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(VatReturnAdjustment::class, 'return_id', 'id');
    }

    public function ledgerNotes(): HasMany
    {
        return $this->hasMany(VatReturnNote::class, 'return_id', 'id');
    }

    public function frozen(): bool
    {
        return in_array($this->status, self::FROZEN, true);
    }
}
