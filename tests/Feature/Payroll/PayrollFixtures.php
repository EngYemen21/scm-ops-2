<?php

namespace Tests\Feature\Payroll;

use App\Hr\Models\Employee;
use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Models\User;
use App\Payroll\Models\Attendance;
use App\Payroll\Models\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * What the tests of «الرواتب والإجازات والحضور» share. Every person, wage and account number here is invented.
 *
 * The suite's clock stands at 2026-09-18 (Tests\ApiTestCase): August 2026 is the last whole month — the month the
 * payroll stories pay. The seeded company schedule is Sunday–Thursday 08:00–12:00 and 13:00–17:00 (Asia/Riyadh).
 */
trait PayrollFixtures
{
    private static int $payrollSeq = 0;

    /** Nothing in these tests may reach another system. */
    protected function sealNetwork(): void
    {
        Http::preventStrayRequests();
    }

    /** The section follows Odoo (records that came from Odoo are Odoo's) or is OPS's own. */
    protected function followOdoo(bool $on): void
    {
        config(['odoo.enabled' => $on, 'odoo.project.payroll' => $on, 'odoo.project.hr' => false]);
    }

    protected static function tag(): string
    {
        return self::uid().'-'.(++self::$payrollSeq);
    }

    /** A signed-in user that holds exactly the given permissions. @return string the username */
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

    /** A department of its own, so a story's lists and runs hold only its own people. @return string its id */
    protected function newDepartment(string $label = 'قسم اختبار الرواتب'): string
    {
        return $this->expectOk($this->postAs('admin', '/api/hr/departments', ['nameAr' => $label.' '.self::tag()]))['id'];
    }

    /**
     * An employee made through People & expenses (the section payroll is built on), optionally linked to a login and
     * placed under a manager. @return array the employee's card
     */
    protected function newEmployee(array $values = []): array
    {
        return $this->expectOk($this->postAs('admin', '/api/hr/employees', $values + ['nameAr' => 'موظف اختبار '.self::tag(), 'nameEn' => 'Test employee']));
    }

    /** A contract in force from a date, with invented pay. @return array the contract */
    protected function newContract(string $employeeId, string $start, array $pay = [], ?string $end = null): array
    {
        $c = $this->expectOk($this->postAs('admin', '/api/hr/contracts', ['employee' => $employeeId, 'startDate' => $start, 'endDate' => $end] + $pay + ['wage' => 6000]));
        $this->expectOk($this->postAs('admin', "/api/hr/contracts/{$c['id']}/start"));
        if ($end !== null && $end < now()->toDateString()) {
            $this->expectOk($this->postAs('admin', "/api/hr/contracts/{$c['id']}/end", ['date' => $end]));
        }

        return $c;
    }

    /** A presence written straight into the table (a past day cannot be checked in through the clock). Times are on the Riyadh clock. */
    protected function presence(string $employeeId, string $date, string $in, ?string $out): Attendance
    {
        $tz = 'Asia/Riyadh';
        $from = Carbon::parse("{$date} {$in}", $tz)->utc();
        $to = $out === null ? null : Carbon::parse("{$date} {$out}", $tz)->utc();

        return Attendance::create(['employee_id' => $employeeId, 'work_date' => $date, 'check_in' => $from, 'check_out' => $to, 'worked_minutes' => $to ? intdiv($to->getTimestamp() - $from->getTimestamp(), 60) : 0,
            'in_mode' => 'import', 'out_mode' => $to ? 'import' : null, 'status' => 'ok', 'created_by' => 'test']);
    }

    /**
     * A small world of its own for one story — never the company default, never another story's rows (the whole suite
     * shares one database at integration): a department, a Sunday–Thursday 08:00–12:00 + 13:00–17:00 schedule on the
     * Riyadh clock, a policy (15 minutes of grace, lateness and early leave minute for minute, an absent day = the day,
     * overtime ×1.5 on every kind of day), a pay structure under the social-insurance scheme `saudi`, made through the
     * API by a payroll officer.
     *
     * @return array{department:string, schedule:string, policy:string, structure:string}
     */
    protected function payrollWorld(string $officer = 'admin'): array
    {
        $tag = self::tag();
        $days = [];
        foreach ([6, 0, 1, 2, 3] as $weekday) {
            $days[] = ['weekday' => $weekday, 'kind' => 'work', 'from' => '08:00', 'to' => '12:00'];
            $days[] = ['weekday' => $weekday, 'kind' => 'break', 'from' => '12:00', 'to' => '13:00'];
            $days[] = ['weekday' => $weekday, 'kind' => 'work', 'from' => '13:00', 'to' => '17:00'];
        }
        $schedule = $this->expectOk($this->postAs($officer, '/api/payroll/schedules', ['nameAr' => "دوام اختبار {$tag}", 'timezone' => 'Asia/Riyadh', 'hoursPerDay' => 8, 'days' => $days]));
        $policy = $this->expectOk($this->postAs($officer, '/api/payroll/policies', ['nameAr' => "سياسة اختبار {$tag}", 'lateGraceMinutes' => 15, 'earlyGraceMinutes' => 15,
            'late' => [['type' => 'rate', 'thresholdMinutes' => 0, 'rate' => 1]], 'early' => [['type' => 'rate', 'thresholdMinutes' => 0, 'rate' => 1]],
            'absence' => [['counter' => 1, 'rate' => 1]],
            'overtime' => [['dayType' => 'workday', 'activeAfterMinutes' => 0, 'rate' => 1.5], ['dayType' => 'weekend', 'activeAfterMinutes' => 0, 'rate' => 1.5], ['dayType' => 'holiday', 'activeAfterMinutes' => 0, 'rate' => 1.5]]]));
        $structure = $this->expectOk($this->postAs($officer, '/api/payroll/structures', ['code' => 'T'.preg_replace('/\D/', '', $tag), 'nameAr' => "هيكل اختبار {$tag}", 'gosiScheme' => 'saudi']));

        return ['department' => $this->newDepartment(), 'schedule' => $schedule['id'], 'policy' => $policy['id'], 'structure' => $structure['id']];
    }

    /**
     * A person of a world: employee (optionally linked to a login, under a manager), a running contract with invented
     * pay, and a pay profile on the world's schedule, policy and structure. @return array the employee's card + `contract`
     */
    protected function staff(array $world, array $employee = [], array $pay = [], string $start = '2026-01-01', ?string $end = null): array
    {
        $e = $this->newEmployee($employee + ['departmentId' => $world['department']]);
        $c = $this->newContract($e['id'], $start, $pay + ['wage' => 6000, 'housingAllowance' => 1500, 'transportAllowance' => 500, 'departmentId' => $world['department']], $end);
        $this->expectOk($this->putAs('admin', "/api/payroll/profiles/{$e['id']}", ['structureId' => $world['structure'], 'scheduleId' => $world['schedule'], 'policyId' => $world['policy'], 'paymentMethod' => 'bank']));

        return $e + ['contract' => $c];
    }

    /** A kind of time off of the story's own. @return array the type row */
    protected function leaveType(array $values = []): array
    {
        $code = 'T'.preg_replace('/\D/', '', self::tag());

        return $this->expectOk($this->postAs('admin', '/api/payroll/leave-types', $values + ['code' => $code, 'nameAr' => "نوع اختبار {$code}", 'paid' => true, 'requiresAllocation' => false, 'approval' => 'manager']));
    }

    /** A bank account of the story's own in the finance module. @return string its code */
    protected function bankAccount(): string
    {
        $code = 'PB'.substr(preg_replace('/\D/', '', self::tag()), -8);
        $this->expectOk($this->postAs('admin', '/api/finance/banks', ['code' => $code, 'nameAr' => "بنك رواتب اختبار {$code}", 'nameEn' => "Payroll test bank {$code}", 'kind' => 'bank']));

        return $code;
    }

    protected function defaultSchedule(): Schedule
    {
        return Schedule::where('is_default', true)->firstOrFail();
    }

    protected function employeeModel(string $id): Employee
    {
        return Employee::findOrFail($id);
    }

    // ───────────── a small mirror of Odoo, written straight into the copy's tables (Odoo is never called) ─────────────

    protected function emptyMirror(): void
    {
        // the copy of the real Odoo lives in the development database: these rows are only ever written in a test database
        Assert::assertStringContainsString('test', (string) DB::connection()->getDatabaseName(), 'the small mirror is built in a test database only');
        foreach (['odoo_changes', 'odoo_records', 'odoo_models'] as $table) {
            DB::table($table)->delete();
        }
        if (Schema::hasTable('odoo_projections')) {
            DB::table('odoo_projections')->where('key', 'payroll')->delete();
        }
    }

    /** Registers a model of the copy as fully copied. */
    protected function mirrorModel(string $model, array $fields = []): void
    {
        OdooModel::updateOrCreate(['model' => $model], ['label' => $model, 'status' => 'active', 'full_state' => 'done', 'has_write_date' => true, 'has_active' => isset($fields['active']),
            'fields' => $fields + ['display_name' => 'char'], 'priority' => 1000, 'page_size' => 500]);
    }

    /** One record of the copy, as the mirror would hold it; `$change` also puts it on the feed (created | updated | deleted). */
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
