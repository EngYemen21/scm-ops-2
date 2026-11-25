<?php

namespace App\Insights\Support;

use App\Insights\Models\BrandOwner;
use App\Support\AppError;
use App\Support\AuthUser;

/**
 * Who sees what in «العلامات والصلاحية والتحليلات». Enforced by the services on every query, never by the client.
 *
 *  - A holder of `brandowner.manage` sees every brand owner and every figure (margin and cost still need
 *    `inventory.view_cost`).
 *  - A user LINKED to a brand owner (and without `brandowner.manage`) is an OUTSIDER looking in: he sees his own brand
 *    only — its products, its franchisees' figures — and only while the owner is active; invoices, payments, returns
 *    and margin only when the owner's access flags allow them (margin also needs `inventory.view_cost`). Everything
 *    else of the section (expiry, stock card, operations) is refused to him (403 INSIGHTS_OUTSIDER).
 *  - Anybody else holding `page.insights` uses the company's own tabs and sees no brand (403 BRAND_FORBIDDEN).
 */
final class Access
{
    public const MANAGE = 'brandowner.manage';

    public static function manager(AuthUser $u): bool
    {
        return $u->can(self::MANAGE);
    }

    /** The brand owner this user is (whatever its state), or null. */
    public static function ownerOf(AuthUser $u): ?BrandOwner
    {
        return BrandOwner::where('user_id', $u->id)->first();
    }

    /** A user linked to a brand owner who does not manage brand owners. */
    public static function outsider(AuthUser $u): bool
    {
        return ! self::manager($u) && self::ownerOf($u) !== null;
    }

    /** Refuses the company's own figures to an outsider. */
    public static function insider(AuthUser $u): void
    {
        if (self::outsider($u)) {
            throw AppError::forbidden('INSIGHTS_OUTSIDER', 'حساب مالك العلامة يرى علامته فقط', 'A brand owner account sees its own brand only');
        }
    }

    /** The owner `$id` as this user may see it, or a refusal. */
    public static function owner(AuthUser $u, string $id): BrandOwner
    {
        $owner = BrandOwner::find($id) ?? throw AppError::notFound('BRAND_OWNER_NOT_FOUND', 'مالك العلامة غير موجود', 'Brand owner not found');
        if (self::manager($u)) {
            return $owner;
        }
        if ($owner->user_id === null || $owner->user_id !== $u->id) {
            throw AppError::forbidden('BRAND_FORBIDDEN', 'لا تملك الاطلاع على هذه العلامة', 'You may not see this brand');
        }
        if ($owner->state !== 'active') {
            throw AppError::forbidden('BRAND_NOT_ACTIVE', 'حساب مالك العلامة غير نشط — راجع الإدارة', 'The brand owner account is not active');
        }

        return $owner;
    }

    /**
     * What this user may see of this owner beyond the purchases.
     *
     * @return array{manager:bool, invoices:bool, payments:bool, returns:bool, margin:bool, cost:bool}
     */
    public static function flags(AuthUser $u, BrandOwner $o): array
    {
        $manager = self::manager($u);
        $cost = $u->can('inventory.view_cost');

        return [
            'manager' => $manager,
            'invoices' => $manager || $o->allow_invoice,
            'payments' => $manager || $o->allow_payment,
            'returns' => $manager || $o->allow_return,
            'margin' => $cost && ($manager || $o->allow_margin),
            'cost' => $cost,
        ];
    }

    /** Refuses a figure the flags do not allow. */
    public static function need(array $flags, string $flag): void
    {
        if (! ($flags[$flag] ?? false)) {
            throw AppError::forbidden('BRAND_ACCESS_'.strtoupper($flag), 'لا تسمح صلاحيات مالك العلامة بهذا الرقم', "The brand owner's access does not include {$flag}");
        }
    }
}
