<?php

namespace Tests\Feature\Credit;

use App\Credit\Models\CreditApproval;
use App\Credit\Models\CreditBankLine;
use App\Credit\Models\CreditEnvelope;
use App\Credit\Models\CreditFile;
use App\Credit\Models\CreditNotification;
use App\Credit\Models\CreditPaymentMethod;
use App\Credit\Models\CreditRequest;
use App\Credit\Models\CreditResponsible;
use App\Credit\Models\CreditStage;
use App\Credit\Models\CreditTermsVersion;
use App\Credit\OdooCreditProjection;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Services\ExternalRefs;
use App\Models\Customer;
use App\Support\AppError;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * From the copy of Odoo's credit module to the credit section (OdooCreditProjection). Odoo refuses the connection
 * user these records today, so no real record exists: the copy is built here by hand — rows of `odoo_models` /
 * `odoo_records` with EXACTLY the fields of docs/odoo/shapes/unreadable.txt — and Odoo is never called.
 * Proven: the plan writes nothing; the apply makes every record once and a second one changes nothing; links by
 * identity only (customer through int_external_refs, people by login, groups by name, stages and methods by code);
 * personal values encrypted and never in the trail; who owns what afterwards; a change and a deletion in Odoo; and
 * what the projection answers on a copy where the ten models are refused (the real copy today).
 */
class OdooCreditProjectionTest extends ApiTestCase
{
    use CreditFixtures;

    private int $o;

    private string $tag;

    private Customer $customer;

    /** The last base of Odoo ids a story of this class used (one run of the suite never reuses one). */
    private static ?int $base = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->followOdoo(true);
        $this->plainPolicies();
        // what an earlier story of this class left: the credit records of the copy, and what the projection made of them
        OdooRecord::where('model', 'like', 'b2b.credit.%')->delete();
        $requests = DB::table('credit_requests')->where('source_system', 'odoo')->pluck('id');
        foreach (['credit_approvals', 'credit_envelopes', 'credit_files', 'credit_events', 'credit_notifications', 'credit_responsibles', 'credit_signatories', 'credit_bank_lines', 'credit_request_methods'] as $table) {
            DB::table($table)->whereIn('request_id', $requests)->delete();
        }
        DB::table('credit_notifications')->where('source_system', 'odoo')->delete();
        DB::table('credit_requests')->whereIn('id', $requests)->delete();
        foreach (['credit_stages', 'credit_payment_methods', 'credit_terms_versions'] as $table) {
            DB::table($table)->where('source_system', 'odoo')->where('created_by', 'Odoo')->delete();
            DB::table($table)->where('source_system', 'odoo')->update(['source_system' => null, 'external_ref' => null]); // adopted rows are OPS's again
        }
        foreach (OdooCreditProjection::MODELS as $model) {
            OdooModel::updateOrCreate(['model' => $model], ['status' => 'active', 'full_state' => 'done', 'has_active' => in_array($model, ['b2b.credit.payment.method', 'b2b.credit.stage', 'b2b.credit.terms.version'], true)]);
        }
        // a base of Odoo ids of its own for each story: a random one could repeat one an earlier story already linked
        self::$base = (self::$base ?? random_int(10, 40)) + 1;
        $this->o = self::$base * 10000;
        $this->tag = self::tag();
        $this->customer = $this->customer(['source_system' => 'odoo']);
        app(ExternalRefs::class)->link('odoo', 'customer', (string) ($this->o + 900), $this->customer->id, $this->customer->code);
        $this->copy();
    }

    public function test_plan_writes_nothing_apply_makes_everything_once(): void
    {
        $before = [CreditRequest::count(), CreditStage::count(), CreditApproval::count(), DB::table('audit_logs')->count()];
        $plan = app(OdooCreditProjection::class)->plan();
        $this->assertTrue($plan['dryRun']);
        $this->assertSame(2, $plan['counts']['requests.created']);
        $this->assertSame(2, $plan['counts']['stages.created']);
        $this->assertSame(2, $plan['counts']['stages.linked'], 'the draft and the approved stage are the single ones of their state: adopted');
        $this->assertSame([CreditRequest::count(), CreditStage::count(), CreditApproval::count(), DB::table('audit_logs')->count()], $before, 'a plan writes nothing');

        $out = app(OdooCreditProjection::class)->apply();
        $this->assertArrayNotHasKey('failed', $out, json_encode($out, JSON_UNESCAPED_UNICODE));
        $r = CreditRequest::where('source_system', 'odoo')->where('external_ref', (string) ($this->o + 1))->sole();
        $review = CreditStage::where('source_system', 'odoo')->where('external_ref', (string) ($this->o + 11))->sole();
        $this->assertSame([$this->customer->id, 'in_review', $review->id, 'CR/2026/0001'], [$r->customer_id, $r->state, $r->stage_id, $r->source_name]);
        $this->assertSame(['75000.00', 45, '60000.00', '30 Days'], [$r->requested_credit_limit, $r->requested_payment_days, $r->approved_credit_limit, $r->approved_term]);
        $this->assertMatchesRegularExpression('/^CRA-\d{4}-\d{5}$/', $r->number);
        // people by login: the requester has no OPS user (kept by name and login); the approver is the OPS user gm
        $this->assertSame([null, "nobody{$this->tag}@corp.example", 'مندوب أودو'], [$r->requester_id, $r->requester_login, $r->requester_name]);
        // groups by name: "Finance" is an OPS role, "CEO Approver" is not — that stage waits for a person to name its role
        $this->assertSame('finance', $review->responsible_role);
        $ceo = CreditStage::where('source_system', 'odoo')->where('external_ref', (string) ($this->o + 12))->sole();
        $this->assertTrue($ceo->unmapped());
        $this->assertSame('B2B Credit / CEO Approver', $ceo->responsible_role_name);
        $this->assertSame([8.0, 2.0, 4.0, 'all_users'], [(float) $review->sla_hours, (float) $review->reminder_before_hours, (float) $review->escalate_after_hours, $review->approval_mode]);
        // the one draft stage is adopted, not doubled; a method of the same code is adopted
        $this->assertSame(1, CreditStage::where('active', true)->where('state', 'draft')->count());
        $this->assertSame((string) ($this->o + 10), CreditStage::where('active', true)->where('state', 'draft')->value('external_ref'));
        $method = CreditPaymentMethod::where('source_system', 'odoo')->sole();
        $this->assertSame(["pm_{$this->tag}", 'OPS', 'شيك'], [$method->code, $method->created_by, $method->name_ar]);
        $this->assertSame([$method->id], $r->paymentMethods()->pluck('credit_payment_methods.id')->all());
        // the terms the customer accepted, frozen; Odoo's html as text
        $terms = CreditTermsVersion::where('source_system', 'odoo')->sole();
        $this->assertSame(['published', 'البند الأول'], [$terms->status, $r->terms_snapshot['termsAr']]);
        $this->assertSame($terms->id, $r->terms_version_id);

        // personal values: kept encrypted, never in the audit trail
        $this->assertSame('1055555555', $r->owner_national_id);
        $this->assertNotSame('1055555555', DB::table('credit_requests')->where('id', $r->id)->value('owner_national_id'));
        $this->assertSame('2066666666', CreditResponsible::where('request_id', $r->id)->sole()->national_id);
        $this->assertSame('SA0380000000608010167519', CreditBankLine::where('request_id', $r->id)->sole()->iban);
        $trail = DB::table('audit_logs')->where('username', 'svc.odoo')->get()->toJson();
        foreach (['1055555555', '2066666666', 'SA0380000000608010167519', '9988776655'] as $secret) {
            $this->assertStringNotContainsString($secret, $trail);
        }

        // the papers: a row without bytes, its content the Odoo backup's attachment
        $cr = CreditFile::where('request_id', $r->id)->where('slot', 'commercial_registration')->sole();
        $this->assertSame(['odoo', 'cr.pdf', (string) ($this->o + 70), 'current'], [$cr->storage, $cr->name, $cr->attachment_ref, $cr->status]);
        $this->assertSame(1, CreditFile::where('request_id', $r->id)->where('slot', 'owner_id')->count(), 'a filled slot without a file name is still a paper');
        // the approval audit, the envelope, the log
        $lines = CreditApproval::where('request_id', $r->id)->orderBy('external_ref')->get();
        $gm = DB::table('users')->where('username', 'gm')->value('id');
        $this->assertSame(['approved', $gm, 'pending'], [$lines[0]->state, $lines[0]->decided_by_id, $lines[1]->state]);
        $env = CreditEnvelope::where('request_id', $r->id)->sole();
        $this->assertSame(['signed', 'signit'], [$env->status, $env->provider]);
        $this->assertSame('signed_agreement', CreditFile::find($env->signed_file_id)->slot);
        $this->assertSame(['email', 'skipped'], [CreditNotification::where('source_system', 'odoo')->sole()->channel, CreditNotification::where('source_system', 'odoo')->sole()->status]);
        // the second request: its partner is linked to no OPS customer — kept, said
        $this->assertNull(CreditRequest::where('external_ref', (string) ($this->o + 2))->where('source_system', 'odoo')->value('customer_id'));
        $this->assertSame(1, $out['counts']['requests.noCustomer']);

        // idempotent
        $counts = [CreditRequest::count(), CreditApproval::count(), CreditFile::count(), CreditResponsible::count(), DB::table('credit_events')->count()];
        $again = app(OdooCreditProjection::class)->apply();
        $this->assertSame(2, $again['counts']['requests.unchanged']);
        $this->assertArrayNotHasKey('requests.updated', $again['counts']);
        $this->assertSame($counts, [CreditRequest::count(), CreditApproval::count(), CreditFile::count(), CreditResponsible::count(), DB::table('credit_events')->count()]);
    }

    public function test_odoo_owns_its_records_until_the_section_is_handed_over(): void
    {
        app(OdooCreditProjection::class)->apply();
        $r = CreditRequest::where('source_system', 'odoo')->where('external_ref', (string) ($this->o + 1))->sole();
        $this->expectRejected($this->patchAs('admin', "/api/credit/requests/{$r->id}", ['city' => 'جدة']), 'CREDIT_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('admin', "/api/credit/requests/{$r->id}/approve"), 'CREDIT_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('admin', "/api/credit/requests/{$r->id}/documents", ['slot' => 'vat_certificate', 'fileName' => 'v.pdf', 'base64' => self::pdf()]), 'CREDIT_OWNED_BY_SOURCE', [422]);
        // what is OPS's own stays open: a comment, an extra paper
        $this->expectOk($this->postAs('admin', "/api/credit/requests/{$r->id}/comments", ['text' => 'ملاحظة داخلية']));
        $this->expectOk($this->postAs('admin', "/api/credit/requests/{$r->id}/documents", ['slot' => 'other', 'fileName' => 'extra.pdf', 'base64' => self::pdf()]));
        $d = $this->request($r->id);
        $this->assertSame(['odoo', true, false], [$d['origin'], $d['ownedBySource'], $d['can']['edit']]);
        // a stage of Odoo: only the OPS role its group answers to is set here
        $ceo = CreditStage::where('source_system', 'odoo')->where('external_ref', (string) ($this->o + 12))->sole();
        $this->expectRejected($this->patchAs('admin', "/api/credit/stages/{$ceo->id}", ['nameAr' => 'اسم آخر']), 'CREDIT_OWNED_BY_SOURCE', [422]);
        $s = $this->expectOk($this->patchAs('admin', "/api/credit/stages/{$ceo->id}", ['responsibleRole' => 'gm']));
        $this->assertFalse($s['unmapped']);
        // a later pass keeps the role a person set (Odoo's group still has no OPS role)
        $this->odooWrites('b2b.credit.stage', $this->o + 12, ['sla_hours' => 24.0]);
        app(OdooCreditProjection::class)->apply();
        $this->assertSame(['gm', 24.0], [CreditStage::find($ceo->id)->responsible_role, (float) CreditStage::find($ceo->id)->sla_hours]);
        $terms = CreditTermsVersion::where('source_system', 'odoo')->sole();
        $this->expectRejected($this->postAs('admin', "/api/credit/terms/{$terms->id}/retire"), 'CREDIT_OWNED_BY_SOURCE', [422]);

        // handed over: everything is OPS's
        $this->followOdoo(false);
        $this->expectOk($this->patchAs('admin', "/api/credit/requests/{$r->id}", ['city' => 'جدة']));
        $this->assertSame('جدة', CreditRequest::find($r->id)->city);
    }

    public function test_a_change_and_a_deletion_in_odoo_reach_the_section(): void
    {
        app(OdooCreditProjection::class)->apply();
        $r = CreditRequest::where('source_system', 'odoo')->where('external_ref', (string) ($this->o + 1))->sole();
        $events = DB::table('credit_events')->where('request_id', $r->id)->count();
        $this->odooWrites('b2b.credit.request', $this->o + 1, ['state' => 'approved', 'stage_id' => [$this->o + 13, 'Approved'], 'filename_commercial_registration' => 'cr-2026.pdf', 'owner_national_id' => '1044444444']);
        $this->odooWrites('b2b.credit.responsible.line', $this->o + 41, [], deleted: true);
        $this->odooWrites('b2b.credit.approval', $this->o + 51, [], deleted: true);
        $out = app(OdooCreditProjection::class)->apply();
        $r = CreditRequest::find($r->id);
        $this->assertSame(['approved', '1044444444'], [$r->state, $r->owner_national_id]);
        $this->assertNotNull($r->approved_at);
        $this->assertSame($events + 1, DB::table('credit_events')->where('request_id', $r->id)->count());
        $this->assertTrue(DB::table('status_history')->where('entity_id', $r->id)->where('to_status', 'approved')->exists());
        $this->assertSame(['replaced', 'current'], CreditFile::where('request_id', $r->id)->where('slot', 'commercial_registration')->orderBy('created_at')->orderBy('id')->pluck('status')->all());
        $this->assertSame(0, CreditResponsible::where('request_id', $r->id)->count(), 'a line of the form taken off in Odoo is taken off here');
        $this->assertSame(2, CreditApproval::where('request_id', $r->id)->count(), 'the approval audit is never removed');
        $this->assertSame(1, $out['counts']['approvals.deletedInOdooKept']);
        $this->assertStringNotContainsString('1044444444', DB::table('audit_logs')->where('entity_id', $r->id)->get()->toJson());
    }

    public function test_the_real_copy_today_refuses_the_ten_models(): void
    {
        OdooModel::whereIn('model', OdooCreditProjection::MODELS)->update(['status' => 'skipped', 'full_state' => 'pending', 'reason' => 'no read access for the Odoo user']);
        try {
            app(OdooCreditProjection::class)->plan();
            $this->fail('a plan on a copy without the credit records must say so');
        } catch (AppError $e) {
            $this->assertSame('ODOO_COPY_INCOMPLETE', $e->errorCode);
        }
    }

    // ───────────── the copy ─────────────

    private function copy(): void
    {
        $o = $this->o;
        $t = $this->tag;
        // an OPS payment method of the same code: adopted
        CreditPaymentMethod::create(['code' => "pm_{$t}", 'name_ar' => 'شيك', 'name_en' => 'Cheque', 'sequence' => 50, 'active' => true, 'created_by' => 'OPS']);
        $this->rec('res.users', $o + 90, ['login' => 'gm', 'partner_id' => false], 'المدير العام');
        $this->rec('res.users', $o + 91, ['login' => "nobody{$t}@corp.example", 'partner_id' => false], 'مندوب أودو');
        $this->rec('b2b.credit.payment.method', $o + 1, ['name' => 'Cheque', 'code' => "pm_{$t}", 'sequence' => 20, 'active' => true]);
        $this->rec('b2b.credit.terms.version', $o + 1, ['name' => 'Credit terms', 'version' => "odoo-{$t}", 'active' => true, 'effective_date' => '2025-01-01',
            'terms_ar' => '<p>البند الأول</p>', 'terms_en' => '<p>Clause one</p>', 'jurisdiction_city' => 'Riyadh', 'company_id' => [1, 'B2B'], 'create_date' => '2025-01-01 08:00:00']);
        $stage = fn (int $id, string $code, string $name, string $state, array $more = []) => $this->rec('b2b.credit.stage', $id, $more + [
            'name' => $name, 'code' => $code, 'sequence' => $id - $o, 'active' => true, 'fold' => false, 'state_mapping' => $state, 'responsible_group_id' => false, 'responsible_user_id' => false,
            'approval_mode' => 'any_user', 'sla_hours' => 0.0, 'reminder_before_deadline_hours' => 0.0, 'escalate_after_deadline_hours' => 0.0, 'escalation_group_id' => false,
            'require_documents' => false, 'require_signature' => false, 'auto_send_signit' => false, 'lock_request_fields' => false, 'portal_visible' => false, 'company_id' => [1, 'B2B'],
        ]);
        $stage($o + 10, "draft_{$t}", 'Draft', 'draft');
        $stage($o + 11, "finance_{$t}", 'Finance Review', 'in_review', ['responsible_group_id' => [145, 'B2B Credit / Finance'], 'approval_mode' => 'all_users', 'sla_hours' => 8.0,
            'reminder_before_deadline_hours' => 2.0, 'escalate_after_deadline_hours' => 4.0, 'escalation_group_id' => [148, 'B2B Credit / Manager'], 'require_documents' => true]);
        $stage($o + 12, "ceo_{$t}", 'CEO Approval', 'ceo_approval', ['responsible_group_id' => [147, 'B2B Credit / CEO Approver']]);
        $stage($o + 13, "approved_{$t}", 'Approved', 'approved', ['fold' => true]);
        $files = [];
        foreach (['commercial_registration', 'vat_certificate', 'national_address', 'chamber_certificate', 'owner_id', 'authorized_signatory_ids', 'official_authorization', 'recent_bank_statement', 'other'] as $slot) {
            $files["file_{$slot}"] = false;
            $files["filename_{$slot}"] = false;
        }
        $request = fn (int $id, array $more) => $this->rec('b2b.credit.request', $id, $more + $files + [
            'access_url' => "/my/credit/{$id}", 'access_token' => 'SECRET-TOKEN-'.$id, 'access_warning' => false, 'request_date' => '2026-03-01', 'source' => 'portal',
            'commercial_partner_id' => false, 'company_id' => [1, 'B2B'], 'currency_id' => [148, 'SAR'], 'assigned_user_id' => [$o + 91, 'مندوب أودو'], 'current_group_id' => [145, 'B2B Credit / Finance'],
            'submitted_at' => '2026-03-01 09:00:00', 'stage_entered_at' => '2026-03-02 09:00:00', 'sla_deadline' => '2026-03-03 09:00:00', 'sla_status' => 'late', 'last_reminder_at' => false,
            'sla_last_checked_at' => false, 'escalation_count' => 0, 'company_name' => "مطاعم أودو {$t}", 'commercial_registration' => '1010998877', 'business_activity' => 'Restaurants',
            'legal_entity_type' => 'LLC', 'owner_name' => 'مالك أودو', 'owner_national_id' => '1055555555', 'phone' => false, 'mobile' => '0550000000', 'email' => 'odoo@customer.example',
            'website' => false, 'po_box' => false, 'postal_code' => '12345', 'street' => 'King Fahd Rd', 'district' => 'Olaya', 'building_no' => '1234', 'city' => 'Riyadh',
            'requested_credit_limit' => 75000.0, 'requested_payment_days' => 45, 'monthly_purchase_volume' => 30000.0, 'approved_credit_limit' => 60000.0, 'approved_payment_days' => 30,
            'approved_payment_term_id' => [4, '30 Days'], 'approved_payment_term_snapshot' => '30 Days', 'payment_method_ids' => [$o + 1], 'finance_recommendation' => '<p>Good payer</p>',
            'legal_notes' => false, 'ceo_notes' => false, 'rejection_reason' => false, 'changes_requested_note' => false, 'responsible_line_ids' => [], 'bank_line_ids' => [],
            'authorization_line_ids' => [], 'approval_line_ids' => [], 'signit_envelope_ids' => [], 'notification_log_ids' => [], 'terms_version_id' => [$o + 1, 'Credit terms'],
            'attachments_complete' => false, 'can_submit' => false, 'can_approve' => true, 'can_edit_finance' => true, 'is_portal_editable' => false, 'signit_status' => 'signed',
            'approved_agreement_attachment_id' => false, 'create_date' => '2026-03-01 08:00:00', 'create_uid' => [$o + 91, 'مندوب أودو'],
        ]);
        $request($o + 1, ['name' => 'CR/2026/0001', 'partner_id' => [$o + 900, $this->customer->name_ar], 'stage_id' => [$o + 11, 'Finance Review'], 'state' => 'in_review',
            'file_commercial_registration' => true, 'filename_commercial_registration' => 'cr.pdf', 'file_owner_id' => true]);
        $request($o + 2, ['name' => 'CR/2026/0002', 'partner_id' => [$o + 901, 'عميل بلا ارتباط'], 'stage_id' => [$o + 10, 'Draft'], 'state' => 'draft', 'owner_national_id' => '9988776655']);
        $this->rec('ir.attachment', $o + 70, ['name' => 'cr.pdf', 'res_model' => 'b2b.credit.request', 'res_id' => $o + 1, 'res_field' => 'file_commercial_registration', 'mimetype' => 'application/pdf', 'file_size' => 2048]);
        $this->rec('b2b.credit.responsible.line', $o + 41, ['request_id' => [$o + 1, 'CR/2026/0001'], 'name' => 'مسؤول أودو', 'position' => 'Buyer', 'national_id' => '2066666666', 'mobile' => '0551111111']);
        $this->rec('b2b.credit.authorization.line', $o + 42, ['request_id' => [$o + 1, 'CR/2026/0001'], 'name' => 'مفوض أودو', 'job_title' => 'GM', 'mobile' => '0552222222', 'signature_note' => false]);
        $this->rec('b2b.credit.bank.line', $o + 43, ['request_id' => [$o + 1, 'CR/2026/0001'], 'bank_name' => 'SNB', 'account_number' => '0011223344', 'branch' => 'Olaya', 'iban' => 'sa03 8000 0000 6080 1016 7519', 'swift' => 'NCBKSAJE']);
        $line = fn (int $id, array $more) => $this->rec('b2b.credit.approval', $id, $more + ['request_id' => [$o + 1, 'CR/2026/0001'], 'stage_id' => [$o + 11, 'Finance Review'], 'group_id' => [145, 'B2B Credit / Finance'],
            'user_id' => false, 'deadline' => '2026-03-03 09:00:00', 'approved_by' => false, 'approved_at' => false, 'note' => false, 'activity_id' => false, 'reminder_count' => 1,
            'last_reminder_at' => '2026-03-03 07:00:00', 'escalated_at' => false, 'company_id' => [1, 'B2B']]);
        $line($o + 51, ['state' => 'approved', 'approved_by' => [$o + 90, 'المدير العام'], 'approved_at' => '2026-03-02 12:00:00', 'note' => '<p>ok</p>']);
        $line($o + 52, ['state' => 'pending']);
        $this->rec('b2b.credit.signit.envelope', $o + 61, ['request_id' => [$o + 1, 'CR/2026/0001'], 'external_envelope_id' => 'SIG-778', 'status' => 'signed', 'signer_partner_id' => false,
            'signer_name' => 'مفوض أودو', 'signer_email' => 'sign@customer.example', 'signer_mobile' => false, 'sent_at' => '2026-03-02 10:00:00', 'signed_at' => '2026-03-02 15:00:00',
            'signed_pdf_attachment_id' => [$o + 71, 'agreement-signed.pdf'], 'last_payload' => false, 'error_message' => false, 'company_id' => [1, 'B2B']]);
        $this->rec('b2b.credit.notification.log', $o + 81, ['request_id' => [$o + 1, 'CR/2026/0001'], 'channel' => 'email', 'recipient_partner_id' => [$o + 900, 'عميل'], 'recipient_user_id' => false,
            'recipient_value' => 'odoo@customer.example', 'template_code' => 'request.submitted', 'status' => 'skipped', 'sent_at' => false, 'error_message' => 'no mail server', 'payload' => false]);
    }

    private function rec(string $model, int $id, array $data, ?string $display = null): void
    {
        $data += ['write_date' => '2026-09-01 08:00:00'];
        $active = array_key_exists('active', $data) ? (bool) $data['active'] : null;
        OdooRecord::updateOrCreate(['model' => $model, 'odoo_id' => $id], ['display_name' => $display ?? (is_string($data['name'] ?? null) ? $data['name'] : null), 'write_date' => $data['write_date'],
            'active' => $active, 'data' => $data, 'hash' => sha1(json_encode($data)), 'first_seen_at' => now(), 'changed_at' => now(), 'deleted_at' => null]);
    }

    private function odooWrites(string $model, int $id, array $values, bool $deleted = false): void
    {
        $r = OdooRecord::where('model', $model)->where('odoo_id', $id)->sole();
        $data = array_merge((array) $r->data, $values, ['write_date' => '2026-09-10 08:00:00']);
        $r->update(['data' => $data, 'write_date' => $data['write_date'], 'hash' => sha1(json_encode($data)), 'changed_at' => now(), 'deleted_at' => $deleted ? now() : null]);
    }
}
