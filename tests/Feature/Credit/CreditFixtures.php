<?php

namespace Tests\Feature\Credit;

use App\Credit\Models\CreditRequest;
use App\Credit\Support\Settings;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fixtures of the credit stories. The workflow is global (every active working stage is on every request's path), so a
 * story that needs a path of its own archives the working stages and creates its own (`path()`); the customers are
 * the story's own (rule 13: never the demo data's).
 *
 * Demo users: sales (credit.request), finance (credit.review, role finance), gm (credit.approve, role gm), admin
 * (super: every permission, credit.manage among them).
 */
trait CreditFixtures
{
    private static int $creditSeq = 0;

    protected static function tag(): string
    {
        return self::uid().(++self::$creditSeq);
    }

    /** The section is handed over to OPS (records of Odoo are editable) or follows Odoo. */
    protected function followOdoo(bool $on): void
    {
        config(['odoo.enabled' => $on, 'odoo.project.credit' => $on]);
    }

    /**
     * Replaces the working stages by these, in this order. Each: [code suffix, state, overrides]. Returns code => id.
     *
     * @param  array<int, array{0:string, 1:string, 2?:array}>  $stages
     * @return array<string, string>
     */
    protected function path(array $stages): array
    {
        DB::table('credit_stages')->whereIn('state', ['submitted', 'in_review', 'pending_signature', 'ceo_approval'])->where('active', true)->update(['active' => false]);
        $out = [];
        foreach (array_values($stages) as $i => $s) {
            [$key, $state] = $s;
            $id = (string) Str::ulid();
            DB::table('credit_stages')->insert(($s[2] ?? []) + [
                'id' => $id, 'code' => 'qa_'.$key.'_'.self::tag(), 'name_ar' => "مرحلة {$key}", 'name_en' => "Stage {$key}", 'state' => $state, 'sequence' => 100 + $i * 10, 'active' => true,
                'fold' => false, 'approval_mode' => 'any_user', 'require_documents' => false, 'require_signature' => false, 'auto_send_signature' => false, 'lock_request_fields' => false,
                'portal_visible' => false, 'created_by' => 'test', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $out[$key] = $id;
        }
        app(Settings::class)->flush();

        return $out;
    }

    /** Two stages: a review by finance, the final approval by the general manager. */
    protected function twoStagePath(array $review = [], array $final = []): array
    {
        return $this->path([['review', 'in_review', $review + ['responsible_role' => 'finance']], ['final', 'ceo_approval', $final + ['responsible_role' => 'gm']]]);
    }

    /** No terms version in force, no paper required (a story that tests them sets them itself). */
    protected function plainPolicies(): void
    {
        DB::table('credit_terms_versions')->where('status', 'published')->update(['status' => 'retired', 'retired_at' => now(), 'retired_by' => 'test']);
        DB::table('credit_settings')->where('key', 'documents')->delete();
        DB::table('credit_settings')->insert(['key' => 'documents', 'value' => json_encode(['required' => []]), 'updated_by' => 'test', 'updated_at' => now()]);
        app(Settings::class)->flush();
    }

    protected function customer(array $more = []): Customer
    {
        $tag = self::tag();

        return Customer::create($more + ['code' => "CUS-CR{$tag}", 'name_ar' => "مؤسسة الائتمان {$tag}", 'name_en' => "Credit Co {$tag}", 'city' => 'الرياض', 'zone' => 'الرياض',
            'terms' => 'نقدي / محفظة', 'credit_limit' => 0, 'active' => true]);
    }

    /** A complete request body. */
    protected function body(Customer $c, array $more = []): array
    {
        return $more + [
            'customerId' => $c->id, 'companyName' => $c->name_ar, 'commercialRegistration' => '1010'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'businessActivity' => 'مطاعم', 'legalEntityType' => 'مؤسسة فردية', 'ownerName' => 'صالح المالك', 'ownerNationalId' => '1012345678', 'mobile' => '0551234567',
            'email' => 'buyer@credit.example', 'city' => 'الرياض', 'street' => 'طريق الملك فهد', 'district' => 'العليا', 'buildingNo' => '7788', 'postalCode' => '12211',
            'requestedCreditLimit' => 50000, 'requestedPaymentDays' => 30, 'monthlyPurchaseVolume' => 20000,
            'signatories' => [['name' => 'سعد المفوض', 'jobTitle' => 'مدير', 'mobile' => '0559876543']],
            'responsibles' => [['name' => 'خالد المسؤول', 'position' => 'المشتريات', 'nationalId' => '2098765432', 'mobile' => '0551112233']],
            'bankLines' => [['bankName' => 'البنك الأهلي', 'accountNumber' => '12345678901234', 'iban' => 'SA0380000000608010167519', 'branch' => 'العليا']],
        ];
    }

    protected function newRequest(?Customer $c = null, array $more = [], string $as = 'sales'): array
    {
        return $this->expectOk($this->postAs($as, '/api/credit/requests', $this->body($c ?? $this->customer(), $more)));
    }

    protected function request(string $id, string $as = 'admin'): array
    {
        return $this->expectOk($this->getAs($as, "/api/credit/requests/{$id}"));
    }

    protected function row(string $id): CreditRequest
    {
        return CreditRequest::findOrFail($id);
    }

    protected function grant(string $id, string $as = 'finance', float $limit = 40000, int $days = 30): array
    {
        return $this->expectOk($this->postAs($as, "/api/credit/requests/{$id}/assess", ['approvedCreditLimit' => $limit, 'approvedPaymentDays' => $days]));
    }

    /**
     * A user of a role of the story's own, holding these permissions. @return string the username
     */
    protected function user(string $role, array $permissions, ?string $username = null): string
    {
        $username ??= $role.'.'.self::tag();
        $roleId = DB::table('roles')->where('key', $role)->value('id');
        if (! $roleId) {
            $roleId = (string) Str::ulid();
            DB::table('roles')->insert(['id' => $roleId, 'key' => $role, 'name_ar' => "دور {$role}", 'name_en' => "Role {$role}", 'is_system' => false]);
            foreach (DB::table('permissions')->whereIn('key', $permissions)->pluck('id') as $p) {
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $p]);
            }
        }
        if (! User::where('username', $username)->exists()) {
            $u = User::create(['username' => $username, 'email' => "{$username}@credit.example", 'password_hash' => Hash::make((string) env('SEED_PASSWORD')), 'name_ar' => "مستخدم {$username}",
                'name_en' => "User {$username}", 'initials' => 'CU', 'active' => true, 'must_change_password' => false]);
            DB::table('user_roles')->insert(['user_id' => $u->id, 'role_id' => $roleId]);
        }

        return $username;
    }

    /** A real one-page PDF, base64. */
    protected static function pdf(): string
    {
        return base64_encode("%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 10 10]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
    }

    protected function auditOf(string $requestId): string
    {
        return (string) DB::table('audit_logs')->where('entity_id', $requestId)->get(['action', 'old_value', 'new_value'])->toJson(JSON_UNESCAPED_UNICODE);
    }
}
