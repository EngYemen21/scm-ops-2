<?php

namespace App\Payroll\Support;

use App\Hr\Models\Employee;
use App\Hr\Support\ClaimAccess;
use App\Support\AppError;
use App\Support\AuthUser;

/**
 * Who may read what in this section. Everything here is people's pay and personal data:
 *
 *   payroll.manage / payroll.approve   read everyone's pay, leave, attendance, skills
 *   leave.approve                      reads and decides everyone's time off
 *   attendance.manage                  reads and corrects everyone's attendance
 *   a manager                          reads and decides the time off of the people who report to him
 *   everyone                           his OWN payslips, time off, attendance, skills — through the employee record HR
 *                                      linked to his login (App\Hr\Services\Workforce::ofUser)
 *
 * The routes let a signed-in person in; these checks decide what each call returns.
 */
final class Access
{
    /** The employee record of the signed-in person, or null when HR has linked none. */
    public static function employeeId(AuthUser $user): ?string
    {
        return ClaimAccess::employeeId($user);
    }

    /** …and it must exist: the self-service calls are about that record. */
    public static function needEmployee(AuthUser $user): string
    {
        return self::employeeId($user) ?? throw AppError::rule('NO_EMPLOYEE_RECORD',
            'حسابك غير مربوط بسجل موظف — اطلب من الموارد البشرية ربط دخولك بسجلك في «الموظفون»',
            'Your login is not linked to an employee record — ask HR to link it in People');
    }

    public static function managesPay(AuthUser $user): bool
    {
        return $user->can('payroll.manage') || $user->can('payroll.approve');
    }

    public static function needPay(AuthUser $user): void
    {
        if (! self::managesPay($user)) {
            throw self::denied();
        }
    }

    public static function seesAllLeave(AuthUser $user): bool
    {
        return $user->can('leave.approve') || self::managesPay($user);
    }

    public static function seesAllAttendance(AuthUser $user): bool
    {
        return $user->can('attendance.manage') || self::managesPay($user);
    }

    /** Pay of this employee: the payroll people, or the employee himself. */
    public static function seesPayOf(AuthUser $user, ?string $employeeId): bool
    {
        return self::managesPay($user) || ($employeeId !== null && self::employeeId($user) === $employeeId);
    }

    /** The people who report directly to the caller and are at work (ids), read once per request. @return string[] */
    public static function team(AuthUser $user): array
    {
        $bag = request()->attributes;
        $key = 'payroll.teamOf.'.$user->id;
        if (! $bag->has($key)) {
            $mine = self::employeeId($user);
            $bag->set($key, $mine ? Employee::where('manager_id', $mine)->pluck('id')->all() : []);
        }

        return $bag->get($key);
    }

    public static function isManagerOf(AuthUser $user, ?string $employeeId): bool
    {
        return $employeeId !== null && in_array($employeeId, self::team($user), true);
    }

    public static function denied(): AppError
    {
        return AppError::forbidden('PAYROLL_PRIVATE', 'هذه بيانات رواتب وشؤون موظفين — يراها مسؤولو الرواتب وصاحبها فقط', 'This is pay and personnel data — only payroll officers and the person it belongs to may see it');
    }
}
