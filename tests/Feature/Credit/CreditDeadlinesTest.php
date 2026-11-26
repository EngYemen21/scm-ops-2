<?php

namespace Tests\Feature\Credit;

use App\Credit\Models\CreditApproval;
use App\Credit\Models\CreditEnvelope;
use App\Credit\Models\CreditEvent;
use App\Credit\Models\CreditNotification;
use App\Credit\Services\DeadlinesService;
use App\Credit\Services\WorkflowEngine;
use App\Credit\Support\BusinessClock;
use App\Support\AuthUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * The clock of the workflow with travelled time. The suite's clock stands on Friday 2026-09-18 10:00 UTC (13:00 in
 * Riyadh) — a day off in the business calendar (Sunday to Thursday, 08:00-17:00 Riyadh): target hours start counting
 * on Sunday morning. Reminder before the deadline, the overdue notice and its repetition (at most `reminders.max`
 * times), the escalation that lets the escalation role decide — each once, whatever how often the clock runs.
 */
class CreditDeadlinesTest extends ApiTestCase
{
    use CreditFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->followOdoo(false);
        $this->plainPolicies();
        DB::table('credit_settings')->whereIn('key', ['calendar', 'reminders'])->delete();
        DB::table('credit_settings')->insert([
            ['key' => 'calendar', 'value' => json_encode(['mode' => 'working', 'weekdays' => [0, 1, 2, 3, 4], 'dayStart' => '08:00', 'dayEnd' => '17:00', 'holidays' => []]), 'updated_by' => 'test', 'updated_at' => now()],
            ['key' => 'reminders', 'value' => json_encode(['repeatHours' => 24, 'max' => 3]), 'updated_by' => 'test', 'updated_at' => now()],
        ]);
    }

    public function test_business_clock_counts_working_time_only(): void
    {
        $clock = BusinessClock::from(['mode' => 'working', 'weekdays' => [0, 1, 2, 3, 4], 'dayStart' => '08:00', 'dayEnd' => '17:00'], 'Asia/Riyadh');
        // Thursday 16:00 Riyadh + 8 working hours = Sunday 15:00 Riyadh
        $this->assertSame('2026-09-20 12:00:00', $clock->add(Carbon::parse('2026-09-17 13:00:00', 'UTC'), 8)->utc()->format('Y-m-d H:i:s'));
        // given on Friday: counted from Sunday 08:00
        $this->assertSame('2026-09-20 07:00:00', $clock->add(Carbon::parse('2026-09-18 10:00:00', 'UTC'), 2)->utc()->format('Y-m-d H:i:s'));
        // a holiday is skipped like a weekend
        $holiday = BusinessClock::from(['mode' => 'working', 'weekdays' => [0, 1, 2, 3, 4], 'dayStart' => '08:00', 'dayEnd' => '17:00', 'holidays' => ['2026-09-20']], 'Asia/Riyadh');
        $this->assertSame('2026-09-21 07:00:00', $holiday->add(Carbon::parse('2026-09-18 10:00:00', 'UTC'), 2)->utc()->format('Y-m-d H:i:s'));
        // calendar hours: every hour counts
        $calendar = BusinessClock::from(['mode' => 'calendar'], 'Asia/Riyadh');
        $this->assertSame('2026-09-18 18:00:00', $calendar->add(Carbon::parse('2026-09-18 10:00:00', 'UTC'), 8)->utc()->format('Y-m-d H:i:s'));
    }

    public function test_reminder_overdue_escalation_each_once_and_the_escalation_role_decides(): void
    {
        $this->twoStagePath(['sla_hours' => 8, 'reminder_before_hours' => 2, 'escalate_after_hours' => 4, 'escalation_role' => 'gm']);
        $r = $this->newRequest();
        $id = $r['id'];
        $this->expectOk($this->postAs('sales', "/api/credit/requests/{$id}/submit"));
        $row = $this->row($id);
        // entered on Friday: Sunday 08:00 + 8h = Sunday 16:00 Riyadh; reminder at 6h = 14:00; escalation 4 working hours after = Monday 11:00 Riyadh
        $this->assertSame('2026-09-20 13:00:00', $row->sla_deadline->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-20 11:00:00', $row->sla_due_soon_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-21 08:00:00', $row->sla_escalate_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('on_track', WorkflowEngine::sla($row));
        $line = CreditApproval::where('request_id', $id)->sole();
        $this->assertSame('2026-09-20 13:00:00', $line->deadline->utc()->format('Y-m-d H:i:s'));

        $run = fn (string $at) => $this->runAt($at);
        $run('2026-09-19 12:00:00');
        $this->assertSame(0, $this->row($id)->reminder_count, 'nothing before the reminder moment');

        $run('2026-09-20 11:30:00');
        $this->assertSame([1, 'due_soon'], [$this->row($id)->reminder_count, WorkflowEngine::sla($this->row($id))]);
        $run('2026-09-20 11:45:00');
        $this->assertSame(1, $this->row($id)->reminder_count, 'the same reminder is not given twice');
        $this->assertSame(1, CreditNotification::where('request_id', $id)->where('template_code', 'stage.reminder')->where('recipient_role', 'finance')->count());

        $run('2026-09-20 13:30:00');
        $this->assertSame([2, 'late'], [$this->row($id)->reminder_count, WorkflowEngine::sla($this->row($id))]);
        $run('2026-09-20 14:00:00');
        $this->assertSame(2, $this->row($id)->reminder_count, 'repeated only after the repeat hours');

        $run('2026-09-21 08:30:00');
        $row = $this->row($id);
        $this->assertNotNull($row->escalated_at);
        $this->assertSame([1, 'escalated'], [$row->escalation_count, WorkflowEngine::sla($row)]);
        $line = CreditApproval::find($line->id);
        $this->assertSame(['escalated', 'gm'], [$line->state, $line->escalated_to_role]);
        $this->assertTrue(DB::table('audit_logs')->where('entity_id', $id)->where('action', 'CREDIT.ESCALATE')->exists());
        $this->assertSame(1, CreditNotification::where('request_id', $id)->where('template_code', 'stage.escalated')->where('recipient_role', 'gm')->count());
        $run('2026-09-21 08:45:00');
        $this->assertSame(1, $this->row($id)->escalation_count, 'escalated once');

        $run('2026-09-21 14:00:00');
        $this->assertSame(3, $this->row($id)->reminder_count, 'the overdue notice repeats after 24 hours');
        $run('2026-09-22 15:00:00');
        $this->assertSame(3, $this->row($id)->reminder_count, 'never more than reminders.max');
        $this->assertSame(1 + 2, CreditEvent::where('request_id', $id)->where('kind', 'reminder')->count());

        // the general manager was not asked at this stage; escalated to his role, he decides it now
        $this->assertTrue(app(WorkflowEngine::class)->addressed($this->auth('gm'), CreditApproval::find($line->id)));
        Carbon::setTestNow(Carbon::parse('2026-09-22 15:05:00', 'UTC'));
        app(WorkflowEngine::class)->approve($this->auth('gm'), $id, 'قرار بعد التصعيد');
        $row = $this->row($id);
        $this->assertSame('ceo_approval', $row->state);
        $this->assertNull($row->escalated_at, 'a new stay starts with a new clock');
        $this->assertSame(0, $row->reminder_count);
        $this->assertSame('approved', CreditApproval::find($line->id)->state);
    }

    public function test_an_envelope_that_waits_past_its_time_expires(): void
    {
        $this->path([['sign', 'pending_signature', ['responsible_role' => 'finance', 'require_signature' => true]], ['final', 'ceo_approval', ['responsible_role' => 'gm']]]);
        $r = $this->newRequest();
        $this->expectOk($this->postAs('sales', "/api/credit/requests/{$r['id']}/submit"));
        $env = $this->expectOk($this->postAs('finance', "/api/credit/requests/{$r['id']}/envelopes"))['envelopes'][0];
        $this->runAt(now()->addDays(13)->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('sent', CreditEnvelope::find($env['id'])->status);
        $out = $this->runAt(now()->addDays(15)->utc()->format('Y-m-d H:i:s'));
        $this->assertGreaterThanOrEqual(1, $out['envelopesExpired']);
        $this->assertSame('expired', CreditEnvelope::find($env['id'])->status);
        $this->assertSame('expired', $this->row($r['id'])->signature_status);
    }

    public function test_the_manager_runs_the_clock_and_nobody_else(): void
    {
        $this->expectRejected($this->postAs('finance', '/api/credit/deadlines/run'), 'FORBIDDEN', [403]);
        $out = $this->expectOk($this->postAs('admin', '/api/credit/deadlines/run'));
        $this->assertTrue($out['installed']);
        $this->assertSame(0, $out['failed']);
    }

    /** Runs the clock at a moment (UTC) and leaves the clock there. */
    private function runAt(string $at): array
    {
        Carbon::setTestNow(Carbon::parse($at, 'UTC'));

        return app(DeadlinesService::class)->run();
    }

    private function auth(string $username): AuthUser
    {
        $u = DB::table('users')->where('username', $username)->first();
        $roles = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $u->id)->pluck('r.key')->all();
        $perms = DB::table('user_roles as ur')->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_id', $u->id)->distinct()->pluck('p.key')->all();

        return new AuthUser($u->id, $u->username, $u->name_ar, $u->name_en, $roles, $perms, [], null, null);
    }
}
