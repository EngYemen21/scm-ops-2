<?php

namespace App\Crm\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A to-do on a lead: a type, a due date and an owner. It is done (with a note) or cancelled — never removed. */
class CrmActivity extends BaseModel
{
    use HasUlids;

    protected $table = 'crm_activities';

    public const STATUSES = ['open', 'done', 'cancelled'];

    /** What a salesperson of a food-service distributor does with a prospect. */
    public const TYPES = [
        'call' => ['اتصال', 'Call'], 'visit' => ['زيارة', 'Visit'], 'meeting' => ['اجتماع', 'Meeting'], 'email' => ['بريد إلكتروني', 'E-mail'],
        'message' => ['رسالة / واتساب', 'Message / WhatsApp'], 'sample' => ['إرسال عينات', 'Send samples'], 'todo' => ['مهمة', 'To-do'],
    ];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'done_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
