<?php

namespace App\Portal\Http;

use App\Http\Controllers\Controller;
use App\Portal\Models\PortalBranch;
use App\Portal\Services\BranchesService;
use App\Portal\Services\PortalSummary;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer branches — and the figures of the page's head. Thin: validate (camelCase keys, exactly as the client sends
 * them), call a service, return.
 */
class BranchesController extends Controller
{
    public function __construct(private readonly BranchesService $branches, private readonly PortalSummary $summary) {}

    public function summary(): array
    {
        return $this->summary->get();
    }

    public function index(Request $request): array
    {
        $filters = PortalRules::filters($request, [
            'customer' => 'nullable|string|max:160', 'state' => 'nullable|in:active,archived,all', 'origin' => 'nullable|in:ops,odoo', 'linked' => 'nullable|in:yes,no', 'site' => 'nullable|in:yes,no',
        ]);

        return $this->branches->list(Paging::from($request, 25), $filters);
    }

    public function show(string $id): array
    {
        return $this->branches->get($id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['customerId' => 'required|string|max:160', 'name' => 'required|string|min:1|max:190'] + self::fieldRules());

        return response()->json($this->branches->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        return $this->branches->update(AuthUser::current(), $id, $request->validate(['customerId' => 'sometimes|nullable|string|max:160', 'name' => 'sometimes|string|min:1|max:190'] + self::fieldRules()));
    }

    public function archive(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->branches->archive(AuthUser::current(), $id, $data['reason'] ?? null);
    }

    public function restore(string $id): array
    {
        return $this->branches->restore(AuthUser::current(), $id);
    }

    /** What the form of a branch may carry besides its name and customer. Every key is optional; in an edit an empty value clears. */
    private static function fieldRules(): array
    {
        return [
            'code' => PortalRules::text(40), 'sequence' => 'sometimes|nullable|integer|min:0|max:100000',
            'contactName' => PortalRules::text(190), 'phone' => PortalRules::text(40), 'email' => 'sometimes|nullable|email|max:190',
            'city' => PortalRules::text(120), 'district' => PortalRules::text(120), 'address' => PortalRules::text(500),
            'lat' => 'sometimes|nullable|numeric|between:-90,90', 'lng' => 'sometimes|nullable|numeric|between:-180,180',
            'windowFrom' => ['sometimes', 'nullable', 'string', PortalRules::HOUR], 'windowTo' => ['sometimes', 'nullable', 'string', PortalRules::HOUR],
            'deliveryDays' => 'sometimes|nullable|array|max:7', 'deliveryDays.*' => 'string|in:'.implode(',', PortalBranch::DAYS),
            'deliveryNotes' => PortalRules::text(500), 'siteId' => PortalRules::text(40), 'notes' => PortalRules::text(4000),
        ];
    }
}
