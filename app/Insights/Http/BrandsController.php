<?php

namespace App\Insights\Http;

use App\Http\Controllers\Controller;
use App\Insights\Models\BrandOwner;
use App\Insights\Models\Franchisee;
use App\Insights\Services\BrandDashboardService;
use App\Insights\Services\BrandOwnersService;
use App\Insights\Services\OdooHistory;
use App\Insights\Services\SalesFacts;
use App\Insights\Support\Access;
use App\Insights\Support\Settings;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Brand owners, their franchisees, the brand dashboard and the lists behind its figures. */
class BrandsController extends Controller
{
    public function __construct(private readonly BrandOwnersService $owners, private readonly BrandDashboardService $dashboard) {}

    /** What this user is in the section (the client shapes its tabs on it; the server enforces it anyway). */
    public function me(): array
    {
        $u = AuthUser::current();
        $owner = Access::ownerOf($u);

        return ['manager' => Access::manager($u), 'outsider' => Access::outsider($u), 'brandOwner' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'brandName' => $owner->brand_name, 'state' => $owner->state] : null,
            'expiryManager' => $u->can('expiryalert.manage'), 'finance' => $u->can('fin.report'), 'cost' => $u->can('inventory.view_cost'), 'export' => $u->can('report.export')];
    }

    public function index(Request $request): array
    {
        $f = $request->validate(['state' => ['nullable', Rule::in(BrandOwner::STATES)]]);

        return $this->owners->list(AuthUser::current(), Paging::from($request, 25), $f);
    }

    public function lookups(): array
    {
        return $this->owners->lookups();
    }

    public function show(string $id): array
    {
        return $this->owners->show(AuthUser::current(), $id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|min:2|max:190', 'userId' => 'required|string|max:60'] + self::ownerRules());

        return response()->json($this->owners->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate(['name' => 'sometimes|string|min:2|max:190', 'userId' => 'sometimes|nullable|string|max:60'] + self::ownerRules());

        return $this->owners->update(AuthUser::current(), $id, $data);
    }

    public function transition(Request $request, string $id): array
    {
        $data = $request->validate(['to' => ['required', Rule::in(BrandOwner::STATES)], 'reason' => 'nullable|string|max:500']);

        return $this->owners->transition(AuthUser::current(), $id, $data['to'], $data['reason'] ?? null);
    }

    public function addFranchisee(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['customerId' => 'required|string|max:60'] + self::franchiseeRules());

        return response()->json($this->owners->addFranchisee(AuthUser::current(), $id, $data), 201);
    }

    public function updateFranchisee(Request $request, string $id): array
    {
        return $this->owners->updateFranchisee(AuthUser::current(), $id, $request->validate(self::franchiseeRules()));
    }

    public function franchiseeStatus(Request $request, string $id): array
    {
        $data = $request->validate(['to' => ['required', Rule::in(Franchisee::STATES)], 'reason' => 'nullable|string|max:500']);

        return $this->owners->franchiseeStatus(AuthUser::current(), $id, $data['to'], $data['reason'] ?? null);
    }

    public function dashboard(Request $request, string $id): array
    {
        $f = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return $this->dashboard->dashboard(AuthUser::current(), $id, $f['from'] ?? null, $f['to'] ?? null);
    }

    public function lines(Request $request, string $id): array
    {
        $f = $request->validate([
            'figure' => ['required', Rule::in(['sales', 'returns', 'overdue', 'payments'])], 'customer' => 'nullable|string|max:60', 'product' => 'nullable|string|max:60',
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'], 'from' => 'nullable|date', 'to' => 'nullable|date', 'source' => ['nullable', Rule::in(['ops', 'odoo'])], 'kind' => ['nullable', Rule::in(['invoice', 'credit_note'])],
        ]);

        return $this->dashboard->lines(AuthUser::current(), $id, $f, Paging::from($request, 50));
    }

    /** The cutover day between Odoo's history and OPS's own invoices, and where the copy of Odoo's invoices stands. */
    public function history(SalesFacts $facts, OdooHistory $history): array
    {
        Access::insider(AuthUser::current());

        return ['cutover' => $facts->cutover(), 'copy' => $history->status()];
    }

    public function setCutover(Request $request, SalesFacts $facts, AuditService $audit): array
    {
        $data = $request->validate(['date' => 'present|nullable|date_format:Y-m-d']);
        $u = AuthUser::current();
        DB::transaction(function () use ($data, $u, $facts, $audit) {
            $old = $facts->cutover();
            Settings::put(Settings::CUTOVER, $data['date'], $u->username);
            $audit->log($u, ['action' => 'INSIGHTS.CUTOVER', 'entityType' => 'InsightsSetting', 'entityId' => Settings::CUTOVER, 'oldValue' => $old, 'newValue' => ['date' => $data['date']]]);
        });

        return ['cutover' => $facts->cutover()];
    }

    /** Advances the copy of Odoo's invoices for at most `seconds` (the command does the long first pass). */
    public function syncHistory(Request $request, OdooHistory $history, AuditService $audit): array
    {
        $data = $request->validate(['seconds' => 'nullable|integer|min:1|max:25']);
        if (! $history->installed()) {
            throw AppError::rule('INSIGHTS_NOT_INSTALLED', 'جداول القسم لم تُنشأ بعد', 'The tables of the section do not exist yet');
        }
        $out = $history->advance((float) ($data['seconds'] ?? 10));
        $audit->log(AuthUser::current(), ['action' => 'INSIGHTS.HISTORY_SYNC', 'entityType' => 'InsightsSetting', 'entityId' => Settings::HISTORY, 'newValue' => $out]);

        return $out + ['status' => $history->status()];
    }

    private static function ownerRules(): array
    {
        return [
            'brandName' => 'sometimes|nullable|string|max:120', 'contactCustomerId' => 'sometimes|nullable|string|max:60', 'dateStart' => 'sometimes|nullable|date_format:Y-m-d',
            'allowInvoice' => 'sometimes|boolean', 'allowPayment' => 'sometimes|boolean', 'allowReturn' => 'sometimes|boolean', 'allowMargin' => 'sometimes|boolean',
            'notes' => 'sometimes|nullable|string|max:4000', 'productIds' => 'sometimes|array|max:2000', 'productIds.*' => 'string|max:60',
            'categoryIds' => 'sometimes|array|max:500', 'categoryIds.*' => 'string|max:60',
        ];
    }

    private static function franchiseeRules(): array
    {
        return ['region' => 'sometimes|nullable|string|max:120', 'city' => 'sometimes|nullable|string|max:120', 'dateStart' => 'sometimes|nullable|date_format:Y-m-d',
            'monthlyTarget' => 'sometimes|numeric|min:0|max:999999999', 'notes' => 'sometimes|nullable|string|max:4000'];
    }
}
