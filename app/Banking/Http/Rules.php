<?php

namespace App\Banking\Http;

use App\Banking\Support\Money;
use App\Support\Paging;
use Closure;
use Illuminate\Http\Request;

/** What the controllers of the section validate with: money as an exact decimal, a day, the paging of a list. */
final class Rules
{
    public const DAY = 'date_format:Y-m-d';

    /** An amount of at most two decimals (a string or a number). `$signed`: may be negative · `$zero`: may be zero. */
    public static function money(bool $signed = true, bool $zero = true): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($signed, $zero): void {
            if (! (is_string($value) || is_int($value) || is_float($value)) || ! Money::valid(is_string($value) ? trim($value) : $value)) {
                $fail('مبلغ غير صالح: رقم بخانتين عشريتين على الأكثر');

                return;
            }
            $cents = Money::cents(is_string($value) ? trim($value) : $value);
            if ((! $signed && $cents < 0) || (! $zero && $cents === 0)) {
                $fail($cents === 0 ? 'المبلغ لا يكون صفرًا' : 'المبلغ لا يكون سالبًا');
            }
        };
    }

    /** The paging keys every list accepts beside its own filters. */
    public static function paging(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'pageSize' => 'sometimes|integer|min:1|max:500', 'q' => 'nullable|string|max:120', 'sort' => 'nullable|string|max:30', 'order' => 'sometimes|in:asc,desc'];
    }

    /** Paging of a list whose natural order is ascending (the lines of a statement, a dictionary) unless the caller asks otherwise. */
    public static function ascending(Request $request, int $size = 50): Paging
    {
        $page = Paging::from($request, $size);

        return $request->query('order') ? $page : new Paging($page->page, $page->pageSize, $page->q, $page->sort, 'asc');
    }

    /** Validates the filters of a list (and its paging keys) and returns the filters only. */
    public static function filters(Request $request, array $rules): array
    {
        return array_intersect_key($request->validate($rules + self::paging()), $rules);
    }
}
