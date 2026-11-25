<?php

namespace App\Pricing\Http;

use App\Http\Controllers\Controller;
use App\Pricing\Services\AssignmentsService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

/** The price list of each customer: who is on which list, and moving a customer from one to another. */
class AssignmentsController extends Controller
{
    public function __construct(private readonly AssignmentsService $assignments) {}

    public function index(Request $request): array
    {
        return $this->assignments->customers(Paging::from($request, 25), $request->only(['list', 'assigned', 'basis', 'active']));
    }

    /** The customers of one list. */
    public function ofList(Request $request, string $id): array
    {
        return $this->assignments->customers(Paging::from($request, 25), ['list' => $id, 'active' => 'all']);
    }

    public function show(string $id): array
    {
        return $this->assignments->customer($id);
    }

    public function assign(Request $request, string $id): array
    {
        $data = $request->validate(['list' => 'required|string|min:1|max:60', 'note' => 'nullable|string|max:1000']);

        return $this->assignments->assign(AuthUser::current(), $id, $data['list'], $data['note'] ?? null);
    }

    public function unassign(Request $request, string $id): array
    {
        $data = $request->validate(['note' => 'nullable|string|max:1000', 'reason' => 'nullable|string|max:1000']);

        return $this->assignments->unassign(AuthUser::current(), $id, ($data['note'] ?? null) ?: ($data['reason'] ?? null));
    }

    /** Several customers onto one list. */
    public function assignMany(Request $request, string $id): array
    {
        $data = $request->validate([
            'customers' => 'required|array|min:1|max:'.AssignmentsService::MANY_LIMIT, 'customers.*' => 'required|string|min:1|max:60', 'note' => 'nullable|string|max:1000',
        ]);

        return $this->assignments->assignMany(AuthUser::current(), $id, $data['customers'], $data['note'] ?? null);
    }
}
