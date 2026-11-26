<?php

namespace App\Tax\Services;

use App\Finance\Models\CustomerInvoice;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Tax\Models\Einvoice;
use App\Tax\Models\EinvoiceUnit;
use App\Tax\Zatca\Certificate;
use App\Tax\Zatca\Qr;
use App\Tax\Zatca\Signer;
use App\Tax\Zatca\ZatcaClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The queue to the tax authority. A document that is `queued` is sent by the unit that made it: a standard document
 * to the clearance service, a simplified one to the reporting service (and a standard one to reporting too when the
 * authority answers that clearance is switched off).
 *
 *   accepted                 → cleared / reported                (the stamped copy the authority returns is kept)
 *   accepted with warnings   → accepted_warnings                 (the warnings are kept for a person to read)
 *   rejected                 → rejected                          (final for this document: a corrected one is made)
 *   no answer, 5xx, 429 …    → stays queued, sent again later    (1, 2, 5, 15, 30, 60, 120, 240, 480 minutes)
 *   401 / 403                → stays queued, an hour later       (the certificate is not accepted: a person looks)
 *
 * ORDER. A unit's documents leave in the order of their counter; a document that must wait makes the ones after it
 * wait as well. A rejected document does not hold the queue.
 *
 * NO TRANSACTION IS OPEN while the authority is called: a document is first claimed (its next attempt is moved a few
 * minutes ahead under a row lock), then sent, then its answer is recorded. A process that dies in between loses
 * nothing: the document is simply sent again, and the authority counts a document once (by UUID and hash).
 */
class EinvoiceSubmitter
{
    public const BACKOFF_MINUTES = [1, 2, 5, 15, 30, 60, 120, 240, 480];

    public function __construct(
        private readonly ZatcaClient $client,
        private readonly EinvoiceService $register,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
    ) {}

    /** A person puts a document that was made but not sent into the queue (its unit is onboarded now). */
    public function queue(AuthUser $user, string $id): array
    {
        DB::transaction(function () use ($user, $id) {
            $e = $this->register->find($id, true);
            $this->queueable($e, EinvoiceUnit::findOrFail($e->unit_id));
            $e->update(['status' => 'queued', 'next_attempt_at' => now(), 'last_error' => null]);
            $this->audit->status($user, 'Einvoice', $e->id, $e->doc_number, 'not_sent', 'queued');
        });

        return $this->register->get($id);
    }

    /** Every current document that was made but never sent, from a day on — what waits since before the unit was onboarded. */
    public function queueBacklog(AuthUser $user, ?string $from = null): array
    {
        $unit = $this->register->unit();
        $this->mustSend($unit);
        $since = $from ? Carbon::parse($from, EinvoiceDocuments::TIMEZONE)->startOfDay()->utc() : null;
        $queued = $skipped = 0;
        $ids = Einvoice::where('unit_id', $unit->id)->where('is_current', true)->where('status', 'not_sent')->when($since, fn ($q) => $q->where('issue_at', '>=', $since))->orderBy('icv')->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($user, $id, $unit, &$queued, &$skipped) {
                $e = $this->register->find($id, true);
                try {
                    $this->queueable($e, $unit);
                } catch (AppError) {
                    $skipped++;

                    return;
                }
                $e->update(['status' => 'queued', 'next_attempt_at' => now(), 'last_error' => null]);
                $this->audit->status($user, 'Einvoice', $e->id, $e->doc_number, 'not_sent', 'queued', 'backlog');
                $queued++;
            });
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /** Takes a document out of the queue before it leaves. */
    public function unqueue(AuthUser $user, string $id, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw AppError::validation('REASON_REQUIRED', 'اكتب السبب (5 أحرف على الأقل)', 'A reason is required', [['path' => 'reason', 'message' => 'هذا الحقل إلزامي']]);
        }
        DB::transaction(function () use ($user, $id, $reason) {
            $e = $this->register->find($id, true);
            if ($e->status !== 'queued') {
                throw AppError::rule('EINVOICE_STATE', 'الفاتورة ليست في قائمة الإرسال', 'The e-invoice is not queued', ['status' => $e->status]);
            }
            $e->update(['status' => 'not_sent', 'next_attempt_at' => null]);
            $this->audit->status($user, 'Einvoice', $e->id, $e->doc_number, 'queued', 'not_sent', $reason);
        });

        return $this->register->get($id);
    }

    /** Sends the unit's queue now, up to this document (the ones before it leave first). */
    public function sendNow(AuthUser $user, string $id): array
    {
        $e = $this->register->find($id);
        if ($e->status !== 'queued') {
            throw AppError::rule('EINVOICE_STATE', 'الفاتورة ليست في قائمة الإرسال', 'The e-invoice is not queued', ['status' => $e->status]);
        }
        $unit = EinvoiceUnit::findOrFail($e->unit_id);
        $this->mustSend($unit);
        $this->drain($unit, 200, microtime(true) + 50, true, $e->icv, $user);

        return $this->register->get($id);
    }

    /**
     * Sends what is due, for at most `$seconds` (one document at least).
     *
     * @return array{sent:int, accepted:int, warnings:int, rejected:int, retry:int, withdrawn:int, units:int}
     */
    public function run(int $limit = 50, float $seconds = 20.0): array
    {
        $total = ['sent' => 0, 'accepted' => 0, 'warnings' => 0, 'rejected' => 0, 'retry' => 0, 'withdrawn' => 0, 'units' => 0];
        if (! (bool) config('tax.einvoice.send', true)) {
            return $total;
        }
        $until = microtime(true) + $seconds;
        foreach (EinvoiceUnit::where('status', 'active')->where('sending', true)->orderBy('created_at')->get() as $unit) {
            if (! $unit->onboarded()) {
                continue;
            }
            $total['units']++;
            foreach ($this->drain($unit, $limit, $until, false, null, null) as $key => $n) {
                $total[$key] += $n;
            }
        }

        return $total;
    }

    /** @return array{sent:int, accepted:int, warnings:int, rejected:int, retry:int, withdrawn:int} */
    private function drain(EinvoiceUnit $unit, int $limit, float $until, bool $force, ?int $upToIcv, ?AuthUser $by): array
    {
        $out = ['sent' => 0, 'accepted' => 0, 'warnings' => 0, 'rejected' => 0, 'retry' => 0, 'withdrawn' => 0];
        $ids = Einvoice::where('unit_id', $unit->id)->where('status', 'queued')->when($upToIcv, fn ($q) => $q->where('icv', '<=', $upToIcv))->orderBy('icv')->limit($limit)->get(['id', 'next_attempt_at']);
        foreach ($ids as $row) {
            if ($out['sent'] > 0 && microtime(true) >= $until) {
                break;
            }
            if (! $force && $row->next_attempt_at !== null && $row->next_attempt_at->isFuture()) {
                break; // the head of the queue is not due: the ones after it wait with it
            }
            $outcome = $this->send((string) $row->id, $by);
            if ($outcome === null) {
                continue;
            }
            if ($outcome === 'withdrawn') {
                $out['withdrawn']++;

                continue;
            }
            $out['sent']++;
            $key = ['accepted' => 'accepted', 'warnings' => 'warnings', 'rejected' => 'rejected'][$outcome] ?? 'retry';
            $out[$key]++;
            if ($key === 'retry') {
                break;
            }
        }

        return $out;
    }

    /** One document: claim, send, record. @return string|null the outcome, `withdrawn`, or null when there was nothing to send */
    private function send(string $id, ?AuthUser $by): ?string
    {
        $claim = DB::transaction(function () use ($id, $by) {
            $e = $this->register->find($id, true);
            $unit = EinvoiceUnit::find($e->unit_id);
            if ($e->status !== 'queued' || ! $unit || ! $unit->onboarded() || ! $unit->sending) {
                return null;
            }
            // an invoice cancelled in OPS before its e-invoice left is not sent: the document stays in the chain, withdrawn
            if ($e->doc_type === 'CustomerInvoice' && CustomerInvoice::whereKey($e->doc_id)->value('status') === 'cancelled') {
                $e->update(['status' => 'withdrawn', 'next_attempt_at' => null, 'last_error' => 'الفاتورة أُلغيت في العمليات قبل إرسالها']);
                $this->audit->status($by, 'Einvoice', $e->id, $e->doc_number, 'queued', 'withdrawn', 'source invoice cancelled');

                return 'withdrawn';
            }
            if (! $e->signed) {
                // made before the unit had its certificate: stamped now, as the same document
                $basic = Qr::decode((string) $e->qr);
                $stamp = Signer::stamp((string) $e->xml, (string) $unit->private_key, Certificate::fromToken((string) $unit->production_token),
                    ['seller' => $basic[1] ?? '', 'vatNo' => $basic[2] ?? '', 'timestamp' => $basic[3] ?? '', 'total' => $basic[4] ?? '', 'vat' => $basic[5] ?? ''], $e->profile === 'simplified');
                if ($stamp['hash'] !== $e->hash) {
                    throw AppError::rule('EINVOICE_HASH_CHANGED', "ختم الفاتورة {$e->doc_number} غيّر بصمتها — لم تُرسل", "Stamping e-invoice {$e->doc_number} changed its hash — it was not sent");
                }
                $e->xml = $stamp['xml'];
                $e->qr = $stamp['qr'];
                $e->signed = true;
            }
            $e->attempts = (int) $e->attempts + 1;
            $e->next_attempt_at = now()->addMinutes(3); // if this process dies before the answer is recorded, the document is sent again
            $e->sent_at = now();
            $e->save();

            return ['e' => $e, 'unit' => $unit];
        });
        if (! is_array($claim)) {
            return $claim;
        }
        /** @var Einvoice $e */
        /** @var EinvoiceUnit $unit */
        ['e' => $e, 'unit' => $unit] = $claim;
        $api = $e->profile === 'standard' ? 'clearance' : 'reporting';
        $call = function (string $api) use ($e, $unit, $by) {
            $t = microtime(true);
            $result = $this->client->submit($api, $unit->environment, (string) $unit->production_token, (string) $unit->production_secret, $e->uuid, $e->hash, (string) $e->xml);
            $this->register->logAttempt($e, $unit, $result, (int) round((microtime(true) - $t) * 1000), $by);

            return $result;
        };
        $result = $call($api);
        if ($result['outcome'] === 'clearance_off') {
            $result = $call('reporting'); // the authority switched clearance off: standard documents are reported
        }

        return DB::transaction(function () use ($e, $result, $by) {
            $e = $this->register->find($e->id, true);
            $from = $e->status;
            $first = collect($result['messages'])->firstWhere('type', 'ERROR') ?? collect($result['messages'])->first();
            $said = $first ? trim(($first['code'] ? $first['code'].': ' : '').$first['message']) : $result['error'];
            $base = ['http_status' => $result['http'] ?: null, 'messages' => $result['messages'] ?: null];
            switch ($result['outcome']) {
                case 'accepted':
                case 'warnings':
                    $warnings = count(array_filter($result['messages'], fn (array $m) => $m['type'] === 'WARNING'));
                    $cleared = $result['clearedInvoice'] ? base64_decode($result['clearedInvoice'], true) : null;
                    $e->update($base + ['status' => $result['outcome'] === 'warnings' ? 'accepted_warnings' : ($result['api'] === 'clearance' ? 'cleared' : 'reported'), 'via' => $result['api'], 'warnings' => $warnings,
                        'accepted_at' => now(), 'next_attempt_at' => null, 'last_error' => null] + ($cleared ? ['cleared_xml' => $cleared] : []));
                    break;
                case 'rejected':
                    $e->update($base + ['status' => 'rejected', 'via' => $result['api'], 'next_attempt_at' => null, 'last_error' => mb_substr((string) $said, 0, 1000)]);
                    $this->notify->activity($by, 'Einvoice', $e->id, $e->doc_number, "رفضت الهيئة الفاتورة الإلكترونية {$e->doc_number}: ".mb_substr((string) $said, 0, 200), "The authority rejected e-invoice {$e->doc_number}", ['finance']);
                    break;
                case 'unauthorized':
                    $e->update($base + ['next_attempt_at' => now()->addMinutes(60), 'last_error' => 'الهيئة لم تقبل شهادة الوحدة (HTTP '.$result['http'].') — راجع ربط الوحدة']);
                    if ($e->attempts === 1) {
                        $this->notify->activity($by, 'Einvoice', $e->id, $e->doc_number, 'الهيئة لم تقبل شهادة وحدة الفوترة الإلكترونية — الإرسال متوقف إلى أن تُراجع', 'The authority refused the e-invoicing unit\'s certificate', ['finance']);
                    }
                    break;
                default:
                    $wait = self::BACKOFF_MINUTES[min($e->attempts, count(self::BACKOFF_MINUTES)) - 1] ?? 1;
                    $e->update($base + ['next_attempt_at' => now()->addMinutes($wait), 'last_error' => mb_substr((string) ($said ?: 'لا رد من خدمة الهيئة'), 0, 1000)]);
            }
            if ($e->status !== $from) {
                $this->audit->status($by, 'Einvoice', $e->id, $e->doc_number, $from, $e->status, $said ? mb_substr((string) $said, 0, 300) : null);
            }

            return in_array($result['outcome'], ['accepted', 'warnings', 'rejected'], true) ? $result['outcome'] : 'retry';
        });
    }

    private function mustSend(EinvoiceUnit $unit): void
    {
        if (! $unit->onboarded()) {
            throw AppError::rule('EINVOICE_NOT_ONBOARDED', 'وحدة الفوترة غير مربوطة بالهيئة — لا يُرسل شيء قبل إتمام الربط', 'The e-invoicing unit is not onboarded — nothing is sent before it is');
        }
        if (! $unit->sending) {
            throw AppError::rule('EINVOICE_SENDING_PAUSED', 'الإرسال موقوف لهذه الوحدة — فعّله أولًا', 'Sending is paused for this unit — switch it on first');
        }
    }

    private function queueable(Einvoice $e, EinvoiceUnit $unit): void
    {
        $this->mustSend($unit);
        if ($e->status !== 'not_sent' || ! $e->is_current) {
            throw AppError::rule('EINVOICE_STATE', 'تُضاف إلى قائمة الإرسال الفاتورة السارية التي لم تُرسل فقط', 'Only a current, not-sent e-invoice is queued', ['status' => $e->status]);
        }
        $errors = array_values(array_filter((array) $e->issues, fn ($i) => ($i['level'] ?? null) === 'error'));
        if ($errors) {
            throw AppError::rule('EINVOICE_HAS_ERRORS', "الفاتورة {$e->doc_number} ينقصها ما تشترطه الهيئة ({$errors[0]['code']}) — أكمل البيانات ثم أعد إصدارها", "E-invoice {$e->doc_number} lacks what the authority requires ({$errors[0]['code']}) — complete the data, then re-make it", ['issues' => $errors]);
        }
    }
}
