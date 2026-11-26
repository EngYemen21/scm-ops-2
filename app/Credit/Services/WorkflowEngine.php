<?php

namespace App\Credit\Services;

use App\Credit\Models\CreditApproval;
use App\Credit\Models\CreditRequest;
use App\Credit\Models\CreditStage;
use App\Credit\Support\Access;
use App\Credit\Support\Flow;
use App\Credit\Support\Owned;
use App\Credit\Support\People;
use App\Credit\Support\Privacy;
use App\Credit\Support\Settings;
use App\Credit\Support\Slots;
use App\Credit\Support\Text;
use App\Support\AppError;
use App\Support\AuthUser;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The workflow of a credit request. The stages are data (`credit_stages`); this class is what makes them binding.
 *
 * THE PATH. A request that is sent enters the first working stage (the active stages whose state is one of
 * Flow::WORK, in their order) and moves to the next each time a stage is decided; after the last one it is approved.
 * Sent again after changes were asked for, it starts at the first stage again: nobody's approval covers values he has
 * not seen.
 *
 * WHO DECIDES A STAGE. Entering a stage opens its approval lines (`credit_approvals`): one line for "any user", one
 * per person for "all users". A line is answered by the people it names — the users of the stage's role, the stage's
 * user, the escalation role once it was escalated, or any holder of the permission when the stage names nobody — who
 * also hold `credit.review` or `credit.approve`. The LAST working stage grants the credit: it is answered with
 * `credit.approve` only. Nobody answers a line on the request he entered (CREDIT_SELF_APPROVAL), and the manager of
 * the section does not answer in an approver's place: he may skip a stage that is not the last, with a reason.
 *
 * WHAT A STAGE REQUIRES. A request cannot leave a stage whose requirements are not met — whoever moves it and however
 * (sent, approved, skipped): the required papers are all there (CREDIT_DOCUMENTS_REQUIRED), the agreement is signed
 * and still says what was signed (CREDIT_SIGNATURE_REQUIRED / CREDIT_SIGNATURE_STALE); and it cannot be granted
 * without an approved limit and payment days (CREDIT_GRANT_REQUIRED).
 *
 * THE AUDIT. Every answer is written once on its line (CreditApproval refuses a second writing), with the timeline
 * entry, the audit rows and the notifications of the move — all in the one transaction that holds the request's row.
 *
 * THE DEADLINE. Entering a stage sets its target time, counted by the business clock (Settings::clock): working hours
 * in the business time zone unless the settings say calendar hours. Reminders and escalation are DeadlinesService's.
 *
 * WHAT CAME FROM ODOO is Odoo's while the section follows it: every move here is refused with
 * CREDIT_OWNED_BY_SOURCE.
 */
class WorkflowEngine
{
    public function __construct(
        private readonly CreditLog $log,
        private readonly Notifier $notifier,
        private readonly People $people,
        private readonly Settings $settings,
        private readonly DocumentsService $documents,
        private readonly SignatureService $signature,
        private readonly CustomerCredit $customer,
    ) {}

    // ───────────── the stages ─────────────

    /** @return Collection<int, CreditStage> the working stages, in the order a request walks them */
    public function path(): Collection
    {
        return CreditStage::where('active', true)->whereIn('state', Flow::WORK)->ordered()->get();
    }

    /** The one stage of a state a request is sent to (draft, approved, rejected, changes requested, cancelled). */
    public function single(string $state): CreditStage
    {
        return CreditStage::where('state', $state)->orderByDesc('active')->ordered()->first()
            ?? throw AppError::rule('CREDIT_STAGE_MISSING', "لا توجد مرحلة للحالة «{$state}» في إعداد سير العمل — أضفها من إعدادات الائتمان", "The workflow has no stage for state \"{$state}\" — add it in the credit settings", ['state' => $state]);
    }

    /** The working stage that follows this one, or null when it is the last. */
    public function next(CreditStage $stage): ?CreditStage
    {
        $path = $this->path();
        $at = $path->search(fn (CreditStage $s) => $s->id === $stage->id);
        if ($at !== false) {
            return $path->get($at + 1);
        }

        // the stage was archived while a request stood in it: the first active stage placed after it
        return $path->first(fn (CreditStage $s) => $s->sequence > $stage->sequence);
    }

    /** Deciding this stage grants the credit: no working stage follows it. */
    public function isLast(CreditStage $stage): bool
    {
        return $this->next($stage) === null;
    }

    /** @return string[] the permissions a decision of this stage is made with */
    public function permissionsFor(CreditStage $stage): array
    {
        return $this->isLast($stage) ? ['credit.approve'] : ['credit.review', 'credit.approve'];
    }

    /**
     * The target time of a stay in a stage that begins at `$entered`.
     *
     * @return array{deadline:?Carbon, dueSoonAt:?Carbon, escalateAt:?Carbon}
     */
    public function deadlines(CreditStage $stage, DateTimeInterface $entered): array
    {
        $sla = (float) $stage->sla_hours;
        if ($sla <= 0) {
            return ['deadline' => null, 'dueSoonAt' => null, 'escalateAt' => null];
        }
        $clock = $this->settings->clock();
        $deadline = $clock->add($entered, $sla);
        $before = (float) $stage->reminder_before_hours;
        $after = $stage->escalate_after_hours;

        return [
            'deadline' => $deadline,
            'dueSoonAt' => $before > 0 ? $clock->add($entered, max(0.0, $sla - $before)) : null,
            'escalateAt' => $after === null ? null : ((float) $after > 0 ? $clock->add($deadline, (float) $after) : $deadline->copy()),
        ];
    }

    /** on_track · due_soon · late · escalated — or none when the stage has no target time or the request is closed. */
    public static function sla(CreditRequest $r, ?DateTimeInterface $now = null): string
    {
        if (! Flow::isOpen($r->state) || $r->sla_deadline === null) {
            return 'none';
        }
        if ($r->escalated_at !== null) {
            return 'escalated';
        }
        $now = Carbon::instance($now ?? now());
        if ($now->gte($r->sla_deadline)) {
            return 'late';
        }

        return $r->sla_due_soon_at !== null && $now->gte($r->sla_due_soon_at) ? 'due_soon' : 'on_track';
    }

    // ───────────── sending ─────────────

    public function submit(AuthUser $u, string $id): string
    {
        return DB::transaction(function () use ($u, $id) {
            $r = CreditRequest::locate($id, true);
            self::refuseIf($this->blockSubmit($u, $r));
            $this->send($u, $r);

            return $r->id;
        });
    }

    /** draft | changes requested → the first working stage. Call inside the transaction that holds the request's row. */
    public function send(AuthUser $u, CreditRequest $r): void
    {
        $first = $this->path()->first()
            ?? throw AppError::rule('CREDIT_NO_STAGES', 'سير العمل بلا مراحل مراجعة نشطة — أضف مرحلة واحدة على الأقل من إعدادات الائتمان', 'The workflow has no active working stage — add at least one in the credit settings');
        if ($missing = $this->missingToSend($r)) {
            throw AppError::validation('CREDIT_FIELDS_REQUIRED', 'لا يُقدَّم الطلب قبل إكمال: '.implode('، ', array_column($missing, 'labelAr')),
                'The request cannot be submitted before these are filled in: '.implode(', ', array_column($missing, 'labelEn')), array_map(fn (array $m) => ['path' => $m['path'], 'message' => 'required'], $missing));
        }
        $this->assertTerms($r);
        $this->assertExit($r, $this->stage($r));
        $again = $r->state === 'changes_requested';
        $r->forceFill(['round' => (int) $r->round + 1, 'submitted_at' => now(), 'rejection_reason' => null, 'rejected_at' => null, 'rejected_by' => null])->save();
        $this->log->event($r, 'submitted', $u, null, ['round' => (int) $r->round, 'again' => $again]);
        $this->log->audit($u, $r, 'SUBMIT', null, ['round' => (int) $r->round, 'requestedCreditLimit' => Text::money($r->requested_credit_limit), 'requestedPaymentDays' => $r->requested_payment_days]);
        $limit = number_format((float) $r->requested_credit_limit, 2).' '.($r->currency ?: 'SAR');
        $this->notifier->tell($r, 'request.submitted', ($again ? 'أُعيد تقديم' : 'قُدِّم')." طلب الائتمان الآجل {$r->number} ({$r->company_name}) — الحد المطلوب {$limit}",
            "Deferred credit request {$r->number} ({$r->company_name}) was ".($again ? 'submitted again' : 'submitted')." — requested limit {$limit}", $u, ['feed' => true]);
        $this->enter($u, $r, $first);
        if ($first->portal_visible) {
            $this->notifier->tell($r, 'request.received', "استلمنا طلب حساب الائتمان الآجل {$r->number} وهو قيد المراجعة", "We received deferred credit request {$r->number}; it is under review", $u, ['customer' => true]);
        }
    }

    // ───────────── deciding a stage ─────────────

    /** @param  array<string, mixed>  $terms  the approved limit, days and term, and the notes, written with the answer */
    public function approve(AuthUser $u, string $id, ?string $note = null, array $terms = []): string
    {
        return DB::transaction(function () use ($u, $id, $note, $terms) {
            $r = CreditRequest::locate($id, true);
            $lines = $this->openLines($r);
            self::refuseIf($this->blockDecide($u, $r, $lines));
            $stage = $this->stage($r);
            $line = $this->myLine($u, $lines);
            if ($terms) {
                $this->writeAssessment($u, $r, $terms);
            }
            if ($this->isLast($stage)) {
                $this->assertGrant($r);
            }
            $this->assertExit($r, $stage);
            $line->decide('approved', $u, Text::clean($note, 2000));
            $this->log->event($r, 'approved', $u, Text::clean($note, 2000), ['stage' => $stage->code]);
            $this->log->audit($u, $r, 'STAGE.APPROVE', null, ['stage' => $stage->code, 'round' => (int) $r->round]);
            $others = $lines->reject(fn (CreditApproval $l) => $l->id === $line->id);
            if ($stage->approval_mode === 'all_users' && $others->isNotEmpty()) {
                return $r->id; // the others are still asked: the stage is decided when every one of them has approved
            }
            foreach ($others as $other) {
                $other->decide('skipped', null, 'اكتفت المرحلة بموافقة واحدة');
            }
            $this->leave($u, $r, $stage);

            return $r->id;
        });
    }

    public function reject(AuthUser $u, string $id, ?string $reason): string
    {
        return $this->sendOut($u, $id, 'rejected', self::reason($reason, 'سبب الرفض', 'the reason for the rejection'));
    }

    public function requestChanges(AuthUser $u, string $id, ?string $note): string
    {
        return $this->sendOut($u, $id, 'changes_requested', self::reason($note, 'ما المطلوب تعديله', 'what has to be changed'));
    }

    /** The manager moves a request past a stage nobody answers. Never the last stage, never his own request; the stage's requirements still hold. */
    public function skip(AuthUser $u, string $id, ?string $reason): string
    {
        $reason = self::reason($reason, 'سبب تخطي المرحلة', 'the reason for skipping the stage');

        return DB::transaction(function () use ($u, $id, $reason) {
            $r = CreditRequest::locate($id, true);
            self::refuseIf($this->blockSkip($u, $r));
            $stage = $this->stage($r);
            $this->assertExit($r, $stage);
            foreach ($this->openLines($r) as $line) {
                $line->decide('skipped', $u, $reason);
            }
            $this->log->event($r, 'skipped', $u, $reason, ['stage' => $stage->code]);
            $this->log->audit($u, $r, 'STAGE.SKIP', null, ['stage' => $stage->code, 'reason' => $reason]);
            $this->leave($u, $r, $stage);

            return $r->id;
        });
    }

    /**
     * The lines of the stage are opened again as the stage is configured NOW (its role changed, a person left, a group
     * of Odoo got its OPS role): the lines that waited are closed as skipped. The target time of the stage stays.
     */
    public function refreshApprovers(AuthUser $u, string $id, ?string $reason): string
    {
        return DB::transaction(function () use ($u, $id, $reason) {
            $r = CreditRequest::locate($id, true);
            self::refuseIf($this->blockManage($u, $r));
            $stage = $this->stage($r);
            $reason = Text::clean($reason, 500);
            foreach ($this->openLines($r) as $line) {
                $line->decide('skipped', $u, $reason ?? 'أُعيد إصدار طلب القرار');
            }
            $lines = $this->openStage($r, $stage, $r->sla_deadline);
            $this->log->event($r, 'approvers_refreshed', $u, $reason, ['stage' => $stage->code, 'lines' => $lines->count()]);
            $this->log->audit($u, $r, 'STAGE.REFRESH', null, ['stage' => $stage->code, 'lines' => $lines->count()]);
            $this->ask($u, $r, $stage, $lines);

            return $r->id;
        });
    }

    /**
     * The assessment of a request while it is worked: the finance recommendation, the legal notes, the chief
     * executive's notes, and what is to be granted (limit, days, term).
     */
    public function assess(AuthUser $u, string $id, array $dto): string
    {
        return DB::transaction(function () use ($u, $id, $dto) {
            $r = CreditRequest::locate($id, true);
            self::refuseIf($this->blockAssess($u, $r));
            $this->writeAssessment($u, $r, $dto);

            return $r->id;
        });
    }

    // ───────────── the requester's and the manager's moves ─────────────

    /** The requester takes his request back to a draft: what waited for a decision is closed, and the path starts again when he sends it. */
    public function withdraw(AuthUser $u, string $id, ?string $reason): string
    {
        return DB::transaction(function () use ($u, $id, $reason) {
            $r = CreditRequest::locate($id, true);
            self::refuseIf($this->blockWithdraw($u, $r));
            $reason = Text::clean($reason, 1000);
            $asked = $this->closeWaiting($u, $r, 'سحب مقدّم الطلب طلبه');
            $this->signature->voidWaiting($u, $r, 'سُحب الطلب للتعديل');
            $this->log->event($r, 'withdrawn', $u, $reason, ['from' => $r->state]);
            $this->log->audit($u, $r, 'WITHDRAW', ['state' => $r->state], ['state' => 'draft', 'reason' => $reason]);
            $this->enter($u, $r, $this->single('draft'), $reason);
            $this->notifier->tell($r, 'request.withdrawn', "سُحب طلب الائتمان {$r->number} ({$r->company_name}) للتعديل — لا قرار مطلوب الآن", "Credit request {$r->number} ({$r->company_name}) was withdrawn for changes — no decision is needed now", $u, $asked);

            return $r->id;
        });
    }

    /**
     * Cancels a request. An approved one is revoked by the manager — and the customer's limit is left exactly as it
     * is: taking it back is a separate, explicit step (CustomerCredit::restore).
     *
     * @return array{id:string, revoked:bool}
     */
    public function cancel(AuthUser $u, string $id, ?string $reason): array
    {
        return DB::transaction(function () use ($u, $id, $reason) {
            $r = CreditRequest::locate($id, true);
            self::refuseIf($this->blockCancel($u, $r));
            $from = $r->state;
            $reason = $from === 'draft' ? Text::clean($reason, 1000) : self::reason($reason, 'سبب الإلغاء', 'the reason for the cancellation');
            $asked = $this->closeWaiting($u, $r, 'أُلغي الطلب');
            $this->signature->voidWaiting($u, $r, 'أُلغي الطلب');
            $revoked = $from === 'approved';
            $r->forceFill(['cancelled_at' => now(), 'cancelled_by' => $u->nameAr, 'cancel_reason' => $reason])->save();
            // said, not done: the limit this request put on the customer stays until a person takes it back
            $kept = $revoked && $r->apply_status === 'applied' ? ['customerUnchanged' => true, 'creditLimit' => $r->applied_changes['creditLimit']['to'] ?? null, 'terms' => $r->applied_changes['terms']['to'] ?? null] : [];
            $this->log->event($r, 'cancelled', $u, $reason, ['from' => $from, 'revoked' => $revoked] + $kept);
            $this->log->audit($u, $r, $revoked ? 'REVOKE' : 'CANCEL', ['state' => $from], ['state' => 'cancelled', 'reason' => $reason] + $kept);
            $this->enter($u, $r, $this->single('cancelled'), $reason);
            if ($from !== 'draft') {
                $because = $reason ? " — {$reason}" : '';
                $this->notifier->tell($r, $revoked ? 'request.revoked' : 'request.cancelled',
                    ($revoked ? "أُلغي اعتماد حساب الائتمان {$r->number} ({$r->company_name})" : "أُلغي طلب الائتمان {$r->number} ({$r->company_name})").$because,
                    ($revoked ? "The approval of credit account {$r->number} ({$r->company_name}) was revoked" : "Credit request {$r->number} ({$r->company_name}) was cancelled").$because, $u,
                    ['roles' => $asked['roles'], 'users' => [...$asked['users'], $r->requester_id], 'feed' => true]);
            }

            return ['id' => $r->id, 'revoked' => $revoked];
        });
    }

    /** A rejected or cancelled request is taken back to a draft, to be corrected and sent again — unless it was approved once. */
    public function reopen(AuthUser $u, string $id): string
    {
        return DB::transaction(function () use ($u, $id) {
            $r = CreditRequest::locate($id, true);
            self::refuseIf($this->blockReopen($u, $r));
            $from = $r->state;
            $r->forceFill(['cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null])->save();
            $this->log->event($r, 'reopened', $u, null, ['from' => $from]);
            $this->log->audit($u, $r, 'REOPEN', ['state' => $from], ['state' => 'draft']);
            $this->enter($u, $r, $this->single('draft'));

            return $r->id;
        });
    }

    /** Writes the approved limit and term on the customer again after the customer service refused it the first time. */
    public function apply(AuthUser $u, string $id): string
    {
        return DB::transaction(function () use ($u, $id) {
            $r = CreditRequest::locate($id, true);
            self::refuseIf($this->blockApply($u, $r));
            $out = $this->customer->apply($u, $r);
            $this->tellApplied($u, $r, $out['status']);

            return $r->id;
        });
    }

    /** Puts the customer's previous limit and terms back after the approval was revoked — the manager's explicit step. */
    public function restoreCustomer(AuthUser $u, string $id, ?string $reason): string
    {
        return DB::transaction(function () use ($u, $id, $reason) {
            $r = CreditRequest::locate($id, true);
            self::refuseIf(Owned::bySource($r) ? Owned::error($r, 'request') : null);
            $this->customer->restore($u, $r, $reason);

            return $r->id;
        });
    }

    // ───────────── who may ─────────────

    public function blockSubmit(AuthUser $u, CreditRequest $r): ?AppError
    {
        if (Owned::bySource($r)) {
            return Owned::error($r, 'request');
        }
        if (! in_array($r->state, Flow::EDITABLE, true)) {
            [$ar, $en] = Flow::label($r->state);

            return AppError::rule('CREDIT_TRANSITION', "الطلب {$r->number} ليس مسودة ولا مُعادًا للتعديل — حالته الآن «{$ar}»", "Request {$r->number} is neither a draft nor sent back for changes — it is {$en}", ['from' => $r->state]);
        }

        return Privacy::isRequester($u, $r) || Access::manages($u) ? null : Access::notYours($r);
    }

    /** Why this user may not answer the stage the request stands in (null = he may). */
    public function blockDecide(AuthUser $u, CreditRequest $r, ?Collection $lines = null): ?AppError
    {
        if (Owned::bySource($r)) {
            return Owned::error($r, 'request');
        }
        if (! Flow::isWork($r->state)) {
            [$ar, $en] = Flow::label($r->state);

            return AppError::rule('CREDIT_NOT_PENDING', "الطلب {$r->number} لا ينتظر قرارًا الآن — حالته «{$ar}»", "Request {$r->number} is not waiting for a decision — it is {$en}", ['state' => $r->state]);
        }
        if (Privacy::isRequester($u, $r)) {
            return AppError::rule('CREDIT_SELF_APPROVAL', 'لا يقرّر أحد في طلب أدخله بنفسه — القرار لغير مقدّم الطلب', 'Nobody decides a request he entered himself');
        }
        if (! Access::decides($u)) {
            return AppError::forbidden('CREDIT_DECIDE_PERMISSION', 'قرار المرحلة يحتاج صلاحية مراجعة الائتمان أو اعتماده (credit.review / credit.approve)', 'A stage decision needs credit.review or credit.approve');
        }
        $lines ??= $this->openLines($r);
        if ($this->myLine($u, $lines) === null) {
            $answered = CreditApproval::where('request_id', $r->id)->where('stage_id', $r->stage_id)->where('round', $r->round)->where('state', 'approved')->where('decided_by_id', $u->id)->exists();

            return $answered
                ? AppError::rule('CREDIT_ALREADY_DECIDED', 'سجّلت موافقتك على هذه المرحلة مسبقًا — المرحلة بانتظار بقية المعتمدين', 'You have already approved this stage — it waits for the other approvers')
                : AppError::forbidden('CREDIT_NOT_APPROVER', "لست ممن يقرّرون مرحلة «{$r->stage?->name_ar}» للطلب {$r->number}", "You are not among those who decide stage \"{$r->stage?->name_en}\" of request {$r->number}");
        }
        $stage = $this->stage($r);
        if ($this->isLast($stage) && ! $u->can('credit.approve')) {
            return AppError::forbidden('CREDIT_FINAL_PERMISSION', "مرحلة «{$stage->name_ar}» هي الاعتماد النهائي الذي يمنح الائتمان — تحتاج صلاحية اعتماد الائتمان (credit.approve)",
                "Stage \"{$stage->name_en}\" is the final approval that grants the credit — it needs credit.approve");
        }

        return null;
    }

    public function blockAssess(AuthUser $u, CreditRequest $r): ?AppError
    {
        if (Owned::bySource($r)) {
            return Owned::error($r, 'request');
        }
        if (! Flow::isWork($r->state)) {
            [$ar, $en] = Flow::label($r->state);

            return AppError::rule('CREDIT_NOT_PENDING', "يُكتب تقييم الطلب أثناء مراجعته — حالة الطلب {$r->number} الآن «{$ar}»", "A request is assessed while it is reviewed — request {$r->number} is {$en}", ['state' => $r->state]);
        }
        if (Privacy::isRequester($u, $r)) {
            return AppError::rule('CREDIT_SELF_APPROVAL', 'لا يقيّم أحد طلبًا أدخله بنفسه ولا يحدّد ما يُمنح فيه', 'Nobody assesses a request he entered himself, nor sets what it is granted');
        }

        return Access::decides($u) ? null : AppError::forbidden('CREDIT_DECIDE_PERMISSION', 'تقييم الطلب يحتاج صلاحية مراجعة الائتمان أو اعتماده', 'Assessing a request needs credit.review or credit.approve');
    }

    /** The manager's administrative moves on a request that is being worked. */
    public function blockManage(AuthUser $u, CreditRequest $r): ?AppError
    {
        if (Owned::bySource($r)) {
            return Owned::error($r, 'request');
        }
        if (! Access::manages($u)) {
            return AppError::forbidden('CREDIT_MANAGE_PERMISSION', 'هذا الإجراء لمدير الائتمان (credit.manage)', 'This action is for the credit manager (credit.manage)');
        }
        if (! Flow::isWork($r->state)) {
            [$ar, $en] = Flow::label($r->state);

            return AppError::rule('CREDIT_NOT_PENDING', "الطلب {$r->number} ليس في مرحلة مراجعة — حالته «{$ar}»", "Request {$r->number} is not in a working stage — it is {$en}", ['state' => $r->state]);
        }

        return null;
    }

    public function blockSkip(AuthUser $u, CreditRequest $r): ?AppError
    {
        if ($e = $this->blockManage($u, $r)) {
            return $e;
        }
        if (Privacy::isRequester($u, $r)) {
            return AppError::rule('CREDIT_SELF_APPROVAL', 'لا يتخطى أحد مرحلة في طلب أدخله بنفسه', 'Nobody skips a stage of a request he entered himself');
        }
        $stage = $this->stage($r);

        return $this->isLast($stage)
            ? AppError::rule('CREDIT_FINAL_NOT_SKIPPABLE', "مرحلة «{$stage->name_ar}» هي الاعتماد النهائي — لا تُتخطى: يقرّرها صاحب صلاحية اعتماد الائتمان", "Stage \"{$stage->name_en}\" is the final approval — it is never skipped: a holder of credit.approve decides it") : null;
    }

    public function blockWithdraw(AuthUser $u, CreditRequest $r): ?AppError
    {
        if (Owned::bySource($r)) {
            return Owned::error($r, 'request');
        }
        if (! Flow::isWork($r->state)) {
            [$ar, $en] = Flow::label($r->state);

            return AppError::rule('CREDIT_TRANSITION', "يُسحب الطلب وهو قيد المراجعة — حالة الطلب {$r->number} الآن «{$ar}»", "A request is withdrawn while it is under review — request {$r->number} is {$en}", ['from' => $r->state, 'to' => 'draft']);
        }

        return Privacy::isRequester($u, $r) ? null : Access::notYours($r);
    }

    public function blockCancel(AuthUser $u, CreditRequest $r): ?AppError
    {
        if (Owned::bySource($r)) {
            return Owned::error($r, 'request');
        }
        if (! Flow::can($r->state, 'cancelled')) {
            return $r->state === 'cancelled'
                ? AppError::rule('CREDIT_TRANSITION', "الطلب {$r->number} ملغى مسبقًا", "Request {$r->number} is already cancelled", ['from' => $r->state, 'to' => 'cancelled'])
                : Access::closed($r);
        }
        if ($r->state === 'approved') {
            return Access::manages($u) ? null
                : AppError::forbidden('CREDIT_REVOKE_PERMISSION', "الطلب {$r->number} معتمد — إلغاء اعتماده لمدير الائتمان (credit.manage)", "Request {$r->number} is approved — revoking it is for the credit manager (credit.manage)");
        }

        return Privacy::isRequester($u, $r) || Access::manages($u) ? null : Access::notYours($r);
    }

    public function blockReopen(AuthUser $u, CreditRequest $r): ?AppError
    {
        if (Owned::bySource($r)) {
            return Owned::error($r, 'request');
        }
        if (! in_array($r->state, ['rejected', 'cancelled'], true)) {
            [$ar, $en] = Flow::label($r->state);

            return AppError::rule('CREDIT_TRANSITION', "يُعاد إلى مسودة الطلب المرفوض أو الملغى — حالة الطلب {$r->number} الآن «{$ar}»", "A rejected or cancelled request is taken back to a draft — request {$r->number} is {$en}", ['from' => $r->state, 'to' => 'draft']);
        }
        if ($r->approved_at !== null) {
            return AppError::rule('CREDIT_REOPEN_APPROVED', "الطلب {$r->number} اعتُمد ثم أُلغي اعتماده — لا يُعاد فتحه: يُقدَّم طلب جديد", "Request {$r->number} was approved and then revoked — it is not reopened: a new request is raised");
        }

        return Privacy::isRequester($u, $r) || Access::manages($u) ? null : Access::notYours($r);
    }

    public function blockApply(AuthUser $u, CreditRequest $r): ?AppError
    {
        if (Owned::bySource($r)) {
            return Owned::error($r, 'request');
        }
        if (! $u->can('credit.approve') && ! Access::manages($u)) {
            return AppError::forbidden('CREDIT_APPLY_PERMISSION', 'تطبيق الاعتماد على العميل لمعتمد الائتمان أو مديره', 'Applying the approval to the customer is for the credit approver or manager');
        }
        if ($r->state !== 'approved') {
            return AppError::rule('CREDIT_NOT_APPROVED', "الطلب {$r->number} غير معتمد — لا يُطبَّق على العميل شيء قبل الاعتماد النهائي", "Request {$r->number} is not approved — nothing reaches the customer before the final approval");
        }

        return in_array($r->apply_status, ['applied', 'restored'], true)
            ? AppError::rule('CREDIT_ALREADY_APPLIED', "طُبِّق الطلب {$r->number} على العميل مسبقًا — لا يُطبَّق مرتين", "Request {$r->number} was already applied to the customer — it is applied once") : null;
    }

    // ───────────── what the stage the request stands in asks for ─────────────

    /** @return Collection<int, CreditApproval> the lines of the current stay that still wait */
    public function openLines(CreditRequest $r): Collection
    {
        if ($r->stage_id === null) {
            return collect();
        }

        return CreditApproval::where('request_id', $r->id)->where('stage_id', $r->stage_id)->where('round', $r->round)->whereIn('state', Flow::LINE_OPEN)
            ->orderBy('created_at')->orderBy('id')->get();
    }

    /** The waiting line this user answers: his own by name first, then one he is among the people of. */
    public function myLine(AuthUser $u, Collection $lines): ?CreditApproval
    {
        return $lines->first(fn (CreditApproval $l) => $l->personal && $this->addressed($u, $l)) ?? $lines->first(fn (CreditApproval $l) => $this->addressed($u, $l));
    }

    /** Is this user one of the people a waiting line asks? (The permission is checked apart: blockDecide.) */
    public function addressed(AuthUser $u, CreditApproval $line): bool
    {
        $named = $line->user_id !== null || $line->user_login !== null;
        if ($named && $this->people->isMine($u, $line->user_id, $line->user_login)) {
            return true;
        }
        if (! $line->personal && $line->role_key !== null && $u->hasRole($line->role_key)) {
            return true;
        }
        if ($line->state === 'escalated') {
            // escalated to a role — or, when the stage names none, to the managers of the section
            return $line->escalated_to_role !== null ? $u->hasRole($line->escalated_to_role) : Access::manages($u);
        }

        // a stage that names nobody (and no group of Odoo either): any holder of the permission answers
        return ! $line->personal && ! $named && $line->role_key === null && $line->role_name === null;
    }

    /**
     * What the stage the request stands in requires before it is left, and what is there.
     *
     * @return array{documents:array{required:bool, complete:bool, missing:string[]}, signature:array{required:bool, status:string, ok:bool, stale:bool}, grant:array{required:bool, ok:bool}}
     */
    public function requirements(CreditRequest $r): array
    {
        $stage = $r->stage;
        $missing = $this->documents->missing($r);
        $last = $stage !== null && $stage->isWork() && $this->isLast($stage);

        return [
            'documents' => ['required' => (bool) $stage?->require_documents, 'complete' => $missing === [], 'missing' => $missing],
            'signature' => ['required' => (bool) $stage?->require_signature] + $this->signature->state($r),
            'grant' => ['required' => $last, 'ok' => (float) $r->approved_credit_limit > 0 && (int) $r->approved_payment_days >= 1],
        ];
    }

    /** What is missing before the request may be sent at all. @return array<int, array{path:string, labelAr:string, labelEn:string}> */
    public function missingToSend(CreditRequest $r): array
    {
        $checks = [
            ['customerId', 'العميل', 'the customer', $r->customer_id !== null],
            ['companyName', 'اسم المنشأة', 'the company name', trim((string) $r->company_name) !== ''],
            ['commercialRegistration', 'رقم السجل التجاري', 'the commercial registration', trim((string) $r->commercial_registration) !== ''],
            ['ownerName', 'اسم المالك', 'the owner\'s name', trim((string) $r->owner_name) !== ''],
            ['requestedCreditLimit', 'الحد الائتماني المطلوب', 'the requested credit limit', (float) $r->requested_credit_limit > 0],
            ['requestedPaymentDays', 'مدة السداد المطلوبة', 'the requested payment days', (int) $r->requested_payment_days >= 1],
            ['signatories', 'مفوَّض واحد بالتوقيع على الأقل', 'at least one authorised signatory', $r->signatories()->exists()],
        ];
        $out = [];
        foreach ($checks as [$path, $ar, $en, $ok]) {
            if (! $ok) {
                $out[] = ['path' => $path, 'labelAr' => $ar, 'labelEn' => $en];
            }
        }

        return $out;
    }

    /** The stages of the path with where the request is on it — what the client draws as steps. */
    public function steps(CreditRequest $r): array
    {
        // while the requester works on it the path lies ahead as a whole: sent again, it starts at the first stage
        $ahead = in_array($r->state, Flow::EDITABLE, true);
        $done = $ahead ? [] : CreditApproval::where('request_id', $r->id)->where('round', $r->round)->whereIn('state', ['approved', 'skipped'])->distinct()->pluck('stage_id')->all();
        $out = [];
        foreach ($this->path() as $stage) {
            $status = $stage->id === $r->stage_id ? 'current' : ($r->state === 'approved' || in_array($stage->id, $done, true) ? 'done' : 'upcoming');
            $out[] = $stage->brief() + ['status' => $status, 'requireDocuments' => (bool) $stage->require_documents, 'requireSignature' => (bool) $stage->require_signature, 'slaHours' => Text::hours($stage->sla_hours)];
        }

        return $out;
    }

    // ───────────── the mechanics ─────────────

    private function stage(CreditRequest $r): CreditStage
    {
        return $r->stage ?? throw AppError::rule('CREDIT_STAGE_MISSING', "الطلب {$r->number} لا يقف في مرحلة — عيّن له مرحلة من إعدادات الائتمان", "Request {$r->number} stands in no stage");
    }

    /** rejected | changes_requested: the answer of one approver sends the request out of the path. */
    private function sendOut(AuthUser $u, string $id, string $answer, string $text): string
    {
        return DB::transaction(function () use ($u, $id, $answer, $text) {
            $r = CreditRequest::locate($id, true);
            $lines = $this->openLines($r);
            self::refuseIf($this->blockDecide($u, $r, $lines));
            $stage = $this->stage($r);
            $line = $this->myLine($u, $lines);
            $line->decide($answer, $u, $text);
            $rejected = $answer === 'rejected';
            foreach ($lines->reject(fn (CreditApproval $l) => $l->id === $line->id) as $other) {
                $other->decide('skipped', null, $rejected ? 'أُغلق: رُفض الطلب' : 'أُغلق: أُعيد الطلب للتعديل');
            }
            $this->signature->voidWaiting($u, $r, $rejected ? 'رُفض الطلب' : 'أُعيد الطلب للتعديل');
            $r->forceFill($rejected ? ['rejection_reason' => $text, 'rejected_at' => now(), 'rejected_by' => $u->nameAr] : ['changes_requested_note' => $text])->save();
            $this->log->event($r, $answer, $u, $text, ['stage' => $stage->code]);
            $this->log->audit($u, $r, $rejected ? 'STAGE.REJECT' : 'STAGE.CHANGES', null, ['stage' => $stage->code, 'round' => (int) $r->round, 'note' => $text]);
            $to = $this->single($answer);
            $this->enter($u, $r, $to, $text);
            $rejected
                ? $this->notifier->tell($r, 'request.rejected', "رُفض طلب حساب الائتمان الآجل {$r->number}: {$text}", "Deferred credit request {$r->number} was rejected: {$text}", $u, ['users' => [$r->requester_id], 'customer' => (bool) $to->portal_visible, 'feed' => true])
                : $this->notifier->tell($r, 'request.changes_requested', "طلب حساب الائتمان الآجل {$r->number} يحتاج تعديلًا: {$text}", "Deferred credit request {$r->number} needs changes: {$text}", $u, ['users' => [$r->requester_id], 'customer' => (bool) $to->portal_visible, 'feed' => true]);

            return $r->id;
        });
    }

    /** The stage is decided: on to the next one, or — after the last — the credit is granted. */
    private function leave(AuthUser $u, CreditRequest $r, CreditStage $stage): void
    {
        if ($next = $this->next($stage)) {
            $this->enter($u, $r, $next);

            return;
        }
        if (! $u->can('credit.approve')) {
            // unreachable through the checks above (the last stage is answered with credit.approve and is never skipped);
            // kept as the last guard of the one rule that must never fail
            throw AppError::forbidden('CREDIT_FINAL_PERMISSION', 'الاعتماد النهائي يحتاج صلاحية اعتماد الائتمان (credit.approve)', 'The final approval needs credit.approve');
        }
        $this->assertGrant($r);
        $r->forceFill(['approved_at' => now(), 'approved_by_id' => $u->id, 'approved_by' => $u->nameAr])->save();
        $to = $this->single('approved');
        $this->enter($u, $r, $to);
        $granted = ['approvedCreditLimit' => Text::money($r->approved_credit_limit), 'approvedPaymentDays' => (int) $r->approved_payment_days, 'approvedTerm' => $r->approved_term ?: CustomerCredit::termsFor((int) $r->approved_payment_days)];
        $this->log->audit($u, $r, 'APPROVE', null, $granted);
        $out = $this->customer->apply($u, $r);
        $limit = number_format((float) $r->approved_credit_limit, 2).' '.($r->currency ?: 'SAR');
        $this->notifier->tell($r, 'request.approved', "اعتُمد حساب الائتمان الآجل {$r->number} ({$r->company_name}): حد {$limit} ومدة سداد {$granted['approvedPaymentDays']} يومًا",
            "Deferred credit account {$r->number} ({$r->company_name}) was approved: limit {$limit}, payment within {$granted['approvedPaymentDays']} days", $u,
            ['users' => [$r->requester_id], 'customer' => (bool) $to->portal_visible, 'feed' => true]);
        $this->tellApplied($u, $r, $out['status']);
    }

    /** When the customer service refused the approved limit, the managers of the section are told why. */
    private function tellApplied(AuthUser $u, CreditRequest $r, string $status): void
    {
        if ($status !== 'blocked') {
            return;
        }
        $why = (string) ($r->apply_error['messageAr'] ?? '');
        $whyEn = (string) ($r->apply_error['messageEn'] ?? $why);
        $this->notifier->tell($r, 'credit.apply_blocked', "اعتُمد الطلب {$r->number} ولم يُطبَّق حدّه على العميل: {$why}", "Request {$r->number} was approved and its limit was NOT applied to the customer: {$whyEn}", $u,
            ['users' => [...$this->people->holders(['credit.manage'])->pluck('id')->all(), $r->approved_by_id]]);
    }

    /** The request enters a stage: its state, its target time, its approval lines, and the people who are asked. */
    private function enter(?AuthUser $by, CreditRequest $r, CreditStage $to, ?string $note = null): void
    {
        $from = $r->state;
        Flow::assert($from, $to->state);
        if ($to->isWork() && $to->unmapped()) {
            throw AppError::rule('CREDIT_STAGE_UNMAPPED', "مرحلة «{$to->name_ar}» تسمّي مجموعة من أودو («{$to->responsible_role_name}») لا دور يقابلها في نظام العمليات — حدّد دورها من إعدادات الائتمان أولًا",
                "Stage \"{$to->name_en}\" names an Odoo group (\"{$to->responsible_role_name}\") that no OPS role answers to — set its role in the credit settings first", ['stage' => $to->code]);
        }
        $entered = now();
        $sla = $this->deadlines($to, $entered);
        $r->forceFill([
            'stage_id' => $to->id, 'state' => $to->state, 'stage_entered_at' => $entered, 'sla_deadline' => $sla['deadline'], 'sla_due_soon_at' => $sla['dueSoonAt'], 'sla_escalate_at' => $sla['escalateAt'],
            'last_reminder_at' => null, 'reminder_count' => 0, 'escalated_at' => null, 'sla_checked_at' => null,
        ])->save();
        $r->setRelation('stage', $to);
        $this->log->status($by, $r, $from, $to->state, $note);
        $this->log->event($r, 'stage', $by, null, ['from' => $from, 'to' => $to->state, 'stage' => $to->code, 'deadline' => $sla['deadline']?->toJSON()]);
        if (! $to->isWork()) {
            return;
        }
        $this->ask($by, $r, $to, $this->openStage($r, $to, $sla['deadline']));
        if ($to->require_signature && $to->auto_send_signature) {
            $this->signature->issueAutomatically($by, $r);
        }
    }

    /**
     * Opens the approval lines of a stay in a working stage: one line for "any user", one per person for "all users".
     * A stage nobody could answer is not entered (the move that led to it is refused as a whole).
     *
     * @return Collection<int, CreditApproval>
     */
    private function openStage(CreditRequest $r, CreditStage $stage, ?DateTimeInterface $deadline): Collection
    {
        $userId = $this->people->userId($stage->responsible_user_id, $stage->responsible_user_login);
        // "nobody named" is a stage that names no role, no person and no group of Odoo either: then any holder decides
        $nobody = $stage->responsible_role === null && $userId === null && $stage->responsible_user_login === null && $stage->responsible_user_name === null && ! $stage->unmapped();
        $people = $this->people->holders($this->permissionsFor($stage), $stage->responsible_role, $userId, $nobody)
            ->reject(fn ($p) => $r->requester_id !== null && strcasecmp((string) $p->id, (string) $r->requester_id) === 0)->values();
        if ($people->isEmpty()) {
            $needs = implode(' / ', $this->permissionsFor($stage));
            throw AppError::rule('CREDIT_STAGE_NO_APPROVER', "لا أحد يستطيع قرار مرحلة «{$stage->name_ar}»: لا مستخدم نشطًا ممن تسمّيهم المرحلة يحمل الصلاحية {$needs} (غير مقدّم الطلب) — راجع دور المرحلة وصلاحيات أصحابه",
                "Nobody can decide stage \"{$stage->name_en}\": no active user the stage names holds {$needs} (other than the requester) — check the stage's role and its users' permissions", ['stage' => $stage->code]);
        }
        $base = ['request_id' => $r->id, 'stage_id' => $stage->id, 'round' => (int) $r->round, 'state' => 'pending', 'deadline' => $deadline];
        $lines = collect();
        if ($stage->approval_mode === 'all_users') {
            foreach ($people as $p) {
                $lines->push(CreditApproval::create($base + ['user_id' => $p->id, 'user_name' => $p->name_ar, 'role_key' => $stage->responsible_role, 'personal' => true]));
            }

            return $lines;
        }
        $named = $userId !== null ? $people->firstWhere('id', $userId) : null;

        return $lines->push(CreditApproval::create($base + [
            'role_key' => $stage->responsible_role, 'user_id' => $named?->id, 'user_name' => $named?->name_ar, 'personal' => $stage->responsible_role === null && $named !== null,
        ]));
    }

    /** Tells the people of the lines that a request waits for them, and by when. */
    private function ask(?AuthUser $by, CreditRequest $r, CreditStage $stage, Collection $lines): void
    {
        $to = $this->audience($lines, $stage);
        // the requester may hold the permission too: he is not asked about his own request
        $to['users'] = array_values(array_filter($to['users'], fn (string $id) => $r->requester_id === null || strcasecmp($id, (string) $r->requester_id) !== 0));
        $limit = number_format((float) $r->requested_credit_limit, 2).' '.($r->currency ?: 'SAR');
        $due = $r->sla_deadline ? $r->sla_deadline->copy()->setTimezone((string) config('app.business_timezone', 'UTC'))->format('Y-m-d H:i') : null;
        $this->notifier->tell($r, 'stage.entered',
            "طلب الائتمان الآجل {$r->number} ({$r->company_name}، الحد المطلوب {$limit}) ينتظر القرار في مرحلة «{$stage->name_ar}»".($due ? " — المهلة حتى {$due}" : ''),
            "Deferred credit request {$r->number} ({$r->company_name}, requested limit {$limit}) is waiting for a decision in stage \"{$stage->name_en}\"".($due ? " — due by {$due}" : ''), $by, $to);
    }

    /**
     * Who the lines of a stage ask: the roles (their bell) and the people named (their own).
     *
     * @return array{roles:string[], users:string[]}
     */
    public function audience(Collection $lines, ?CreditStage $stage = null): array
    {
        $roles = $users = [];
        $open = false;
        foreach ($lines as $line) {
            if ($line->user_id !== null) {
                $users[] = (string) $line->user_id;
            }
            if (! $line->personal && $line->role_key !== null) {
                $roles[] = (string) $line->role_key;
            }
            if ($line->state === 'escalated' && $line->escalated_to_role !== null) {
                $roles[] = (string) $line->escalated_to_role;
            }
            $open = $open || (! $line->personal && $line->user_id === null && $line->role_key === null && $line->role_name === null);
        }
        if ($open && $stage) {
            // the stage names nobody: every holder of the permission is asked
            $users = [...$users, ...$this->people->holders($this->permissionsFor($stage))->pluck('id')->all()];
        }

        return ['roles' => array_values(array_unique($roles)), 'users' => array_values(array_unique($users))];
    }

    /** Closes the lines that wait, without an answer, and says who had been asked. @return array{roles:string[], users:string[]} */
    private function closeWaiting(AuthUser $u, CreditRequest $r, string $why): array
    {
        $lines = $this->openLines($r);
        $asked = $this->audience($lines, $r->stage);
        foreach ($lines as $line) {
            $line->decide('skipped', $u, $why);
        }

        return $asked;
    }

    private function writeAssessment(AuthUser $u, CreditRequest $r, array $dto): void
    {
        if (array_key_exists('ceoNotes', $dto) && ! $u->can('credit.approve')) {
            throw AppError::forbidden('CREDIT_CEO_NOTES_PERMISSION', 'ملاحظات الإدارة العليا يكتبها صاحب صلاحية اعتماد الائتمان (credit.approve)', 'The chief executive\'s notes are written by a holder of credit.approve');
        }
        $values = [];
        foreach (['financeRecommendation' => 'finance_recommendation', 'legalNotes' => 'legal_notes', 'ceoNotes' => 'ceo_notes'] as $key => $column) {
            if (array_key_exists($key, $dto)) {
                $values[$column] = Text::clean($dto[$key], 8000);
            }
        }
        if (array_key_exists('approvedCreditLimit', $dto)) {
            $values['approved_credit_limit'] = $dto['approvedCreditLimit'] === null || $dto['approvedCreditLimit'] === '' ? null : round((float) $dto['approvedCreditLimit'], 2);
        }
        if (array_key_exists('approvedPaymentDays', $dto)) {
            $values['approved_payment_days'] = $dto['approvedPaymentDays'] === null || $dto['approvedPaymentDays'] === '' ? null : (int) $dto['approvedPaymentDays'];
        }
        if (array_key_exists('approvedTerm', $dto)) {
            $values['approved_term'] = Text::clean($dto['approvedTerm'], 120);
        }
        $old = $new = [];
        foreach ($values as $column => $value) {
            $current = $r->getAttribute($column);
            $same = is_numeric($current) && is_numeric($value) ? abs((float) $current - (float) $value) < 0.005 : (string) ($current ?? '') === (string) ($value ?? '');
            if (! $same) {
                $old[$column] = $current;
                $new[$column] = $value;
            }
        }
        if (! $new) {
            return;
        }
        $r->forceFill($new)->save();
        $this->log->event($r, 'assessed', $u, null, ['fields' => array_keys($new)]);
        $this->log->audit($u, $r, 'ASSESS', $old, $new);
    }

    /** The accepted terms are the version in force (when one is published at all). */
    private function assertTerms(CreditRequest $r): void
    {
        $inForce = AgreementService::termsInForce();
        if (! $inForce) {
            return;
        }
        if (! $r->terms_snapshot) {
            throw AppError::rule('CREDIT_TERMS_NOT_ACCEPTED', "لا يُقدَّم الطلب قبل تسجيل قبول العميل للشروط والأحكام (النسخة {$inForce->version})", "The request cannot be submitted before the customer's acceptance of the terms is recorded (version {$inForce->version})", ['version' => $inForce->version]);
        }
        if (($r->terms_snapshot['hash'] ?? null) !== $inForce->snapshot()['hash']) {
            $had = (string) ($r->terms_snapshot['version'] ?? '');
            throw AppError::rule('CREDIT_TERMS_OUTDATED', "قَبِل العميل النسخة {$had} من الشروط والسارية الآن {$inForce->version} — يُسجَّل قبوله للنسخة السارية قبل التقديم",
                "The customer accepted version {$had} of the terms and version {$inForce->version} is in force now — record the acceptance of the version in force before submitting", ['accepted' => $had, 'inForce' => $inForce->version]);
        }
    }

    /** The requirements of the stage that is being left. */
    private function assertExit(CreditRequest $r, CreditStage $stage): void
    {
        if ($stage->require_documents && ($missing = $this->documents->missing($r))) {
            $ar = implode('، ', array_map(fn (string $slot) => Slots::label($slot)[0], $missing));
            $en = implode(', ', array_map(fn (string $slot) => Slots::label($slot)[1], $missing));
            throw AppError::rule('CREDIT_DOCUMENTS_REQUIRED', "لا يغادر الطلب مرحلة «{$stage->name_ar}» قبل اكتمال المستندات المطلوبة — ينقص: {$ar}",
                "The request cannot leave stage \"{$stage->name_en}\" before the required documents are complete — missing: {$en}", ['stage' => $stage->code, 'missing' => $missing]);
        }
        if ($stage->require_signature) {
            $state = $this->signature->state($r);
            if ($state['stale']) {
                throw AppError::rule('CREDIT_SIGNATURE_STALE', 'ما في الطلب الآن يختلف عمّا وقّعه العميل (الحد أو المدة أو الشروط أو المفوَّضون) — تُصدر الاتفاقية للتوقيع من جديد، أو يُعاد ما وُقِّع عليه',
                    'What the request says now differs from what the customer signed (the limit, the days, the terms or the signatories) — issue the agreement for signature again, or put back what was signed', ['stage' => $stage->code]);
            }
            if (! $state['ok']) {
                throw AppError::rule('CREDIT_SIGNATURE_REQUIRED', "لا يغادر الطلب مرحلة «{$stage->name_ar}» قبل توقيع العميل على الاتفاقية — حالة التوقيع الآن: {$state['status']}",
                    "The request cannot leave stage \"{$stage->name_en}\" before the customer has signed the agreement — the signature is: {$state['status']}", ['stage' => $stage->code, 'signature' => $state['status']]);
            }
        }
    }

    /** What is granted is written down before it is granted. */
    private function assertGrant(CreditRequest $r): void
    {
        if (! ((float) $r->approved_credit_limit > 0) || (int) $r->approved_payment_days < 1) {
            throw AppError::validation('CREDIT_GRANT_REQUIRED', 'لا يُعتمد الطلب قبل تحديد الحد الائتماني المعتمد (أكبر من صفر) ومدة السداد المعتمدة (يوم واحد على الأقل)',
                'The request cannot be approved before the approved credit limit (above zero) and the approved payment days (one at least) are set',
                [['path' => 'approvedCreditLimit', 'message' => 'required'], ['path' => 'approvedPaymentDays', 'message' => 'required']]);
        }
    }

    private static function reason(?string $text, string $whatAr, string $whatEn): string
    {
        $text = Text::clean($text, 2000);
        if ($text === null || mb_strlen($text) < 3) {
            throw AppError::validation('CREDIT_REASON_REQUIRED', "اكتب {$whatAr} (3 أحرف على الأقل)", "Write {$whatEn} (3 characters at least)", [['path' => 'reason', 'message' => 'required']]);
        }

        return $text;
    }

    private static function refuseIf(?AppError $e): void
    {
        if ($e) {
            throw $e;
        }
    }
}
