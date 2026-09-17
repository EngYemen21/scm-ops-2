<?php

namespace App\Http\Controllers\Api\Procurement;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

abstract class ProcurementBaseController extends Controller
{
    /** The reference API answers every POST with 201 (NestJS default); keep the same status for creations and actions. */
    protected function created(mixed $data): JsonResponse
    {
        return response()->json($data, 201);
    }
}
