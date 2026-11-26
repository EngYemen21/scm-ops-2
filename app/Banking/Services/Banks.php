<?php

namespace App\Banking\Services;

use App\Finance\Models\BankAccount;
use App\Support\AppError;

/**
 * The OPS bank accounts and cash boxes the section works on. They are finance's master data (`/api/finance/banks`):
 * the section reads them and never changes them.
 */
class Banks
{
    /** @var array<string, BankAccount> */
    private array $found = [];

    /** @var array<string, string[]> ledger account id → the codes of the bank accounts that post to it */
    private array $onAccount = [];

    /** By id or by code. */
    public function find(string $idOrCode): BankAccount
    {
        return $this->found[$idOrCode] ??= BankAccount::with('account:id,code,name_ar,name_en,is_postable,active')->where(fn ($w) => $w->where('id', $idOrCode)->orWhere('code', $idOrCode))->first()
            ?? throw AppError::notFound('BANK_ACCOUNT_NOT_FOUND', 'الحساب البنكي غير موجود — يُضاف من المالية ← البنوك', 'Bank account not found — add it in Finance → Banks');
    }

    /**
     * The OTHER bank accounts that post to the same ledger account. Finance gives every bank a ledger account of its
     * own unless a person names an existing one; where two share one, an entry no document assigns to a bank is shown
     * under each of them until it is matched (see BookItems).
     *
     * @return array<int, array{id:string, code:string}>
     */
    public function sharing(BankAccount $bank): array
    {
        $all = $this->onAccount[$bank->account_id] ??= BankAccount::where('account_id', $bank->account_id)->orderBy('code')->pluck('code', 'id')->all();
        $out = [];
        foreach ($all as $id => $code) {
            if ((string) $id !== (string) $bank->id) {
                $out[] = ['id' => (string) $id, 'code' => (string) $code];
            }
        }

        return $out;
    }

    public function flush(): void
    {
        $this->found = $this->onAccount = [];
    }
}
