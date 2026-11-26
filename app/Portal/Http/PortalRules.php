<?php

namespace App\Portal\Http;

use Illuminate\Http\Request;

/** Validation the controllers of the section share: the keys every list accepts, days, and short optional text. */
final class PortalRules
{
    public const DAY = 'date_format:Y-m-d';

    public const HOUR = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';

    /** What `Paging` reads from every list request. */
    public const PAGING = [
        'page' => 'sometimes|integer|min:1', 'pageSize' => 'sometimes|integer|min:1|max:200', 'q' => 'nullable|string|max:120', 'sort' => 'nullable|string|max:30', 'order' => 'sometimes|in:asc,desc',
    ];

    /** Optional text of a form: absent = leave as it is, null or empty = clear. */
    public static function text(int $max): string
    {
        return "sometimes|nullable|string|max:{$max}";
    }

    /**
     * The filters of a list, validated; paging, search and sort are validated with them and read by `Paging`.
     *
     * @param  array<string, mixed>  $rules  the filters the list knows
     * @return array<string, mixed>
     */
    public static function filters(Request $request, array $rules): array
    {
        return array_intersect_key($request->validate($rules + self::PAGING), $rules);
    }
}
