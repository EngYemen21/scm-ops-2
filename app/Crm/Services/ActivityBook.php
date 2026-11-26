<?php

namespace App\Crm\Services;

use App\Crm\Models\CrmActivity;
use App\Crm\Models\CrmLead;
use Illuminate\Support\Facades\DB;

/**
 * The two things the to-dos of a lead owe the lead itself, in one place: the lead's `next_activity_*` columns always
 * show its earliest open to-do (the board, the sort and the "overdue" filter read them), and closing a lead as lost
 * cancels what was still planned for it. Both run inside the caller's transaction.
 */
class ActivityBook
{
    /** Call after anything that opens, closes, re-dates or re-types a to-do of the lead. */
    public function refreshNext(string $leadId): void
    {
        $next = CrmActivity::where('lead_id', $leadId)->where('status', 'open')->orderBy('due_date')->orderBy('id')->first(['due_date', 'type', 'summary']);
        // through the base query: the lead's own `updated_at` says when its values changed, not when a to-do moved
        DB::table('crm_leads')->where('id', $leadId)->update([
            'next_activity_date' => $next?->due_date?->toDateString(), 'next_activity_type' => $next?->type, 'next_activity_summary' => $next?->summary,
        ]);
    }

    /** @return int how many open to-dos were cancelled */
    public function cancelOpenOf(CrmLead $lead, string $by, string $reason): int
    {
        $n = CrmActivity::where('lead_id', $lead->id)->where('status', 'open')
            ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $by, 'cancel_reason' => $reason]);
        if ($n) {
            $this->refreshNext($lead->id);
        }

        return $n;
    }
}
