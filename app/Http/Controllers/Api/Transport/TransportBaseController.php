<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

abstract class TransportBaseController extends Controller
{
    /** 'YYYY-MM-DD…' — same check as the reference `DateStr`. */
    protected const DATE = 'regex:/^\d{4}-\d{2}-\d{2}/';

    /** The reference API answers every POST with 201 (NestJS default); keep the same status for creations and actions. */
    protected function created(mixed $data): JsonResponse
    {
        return response()->json($data, 201);
    }

    /** Applies schema defaults to keys that are absent or null. */
    protected function withDefaults(array $data, array $defaults): array
    {
        foreach ($defaults as $key => $value) {
            if (! isset($data[$key])) {
                $data[$key] = $value;
            }
        }

        return $data;
    }
}
