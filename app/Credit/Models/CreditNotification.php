<?php

namespace App\Credit\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * One line of the notification log (Odoo's `b2b.credit.notification.log`): what the section tried to tell whom, by
 * which channel — in_app (Odoo's own channel is called `odoo` there), email, whatsapp — and how it ended: sent, failed,
 * or skipped when no provider is configured for the channel. Honest by construction: nothing is ever logged as sent
 * that was not handed to a provider. Append-only.
 */
class CreditNotification extends BaseModel
{
    use HasUlids;

    protected $table = 'credit_notifications';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
