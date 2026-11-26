<?php

namespace App\Credit\Services;

use App\Credit\Models\CreditEnvelope;
use App\Credit\Models\CreditFile;
use App\Credit\Models\CreditRequest;
use App\Credit\Signature\SignatureProvider;
use App\Credit\Support\Access;
use App\Credit\Support\Flow;
use App\Credit\Support\Owned;
use App\Credit\Support\Privacy;
use App\Credit\Support\Settings;
use App\Credit\Support\Slots;
use App\Credit\Support\Text;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The signature of a request's agreement: one envelope per attempt (`credit_envelopes`).
 *
 *   issue      the agreement as it stands now is handed over for signature (through the provider) — the envelope keeps
 *              the proof of its text, to whom it went and until when it waits
 *   delivered  the signer received it
 *   signed     the signed agreement came back: its scan is kept as a paper of the request (`credit_files`, slot
 *              `signed_agreement`) and the envelope is closed
 *   declined · voided · expired · failed   the other ways an envelope ends; a new one can then be issued
 *
 * A request has at most one envelope that still waits. The provider is whatever stands behind SignatureProvider — in
 * this installation the manual one: a person hands the printed agreement over and uploads the signed copy.
 *
 * WHO. Issuing, and recording that it was delivered, declined or voided: the reviewers, approvers and managers.
 * Uploading the signed copy: they, and the requester for the request he entered (it comes back to him from the
 * customer) — the stage's approver is the one who then confirms it.
 */
class SignatureService
{
    public function __construct(
        private readonly SignatureProvider $provider,
        private readonly AgreementService $agreement,
        private readonly DocumentsService $documents,
        private readonly CreditLog $log,
        private readonly Notifier $notifier,
        private readonly Settings $settings,
    ) {}

    /** @return array{key:string, labelAr:string, labelEn:string, configured:bool, remote:bool} the provider of this installation */
    public function provider(): array
    {
        $label = $this->provider->label();

        return ['key' => $this->provider->key(), 'labelAr' => $label['ar'], 'labelEn' => $label['en'], 'configured' => $this->provider->configured(), 'remote' => $this->provider->remote()];
    }

    /**
     * Issues the agreement for signature.
     *
     * @param  array{signerName?:?string, signerEmail?:?string, signerMobile?:?string}  $signer  default: the first authorised signatory of the request
     * @return string the request's id
     */
    public function issue(AuthUser $u, string $requestId, array $signer = []): string
    {
        return DB::transaction(function () use ($u, $requestId, $signer) {
            $r = CreditRequest::locate($requestId, true);
            if ($e = $this->blockIssue($u, $r)) {
                throw $e;
            }
            $this->open($u, $r, $signer);

            return $r->id;
        });
    }

    /** Entering a stage that sends the agreement by itself: one is issued unless one waits or is signed already. Never throws. */
    public function issueAutomatically(?AuthUser $by, CreditRequest $r): void
    {
        if (! $this->provider->configured() || $this->current($r) !== null || $this->state($r)['ok']) {
            return;
        }
        $this->open($by, $r, []);
    }

    public function delivered(AuthUser $u, string $requestId, string $envelopeId): string
    {
        return $this->move($u, $requestId, $envelopeId, 'delivered', null);
    }

    public function declined(AuthUser $u, string $requestId, string $envelopeId, ?string $reason): string
    {
        return $this->move($u, $requestId, $envelopeId, 'declined', self::reason($reason));
    }

    public function void(AuthUser $u, string $requestId, string $envelopeId, ?string $reason): string
    {
        return $this->move($u, $requestId, $envelopeId, 'voided', self::reason($reason));
    }

    /**
     * The signed agreement came back: its scan is kept and the envelope is closed as signed.
     *
     * @param  array{fileName:string, base64:string}  $file
     * @return string the request's id
     */
    public function signed(AuthUser $u, string $requestId, string $envelopeId, array $file): string
    {
        $decoded = DocumentsService::decode($file);

        return DB::transaction(function () use ($u, $requestId, $envelopeId, $decoded) {
            $r = CreditRequest::locate($requestId, true);
            $env = $this->envelope($r, $envelopeId);
            if ($e = $this->blockSigned($u, $r, $env)) {
                throw $e;
            }
            $this->close($u, $r, $env, 'signed', null, $this->documents->keep($u, $r, Slots::SIGNED_AGREEMENT, $decoded));

            return $r->id;
        });
    }

    /** Asks the provider where a waiting envelope stands (a provider that carries the agreement itself). */
    public function refresh(AuthUser $u, string $requestId, string $envelopeId): string
    {
        return DB::transaction(function () use ($u, $requestId, $envelopeId) {
            $r = CreditRequest::locate($requestId, true);
            $env = $this->envelope($r, $envelopeId);
            if ($e = $this->blockStaff($u, $r) ?? ($env->active() ? null : self::ended($env))) {
                throw $e;
            }
            $news = $this->provider->status($env);
            if ($news === null || ($news['status'] ?? $env->status) === $env->status) {
                return $r->id;
            }
            Flow::envelopeAssert($env->status, (string) $news['status']);
            $file = null;
            if ($news['status'] === 'signed') {
                $pdf = (string) ($news['signedPdf'] ?? '');
                if (! str_starts_with($pdf, '%PDF-')) {
                    throw AppError::rule('CREDIT_SIGNED_FILE_REQUIRED', 'أفاد المزوّد بالتوقيع ولم يُرجع الاتفاقية الموقَّعة', 'The provider reported a signature and returned no signed agreement');
                }
                $file = $this->documents->keep($u, $r, Slots::SIGNED_AGREEMENT, ['bytes' => $pdf, 'mime' => 'application/pdf', 'ext' => 'pdf', 'name' => "{$r->number}-signed.pdf"]);
            }
            $this->close($u, $r, $env, (string) $news['status'], Text::clean($news['error'] ?? null, 500), $file, isset($news['payload']) ? (array) $news['payload'] : null);

            return $r->id;
        });
    }

    /**
     * The request left the stages it is worked in (sent back, withdrawn, rejected, cancelled): an agreement that still
     * waits for the signer is withdrawn — what comes next is another agreement. Call inside the request's transaction.
     */
    public function voidWaiting(?AuthUser $by, CreditRequest $r, string $reason): void
    {
        foreach (CreditEnvelope::where('request_id', $r->id)->whereIn('status', Flow::ENVELOPE_ACTIVE)->lockForUpdate()->get() as $env) {
            try {
                $this->provider->void($env, $reason);
            } catch (Throwable $e) {
                report($e); // the provider could not be told: the envelope is closed here all the same, and said so
            }
            $this->close($by, $r, $env, 'voided', $reason);
        }
    }

    /** Envelopes that waited past their time. Called by the deadlines run. @return int how many expired */
    public function expire(): int
    {
        $n = 0;
        $due = CreditEnvelope::whereIn('status', ['sent', 'delivered'])->whereNotNull('expires_at')->where('expires_at', '<=', now())->orderBy('expires_at')->limit(200)->pluck('id');
        foreach ($due as $id) {
            $n += DB::transaction(function () use ($id) {
                $env = CreditEnvelope::whereKey($id)->lockForUpdate()->first();
                $r = $env ? CreditRequest::whereKey($env->request_id)->lockForUpdate()->first() : null;
                if (! $env || ! $r || ! $env->active() || Owned::bySource($r)) {
                    return 0;
                }
                $this->close(null, $r, $env, 'expired', null);

                return 1;
            });
        }

        return $n;
    }

    // ───────────── reads ─────────────

    /** The envelope that still waits for the signer. */
    public function current(CreditRequest $r): ?CreditEnvelope
    {
        return CreditEnvelope::where('request_id', $r->id)->whereIn('status', Flow::ENVELOPE_ACTIVE)->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    /** The envelope the agreement was signed with (the latest one). */
    public function signedEnvelope(CreditRequest $r): ?CreditEnvelope
    {
        return CreditEnvelope::where('request_id', $r->id)->where('status', 'signed')->orderByDesc('signed_at')->orderByDesc('id')->first();
    }

    /**
     * Where the signature of a request stands, for a stage that requires one.
     *
     * @return array{status:string, ok:bool, stale:bool} `stale`: signed, but the agreement changed since (a request of Odoo is taken at Odoo's word)
     */
    public function state(CreditRequest $r): array
    {
        $signed = $this->signedEnvelope($r);
        if (! $signed) {
            return ['status' => $this->current($r)?->status ?? ($r->signature_status ?: 'none'), 'ok' => false, 'stale' => false];
        }
        $stale = $signed->agreement_hash !== null && ! hash_equals((string) $signed->agreement_hash, $this->agreement->fingerprint($r));

        return ['status' => 'signed', 'ok' => ! $stale, 'stale' => $stale];
    }

    public function shape(AuthUser $u, CreditRequest $r, CreditEnvelope $env): array
    {
        $staff = $this->blockStaff($u, $r) === null;

        return [
            'id' => $env->id, 'provider' => $env->provider, 'status' => $env->status, 'externalEnvelopeId' => $env->external_envelope_id,
            'signerName' => $env->signer_name, 'signerEmail' => $env->signer_email, 'signerMobile' => $env->signer_mobile,
            'sentAt' => Text::at($env->sent_at), 'deliveredAt' => Text::at($env->delivered_at), 'signedAt' => Text::at($env->signed_at), 'closedAt' => Text::at($env->closed_at),
            'expiresAt' => Text::at($env->expires_at), 'errorMessage' => $env->error_message, 'signedFileId' => $env->signed_file_id, 'createdBy' => $env->created_by,
            'createdAt' => Text::at($env->created_at), 'source' => $env->source_system === Owned::SYSTEM ? 'odoo' : 'ops',
            // signed, and the agreement of the request is no longer the one that was signed
            'stale' => $env->status === 'signed' && $env->agreement_hash !== null && ! hash_equals((string) $env->agreement_hash, $this->agreement->fingerprint($r)),
            'can' => [
                'delivered' => $staff && $env->status === 'sent', 'declined' => $staff && $env->active() && $env->status !== 'draft', 'void' => $staff && $env->active(),
                'signed' => $this->blockSigned($u, $r, $env) === null, 'refresh' => $staff && $env->active() && $this->provider->remote(),
            ],
        ];
    }

    // ───────────── who may ─────────────

    /** Why this user may not act on the envelopes of this request as staff (null = he may). */
    public function blockStaff(AuthUser $u, CreditRequest $r): ?AppError
    {
        if (Owned::bySource($r)) {
            return Owned::error($r, 'envelope');
        }

        return Privacy::privileged($u) ? null
            : AppError::forbidden('CREDIT_SIGNATURE_STAFF', 'إصدار مظروف التوقيع وتسجيل حالته لمراجعي الائتمان ومعتمديه', 'Issuing a signature envelope and recording where it stands is for the credit reviewers and approvers');
    }

    public function blockIssue(AuthUser $u, CreditRequest $r): ?AppError
    {
        if ($e = $this->blockStaff($u, $r)) {
            return $e;
        }
        if (Flow::isFinal($r->state)) {
            return Access::closed($r);
        }
        if (! Flow::isWork($r->state)) {
            return AppError::rule('CREDIT_NOT_SUBMITTED', "الطلب {$r->number} لم يُقدَّم بعد — تُصدر الاتفاقية للتوقيع بعد تقديمه", "Request {$r->number} is not submitted yet — the agreement is issued for signature once it is");
        }
        if (! $this->provider->configured()) {
            return AppError::rule('CREDIT_SIGNATURE_PROVIDER', 'مزوّد التوقيع غير مُعَدّ — أكمل إعداده أو استخدم التوقيع اليدوي', 'The signature provider is not configured — complete its set-up or use the manual signature');
        }
        if ($waiting = $this->current($r)) {
            return AppError::rule('CREDIT_ENVELOPE_ACTIVE', 'للطلب مظروف توقيع ما زال بانتظار العميل — سجّل نتيجته أو أبطله قبل إصدار آخر', 'The request has an envelope that still waits for the customer — record its outcome or void it before issuing another', ['envelope' => $waiting->id]);
        }
        $state = $this->state($r);

        return $state['status'] === 'signed' && ! $state['stale']
            ? AppError::rule('CREDIT_ALREADY_SIGNED', 'الاتفاقية موقَّعة ولم تتغيّر منذ توقيعها — لا حاجة إلى مظروف جديد', 'The agreement is signed and has not changed since — no new envelope is needed') : null;
    }

    public function blockSigned(AuthUser $u, CreditRequest $r, CreditEnvelope $env): ?AppError
    {
        if (Owned::bySource($r)) {
            return Owned::error($r, 'envelope');
        }
        if (! Privacy::privileged($u) && ! Privacy::isRequester($u, $r)) {
            return Access::notYours($r);
        }
        if (Flow::isFinal($r->state)) {
            return Access::closed($r);
        }

        return in_array($env->status, ['sent', 'delivered'], true) ? null : ($env->active()
            ? AppError::rule('CREDIT_ENVELOPE_TRANSITION', 'المظروف لم يُرسل بعد', 'The envelope was not sent yet', ['from' => $env->status, 'to' => 'signed']) : self::ended($env));
    }

    // ───────────── the mechanics ─────────────

    private function open(?AuthUser $by, CreditRequest $r, array $signer): CreditEnvelope
    {
        $first = $r->signatories()->first();
        $agreement = $this->agreement->build($r);
        $env = CreditEnvelope::create([
            'request_id' => $r->id, 'provider' => $this->provider->key(), 'status' => 'draft', 'agreement_hash' => $agreement['fingerprint'],
            'signer_name' => Text::clean($signer['signerName'] ?? null, 190) ?? $first?->name ?? $r->owner_name, 'signer_email' => Text::clean($signer['signerEmail'] ?? null, 190) ?? $r->email,
            'signer_mobile' => Text::clean($signer['signerMobile'] ?? null, 40) ?? $first?->mobile ?? $r->mobile,
            'created_by_id' => $by?->id, 'created_by' => $by?->nameAr ?? 'النظام',
        ]);
        try {
            $out = $this->provider->send($env, ['html' => $this->agreement->html($agreement), 'hash' => $agreement['fingerprint'], 'number' => $r->number]);
        } catch (Throwable $e) {
            report($e);
            $out = ['status' => 'failed', 'error' => 'the provider did not answer ('.class_basename($e).')'];
        }
        $sent = ($out['status'] ?? null) === 'sent';
        $days = max(1, (int) ($this->settings->get('signature')['expiryDays'] ?? 14));
        $env->forceFill([
            'status' => $sent ? 'sent' : 'failed', 'external_envelope_id' => Text::clean($out['externalId'] ?? null, 120), 'sent_at' => $sent ? now() : null,
            'expires_at' => $sent ? now()->addDays($days) : null, 'closed_at' => $sent ? null : now(), 'error_message' => $sent ? null : Text::clean($out['error'] ?? 'refused by the provider', 500),
            'last_payload' => isset($out['payload']) ? json_encode($out['payload'], JSON_UNESCAPED_UNICODE) : null,
        ])->save();
        $this->sync($r);
        $this->log->event($r, 'envelope', $by, null, ['status' => $env->status, 'provider' => $env->provider, 'envelope' => $env->id]);
        $this->log->audit($by, $r, 'ENVELOPE.'.($sent ? 'SEND' : 'FAILED'), null, ['provider' => $env->provider, 'envelope' => $env->id]);
        if ($sent) {
            $this->notifier->tell($r, 'signature.requested', "اتفاقية حساب الائتمان الآجل {$r->number} بانتظار توقيعكم", "The deferred credit agreement {$r->number} is waiting for your signature", $by, ['customer' => true]);
            $this->notifier->tell($r, 'signature.issued', "أُصدرت اتفاقية الطلب {$r->number} ({$r->company_name}) لتوقيع العميل — ارفع النسخة الموقَّعة عند استلامها",
                "The agreement of request {$r->number} ({$r->company_name}) was issued for the customer's signature — upload the signed copy when it comes back", $by, ['users' => [$r->requester_id]]);
        }

        return $env;
    }

    private function move(AuthUser $u, string $requestId, string $envelopeId, string $to, ?string $reason): string
    {
        return DB::transaction(function () use ($u, $requestId, $envelopeId, $to, $reason) {
            $r = CreditRequest::locate($requestId, true);
            $env = $this->envelope($r, $envelopeId);
            if ($e = $this->blockStaff($u, $r)) {
                throw $e;
            }
            if ($to === 'voided' && $env->active()) {
                $this->provider->void($env, (string) $reason);
            }
            $this->close($u, $r, $env, $to, $reason);

            return $r->id;
        });
    }

    /** One step of an envelope, with its record on the request. */
    private function close(?AuthUser $by, CreditRequest $r, CreditEnvelope $env, string $to, ?string $reason, ?CreditFile $signed = null, ?array $payload = null): void
    {
        $from = $env->status;
        Flow::envelopeAssert($from, $to);
        $env->forceFill([
            'status' => $to, 'error_message' => $reason ?? $env->error_message,
            'delivered_at' => $to === 'delivered' ? now() : $env->delivered_at, 'signed_at' => $to === 'signed' ? now() : $env->signed_at,
            'closed_at' => in_array($to, Flow::ENVELOPE_ACTIVE, true) ? null : now(), 'signed_file_id' => $signed?->id ?? $env->signed_file_id,
        ] + ($payload !== null ? ['last_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)] : []))->save();
        $this->sync($r);
        $this->log->event($r, 'envelope', $by, $reason, ['status' => $to, 'from' => $from, 'provider' => $env->provider, 'envelope' => $env->id]);
        $this->log->audit($by, $r, 'ENVELOPE.'.strtoupper($to), ['status' => $from], ['status' => $to, 'envelope' => $env->id]);
        if ($to === 'signed') {
            $this->notifier->tell($r, 'signature.signed', "وصلت الاتفاقية الموقَّعة للطلب {$r->number} ({$r->company_name}) — بانتظار التأكيد", "The signed agreement of request {$r->number} ({$r->company_name}) arrived — waiting to be confirmed", $by,
                ['roles' => $this->waitingRoles($r), 'users' => [$r->requester_id, ...$this->waitingUsers($r)]]);
        } elseif (in_array($to, ['declined', 'expired'], true)) {
            $what = $to === 'declined' ? ['رفض العميل توقيع', 'The customer declined to sign'] : ['انتهت مهلة توقيع', 'The time to sign ran out for'];
            $this->notifier->tell($r, "signature.{$to}", "{$what[0]} اتفاقية الطلب {$r->number} ({$r->company_name})", "{$what[1]} the agreement of request {$r->number} ({$r->company_name})", $by,
                ['roles' => $this->waitingRoles($r), 'users' => [$r->requester_id, ...$this->waitingUsers($r)]]);
        }
    }

    /** The request carries where its signature stands: the envelope that waits, else the signed one, else the last one. */
    private function sync(CreditRequest $r): void
    {
        $status = $this->current($r)?->status ?? ($this->signedEnvelope($r) ? 'signed'
            : (CreditEnvelope::where('request_id', $r->id)->orderByDesc('created_at')->orderByDesc('id')->value('status') ?? 'none'));
        if ($r->signature_status !== $status) {
            $r->forceFill(['signature_status' => $status])->save();
        }
    }

    /** @return string[] the roles asked to decide the stage the request stands in */
    private function waitingRoles(CreditRequest $r): array
    {
        return DB::table('credit_approvals')->where('request_id', $r->id)->where('stage_id', $r->stage_id)->where('round', $r->round)->whereIn('state', Flow::LINE_OPEN)
            ->whereNotNull('role_key')->distinct()->pluck('role_key')->all();
    }

    /** @return string[] the people asked by name to decide the stage the request stands in */
    private function waitingUsers(CreditRequest $r): array
    {
        return DB::table('credit_approvals')->where('request_id', $r->id)->where('stage_id', $r->stage_id)->where('round', $r->round)->whereIn('state', Flow::LINE_OPEN)
            ->whereNotNull('user_id')->distinct()->pluck('user_id')->all();
    }

    private function envelope(CreditRequest $r, string $id): CreditEnvelope
    {
        return CreditEnvelope::where('request_id', $r->id)->where('id', $id)->lockForUpdate()->first()
            ?? throw AppError::notFound('CREDIT_ENVELOPE_NOT_FOUND', 'مظروف التوقيع غير موجود على هذا الطلب', 'The signature envelope is not on this request');
    }

    private static function ended(CreditEnvelope $env): AppError
    {
        return AppError::rule('CREDIT_ENVELOPE_CLOSED', "انتهى هذا المظروف ({$env->status}) — أصدر مظروفًا جديدًا", "This envelope has ended ({$env->status}) — issue a new one", ['status' => $env->status]);
    }

    private static function reason(?string $reason): string
    {
        $reason = Text::clean($reason, 500);
        if ($reason === null || mb_strlen($reason) < 3) {
            throw AppError::validation('CREDIT_REASON_REQUIRED', 'اكتب السبب (3 أحرف على الأقل)', 'A reason is required (3 characters at least)', [['path' => 'reason', 'message' => 'required']]);
        }

        return $reason;
    }
}
