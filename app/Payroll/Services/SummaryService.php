<?php

namespace App\Payroll\Services;

use App\Finance\Models\BankAccount;
use App\Hr\Models\Department;
use App\Hr\Models\Employee;
use App\Payroll\Models\Adjustment;
use App\Payroll\Models\Attendance;
use App\Payroll\Models\AttendancePolicy;
use App\Payroll\Models\Component;
use App\Payroll\Models\LeaveType;
use App\Payroll\Models\PayAccount;
use App\Payroll\Models\Payslip;
use App\Payroll\Models\Run;
use App\Payroll\Models\Schedule;
use App\Payroll\Models\Sheet;
use App\Payroll\Models\Structure;
use App\Payroll\Support\Access;
use App\Payroll\Support\Owned;
use App\Payroll\Support\Shape;
use App\Support\AuthUser;
use Illuminate\Support\Carbon;

/**
 * The top of the page and what its forms pick from — each part only for who may see it: a person without any of the
 * section's permissions still gets their own corner (their leave, their clock, their payslips) and nothing else.
 */
class SummaryService
{
    public function __construct(private readonly AttendanceService $attendance, private readonly LeaveService $leaves, private readonly LeaveLedger $ledger) {}

    public function summary(AuthUser $user): array
    {
        $mine = Access::employeeId($user);
        $year = (int) Carbon::today()->format('Y');
        $out = [
            'following' => Owned::following(),
            'permissions' => ['payrollManage' => $user->can('payroll.manage'), 'payrollApprove' => $user->can('payroll.approve'), 'leaveRequest' => $user->can('leave.request'),
                'leaveApprove' => $user->can('leave.approve'), 'attendanceManage' => $user->can('attendance.manage'), 'managesTeam' => Access::team($user) !== []],
            'me' => $mine ? [
                'linked' => true, 'employee' => Shape::employee(Employee::find($mine)), 'attendance' => $this->attendance->mine($user), 'year' => $year, 'balances' => $this->ledger->balances($mine, $year),
                'payslips' => Payslip::where('employee_id', $mine)->whereIn('state', Payslip::VISIBLE_TO_EMPLOYEE)->count(),
                'lastPayslip' => ($last = Payslip::where('employee_id', $mine)->whereIn('state', Payslip::VISIBLE_TO_EMPLOYEE)->orderByDesc('period')->orderByDesc('id')->first()) ? ['id' => $last->id, 'number' => $last->number, 'period' => $last->period, 'net' => $last->net] : null,
            ] : ['linked' => false],
            'leave' => $this->leaves->counts($user),
        ];
        if (Access::seesAllAttendance($user)) {
            $today = Carbon::now((string) config('app.business_timezone', 'Asia/Riyadh'))->format('Y-m-d');
            $sheets = Sheet::query()->when(Owned::following(), fn ($q) => $q->whereNull('source_system'))->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');
            $out['attendance'] = ['date' => $today, 'checkedIn' => Attendance::where('status', 'ok')->whereDate('work_date', $today)->distinct()->count('employee_id'),
                'stillIn' => Attendance::where('status', 'ok')->whereDate('work_date', $today)->whereNull('check_out')->count(),
                'missingCheckOut' => Attendance::where('status', 'ok')->whereDate('work_date', '<', $today)->whereNull('check_out')->count(),
                'sheets' => ['draft' => (int) ($sheets['draft'] ?? 0), 'confirmed' => (int) ($sheets['confirmed'] ?? 0), 'approved' => (int) ($sheets['approved'] ?? 0)]];
        }
        if (Access::managesPay($user)) {
            $runs = Run::query()->whereNull('source_system')->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');
            $last = Run::query()->whereIn('state', Run::LIVE)->orderByDesc('period')->orderByDesc('created_at')->first();
            $out['payroll'] = ['runs' => ['draft' => (int) ($runs['draft'] ?? 0), 'review' => (int) ($runs['review'] ?? 0), 'approved' => (int) ($runs['approved'] ?? 0), 'posted' => (int) ($runs['posted'] ?? 0), 'paid' => (int) ($runs['paid'] ?? 0)],
                'lastRun' => $last ? ['id' => $last->id, 'number' => $last->number, 'period' => $last->period, 'state' => $last->state, 'net' => $last->net, 'slipCount' => $last->slip_count, 'source' => $last->source_system] : null,
                'pendingAdjustments' => Adjustment::where('state', 'pending')->count(),
                'unmappedAccounts' => count(RunPosting::KEYS) - PayAccount::whereIn('key', array_keys(RunPosting::KEYS))->whereNotNull('account_code')->count()];
        }

        return $out;
    }

    public function lookups(AuthUser $user): array
    {
        $mine = Access::employeeId($user);
        $everyone = Access::seesAllLeave($user) || Access::seesAllAttendance($user);
        // the people a form may name: everyone for an officer, the team for a manager, oneself otherwise
        $employees = Employee::query()->where('status', 'active')->when(! $everyone, fn ($q) => $q->whereIn('id', array_values(array_filter(array_merge([$mine], Access::team($user)))) ?: ['-']))
            ->orderBy('name_ar')->orderBy('id')->limit(2000)->get(['id', 'code', 'name_ar', 'name_en', 'department_id', 'status']);
        $out = [
            'employees' => $employees->map(fn (Employee $e) => Shape::employee($e) + ['departmentId' => $e->department_id])->all(),
            'departments' => Department::where('active', true)->orderBy('name_ar')->get()->map(fn (Department $d) => Shape::department($d))->all(),
            'leaveTypes' => LeaveType::where('active', true)->orderBy('sequence')->orderBy('name_ar')->get()->map(fn (LeaveType $t) => ConfigService::leaveTypeRow($t))->all(),
            'year' => (int) Carbon::today()->format('Y'),
        ];
        if ($everyone) {
            $out['schedules'] = Schedule::where('active', true)->orderByDesc('is_default')->orderBy('name_ar')->get()->map(fn (Schedule $s) => Shape::named($s, ['isDefault' => 'is_default']))->all();
            $out['policies'] = AttendancePolicy::where('active', true)->orderByDesc('is_default')->orderBy('name_ar')->get()->map(fn (AttendancePolicy $p) => Shape::named($p, ['isDefault' => 'is_default', 'incomplete' => 'incomplete']))->all();
        }
        if (Access::managesPay($user)) {
            $out['structures'] = Structure::where('active', true)->orderByDesc('is_default')->orderBy('name_ar')->get()->map(fn (Structure $s) => Shape::named($s, ['isDefault' => 'is_default', 'gosiScheme' => 'gosi_scheme']))->all();
            $out['inputComponents'] = Component::where('active', true)->where('calc', 'input')->orderBy('sequence')->get()->map(fn (Component $c) => Shape::named($c, ['category' => 'category']))->all();
            $out['bankAccounts'] = BankAccount::where('active', true)->orderBy('code')->get(['id', 'code', 'name_ar', 'name_en', 'kind'])
                ->map(fn (BankAccount $b) => ['code' => $b->code, 'nameAr' => $b->name_ar, 'nameEn' => $b->name_en, 'kind' => $b->kind])->all();
        }

        return $out;
    }
}
