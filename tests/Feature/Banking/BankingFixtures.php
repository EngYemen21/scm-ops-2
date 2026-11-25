<?php

namespace Tests\Feature\Banking;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * What the banking stories share. Every story works on a bank account of its own, made through finance's own API
 * (`POST /api/finance/banks`), so its ledger account holds only that story's receipts, expenses and write-offs.
 *
 * Users: `finance` holds page.banking + bankstatement.manage + bank.reconcile + bank.manage; `gm` reads the section
 * (page.banking) and nothing else; `sales` does not see the section; `bankclerk` (made here) enters statements
 * (bankstatement.manage) but does not reconcile.
 */
trait BankingFixtures
{
    /** A new OPS bank account with its own ledger account. @return array the finance shape (id, code, account …) */
    protected function newBank(string $prefix, ?string $iban = null, string $kind = 'bank', ?string $nameAr = null): array
    {
        $code = strtoupper($prefix).self::uid();

        return $this->expectOk($this->postAs('finance', '/api/finance/banks', array_filter(['code' => $code, 'nameAr' => $nameAr ?? "بنك الاختبار {$code}", 'nameEn' => "Test bank {$code}", 'kind' => $kind,
            'bankName' => 'Test Bank', 'iban' => $iban])));
    }

    /** A customer of the story (a receipt needs one). */
    protected function customerCode(): string
    {
        $code = 'CUS-B'.self::uid().random_int(10, 99);
        Customer::create(['code' => $code, 'name_ar' => "عميل البنك {$code}", 'name_en' => "Bank customer {$code}", 'zone' => 'الرياض', 'contact' => '0550000000', 'terms' => 'نقدي / محفظة']);

        return $code;
    }

    /** A customer receipt into the bank: one posted ledger entry of +amount on the bank's account. @return array the payment */
    protected function receipt(string $bank, string $customer, string|float $amount, string $date, ?string $reference = null): array
    {
        return $this->expectOk($this->postAs('finance', '/api/finance/receipts', array_filter(['customer' => $customer, 'amount' => $amount, 'date' => $date, 'method' => 'bank', 'bankAccount' => $bank,
            'reference' => $reference], fn ($v) => $v !== null)));
    }

    /** An expense paid from the bank (no VAT): one posted ledger entry of −amount on the bank's account. */
    protected function expense(string $bank, string|float $amount, string $date, string $description = 'مصروف من البنك'): array
    {
        return $this->expectOk($this->postAs('finance', '/api/finance/expenses', ['date' => $date, 'account' => $this->expenseAccount(), 'amount' => $amount, 'vatPct' => 0, 'settlement' => 'bank',
            'bankAccount' => $bank, 'description' => $description]));
    }

    /** The posted entry of a payment / expense on the bank's ledger account, as the section lists it. */
    protected function entryOf(string $bank, string $documentNumber): array
    {
        $items = $this->expectOk($this->getAs('finance', "/api/banking/ledger-items?bank={$bank}&state=all&q=".urlencode($documentNumber)))['items'];
        $this->assertNotEmpty($items, "no ledger entry for {$documentNumber}");

        return $items[0];
    }

    /** An expense account a write-off rule may post to. */
    protected function expenseAccount(): string
    {
        static $code = null;

        return $code ??= (string) DB::table('fin_accounts')->where('type', 'expense')->where('is_postable', true)->where('active', true)->orderBy('code')->value('code');
    }

    /** A statement of the bank. @return array the detail */
    protected function newStatement(string $bank, string $from, string $to, string $opening, string $closing, string $user = 'finance'): array
    {
        $res = $this->postAs($user, '/api/banking/statements', ['bankAccount' => $bank, 'dateFrom' => $from, 'dateTo' => $to, 'openingBalance' => $opening, 'closingBalance' => $closing, 'name' => "كشف {$from}"]);
        $this->assertSame(201, $res->getStatusCode(), $res->getContent());

        return (array) $res->json();
    }

    /** Typed lines. @return array<int, array> the lines as shaped */
    protected function addLines(string $statement, array $lines): array
    {
        $res = $this->postAs('finance', "/api/banking/statements/{$statement}/lines", ['lines' => $lines]);
        $this->assertSame(201, $res->getStatusCode(), $res->getContent());

        return $res->json('lines');
    }

    /** A write-off rule. */
    protected function newRule(string $name, string $max, string $nature = 'both', ?string $bank = null, ?string $matchText = null): array
    {
        $res = $this->postAs('finance', '/api/banking/rules', array_filter(['nameAr' => $name, 'nameEn' => $name, 'account' => $this->expenseAccount(), 'maxAmount' => $max, 'nature' => $nature, 'label' => "تسوية {$name}",
            'bankAccount' => $bank, 'matchText' => $matchText], fn ($v) => $v !== null));
        $this->assertSame(201, $res->getStatusCode(), $res->getContent());

        return (array) $res->json();
    }

    protected function match(array $lines, array $entries, array $extra = []): array
    {
        $res = $this->postAs('finance', '/api/banking/matches', ['statementLines' => $lines, 'ledgerLines' => $entries] + $extra);
        $this->assertSame(201, $res->getStatusCode(), $res->getContent());

        return (array) $res->json();
    }

    protected function statement(string $id): array
    {
        return $this->expectOk($this->getAs('finance', "/api/banking/statements/{$id}"));
    }

    protected function day(int $offset): string
    {
        return now()->startOfDay()->addDays($offset)->toDateString();
    }

    /** A signed-in user who enters statements and nothing more: page.banking + bankstatement.manage, no bank.reconcile. */
    protected function clerk(): string
    {
        $username = 'bankclerk';
        if (! DB::table('users')->where('username', $username)->exists()) {
            $role = (string) Str::ulid();
            DB::table('roles')->insert(['id' => $role, 'key' => 'bankclerk', 'name_ar' => 'مدخل كشوف البنك', 'name_en' => 'Bank statement clerk', 'is_system' => false]);
            foreach (DB::table('permissions')->whereIn('key', ['page.banking', 'bankstatement.manage'])->pluck('id') as $permission) {
                DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
            }
            $user = (string) Str::ulid();
            DB::table('users')->insert(['id' => $user, 'username' => $username, 'password_hash' => Hash::make((string) env('SEED_PASSWORD')), 'name_ar' => 'مدخل الكشوف', 'name_en' => 'Statement clerk',
                'initials' => 'SC', 'active' => true, 'must_change_password' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $user, 'role_id' => $role]);
        }

        return $username;
    }
}
