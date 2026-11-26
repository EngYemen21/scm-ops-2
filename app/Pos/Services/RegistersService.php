<?php

namespace App\Pos\Services;

use App\Finance\Models\Account;
use App\Finance\Models\BankAccount;
use App\Models\Customer;
use App\Models\Warehouse;
use App\Pos\Models\PosDenomination;
use App\Pos\Models\PosOrderPayment;
use App\Pos\Models\PosPaymentMethod;
use App\Pos\Models\PosRegister;
use App\Pos\Models\PosSession;
use App\Pos\Support\Money;
use App\Pos\Support\Owned;
use App\Services\Core\AuditService;
use App\Services\Core\SettingsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;

/**
 * What the counter is made of: registers, payment methods (each bound to a finance bank account or cash box) and the
 * denominations cash is counted in. Nothing is deleted — a register or a method is switched off. While the section
 * follows Odoo, what came from Odoo is read-only (POS_OWNED_BY_SOURCE).
 */
class RegistersService
{
    public function __construct(private readonly AuditService $audit, private readonly SettingsService $settings, private readonly SessionsService $sessions) {}

    public function lookups(AuthUser $user): array
    {
        return [
            'registers' => PosRegister::with(['methods:id,code,name_ar,name_en,kind,active', 'warehouse:id,code,name_ar,name_en'])->where('active', true)->orderBy('code')->get()
                ->map(fn (PosRegister $r) => $this->shape($r))->all(),
            'methods' => PosPaymentMethod::with('bankAccount:id,code,name_ar,name_en,kind,active')->orderBy('sequence')->orderBy('code')->get()->map->toArray()->all(),
            'denominations' => PosDenomination::where('active', true)->orderBy('value')->get(['id', 'value', 'kind', 'label'])->map->toArray()->all(),
            'bankAccounts' => BankAccount::where('active', true)->orderBy('code')->get(['id', 'code', 'name_ar', 'name_en', 'kind'])->map->toArray()->all(),
            'vatPct' => Money::str(Money::bp($this->settings->get('sales.vatPct') ?? 15)),
            'follows' => Owned::following(),
            'session' => $this->sessions->current($user),
            'kinds' => PosPaymentMethod::KINDS, 'restock' => PosRegister::RESTOCK, 'receipts' => PosRegister::RECEIPTS,
            'can' => ['sell' => $user->can('pos.sell'), 'manage' => $user->can('pos.manage'), 'viewCost' => $user->can('inventory.view_cost')],
        ];
    }

    // ───────────── registers ─────────────

    public function list(Paging $page, array $f): array
    {
        $q = PosRegister::query()->with(['methods:id,code,name_ar,name_en,kind,active', 'warehouse:id,code,name_ar,name_en', 'customer:id,code,name_ar,name_en']);
        if (($f['active'] ?? null) !== null && $f['active'] !== '') {
            $q->where('active', $f['active'] === '1' || $f['active'] === 'true');
        }
        if (! empty($f['warehouse'])) {
            $q->where('warehouse_id', $f['warehouse']);
        }
        if (($f['source'] ?? null) === 'odoo') {
            $q->where('source_system', 'odoo');
        } elseif (($f['source'] ?? null) === 'ops') {
            $q->whereNull('source_system');
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like));
        }
        $q->orderBy($page->sort === 'name' ? 'name_ar' : 'code', $page->sort ? $page->order : 'asc')->orderBy('id');

        return $page->paginate($q, fn (PosRegister $r) => $this->shape($r));
    }

    public function get(string $id): array
    {
        return $this->shape($this->find($id)->load(['methods.bankAccount:id,code,name_ar,name_en,kind,active', 'warehouse:id,code,name_ar,name_en,active', 'customer:id,code,name_ar,name_en,active']));
    }

    private function shape(PosRegister $r): array
    {
        $row = $r->toArray();
        $row['openSession'] = PosSession::where('open_register_key', $r->id)->first(['id', 'number', 'cashier_name', 'opened_at'])?->toArray();
        $row['notReady'] = $r->relationLoaded('methods') && $r->relationLoaded('warehouse') ? array_column(SessionsService::notReady($r->loadMissing('customer', 'methods.bankAccount')), 'key') : [];
        $row['owned'] = Owned::bySource($r);

        return $row;
    }

    public function create(AuthUser $user, array $dto): array
    {
        $id = DB::transaction(function () use ($user, $dto) {
            if (PosRegister::where('code', $dto['code'])->exists()) {
                throw AppError::conflict('POS_REGISTER_CODE', "الرمز {$dto['code']} مستخدم لصندوق آخر", "Code {$dto['code']} is already used");
            }
            $values = $this->values($dto, true);
            $r = PosRegister::create($values + ['code' => mb_strtoupper(trim($dto['code'])), 'active' => true, 'created_by' => $user->username]);
            $this->syncMethods($r, $dto['methods'] ?? []);
            $this->audit->log($user, ['action' => 'POS.REGISTER.CREATE', 'entityType' => 'PosRegister', 'entityId' => $r->id, 'entityNumber' => $r->code, 'newValue' => $values + ['methods' => $dto['methods'] ?? []]]);

            return $r->id;
        });

        return $this->get($id);
    }

    public function update(AuthUser $user, string $id, array $dto): array
    {
        DB::transaction(function () use ($user, $id, $dto) {
            $r = PosRegister::whereKey($this->find($id)->id)->lockForUpdate()->first();
            Owned::refuse($r, 'register');
            $values = $this->values($dto, false);
            if (array_key_exists('warehouse_id', $values) && $values['warehouse_id'] !== $r->warehouse_id && PosSession::where('open_register_key', $r->id)->exists()) {
                throw AppError::rule('POS_REGISTER_BUSY', 'لا يُغيَّر مستودع صندوق عليه جلسة مفتوحة', 'The warehouse of a register with an open session cannot change');
            }
            $old = array_intersect_key($r->getAttributes(), $values);
            $r->update($values + ['updated_by' => $user->username]);
            if (array_key_exists('methods', $dto)) {
                $old['methods'] = $r->methods()->pluck('code')->all();
                $this->syncMethods($r, $dto['methods'] ?? []);
            }
            $this->audit->log($user, ['action' => 'POS.REGISTER.UPDATE', 'entityType' => 'PosRegister', 'entityId' => $r->id, 'entityNumber' => $r->code, 'oldValue' => $old,
                'newValue' => $values + (array_key_exists('methods', $dto) ? ['methods' => $dto['methods']] : [])]);
        });

        return $this->get($id);
    }

    /** camelCase payload → columns, every reference checked. */
    private function values(array $dto, bool $create): array
    {
        $map = ['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'maxDiscountPct' => 'max_discount_pct', 'allowNegativeStock' => 'allow_negative_stock', 'refundRestock' => 'refund_restock',
            'refundNeedsManager' => 'refund_needs_manager', 'receiptFormat' => 'receipt_format', 'receiptFooter' => 'receipt_footer', 'sellerNameAr' => 'seller_name_ar', 'sellerNameEn' => 'seller_name_en',
            'sellerVatNo' => 'seller_vat_no', 'sellerCr' => 'seller_cr', 'sellerAddress' => 'seller_address', 'active' => 'active'];
        $out = [];
        foreach ($map as $k => $col) {
            if (array_key_exists($k, $dto)) {
                $out[$col] = $dto[$k];
            }
        }
        if ($create) {
            $out['name_en'] = ($out['name_en'] ?? null) ?: $out['name_ar'];
        }
        if (array_key_exists('max_discount_pct', $out)) {
            $out['max_discount_pct'] = Money::str(Money::bp($out['max_discount_pct'] ?? 0));
        }
        if (array_key_exists('warehouse', $dto)) {
            $w = Warehouse::where('id', $dto['warehouse'])->orWhere('code', $dto['warehouse'])->first() ?? throw AppError::notFound('WAREHOUSE_NOT_FOUND', 'المستودع غير موجود', 'Warehouse not found');
            $out['warehouse_id'] = $w->id;
        } elseif ($create) {
            throw AppError::validation('POS_REGISTER_WAREHOUSE', 'حدد المستودع الذي يبيع منه الصندوق', 'Choose the warehouse the register sells from');
        }
        if (array_key_exists('customer', $dto)) {
            $c = $dto['customer'] ? (Customer::where('id', $dto['customer'])->orWhere('code', $dto['customer'])->first() ?? throw AppError::notFound('CUSTOMER_NOT_FOUND', 'العميل غير موجود', 'Customer not found')) : null;
            $out['default_customer_id'] = $c?->id;
        }
        if (array_key_exists('differenceAccount', $dto)) {
            $a = $dto['differenceAccount'] ? (Account::where('id', $dto['differenceAccount'])->orWhere('code', $dto['differenceAccount'])->first() ?? throw AppError::notFound('ACCOUNT_NOT_FOUND', 'الحساب غير موجود', 'Account not found')) : null;
            if ($a && (! $a->is_postable || ! $a->active)) {
                throw AppError::rule('ACCOUNT_NOT_POSTABLE', "الحساب {$a->code} لا يقبل الترحيل", "Account {$a->code} is not postable");
            }
            $out['difference_account_id'] = $a?->id;
        }

        return $out;
    }

    private function syncMethods(PosRegister $r, array $methods): void
    {
        $ids = [];
        foreach ($methods as $m) {
            $ids[] = (PosPaymentMethod::where('id', $m)->orWhere('code', $m)->first() ?? throw AppError::notFound('POS_METHOD_NOT_FOUND', "طريقة الدفع {$m} غير موجودة", 'Payment method not found'))->id;
        }
        $ids = array_values(array_unique($ids));
        if (PosPaymentMethod::whereIn('id', $ids)->where('kind', 'cash')->count() > 1) {
            throw AppError::rule('POS_ONE_CASH_METHOD', 'للصندوق طريقة دفع نقدية واحدة (درج واحد يُعَدّ عند الإغلاق)', 'A register has one cash method (one drawer counted at closing)');
        }
        $r->methods()->sync($ids);
    }

    private function find(string $id): PosRegister
    {
        return PosRegister::where('id', $id)->orWhere('code', $id)->first() ?? throw AppError::notFound('POS_REGISTER_NOT_FOUND', 'الصندوق غير موجود', 'Register not found');
    }

    // ───────────── payment methods ─────────────

    public function createMethod(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $code = mb_strtoupper(trim($dto['code']));
            if (PosPaymentMethod::where('code', $code)->exists()) {
                throw AppError::conflict('POS_METHOD_CODE', "الرمز {$code} مستخدم", "Code {$code} is already used");
            }
            $values = $this->methodValues($dto, null);
            $m = PosPaymentMethod::create($values + ['code' => $code, 'active' => true, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'POS.METHOD.CREATE', 'entityType' => 'PosPaymentMethod', 'entityId' => $m->id, 'entityNumber' => $m->code, 'newValue' => $values]);

            return $m->load('bankAccount:id,code,name_ar,name_en,kind,active')->toArray();
        });
    }

    public function updateMethod(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $m = PosPaymentMethod::where(fn ($w) => $w->where('id', $id)->orWhere('code', $id))->lockForUpdate()->first() ?? throw AppError::notFound('POS_METHOD_NOT_FOUND', 'طريقة الدفع غير موجودة', 'Payment method not found');
            Owned::refuse($m, 'method');
            $values = $this->methodValues($dto, $m);
            if (isset($values['kind']) && $values['kind'] !== $m->kind && PosOrderPayment::where('method_id', $m->id)->exists()) {
                throw AppError::rule('POS_METHOD_IN_USE', 'نوع طريقة دفع استُخدمت في مبيعات لا يتغير — أنشئ طريقة جديدة', 'The kind of a method used in sales cannot change — create a new method');
            }
            $old = array_intersect_key($m->getAttributes(), $values);
            $m->update($values + ['updated_by' => $user->username]);
            $this->audit->log($user, ['action' => 'POS.METHOD.UPDATE', 'entityType' => 'PosPaymentMethod', 'entityId' => $m->id, 'entityNumber' => $m->code, 'oldValue' => $old, 'newValue' => $values]);

            return $m->refresh()->load('bankAccount:id,code,name_ar,name_en,kind,active')->toArray();
        });
    }

    private function methodValues(array $dto, ?PosPaymentMethod $m): array
    {
        $out = [];
        foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'kind' => 'kind', 'requiresReference' => 'requires_reference', 'sequence' => 'sequence', 'active' => 'active'] as $k => $col) {
            if (array_key_exists($k, $dto)) {
                $out[$col] = $dto[$k];
            }
        }
        if (! $m) {
            $out['name_en'] = ($out['name_en'] ?? null) ?: $out['name_ar'];
            $out['requires_reference'] ??= in_array($out['kind'], ['card', 'transfer'], true);
        }
        $kind = $out['kind'] ?? $m?->kind;
        $bankId = $m?->bank_account_id;
        if (array_key_exists('bankAccount', $dto)) {
            $bankId = $dto['bankAccount'] ? (BankAccount::where('id', $dto['bankAccount'])->orWhere('code', $dto['bankAccount'])->first() ?? throw AppError::notFound('BANK_ACCOUNT_NOT_FOUND', 'الحساب البنكي غير موجود', 'Bank account not found'))->id : null;
            $out['bank_account_id'] = $bankId;
        }
        if ($kind === 'account') {
            $out['bank_account_id'] = null;   // on account moves no money
        } elseif ($bankId) {
            $bank = BankAccount::find($bankId);
            if (($kind === 'cash') !== ($bank->kind === 'cash')) {
                throw AppError::rule('POS_METHOD_ACCOUNT_KIND', $kind === 'cash' ? 'الطريقة النقدية تُربط بصندوق نقدي' : 'طريقة الشبكة أو التحويل تُربط بحساب بنكي لا بصندوق نقدي',
                    $kind === 'cash' ? 'A cash method is bound to a cash box' : 'A card or transfer method is bound to a bank account, not a cash box');
            }
        }

        return $out;
    }

    // ───────────── denominations ─────────────

    public function createDenomination(AuthUser $user, array $dto): array
    {
        $value = Money::cents($dto['value']);
        if ($value <= 0) {
            throw AppError::validation('POS_DENOMINATION', 'قيمة الفئة أكبر من صفر', 'A denomination is positive');
        }
        if (PosDenomination::where('active', true)->get(['value'])->contains(fn ($d) => Money::cents($d->value) === $value)) {
            throw AppError::conflict('POS_DENOMINATION_EXISTS', 'هذه الفئة موجودة', 'This denomination exists');
        }
        $d = PosDenomination::create(['value' => Money::str($value), 'kind' => $dto['kind'], 'label' => $dto['label'] ?? Money::str($value), 'active' => true, 'created_by' => $user->username]);
        $this->audit->log($user, ['action' => 'POS.DENOMINATION.CREATE', 'entityType' => 'PosDenomination', 'entityId' => $d->id, 'entityNumber' => $d->value]);

        return $d->toArray();
    }

    public function updateDenomination(AuthUser $user, string $id, array $dto): array
    {
        $d = PosDenomination::find($id) ?? throw AppError::notFound('POS_DENOMINATION_NOT_FOUND', 'الفئة غير موجودة', 'Denomination not found');
        Owned::refuse($d, 'denomination');
        $values = array_filter(['label' => $dto['label'] ?? null, 'kind' => $dto['kind'] ?? null], fn ($v) => $v !== null) + (array_key_exists('active', $dto) ? ['active' => (bool) $dto['active']] : []);
        $d->update($values);
        $this->audit->log($user, ['action' => 'POS.DENOMINATION.UPDATE', 'entityType' => 'PosDenomination', 'entityId' => $d->id, 'entityNumber' => $d->value, 'newValue' => $values]);

        return $d->refresh()->toArray();
    }
}
