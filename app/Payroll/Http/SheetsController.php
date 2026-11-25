<?php

namespace App\Payroll\Http;

use App\Http\Controllers\Controller;
use App\Payroll\Services\SheetService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Attendance sheets and their batches. */
class SheetsController extends Controller
{
    public function __construct(private readonly SheetService $sheets) {}

    public function index(Request $request): array
    {
        return $this->sheets->list(AuthUser::current(), Paging::from($request, 25), $request->only(['scope', 'state', 'employee', 'department', 'period', 'batch', 'source']));
    }

    public function show(string $id): array
    {
        return $this->sheets->get(AuthUser::current(), $id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['employee' => 'required|string|max:40', 'dateFrom' => 'required|date', 'dateTo' => 'required|date']);

        return response()->json($this->sheets->generate(AuthUser::current(), $data), 201);
    }

    public function recompute(string $id): JsonResponse
    {
        return response()->json($this->sheets->recompute(AuthUser::current(), $id), 201);
    }

    public function confirm(string $id): JsonResponse
    {
        return response()->json($this->sheets->confirm(AuthUser::current(), $id), 201);
    }

    public function approve(string $id): JsonResponse
    {
        return response()->json($this->sheets->approve(AuthUser::current(), $id), 201);
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json($this->sheets->reopen(AuthUser::current(), $id, $data['reason']), 201);
    }

    public function correctLine(Request $request, string $id, string $line): array
    {
        $data = $request->validate(['late' => 'sometimes|nullable|integer|min:0|max:1440', 'early' => 'sometimes|nullable|integer|min:0|max:1440', 'overtime' => 'sometimes|nullable|integer|min:0|max:1440',
            'note' => 'required|string|min:5|max:500']);

        return $this->sheets->correctLine(AuthUser::current(), $id, $line, $data);
    }

    // ── batches

    public function batches(Request $request): array
    {
        return $this->sheets->batches(Paging::from($request, 25), $request->only(['state', 'department']));
    }

    public function batch(string $id): array
    {
        return $this->sheets->batch(AuthUser::current(), $id);
    }

    public function storeBatch(Request $request): JsonResponse
    {
        $data = $request->validate(['department' => 'nullable|string|max:40', 'dateFrom' => 'required|date', 'dateTo' => 'required|date', 'name' => 'nullable|string|max:190']);

        return response()->json($this->sheets->createBatch(AuthUser::current(), $data), 201);
    }

    public function generateBatch(string $id): JsonResponse
    {
        return response()->json($this->sheets->generateBatch(AuthUser::current(), $id), 201);
    }

    public function submitBatch(string $id): JsonResponse
    {
        return response()->json($this->sheets->submitBatch(AuthUser::current(), $id), 201);
    }

    public function closeBatch(string $id): JsonResponse
    {
        return response()->json($this->sheets->closeBatch(AuthUser::current(), $id), 201);
    }
}
