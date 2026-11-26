<?php

namespace App\Credit\Support;

use App\Credit\Models\CreditRequest;
use App\Models\AuditLog;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\Log;

/**
 * Who may read what is personal and confidential on a credit request: the bank details (account number, IBAN), the
 * national identity numbers (the owner's, the responsible persons') and the content — and the file names — of the
 * nine papers and of the signed agreement.
 *
 *   - the people who review, approve or run the section (`credit.review`, `credit.approve`, `credit.manage`) read them;
 *   - the requester reads what HE typed or uploaded on the request he entered, while that request is still open — it is
 *     his to complete and correct; a value somebody else added, and everything once the request is closed, is not his;
 *   - everybody else gets the last four characters behind dots, and no file.
 *
 * A value never travels by accident: every answer of the API carries the masked form; the real one is read only through
 * `GET /api/credit/requests/{id}/private` and the file endpoint, and each such read is written to the audit trail
 * (who, which request, how many values — never the values). The same goes for the audit trail, the timeline, the
 * notifications and the logs: names of fields and counts only.
 */
final class Privacy
{
    public const PRIVILEGED = ['credit.review', 'credit.approve', 'credit.manage'];

    /** API keys that carry a personal value, wherever they stand in a payload (the request itself or one of its lines). */
    public const KEYS = ['ownerNationalId', 'nationalId', 'accountNumber', 'iban'];

    public static function privileged(AuthUser $u): bool
    {
        foreach (self::PRIVILEGED as $permission) {
            if ($u->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function isRequester(AuthUser $u, CreditRequest $r): bool
    {
        return $r->requester_id !== null && strcasecmp((string) $r->requester_id, $u->id) === 0;
    }

    /** May this user read a personal value of the request that `$enteredBy` typed (or a paper he uploaded)? */
    public static function sees(AuthUser $u, CreditRequest $r, ?string $enteredBy): bool
    {
        if (self::privileged($u)) {
            return true;
        }

        return self::isRequester($u, $r) && Flow::isOpen($r->state) && $enteredBy !== null && strcasecmp($enteredBy, $u->id) === 0;
    }

    /** Is there anything on this request the user could ask to see in full? */
    public static function mayReveal(AuthUser $u, CreditRequest $r): bool
    {
        return self::privileged($u) || (self::isRequester($u, $r) && Flow::isOpen($r->state));
    }

    /**
     * Refuses a read of what is personal — and writes the attempt to the audit trail like every 403 of the API. Call it
     * outside a transaction (it is only ever reached from a GET): the line must stay.
     */
    public static function refuse(AuthUser $u, CreditRequest $r, string $what): never
    {
        $request = request();
        AuditLog::create([
            'user_id' => $u->id, 'username' => $u->username, 'action' => 'SECURITY.UNAUTHORIZED', 'entity_type' => 'endpoint',
            'entity_id' => mb_substr($request->method().' /'.$request->path(), 0, 250), 'old_value' => implode(',', $u->roles),
            'new_value' => "denied: {$what} of {$r->number}", 'request_id' => $u->requestId,
        ]);
        Log::warning("403 {$u->username} {$request->method()} /{$request->path()} private={$what}");
        throw AppError::forbidden('CREDIT_PRIVATE',
            'بيانات شخصية وسرّية (هوية، حساب بنكي، مستندات) — يطّلع عليها مراجعو الائتمان ومعتمدوه، ومن أدخلها ما دام الطلب مفتوحًا',
            'Personal and confidential data (identity, bank account, documents) — read by the credit reviewers and approvers, and by whoever entered it while the request is open');
    }

    /** All but the last four characters hidden. */
    public static function mask(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = mb_strlen($v);

        return $n <= 4 ? str_repeat('•', $n) : str_repeat('•', min(12, $n - 4)).mb_substr($v, -4);
    }

    /**
     * A payload as it may be written to the audit trail or the timeline: a personal value is replaced by the fact that
     * one was given. Works through nested lists (the lines of a request).
     */
    public static function scrub(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, self::KEYS, true)) {
                $out[$key] = $value === null || $value === '' ? null : '•••';
            } elseif (is_array($value)) {
                $out[$key] = self::scrub($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
