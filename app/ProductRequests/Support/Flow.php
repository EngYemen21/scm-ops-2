<?php

namespace App\ProductRequests\Support;

use App\Support\AppError;

/**
 * Where a new-product request can stand and how it moves:
 *
 *   draft ──submit──▶ submitted ──start review──▶ in_review ──▶ approved   (the product is created and linked)
 *     │                  │  ▲                        │       └─▶ rejected   (with a reason)
 *     │                  │  └──────resubmit──── returned ◀───┘              (sent back for correction, with a reason)
 *     └──────────────────┴──────────────┴────────────┴──────────▶ cancelled
 *
 * A reviewer may decide a submitted request without taking it into review first. Approved, rejected and cancelled
 * are final. The server is the only authority on these moves; the client only draws them.
 */
final class Flow
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const IN_REVIEW = 'in_review';

    public const RETURNED = 'returned';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::SUBMITTED, self::IN_REVIEW, self::RETURNED, self::APPROVED, self::REJECTED, self::CANCELLED];

    /** Still on somebody's desk. */
    public const OPEN = [self::DRAFT, self::SUBMITTED, self::IN_REVIEW, self::RETURNED];

    /** On the reviewers' desk. */
    public const WAITING = [self::SUBMITTED, self::IN_REVIEW];

    public const CLOSED = [self::APPROVED, self::REJECTED, self::CANCELLED];

    /** What another request must be in to count as "the same product is already being asked for". */
    public const RUNNING = [self::SUBMITTED, self::IN_REVIEW, self::RETURNED];

    public const TRANSITIONS = [
        self::DRAFT => [self::SUBMITTED, self::CANCELLED],
        self::SUBMITTED => [self::IN_REVIEW, self::RETURNED, self::APPROVED, self::REJECTED, self::CANCELLED],
        self::IN_REVIEW => [self::RETURNED, self::APPROVED, self::REJECTED, self::CANCELLED],
        self::RETURNED => [self::SUBMITTED, self::CANCELLED],
        self::APPROVED => [],
        self::REJECTED => [],
        self::CANCELLED => [],
    ];

    /** status → [Arabic, English] */
    public const LABELS = [
        self::DRAFT => ['مسودة', 'Draft'],
        self::SUBMITTED => ['بانتظار المراجعة', 'Waiting for review'],
        self::IN_REVIEW => ['قيد المراجعة', 'In review'],
        self::RETURNED => ['أُعيد للتصحيح', 'Returned for correction'],
        self::APPROVED => ['معتمد', 'Approved'],
        self::REJECTED => ['مرفوض', 'Rejected'],
        self::CANCELLED => ['ملغى', 'Cancelled'],
    ];

    /** The groups a list may be asked for by one word. */
    public const GROUPS = ['open' => self::OPEN, 'waiting' => self::WAITING, 'closed' => self::CLOSED];

    public const REQUEST_TYPES = ['new', 'existing'];

    public const STORAGE = ['ambient', 'chilled', 'frozen'];

    /** The lines of a request's history. */
    public const EVENTS = [
        'created', 'edited', 'submitted', 'resubmitted', 'review_started', 'returned', 'corrected', 'approved', 'rejected', 'cancelled',
        'comment', 'picture_added', 'picture_removed', 'product_linked',
        'source_step', // a step of Odoo's own flow that is not a change of status here (one approval of its three)
    ];

    /** The history line that tells the entry into a status. */
    public const EVENT_OF = [
        self::DRAFT => 'created', self::SUBMITTED => 'submitted', self::IN_REVIEW => 'review_started', self::RETURNED => 'returned',
        self::APPROVED => 'approved', self::REJECTED => 'rejected', self::CANCELLED => 'cancelled',
    ];

    /**
     * Odoo's staff flow (`b2b.new.product`) has three approvals in a row. The request is `submitted` while it waits for
     * the first one and `in_review` once somebody has approved a step; Odoo's own word is kept beside the status.
     */
    public const ODOO_STAFF = [
        'draft' => self::DRAFT, 'supply_chain_approval' => self::SUBMITTED, 'sales_approval' => self::IN_REVIEW, 'system_admin_approval' => self::IN_REVIEW,
        'approved' => self::APPROVED, 'rejected' => self::REJECTED,
    ];

    /** What a customer asked for through the portal (`hub.product.request`). */
    public const ODOO_PORTAL = ['pending' => self::SUBMITTED, 'approved' => self::APPROVED, 'rejected' => self::REJECTED, 'cancelled' => self::CANCELLED];

    /** Odoo's own states in words: [Arabic, English]. */
    public const SOURCE_STATES = [
        'draft' => ['مسودة', 'Draft'],
        'supply_chain_approval' => ['بانتظار موافقة مدير الإمداد', 'Waiting for the supply chain manager'],
        'sales_approval' => ['بانتظار موافقة مدير المبيعات', 'Waiting for the sales manager'],
        'system_admin_approval' => ['بانتظار موافقة مدير النظام', 'Waiting for the system administrator'],
        'approved' => ['معتمد', 'Approved'],
        'rejected' => ['مرفوض', 'Rejected'],
        'pending' => ['بانتظار المراجعة', 'Pending review'],
        'cancelled' => ['ملغى', 'Cancelled'],
    ];

    public static function can(?string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from ?? ''] ?? [], true);
    }

    /** Refuses an illegal move with PREQ_TRANSITION (422). */
    public static function assert(?string $from, string $to): void
    {
        if (! self::can($from, $to)) {
            [$fromAr, $fromEn] = self::LABELS[$from ?? ''] ?? [$from ?? '—', $from ?? '—'];
            [$toAr, $toEn] = self::LABELS[$to] ?? [$to, $to];
            throw AppError::rule('PREQ_TRANSITION', "لا يمكن نقل الطلب من «{$fromAr}» إلى «{$toAr}»", "A request that is “{$fromEn}” cannot become “{$toEn}”", ['from' => $from, 'to' => $to]);
        }
    }

    /** @return array{key:string, ar:string, en:string}|null */
    public static function sourceState(?string $state): ?array
    {
        if ($state === null || $state === '') {
            return null;
        }
        [$ar, $en] = self::SOURCE_STATES[$state] ?? [$state, $state];

        return ['key' => $state, 'ar' => $ar, 'en' => $en];
    }
}
