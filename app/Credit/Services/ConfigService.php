<?php

namespace App\Credit\Services;

use App\Credit\Models\CreditPaymentMethod;
use App\Credit\Models\CreditRequest;
use App\Credit\Models\CreditStage;
use App\Credit\Models\CreditTermsVersion;
use App\Credit\Notifications\EmailGateway;
use App\Credit\Notifications\WhatsAppGateway;
use App\Credit\Support\Access;
use App\Credit\Support\BusinessClock;
use App\Credit\Support\Flow;
use App\Credit\Support\Owned;
use App\Credit\Support\People;
use App\Credit\Support\Settings;
use App\Credit\Support\Slots;
use App\Credit\Support\Text;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

/**
 * What the credit section is made of, set by its manager (`credit.manage`): the stages of the workflow, the versions
 * of the terms, the contractual payment methods and the section's policies — and the lookups every page of the
 * section reads.
 *
 * THE WORKFLOW IS DATA, AND IT IS CHECKED AS DATA. A stage names a state; the five states a request is SENT to (draft,
 * approved, rejected, changes requested, cancelled) have exactly one active stage each; a stage names an OPS role and
 * / or an active user that exist; target hours, the reminder before them and the escalation after them are coherent.
 * A stage a request stands in is not archived. Nothing is deleted: stages, versions and methods are archived.
 *
 * A record that came from Odoo is Odoo's while the section follows it: only the OPS role its Odoo group answers to may
 * be set here (CREDIT_OWNED_BY_SOURCE for the rest).
 *
 * TERMS. A version is written as a draft and published once: from then on its text is frozen (its hash is kept) and a
 * correction is a new version. A request keeps the version its customer accepted; publishing or retiring never
 * rewrites it.
 */
class ConfigService
{
    /** Stage form: camelCase key → column (booleans and numbers are converted apart). */
    private const STAGE_FLAGS = [
        'fold' => 'fold', 'requireDocuments' => 'require_documents', 'requireSignature' => 'require_signature', 'autoSendSignature' => 'auto_send_signature',
        'lockRequestFields' => 'lock_request_fields', 'portalVisible' => 'portal_visible',
    ];

    private const STAGE_HOURS = ['slaHours' => 'sla_hours', 'reminderBeforeHours' => 'reminder_before_hours', 'escalateAfterHours' => 'escalate_after_hours'];

    public function __construct(
        private readonly CreditLog $log,
        private readonly People $people,
        private readonly Settings $settings,
        private readonly SignatureService $signature,
        private readonly WorkflowEngine $engine,
    ) {}

    // ───────────── lookups ─────────────

    public function lookups(AuthUser $u): array
    {
        $inForce = AgreementService::termsInForce();

        return [
            'states' => array_map(fn (string $s) => ['value' => $s, 'labelAr' => Flow::label($s)[0], 'labelEn' => Flow::label($s)[1], 'work' => Flow::isWork($s), 'final' => Flow::isFinal($s)], Flow::STATES),
            'stages' => CreditStage::ordered()->get()->map(fn (CreditStage $s) => $this->stage($s))->values()->all(),
            'path' => $this->engine->path()->map(fn (CreditStage $s) => $s->brief())->values()->all(),
            'paymentMethods' => CreditPaymentMethod::ordered()->get()->map(fn (CreditPaymentMethod $m) => $m->brief() + ['active' => (bool) $m->active, 'sequence' => (int) $m->sequence,
                'origin' => $m->source_system === Owned::SYSTEM ? 'odoo' : 'ops'])->values()->all(),
            'roles' => $this->people->roles(),
            'slots' => array_map(fn (string $slot) => ['slot' => $slot, 'labelAr' => Slots::label($slot)[0], 'labelEn' => Slots::label($slot)[1], 'many' => in_array($slot, Slots::MANY, true),
                'required' => in_array($slot, $this->settings->requiredSlots(), true)], Slots::keys()),
            'termsInForce' => $inForce ? ['id' => $inForce->id, 'name' => $inForce->name, 'version' => $inForce->version, 'effectiveDate' => $inForce->effective_date?->toDateString()] : null,
            'signatureProvider' => $this->signature->provider(),
            'channels' => ['inApp' => true, 'email' => app(EmailGateway::class)->configured(), 'whatsapp' => app(WhatsAppGateway::class)->configured()],
            'settings' => $this->settings->all(),
            'followsOdoo' => Owned::following(),
            'can' => ['request' => $u->can('credit.request'), 'review' => $u->can('credit.review'), 'approve' => $u->can('credit.approve'), 'manage' => Access::manages($u)],
        ];
    }

    // ───────────── stages ─────────────

    public function stage(CreditStage $s): array
    {
        return $s->brief() + [
            'sequence' => (int) $s->sequence, 'active' => (bool) $s->active, 'fold' => (bool) $s->fold, 'work' => $s->isWork(),
            'responsibleRole' => $this->people->role($s->responsible_role, $s->responsible_role_name), 'responsibleUser' => $this->people->present($s->responsible_user_id, $s->responsible_user_name, $s->responsible_user_login),
            'approvalMode' => $s->approval_mode, 'slaHours' => Text::hours($s->sla_hours), 'reminderBeforeHours' => Text::hours($s->reminder_before_hours), 'escalateAfterHours' => Text::hours($s->escalate_after_hours),
            'escalationRole' => $this->people->role($s->escalation_role, $s->escalation_role_name),
            'requireDocuments' => (bool) $s->require_documents, 'requireSignature' => (bool) $s->require_signature, 'autoSendSignature' => (bool) $s->auto_send_signature,
            'lockRequestFields' => (bool) $s->lock_request_fields, 'portalVisible' => (bool) $s->portal_visible, 'unmapped' => $s->unmapped(),
            'origin' => $s->source_system === Owned::SYSTEM ? 'odoo' : 'ops', 'ownedBySource' => Owned::bySource($s),
            'requests' => CreditRequest::where('stage_id', $s->id)->whereNotIn('state', Flow::FINAL)->count(),
        ];
    }

    public function createStage(AuthUser $u, array $dto): array
    {
        $s = DB::transaction(function () use ($u, $dto) {
            $code = $this->code($dto['code'] ?? null);
            if (CreditStage::where('code', $code)->exists()) {
                throw AppError::conflict('CREDIT_STAGE_CODE_TAKEN', "الرمز {$code} مستخدم لمرحلة أخرى", "Code {$code} is used by another stage");
            }
            $s = new CreditStage;
            $s->forceFill(['code' => $code, 'active' => true, 'approval_mode' => 'any_user', 'created_by' => $u->nameAr]);
            $this->fillStage($s, $dto, true);
            $this->assertStage($s);
            $s->save();
            $this->log->config($u, 'STAGE.CREATE', 'CreditStage', $s->id, $s->code, null, $this->stageFacts($s));

            return $s;
        });

        return $this->stage($s);
    }

    public function updateStage(AuthUser $u, string $id, array $dto): array
    {
        $s = DB::transaction(function () use ($u, $id, $dto) {
            $s = CreditStage::where('id', $id)->orWhere('code', $id)->lockForUpdate()->first()
                ?? throw AppError::notFound('CREDIT_STAGE_NOT_FOUND', 'المرحلة غير موجودة', 'Stage not found');
            if (Owned::bySource($s)) {
                // Odoo's stage: the OPS roles its groups answer to are OPS's own — nothing else
                $foreign = array_diff(array_keys($dto), ['responsibleRole', 'escalationRole']);
                if ($foreign) {
                    throw Owned::error($s, 'stage');
                }
            }
            $before = $this->stageFacts($s);
            $wasActive = (bool) $s->active;
            if (array_key_exists('code', $dto)) {
                $code = $this->code($dto['code']);
                if ($code !== $s->code && CreditStage::where('code', $code)->where('id', '!=', $s->id)->exists()) {
                    throw AppError::conflict('CREDIT_STAGE_CODE_TAKEN', "الرمز {$code} مستخدم لمرحلة أخرى", "Code {$code} is used by another stage");
                }
                $s->code = $code;
            }
            $this->fillStage($s, $dto, false);
            if (array_key_exists('active', $dto)) {
                $s->active = (bool) $dto['active'];
            }
            if (array_key_exists('state', $dto) && $s->getOriginal('state') !== $s->state && CreditRequest::where('stage_id', $s->id)->whereNotIn('state', Flow::FINAL)->exists()) {
                throw AppError::rule('CREDIT_STAGE_IN_USE', "طلبات مفتوحة تقف في مرحلة «{$s->name_ar}» — لا تتغيّر حالتها", "Open requests stand in stage \"{$s->name_en}\" — its state does not change");
            }
            if ($wasActive && ! $s->active && CreditRequest::where('stage_id', $s->id)->whereNotIn('state', Flow::FINAL)->exists()) {
                throw AppError::rule('CREDIT_STAGE_IN_USE', "طلبات مفتوحة تقف في مرحلة «{$s->name_ar}» — لا تؤرشف قبل أن تغادرها", "Open requests stand in stage \"{$s->name_en}\" — it is not archived before they leave it");
            }
            $this->assertStage($s);
            if (! $s->isDirty()) {
                return $s;
            }
            $s->save();
            $after = $this->stageFacts($s);
            $this->log->config($u, ! $s->active && $wasActive ? 'STAGE.ARCHIVE' : 'STAGE.UPDATE', 'CreditStage', $s->id, $s->code, array_diff_assoc($before, $after), array_diff_assoc($after, $before));

            return $s;
        });

        return $this->stage($s);
    }

    private function fillStage(CreditStage $s, array $dto, bool $new): void
    {
        foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en'] as $key => $column) {
            if (array_key_exists($key, $dto)) {
                $s->setAttribute($column, Text::clean($dto[$key], 120));
            }
        }
        if ($new && $s->name_en === null) {
            $s->name_en = $s->name_ar;
        }
        if (array_key_exists('state', $dto)) {
            $s->state = (string) $dto['state'];
        }
        if (array_key_exists('sequence', $dto)) {
            $s->sequence = (int) $dto['sequence'];
        }
        if (array_key_exists('approvalMode', $dto)) {
            $s->approval_mode = (string) $dto['approvalMode'];
        }
        foreach (self::STAGE_FLAGS as $key => $column) {
            if (array_key_exists($key, $dto)) {
                $s->setAttribute($column, (bool) $dto[$key]);
            } elseif ($new) {
                $s->setAttribute($column, false);
            }
        }
        foreach (self::STAGE_HOURS as $key => $column) {
            if (array_key_exists($key, $dto)) {
                $s->setAttribute($column, Text::hours($dto[$key]));
            }
        }
        if (array_key_exists('responsibleRole', $dto)) {
            $role = Text::clean($dto['responsibleRole'], 60);
            if ($role !== null && ! $this->people->roleExists($role)) {
                throw AppError::validation('CREDIT_ROLE_UNKNOWN', "الدور {$role} غير موجود", "Role {$role} does not exist", [['path' => 'responsibleRole', 'message' => $role]]);
            }
            $s->responsible_role = $role;
        }
        if (array_key_exists('escalationRole', $dto)) {
            $role = Text::clean($dto['escalationRole'], 60);
            if ($role !== null && ! $this->people->roleExists($role)) {
                throw AppError::validation('CREDIT_ROLE_UNKNOWN', "الدور {$role} غير موجود", "Role {$role} does not exist", [['path' => 'escalationRole', 'message' => $role]]);
            }
            $s->escalation_role = $role;
        }
        if (array_key_exists('responsibleUserId', $dto)) {
            $key = Text::clean($dto['responsibleUserId'], 60);
            if ($key === null) {
                $s->forceFill(['responsible_user_id' => null, 'responsible_user_login' => null, 'responsible_user_name' => null]);
            } else {
                $user = $this->people->find($key) ?? throw AppError::validation('CREDIT_USER_UNKNOWN', 'المستخدم غير موجود أو غير نشط', 'The user does not exist or is inactive', [['path' => 'responsibleUserId', 'message' => $key]]);
                $s->forceFill(['responsible_user_id' => $user->id, 'responsible_user_login' => null, 'responsible_user_name' => $user->name_ar]);
            }
        }
    }

    /** The rules a stage obeys, whoever wrote it. */
    private function assertStage(CreditStage $s): void
    {
        $errors = [];
        if (trim((string) $s->name_ar) === '') {
            $errors[] = ['path' => 'nameAr', 'message' => 'required'];
        }
        if (! in_array($s->state, Flow::STATES, true)) {
            $errors[] = ['path' => 'state', 'message' => 'one of '.implode(', ', Flow::STATES)];
        }
        if (! in_array($s->approval_mode, Flow::APPROVAL_MODES, true)) {
            $errors[] = ['path' => 'approvalMode', 'message' => 'any_user | all_users'];
        }
        $sla = $s->sla_hours;
        foreach (['slaHours' => $sla, 'reminderBeforeHours' => $s->reminder_before_hours, 'escalateAfterHours' => $s->escalate_after_hours] as $path => $v) {
            if ($v !== null && ($v < 0 || $v > 2000)) {
                $errors[] = ['path' => $path, 'message' => '0..2000'];
            }
        }
        if ($errors) {
            throw AppError::validation('CREDIT_STAGE_INVALID', 'بيانات المرحلة غير صحيحة', 'The stage is not valid', $errors);
        }
        if (($s->reminder_before_hours !== null || $s->escalate_after_hours !== null) && ! ($sla > 0)) {
            throw AppError::validation('CREDIT_STAGE_SLA', 'التذكير والتصعيد يُحسبان من مهلة المرحلة — حدّد المهلة أولًا', 'The reminder and the escalation are counted from the target time — set it first', [['path' => 'slaHours', 'message' => 'required']]);
        }
        if ($s->reminder_before_hours !== null && $sla > 0 && $s->reminder_before_hours >= $sla) {
            throw AppError::validation('CREDIT_STAGE_SLA', 'التذكير قبل انتهاء المهلة يجب أن يكون أقل من المهلة نفسها', 'The reminder before the deadline must be shorter than the target time', [['path' => 'reminderBeforeHours', 'message' => '< slaHours']]);
        }
        if ($s->require_signature && ! $s->isWork()) {
            throw AppError::validation('CREDIT_STAGE_SIGNATURE', 'يُطلب التوقيع في مرحلة مراجعة فقط', 'A signature is required only in a working stage', [['path' => 'requireSignature', 'message' => 'work stage']]);
        }
        if ($s->auto_send_signature && ! $s->require_signature) {
            throw AppError::validation('CREDIT_STAGE_SIGNATURE', 'الإرسال التلقائي للتوقيع يتبع مرحلة تتطلب التوقيع', 'Sending the agreement by itself belongs to a stage that requires the signature', [['path' => 'autoSendSignature', 'message' => 'requireSignature']]);
        }
        if ($s->active && in_array($s->state, Flow::SINGLE, true)) {
            $twin = CreditStage::where('active', true)->where('state', $s->state)->when($s->exists, fn ($q) => $q->where('id', '!=', $s->id))->value('code');
            if ($twin) {
                throw AppError::conflict('CREDIT_STAGE_STATE_TAKEN', 'للحالة «'.Flow::label($s->state)[0]."» مرحلة نشطة أخرى ({$twin}) — يُرسل الطلب إلى مرحلة واحدة لكل حالة من هذه", 'State "'.Flow::label($s->state)[1]."\" has another active stage ({$twin}) — a request is sent to one stage of such a state");
            }
        }
        if (! $s->active && $s->exists && in_array($s->getOriginal('state'), Flow::SINGLE, true) && $s->getOriginal('active')
            && ! CreditStage::where('active', true)->where('state', $s->getOriginal('state'))->where('id', '!=', $s->id)->exists()) {
            throw AppError::rule('CREDIT_STAGE_REQUIRED', 'هذه المرحلة الوحيدة لحالتها — لا تؤرشف قبل أن تحل محلها أخرى', 'This is the only stage of its state — it is not archived before another one takes its place');
        }
    }

    private function stageFacts(CreditStage $s): array
    {
        return array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : (string) $v, [
            'code' => $s->code, 'nameAr' => $s->name_ar, 'nameEn' => $s->name_en, 'state' => $s->state, 'sequence' => $s->sequence, 'active' => (bool) $s->active,
            'responsibleRole' => $s->responsible_role, 'responsibleUserId' => $s->responsible_user_id, 'approvalMode' => $s->approval_mode, 'slaHours' => $s->sla_hours,
            'reminderBeforeHours' => $s->reminder_before_hours, 'escalateAfterHours' => $s->escalate_after_hours, 'escalationRole' => $s->escalation_role,
            'requireDocuments' => (bool) $s->require_documents, 'requireSignature' => (bool) $s->require_signature, 'autoSendSignature' => (bool) $s->auto_send_signature,
            'lockRequestFields' => (bool) $s->lock_request_fields, 'portalVisible' => (bool) $s->portal_visible, 'fold' => (bool) $s->fold,
        ]);
    }

    private function code(mixed $v): string
    {
        $code = mb_strtolower(trim((string) $v));
        if (! preg_match('/^[a-z][a-z0-9_]{1,39}$/', $code)) {
            throw AppError::validation('CREDIT_CODE_INVALID', 'الرمز: حروف إنجليزية صغيرة وأرقام وشرطة سفلية، من حرفين إلى 40', 'Code: lower-case letters, digits and underscores, 2 to 40', [['path' => 'code', 'message' => 'invalid']]);
        }

        return $code;
    }

    // ───────────── terms versions ─────────────

    public function terms(): array
    {
        $inForce = AgreementService::termsInForce();

        return ['items' => CreditTermsVersion::orderByDesc('effective_date')->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(fn (CreditTermsVersion $t) => $this->termsShape($t, $inForce?->id))->values()->all()];
    }

    public function createTerms(AuthUser $u, array $dto): array
    {
        $t = DB::transaction(function () use ($u, $dto) {
            $t = new CreditTermsVersion;
            $t->forceFill(['status' => 'draft', 'created_by' => $u->nameAr]);
            $this->fillTerms($t, $dto);
            $t->save();
            $this->log->config($u, 'TERMS.CREATE', 'CreditTermsVersion', $t->id, $t->version, null, ['name' => $t->name, 'version' => $t->version, 'effectiveDate' => $t->effective_date?->toDateString()]);

            return $t;
        });

        return $this->termsShape($t, AgreementService::termsInForce()?->id);
    }

    public function updateTerms(AuthUser $u, string $id, array $dto): array
    {
        $t = DB::transaction(function () use ($u, $id, $dto) {
            $t = $this->lockTerms($id);
            if ($t->status !== 'draft') {
                throw AppError::rule('CREDIT_TERMS_FROZEN', "النسخة {$t->version} منشورة — نصّها لا يتغيّر بعد النشر: التصحيح نسخة جديدة", "Version {$t->version} is published — its text does not change after publication: a correction is a new version");
            }
            $before = ['name' => $t->name, 'version' => $t->version, 'effectiveDate' => $t->effective_date?->toDateString(), 'jurisdictionCity' => $t->jurisdiction_city];
            $this->fillTerms($t, $dto);
            if ($t->isDirty()) {
                $t->save();
                $this->log->config($u, 'TERMS.UPDATE', 'CreditTermsVersion', $t->id, $t->version, $before, ['name' => $t->name, 'version' => $t->version, 'effectiveDate' => $t->effective_date?->toDateString(), 'jurisdictionCity' => $t->jurisdiction_city, 'textChanged' => true]);
            }

            return $t;
        });

        return $this->termsShape($t, AgreementService::termsInForce()?->id);
    }

    public function publishTerms(AuthUser $u, string $id): array
    {
        $t = DB::transaction(function () use ($u, $id) {
            $t = $this->lockTerms($id);
            if ($t->status !== 'draft') {
                throw AppError::rule('CREDIT_TERMS_TRANSITION', "النسخة {$t->version} ليست مسودة — تُنشر المسودة مرة واحدة", "Version {$t->version} is not a draft — a draft is published once", ['from' => $t->status, 'to' => 'published']);
            }
            if (trim((string) $t->terms_ar) === '' || trim((string) $t->terms_en) === '') {
                throw AppError::validation('CREDIT_TERMS_TEXT_REQUIRED', 'تُنشر الشروط بنصّيها العربي والإنجليزي', 'The terms are published with both their Arabic and English text', [['path' => 'termsAr', 'message' => 'required'], ['path' => 'termsEn', 'message' => 'required']]);
            }
            $t->forceFill(['status' => 'published', 'content_hash' => CreditTermsVersion::hashOf((string) $t->version, $t->terms_ar, $t->terms_en, $t->jurisdiction_city), 'published_at' => now(), 'published_by' => $u->nameAr])->save();
            $this->log->config($u, 'TERMS.PUBLISH', 'CreditTermsVersion', $t->id, $t->version, ['status' => 'draft'], ['status' => 'published', 'hash' => $t->content_hash, 'effectiveDate' => $t->effective_date?->toDateString()]);

            return $t;
        });

        return $this->termsShape($t, AgreementService::termsInForce()?->id);
    }

    public function retireTerms(AuthUser $u, string $id, ?string $reason): array
    {
        $t = DB::transaction(function () use ($u, $id, $reason) {
            $t = $this->lockTerms($id);
            if ($t->status !== 'published') {
                throw AppError::rule('CREDIT_TERMS_TRANSITION', "تُسحب النسخة المنشورة فقط — النسخة {$t->version} حالتها {$t->status}", "Only a published version is retired — version {$t->version} is {$t->status}", ['from' => $t->status, 'to' => 'retired']);
            }
            $t->forceFill(['status' => 'retired', 'retired_at' => now(), 'retired_by' => $u->nameAr])->save();
            $this->log->config($u, 'TERMS.RETIRE', 'CreditTermsVersion', $t->id, $t->version, ['status' => 'published'], ['status' => 'retired', 'reason' => Text::clean($reason, 500)]);

            return $t;
        });

        return $this->termsShape($t, AgreementService::termsInForce()?->id);
    }

    private function fillTerms(CreditTermsVersion $t, array $dto): void
    {
        if (Owned::bySource($t)) {
            throw Owned::error($t, 'termsVersion');
        }
        if (array_key_exists('name', $dto)) {
            $t->name = Text::clean($dto['name'], 190);
        }
        if (array_key_exists('version', $dto)) {
            $version = Text::clean($dto['version'], 40);
            if ($version !== null && CreditTermsVersion::where('version', $version)->whereNull('source_system')->when($t->exists, fn ($q) => $q->where('id', '!=', $t->id))->exists()) {
                throw AppError::conflict('CREDIT_TERMS_VERSION_TAKEN', "النسخة {$version} موجودة", "Version {$version} already exists");
            }
            $t->version = $version;
        }
        if (array_key_exists('effectiveDate', $dto)) {
            $t->effective_date = $dto['effectiveDate'];
        }
        foreach (['termsAr' => 'terms_ar', 'termsEn' => 'terms_en'] as $key => $column) {
            if (array_key_exists($key, $dto)) {
                $t->setAttribute($column, Text::clean($dto[$key], 60000));
            }
        }
        if (array_key_exists('jurisdictionCity', $dto)) {
            $t->jurisdiction_city = Text::clean($dto['jurisdictionCity'], 120);
        }
        if (trim((string) $t->name) === '' || trim((string) $t->version) === '' || $t->effective_date === null) {
            throw AppError::validation('CREDIT_TERMS_INVALID', 'الاسم ورقم النسخة وتاريخ السريان مطلوبة', 'The name, the version and the effective date are required', [['path' => 'version', 'message' => 'required']]);
        }
    }

    private function lockTerms(string $id): CreditTermsVersion
    {
        $t = CreditTermsVersion::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('CREDIT_TERMS_NOT_FOUND', 'نسخة الشروط غير موجودة', 'Terms version not found');
        Owned::refuse($t, 'termsVersion');

        return $t;
    }

    private function termsShape(CreditTermsVersion $t, ?string $inForceId): array
    {
        return [
            'id' => $t->id, 'name' => $t->name, 'version' => $t->version, 'status' => $t->status, 'effectiveDate' => $t->effective_date?->toDateString(),
            'termsAr' => $t->terms_ar, 'termsEn' => $t->terms_en, 'jurisdictionCity' => $t->jurisdiction_city, 'hash' => $t->content_hash,
            'publishedAt' => Text::at($t->published_at), 'publishedBy' => $t->published_by, 'retiredAt' => Text::at($t->retired_at), 'retiredBy' => $t->retired_by,
            'inForce' => $t->id === $inForceId, 'acceptedBy' => CreditRequest::where('terms_version_id', $t->id)->count(),
            'origin' => $t->source_system === Owned::SYSTEM ? 'odoo' : 'ops', 'ownedBySource' => Owned::bySource($t), 'createdAt' => Text::at($t->created_at), 'createdBy' => $t->created_by,
        ];
    }

    // ───────────── payment methods ─────────────

    public function createMethod(AuthUser $u, array $dto): array
    {
        $m = DB::transaction(function () use ($u, $dto) {
            $code = $this->code($dto['code'] ?? null);
            if (CreditPaymentMethod::where('code', $code)->exists()) {
                throw AppError::conflict('CREDIT_METHOD_CODE_TAKEN', "الرمز {$code} مستخدم", "Code {$code} is taken");
            }
            $m = new CreditPaymentMethod;
            $m->forceFill(['code' => $code, 'name_ar' => Text::clean($dto['nameAr'] ?? null, 120), 'name_en' => Text::clean($dto['nameEn'] ?? null, 120) ?? Text::clean($dto['nameAr'] ?? null, 120),
                'sequence' => (int) ($dto['sequence'] ?? 10), 'active' => true, 'created_by' => $u->nameAr])->save();
            $this->log->config($u, 'METHOD.CREATE', 'CreditPaymentMethod', $m->id, $m->code, null, ['nameAr' => $m->name_ar, 'nameEn' => $m->name_en]);

            return $m;
        });

        return $m->brief() + ['active' => true, 'sequence' => (int) $m->sequence, 'origin' => 'ops'];
    }

    public function updateMethod(AuthUser $u, string $id, array $dto): array
    {
        $m = DB::transaction(function () use ($u, $id, $dto) {
            $m = CreditPaymentMethod::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('CREDIT_METHOD_NOT_FOUND', 'طريقة السداد غير موجودة', 'Payment method not found');
            Owned::refuse($m, 'paymentMethod');
            $before = ['nameAr' => $m->name_ar, 'nameEn' => $m->name_en, 'sequence' => (string) $m->sequence, 'active' => $m->active ? '1' : '0'];
            foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en'] as $key => $column) {
                if (array_key_exists($key, $dto) && ($v = Text::clean($dto[$key], 120)) !== null) {
                    $m->setAttribute($column, $v);
                }
            }
            if (array_key_exists('sequence', $dto)) {
                $m->sequence = (int) $dto['sequence'];
            }
            if (array_key_exists('active', $dto)) {
                $m->active = (bool) $dto['active'];
            }
            if ($m->isDirty()) {
                $m->save();
                $after = ['nameAr' => $m->name_ar, 'nameEn' => $m->name_en, 'sequence' => (string) $m->sequence, 'active' => $m->active ? '1' : '0'];
                $this->log->config($u, $m->active ? 'METHOD.UPDATE' : 'METHOD.ARCHIVE', 'CreditPaymentMethod', $m->id, $m->code, array_diff_assoc($before, $after), array_diff_assoc($after, $before));
            }

            return $m;
        });

        return $m->brief() + ['active' => (bool) $m->active, 'sequence' => (int) $m->sequence, 'origin' => $m->source_system === Owned::SYSTEM ? 'odoo' : 'ops'];
    }

    // ───────────── settings ─────────────

    public function putSettings(AuthUser $u, string $group, array $value): array
    {
        $clean = match ($group) {
            'calendar' => $this->calendar($value),
            'documents' => $this->requiredDocuments($value),
            'reminders' => ['repeatHours' => self::between($value['repeatHours'] ?? null, 1, 720, 'repeatHours'), 'max' => (int) self::between($value['max'] ?? null, 1, 20, 'max')],
            'signature' => ['expiryDays' => (int) self::between($value['expiryDays'] ?? null, 1, 365, 'expiryDays')],
            default => throw AppError::validation('CREDIT_SETTING_UNKNOWN', 'مجموعة إعدادات غير معروفة', 'Unknown settings group', [['path' => 'group', 'message' => $group]]),
        };
        DB::transaction(function () use ($u, $group, $clean) {
            $before = $this->settings->get($group);
            $this->settings->put($group, $clean, $u->nameAr);
            $this->log->config($u, 'SETTINGS.UPDATE', 'CreditSettings', null, $group, $before, $clean);
        });

        return $this->settings->all();
    }

    private function calendar(array $v): array
    {
        $mode = in_array($v['mode'] ?? null, BusinessClock::MODES, true) ? $v['mode'] : throw AppError::validation('CREDIT_CALENDAR_INVALID', 'طريقة حساب المهلة غير معروفة', 'Unknown way of counting the target time', [['path' => 'mode', 'message' => 'working | calendar']]);
        $days = array_values(array_unique(array_map('intval', (array) ($v['weekdays'] ?? []))));
        sort($days);
        if (array_diff($days, range(0, 6)) || ($mode === 'working' && ! $days)) {
            throw AppError::validation('CREDIT_CALENDAR_INVALID', 'أيام العمل: من 0 (الأحد) إلى 6 (السبت)، يوم واحد على الأقل', 'Working days: 0 (Sunday) to 6 (Saturday), one at least', [['path' => 'weekdays', 'message' => 'invalid']]);
        }
        $start = BusinessClock::minutes((string) ($v['dayStart'] ?? ''));
        $end = BusinessClock::minutes((string) ($v['dayEnd'] ?? ''));
        if ($start === null || $end === null || $end <= $start) {
            throw AppError::validation('CREDIT_CALENDAR_INVALID', 'بداية الدوام ونهايته بصيغة 08:00، والنهاية بعد البداية', 'Opening and closing as 08:00, closing after opening', [['path' => 'dayEnd', 'message' => 'invalid']]);
        }
        $holidays = [];
        foreach ((array) ($v['holidays'] ?? []) as $day) {
            if (! is_string($day) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || ! checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
                throw AppError::validation('CREDIT_CALENDAR_INVALID', 'العطلات بصيغة YYYY-MM-DD', 'Holidays as YYYY-MM-DD', [['path' => 'holidays', 'message' => (string) (is_string($day) ? $day : '?')]]);
            }
            $holidays[] = $day;
        }
        $holidays = array_values(array_unique($holidays));
        sort($holidays);

        return ['mode' => $mode, 'weekdays' => $days, 'dayStart' => (string) $v['dayStart'], 'dayEnd' => (string) $v['dayEnd'], 'holidays' => $holidays];
    }

    private function requiredDocuments(array $v): array
    {
        $slots = array_values(array_unique(array_map('strval', (array) ($v['required'] ?? []))));
        if ($unknown = array_diff($slots, Slots::keys())) {
            throw AppError::validation('CREDIT_SLOT_UNKNOWN', 'خانة مستند غير معروفة', 'Unknown document slot', [['path' => 'required', 'message' => implode(',', $unknown)]]);
        }

        return ['required' => array_values(array_intersect(Slots::keys(), $slots))];
    }

    private static function between(mixed $v, float $min, float $max, string $path): float
    {
        if (! is_numeric($v) || (float) $v < $min || (float) $v > $max) {
            throw AppError::validation('CREDIT_SETTING_INVALID', "القيمة بين {$min} و{$max}", "The value is between {$min} and {$max}", [['path' => $path, 'message' => "{$min}..{$max}"]]);
        }

        return round((float) $v, 2);
    }
}
