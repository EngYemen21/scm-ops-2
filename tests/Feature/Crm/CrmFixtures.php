<?php

namespace Tests\Feature\Crm;

use App\Crm\Models\CrmLostReason;
use App\Crm\Models\CrmStage;
use App\Crm\Models\CrmTeam;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fixtures of the CRM tests. The section's tables are shared by every story of the run (and by whatever the
 * installation seeded), so each story works in its own team and on a pipeline of its own, found by name.
 */
trait CrmFixtures
{
    private static int $crmSeq = 0;

    /** A short unique tag for names (two calls in one millisecond still differ). */
    protected static function tag(): string
    {
        return self::uid().'-'.(++self::$crmSeq);
    }

    /**
     * The pipeline the stories move leads through: new → visit → offer → won. Made once, found by name afterwards.
     *
     * @return array{new:string, visit:string, offer:string, won:string} stage ids
     */
    protected function pipeline(): array
    {
        $stages = ['new' => ['اختبار — جديدة', 'QA New', false, 10, 90010], 'visit' => ['اختبار — زيارة', 'QA Visit', false, 30, 90020], 'offer' => ['اختبار — عرض', 'QA Offer', false, 70, 90030], 'won' => ['اختبار — مكسوبة', 'QA Won', true, 100, 90040]];
        $out = [];
        foreach ($stages as $key => [$ar, $en, $won, $probability, $sequence]) {
            $out[$key] = (CrmStage::where('name_en', $en)->first()
                ?? CrmStage::create(['name_ar' => $ar, 'name_en' => $en, 'is_won' => $won, 'probability' => $probability, 'sequence' => $sequence, 'active' => true, 'created_by' => 'test']))->id;
        }

        return $out;
    }

    protected function lostReason(string $en = 'QA Too expensive', string $ar = 'اختبار — السعر مرتفع'): string
    {
        return (CrmLostReason::where('name_en', $en)->first() ?? CrmLostReason::create(['name_ar' => $ar, 'name_en' => $en, 'active' => true, 'created_by' => 'test']))->id;
    }

    /** A team of its own: the lists, the board and the report of a story are read through it. */
    protected function newTeam(): string
    {
        $tag = self::tag();

        return CrmTeam::create(['name_ar' => "فريق اختبار {$tag}", 'name_en' => "QA team {$tag}", 'sequence' => 900, 'pipeline' => true, 'active' => true, 'created_by' => 'test'])->id;
    }

    /** POST /api/crm/leads as the sales user, in the first stage of the test pipeline unless told otherwise. */
    protected function newLead(array $more = [], string $as = 'sales'): array
    {
        return $this->expectOk($this->postAs($as, '/api/crm/leads', $more + ['name' => 'فرصة اختبار '.self::tag(), 'stageId' => $this->pipeline()['new']]));
    }

    protected function lead(string $idOrNumber, string $as = 'sales'): array
    {
        return $this->expectOk($this->getAs($as, "/api/crm/leads/{$idOrNumber}"));
    }

    protected function userId(string $username): string
    {
        return (string) User::where('username', $username)->value('id');
    }

    /** The section follows Odoo (records that came from Odoo are Odoo's) or is handed over to OPS. */
    protected function followOdoo(bool $on): void
    {
        config(['odoo.enabled' => $on, 'odoo.project.crm' => $on]);
    }

    /**
     * A signed-in user who works the pipeline and nothing else: page.crm + crm.manage, without customer.manage or
     * sales.manage. @return string the username
     */
    protected function pipelineOnlyUser(): string
    {
        $username = 'crmonly';
        if (! DB::table('users')->where('username', $username)->exists()) {
            $role = (string) Str::ulid();
            DB::table('roles')->insert(['id' => $role, 'key' => 'crmonly', 'name_ar' => 'مسار المبيعات فقط', 'name_en' => 'Pipeline only', 'is_system' => false]);
            foreach (DB::table('permissions')->whereIn('key', ['page.crm', 'crm.manage'])->pluck('id') as $permission) {
                DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
            }
            $user = (string) Str::ulid();
            DB::table('users')->insert(['id' => $user, 'username' => $username, 'password_hash' => Hash::make((string) env('SEED_PASSWORD')), 'name_ar' => 'مندوب المسار', 'name_en' => 'Pipeline rep',
                'initials' => 'PR', 'active' => true, 'must_change_password' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $user, 'role_id' => $role]);
        }

        return $username;
    }
}
