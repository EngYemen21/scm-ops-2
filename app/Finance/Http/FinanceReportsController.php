<?php

namespace App\Finance\Http;

use App\Finance\Models\Budget;
use App\Finance\Services\AccountMap;
use App\Finance\Services\FinanceReportsService;
use App\Http\Controllers\Controller;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Http\Request;

/** Financial statements, sub-ledger reports, analyses and the budget. */
class FinanceReportsController extends Controller
{
    public function __construct(private readonly FinanceReportsService $reports, private readonly AccountMap $map, private readonly AuditService $audit) {}

    public function trialBalance(Request $r): array
    {
        return $this->reports->trialBalance($r->query('from'), $r->query('to'));
    }

    public function ledger(Request $r, string $code): array
    {
        return $this->reports->accountLedger($code, $r->query('from'), $r->query('to'), $r->query('costCenter'));
    }

    public function incomeStatement(Request $r): array
    {
        return $this->reports->incomeStatement($r->query('from'), $r->query('to'), $r->query('costCenter'));
    }

    public function balanceSheet(Request $r): array
    {
        return $this->reports->balanceSheet($r->query('asOf'));
    }

    public function cashFlow(Request $r): array
    {
        return $this->reports->cashFlow($r->query('from'), $r->query('to'));
    }

    public function statement(Request $r, string $type, string $code): array
    {
        if (! in_array($type, ['customer', 'supplier'], true)) {
            throw AppError::validation('PARTNER_TYPE', 'نوع الطرف: customer أو supplier', 'Partner type must be customer or supplier');
        }

        return $this->reports->partnerStatement($type, $code, $r->query('from'), $r->query('to'));
    }

    public function aging(string $type): array
    {
        if (! in_array($type, ['customer', 'supplier'], true)) {
            throw AppError::validation('PARTNER_TYPE', 'نوع الطرف: customer أو supplier', 'Partner type must be customer or supplier');
        }

        return $this->reports->aging($type);
    }

    public function vat(Request $r): array
    {
        return $this->reports->vatReturn($r->query('from'), $r->query('to'));
    }

    public function inventoryValuation(): array
    {
        return $this->reports->inventoryValuation();
    }

    public function profitability(Request $r): array
    {
        return $this->reports->profitability($r->query('by') === 'customer' ? 'customer' : 'product', $r->query('from'), $r->query('to'));
    }

    public function fleetCosts(Request $r): array
    {
        return $this->reports->fleetCosts($r->query('from'), $r->query('to'));
    }

    public function budget(Request $r): array
    {
        $period = $r->query('period') ?: now()->format('Y');

        return $this->reports->budgetVsActual($period);
    }

    /** Upsert budget amounts: [{account, period: YYYY-MM, amount, costCenter?}]. */
    public function saveBudget(Request $r): array
    {
        $data = $r->validate(['items' => 'required|array|min:1', 'items.*.account' => 'required|string', 'items.*.period' => 'required|date_format:Y-m', 'items.*.amount' => 'required|numeric|min:0', 'items.*.costCenter' => 'nullable|string']);
        $user = AuthUser::current();
        $n = 0;
        foreach ($data['items'] as $it) {
            $acct = $this->map->code($it['account']);
            $cc = ! empty($it['costCenter']) ? $this->map->costCenter($it['costCenter'])?->id : null;
            Budget::updateOrCreate(['account_id' => $acct->id, 'period_code' => $it['period'], 'cost_center_id' => $cc], ['amount' => round((float) $it['amount'], 2), 'updated_by' => $user->username, 'updated_at' => now()]);
            $n++;
        }
        $this->audit->log($user, ['action' => 'BUDGET.SAVE', 'entityType' => 'Budget', 'entityId' => null, 'entityNumber' => null, 'newValue' => ['items' => $n]]);

        return ['ok' => true, 'saved' => $n];
    }
}
