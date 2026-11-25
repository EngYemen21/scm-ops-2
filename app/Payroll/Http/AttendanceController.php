<?php

namespace App\Payroll\Http;

use App\Http\Controllers\Controller;
use App\Payroll\Services\AttendanceService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Check in / check out, the records, their corrections, and the daily board. */
class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(Request $request): array
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return $this->attendance->list(AuthUser::current(), Paging::from($request, 25), $request->only(['scope', 'employee', 'department', 'from', 'to', 'status', 'source']));
    }

    public function mine(): array
    {
        return $this->attendance->mine(AuthUser::current());
    }

    public function daily(Request $request): array
    {
        $data = $request->validate(['date' => 'nullable|date']);
        $date = isset($data['date']) ? Carbon::parse($data['date'])->format('Y-m-d') : Carbon::now((string) config('app.business_timezone', 'Asia/Riyadh'))->format('Y-m-d');

        return $this->attendance->daily(AuthUser::current(), $date, Paging::from($request, 50), $request->only(['department', 'state']));
    }

    public function show(string $id): array
    {
        return $this->attendance->get(AuthUser::current(), $id);
    }

    public function checkIn(): JsonResponse
    {
        return response()->json($this->attendance->checkIn(AuthUser::current()), 201);
    }

    public function checkOut(): JsonResponse
    {
        return response()->json($this->attendance->checkOut(AuthUser::current()), 201);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['employee' => 'required|string|max:40', 'checkIn' => 'required|date', 'checkOut' => 'nullable|date', 'reason' => 'required|string|min:5|max:500']);

        return response()->json($this->attendance->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate(['checkIn' => 'sometimes|date', 'checkOut' => 'sometimes|nullable|date', 'reason' => 'required|string|min:5|max:500']);

        return $this->attendance->correct(AuthUser::current(), $id, $data);
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json($this->attendance->void(AuthUser::current(), $id, $data['reason']), 201);
    }
}
