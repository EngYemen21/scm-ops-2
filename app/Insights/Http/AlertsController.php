<?php

namespace App\Insights\Http;

use App\Http\Controllers\Controller;
use App\Insights\Models\BrandAlert;
use App\Insights\Services\BrandAlertsService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The alerts of the brand dashboards and their rules. */
class AlertsController extends Controller
{
    public function __construct(private readonly BrandAlertsService $alerts) {}

    public function index(Request $request): array
    {
        $f = $request->validate([
            'owner' => 'nullable|string|max:60', 'read' => 'nullable|in:0,1,true,false', 'type' => ['nullable', Rule::in(BrandAlert::TYPES)], 'severity' => ['nullable', Rule::in(BrandAlert::SEVERITIES)],
            'customer' => 'nullable|string|max:60', 'from' => 'nullable|date', 'to' => 'nullable|date',
        ]);

        return $this->alerts->list(AuthUser::current(), $f['owner'] ?? null, $f, Paging::from($request, 25));
    }

    public function read(string $id): array
    {
        return $this->alerts->mark(AuthUser::current(), $id, true);
    }

    public function unread(string $id): array
    {
        return $this->alerts->mark(AuthUser::current(), $id, false);
    }

    public function readAll(string $owner): array
    {
        return $this->alerts->readAll(AuthUser::current(), $owner);
    }

    public function rules(): array
    {
        return ['items' => array_values($this->alerts->rules())];
    }

    public function updateRule(Request $request, string $type): array
    {
        $data = $request->validate(['enabled' => 'sometimes|boolean', 'threshold' => 'sometimes|numeric|min:0|max:100000', 'window' => 'sometimes|integer|min:0|max:365']);

        return $this->alerts->updateRule(AuthUser::current(), $type, $data);
    }

    public function run(Request $request): array
    {
        $data = $request->validate(['owner' => 'nullable|string|max:60']);

        return $this->alerts->run(AuthUser::current(), $data['owner'] ?? null);
    }
}
