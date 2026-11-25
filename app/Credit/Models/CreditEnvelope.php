<?php

namespace App\Credit\Models;

use App\Credit\Support\Flow;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The agreement of a request, sent to be signed (Odoo's `b2b.credit.signit.envelope`): who signs, through which
 * provider, where it stands — draft, sent, delivered, signed, declined, expired, failed, voided — and the signed
 * agreement it ended with (`signed_file_id`, a row of `credit_files`). `agreement_hash` is the proof of the text that
 * was sent. `last_payload` is what the provider last said; it may name the signer and is never returned by the API.
 */
class CreditEnvelope extends BaseModel
{
    use HasUlids;

    protected $table = 'credit_envelopes';

    protected $hidden = ['last_payload'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'signed_at' => 'datetime', 'closed_at' => 'datetime', 'expires_at' => 'datetime',
            'created_at' => 'datetime', 'updated_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CreditRequest::class, 'request_id', 'id');
    }

    /** Still waiting for the signer. */
    public function active(): bool
    {
        return in_array($this->status, Flow::ENVELOPE_ACTIVE, true);
    }
}
