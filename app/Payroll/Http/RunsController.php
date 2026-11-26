<?php

namespace App\Payroll\Http;

use App\Http\Controllers\Controller;
use App\Payroll\Services\AdjustmentsService;
use App\Payroll\Services\PayslipsService;
use App\Payroll\Services\RunsService;
use App\Payroll\Services\TransferFile;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Payroll runs, their payslips, the adjustments a month carries, and the bank transfer listing. */
class RunsController extends Controller
{
    public function __construct(
        private readonly RunsService $runs,
        private readonly PayslipsService $payslips,
        private readonly AdjustmentsService $adjustments,
        private readonly TransferFile $transfer,
    ) {}

    // ── runs
    public function index(Request $request): array
    {
        return $this->runs->list(AuthUser::current(), Paging::from($request, 25), $request->only(['state', 'period', 'year', 'department', 'source']));
    }

    public function show(string $id): array
    {
        return $this->runs->get(AuthUser::current(), $id);
    }

    public function journal(string $id): array
    {
        return $this->runs->journal(AuthUser::current(), $id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'department' => 'nullable|string|max:40', 'name' => 'nullable|string|max:190']);

        return response()->json($this->runs->create(AuthUser::current(), $data), 201);
    }

    public function compute(string $id): JsonResponse
    {
        return response()->json($this->runs->compute(AuthUser::current(), $id), 201);
    }

    public function submit(string $id): JsonResponse
    {
        return response()->json($this->runs->submit(AuthUser::current(), $id), 201);
    }

    public function sendBack(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json($this->runs->sendBack(AuthUser::current(), $id, $data['reason']), 201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return response()->json($this->runs->cancel(AuthUser::current(), $id, $data['reason'] ?? null), 201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);

        return response()->json($this->runs->approve(AuthUser::current(), $id, $data['note'] ?? null), 201);
    }

    public function post(Request $request, string $id): JsonResponse
    {
        return response()->json($this->runs->post(AuthUser::current(), $id, $request->validate(['date' => 'nullable|date'])), 201);
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['bankAccount' => 'required|string|max:40', 'reference' => 'required|string|min:2|max:80', 'date' => 'nullable|date']);

        return response()->json($this->runs->pay(AuthUser::current(), $id, $data), 201);
    }

    public function unpay(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json($this->runs->unpay(AuthUser::current(), $id, $data['reason']), 201);
    }

    public function reverse(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json($this->runs->reverse(AuthUser::current(), $id, $data['reason']), 201);
    }

    // ── the bank transfer listing (the one place bank account numbers are returned)
    public function transfer(string $id): array
    {
        return $this->transfer->listing(AuthUser::current(), $id);
    }

    public function transferCsv(string $id)
    {
        $file = $this->transfer->csv(AuthUser::current(), $id);

        return response($file['bytes'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$file['fileName']}\"",
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', // account numbers are not left in a shared cache
        ]);
    }

    // ── payslips
    public function payslips(Request $request): array
    {
        return $this->payslips->list(AuthUser::current(), Paging::from($request, 25), $request->only(['scope', 'run', 'employee', 'department', 'period', 'year', 'state', 'source']));
    }

    public function payslip(string $id): array
    {
        return $this->payslips->get(AuthUser::current(), $id);
    }

    // ── adjustments
    public function adjustments(Request $request): array
    {
        return $this->adjustments->list(AuthUser::current(), Paging::from($request, 25), $request->only(['state', 'employee', 'period', 'component', 'kind']));
    }

    public function storeAdjustment(Request $request): JsonResponse
    {
        $data = $request->validate(['employee' => 'required|string|max:40', 'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'component' => 'required|string|max:40',
            'amount' => 'required|numeric|min:0.01|max:99999999', 'reason' => 'required|string|min:5|max:500']);

        return response()->json($this->adjustments->create(AuthUser::current(), $data), 201);
    }

    public function cancelAdjustment(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json($this->adjustments->cancel(AuthUser::current(), $id, $data['reason']), 201);
    }
}
