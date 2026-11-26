<?php

namespace Tests\Feature\Payroll;

use App\Payroll\Models\AttendanceChange;
use Tests\ApiTestCase;

/**
 * Attendance: the employee's own clock, the timekeeper's hand (entries, corrections that keep the original and say who
 * changed what and why, voids), the period sheet built from the presences and the schedule through the policy, and
 * batches per department. Week of Sunday 2 – Saturday 8 August 2026; schedule Sun–Thu 08–12 + 13–17 (Riyadh); policy
 * 15 minutes of grace, minute for minute, an absent day = the day, overtime ×1.5. Every person here is invented.
 */
class PayrollAttendanceTest extends ApiTestCase
{
    use PayrollFixtures;

    private static array $s = [];

    private function world(): array
    {
        if (self::$s) {
            return self::$s;
        }
        $w = $this->payrollWorld();
        $t = self::tag();
        $keeper = $this->userWith("pak{$t}", ['attendance.manage']);
        $empUser = $this->userWith("pae{$t}", ['leave.request']);
        $otherUser = $this->userWith("pao{$t}", ['leave.request']);
        $employee = $this->staff($w, ['userId' => $empUser]);
        $other = $this->staff($w, ['userId' => $otherUser]);

        return self::$s = compact('w', 'keeper', 'empUser', 'otherUser', 'employee', 'other');
    }

    private static function line(array $sheet, string $date): array
    {
        return array_values(array_filter($sheet['lines'], fn ($l) => $l['date'] === $date))[0];
    }

    public function test_the_own_clock(): void
    {
        $s = $this->world();
        $me = $this->expectOk($this->getAs($s['empUser'], '/api/payroll/attendance/me'));
        $this->assertSame([true, false, false], [$me['linked'], $me['in'], $me['working']], 'Friday is not a working day of the schedule');
        $me = $this->expectOk($this->postAs($s['empUser'], '/api/payroll/attendance/check-in'));
        $this->assertTrue($me['in']);
        $this->expectRejected($this->postAs($s['empUser'], '/api/payroll/attendance/check-in'), 'ATTENDANCE_ALREADY_IN', [422]);
        $me = $this->expectOk($this->postAs($s['empUser'], '/api/payroll/attendance/check-out'));
        $this->assertFalse($me['in']);
        $this->expectRejected($this->postAs($s['empUser'], '/api/payroll/attendance/check-out'), 'ATTENDANCE_NOT_IN', [422]);
        $mine = $this->expectOk($this->getAs($s['empUser'], '/api/payroll/attendance?status=all'));
        $this->assertSame(1, $mine['total']);
        $this->assertSame(['self', 'self'], [$mine['items'][0]['inMode'], $mine['items'][0]['outMode']]);
        // nobody else's records, and no officer's lists
        $this->assertSame(0, $this->expectOk($this->getAs($s['otherUser'], '/api/payroll/attendance?status=all'))['total']);
        $this->expectRejected($this->getAs($s['otherUser'], "/api/payroll/attendance/{$mine['items'][0]['id']}"), 'PAYROLL_PRIVATE', [403]);
        $this->expectRejected($this->getAs($s['empUser'], '/api/payroll/attendance?scope=all'), 'FORBIDDEN', [403]);
        $this->assertSame(403, $this->getAs($s['empUser'], '/api/payroll/attendance/daily')->getStatusCode());
        $this->assertSame(403, $this->postAs($s['empUser'], '/api/payroll/attendance', ['employee' => $s['employee']['id'], 'checkIn' => '2026-08-01 08:00', 'reason' => 'محاولة من غير مخوّل'])->getStatusCode());
    }

    public function test_the_period_sheet_from_presences_schedule_and_policy(): void
    {
        $s = $this->world();
        $e = $s['employee']['id'];
        $this->expectOk($this->postAs('admin', '/api/payroll/holidays', ['nameAr' => 'عطلة حضور '.self::tag(), 'dateFrom' => '2026-08-06', 'scope' => 'department', 'scopeIds' => [$s['w']['department']]]));
        $this->presence($e, '2026-08-02', '08:40', '17:00');   // 40 late
        $short = $this->presence($e, '2026-08-03', '08:00', '16:00');   // 60 early
        $this->presence($e, '2026-08-04', '08:10', '18:30');   // 10 late (within grace), 90 overtime → 135
        // Wednesday 5: nobody came — an absence
        $this->presence($e, '2026-08-06', '09:00', '11:00');   // a holiday: every minute is overtime → 180
        $this->presence($e, '2026-08-08', '10:00', '12:00');   // Saturday: weekend overtime → 180

        $this->expectRejected($this->postAs($s['empUser'], '/api/payroll/sheets', ['employee' => $e, 'dateFrom' => '2026-08-02', 'dateTo' => '2026-08-08']), null, [403]);
        $sheet = $this->expectOk($this->postAs($s['keeper'], '/api/payroll/sheets', ['employee' => $e, 'dateFrom' => '2026-08-02', 'dateTo' => '2026-08-08']));
        $this->assertSame(['draft', '2026-08', 7], [$sheet['state'], $sheet['period'], count($sheet['lines'])]);
        $t = $sheet['totals'];
        $this->assertSame([1, 50, 40], [$t['lateCount'], $t['lateActualMinutes'], $t['lateMinutes']]);
        $this->assertSame([60, 60], [$t['earlyActualMinutes'], $t['earlyMinutes']]);
        $this->assertSame([330, 495, 3], [$t['overtimeActualMinutes'], $t['overtimeMinutes'], $t['overtimeCount']]);
        $this->assertSame([1, 480, 1, 2], [$t['absenceCount'], $t['absenceMinutes'], $t['holidayDays'], $t['weekendDays']]);
        $this->assertSame(['08:00', '08:40', 'absence', 'holiday', 'weekend'], [self::line($sheet, '2026-08-02')['plannedIn'], self::line($sheet, '2026-08-02')['actualIn'],
            self::line($sheet, '2026-08-05')['status'], self::line($sheet, '2026-08-06')['status'], self::line($sheet, '2026-08-07')['status']]);
        $this->expectRejected($this->postAs($s['keeper'], '/api/payroll/sheets', ['employee' => $e, 'dateFrom' => '2026-08-08', 'dateTo' => '2026-08-10']), 'SHEET_OVERLAP', [422]);

        // a correction of a line keeps what it replaced, who and why — and a recompute keeps the correction
        $line = self::line($sheet, '2026-08-02');
        $this->expectRejected($this->patchAs($s['keeper'], "/api/payroll/sheets/{$sheet['id']}/lines/{$line['id']}", ['late' => 0]), 'INVALID_INPUT', [400]);
        $sheet = $this->expectOk($this->patchAs($s['keeper'], "/api/payroll/sheets/{$sheet['id']}/lines/{$line['id']}", ['late' => 0, 'note' => 'تأخر بإذن المدير المسبق']));
        $line = self::line($sheet, '2026-08-02');
        $this->assertSame([0, 40, true], [$line['late'], $line['lateActual'], $line['corrected']]);
        $this->assertSame([['late', '40', '0', $s['keeper']]], array_map(fn ($c) => [$c['field'], $c['old'], $c['new'], $c['by']], $line['changes']));
        $this->assertSame(0, $sheet['totals']['lateMinutes']);

        // the timekeeper enters the forgotten Wednesday and corrects Monday's check-out: originals stay
        $this->expectRejected($this->postAs($s['keeper'], '/api/payroll/attendance', ['employee' => $e, 'checkIn' => '2026-08-02 16:00', 'checkOut' => '2026-08-02 18:00', 'reason' => 'إدخال متقاطع']), 'ATTENDANCE_OVERLAP', [422]);
        $this->expectRejected($this->postAs($s['keeper'], '/api/payroll/attendance', ['employee' => $e, 'checkIn' => '2026-12-01 08:00', 'reason' => 'وقت في المستقبل']), 'ATTENDANCE_FUTURE', [400]);
        $entered = $this->expectOk($this->postAs($s['keeper'], '/api/payroll/attendance', ['employee' => $e, 'checkIn' => '2026-08-05 08:00', 'checkOut' => '2026-08-05 17:00', 'reason' => 'نسي البصمة — بشهادة المدير']));
        $this->assertSame(['manual', '2026-08-05', 540], [$entered['inMode'], $entered['workDate'], $entered['workedMinutes']]);
        $fixed = $this->expectOk($this->patchAs($s['keeper'], "/api/payroll/attendance/{$short->id}", ['checkOut' => '2026-08-03 17:00', 'reason' => 'انقطاع جهاز البصمة']));
        $this->assertTrue($fixed['corrected']);
        $this->assertSame($short->check_out->toJSON(), $fixed['originalCheckOut'], 'what the record said first stays on it');
        $this->assertSame(['check_out'], array_column($fixed['changes'], 'field'));
        $this->assertSame('انقطاع جهاز البصمة', $fixed['changes'][0]['reason']);
        $this->expectRejected($this->patchAs($s['keeper'], "/api/payroll/attendance/{$short->id}", ['checkOut' => '2026-08-03 17:00', 'reason' => 'لا تغيير هنا']), 'ATTENDANCE_NO_CHANGE', [422]);

        $sheet = $this->expectOk($this->postAs($s['keeper'], "/api/payroll/sheets/{$sheet['id']}/recompute"));
        $this->assertSame([0, 0, 0, 0], [$sheet['totals']['absenceCount'], $sheet['totals']['earlyMinutes'], $sheet['totals']['lateMinutes'], self::line($sheet, '2026-08-02')['late']]);
        $this->assertSame(50, $sheet['totals']['lateActualMinutes'], 'the actual lateness is still shown; the corrected figure stands');

        // an open presence blocks the confirmation until it is voided (with a reason, never deleted)
        $open = $this->presence($e, '2026-08-07', '18:00', null);
        $this->expectRejected($this->postAs($s['keeper'], "/api/payroll/sheets/{$sheet['id']}/confirm"), 'SHEET_OPEN_ATTENDANCE', [422]);
        $this->expectRejected($this->postAs($s['keeper'], "/api/payroll/attendance/{$open->id}/void"), 'INVALID_INPUT', [400]);
        $void = $this->expectOk($this->postAs($s['keeper'], "/api/payroll/attendance/{$open->id}/void", ['reason' => 'بصمة خاطئة يوم الجمعة']));
        $this->assertSame(['void', $s['keeper']], [$void['status'], $void['voidedBy']]);
        $sheet = $this->expectOk($this->postAs($s['keeper'], "/api/payroll/sheets/{$sheet['id']}/confirm"));
        $this->assertSame('confirmed', $sheet['state']);

        // a confirmed day is the sheet's: the record is not touched until the sheet is reopened
        $this->expectRejected($this->patchAs($s['keeper'], "/api/payroll/attendance/{$short->id}", ['checkOut' => '2026-08-03 17:30', 'reason' => 'تصحيح بعد التأكيد']), 'ATTENDANCE_IN_SHEET', [422]);
        $this->expectRejected($this->postAs($s['keeper'], "/api/payroll/sheets/{$sheet['id']}/recompute"), 'SHEET_STATE', [422]);
        $this->expectRejected($this->postAs($s['keeper'], "/api/payroll/sheets/{$sheet['id']}/approve"), null, [403]);
        $sheet = $this->expectOk($this->postAs('admin', "/api/payroll/sheets/{$sheet['id']}/approve"));
        $this->assertSame('approved', $sheet['state']);
        $this->expectRejected($this->postAs('admin', "/api/payroll/sheets/{$sheet['id']}/approve"), 'SHEET_TRANSITION', [422]);
        $this->expectRejected($this->postAs($s['keeper'], "/api/payroll/sheets/{$sheet['id']}/reopen", ['reason' => 'أريد التعديل']), 'PAYROLL_PRIVATE', [403]);

        // the employee reads his own sheet, nobody else's
        $this->assertSame($sheet['id'], $this->expectOk($this->getAs($s['empUser'], "/api/payroll/sheets/{$sheet['id']}"))['id']);
        $this->expectRejected($this->getAs($s['otherUser'], "/api/payroll/sheets/{$sheet['id']}"), 'PAYROLL_PRIVATE', [403]);
        $this->assertSame([$sheet['id']], array_column($this->expectOk($this->getAs($s['empUser'], '/api/payroll/sheets'))['items'], 'id'));

        // payroll reopens it with a reason
        $sheet = $this->expectOk($this->postAs('admin', "/api/payroll/sheets/{$sheet['id']}/reopen", ['reason' => 'مراجعة الإضافي']));
        $this->assertSame('draft', $sheet['state']);
        $this->assertGreaterThanOrEqual(3, AttendanceChange::where('employee_id', $e)->count());

        // the daily board of a past day
        $board = $this->expectOk($this->getAs($s['keeper'], "/api/payroll/attendance/daily?date=2026-08-04&department={$s['w']['department']}"));
        $row = array_values(array_filter($board['items'], fn ($r) => $r['employee']['id'] === $e))[0];
        $this->assertSame(['present', 10, 90], [$row['state'], $row['lateMinutes'], $row['overtimeMinutes']]);
        $this->assertSame('absent', array_values(array_filter($board['items'], fn ($r) => $r['employee']['id'] === $s['other']['id']))[0]['state']);
    }

    public function test_a_period_is_confirmed_only_after_it_ended(): void
    {
        $s = $this->world();
        $sheet = $this->expectOk($this->postAs($s['keeper'], '/api/payroll/sheets', ['employee' => $s['other']['id'], 'dateFrom' => '2026-09-13', 'dateTo' => '2026-09-24']));
        $this->assertNull(self::line($sheet, '2026-09-20')['status'], 'a working day still to come (the suite stands on the 18th) is neither worked nor absent');
        $this->assertSame('absence', self::line($sheet, '2026-09-17')['status']);
        $this->expectRejected($this->postAs($s['keeper'], "/api/payroll/sheets/{$sheet['id']}/confirm"), 'SHEET_PERIOD_OPEN', [422]);
        $this->expectRejected($this->postAs($s['keeper'], '/api/payroll/sheets', ['employee' => $s['other']['id'], 'dateFrom' => '2026-06-01', 'dateTo' => '2026-08-31']), 'SHEET_DATES', [400]);
    }

    public function test_a_batch_makes_the_sheets_of_a_department(): void
    {
        $s = $this->world();
        $w = $this->payrollWorld();
        $a = $this->staff($w);
        $b = $this->staff($w);
        $this->newEmployee(['departmentId' => $w['department']]);   // no contract: no sheet, and the batch says why
        $batch = $this->expectOk($this->postAs($s['keeper'], '/api/payroll/sheet-batches', ['department' => $w['department'], 'dateFrom' => '2026-08-09', 'dateTo' => '2026-08-15', 'name' => 'أسبوع اختبار']));
        $this->assertSame(['draft', 'generate'], [$batch['state'], $batch['next']]);
        $this->expectRejected($this->postAs($s['keeper'], "/api/payroll/sheet-batches/{$batch['id']}/submit"), 'SHEET_BATCH_TRANSITION', [422]);
        $batch = $this->expectOk($this->postAs($s['keeper'], "/api/payroll/sheet-batches/{$batch['id']}/generate"));
        $this->assertSame(['generated', 2], [$batch['state'], $batch['sheetCount']]);
        $this->assertEqualsCanonicalizing([$a['id'], $b['id']], array_map(fn ($x) => $x['employee']['id'], $batch['sheets']));
        $this->assertSame(['NO_CONTRACT'], array_column($batch['skipped'], 'reason'));
        $batch = $this->expectOk($this->postAs($s['keeper'], "/api/payroll/sheet-batches/{$batch['id']}/submit"));
        $this->assertSame(['submitted', 2], [$batch['state'], $batch['sheetsByState']['confirmed']]);
        $this->assertSame(403, $this->postAs($s['keeper'], "/api/payroll/sheet-batches/{$batch['id']}/close")->getStatusCode());
        $batch = $this->expectOk($this->postAs('admin', "/api/payroll/sheet-batches/{$batch['id']}/close"));
        $this->assertSame(['closed', 2, null], [$batch['state'], $batch['sheetsByState']['approved'], $batch['next']]);
        $list = $this->expectOk($this->getAs($s['keeper'], "/api/payroll/sheet-batches?department={$w['department']}"));
        $this->assertSame([$batch['id']], array_column($list['items'], 'id'));
    }
}
