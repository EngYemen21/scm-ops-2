<?php

namespace App\Tax\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The e-invoice of one OPS document: its XML, its counter in the unit's sequence, its hash and the hash of the document
 * before it, its QR code, and what the authority answered. A document the authority rejected stays in the chain as it
 * is; the corrected one is a NEW e-invoice of the same OPS document (`attempt_no`, `superseded_by_id`).
 */
class Einvoice extends BaseModel
{
    use HasUlids;

    protected $table = 'tax_einvoices';

    /**
     * not_sent           made; the unit is not onboarded (or sending is switched off)
     * queued             waiting to be sent, or to be sent again
     * reported / cleared the authority accepted it (simplified: reported; standard: cleared)
     * accepted_warnings  accepted, with warnings to read
     * rejected           the authority refused it — a corrected document must be made
     * withdrawn          never sent and never to be sent: the OPS invoice was cancelled first, or a newer document took its place
     */
    public const STATES = ['not_sent', 'queued', 'reported', 'cleared', 'accepted_warnings', 'rejected', 'withdrawn'];

    public const ACCEPTED = ['reported', 'cleared', 'accepted_warnings'];

    public const LABELS = [
        'not_sent' => ['لم تُرسل', 'Not sent'], 'queued' => ['بانتظار الإرسال', 'Queued'], 'reported' => ['مُبلَّغة', 'Reported'], 'cleared' => ['مُجازة', 'Cleared'],
        'accepted_warnings' => ['مقبولة مع تحذيرات', 'Accepted with warnings'], 'rejected' => ['مرفوضة', 'Rejected'], 'withdrawn' => ['مسحوبة', 'Withdrawn'],
    ];

    protected $hidden = ['xml', 'cleared_xml'];

    protected function casts(): array
    {
        return [
            'icv' => 'integer', 'issue_at' => 'datetime', 'net' => 'decimal:2', 'vat' => 'decimal:2', 'total' => 'decimal:2', 'signed' => 'boolean', 'is_current' => 'boolean',
            'attempt_no' => 'integer', 'warnings' => 'integer', 'messages' => 'array', 'issues' => 'array', 'http_status' => 'integer', 'attempts' => 'integer',
            'sent_at' => 'datetime', 'accepted_at' => 'datetime', 'next_attempt_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(EinvoiceUnit::class, 'unit_id', 'id');
    }

    public function log(): HasMany
    {
        return $this->hasMany(EinvoiceAttempt::class, 'einvoice_id', 'id');
    }

    public function accepted(): bool
    {
        return in_array($this->status, self::ACCEPTED, true);
    }
}
