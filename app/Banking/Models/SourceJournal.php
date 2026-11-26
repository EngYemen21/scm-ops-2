<?php

namespace App\Banking\Models;

use App\Finance\Models\BankAccount;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bank or cash journal of the source system (Odoo), and the OPS bank account it IS — when one was recognised by its
 * IBAN, its account number or its exact name, or named by a person. A journal no OPS bank account answers to stays
 * here unlinked, listed for a person: the section never makes a second bank account beside an existing one.
 *
 * What the source owns: code, name, kind, currency, bank, account number, active. What OPS owns: the link.
 */
class SourceJournal extends BaseModel
{
    use HasUlids;

    protected $table = 'bank_source_journals';

    public const KINDS = ['bank', 'cash'];

    /** How the link was made. */
    public const LINKED_BY = ['iban', 'number', 'name', 'manual', 'adopted'];

    protected $hidden = ['account_number', 'account_key'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'linked_at' => 'datetime', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime'];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id', 'id');
    }

    public function statements(): HasMany
    {
        return $this->hasMany(Statement::class, 'source_journal_id', 'id');
    }
}
