<?php

namespace App\Tax\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * An e-invoice document another system made and sent (Odoo's `account.edi.document`): the e-invoice history of the
 * invoices the archive holds. Read-only — it is a record of what happened there.
 */
class EinvoiceHistory extends BaseModel
{
    use HasUlids;

    protected $table = 'tax_einvoice_history';

    /** Odoo's state → what it means here (a document waiting with an error is `error`). */
    public const STATUS = ['sent' => 'accepted', 'to_send' => 'not_sent', 'to_cancel' => 'cancelling', 'cancelled' => 'cancelled'];

    public const LABELS = [
        'accepted' => ['أُرسلت وقُبلت', 'Sent and accepted'], 'not_sent' => ['لم تُرسل', 'Not sent'], 'error' => ['تعذّر الإرسال', 'Sending failed'],
        'cancelling' => ['بانتظار الإلغاء', 'Waiting to be cancelled'], 'cancelled' => ['ملغاة', 'Cancelled'],
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date', 'amount_total' => 'decimal:2', 'amount_tax' => 'decimal:2', 'chain_index' => 'integer', 'confirmed_at' => 'datetime',
            'source_created_at' => 'datetime', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }
}
