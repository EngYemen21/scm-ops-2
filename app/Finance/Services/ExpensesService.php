<?php

namespace App\Finance\Services;

use App\Finance\Models\BankAccount;
use App\Finance\Models\CostCenter;
use App\Finance\Models\Expense;
use App\Models\Supplier;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Expense vouchers: an operating cost with its dimensions (cost center, vehicle, trip) settled from a bank account /
 * cash box, charged to a supplier account, or accrued. Fleet-linked vouchers feed the trip cost sheet.
 */
class ExpensesService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountMap $map,
        private readonly FleetPosting $fleet,
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
    ) {}

    /** @param  array{date?:?string, account:string, amount:float|int|string, vatPct?:float|int|string|null, settlement?:string, bankAccount?:?string, supplier?:?string, costCenter?:?string, vehicle?:?string, trip?:?string, description:string, reference?:?string}  $dto */
    public function create(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $account = $this->map->code($dto['account']);
            if ($account->type !== 'expense' && $account->type !== 'asset') {
                throw AppError::rule('EXPENSE_ACCOUNT', "الحساب {$account->code} ليس حساب مصروف", 'Not an expense account');
            }
            $amount = round((float) $dto['amount'], 2);
            if ($amount <= 0) {
                throw AppError::validation('AMOUNT_INVALID', 'المبلغ يجب أن يكون أكبر من صفر', 'Amount must be positive');
            }
            $vatPct = isset($dto['vatPct']) && $dto['vatPct'] !== null && $dto['vatPct'] !== '' ? (float) $dto['vatPct'] : 0.0;
            $vat = round($amount * $vatPct / 100, 2);
            $total = round($amount + $vat, 2);
            $settlement = $dto['settlement'] ?? 'bank';
            if (! in_array($settlement, Expense::SETTLEMENTS, true)) {
                throw AppError::validation('SETTLEMENT', 'طريقة التسوية غير معروفة', 'Unknown settlement');
            }
            $bank = null;
            $supplier = null;
            if ($settlement === 'bank') {
                $bank = BankAccount::where('code', $dto['bankAccount'] ?? 'BANK')->orWhere('id', $dto['bankAccount'] ?? '')->first() ?? throw AppError::notFound('BANK_ACCOUNT_NOT_FOUND', 'الحساب البنكي غير موجود', 'Bank account not found');
            } elseif ($settlement === 'supplier') {
                $supplier = Supplier::where('code', $dto['supplier'] ?? '')->orWhere('id', $dto['supplier'] ?? '')->first() ?? throw AppError::notFound('SUPPLIER_NOT_FOUND', 'المورد غير موجود', 'Supplier not found');
            }
            $vehicle = ! empty($dto['vehicle']) ? (Vehicle::where('code', $dto['vehicle'])->orWhere('id', $dto['vehicle'])->first() ?? throw AppError::notFound('VEHICLE_NOT_FOUND', 'المركبة غير موجودة', 'Vehicle not found')) : null;
            $trip = ! empty($dto['trip']) ? (Trip::where('number', $dto['trip'])->orWhere('id', $dto['trip'])->first() ?? throw AppError::notFound('TRIP_NOT_FOUND', 'الرحلة غير موجودة', 'Trip not found')) : null;
            $cc = ! empty($dto['costCenter']) ? ($this->map->costCenter($dto['costCenter']) ?? throw AppError::notFound('COST_CENTER_NOT_FOUND', 'مركز التكلفة غير موجود', 'Cost center not found')) : ($vehicle ? $this->map->forVehicle($vehicle->id) : null);
            $date = ! empty($dto['date']) ? Carbon::parse($dto['date'])->startOfDay() : now()->startOfDay();
            $e = Expense::create([
                'number' => $this->numbering->next('EXP'), 'expense_date' => $date->toDateString(), 'account_id' => $account->id, 'amount' => $amount, 'vat_pct' => $vatPct, 'vat_amount' => $vat, 'total' => $total,
                'settlement' => $settlement, 'bank_account_id' => $bank?->id, 'supplier_id' => $supplier?->id, 'cost_center_id' => $cc?->id, 'vehicle_id' => $vehicle?->id, 'trip_id' => $trip?->id,
                'description' => mb_substr(trim($dto['description']), 0, 500), 'reference' => ($dto['reference'] ?? null) ?: null, 'status' => 'posted', 'created_by' => $user->username,
            ]);
            $dims = ['costCenterId' => $cc?->id, 'vehicleId' => $vehicle?->id, 'tripId' => $trip?->id, 'referenceType' => 'Expense', 'referenceId' => $e->id];
            $credit = match ($settlement) {
                'bank' => ['account' => $bank->account_id, 'credit' => $total, 'description' => "{$e->number} · {$bank->name_ar}"] + $dims,
                'supplier' => ['role' => 'ap', 'credit' => $total, 'description' => "{$e->number} · {$supplier->name_ar}", 'partnerType' => 'supplier', 'partnerId' => $supplier->id] + $dims,
                default => ['role' => 'accrued_expenses', 'credit' => $total, 'description' => "{$e->number} — مستحق"] + $dims,
            };
            $j = $this->ledger->post($user, [
                'eventKey' => "exp:{$e->id}", 'date' => $date, 'source' => ['type' => 'Expense', 'id' => $e->id, 'number' => $e->number], 'memo' => "مصروف {$e->number} — {$e->description}",
                'lines' => array_merge(
                    [['account' => $account->id, 'debit' => $amount, 'description' => $e->description] + $dims],
                    $vat > 0 ? [['role' => 'vat_input', 'debit' => $vat, 'description' => "ضريبة مدخلات {$vatPct}%"] + $dims] : [],
                    [$credit],
                ),
            ]);
            $e->update(['journal_id' => $j->id]);
            if ($trip) {
                $this->fleet->rebuildTripCosts($trip->id);
            }
            $this->audit->log($user, ['action' => 'EXPENSE.CREATE', 'entityType' => 'Expense', 'entityId' => $e->id, 'entityNumber' => $e->number, 'newValue' => ['account' => $account->code, 'total' => $total, 'settlement' => $settlement, 'vehicle' => $vehicle?->code, 'trip' => $trip?->number, 'journal' => $j->number]]);
            $this->notify->activity($user, 'Expense', $e->id, $e->number, "مصروف {$e->number}: {$e->description} — ".number_format($total, 2).' ر.س'.($vehicle ? " · {$vehicle->code}" : ''), "Expense {$e->number}: {$e->description}", ['finance']);

            return $this->get($e->id);
        });
    }

    public function void(AuthUser $user, string $idOrNumber, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw AppError::validation('REASON_REQUIRED', 'اكتب سبب الإلغاء (5 أحرف على الأقل)', 'A reason is required');
        }

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $e = Expense::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first() ?? throw AppError::notFound('EXPENSE_NOT_FOUND', 'سند المصروف غير موجود', 'Expense not found');
            if ($e->status === 'void') {
                throw AppError::rule('EXPENSE_STATE', 'السند ملغى مسبقًا', 'Already void');
            }
            if ($e->journal_id) {
                $this->ledger->reverse($user, $e->journal_id, "إلغاء {$e->number}: {$reason}");
            }
            $e->update(['status' => 'void', 'voided_by' => $user->username, 'voided_at' => now(), 'void_reason' => $reason]);
            if ($e->trip_id) {
                $this->fleet->rebuildTripCosts($e->trip_id);
            }
            $this->audit->status($user, 'Expense', $e->id, $e->number, 'posted', 'void', $reason);

            return $this->get($e->id);
        });
    }

    public function get(string $idOrNumber): array
    {
        $e = Expense::with(['account:id,code,name_ar,name_en', 'costCenter:id,code,name_ar,name_en', 'journal:id,number', 'vehicle:id,code,plate_ar'])->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('EXPENSE_NOT_FOUND', 'سند المصروف غير موجود', 'Expense not found');
        $row = $e->toArray();
        $row['supplier'] = $e->supplier_id ? Supplier::whereKey($e->supplier_id)->first(['id', 'code', 'name_ar', 'name_en'])?->toArray() : null;
        $row['trip'] = $e->trip_id ? Trip::whereKey($e->trip_id)->first(['id', 'number'])?->toArray() : null;
        $row['bankAccount'] = $e->bank_account_id ? BankAccount::whereKey($e->bank_account_id)->first(['id', 'code', 'name_ar', 'name_en'])?->toArray() : null;

        return $row;
    }

    /** @param  array{status?:?string, account?:?string, vehicle?:?string, trip?:?string, costCenter?:?string, from?:?string, to?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $q = Expense::with(['account:id,code,name_ar,name_en', 'costCenter:id,code,name_ar,name_en', 'vehicle:id,code,plate_ar']);
        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['account'])) {
            $q->where('account_id', $this->map->code($f['account'])->id);
        }
        if (! empty($f['vehicle'])) {
            $q->whereHas('vehicle', fn ($v) => $v->where('code', $f['vehicle']));
        }
        if (! empty($f['trip'])) {
            $q->whereIn('trip_id', Trip::where('number', $f['trip'])->orWhere('id', $f['trip'])->pluck('id'));
        }
        if (! empty($f['costCenter'])) {
            $q->whereIn('cost_center_id', CostCenter::where('code', $f['costCenter'])->pluck('id'));
        }
        if (! empty($f['from'])) {
            $q->whereDate('expense_date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('expense_date', '<=', $f['to']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('description', 'like', $like)->orWhere('reference', 'like', $like));
        }
        $q->orderBy($page->sort === 'total' ? 'total' : 'expense_date', $page->order)->orderBy('id', $page->order);

        return $page->paginate($q);
    }
}
