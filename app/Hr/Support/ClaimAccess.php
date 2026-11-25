<?php

namespace App\Hr\Support;

use App\Hr\Models\Claim;
use App\Hr\Models\Employee;
use App\Support\AppError;
use App\Support\AuthUser;

/**
 * Whose claim is it. A claim belongs to the person whose expenses it holds (their OPS login, directly or through
 * their employee record) and to whoever entered it on their behalf; the reviewers (`expense.review`) see every claim.
 */
final class ClaimAccess
{
    /** The employee record of the signed-in person, when HR has linked one (looked up once per request). */
    public static function employeeId(AuthUser $user): ?string
    {
        $bag = request()->attributes;
        $key = 'hr.employeeOf.'.$user->id;
        if (! $bag->has($key)) {
            $bag->set($key, Employee::where('user_id', $user->id)->value('id'));
        }

        return $bag->get($key);
    }

    public static function isMine(AuthUser $user, Claim $claim): bool
    {
        if ($claim->claimant_user_id === $user->id || $claim->created_by_id === $user->id) {
            return true;
        }
        $mine = self::employeeId($user);

        return $mine !== null && $claim->employee_id === $mine;
    }

    public static function sees(AuthUser $user, Claim $claim): bool
    {
        return $user->can('expense.review') || self::isMine($user, $claim);
    }

    public static function assertSees(AuthUser $user, Claim $claim): void
    {
        if (! self::sees($user, $claim)) {
            throw AppError::forbidden('CLAIM_NOT_YOURS', 'هذه المطالبة ليست لك — يراها صاحبها ومراجعو المصروفات', 'This claim is not yours — its owner and the expense reviewers see it');
        }
    }
}
