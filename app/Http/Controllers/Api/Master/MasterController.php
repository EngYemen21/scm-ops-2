<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Shared input handling of the master-data controllers: the list-query contract (same checks as the reference
 * PageQuery) and typing of validated input, which may arrive as numeric strings / 0-1 flags.
 */
abstract class MasterController extends Controller
{
    protected const FLAG = 'sometimes|in:true,false,1,0';

    protected const DATE = ['regex:/^\d{4}-\d{2}-\d{2}/'];

    /** Validates ?page&pageSize&q&sort&order plus the endpoint's own filters and returns the filters. */
    protected function filters(Request $request, array $rules = []): array
    {
        $data = $request->validate($rules + [
            'page' => 'sometimes|integer|min:1', 'pageSize' => 'sometimes|integer|min:1|max:500', 'q' => 'nullable|string',
            'sort' => 'nullable|string', 'order' => 'sometimes|in:asc,desc',
        ]);

        return array_intersect_key($data, $rules);
    }

    /**
     * @param  string[]  $ints
     * @param  string[]  $floats
     * @param  string[]  $bools
     */
    protected static function typed(array $data, array $ints = [], array $floats = [], array $bools = []): array
    {
        foreach ($ints as $k) {
            if (isset($data[$k])) {
                $data[$k] = (int) $data[$k];
            }
        }
        foreach ($floats as $k) {
            if (isset($data[$k])) {
                $data[$k] = (float) $data[$k];
            }
        }
        foreach ($bools as $k) {
            if (isset($data[$k])) {
                $data[$k] = filter_var($data[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $data;
    }

    /** Drops keys sent empty for fields where the reference treats "empty" the same as "not sent". */
    protected static function withoutNulls(array $data, array $keys): array
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                unset($data[$k]);
            }
        }

        return $data;
    }
}
