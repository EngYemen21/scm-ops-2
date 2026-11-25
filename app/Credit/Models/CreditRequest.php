<?php

namespace App\Credit\Models;

use App\Credit\Support\Owned;
use App\Models\BaseModel;
use App\Models\Customer;
use App\Support\AppError;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's request for a deferred credit account (Odoo's `b2b.credit.request`): the company and its owner, what
 * is asked for, what was granted, where it stands in the workflow and — once approved — what it changed on the
 * customer.
 *
 * `state` follows the stage (`stage_id`); `round` counts how many times it was sent. The deadline columns describe the
 * CURRENT stay in a stage. `apply_status` tells whether the approved limit and terms reached the customer:
 * applied (with `applied_changes` = the previous and the new values), blocked (with `apply_error` = what the customer
 * service answered) or restored (a person put the previous values back after the approval was revoked).
 *
 * `owner_national_id` is personal: it is kept encrypted (APP_KEY) and `$hidden`, so neither a look at the table nor an
 * accidental `toArray()` shows it — it is read through App\Credit\Support\Privacy only; `owner_national_id_by` is who
 * typed it.
 */
class CreditRequest extends BaseModel
{
    use HasUlids;

    protected $table = 'credit_requests';

    protected $hidden = ['owner_national_id'];

    protected function casts(): array
    {
        return [
            'request_date' => 'date', 'round' => 'integer', 'owner_national_id' => 'encrypted',
            'requested_credit_limit' => 'decimal:2', 'requested_payment_days' => 'integer', 'monthly_purchase_volume' => 'decimal:2',
            'approved_credit_limit' => 'decimal:2', 'approved_payment_days' => 'integer',
            'terms_snapshot' => 'array', 'terms_accepted_at' => 'datetime', 'attachments_complete' => 'boolean',
            'submitted_at' => 'datetime', 'stage_entered_at' => 'datetime', 'sla_deadline' => 'datetime', 'sla_due_soon_at' => 'datetime', 'sla_escalate_at' => 'datetime',
            'last_reminder_at' => 'datetime', 'reminder_count' => 'integer', 'sla_checked_at' => 'datetime', 'escalated_at' => 'datetime', 'escalation_count' => 'integer',
            'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime',
            'applied_at' => 'datetime', 'applied_changes' => 'array', 'apply_error' => 'array', 'restored_at' => 'datetime',
            'extra' => 'array', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CreditStage::class, 'stage_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function termsVersion(): BelongsTo
    {
        return $this->belongsTo(CreditTermsVersion::class, 'terms_version_id', 'id');
    }

    public function paymentMethods(): BelongsToMany
    {
        return $this->belongsToMany(CreditPaymentMethod::class, 'credit_request_methods', 'request_id', 'payment_method_id')->orderBy('sequence')->orderBy('code');
    }

    public function responsibles(): HasMany
    {
        return $this->hasMany(CreditResponsible::class, 'request_id', 'id')->orderBy('line_no')->orderBy('id');
    }

    public function signatories(): HasMany
    {
        return $this->hasMany(CreditSignatory::class, 'request_id', 'id')->orderBy('line_no')->orderBy('id');
    }

    public function bankLines(): HasMany
    {
        return $this->hasMany(CreditBankLine::class, 'request_id', 'id')->orderBy('line_no')->orderBy('id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(CreditApproval::class, 'request_id', 'id')->orderBy('created_at')->orderBy('id');
    }

    public function envelopes(): HasMany
    {
        return $this->hasMany(CreditEnvelope::class, 'request_id', 'id')->orderByDesc('created_at')->orderByDesc('id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(CreditFile::class, 'request_id', 'id')->orderBy('created_at')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CreditEvent::class, 'request_id', 'id')->orderBy('at')->orderBy('id');
    }

    public function fromOdoo(): bool
    {
        return $this->source_system === Owned::SYSTEM;
    }

    /**
     * The request a path names — by its id or its number (CRA-YYYY-NNNNN). With `$lock` the row is locked for the
     * transaction that changes it: every change of a request is one transaction on its locked row.
     */
    public static function locate(string $idOrNumber, bool $lock = false): self
    {
        $key = trim($idOrNumber);

        return self::where(fn ($w) => $w->where('id', $key)->orWhere('number', $key))->when($lock, fn ($q) => $q->lockForUpdate())->first()
            ?? throw AppError::notFound('CREDIT_REQUEST_NOT_FOUND', "طلب الائتمان {$key} غير موجود", "Credit request {$key} not found");
    }
}
