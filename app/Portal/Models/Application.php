<?php

namespace App\Portal\Models;

use App\Models\BaseModel;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company asking to become a customer (an onboarding application).
 *
 * STATE MACHINE (`TRANSITIONS`). draft → submitted → approved | rejected, with `correction` as the way back to the
 * company (submitted → correction → submitted). A draft or a correction nobody completes is `expired`. `accepted` is
 * a reviewed application that waits for the company's signature: OPS never puts an application there itself, Odoo's
 * "pending signature" and "signed" arrive in it, and it is decided like a submitted one. A rejected or expired
 * application is reopened as a draft. `approved` is final: the company is a customer.
 */
class Application extends BaseModel
{
    use HasUlids;

    protected $table = 'portal_applications';

    public const STATUSES = ['draft', 'submitted', 'correction', 'accepted', 'approved', 'rejected', 'expired'];

    /** from → the states it may move to */
    public const TRANSITIONS = [
        'draft' => ['submitted', 'expired'],
        'submitted' => ['correction', 'approved', 'rejected'],
        'correction' => ['submitted', 'rejected', 'expired'],
        'accepted' => ['approved', 'rejected'],
        'rejected' => ['draft'],
        'expired' => ['draft'],
        'approved' => [],
    ];

    /** While it stands in one of these its particulars may still be edited. */
    public const EDITABLE = ['draft', 'correction', 'submitted'];

    public const LEGAL_TYPES = ['establishment', 'llc', 'jsc', 'sole_prop', 'other'];

    public const CONTACT_ROLES = ['purchaser', 'receiver', 'accountant', 'manager', 'other'];

    /** The national id never leaves in a plain `toArray()`: Shape decides who sees it. */
    protected $hidden = ['owner_national_id'];

    protected function casts(): array
    {
        return [
            'contacts' => 'array', 'extra' => 'array', 'terms_accepted' => 'boolean', 'documents_count' => 'integer',
            'terms_accepted_at' => 'datetime', 'signed_at' => 'datetime', 'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'approved_at' => 'datetime',
            'expired_at' => 'datetime', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class, 'application_id', 'id');
    }
}
