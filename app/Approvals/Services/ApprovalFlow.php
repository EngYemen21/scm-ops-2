<?php

namespace App\Approvals\Services;

use App\Approvals\Models\ApprovalApprover;
use App\Support\AppError;

/**
 * The rules of an approval request that are the same whoever asks: the states it may move between, the fields a
 * category may ask for, and how the decisions of its approvers add up.
 *
 *   draft ──submit──▶ pending ──▶ approved            pending ──recall──▶ draft
 *     │                 │   └───▶ refused ──the refuser takes it back──▶ pending
 *     └──────cancel─────┴──▶ cancelled ──reopen──▶ draft       (an approved request is only cancelled, by a manager)
 */
final class ApprovalFlow
{
    public const STATUSES = ['draft', 'pending', 'approved', 'refused', 'cancelled'];

    public const TRANSITIONS = [
        'draft' => ['pending', 'cancelled'],
        'pending' => ['approved', 'refused', 'cancelled', 'draft'],
        'approved' => ['cancelled'],
        'refused' => ['pending', 'draft', 'cancelled'],
        'cancelled' => ['draft'],
    ];

    /** parallel: every approver is asked at once · sequence: one after the other, in their order. */
    public const MODES = ['parallel', 'sequence'];

    /** What a category may ask a request to carry. `document` = at least one attachment. */
    public const FIELDS = ['amount', 'date', 'period', 'partner', 'reference', 'quantity', 'items', 'location', 'paymentMethod', 'document'];

    public const LEVELS = ['required', 'optional', 'no'];

    public const PAYMENT_METHODS = ['bank', 'cash', 'cheque', 'card', 'other'];

    public const PARTNER_TYPES = ['customer', 'supplier', 'other'];

    public static function can(?string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from ?? ''] ?? [], true);
    }

    public static function assert(?string $from, string $to): void
    {
        if (! self::can($from, $to)) {
            throw AppError::rule('APPROVAL_TRANSITION', "انتقال غير مسموح لطلب الاعتماد: {$from} ← {$to}", "Invalid approval request transition: {$from} -> {$to}");
        }
    }

    /**
     * The field rules of a category with every field present. A field nobody set is not asked for (`no`); attachments
     * can always be added, so `document` is never `no`.
     *
     * @return array<string, string> field → required | optional | no
     */
    public static function fields(?array $fields): array
    {
        $out = [];
        foreach (self::FIELDS as $key) {
            $level = $fields[$key] ?? 'no';
            $out[$key] = in_array($level, self::LEVELS, true) ? $level : 'no';
        }
        if ($out['document'] === 'no') {
            $out['document'] = 'optional';
        }

        return $out;
    }

    /**
     * The rows of a list as the client sent them, in the order it sent them. (A validated payload comes back with the
     * rows in the order their keys were first seen by the rules — row 1 before row 0 when only row 1 carries the first
     * optional key — so the order is taken from the keys, not from the array.)
     *
     * @return array<int, array>
     */
    public static function rows(array $list): array
    {
        ksort($list);

        return array_values(array_filter($list, 'is_array'));
    }

    /**
     * Where a sent request stands, from its approver lines (in their order):
     *  - one refusal refuses the request;
     *  - it is approved when the approvals reach the minimum (never more than the approvers it has) and every approver
     *    marked as required has approved;
     *  - otherwise it waits: every undecided approver is asked (parallel), or the first one in order while the others
     *    wait (sequence).
     * Lines that are out (`cancelled`, `removed`) do not count.
     *
     * @param  array<int, array{id:string, status:string, required:bool}>  $lines
     * @return array{status:string, lines:array<string,string>, approved:int, needed:int, total:int}
     *                                                                                               `lines` = the status every undecided line must have (only while the request waits)
     */
    public static function evaluate(string $mode, int $min, array $lines): array
    {
        $active = array_values(array_filter($lines, fn (array $l) => ! in_array($l['status'], ApprovalApprover::OUT, true)));
        $approved = count(array_filter($active, fn (array $l) => $l['status'] === 'approved'));
        $out = ['status' => 'pending', 'lines' => [], 'approved' => $approved, 'needed' => max(1, min($min, count($active))), 'total' => count($active)];
        foreach ($active as $l) {
            if ($l['status'] === 'refused') {
                return ['status' => 'refused'] + $out;
            }
        }
        $requiredOpen = count(array_filter($active, fn (array $l) => $l['required'] && $l['status'] !== 'approved'));
        if ($active && $approved >= $out['needed'] && $requiredOpen === 0) {
            return ['status' => 'approved'] + $out;
        }
        $first = true;
        foreach ($active as $l) {
            if (in_array($l['status'], ApprovalApprover::OPEN, true)) {
                $out['lines'][$l['id']] = $mode === 'sequence' && ! $first ? 'waiting' : 'pending';
                $first = false;
            }
        }

        return $out;
    }
}
