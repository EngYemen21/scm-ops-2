<?php

namespace App\Tax\Services;

use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Tax\Models\EinvoiceUnit;
use App\Tax\Support\Money;
use App\Tax\Support\Shape;
use App\Tax\Zatca\Certificate;
use App\Tax\Zatca\Codes;
use App\Tax\Zatca\Csr;
use App\Tax\Zatca\Keys;
use App\Tax\Zatca\Qr;
use App\Tax\Zatca\Signer;
use App\Tax\Zatca\Ubl;
use App\Tax\Zatca\ZatcaClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * E-invoicing units and their onboarding with the tax authority (Detailed Technical Guidelines §3.3):
 *
 *   1. the unit's data            name, serial "1-…|2-…|3-…", which documents it issues, branch, address, industry
 *   2. key + certificate request  made here; the key never leaves the server
 *   3. compliance certificate     with the one-time password a person generates on the authority's portal
 *   4. compliance checks          a sample invoice, credit note and debit note of each kind the unit issues
 *   5. production certificate     from then on the unit stamps and sends
 *
 * and later: a renewal (a new request, a new one-time password), pausing the sending, retiring the unit.
 *
 * SECRETS. The key, the request, the two certificates and their secrets are written encrypted and never read back by
 * an endpoint: every answer of this service is `Shape::unit()`, which says "held: yes / no". The audit trail records
 * that a step happened, the environment and the certificate's serial number — never a key, a token or a secret.
 */
class EinvoiceUnitsService
{
    public function __construct(
        private readonly ZatcaClient $client,
        private readonly TaxProfileService $profiles,
        private readonly EinvoiceService $register,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
    ) {}

    public function list(): array
    {
        $this->register->unit(); // one always exists

        return EinvoiceUnit::orderByDesc('is_default')->orderBy('created_at')->orderBy('id')->get()->map(fn (EinvoiceUnit $u) => Shape::unit($u) + ['steps' => self::steps($u)])->all();
    }

    public function show(string $id): array
    {
        $u = $this->find($id);

        return Shape::unit($u) + ['steps' => self::steps($u)];
    }

    public function create(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $values = $this->values($dto, null);
            $u = EinvoiceUnit::create($values + ['status' => 'not_onboarded', 'is_default' => false, 'sending' => true, 'icv' => 0, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'EINVOICE.UNIT.CREATE', 'entityType' => 'EinvoiceUnit', 'entityId' => $u->id, 'entityNumber' => $u->name, 'newValue' => $values]);

            return $this->show($u->id);
        });
    }

    public function update(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $u = $this->find($id, true);
            $this->alive($u);
            $identity = array_intersect_key($dto, array_flip(['name', 'serial', 'environment', 'invoiceTypes', 'branchName', 'location', 'industry']));
            if ($identity && ! in_array($u->status, ['not_onboarded', 'csr_ready'], true)) {
                throw AppError::rule('EINVOICE_UNIT_ISSUED', 'بيانات الوحدة داخلة في شهادتها — لا تُعدَّل بعد إصدار الشهادة؛ أوقف الوحدة وأنشئ أخرى', 'The unit\'s data is part of its certificate — it is not changed once a certificate exists; retire the unit and make another');
            }
            $values = $identity ? $this->values($dto, $u) : [];
            if (array_key_exists('sending', $dto) && $dto['sending'] !== null) {
                $values['sending'] = (bool) $dto['sending'];
            }
            $old = $new = [];
            foreach ($values as $column => $value) {
                $current = $u->getAttribute($column);
                $same = is_bool($value) ? (bool) $current === $value : (string) ($current ?? '') === (string) ($value ?? '');
                if (! $same) {
                    $old[$column] = $current;
                    $new[$column] = $value;
                }
            }
            if ($new) {
                if ($u->status === 'csr_ready' && array_diff(array_keys($new), ['sending'])) {
                    // the request was made from the old data: it is made again
                    $new += ['csr' => null, 'status' => 'not_onboarded'];
                }
                $u->update($new);
                $this->audit->log($user, ['action' => 'EINVOICE.UNIT.UPDATE', 'entityType' => 'EinvoiceUnit', 'entityId' => $u->id, 'entityNumber' => $u->name, 'oldValue' => $old, 'newValue' => array_diff_key($new, ['csr' => 1])]);
            }
            if (! empty($dto['isDefault']) && ! $u->is_default) {
                EinvoiceUnit::where('is_default', true)->where('id', '!=', $u->id)->update(['is_default' => false]);
                $u->update(['is_default' => true]);
                $this->audit->log($user, ['action' => 'EINVOICE.UNIT.DEFAULT', 'entityType' => 'EinvoiceUnit', 'entityId' => $u->id, 'entityNumber' => $u->name, 'newValue' => ['default' => true]]);
            }

            return $this->show($u->id);
        });
    }

    /** Step 2: the signing key (made once) and the certificate request, from the unit's data and the taxpayer's. */
    public function makeRequest(AuthUser $user, string $id): array
    {
        return DB::transaction(function () use ($user, $id) {
            $u = $this->find($id, true);
            $this->alive($u);
            if (! in_array($u->status, ['not_onboarded', 'csr_ready'], true)) {
                throw AppError::rule('EINVOICE_UNIT_STATE', 'للوحدة شهادة — طلب جديد يكون بالتجديد', 'The unit holds a certificate — a new request is a renewal', ['status' => $u->status]);
            }
            $key = $u->private_key ?: Keys::generate();
            $u->update(['private_key' => $key, 'csr' => $this->requestFor($u, $key), 'status' => 'csr_ready']);
            $this->audit->log($user, ['action' => 'EINVOICE.UNIT.REQUEST', 'entityType' => 'EinvoiceUnit', 'entityId' => $u->id, 'entityNumber' => $u->name, 'newValue' => ['environment' => $u->environment, 'invoiceTypes' => $u->invoice_types]]);
            $this->audit->status($user, 'EinvoiceUnit', $u->id, $u->name, 'not_onboarded', 'csr_ready');

            return $this->show($u->id);
        });
    }

    /** Step 3: the compliance certificate, for the one-time password of the authority's portal. */
    public function requestCompliance(AuthUser $user, string $id, string $otp): array
    {
        $u = $this->find($id);
        $this->alive($u);
        if (! in_array($u->status, ['csr_ready', 'compliance'], true) || ! $u->csr) {
            throw AppError::rule('EINVOICE_UNIT_STATE', 'أنشئ طلب الشهادة أولًا', 'Make the certificate request first', ['status' => $u->status]);
        }
        $otp = self::otp($otp);
        $answer = $this->client->complianceCertificate($u->environment, (string) $u->csr, $otp);
        $this->register->logAttempt(null, $u, ['api' => 'compliance.csid', 'http' => $answer['http'], 'outcome' => $answer['ok'] ? 'accepted' : 'rejected', 'messages' => [], 'error' => $answer['error']], 0, $user);
        if (! $answer['ok']) {
            throw AppError::rule('EINVOICE_AUTHORITY_REFUSED', 'لم تُصدر الهيئة شهادة الامتثال: '.($answer['error'] ?? 'بلا رد'), 'The authority did not issue the compliance certificate: '.($answer['error'] ?? 'no answer'), ['http' => $answer['http']]);
        }
        $certificate = $this->certificateOf($u, (string) $answer['token']);

        return DB::transaction(function () use ($user, $u, $answer, $certificate) {
            $u = $this->find($u->id, true);
            $from = $u->status;
            $u->update(['compliance_token' => $answer['token'], 'compliance_secret' => $answer['secret'], 'compliance_request_id' => $answer['requestId'], 'status' => 'compliance', 'compliance_checks' => null] + self::facts($certificate));
            $this->audit->log($user, ['action' => 'EINVOICE.UNIT.COMPLIANCE', 'entityType' => 'EinvoiceUnit', 'entityId' => $u->id, 'entityNumber' => $u->name, 'newValue' => ['environment' => $u->environment, 'certificateSerial' => $certificate->serial()]]);
            $this->audit->status($user, 'EinvoiceUnit', $u->id, $u->name, $from, 'compliance');

            return $this->show($u->id);
        });
    }

    /** Step 4: one sample invoice, credit note and debit note of every kind the unit issues, checked by the authority. */
    public function runComplianceChecks(AuthUser $user, string $id): array
    {
        $u = $this->find($id);
        $this->alive($u);
        if (! in_array($u->status, ['compliance', 'compliant'], true) || ! $u->compliance_token) {
            throw AppError::rule('EINVOICE_UNIT_STATE', 'احصل على شهادة الامتثال أولًا', 'Get the compliance certificate first', ['status' => $u->status]);
        }
        $certificate = Certificate::fromToken((string) $u->compliance_token);
        $results = [];
        $previous = Codes::FIRST_PREVIOUS_HASH;
        $icv = 0;
        foreach ($this->samples($u) as $key => $document) {
            $doc = $document + ['icv' => ++$icv, 'previousHash' => $previous, 'uuid' => (string) Str::uuid()];
            $basic = ['seller' => $doc['seller']['name'], 'vatNo' => $doc['seller']['vatNo'], 'timestamp' => "{$doc['issueDate']}T{$doc['issueTime']}", 'total' => Money::text($doc['totals']['total']), 'vat' => Money::text($doc['totals']['vat'])];
            $stamp = Signer::stamp(Ubl::render($doc + ['qr' => Qr::encode($basic)]), (string) $u->private_key, $certificate, $basic, $doc['profile'] === 'simplified');
            $previous = $stamp['hash'];
            $t = microtime(true);
            $result = $this->client->submit('compliance', $u->environment, (string) $u->compliance_token, (string) $u->compliance_secret, $doc['uuid'], $stamp['hash'], $stamp['xml']);
            $this->register->logAttempt(null, $u, $result, (int) round((microtime(true) - $t) * 1000), $user);
            $results[$key] = ['passed' => in_array($result['outcome'], ['accepted', 'warnings'], true), 'outcome' => $result['outcome'], 'http' => $result['http'], 'at' => now()->toJSON(),
                'messages' => array_values(array_filter($result['messages'], fn (array $m) => $m['type'] !== 'INFO')), 'error' => $result['error']];
        }
        $passed = $results && ! in_array(false, array_column($results, 'passed'), true);

        return DB::transaction(function () use ($user, $u, $results, $passed) {
            $u = $this->find($u->id, true);
            $from = $u->status;
            $u->update(['compliance_checks' => $results, 'status' => $passed ? 'compliant' : 'compliance']);
            $this->audit->log($user, ['action' => 'EINVOICE.UNIT.CHECKS', 'entityType' => 'EinvoiceUnit', 'entityId' => $u->id, 'entityNumber' => $u->name,
                'newValue' => ['passed' => $passed, 'samples' => array_map(fn (array $r) => $r['outcome'], $results)]]);
            if ($u->status !== $from) {
                $this->audit->status($user, 'EinvoiceUnit', $u->id, $u->name, $from, $u->status);
            }

            return $this->show($u->id) + ['checksPassed' => $passed];
        });
    }

    /** Step 5: the production certificate. From here on the unit stamps and sends. */
    public function requestProduction(AuthUser $user, string $id): array
    {
        $u = $this->find($id);
        $this->alive($u);
        if ($u->status !== 'compliant') {
            throw AppError::rule('EINVOICE_UNIT_STATE', 'تُطلب شهادة الإنتاج بعد اجتياز فحوص الامتثال', 'The production certificate is requested after the compliance checks have passed', ['status' => $u->status]);
        }
        $answer = $this->client->productionCertificate($u->environment, (string) $u->compliance_token, (string) $u->compliance_secret, (string) $u->compliance_request_id);
        $this->register->logAttempt(null, $u, ['api' => 'production.csid', 'http' => $answer['http'], 'outcome' => $answer['ok'] ? 'accepted' : 'rejected', 'messages' => [], 'error' => $answer['error']], 0, $user);
        if (! $answer['ok']) {
            throw AppError::rule('EINVOICE_AUTHORITY_REFUSED', 'لم تُصدر الهيئة شهادة الإنتاج: '.($answer['error'] ?? 'بلا رد'), 'The authority did not issue the production certificate: '.($answer['error'] ?? 'no answer'), ['http' => $answer['http']]);
        }
        $certificate = $this->certificateOf($u, (string) $answer['token']);

        return DB::transaction(function () use ($user, $u, $answer, $certificate) {
            $u = $this->find($u->id, true);
            $u->update(['production_token' => $answer['token'], 'production_secret' => $answer['secret'], 'production_request_id' => $answer['requestId'], 'status' => 'active'] + self::facts($certificate));
            $this->audit->log($user, ['action' => 'EINVOICE.UNIT.ACTIVATE', 'entityType' => 'EinvoiceUnit', 'entityId' => $u->id, 'entityNumber' => $u->name, 'newValue' => ['environment' => $u->environment, 'certificateSerial' => $certificate->serial(), 'validUntil' => $certificate->notAfter()->toDateString()]]);
            $this->audit->status($user, 'EinvoiceUnit', $u->id, $u->name, 'compliant', 'active');
            $this->notify->activity($user, 'EinvoiceUnit', $u->id, $u->name, "رُبطت وحدة الفوترة الإلكترونية {$u->name} بالهيئة ({$u->environment}) — الفواتير الجديدة تُختم وتُرسل", "E-invoicing unit {$u->name} is onboarded ({$u->environment})", ['finance']);

            return $this->show($u->id);
        });
    }

    /** A new certificate for a unit that has one: a new key and request, a one-time password, and the authority swaps them. */
    public function renew(AuthUser $user, string $id, string $otp): array
    {
        $u = $this->find($id);
        $this->alive($u);
        if ($u->status !== 'active' || ! $u->production_token) {
            throw AppError::rule('EINVOICE_UNIT_STATE', 'التجديد لوحدة مربوطة فقط', 'Only an onboarded unit is renewed', ['status' => $u->status]);
        }
        $otp = self::otp($otp);
        $key = Keys::generate();
        $csr = $this->requestFor($u, $key);
        $answer = $this->client->renewProductionCertificate($u->environment, (string) $u->production_token, (string) $u->production_secret, $csr, $otp);
        $this->register->logAttempt(null, $u, ['api' => 'production.renew', 'http' => $answer['http'], 'outcome' => $answer['ok'] ? 'accepted' : 'rejected', 'messages' => [], 'error' => $answer['error']], 0, $user);
        if (! $answer['ok']) {
            throw AppError::rule('EINVOICE_AUTHORITY_REFUSED', 'لم تجدّد الهيئة الشهادة: '.($answer['error'] ?? 'بلا رد').' — الشهادة الحالية باقية', 'The authority did not renew the certificate: '.($answer['error'] ?? 'no answer').' — the current one stays', ['http' => $answer['http']]);
        }
        try {
            $certificate = Certificate::fromToken((string) $answer['token']);
        } catch (InvalidArgumentException) {
            throw AppError::rule('EINVOICE_CERTIFICATE_INVALID', 'ما أعادته الهيئة ليس شهادة مقروءة — الشهادة الحالية باقية', 'What the authority returned is not a readable certificate — the current one stays');
        }
        if (! Keys::matches($key, $certificate->publicKey())) {
            throw AppError::rule('EINVOICE_CERTIFICATE_KEY', 'الشهادة الجديدة لا تخص مفتاح الطلب — الشهادة الحالية باقية', 'The new certificate does not belong to the key of the request — the current one stays');
        }

        return DB::transaction(function () use ($user, $u, $answer, $certificate, $key, $csr) {
            $u = $this->find($u->id, true);
            $was = $u->cert_serial;
            $u->update(['private_key' => $key, 'csr' => $csr, 'production_token' => $answer['token'], 'production_secret' => $answer['secret'], 'production_request_id' => $answer['requestId']] + self::facts($certificate));
            $this->audit->log($user, ['action' => 'EINVOICE.UNIT.RENEW', 'entityType' => 'EinvoiceUnit', 'entityId' => $u->id, 'entityNumber' => $u->name, 'oldValue' => ['certificateSerial' => $was],
                'newValue' => ['certificateSerial' => $certificate->serial(), 'validUntil' => $certificate->notAfter()->toDateString()]]);

            return $this->show($u->id);
        });
    }

    /** The unit stops for good: it makes no more documents and sends nothing. Its documents and its chain stay. */
    public function retire(AuthUser $user, string $id, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw AppError::validation('REASON_REQUIRED', 'اكتب سبب إيقاف الوحدة (5 أحرف على الأقل)', 'A reason is required', [['path' => 'reason', 'message' => 'هذا الحقل إلزامي']]);
        }

        return DB::transaction(function () use ($user, $id, $reason) {
            $u = $this->find($id, true);
            $this->alive($u);
            $from = $u->status;
            $u->update(['status' => 'retired', 'is_default' => false, 'sending' => false, 'retired_at' => now(), 'retired_by' => $user->username, 'retire_reason' => $reason]);
            $this->audit->log($user, ['action' => 'EINVOICE.UNIT.RETIRE', 'entityType' => 'EinvoiceUnit', 'entityId' => $u->id, 'entityNumber' => $u->name, 'newValue' => ['reason' => $reason, 'lastCounter' => $u->icv]]);
            $this->audit->status($user, 'EinvoiceUnit', $u->id, $u->name, $from, 'retired', $reason);

            return $this->show($u->id);
        });
    }

    // ───────────── internals ─────────────

    /** Where a unit stands on the way, for the screen. */
    public static function steps(EinvoiceUnit $u): array
    {
        $order = ['not_onboarded' => 0, 'csr_ready' => 1, 'compliance' => 2, 'compliant' => 3, 'active' => 4, 'retired' => -1];
        $at = $order[$u->status] ?? 0;
        $step = fn (string $key, int $n, string $ar, string $en) => ['key' => $key, 'ar' => $ar, 'en' => $en, 'done' => $at >= $n, 'next' => $at === $n - 1];

        return [
            $step('request', 1, 'إنشاء المفتاح وطلب الشهادة', 'Key and certificate request'),
            $step('compliance', 2, 'شهادة الامتثال (برمز التحقق من بوابة الهيئة)', 'Compliance certificate (with the portal\'s one-time password)'),
            $step('checks', 3, 'فحوص الامتثال (فواتير نموذجية)', 'Compliance checks (sample documents)'),
            $step('production', 4, 'شهادة الإنتاج', 'Production certificate'),
        ];
    }

    private function values(array $dto, ?EinvoiceUnit $current): array
    {
        $values = [];
        foreach (['name' => 'name', 'serial' => 'serial', 'environment' => 'environment', 'invoiceTypes' => 'invoice_types', 'branchName' => 'branch_name', 'location' => 'location', 'industry' => 'industry'] as $key => $column) {
            if (array_key_exists($key, $dto)) {
                $values[$column] = trim((string) $dto[$key]) === '' ? null : trim((string) $dto[$key]);
            }
        }
        $bad = fn (string $path, string $ar, string $en) => throw AppError::validation('EINVOICE_UNIT_INVALID', $ar, $en, [['path' => $path, 'message' => $ar]]);
        $name = $values['name'] ?? $current?->name;
        if ($name === null || mb_strlen($name) > 120) {
            $bad('name', 'اسم الوحدة إلزامي (حتى 120 حرفًا)', 'The unit needs a name (up to 120 characters)');
        }
        if (array_key_exists('name', $values) && EinvoiceUnit::where('name', $name)->where('status', '!=', 'retired')->when($current, fn ($q) => $q->where('id', '!=', $current->id))->exists()) {
            throw AppError::conflict('EINVOICE_UNIT_EXISTS', "توجد وحدة فعّالة بالاسم «{$name}»", "A live unit is already named \"{$name}\"");
        }
        $environment = $values['environment'] ?? $current?->environment ?? 'sandbox';
        if (! isset(Codes::ENVIRONMENTS[$environment])) {
            $bad('environment', 'بيئة الهيئة: تجريبية أو محاكاة أو إنتاج', 'The environment is sandbox, simulation or production');
        }
        $types = $values['invoice_types'] ?? $current?->invoice_types ?? '1100';
        if (! preg_match('/^[01]{2}00$/', $types) || $types === '0000') {
            $bad('invoiceTypes', 'أنواع الفواتير: 1100 (ضريبية ومبسطة) أو 1000 أو 0100', 'Invoice types: 1100 (standard and simplified), 1000 or 0100');
        }
        $serial = $values['serial'] ?? $current?->serial ?? ('1-OPS|2-1|3-'.strtoupper(Str::random(10)));
        if (! preg_match('/^1-[^|]+\|2-[^|]+\|3-[^|]+$/u', $serial) || mb_strlen($serial) > 190) {
            $bad('serial', 'الرقم التسلسلي بالصيغة «1-المصنّع|2-الطراز|3-الرقم»', 'The serial has the form "1-maker|2-model|3-serial"');
        }

        return ['name' => $name, 'environment' => $environment, 'invoice_types' => $types, 'serial' => $serial] + array_intersect_key($values, array_flip(['branch_name', 'location', 'industry']));
    }

    /** The certificate request of a unit for a key: the unit's data and the taxpayer's. */
    private function requestFor(EinvoiceUnit $u, string $key): string
    {
        $profile = $this->profiles->profile();
        if (! $profile->canIssue()) {
            throw AppError::rule('EINVOICE_SELLER_PROFILE', 'أكمل بيانات المنشأة الضريبية أولًا (الاسم النظامي والرقم الضريبي)', 'Complete the taxpayer profile first (legal name and VAT number)');
        }
        foreach (['branch_name' => 'اسم الفرع', 'location' => 'عنوان الوحدة', 'industry' => 'النشاط'] as $column => $label) {
            if (trim((string) $u->getAttribute($column)) === '') {
                throw AppError::validation('EINVOICE_UNIT_INVALID', "{$label} إلزامي في طلب الشهادة", "{$column} is required for the certificate request", [['path' => Str::camel($column), 'message' => 'هذا الحقل إلزامي']]);
            }
        }

        return Csr::build(['commonName' => $u->name, 'serial' => $u->serial, 'vatNo' => (string) $profile->vat_no, 'branch' => (string) $u->branch_name,
            'organization' => (string) ($profile->legal_name_en ?: $profile->legal_name_ar), 'country' => $profile->country_code ?: 'SA', 'invoiceTypes' => $u->invoice_types,
            'location' => (string) $u->location, 'industry' => (string) $u->industry, 'environment' => $u->environment], $key);
    }

    /** The certificate inside a token, proven to belong to the unit's key. */
    private function certificateOf(EinvoiceUnit $u, string $token): Certificate
    {
        try {
            $certificate = Certificate::fromToken($token);
        } catch (InvalidArgumentException) {
            throw AppError::rule('EINVOICE_CERTIFICATE_INVALID', 'ما أعادته الهيئة ليس شهادة مقروءة', 'What the authority returned is not a readable certificate');
        }
        if (! Keys::matches((string) $u->private_key, $certificate->publicKey())) {
            throw AppError::rule('EINVOICE_CERTIFICATE_KEY', 'الشهادة لا تخص مفتاح هذه الوحدة', 'The certificate does not belong to the key of this unit');
        }

        return $certificate;
    }

    /** What of a certificate is shown: nothing of it is secret. */
    private static function facts(Certificate $c): array
    {
        return ['cert_serial' => mb_substr($c->serial(), 0, 80), 'cert_issuer' => mb_substr($c->issuerName(), 0, 300), 'cert_subject' => mb_substr($c->subjectName(), 0, 300),
            'cert_not_before' => Carbon::instance($c->notBefore()), 'cert_not_after' => Carbon::instance($c->notAfter())];
    }

    /**
     * The sample documents of the compliance checks: for each kind the unit issues, an invoice, a credit note and a
     * debit note — the taxpayer's own data as the seller, a made-up buyer, one line of 100.00 at 15 %.
     *
     * @return array<string, array<string, mixed>> "standard.invoice" … → the document without counter, hash, uuid, QR
     */
    private function samples(EinvoiceUnit $u): array
    {
        $profile = $this->profiles->profile();
        $now = Carbon::now()->setTimezone(EinvoiceDocuments::TIMEZONE);
        $seller = EinvoiceDocuments::seller($profile);
        $buyer = ['name' => 'عميل فحص الامتثال', 'vatNo' => '399999999800003', 'street' => 'شارع الأمير سلطان', 'buildingNo' => '2322', 'district' => 'المربع', 'city' => 'الرياض', 'postalCode' => '23333', 'country' => 'SA'];
        $net = 10000;
        $vat = Money::percent($net, '15');
        $out = [];
        $n = 0;
        foreach (['standard' => 0, 'simplified' => 1] as $profileKind => $position) {
            if (($u->invoice_types[$position] ?? '0') !== '1') {
                continue;
            }
            foreach (['invoice', 'credit_note', 'debit_note'] as $kind) {
                $n++;
                $out["{$profileKind}.{$kind}"] = [
                    'kind' => $kind, 'profile' => $profileKind, 'number' => 'CMP-'.$now->format('Ymd').'-'.$n, 'issueDate' => $now->format('Y-m-d'), 'issueTime' => $now->format('H:i:s'),
                    'deliveryDate' => $now->format('Y-m-d'), 'currency' => 'SAR', 'billingReference' => $kind === 'invoice' ? null : 'CMP-'.$now->format('Ymd').'-'.($n - ($kind === 'credit_note' ? 1 : 2)),
                    'reason' => $kind === 'invoice' ? null : 'فحص الامتثال', 'paymentMeans' => '10', 'seller' => $seller, 'buyer' => $profileKind === 'standard' ? $buyer : ['name' => $buyer['name']],
                    'lines' => [['id' => 1, 'name' => 'صنف فحص الامتثال', 'qty' => 1, 'unit' => 'PCE', 'price' => '100.00', 'discount' => 0, 'net' => $net, 'vat' => $vat, 'category' => 'S', 'rate' => '15']],
                    'tax' => [['category' => 'S', 'rate' => '15', 'taxable' => $net, 'vat' => $vat]],
                    'totals' => ['lineNet' => $net, 'allowance' => 0, 'taxable' => $net, 'vat' => $vat, 'total' => $net + $vat, 'prepaid' => 0, 'payable' => $net + $vat],
                ];
            }
        }

        return $out;
    }

    private function alive(EinvoiceUnit $u): void
    {
        if ($u->status === 'retired') {
            throw AppError::rule('EINVOICE_UNIT_RETIRED', "الوحدة {$u->name} موقوفة", "Unit {$u->name} is retired");
        }
    }

    private static function otp(string $otp): string
    {
        $otp = trim($otp);
        if (! preg_match('/^\d{6}$/', $otp)) {
            throw AppError::validation('EINVOICE_OTP', 'رمز التحقق ستة أرقام تُولَّد من بوابة الهيئة (صالح لساعة)', 'The one-time password is six digits generated on the authority\'s portal (valid for one hour)', [['path' => 'otp', 'message' => 'ستة أرقام']]);
        }

        return $otp;
    }

    private function find(string $id, bool $lock = false): EinvoiceUnit
    {
        return EinvoiceUnit::query()->whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->first() ?? throw AppError::notFound('EINVOICE_UNIT_NOT_FOUND', 'وحدة الفوترة غير موجودة', 'E-invoicing unit not found');
    }
}
