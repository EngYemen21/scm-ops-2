<?php

namespace App\Credit\Support;

use App\Credit\Models\CreditRequest;
use App\Credit\Models\CreditStage;
use App\Support\AppError;
use App\Support\AuthUser;

/**
 * Who may do what on a credit request, where the answer does not depend on the workflow's approval lines (those are
 * the engine's: App\Credit\Services\WorkflowEngine).
 *
 *   credit.request   raise a request, and complete, send, withdraw or cancel the one he entered
 *   credit.review    decide a working stage he is asked at, write the assessment, read what is personal
 *   credit.approve   the same, and give the final approval — the authority to grant credit
 *   credit.manage    run the section: stages, terms, settings, and the administrative exits of a request
 */
final class Access
{
    public static function manages(AuthUser $u): bool
    {
        return $u->can('credit.manage');
    }

    /** Holds one of the two permissions a stage decision is made with. */
    public static function decides(AuthUser $u): bool
    {
        return $u->can('credit.review') || $u->can('credit.approve');
    }

    public static function closed(CreditRequest $r): AppError
    {
        [$ar, $en] = Flow::label($r->state);

        return AppError::rule('CREDIT_CLOSED', "الطلب {$r->number} مغلق ({$ar}) — لا يتغيّر", "Request {$r->number} is closed ({$en}) — it no longer changes", ['state' => $r->state]);
    }

    public static function notYours(CreditRequest $r): AppError
    {
        return AppError::forbidden('CREDIT_NOT_YOURS', "الطلب {$r->number} ليس طلبك — يغيّره من أدخله أو مدير الائتمان", "Request {$r->number} is not yours — whoever entered it, or the credit manager, changes it");
    }

    /**
     * Why this user may not change the request's own fields, lines and papers now (null = he may).
     * While the requester works on it (draft, changes requested): the requester and the manager. While it stands in a
     * working stage: nobody when the stage locks the fields; otherwise the requester, the reviewers and the manager.
     */
    public static function blockEdit(AuthUser $u, CreditRequest $r, ?CreditStage $stage): ?AppError
    {
        if (Owned::bySource($r)) {
            return Owned::error($r, 'request');
        }
        if (Flow::isFinal($r->state)) {
            return self::closed($r);
        }
        $mine = Privacy::isRequester($u, $r);
        if (in_array($r->state, Flow::EDITABLE, true)) {
            return $mine || self::manages($u) ? null : self::notYours($r);
        }
        if ($stage?->lock_request_fields) {
            return AppError::rule('CREDIT_FIELDS_LOCKED', "بيانات الطلب {$r->number} مقفلة في مرحلة «{$stage->name_ar}» — يُطلب تعديلها (إعادة الطلب للتعديل) أو يسحبه صاحبه",
                "The fields of request {$r->number} are locked in stage \"{$stage->name_en}\" — ask for changes, or its requester withdraws it", ['stage' => $stage->code]);
        }

        return $mine || self::manages($u) || self::decides($u) ? null : self::notYours($r);
    }
}
