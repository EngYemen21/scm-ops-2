<?php

namespace App\Finance\Http;

use App\Finance\Models\Account;
use App\Finance\Services\AccountsService;
use App\Finance\Services\FinanceDashboardService;
use App\Finance\Services\FixedAssetsService;
use App\Finance\Services\PeriodService;
use App\Finance\Support\Coa;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

/** Finance master data and board: dashboard, chart of accounts, cost centers, bank accounts, periods, fixed assets. */
class FinanceController extends Controller
{
    public function __construct(
        private readonly FinanceDashboardService $dashboard,
        private readonly AccountsService $accounts,
        private readonly PeriodService $periods,
        private readonly FixedAssetsService $assets,
    ) {}

    public function board(): array
    {
        return $this->dashboard->board();
    }

    public function reconciliation(): array
    {
        return $this->dashboard->reconciliation();
    }

    // ── chart of accounts
    public function accounts(Request $request): array
    {
        return ['items' => $this->accounts->chart($request->query('balances', '1') !== '0'), 'roles' => Coa::ROLES, 'types' => Account::TYPES];
    }

    public function storeAccount(Request $request): array
    {
        $data = $request->validate(['code' => 'required|string|min:3|max:20', 'nameAr' => 'required|string|min:1', 'nameEn' => 'nullable|string', 'type' => 'required|in:asset,liability,equity,revenue,expense',
            'subtype' => 'nullable|string|max:40', 'parent' => 'nullable|string', 'isPostable' => 'nullable|boolean', 'role' => 'nullable|string|max:40']);

        return $this->accounts->create(AuthUser::current(), $data);
    }

    public function updateAccount(Request $request, string $code): array
    {
        $data = $request->validate(['nameAr' => 'sometimes|string|min:1', 'nameEn' => 'sometimes|nullable|string', 'type' => 'sometimes|in:asset,liability,equity,revenue,expense', 'subtype' => 'sometimes|nullable|string|max:40',
            'parent' => 'sometimes|nullable|string', 'isPostable' => 'sometimes|boolean', 'role' => 'sometimes|nullable|string|max:40', 'active' => 'sometimes|boolean']);

        return $this->accounts->update(AuthUser::current(), $code, $data);
    }

    // ── cost centers
    public function costCenters(): array
    {
        return ['items' => $this->accounts->costCenters()];
    }

    public function storeCostCenter(Request $request): array
    {
        $data = $request->validate(['code' => 'required|string|min:2|max:30', 'nameAr' => 'required|string|min:1', 'nameEn' => 'nullable|string', 'kind' => 'nullable|in:warehouse,fleet,vehicle,sales,admin', 'vehicle' => 'nullable|string']);

        return $this->accounts->createCostCenter(AuthUser::current(), $data);
    }

    // ── bank accounts
    public function banks(): array
    {
        return ['items' => $this->accounts->banks()];
    }

    public function storeBank(Request $request): array
    {
        $data = $request->validate(['code' => 'required|string|min:2|max:20', 'nameAr' => 'required|string|min:1', 'nameEn' => 'nullable|string', 'kind' => 'nullable|in:bank,cash', 'bankName' => 'nullable|string|max:120', 'iban' => 'nullable|string|max:40', 'account' => 'nullable|string']);

        return $this->accounts->createBank(AuthUser::current(), $data);
    }

    public function updateBank(Request $request, string $code): array
    {
        $data = $request->validate(['nameAr' => 'sometimes|string|min:1', 'nameEn' => 'sometimes|nullable|string', 'bankName' => 'sometimes|nullable|string|max:120', 'iban' => 'sometimes|nullable|string|max:40', 'active' => 'sometimes|boolean']);

        return $this->accounts->updateBank(AuthUser::current(), $code, $data);
    }

    // ── periods
    public function periods(): array
    {
        return ['items' => $this->periods->list()];
    }

    public function closePeriod(Request $request, string $code): array
    {
        $data = $request->validate(['notes' => 'nullable|string|max:500']);

        return $this->periods->close(AuthUser::current(), $code, $data['notes'] ?? null);
    }

    public function reopenPeriod(Request $request, string $code): array
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return $this->periods->reopen(AuthUser::current(), $code, $data['reason']);
    }

    // ── fixed assets
    public function assets(Request $request): array
    {
        return $this->assets->list(Paging::from($request), $request->only(['status', 'category']));
    }

    public function asset(string $code): array
    {
        return $this->assets->get($code);
    }

    public function storeAsset(Request $request): array
    {
        $data = $request->validate(['nameAr' => 'required|string|min:1', 'nameEn' => 'nullable|string', 'category' => 'nullable|in:vehicle,equipment,other', 'vehicle' => 'nullable|string', 'acquiredOn' => 'required|date',
            'cost' => 'required|numeric|gt:0', 'salvageValue' => 'nullable|numeric|min:0', 'usefulLifeMonths' => 'required|integer|min:1|max:600', 'fundedBy' => 'nullable|in:bank,supplier,opening', 'bankAccount' => 'nullable|string',
            'supplier' => 'nullable|string', 'notes' => 'nullable|string|max:500', 'accumulated' => 'nullable|numeric|min:0', 'depreciatedThrough' => 'nullable|date']);

        return $this->assets->register(AuthUser::current(), $data);
    }

    public function depreciate(Request $request): array
    {
        $data = $request->validate(['period' => 'required|date_format:Y-m']);

        return $this->assets->depreciate(AuthUser::current(), $data['period']);
    }

    public function disposeAsset(Request $request, string $code): array
    {
        $data = $request->validate(['disposedOn' => 'nullable|date', 'proceeds' => 'nullable|numeric|min:0', 'bankAccount' => 'nullable|string', 'reason' => 'required|string|min:3|max:500']);

        return $this->assets->dispose(AuthUser::current(), $code, $data);
    }
}
