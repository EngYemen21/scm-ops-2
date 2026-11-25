<?php

namespace App\Payroll\Services;

use App\Payroll\Models\GosiRate;
use App\Payroll\Models\Parameter;
use App\Support\AppError;

/**
 * Every figure the engine uses that is not a person's own: social-insurance rates per scheme and the engine's
 * parameters — each with the date it applies from. Nothing of this is a constant in code: the value in force on a day
 * is the row with the latest `effective_from` that is not after that day, and a figure that has no row is a refusal,
 * never an assumption.
 */
class Rates
{
    /**
     * The parameters the engine reads: code → [kind, Arabic, English, the allowed values of a choice].
     * Their first values are written by PayrollBootstrap; anything else in pay_parameters is kept for reference.
     */
    public const KNOWN = [
        'month_days' => ['number', 'أيام الشهر لحساب أجر اليوم', 'Days of a month for the daily rate', null],
        'unpaid_base' => ['choice', 'أساس خصم الإجازة غير المدفوعة', 'What an unpaid-leave day is deducted from', ['gross', 'basic']],
        'attendance_base' => ['choice', 'أساس أجر الساعة للحضور (إضافي، تأخير، غياب)', 'Base of the hourly rate for attendance (overtime, lateness, absence)', ['gross', 'basic']],
    ];

    /** @var array<string, ?string> */
    private array $parameters = [];

    /** @var array<string, ?GosiRate> */
    private array $gosi = [];

    /** The value of a parameter in force on a day, or null when no row applies yet. */
    public function parameter(string $code, string $on): ?string
    {
        return $this->parameters["{$code}|{$on}"] ??= Parameter::where('code', $code)->whereDate('effective_from', '<=', $on)->orderByDesc('effective_from')->orderByDesc('id')->value('value');
    }

    /** …as a positive number; refused when it is missing or not one. */
    public function number(string $code, string $on): float
    {
        $value = $this->parameter($code, $on);
        if ($value === null || ! is_numeric($value) || (float) $value <= 0) {
            throw self::missing($code, $on);
        }

        return (float) $value;
    }

    /** …as one of its allowed values. */
    public function choice(string $code, string $on): string
    {
        $value = $this->parameter($code, $on);
        if ($value === null || ! in_array($value, self::KNOWN[$code][3] ?? [], true)) {
            throw self::missing($code, $on);
        }

        return $value;
    }

    /** The social-insurance rates of a scheme in force on a day, or null when the scheme has none yet. */
    public function gosi(string $scheme, string $on): ?GosiRate
    {
        return $this->gosi["{$scheme}|{$on}"] ??= GosiRate::where('scheme', $scheme)->whereDate('effective_from', '<=', $on)->orderByDesc('effective_from')->first();
    }

    public function flush(): void
    {
        $this->parameters = $this->gosi = [];
    }

    private static function missing(string $code, string $on): AppError
    {
        return AppError::rule('PAYROLL_PARAMETER_MISSING', "لا قيمة سارية للمعامل «{$code}» بتاريخ {$on} — أضِفها في إعدادات الرواتب", "No value of parameter '{$code}' is in force on {$on} — add it in the payroll settings", ['code' => $code, 'date' => $on]);
    }
}
