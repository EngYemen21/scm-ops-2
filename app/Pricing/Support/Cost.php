<?php

namespace App\Pricing\Support;

use App\Support\AppError;
use App\Support\AuthUser;

/**
 * Who sees what the company pays. A supplier price is a purchase cost: like every cost figure of the system it is
 * shown to the holders of `inventory.view_cost`, and to the people who maintain supplier prices
 * (`supplierprice.manage`). A salesperson opens the section for the customer side only.
 */
final class Cost
{
    public static function visible(AuthUser $user): bool
    {
        return $user->can('inventory.view_cost') || $user->can('supplierprice.manage');
    }

    public static function assertVisible(AuthUser $user): void
    {
        if (! self::visible($user)) {
            throw AppError::forbidden('PRICING_COST_HIDDEN', 'أسعار الموردين تكلفة شراء — تظهر لمن يملك صلاحية عرض التكلفة أو إدارة أسعار الموردين', 'Supplier prices are purchase costs — shown to holders of inventory.view_cost or supplierprice.manage');
        }
    }
}
