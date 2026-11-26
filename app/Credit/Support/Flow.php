<?php

namespace App\Credit\Support;

use App\Support\AppError;

/**
 * The words of a deferred-credit request and the moves it may make — the same whoever asks.
 *
 * A request stands in a STAGE (a row of `credit_stages`: the workflow is data) and carries the STATE that stage maps
 * to. The nine states are Odoo's own (`b2b.credit.request.state`), so a request that came from Odoo and one made here
 * read the same:
 *
 *   draft ──submit──▶ [ submitted ▸ in_review ▸ pending_signature ▸ ceo_approval ] ──▶ approved ──revoke──▶ cancelled
 *     ▲                     │  the stages a request is worked in, in their order           (the customer's limit is
 *     │                     ├──▶ rejected ──reopen──▶ draft                                 never reverted silently)
 *     │                     ├──▶ changes_requested ──submit──▶ back to the first working stage
 *     └──────withdraw───────┘
 *   draft · changes_requested · any working stage ──cancel──▶ cancelled ──reopen──▶ draft (unless it was approved once)
 */
final class Flow
{
    public const STATES = ['draft', 'submitted', 'in_review', 'pending_signature', 'ceo_approval', 'approved', 'rejected', 'changes_requested', 'cancelled'];

    /** The states of the stages a request is worked in: each such stage is decided by its approvers. */
    public const WORK = ['submitted', 'in_review', 'pending_signature', 'ceo_approval'];

    /** The requester's own work: the request is filled in or corrected. */
    public const EDITABLE = ['draft', 'changes_requested'];

    public const FINAL = ['approved', 'rejected', 'cancelled'];

    /** States that exactly one active stage may map to (a request is sent to "the" draft, "the" rejected stage…). */
    public const SINGLE = ['draft', 'approved', 'rejected', 'changes_requested', 'cancelled'];

    public const APPROVAL_MODES = ['any_user', 'all_users'];

    /** The states of an approval audit line (Odoo's `b2b.credit.approval.state`). */
    public const LINE_STATES = ['pending', 'approved', 'rejected', 'changes_requested', 'skipped', 'escalated'];

    /** A line that still waits for a decision. `escalated` waits too: the people it was escalated to may decide it as well. */
    public const LINE_OPEN = ['pending', 'escalated'];

    public const SLA = ['none', 'on_track', 'due_soon', 'late', 'escalated'];

    /** Odoo's envelope statuses, plus `voided`: an envelope issued by mistake is put away by a person, with a reason. */
    public const ENVELOPE_STATUSES = ['draft', 'sent', 'delivered', 'signed', 'declined', 'expired', 'failed', 'voided'];

    /** An envelope that still waits for the signer: a request has one at most. */
    public const ENVELOPE_ACTIVE = ['draft', 'sent', 'delivered'];

    public const ENVELOPE_TRANSITIONS = [
        'draft' => ['sent', 'failed', 'voided'],
        'sent' => ['delivered', 'signed', 'declined', 'expired', 'voided'],
        'delivered' => ['signed', 'declined', 'expired', 'voided'],
        'signed' => [], 'declined' => [], 'expired' => [], 'failed' => [], 'voided' => [],
    ];

    public const CHANNELS = ['in_app', 'email', 'whatsapp'];

    public const NOTIFICATION_STATUSES = ['pending', 'sent', 'failed', 'skipped'];

    public const SOURCES = ['internal', 'portal'];

    public const STATE_LABELS = [
        'draft' => ['مسودة', 'Draft'], 'submitted' => ['مُقدَّم', 'Submitted'], 'in_review' => ['قيد المراجعة', 'In review'],
        'pending_signature' => ['بانتظار التوقيع', 'Pending signature'], 'ceo_approval' => ['اعتماد الإدارة العليا', 'CEO approval'],
        'approved' => ['معتمد', 'Approved'], 'rejected' => ['مرفوض', 'Rejected'], 'changes_requested' => ['مطلوب تعديل', 'Changes requested'], 'cancelled' => ['ملغى', 'Cancelled'],
    ];

    public static function can(?string $from, string $to): bool
    {
        if ($from === null || ! in_array($from, self::STATES, true) || ! in_array($to, self::STATES, true)) {
            return false;
        }
        $work = in_array($to, self::WORK, true);

        return match (true) {
            in_array($from, self::EDITABLE, true) => $work || $to === 'cancelled',
            in_array($from, self::WORK, true) => $work || in_array($to, ['approved', 'rejected', 'changes_requested', 'cancelled', 'draft'], true),
            $from === 'approved' => $to === 'cancelled',
            default => $to === 'draft', // rejected, cancelled: taken back to a draft
        };
    }

    public static function assert(?string $from, string $to): void
    {
        if (! self::can($from, $to)) {
            throw AppError::rule('CREDIT_TRANSITION', "انتقال غير مسموح لطلب الائتمان: {$from} ← {$to}", "Invalid credit request transition: {$from} -> {$to}", ['from' => $from, 'to' => $to]);
        }
    }

    public static function envelopeCan(?string $from, string $to): bool
    {
        return in_array($to, self::ENVELOPE_TRANSITIONS[$from ?? ''] ?? [], true);
    }

    public static function envelopeAssert(?string $from, string $to): void
    {
        if (! self::envelopeCan($from, $to)) {
            throw AppError::rule('CREDIT_ENVELOPE_TRANSITION', "انتقال غير مسموح لمظروف التوقيع: {$from} ← {$to}", "Invalid signature envelope transition: {$from} -> {$to}", ['from' => $from, 'to' => $to]);
        }
    }

    public static function isWork(?string $state): bool
    {
        return in_array($state, self::WORK, true);
    }

    public static function isFinal(?string $state): bool
    {
        return in_array($state, self::FINAL, true);
    }

    /** A request that is not closed yet: somebody still works on it. */
    public static function isOpen(?string $state): bool
    {
        return $state !== null && ! self::isFinal($state);
    }

    /** @return array{0:string, 1:string} [Arabic, English] */
    public static function label(?string $state): array
    {
        return self::STATE_LABELS[$state ?? ''] ?? [(string) $state, (string) $state];
    }
}
