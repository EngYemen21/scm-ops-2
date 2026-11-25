<?php

namespace App\Credit\Services;

use App\Credit\Models\CreditRequest;
use App\Credit\Support\Flow;
use App\Credit\Support\Owned;
use App\Credit\Support\People;
use App\Credit\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The clock of the credit workflow — what `php artisan scm:credit-deadlines` runs (and what the manager can run from
 * the page). It reads the target times the engine set when each request entered its stage and does three things:
 *
 *   reminder    once, when the stage's "remind before the deadline" moment has come: the people who are asked are
 *               reminded that the request is due;
 *   overdue     once the deadline has passed: they are told it is late, and told again every `reminders.repeatHours`
 *               (at a working time), `reminders.max` times per stay at most, counting the first reminder;
 *   escalation  once, when the stage's "escalate after the deadline" moment has come: the lines that still wait are
 *               marked `escalated`, and the escalation role of the stage — the managers of the section when the stage
 *               names none — is told and may from then on decide them as well.
 *
 * A stage the requester himself works in (draft, changes requested) with a target time reminds the requester.
 * Idempotent: running it twice at the same moment tells nobody twice. A request of Odoo is left alone while the section
 * follows Odoo. It also closes the signature envelopes that waited past their time.
 *
 * Whatever it does is attributed to the system (no user), with a line in the request's timeline; an escalation is
 * written to the audit trail too.
 */
class DeadlinesService
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly SignatureService $signature,
        private readonly Notifier $notifier,
        private readonly CreditLog $log,
        private readonly Settings $settings,
        private readonly People $people,
    ) {}

    /** @return array{installed:bool, checked:int, reminded:int, overdue:int, escalated:int, envelopesExpired:int, failed:int} */
    public function run(): array
    {
        $out = ['installed' => true, 'checked' => 0, 'reminded' => 0, 'overdue' => 0, 'escalated' => 0, 'envelopesExpired' => 0, 'failed' => 0];
        if (! Schema::hasTable('credit_requests')) {
            return ['installed' => false] + $out;
        }
        $now = now();
        $due = CreditRequest::whereNotIn('state', Flow::FINAL)->whereNotNull('sla_deadline')
            ->where(fn ($w) => $w->where('sla_due_soon_at', '<=', $now)->orWhere('sla_deadline', '<=', $now)->orWhere('sla_escalate_at', '<=', $now))
            ->orderBy('sla_deadline')->orderBy('id')->limit(1000)->pluck('id');
        foreach ($due as $id) {
            try {
                foreach (DB::transaction(fn () => $this->check((string) $id)) as $what) {
                    $out[$what]++;
                }
            } catch (Throwable $e) {
                report($e); // one request that cannot be handled never stops the others
                $out['failed']++;
            }
        }
        $out['envelopesExpired'] = $this->signature->expire();

        return $out;
    }

    /** One request, on its locked row. @return string[] what was done: checked, reminded, overdue, escalated */
    private function check(string $id): array
    {
        $r = CreditRequest::whereKey($id)->lockForUpdate()->first();
        if (! $r || Flow::isFinal($r->state) || $r->sla_deadline === null || Owned::bySource($r)) {
            return [];
        }
        $stage = $r->stage;
        $work = Flow::isWork($r->state);
        $lines = $work ? $this->engine->openLines($r) : collect();
        if (! $stage || ($work && $lines->isEmpty())) {
            return []; // nobody is waited for
        }
        $now = now();
        $done = ['checked'];
        $clock = $this->settings->clock();
        $policy = $this->settings->get('reminders');
        $max = max(1, (int) ($policy['max'] ?? 3));
        $repeat = max(1.0, (float) ($policy['repeatHours'] ?? 24));
        $to = $work ? $this->engine->audience($lines, $stage) : ['roles' => [], 'users' => [$r->requester_id]];
        $due = $r->sla_deadline->copy()->setTimezone((string) config('app.business_timezone', 'UTC'))->format('Y-m-d H:i');
        $about = "طلب الائتمان الآجل {$r->number} ({$r->company_name})";
        $aboutEn = "deferred credit request {$r->number} ({$r->company_name})";

        if ($r->escalated_at === null && $r->sla_escalate_at !== null && $now->gte($r->sla_escalate_at)) {
            // the escalation role is brought in; the managers of the section when the stage names none
            $role = $stage->escalation_role;
            foreach ($lines as $line) {
                $line->forceFill(['state' => 'escalated', 'escalated_at' => $now, 'escalated_to_role' => $role])->save();
            }
            $r->forceFill(['escalated_at' => $now, 'escalation_count' => (int) $r->escalation_count + 1])->save();
            $this->log->event($r, 'escalated', null, null, ['stage' => $stage->code, 'to' => $role ?? 'credit.manage', 'deadline' => $r->sla_deadline->toJSON()]);
            $this->log->audit(null, $r, 'ESCALATE', null, ['stage' => $stage->code, 'to' => $role ?? 'credit.manage', 'count' => (int) $r->escalation_count]);
            $this->notifier->tell($r, 'stage.escalated', "تصعيد: {$about} تجاوز مهلة مرحلة «{$stage->name_ar}» ({$due}) دون قرار — القرار متاح لكم الآن",
                "Escalated: {$aboutEn} passed the target time of stage \"{$stage->name_en}\" ({$due}) without a decision — you may decide it now", null,
                $role !== null ? ['roles' => [$role], 'users' => $to['users']] : ['roles' => $to['roles'], 'users' => [...$to['users'], ...$this->people->holders(['credit.manage'])->pluck('id')->all()]]);
            $done[] = 'escalated';
        } elseif ($now->gte($r->sla_deadline)) {
            $last = $r->last_reminder_at;
            $first = $last === null || $last->lt($r->sla_deadline); // nothing was said since the deadline passed
            $again = ! $first && $now->gte($clock->opening($last->copy()->addMinutes((int) round($repeat * 60))));
            if ((int) $r->reminder_count < $max && ($first ? $now->gte($clock->opening($r->sla_deadline)) : $again)) {
                $this->remind($r, $lines, $now, true);
                $this->notifier->tell($r, 'stage.overdue', "متأخر: {$about} تجاوز مهلة مرحلة «{$stage->name_ar}» ({$due})", "Overdue: {$aboutEn} passed the target time of stage \"{$stage->name_en}\" ({$due})", null, $to);
                $done[] = 'overdue';
            }
        } elseif ((int) $r->reminder_count === 0 && $r->sla_due_soon_at !== null && $now->gte($r->sla_due_soon_at)) {
            $this->remind($r, $lines, $now, false);
            $this->notifier->tell($r, 'stage.reminder', "تذكير: {$about} في مرحلة «{$stage->name_ar}» — تنتهي مهلته {$due}", "Reminder: {$aboutEn} in stage \"{$stage->name_en}\" is due by {$due}", null, $to);
            $done[] = 'reminded';
        }
        $r->forceFill(['sla_checked_at' => $now])->save();

        return $done;
    }

    private function remind(CreditRequest $r, iterable $lines, Carbon $now, bool $overdue): void
    {
        foreach ($lines as $line) {
            $line->forceFill(['reminder_count' => (int) $line->reminder_count + 1, 'last_reminder_at' => $now])->save();
        }
        $r->forceFill(['reminder_count' => (int) $r->reminder_count + 1, 'last_reminder_at' => $now])->save();
        $this->log->event($r, 'reminder', null, null, ['stage' => $r->stage?->code, 'overdue' => $overdue, 'count' => (int) $r->reminder_count]);
    }
}
