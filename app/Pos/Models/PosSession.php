<?php

namespace App\Pos\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cashier's shift on a register (Odoo `pos.session`): opened with the counted float, closed with the cash counted by
 * denomination. open → closed, never back; a closed session is immutable and carries its frozen end-of-day report.
 * `open_register_key` / `open_cashier_key` hold the register and the cashier while the session is open and null after:
 * their unique indexes are what makes "one open session per register, and per cashier" true under concurrency.
 */
class PosSession extends BaseModel
{
    use HasUlids;

    protected $table = 'pos_sessions';

    public const STATES = ['open', 'closed'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime', 'closed_at' => 'datetime', 'opening_cash' => 'decimal:2', 'opening_counts' => 'array', 'closing_counts' => 'array',
            'counted_cash' => 'decimal:2', 'expected_cash' => 'decimal:2', 'difference' => 'decimal:2', 'report' => 'array', 'extra' => 'array',
            'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'register_id', 'id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id', 'id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PosOrder::class, 'session_id', 'id');
    }

    public function cashMoves(): HasMany
    {
        return $this->hasMany(PosCashMove::class, 'session_id', 'id');
    }
}
