<?php

namespace Tests\Feature\Crm;

use App\Crm\Models\CrmActivity;
use App\Crm\Models\CrmLead;
use App\Crm\Models\CrmLeadEvent;
use App\Crm\Models\CrmLostReason;
use App\Crm\Models\CrmStage;
use App\Crm\Models\CrmTag;
use App\Crm\Models\CrmTeam;
use App\Crm\OdooCrmProjection;
use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Services\ExternalRefs;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\StatusHistory;
use App\Models\User;
use App\Support\AppError;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\ApiTestCase;

/**
 * From the copy of Odoo's CRM to the OPS pipeline (OdooCrmProjection). The copy is built here by hand — rows of
 * `odoo_models` / `odoo_records` shaped like the ones the mirror writes — and Odoo itself is never called.
 * What is proven: the plan writes nothing; the apply makes every stage, team, reason, tag and lead once; who owns
 * what afterwards; and how a change in Odoo reaches the lead while OPS's own notes and to-dos stay.
 */
class OdooCrmProjectionTest extends ApiTestCase
{
    use CrmFixtures;

    private const MODELS = ['crm.team', 'crm.stage', 'crm.lost.reason', 'crm.tag', 'crm.lead'];

    private string $tag;

    /** OPS users the Odoo salespeople are matched to: by e-mail, by login, by the e-mail of the Odoo user's partner. */
    private User $byEmail;

    private User $byLogin;

    private User $byPartner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        // what an earlier story left: the copy, and the records it projected (Odoo's only)
        foreach (['odoo_changes', 'odoo_records', 'odoo_models'] as $table) {
            DB::table($table)->delete();
        }
        $leads = CrmLead::where('source_system', 'odoo')->pluck('id');
        foreach (['crm_lead_tags', 'crm_lead_events', 'crm_activities', 'crm_lead_quotations'] as $table) {
            DB::table($table)->whereIn('lead_id', $leads)->delete();
        }
        CrmLead::whereIn('id', $leads)->delete();
        foreach ([CrmStage::class, CrmTeam::class, CrmLostReason::class, CrmTag::class] as $class) {
            $class::where('source_system', 'odoo')->delete();
        }
        IntExternalRef::where('system', 'odoo')->delete();
        // names of this story's copy that the installation (or another story) may hold as OPS's own: kept apart, so that
        // "created" below means created and "linked" is only what a test set up to be linked
        $names = ['New', 'Qualified', 'Won', 'Negotiation', 'Qualified lead', 'Sales', 'Website', 'Too expensive', 'جديدة', 'زيارة', 'مؤهَّلة', 'مكسوبة', 'المبيعات', 'الموقع الإلكتروني', 'السعر مرتفع', 'غير مهتم'];
        foreach ([CrmStage::class, CrmTeam::class, CrmLostReason::class] as $class) {
            foreach ($class::whereNull('source_system')->where(fn ($w) => $w->whereIn('name_en', $names)->orWhereIn('name_ar', $names))->get() as $row) {
                $row->update(['name_ar' => $row->name_ar.' ·', 'name_en' => $row->name_en.' ·']);
            }
        }
        $this->followOdoo(true);
        $this->tag = $tag = self::uid();

        $user = fn (string $username, ?string $email) => User::create(['username' => $username, 'email' => $email, 'password_hash' => Hash::make(Str::random(20)), 'name_ar' => "مندوب {$username}", 'name_en' => "Rep {$username}",
            'initials' => 'RP', 'active' => true, 'must_change_password' => false]);
        $this->byEmail = $user("rep.a{$tag}", "haitham{$tag}@corp.example");
        $this->byLogin = $user("rep.b{$tag}", null);
        $this->byPartner = $user("rep.c{$tag}", "rep.former.mail{$tag}@corp.example");
        $this->customer = Customer::create(['code' => "CUS-O{$tag}", 'name_ar' => "مطعم الواحة {$tag}", 'name_en' => 'Al Waha', 'zone' => 'الرياض', 'contact' => '0551112222', 'terms' => 'نقدي / محفظة',
            'source_system' => 'odoo', 'vat_no' => '300011112222003']);
        $this->app->make(ExternalRefs::class)->link('odoo', 'customer', '900', $this->customer->id, $this->customer->code); // what the master-data projection did

        foreach (self::MODELS as $model) {
            OdooModel::create(['model' => $model, 'status' => 'active', 'full_state' => 'done', 'has_active' => in_array($model, ['crm.team', 'crm.lost.reason', 'crm.lead'], true)]);
        }
        // ── Odoo's people and partners (other models of the copy the projection only looks things up in)
        $this->rec('res.users', 17, ['login' => strtoupper("haitham{$tag}@corp.example"), 'partner_id' => [861, 'مندوب ثالث']], true);
        $this->rec('res.users', 8, ['login' => "rep.b{$tag}", 'partner_id' => [698, 'مندوب ثان']], true);
        $this->rec('res.users', 20, ['login' => "rep.former{$tag}@gmail.example", 'partner_id' => [884, 'مندوب سابق']], false);
        $this->rec('res.users', 23, ['login' => "nobody{$tag}@corp.example", 'partner_id' => [967, 'مندوب رابع']], true);
        $this->rec('res.partner', 884, ['name' => 'مندوب سابق', 'email' => "rep.former.mail{$tag}@corp.example"], true);
        $this->rec('res.partner', 900, ['name' => "مطعم الواحة {$tag}", 'vat' => '3000-1111-2222-003', 'customer_rank' => 4], true);
        $this->rec('res.partner', 901, ['name' => 'مدير مشتريات الواحة', 'parent_id' => [900, 'مطعم الواحة']], true);
        $this->rec('res.partner', 902, ['name' => 'كافيه بلا ارتباط', 'vat' => false], true);
        // ── the pipeline as Odoo holds it
        $this->rec('crm.team', 1, ['name' => 'Sales', 'sequence' => 0, 'user_id' => [6, 'مندوب أول'], 'use_opportunities' => true], true);
        $this->rec('crm.team', 2, ['name' => 'Website', 'sequence' => 10, 'use_opportunities' => false], false);
        $this->rec('crm.stage', 1, ['name' => 'New', 'sequence' => 0, 'is_won' => false, 'fold' => false]);
        $this->rec('crm.stage', 5, ['name' => 'زيارة', 'sequence' => 1, 'is_won' => false, 'fold' => false]);
        $this->rec('crm.stage', 2, ['name' => 'Qualified', 'sequence' => 2, 'is_won' => false, 'fold' => false, 'requirements' => 'Budget known']);
        $this->rec('crm.stage', 4, ['name' => 'Won', 'sequence' => 4, 'is_won' => true, 'fold' => true]);
        $this->rec('crm.lost.reason', 1, ['name' => 'Too expensive'], true);
        $this->rec('crm.lost.reason', 4, ['name' => 'غير مهتم'], true);
        $this->rec('crm.tag', 1, ['name' => "مطعم {$tag}", 'color' => 9]);
        $this->rec('crm.tag', 2, ['name' => "كافي {$tag}", 'color' => 3]);
        $lead = fn (int $id, array $more, ?bool $active = true) => $this->rec('crm.lead', $id, $more + [
            'name' => "فرصة أودو {$id} {$tag}", 'type' => 'opportunity', 'team_id' => [1, 'Sales'], 'priority' => '0', 'won_status' => 'pending', 'probability' => 48.29, 'expected_revenue' => 0.0,
            'create_date' => '2026-03-01 09:15:00', 'date_open' => '2026-03-01 09:15:00', 'date_last_stage_update' => '2026-03-05 10:00:00', 'create_uid' => [17, 'مندوب ثالث'], 'write_uid' => [17, 'مندوب ثالث'],
            'tag_ids' => [], 'partner_id' => false, 'lost_reason_id' => false, 'date_closed' => false, 'date_deadline' => false, 'contact_name' => false, 'description' => false,
        ], $active);
        $lead(101, ['stage_id' => [1, 'New'], 'user_id' => [17, 'مندوب ثالث'], 'partner_id' => [900, 'مطعم الواحة'], 'partner_name' => 'مطعم الواحة', 'priority' => '3', 'expected_revenue' => 20000.0,
            'probability' => 91.67, 'tag_ids' => [1], 'phone' => '+966 11 222 3333', 'mobile' => '0551112222', 'email_from' => 'buyer@alwaha.example', 'street' => 'طريق الملك فهد', 'city' => 'Riyadh',
            'state_id' => [1644, 'Riyadh (SA)'], 'country_id' => [192, 'Saudi Arabia'], 'zip' => '12345', 'website' => 'https://alwaha.example', 'campaign_id' => [3, 'ramadan-2026'],
            'description' => '<p>يطلب <b>أجبانًا</b> وزيوتًا</p><p>الدفع آجل</p>', 'date_deadline' => '2026-12-31', 'lead_import_id' => 'fb-7781', 'day_open' => 1.5]);
        $lead(102, ['stage_id' => [4, 'Won'], 'user_id' => [8, 'مندوب ثان'], 'partner_id' => [901, 'مطعم الواحة, مدير مشتريات الواحة'], 'won_status' => 'won', 'probability' => 100.0, 'expected_revenue' => 40000.0,
            'date_closed' => '2026-04-10 12:30:00', 'contact_name' => 'مدير المشتريات']);
        $lead(103, ['stage_id' => [1, 'New'], 'user_id' => [20, 'مندوب سابق'], 'won_status' => 'lost', 'probability' => 0.0, 'date_closed' => '2026-05-02 08:00:00'], false);
        $lead(104, ['stage_id' => [5, 'زيارة'], 'user_id' => [23, 'مندوب رابع'], 'partner_id' => [902, 'كافيه بلا ارتباط'], 'tag_ids' => [2]]);
        $lead(105, ['stage_id' => [5, 'زيارة'], 'user_id' => [23, 'مندوب رابع'], 'type' => 'lead'], false); // archived in Odoo without being lost
        $lead(106, ['stage_id' => [1, 'New'], 'user_id' => [17, 'مندوب ثالث']]);
        OdooRecord::where('model', 'crm.lead')->where('odoo_id', 106)->update(['deleted_at' => now()]); // deleted in Odoo before OPS ever had it
    }

    /** One record of the copy, as the mirror would hold it. */
    private function rec(string $model, int $id, array $data, ?bool $active = null): void
    {
        $data = ($active === null ? [] : ['active' => $active]) + $data + ['write_date' => '2026-09-01 08:00:00'];
        OdooRecord::updateOrCreate(['model' => $model, 'odoo_id' => $id], ['display_name' => is_string($data['name'] ?? null) ? $data['name'] : null, 'write_date' => $data['write_date'], 'active' => $active,
            'data' => $data, 'hash' => sha1(json_encode($data)), 'first_seen_at' => now(), 'changed_at' => now(), 'deleted_at' => null]);
    }

    /** What changes in Odoo: the record of the copy is rewritten and the feed says so. */
    private function odooWrites(string $model, int $id, array $values, ?bool $active = true): void
    {
        $current = OdooRecord::where('model', $model)->where('odoo_id', $id)->first();
        $this->rec($model, $id, $values + ['write_date' => '2026-09-10 11:00:00'] + (array) ($current?->data ?? []), $active);
        OdooChange::create(['model' => $model, 'odoo_id' => $id, 'kind' => $current ? 'updated' : 'created', 'seen_at' => now()]);
    }

    private function projection(): OdooCrmProjection
    {
        return $this->app->make(OdooCrmProjection::class);
    }

    private function odooLead(int $id): CrmLead
    {
        return CrmLead::with(['stage', 'team', 'tags', 'lostReason'])->where('source_system', 'odoo')->where('external_ref', (string) $id)->firstOrFail();
    }

    private function tables(): array
    {
        return [CrmStage::count(), CrmTeam::count(), CrmLostReason::count(), CrmTag::count(), CrmLead::count(), CrmLeadEvent::count(), IntExternalRef::count(), AuditLog::count(), User::count(),
            (int) DB::table('number_sequences')->where('key', 'OPP')->value('next')];
    }

    public function test_the_plan_writes_nothing_and_the_apply_loads_odoos_pipeline_once(): void
    {
        $p = $this->projection();
        $this->assertSame(['crm', true, true], [$p->key(), $p->enabled(), $p->ready()]);
        $this->assertEqualsCanonicalizing(self::MODELS, $p->models());
        $before = $this->tables();

        $expected = [
            'teams.created' => 2, 'stages.created' => 4, 'reasons.created' => 2, 'tags.created' => 2, 'leads.created' => 5, 'leads.skippedDeleted' => 1,
            'leads.withCustomer' => 2, 'people.matched' => 3, 'people.byName' => 2,
        ];
        $plan = $p->plan();
        $this->assertTrue($plan['dryRun']);
        $this->assertEquals($expected, $plan['counts']);
        $this->assertEqualsCanonicalizing(['مندوب أول', 'مندوب رابع'], $plan['notes']['people.byName'], 'the salespeople with no OPS user, for a person to see');
        $this->assertSame($before, $this->tables(), 'a plan writes nothing — no record, no number taken, no service user made');

        OdooChange::create(['model' => 'crm.lead', 'odoo_id' => 101, 'kind' => 'created', 'seen_at' => now()]);
        $applied = $p->apply();
        $this->assertFalse($applied['dryRun']);
        $this->assertEquals($expected, $applied['counts']);
        $this->assertSame(0, OdooChange::whereNull('processed_at')->count(), 'a whole pass covers what the feed reported so far');

        // ── stages: Odoo's order and meaning; Odoo's own English names get an Arabic one, a name people typed stays
        $stages = CrmStage::where('source_system', 'odoo')->ordered()->get();
        $this->assertSame([['1', 'جديدة', 'New', 0, false], ['5', 'زيارة', 'زيارة', 1, false], ['2', 'مؤهَّلة', 'Qualified', 2, false], ['4', 'مكسوبة', 'Won', 4, true]],
            $stages->map(fn (CrmStage $s) => [$s->external_ref, $s->name_ar, $s->name_en, $s->sequence, $s->is_won])->all());
        $this->assertSame(['Budget known', true, 100.0], [$stages[2]->requirements, $stages[3]->fold, $stages[3]->probability]);
        // ── teams (an archived team stays archived; a leader with no OPS user is kept by name), reasons, tags
        $teams = CrmTeam::where('source_system', 'odoo')->orderBy('external_ref')->get();
        $this->assertSame([['المبيعات', 'Sales', true, true, null, 'مندوب أول'], ['الموقع الإلكتروني', 'Website', false, false, null, null]],
            $teams->map(fn (CrmTeam $t) => [$t->name_ar, $t->name_en, $t->active, $t->pipeline, $t->leader_id, $t->leader_name])->all());
        $this->assertSame([['السعر مرتفع', 'Too expensive'], ['غير مهتم', 'غير مهتم']], CrmLostReason::where('source_system', 'odoo')->orderBy('external_ref')->get()->map(fn ($r) => [$r->name_ar, $r->name_en])->all());
        $this->assertSame([["مطعم {$this->tag}", 9], ["كافي {$this->tag}", 3]], CrmTag::where('source_system', 'odoo')->orderBy('external_ref')->get()->map(fn ($t) => [$t->name, $t->color])->all());

        // ── a lead, value by value
        $l = $this->odooLead(101);
        $this->assertMatchesRegularExpression('/^OPP-\d{4}-\d{5}$/', $l->number);
        $this->assertSame(["فرصة أودو 101 {$this->tag}", 'opportunity', 'open', false, 3, '20000.00', 91.67, 'New', 'Sales', 'مطعم الواحة', '+966 11 222 3333', '0551112222', 'buyer@alwaha.example'],
            [$l->name, $l->kind, $l->status, $l->archived, $l->priority, $l->expected_revenue, $l->probability, $l->stage->name_en, $l->team->name_en, $l->company_name, $l->phone, $l->mobile, $l->email]);
        $this->assertSame(['طريق الملك فهد', 'Riyadh', 'Riyadh (SA)', 'Saudi Arabia', '12345', 'https://alwaha.example', 'ramadan-2026', '2026-12-31', '900', '300011112222003'],
            [$l->street, $l->city, $l->region, $l->country, $l->zip, $l->website, $l->campaign, $l->deadline->toDateString(), $l->partner_ref, $l->vat_no]);
        $this->assertSame("يطلب أجبانًا وزيوتًا\nالدفع آجل", $l->description, 'Odoo keeps notes as HTML: OPS keeps their text');
        $this->assertSame(["مطعم {$this->tag}"], $l->tags->pluck('name')->all());
        $this->assertEquals(['lead_import_id' => 'fb-7781', 'day_open' => 1.5], $l->extra);
        $this->assertSame(['2026-03-01 09:15:00', '2026-03-01 09:15:00', '2026-03-05 10:00:00', '2026-09-01 08:00:00', 'مندوب ثالث'],
            [$l->created_at->format('Y-m-d H:i:s'), $l->opened_at->format('Y-m-d H:i:s'), $l->stage_changed_at->format('Y-m-d H:i:s'), $l->source_updated_at->format('Y-m-d H:i:s'), $l->created_by], 'Odoo\'s own dates, not the day of the load');
        // the salesperson: the OPS user with that e-mail (whatever the letter case); the customer: the one its partner is linked to
        $this->assertSame([$this->byEmail->id, null, $this->customer->id, null], [$l->owner_id, $l->owner_name, $l->customer_id, $l->converted_at]);
        $event = CrmLeadEvent::where('lead_id', $l->id)->sole();
        $this->assertSame(['created', 'odoo', 'مندوب ثالث', '2026-03-01 09:15:00'], [$event->kind, $event->origin, $event->username, $event->at->format('Y-m-d H:i:s')]);
        $audit = AuditLog::where('action', 'LEAD.CREATE')->where('entity_id', $l->id)->sole();
        $this->assertSame('svc.odoo', $audit->username);
        $this->assertStringContainsString('crm.lead #101', (string) $audit->new_value);

        // ── won (by login; the partner is a contact: its company's customer) · lost without a reason, as Odoo has it · by name
        $won = $this->odooLead(102);
        $this->assertSame(['won', false, 'Won', 100.0, '40000.00', $this->byLogin->id, $this->customer->id, '2026-04-10 12:30:00', 'مدير المشتريات'],
            [$won->status, $won->archived, $won->stage->name_en, $won->probability, $won->expected_revenue, $won->owner_id, $won->customer_id, $won->closed_at->format('Y-m-d H:i:s'), $won->contact_name]);
        $this->assertSame(['won', 'created'], CrmLeadEvent::where('lead_id', $won->id)->orderByDesc('at')->pluck('kind')->all());
        $lost = $this->odooLead(103);
        $this->assertSame(['lost', false, null, 0.0, $this->byPartner->id, null], [$lost->status, $lost->archived, $lost->lost_reason_id, $lost->probability, $lost->owner_id, $lost->owner_name], 'matched by the e-mail of the Odoo user\'s partner');
        $open = $this->odooLead(104);
        $this->assertSame(['open', 'زيارة', null, 'مندوب رابع', null, '902', null, ["كافي {$this->tag}"]],
            [$open->status, $open->stage->name_en, $open->owner_id, $open->owner_name, $open->customer_id, $open->partner_ref, $open->vat_no, $open->tags->pluck('name')->all()]);
        $put = $this->odooLead(105);
        $this->assertSame(['open', true, 'lead'], [$put->status, $put->archived, $put->kind], 'archived in Odoo without being lost: put away, not lost');
        $this->assertNull(CrmLead::where('source_system', 'odoo')->where('external_ref', '106')->first(), 'deleted in Odoo before OPS had it: nothing to keep');

        // ── again: nothing new, nothing changed, nothing written
        $after = $this->tables();
        $again = $p->apply();
        $this->assertEquals(['teams.unchanged' => 2, 'stages.unchanged' => 4, 'reasons.unchanged' => 2, 'tags.unchanged' => 2, 'leads.unchanged' => 5, 'leads.skippedDeleted' => 1,
            'leads.withCustomer' => 2, 'people.matched' => 3, 'people.byName' => 2], $again['counts']);
        $this->assertSame($after, $this->tables());
        $this->assertEquals($again['counts'], $p->plan()['counts']);

        // ── the lists show them as Odoo's
        $list = $this->expectOk($this->getAs('gm', '/api/crm/leads?state=all&origin=odoo&sort=number&order=asc'));
        $this->assertSame(5, $list['total']);
        $this->assertSame([['101', true, 'odoo'], ['102', true, 'odoo'], ['103', true, 'odoo'], ['104', true, 'odoo'], ['105', true, 'odoo']], array_map(fn ($x) => [$x['externalRef'], $x['owned'], $x['sourceSystem']], $list['items']));
        $this->assertContains($l->number, array_column($this->expectOk($this->getAs('gm', '/api/crm/leads?q=101&origin=odoo'))['items'], 'number'), 'found by the number people know it by in Odoo');
        $owner = collect($this->expectOk($this->getAs('gm', '/api/crm/lookups'))['owners'])->firstWhere('key', 'name:مندوب رابع');
        $this->assertSame([null, true, 2], [$owner['id'], $owner['external'], $owner['count']]);
        $this->assertSame(1, $this->expectOk($this->getAs('gm', '/api/crm/leads?owner='.rawurlencode('name:مندوب رابع')))['total'], 'a salesperson kept by name is still a filter');
    }

    public function test_what_odoo_owns_is_read_only_here_and_ops_adds_its_own(): void
    {
        $this->projection()->apply();
        $open = $this->odooLead(104);
        $won = $this->odooLead(102);
        $lost = $this->odooLead(103);
        $stages = CrmStage::where('source_system', 'odoo')->pluck('id', 'external_ref');
        $updatedAt = $open->updated_at;

        $detail = $this->lead($open->number);
        $this->assertTrue($detail['owned']);
        $this->assertSame(['edit' => false, 'move' => false, 'win' => false, 'lose' => false, 'reopen' => false, 'convert' => false, 'quote' => false, 'note' => true, 'activity' => true], $detail['can']);
        $this->assertTrue($this->expectOk($this->getAs('sales', '/api/crm/lookups'))['follows']);

        // ── everything Odoo holds is refused, with a message that says where to change it
        $refused = $this->expectRejected($this->patchAs('sales', "/api/crm/leads/{$open->id}", ['expectedRevenue' => 999]), 'CRM_OWNED_BY_SOURCE', [422]);
        $this->assertStringContainsString('أودو', $refused['message']);
        $this->assertSame(['lead', 'odoo', '104'], [$refused['details']['entity'], $refused['details']['sourceSystem'], $refused['details']['externalRef']]);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$open->id}/move", ['stageId' => $stages['2']]), 'CRM_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$open->id}/win"), 'CRM_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$open->id}/lose", ['reasonId' => $this->lostReason()]), 'CRM_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('sales', "/api/crm/leads/{$lost->id}/reopen"), 'CRM_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->patchAs('sales', "/api/crm/stages/{$stages['1']}", ['nameAr' => 'اسم آخر']), 'CRM_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->patchAs('sales', '/api/crm/teams/'.CrmTeam::where('source_system', 'odoo')->value('id'), ['active' => false]), 'CRM_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->patchAs('sales', '/api/crm/lost-reasons/'.CrmLostReason::where('source_system', 'odoo')->value('id'), ['active' => false]), 'CRM_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->patchAs('sales', '/api/crm/tags/'.CrmTag::where('source_system', 'odoo')->value('id'), ['color' => 1]), 'CRM_OWNED_BY_SOURCE', [422]);
        $fresh = $open->fresh();
        $this->assertSame(['0.00', $stages['5'], 'open'], [$fresh->expected_revenue, $fresh->stage_id, $fresh->status]);
        $this->assertEquals($updatedAt, $fresh->updated_at);
        $this->assertSame('جديدة', CrmStage::find($stages['1'])->name_ar);

        // ── what is OPS's own stays open: notes, to-dos, the customer, the quotations
        $noted = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$open->id}/notes", ['text' => 'ملاحظة من العمليات']));
        $this->assertSame(['note', 'ملاحظة من العمليات', null], [$noted['events'][0]['kind'], $noted['events'][0]['note'], $noted['events'][0]['origin']]);
        $todo = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$open->id}/activities", ['type' => 'visit', 'dueDate' => now()->addDays(2)->toDateString()]));
        $this->assertSame('visit', $this->lead($open->number)['nextActivity']['type']);
        $this->assertSame('done', $this->expectOk($this->postAs('sales', "/api/crm/activities/{$todo['id']}/done", ['note' => 'تمت الزيارة']))['status']);
        // a won opportunity of Odoo already carries the customer its partner is linked to
        $converted = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$won->id}/convert"));
        $this->assertSame(['already', $this->customer->code, true], [$converted['conversion']['by'], $converted['customer']['code'], $converted['can']['quote']]);

        // ── a lead made in OPS works fully, also on Odoo's stages
        $mine = $this->newLead(['stageId' => $stages['1']]);
        $this->assertFalse($mine['owned']);
        $moved = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$mine['id']}/move", ['stageId' => $stages['5']]));
        $this->assertSame(['زيارة', true], [$moved['stage']['nameEn'], $moved['can']['edit']]);
        $this->assertSame('won', $this->expectOk($this->postAs('sales', "/api/crm/leads/{$mine['id']}/move", ['stageId' => $stages['4']]))['status']);
        // OPS may add stages, reasons and tags of its own beside Odoo's
        $own = $this->expectOk($this->postAs('sales', '/api/crm/stages', ['nameAr' => 'تجربة عينات '.self::tag(), 'sequence' => 1]));
        $this->assertFalse($own['owned']);
        $this->expectOk($this->patchAs('sales', "/api/crm/stages/{$own['id']}", ['active' => false]));

        // ── handed over: the switch is off and everything is edited here
        $this->followOdoo(false);
        $this->assertFalse($this->expectOk($this->getAs('sales', '/api/crm/lookups'))['follows']);
        $edited = $this->expectOk($this->patchAs('sales', "/api/crm/leads/{$open->id}", ['expectedRevenue' => 7500, 'ownerId' => $this->byEmail->id]));
        $this->assertSame(['7500.00', $this->byEmail->id, false, true, 'odoo'], [$edited['expectedRevenue'], $edited['owner']['id'], $edited['owned'], $edited['can']['edit'], $edited['sourceSystem']]);
        $this->assertSame('Qualified', $this->expectOk($this->postAs('sales', "/api/crm/leads/{$open->id}/move", ['stageId' => $stages['2']]))['stage']['nameEn']);
        $this->assertSame('open', $this->expectOk($this->postAs('sales', "/api/crm/leads/{$lost->id}/reopen"))['status']);
        $put = $this->odooLead(105);
        $restored = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$put->id}/reopen"));
        $this->assertSame(['open', false], [$restored['status'], $restored['archived']], 'a lead Odoo had archived is taken back into the pipeline');
        $this->assertSame('جديد', $this->expectOk($this->patchAs('sales', "/api/crm/stages/{$stages['1']}", ['nameAr' => 'جديد']))['nameAr']);
    }

    public function test_a_change_in_odoo_reaches_the_lead_and_opss_own_additions_stay(): void
    {
        $p = $this->projection();
        $p->apply();
        $lead = $this->odooLead(104);
        $this->expectOk($this->postAs('sales', "/api/crm/leads/{$lead->id}/notes", ['text' => 'ملاحظة تبقى']));
        $todo = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$lead->id}/activities", ['type' => 'call', 'dueDate' => now()->addDays(3)->toDateString()]));
        $this->assertSame(['changes' => 0, 'counts' => []], $p->consume(), 'nothing in the feed: nothing to do');

        // ── Odoo: the lead moves on, gets another salesperson, a figure and another tag
        $this->odooWrites('crm.lead', 104, ['stage_id' => [2, 'Qualified'], 'date_last_stage_update' => '2026-09-10 10:45:00', 'user_id' => [17, 'مندوب ثالث'], 'expected_revenue' => 5000.0, 'tag_ids' => [1, 2], 'write_uid' => [8, 'مندوب ثان']]);
        $run = $p->consume();
        $this->assertEquals([1, ['leads.updated' => 1, 'people.matched' => 1]], [$run['changes'], $run['counts']]);
        $lead = $this->odooLead(104);
        $this->assertSame(['Qualified', $this->byEmail->id, null, '5000.00', '2026-09-10 10:45:00', '2026-09-10 11:00:00'],
            [$lead->stage->name_en, $lead->owner_id, $lead->owner_name, $lead->expected_revenue, $lead->stage_changed_at->format('Y-m-d H:i:s'), $lead->source_updated_at->format('Y-m-d H:i:s')]);
        $this->assertEqualsCanonicalizing(["مطعم {$this->tag}", "كافي {$this->tag}"], $lead->tags->pluck('name')->all());
        $move = CrmLeadEvent::where('lead_id', $lead->id)->where('kind', 'stage')->sole();
        $names = CrmStage::whereIn('id', [$move->from_stage_id, $move->to_stage_id])->pluck('name_en', 'id');
        $this->assertSame(['زيارة', 'Qualified', 'odoo', 'مندوب ثان', '2026-09-10 10:45:00'], [$names[$move->from_stage_id], $names[$move->to_stage_id], $move->origin, $move->username, $move->at->format('Y-m-d H:i:s')], 'the move is on the timeline, at Odoo\'s time');
        $handed = CrmLeadEvent::where('lead_id', $lead->id)->where('kind', 'assigned')->sole();
        $this->assertSame(['مندوب رابع', $this->byEmail->name_ar], [$handed->data['from'], $handed->data['to']]);
        $this->assertSame('svc.odoo', AuditLog::where('action', 'LEAD.UPDATE')->where('entity_id', $lead->id)->sole()->username);
        // what OPS added is untouched
        $this->assertSame(1, CrmLeadEvent::where('lead_id', $lead->id)->where('kind', 'note')->where('note', 'ملاحظة تبقى')->count());
        $this->assertSame(['open', 'call'], [CrmActivity::findOrFail($todo['id'])->status, $lead->next_activity_type]);
        $this->assertSame(0, OdooChange::whereNull('processed_at')->count());
        $this->assertSame(['changes' => 0, 'counts' => []], $p->consume());

        // ── Odoo: lost, with a reason — the to-do planned here is cancelled with it
        $this->odooWrites('crm.lead', 104, ['won_status' => 'lost', 'probability' => 0.0, 'lost_reason_id' => [4, 'غير مهتم'], 'date_closed' => '2026-09-12 09:00:00'], false);
        $this->assertEquals(['leads.updated' => 1, 'people.matched' => 1], $p->consume()['counts']);
        $lead = $this->odooLead(104);
        $this->assertSame(['lost', false, 'غير مهتم', 0.0, '2026-09-12 09:00:00', null], [$lead->status, $lead->archived, $lead->lostReason->name_ar, $lead->probability, $lead->closed_at->format('Y-m-d H:i:s'), $lead->next_activity_date]);
        $this->assertSame(['cancelled', 'svc.odoo'], [CrmActivity::findOrFail($todo['id'])->status, CrmActivity::findOrFail($todo['id'])->cancelled_by]);
        $history = StatusHistory::where('entity_type', 'CrmLead')->where('entity_id', $lead->id)->sole();
        $this->assertSame(['open', 'lost', 'svc.odoo'], [$history->from_status, $history->to_status, $history->username]);
        $this->assertSame(['lost', 'odoo', '2026-09-12 09:00:00'], CrmLeadEvent::where('lead_id', $lead->id)->where('kind', 'lost')->get()->map(fn ($e) => [$e->kind, $e->origin, $e->at->format('Y-m-d H:i:s')])->sole());

        // ── Odoo: restored and won; then a brand-new stage and a lead in it arrive in one run, the lead first
        $this->odooWrites('crm.lead', 104, ['won_status' => 'won', 'probability' => 100.0, 'stage_id' => [4, 'Won'], 'lost_reason_id' => false, 'date_closed' => '2026-09-15 16:00:00']);
        OdooChange::create(['model' => 'crm.lead', 'odoo_id' => 110, 'kind' => 'created', 'seen_at' => now()]);
        $this->rec('crm.lead', 110, ['name' => "فرصة جديدة {$this->tag}", 'type' => 'opportunity', 'stage_id' => [9, 'Negotiation'], 'user_id' => false, 'won_status' => 'pending', 'probability' => 20.0, 'create_date' => '2026-09-16 08:00:00'], true);
        $this->odooWrites('crm.stage', 9, ['name' => 'Negotiation', 'sequence' => 3, 'is_won' => false], null);
        $run = $p->consume();
        $this->assertSame(3, $run['changes']);
        $this->assertEquals(['leads.updated' => 1, 'people.matched' => 1, 'stages.created' => 1, 'leads.created' => 1, 'stages.unchanged' => 1], $run['counts']);
        $lead = $this->odooLead(104);
        $this->assertSame(['won', 'Won', null, '2026-09-15 16:00:00'], [$lead->status, $lead->stage->name_en, $lead->lost_reason_id, $lead->closed_at->format('Y-m-d H:i:s')]);
        $this->assertSame(['lost', 'won'], [StatusHistory::where('entity_id', $lead->id)->orderByDesc('at')->orderByDesc('id')->first()->from_status, StatusHistory::where('entity_id', $lead->id)->orderByDesc('at')->orderByDesc('id')->first()->to_status]);
        $new = $this->odooLead(110);
        $this->assertSame(['Negotiation', 'Negotiation', null, null, 20.0], [$new->stage->name_en, $new->stage->name_ar, $new->owner_id, $new->owner_name, $new->probability]);
        $this->assertSame(1, CrmStage::where('source_system', 'odoo')->where('external_ref', '9')->count());

        // ── Odoo: a stage is renamed, a tag is deleted, the lead is deleted — archived here, with its history
        $this->odooWrites('crm.stage', 2, ['name' => 'Qualified lead']);
        OdooRecord::where('model', 'crm.tag')->where('odoo_id', 2)->update(['deleted_at' => now()]);
        OdooChange::create(['model' => 'crm.tag', 'odoo_id' => 2, 'kind' => 'deleted', 'seen_at' => now()]);
        OdooRecord::where('model', 'crm.lead')->where('odoo_id', 104)->update(['deleted_at' => now()]);
        OdooChange::create(['model' => 'crm.lead', 'odoo_id' => 104, 'kind' => 'deleted', 'seen_at' => now()]);
        $run = $p->consume();
        $this->assertEquals(['stages.updated' => 1, 'tags.archived' => 1, 'leads.archived' => 1, 'people.matched' => 1], $run['counts']);
        $this->assertSame(['Qualified lead', 'Qualified lead'], [CrmStage::where('external_ref', '2')->where('source_system', 'odoo')->value('name_en'), CrmStage::where('external_ref', '2')->where('source_system', 'odoo')->value('name_ar')]);
        $this->assertFalse((bool) CrmTag::where('source_system', 'odoo')->where('external_ref', '2')->value('active'));
        $gone = $this->odooLead(104);
        $this->assertSame([true, 'won'], [$gone->archived, $gone->status]);
        $this->assertNotNull($gone->source_deleted_at);
        $this->assertStringContainsString('حُذفت من أودو', (string) CrmLeadEvent::where('lead_id', $gone->id)->where('kind', 'archived')->sole()->note);
        $this->assertSame(1, CrmLeadEvent::where('lead_id', $gone->id)->where('kind', 'note')->count(), 'the note OPS added is still on its timeline');
        $this->assertEqualsCanonicalizing([$gone->number, $this->odooLead(105)->number], array_column($this->expectOk($this->getAs('gm', '/api/crm/leads?state=archived&origin=odoo'))['items'], 'number'));
    }

    public function test_names_are_linked_by_identity_and_a_conversion_made_here_is_kept(): void
    {
        // OPS already has a tag, a reason and a stage under exactly these names: they are the same thing
        $tag = CrmTag::create(['name' => "مطعم {$this->tag}", 'color' => 1, 'active' => true]);
        $reason = CrmLostReason::create(['name_ar' => 'غالٍ', 'name_en' => 'too EXPENSIVE', 'active' => true]); // whatever the letter case
        $stage = CrmStage::create(['name_ar' => 'زيارة', 'name_en' => 'Visit '.$this->tag, 'sequence' => 77, 'is_won' => false, 'active' => true]);
        // … and an Odoo user that answers to two different OPS users answers to none
        $this->rec('res.users', 23, ['login' => $this->byLogin->username, 'partner_id' => [884, 'x']], true);
        $before = [CrmTag::count(), CrmLostReason::count(), CrmStage::count()];

        $plan = $this->projection()->plan();
        $this->assertSame([1, 1, 1, 1, 1, 3], [$plan['counts']['tags.linked'], $plan['counts']['tags.created'], $plan['counts']['reasons.linked'], $plan['counts']['reasons.created'], $plan['counts']['stages.linked'], $plan['counts']['stages.created']]);
        $applied = $this->projection()->apply();
        $this->assertEquals($plan['counts'], $applied['counts']);
        $this->assertSame([$before[0] + 1, $before[1] + 1, $before[2] + 3], [CrmTag::count(), CrmLostReason::count(), CrmStage::count()], 'adopted, not doubled');
        $this->assertSame(['odoo', '1', 9], [$tag->fresh()->source_system, $tag->fresh()->external_ref, $tag->fresh()->color]);
        $this->assertSame(['odoo', '1', 'السعر مرتفع'], [$reason->fresh()->source_system, $reason->fresh()->external_ref, $reason->fresh()->name_ar]);
        $this->assertSame(['odoo', '5', 'زيارة', 1], [$stage->fresh()->source_system, $stage->fresh()->external_ref, $stage->fresh()->name_en, $stage->fresh()->sequence]);
        $this->assertSame($stage->id, $this->odooLead(104)->stage_id);
        $this->assertSame([null, 'مندوب رابع'], [$this->odooLead(104)->owner_id, $this->odooLead(104)->owner_name], 'two users answer: nobody is guessed');

        // ── a won opportunity with no Odoo customer is converted HERE: that decision is OPS's and a later pass keeps it
        $this->odooWrites('crm.lead', 104, ['won_status' => 'won', 'stage_id' => [4, 'Won'], 'probability' => 100.0, 'date_closed' => '2026-09-20 10:00:00', 'partner_name' => "كافيه الركن {$this->tag}"]);
        $this->projection()->consume();
        $lead = $this->odooLead(104);
        $this->assertSame(['won', null], [$lead->status, $lead->customer_id]);
        $customers = Customer::count();
        $vat = '39'.str_pad($this->tag, 12, '0', STR_PAD_LEFT).'3';
        $done = $this->expectOk($this->postAs('sales', "/api/crm/leads/{$lead->id}/convert", ['confirmNew' => true, 'zone' => 'العليا', 'vatNo' => $vat]));
        $this->assertSame(['new', "كافيه الركن {$this->tag}", true], [$done['conversion']['by'], $done['customer']['nameAr'], $done['owned']]);
        $this->assertSame($customers + 1, Customer::count());
        $this->assertSame([$vat, null], [Customer::findOrFail($done['customer']['id'])->vat_no, $this->odooLead(104)->vat_no], 'the number is the new customer\'s; the lead is Odoo\'s and keeps what Odoo holds');
        $this->odooWrites('crm.lead', 104, ['expected_revenue' => 3300.0]);
        $this->projection()->consume();
        $after = $this->odooLead(104);
        $this->assertSame([$done['customer']['id'], '3300.00'], [$after->customer_id, $after->expected_revenue], 'Odoo\'s figure arrived, the customer made here stayed');
        // Odoo later names a partner that is another OPS customer: nobody is overruled — it is reported for a person
        $this->odooWrites('crm.lead', 104, ['partner_id' => [900, 'مطعم الواحة']]);
        $run = $this->projection()->consume();
        $this->assertSame(1, $run['counts']['leads.customerConflict']);
        $this->assertSame($done['customer']['id'], $this->odooLead(104)->customer_id);
        $whole = $this->projection()->plan();
        $this->assertStringContainsString($after->number, $whole['notes']['leads.customerConflict'][0]);
    }

    public function test_the_integration_finds_the_projection_and_its_command_loads_the_section(): void
    {
        // the class the mirror calls after every run (config odoo.projections) is this one, and the container can build it
        $this->assertSame(OdooCrmProjection::class, config('odoo.projections.crm'));
        $this->assertInstanceOf(OdooCrmProjection::class, $this->app->make(config('odoo.projections.crm')));

        // php artisan scm:odoo-project crm — the plan, then the first load
        $this->assertSame(0, Artisan::call('scm:odoo-project', ['area' => 'crm']));
        $plan = Artisan::output();
        $this->assertStringContainsString('PLAN (nothing changed)', $plan);
        $this->assertStringContainsString('"leads.created":5', $plan);
        $this->assertSame(0, CrmLead::where('source_system', 'odoo')->count());

        $this->assertSame(0, Artisan::call('scm:odoo-project', ['area' => 'crm', '--apply' => true]));
        $this->assertStringContainsString('APPLIED', Artisan::output());
        $this->assertSame(5, CrmLead::where('source_system', 'odoo')->count());

        // from then on the runs keep it current: a change of the feed, taken within the time a run gives
        $this->odooWrites('crm.lead', 101, ['expected_revenue' => 25000.0]);
        $run = $this->projection()->consume(500, 2.0);
        $this->assertSame(1, $run['changes']);
        $this->assertSame('25000.00', $this->odooLead(101)->expected_revenue);
    }

    public function test_the_projection_waits_for_a_complete_copy(): void
    {
        OdooModel::where('model', 'crm.lead')->update(['full_state' => 'running']);
        $p = $this->projection();
        $this->assertFalse($p->ready());
        $before = $this->tables();
        try {
            $p->plan();
            $this->fail('a plan on an incomplete copy must be refused');
        } catch (AppError $e) {
            $this->assertSame('ODOO_COPY_INCOMPLETE', $e->errorCode);
        }
        $this->assertSame($before, $this->tables());
    }
}
