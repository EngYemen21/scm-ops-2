<?php

namespace App\Payroll\Support;

use App\Hr\Models\Department;
use App\Hr\Models\Employee;
use App\Support\AppError;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/** How the section speaks: the small shapes every answer shares, times of day and durations, and the reason a person must give. */
final class Shape
{
    /** @return array{id:string, code:string, nameAr:string, nameEn:string, status:string}|null */
    public static function employee(?Employee $e): ?array
    {
        return $e ? ['id' => $e->id, 'code' => $e->code, 'nameAr' => $e->name_ar, 'nameEn' => $e->name_en, 'status' => $e->status] : null;
    }

    /** @return array{id:string, nameAr:string, nameEn:string}|null */
    public static function department(?Department $d): ?array
    {
        return $d ? ['id' => $d->id, 'nameAr' => $d->name_ar, 'nameEn' => $d->name_en] : null;
    }

    /** `nameAr` / `nameEn` of any row that has them, with its id (and code when it has one). */
    public static function named(?Model $row, array $more = []): ?array
    {
        if (! $row) {
            return null;
        }
        $out = ['id' => $row->getKey(), 'nameAr' => $row->getAttribute('name_ar'), 'nameEn' => $row->getAttribute('name_en')];
        if ($row->getAttribute('code') !== null) {
            $out['code'] = $row->getAttribute('code');
        }
        foreach ($more as $key => $column) {
            $out[$key] = $row->getAttribute($column);
        }

        return $out;
    }

    public static function day(mixed $d): ?string
    {
        if ($d === null || $d === '') {
            return null;
        }

        return $d instanceof CarbonInterface ? $d->format('Y-m-d') : substr((string) $d, 0, 10);
    }

    public static function at(mixed $d): ?string
    {
        return $d instanceof CarbonInterface ? $d->toJSON() : ($d ? (string) $d : null);
    }

    /** Minutes after midnight as `HH:MM` (past midnight keeps counting: 25:10 is ten past one of the next day). */
    public static function clock(?int $minutes): ?string
    {
        return $minutes === null ? null : sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** A duration in minutes as decimal hours with two places (`1.50`). */
    public static function hours(int $minutes): string
    {
        return number_format($minutes / 60, 2, '.', '');
    }

    /** Trimmed text, '' as null, cut to what the column holds. */
    public static function text(mixed $v, int $max = 250): ?string
    {
        $s = is_scalar($v) ? trim((string) $v) : '';

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /** The reason of an act that undoes or corrects something: at least five characters. */
    public static function reason(mixed $reason, string $forAr, string $forEn): string
    {
        $reason = is_scalar($reason) ? trim((string) $reason) : '';
        if (mb_strlen($reason) < 5) {
            throw AppError::validation('REASON_REQUIRED', "اكتب سبب {$forAr} (5 أحرف على الأقل)", "A reason for {$forEn} is required (at least 5 characters)");
        }

        return mb_substr($reason, 0, 500);
    }

    /** `YYYY-MM` of a period a person sent, or a refusal. */
    public static function period(mixed $period): string
    {
        $p = is_scalar($period) ? trim((string) $period) : '';
        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $p)) {
            throw AppError::validation('PERIOD_INVALID', 'الفترة تُكتب بالصيغة YYYY-MM', 'A period is written as YYYY-MM');
        }

        return $p;
    }
}
