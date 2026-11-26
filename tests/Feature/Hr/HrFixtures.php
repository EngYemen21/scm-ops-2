<?php

namespace Tests\Feature\Hr;

use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * What the tests of the «الموظفون والمصروفات» section share. Every value here is invented: no name, number or amount
 * of a real person appears in a test.
 */
trait HrFixtures
{
    /** Nothing in these tests may reach another system: a stray HTTP call fails the test instead of leaving the machine. */
    protected function sealNetwork(): void
    {
        Http::preventStrayRequests();
    }

    /** The section follows Odoo (records that came from Odoo are Odoo's) or is handed over to OPS. */
    protected function followOdoo(bool $on): void
    {
        config(['odoo.enabled' => $on, 'odoo.project.hr' => $on]);
    }

    /**
     * A signed-in user that holds exactly the given permissions (the seeded roles do not separate hr.manage from
     * hr.private, and the difference between them is what the section is about). @return string the username
     */
    protected function userWith(string $username, array $permissions, ?string $email = null): string
    {
        if (! DB::table('users')->where('username', $username)->exists()) {
            $role = (string) Str::ulid();
            DB::table('roles')->insert(['id' => $role, 'key' => "t-{$username}", 'name_ar' => "دور اختبار {$username}", 'name_en' => "Test role {$username}", 'is_system' => false]);
            foreach (DB::table('permissions')->whereIn('key', $permissions)->pluck('id') as $permission) {
                DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
            }
            $user = (string) Str::ulid();
            DB::table('users')->insert(['id' => $user, 'username' => $username, 'email' => $email, 'password_hash' => Hash::make((string) env('SEED_PASSWORD')), 'name_ar' => "مستخدم {$username}",
                'name_en' => "User {$username}", 'initials' => 'T', 'active' => true, 'must_change_password' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $user, 'role_id' => $role]);
        }

        return $username;
    }

    protected function userId(string $username): string
    {
        return (string) User::where('username', $username)->value('id');
    }

    /** An employee created through the API by the administrator. @return array the employee's card */
    protected function makeEmployee(array $values = []): array
    {
        return $this->expectOk($this->postAs('admin', '/api/hr/employees', $values + ['nameAr' => 'موظف اختبار '.self::uid(), 'nameEn' => 'Test employee']));
    }

    /** A small real PDF, as the client sends a file. */
    protected static function pdfFile(string $name = 'paper.pdf'): array
    {
        return ['fileName' => $name, 'mime' => 'application/pdf', 'base64' => base64_encode("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n")];
    }

    /** A real 1×1 PNG as a data URL. */
    protected static function pngFile(string $name = 'receipt.png'): array
    {
        return ['fileName' => $name, 'mime' => 'image/png', 'base64' => self::proofImage()];
    }

    // ───────────── a small mirror of Odoo, written straight into the copy's tables (Odoo is never called) ─────────────

    protected function emptyMirror(): void
    {
        // the copy of the real Odoo lives in the development database: these rows are only ever written in a test database
        Assert::assertStringContainsString('test', (string) DB::connection()->getDatabaseName(), 'the small mirror is built in a test database only');
        foreach (['odoo_changes', 'odoo_records', 'odoo_models'] as $table) {
            DB::table($table)->delete();
        }
        DB::table('int_external_refs')->where('system', 'odoo')->where('entity', 'like', 'hr\_%')->delete();
    }

    /** Registers a model of the copy as fully copied. */
    protected function mirrorModel(string $model, array $fields = []): void
    {
        OdooModel::updateOrCreate(['model' => $model], ['label' => $model, 'status' => 'active', 'full_state' => 'done', 'has_write_date' => true, 'has_active' => isset($fields['active']),
            'fields' => $fields + ['display_name' => 'char'], 'priority' => 1000, 'page_size' => 500]);
    }

    /** One record of the copy, as the mirror would hold it; `$change` also puts it on the feed (created | updated | archived | deleted). */
    protected function mirrorRecord(string $model, int $id, array $data, ?string $change = null, bool $deleted = false): void
    {
        $now = now();
        $row = OdooRecord::where('model', $model)->where('odoo_id', $id)->first();
        $values = ['display_name' => is_string($data['name'] ?? null) ? mb_substr($data['name'], 0, 250) : null, 'write_date' => $now->format('Y-m-d H:i:s'), 'active' => array_key_exists('active', $data) ? (bool) $data['active'] : null,
            'data' => $data, 'hash' => sha1(json_encode($data)), 'changed_at' => $now, 'deleted_at' => $deleted ? $now : null];
        $row ? $row->update($values + ['version' => $row->version + 1]) : OdooRecord::create(['model' => $model, 'odoo_id' => $id, 'first_seen_at' => $now, 'version' => 1] + $values);
        if ($change) {
            OdooChange::create(['model' => $model, 'odoo_id' => $id, 'kind' => $change, 'seen_at' => $now]);
        }
    }
}
