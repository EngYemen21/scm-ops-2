<?php

namespace App\Commissions\Http;

use App\Commissions\Models\CommissionAssignment;
use App\Commissions\Services\AssignmentsService;
use App\Commissions\Services\CalculationService;
use App\Commissions\Services\CommissionPosting;
use App\Commissions\Services\SummaryService;
use App\Commissions\Support\Period;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The summary, the calculation of a month, the payout form's choices, and "whose sale is it" (assignments). */
class CommissionsController extends Controller
{
    private const PERIOD = ['regex:'.Period::PATTERN];

    public function __construct(
        private readonly SummaryService $summary,
        private readonly CalculationService $calculation,
        private readonly AssignmentsService $assignments,
        private readonly CommissionPosting $posting,
    ) {}

    public function summary(Request $request): array
    {
        return $this->summary->summary(AuthUser::current(), $request->only(['from', 'to', 'beneficiary', 'source']));
    }

    public function lookups(): array
    {
        return $this->summary->lookups(AuthUser::current());
    }

    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['required', 'string', ...self::PERIOD], 'beneficiary' => 'nullable|string|max:60']);

        return response()->json($this->calculation->run(AuthUser::current(), $data['period'], $data['beneficiary'] ?? null), 201);
    }

    public function unassigned(Request $request): array
    {
        $data = $request->validate(['period' => ['nullable', 'string', ...self::PERIOD]]);

        return $this->calculation->unassigned($data['period'] ?? Period::current());
    }

    public function payoutOptions(): array
    {
        return $this->posting->options();
    }

    public function assignments(Request $request): array
    {
        return $this->assignments->list(Paging::from($request, 50), $request->only(['kind', 'user']));
    }

    public function setAssignment(Request $request): array
    {
        $data = $request->validate(['kind' => ['required', Rule::in(CommissionAssignment::KINDS)], 'subject' => 'required|string|min:1|max:190', 'user' => 'required|string|max:60']);

        return $this->assignments->set(AuthUser::current(), $data);
    }

    public function removeAssignment(string $id): array
    {
        return $this->assignments->remove(AuthUser::current(), $id);
    }
}
