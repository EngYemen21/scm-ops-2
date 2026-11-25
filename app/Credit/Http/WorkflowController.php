<?php

namespace App\Credit\Http;

use App\Credit\Services\DeadlinesService;
use App\Credit\Services\RequestsService;
use App\Credit\Services\WorkflowEngine;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use Illuminate\Http\Request;

/**
 * A request's way through the workflow: sent, decided at each stage (approved, rejected, sent back for changes,
 * skipped by the manager), assessed, withdrawn, cancelled or revoked, reopened, applied to the customer, the customer
 * put back. Every answer is the request as it stands afterwards. The rules are WorkflowEngine's; the route only asks
 * for the page.
 */
class WorkflowController extends Controller
{
    public function __construct(private readonly WorkflowEngine $engine, private readonly RequestsService $requests, private readonly DeadlinesService $deadlines) {}

    public function submit(string $id): array
    {
        return $this->answer($this->engine->submit(AuthUser::current(), $id));
    }

    public function approve(Request $request, string $id): array
    {
        $data = $request->validate(['note' => 'nullable|string|max:2000'] + self::assessment());
        $terms = array_intersect_key($data, self::assessment());

        return $this->answer($this->engine->approve(AuthUser::current(), $id, $data['note'] ?? null, $terms));
    }

    public function reject(Request $request, string $id): array
    {
        return $this->answer($this->engine->reject(AuthUser::current(), $id, $this->reason($request)));
    }

    public function requestChanges(Request $request, string $id): array
    {
        return $this->answer($this->engine->requestChanges(AuthUser::current(), $id, $this->reason($request)));
    }

    public function skip(Request $request, string $id): array
    {
        return $this->answer($this->engine->skip(AuthUser::current(), $id, $this->reason($request)));
    }

    public function refreshApprovers(Request $request, string $id): array
    {
        return $this->answer($this->engine->refreshApprovers(AuthUser::current(), $id, $this->reason($request)));
    }

    public function assess(Request $request, string $id): array
    {
        return $this->answer($this->engine->assess(AuthUser::current(), $id, $request->validate(self::assessment())));
    }

    public function withdraw(Request $request, string $id): array
    {
        return $this->answer($this->engine->withdraw(AuthUser::current(), $id, $this->reason($request)));
    }

    public function cancel(Request $request, string $id): array
    {
        $out = $this->engine->cancel(AuthUser::current(), $id, $this->reason($request));

        return $this->answer($out['id']) + ['revoked' => $out['revoked']];
    }

    public function reopen(string $id): array
    {
        return $this->answer($this->engine->reopen(AuthUser::current(), $id));
    }

    public function apply(string $id): array
    {
        return $this->answer($this->engine->apply(AuthUser::current(), $id));
    }

    public function restoreCustomer(Request $request, string $id): array
    {
        return $this->answer($this->engine->restoreCustomer(AuthUser::current(), $id, $this->reason($request)));
    }

    /** The clock of the workflow, run by hand (the scheduler runs it every quarter of an hour). */
    public function runDeadlines(): array
    {
        return $this->deadlines->run();
    }

    private function answer(string $id): array
    {
        return $this->requests->get(AuthUser::current(), $id);
    }

    private function reason(Request $request): ?string
    {
        return $request->validate(['reason' => 'nullable|string|max:2000'])['reason'] ?? null;
    }

    private static function assessment(): array
    {
        return [
            'financeRecommendation' => 'sometimes|nullable|string|max:8000', 'legalNotes' => 'sometimes|nullable|string|max:8000', 'ceoNotes' => 'sometimes|nullable|string|max:8000',
            'approvedCreditLimit' => 'sometimes|nullable|numeric|min:0|max:999999999.99|decimal:0,2', 'approvedPaymentDays' => 'sometimes|nullable|integer|min:1|max:365',
            'approvedTerm' => 'sometimes|nullable|string|max:120',
        ];
    }
}
