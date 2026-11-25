<?php

use App\Hr\Http\AnyPermission;
use App\Payroll\Http\AttendanceController;
use App\Payroll\Http\ConfigController;
use App\Payroll\Http\LeaveController;
use App\Payroll\Http\PayrollController;
use App\Payroll\Http\PaySetupController;
use App\Payroll\Http\RunsController;
use App\Payroll\Http\SheetsController;
use App\Payroll\Http\SkillsController;
use Illuminate\Support\Facades\Route;

/*
 * «الرواتب والإجازات والحضور» — /api/payroll (docs/odoo/WAVE3.md, P6).
 *
 *   payroll.manage     runs, payslips, adjustments, the whole configuration, the bank transfer listing
 *   payroll.approve    reads everything the payroll officer reads; approves, returns and reverses a run
 *   leave.request      asks for one's own time off
 *   leave.approve      decides everyone's time off, grants and corrects balances
 *   attendance.manage  enters, corrects and voids presences; generates, corrects and confirms attendance sheets
 *
 * EVERYTHING HERE IS PEOPLE'S PAY AND PERSONAL DATA. A route without `perm:` is a person's own corner: the service
 * answers only what belongs to the caller (through the employee record HR linked to their login) or to the people who
 * report to them, and refuses the rest with 403 PAYROLL_PRIVATE. `perm:a,b` = all of them; AnyPermission::of(a, b) =
 * at least one. The steps that touch the books ask for the finance permission that guards the same act in the finance
 * section: `gl.post` to post and to pay a run (and `gl.approve`, checked in the service, to reverse a posted one).
 */
Route::prefix('payroll')->group(function () {
    $pay = AnyPermission::of('payroll.manage', 'payroll.approve');
    $officers = AnyPermission::of('payroll.manage', 'payroll.approve', 'leave.approve', 'attendance.manage');
    $timekeepers = AnyPermission::of('attendance.manage', 'payroll.manage', 'payroll.approve');
    $leaveOfficers = AnyPermission::of('leave.approve', 'payroll.manage');
    $leavePeople = AnyPermission::of('leave.request', 'leave.approve', 'payroll.manage');

    // ── the top of the page and what its forms pick from (each caller gets their part)
    Route::get('summary', [PayrollController::class, 'summary']);
    Route::get('lookups', [PayrollController::class, 'lookups']);

    // ── time off: one's own, one's team's, or everyone's for the officers (LeaveService decides)
    Route::get('leaves', [LeaveController::class, 'index']);
    Route::get('leaves/calendar', [LeaveController::class, 'calendar']);
    Route::get('leaves/{id}', [LeaveController::class, 'show']);
    Route::get('leave-balances', [LeaveController::class, 'balances']);
    Route::get('leave-entries', [LeaveController::class, 'entries']);
    Route::get('leave-ledger/reconcile', [LeaveController::class, 'reconcile'])->middleware(AnyPermission::of('leave.approve', 'payroll.manage', 'payroll.approve'));
    Route::middleware($leavePeople)->group(function () {
        Route::post('leaves', [LeaveController::class, 'store']);
        Route::patch('leaves/{id}', [LeaveController::class, 'update']);
        Route::post('leaves/{id}/submit', [LeaveController::class, 'submit']);
        Route::post('leaves/{id}/withdraw', [LeaveController::class, 'withdraw']);
        Route::post('leaves/{id}/cancel', [LeaveController::class, 'cancel']);
        // a manager decides the leave of the people who report to him; a holder of leave.approve, everyone's
        Route::post('leaves/{id}/approve', [LeaveController::class, 'approve']);
        Route::post('leaves/{id}/refuse', [LeaveController::class, 'refuse']);
    });
    Route::middleware($leaveOfficers)->group(function () {
        Route::post('leave-allocations', [LeaveController::class, 'allocate']);
        Route::post('leave-allocations/yearly', [LeaveController::class, 'grantYear']);
    });

    // ── attendance: one's own clock and records; everyone's for the timekeepers
    Route::get('attendance', [AttendanceController::class, 'index']);
    Route::get('attendance/me', [AttendanceController::class, 'mine']);
    Route::get('attendance/daily', [AttendanceController::class, 'daily'])->middleware($timekeepers);
    Route::get('attendance/{id}', [AttendanceController::class, 'show']);
    Route::post('attendance/check-in', [AttendanceController::class, 'checkIn']);
    Route::post('attendance/check-out', [AttendanceController::class, 'checkOut']);
    Route::middleware('perm:attendance.manage')->group(function () {
        Route::post('attendance', [AttendanceController::class, 'store']);
        Route::patch('attendance/{id}', [AttendanceController::class, 'update']);
        Route::post('attendance/{id}/void', [AttendanceController::class, 'void']);
    });

    // ── attendance sheets
    Route::get('sheets', [SheetsController::class, 'index']);
    Route::get('sheets/{id}', [SheetsController::class, 'show']);
    Route::middleware('perm:attendance.manage')->group(function () {
        Route::post('sheets', [SheetsController::class, 'store']);
        Route::post('sheets/{id}/recompute', [SheetsController::class, 'recompute']);
        Route::post('sheets/{id}/confirm', [SheetsController::class, 'confirm']);
        Route::patch('sheets/{id}/lines/{line}', [SheetsController::class, 'correctLine']);
        Route::post('sheet-batches', [SheetsController::class, 'storeBatch']);
        Route::post('sheet-batches/{id}/generate', [SheetsController::class, 'generateBatch']);
        Route::post('sheet-batches/{id}/submit', [SheetsController::class, 'submitBatch']);
    });
    Route::post('sheets/{id}/approve', [SheetsController::class, 'approve'])->middleware($pay);
    Route::post('sheet-batches/{id}/close', [SheetsController::class, 'closeBatch'])->middleware($pay);
    Route::middleware($timekeepers)->group(function () {
        Route::post('sheets/{id}/reopen', [SheetsController::class, 'reopen']);
        Route::get('sheet-batches', [SheetsController::class, 'batches']);
        Route::get('sheet-batches/{id}', [SheetsController::class, 'batch']);
    });

    // ── what working time is made of
    Route::middleware($officers)->group(function () {
        Route::get('schedules', [ConfigController::class, 'schedules']);
        Route::get('holidays', [ConfigController::class, 'holidays']);
        Route::get('leave-types', [ConfigController::class, 'leaveTypes']);
        Route::get('policies', [ConfigController::class, 'policies']);
    });
    Route::middleware('perm:payroll.manage')->group(function () {
        Route::post('schedules', [ConfigController::class, 'storeSchedule']);
        Route::patch('schedules/{id}', [ConfigController::class, 'updateSchedule']);
        Route::post('holidays', [ConfigController::class, 'storeHoliday']);
        Route::patch('holidays/{id}', [ConfigController::class, 'updateHoliday']);
        Route::post('leave-types', [ConfigController::class, 'storeLeaveType']);
        Route::patch('leave-types/{id}', [ConfigController::class, 'updateLeaveType']);
        Route::post('policies', [ConfigController::class, 'storePolicy']);
        Route::patch('policies/{id}', [ConfigController::class, 'updatePolicy']);
    });

    // ── what pay is made of, runs, adjustments: the payroll people
    Route::middleware($pay)->group(function () {
        Route::get('components', [PaySetupController::class, 'components']);
        Route::get('structures', [PaySetupController::class, 'structures']);
        Route::get('structures/{id}', [PaySetupController::class, 'structure']);
        Route::get('gosi-rates', [PaySetupController::class, 'gosiRates']);
        Route::get('parameters', [PaySetupController::class, 'parameters']);
        Route::get('accounts', [PaySetupController::class, 'accounts']);
        Route::get('profiles', [PaySetupController::class, 'profiles']);
        Route::get('adjustments', [RunsController::class, 'adjustments']);
        Route::get('runs', [RunsController::class, 'index']);
        Route::get('runs/{id}', [RunsController::class, 'show']);
        Route::get('runs/{id}/journal', [RunsController::class, 'journal']);
        Route::post('runs/{id}/return', [RunsController::class, 'sendBack']);
    });
    Route::middleware('perm:payroll.manage')->group(function () {
        Route::post('components', [PaySetupController::class, 'storeComponent']);
        Route::patch('components/{id}', [PaySetupController::class, 'updateComponent']);
        Route::post('structures', [PaySetupController::class, 'storeStructure']);
        Route::patch('structures/{id}', [PaySetupController::class, 'updateStructure']);
        Route::post('gosi-rates', [PaySetupController::class, 'storeGosiRate']);
        Route::patch('gosi-rates/{id}', [PaySetupController::class, 'updateGosiRate']);
        Route::delete('gosi-rates/{id}', [PaySetupController::class, 'destroyGosiRate']);
        Route::post('parameters', [PaySetupController::class, 'storeParameter']);
        Route::delete('parameters/{id}', [PaySetupController::class, 'destroyParameter']);
        Route::put('accounts', [PaySetupController::class, 'saveAccounts']);
        Route::put('profiles/{employee}', [PaySetupController::class, 'saveProfile']);
        Route::post('adjustments', [RunsController::class, 'storeAdjustment']);
        Route::post('adjustments/{id}/cancel', [RunsController::class, 'cancelAdjustment']);
        Route::post('runs', [RunsController::class, 'store']);
        Route::post('runs/{id}/compute', [RunsController::class, 'compute']);
        Route::post('runs/{id}/submit', [RunsController::class, 'submit']);
        Route::post('runs/{id}/cancel', [RunsController::class, 'cancel']);
        // the one place bank account numbers leave the server
        Route::get('runs/{id}/transfer', [RunsController::class, 'transfer']);
        Route::get('runs/{id}/transfer.csv', [RunsController::class, 'transferCsv']);
    });
    Route::middleware('perm:payroll.approve')->group(function () {
        Route::post('runs/{id}/approve', [RunsController::class, 'approve']);
        Route::post('runs/{id}/reverse', [RunsController::class, 'reverse']);
    });
    Route::middleware('perm:payroll.manage,gl.post')->group(function () {
        Route::post('runs/{id}/post', [RunsController::class, 'post']);
        Route::post('runs/{id}/pay', [RunsController::class, 'pay']);
        Route::post('runs/{id}/unpay', [RunsController::class, 'unpay']);
    });

    // ── payslips: one's own once approved; all of them for the payroll people (PayslipsService decides)
    Route::get('payslips', [RunsController::class, 'payslips']);
    Route::get('payslips/{id}', [RunsController::class, 'payslip']);

    // ── skills and résumé: the catalogue is open, a person's skills are theirs and the payroll people's (SkillsService decides)
    Route::get('skills/catalog', [SkillsController::class, 'catalog']);
    Route::get('skills/employees', [SkillsController::class, 'employees'])->middleware($pay);
    Route::get('skills/employees/{id}', [SkillsController::class, 'employee']);
    Route::post('skills/employees/{id}/skills', [SkillsController::class, 'setSkill']);
    Route::delete('skills/employees/{id}/skills/{skill}', [SkillsController::class, 'removeSkill']);
    Route::post('skills/employees/{id}/resume', [SkillsController::class, 'storeResume']);
    Route::patch('skills/employees/{id}/resume/{line}', [SkillsController::class, 'updateResume']);
    Route::delete('skills/employees/{id}/resume/{line}', [SkillsController::class, 'removeResume']);
    Route::middleware('perm:payroll.manage')->group(function () {
        Route::post('skills/{kind}', [SkillsController::class, 'storeEntry'])->whereIn('kind', ['types', 'skills', 'levels', 'resume-types']);
        Route::patch('skills/{kind}/{id}', [SkillsController::class, 'updateEntry'])->whereIn('kind', ['types', 'skills', 'levels', 'resume-types']);
    });
});
