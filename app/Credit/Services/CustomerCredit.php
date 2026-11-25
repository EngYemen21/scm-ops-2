<?php

namespace App\Credit\Services;

use App\Credit\Models\CreditRequest;
use App\Credit\Support\Text;
use App\Models\Customer;
use App\Services\Master\PartnersService;
use App\Services\Sales\CreditService;
use App\Support\AppError;
use App\Support\AuthUser;

/**
 * From an approved request to the customer's credit: the approved limit and payment term are written on the customer
 * — ONCE, and only through the services that own the customer and its credit.
 *
 * THE ONE DOOR. The change is made by `PartnersService::updateCustomer` (the same call the customer form makes), so
 * every rule the customer service has holds here too and nothing of it is repeated or bypassed: a customer whose
 * commercial data another system owns is not changed (CUSTOMER_OWNED_BY_SOURCE), credit terms need a positive limit
 * (CUSTOMER_CREDIT_LIMIT), credit is never tightened under what the customer owes (CUSTOMER_LIMIT_BELOW_BALANCE,
 * CUSTOMER_HAS_BALANCE) — and its own audit line (CUSTOMER.UPDATE) is written in the name of the person who approved.
 * `CreditService::sync` then rewrites the customer's exposure from its sources.
 *
 * The authority for the change is the final approval (`credit.approve`): the approver is not also asked for
 * `customer.manage` — granting credit is exactly what the approval is.
 *
 * WHAT THE REQUEST KEEPS. `applied_changes`: the limit and the terms before and after, the exposure at that moment,
 * who and when. When the customer service refuses, the approval stands and the request says so: `apply_status =
 * blocked` with the code and the words of the refusal; a person applies it again once the cause is gone.
 *
 * NEVER SILENTLY UNDONE. Nothing in the section takes the limit back by itself: revoking an approved request leaves
 * the customer exactly as it is. Putting the previous values back is `restore()` — a person's explicit step, through
 * the same door, and only while the customer still holds what this request wrote (a later change by anybody is never
 * overwritten).
 *
 * WHAT HAS NO DOOR YET. The customer's payment days for invoice due dates (`customers.payment_terms_days`, read by
 * finance) are written by no existing service; this class does not write the column behind their back. The approved
 * days stand in the terms text ("آجل 30") and on the request; see the section's hand-over notes ("wiring").
 */
class CustomerCredit
{
    public function __construct(private readonly PartnersService $partners, private readonly CreditService $credit, private readonly CreditLog $log) {}

    /** The customer's payment term for a number of credit days, in the words the sales module uses ("آجل 30"). */
    public static function termsFor(int $days): string
    {
        return "آجل {$days}";
    }

    /**
     * Writes the approved limit and term on the customer. Call it inside the transaction that holds the request's row.
     * A refusal of the customer service is recorded, not thrown: the approval is a fact either way.
     *
     * @return array{status:string, changed?:int}
     */
    public function apply(AuthUser $by, CreditRequest $r): array
    {
        if ($r->state !== 'approved') {
            throw AppError::rule('CREDIT_NOT_APPROVED', "الطلب {$r->number} غير معتمد — لا يُطبَّق على العميل شيء قبل الاعتماد النهائي", "Request {$r->number} is not approved — nothing reaches the customer before the final approval");
        }
        if (in_array($r->apply_status, ['applied', 'restored'], true)) {
            throw AppError::rule('CREDIT_ALREADY_APPLIED', "طُبِّق الطلب {$r->number} على العميل مسبقًا — لا يُطبَّق مرتين", "Request {$r->number} was already applied to the customer — it is applied once");
        }
        $customer = $r->customer_id ? Customer::whereKey($r->customer_id)->lockForUpdate()->first() : null;
        if (! $customer) {
            return $this->blocked($by, $r, 'CREDIT_NO_CUSTOMER', 'الطلب غير مربوط بعميل في نظام العمليات — اربطه بعميل ثم طبِّق الاعتماد', 'The request is not linked to an OPS customer — link it, then apply the approval');
        }
        $limit = round((float) $r->approved_credit_limit, 2);
        $terms = $r->approved_term ?: self::termsFor((int) $r->approved_payment_days);
        $before = ['creditLimit' => Text::money($customer->credit_limit), 'terms' => $customer->terms];
        $exposure = $this->credit->exposure($customer->id);
        try {
            $out = $this->partners->updateCustomer($by, $customer->id, ['creditLimit' => $limit, 'terms' => $terms]);
        } catch (AppError $e) {
            return $this->blocked($by, $r, $e->errorCode, $e->messageAr, $e->messageEn ?? $e->messageAr);
        }
        $this->credit->sync($customer->id);
        $changes = [
            'creditLimit' => ['from' => $before['creditLimit'], 'to' => Text::money($limit)], 'terms' => ['from' => $before['terms'], 'to' => $terms],
            'paymentDays' => $r->approved_payment_days !== null ? (int) $r->approved_payment_days : null, 'changed' => (int) ($out['changed'] ?? 0),
            'exposure' => Text::money($exposure['exposure']), 'customerCode' => $customer->code,
        ];
        $r->forceFill(['apply_status' => 'applied', 'applied_at' => now(), 'applied_by_id' => $by->id, 'applied_by' => $by->nameAr, 'applied_changes' => $changes, 'apply_error' => null])->save();
        $this->log->event($r, 'applied', $by, null, $changes);
        $this->log->audit($by, $r, 'APPLY', ['creditLimit' => $before['creditLimit'], 'terms' => $before['terms']], ['creditLimit' => Text::money($limit), 'terms' => $terms, 'customer' => $customer->code]);

        return ['status' => 'applied', 'changed' => $changes['changed']];
    }

    /**
     * Puts back the limit and the terms the customer had before this request — a person's explicit step after the
     * approval was revoked. A refusal of the customer service is the refusal of this step: nothing is recorded.
     */
    public function restore(AuthUser $by, CreditRequest $r, ?string $reason): void
    {
        if ($r->state !== 'cancelled' || $r->approved_at === null) {
            throw AppError::rule('CREDIT_RESTORE_STATE', "تُعاد قيم العميل السابقة بعد إلغاء اعتماد الطلب فقط — حالة الطلب {$r->number} الآن {$r->state}", "The customer's previous values are put back only after the approval was revoked — request {$r->number} is {$r->state}");
        }
        if ($r->apply_status !== 'applied') {
            throw AppError::rule('CREDIT_NOTHING_TO_RESTORE', $r->apply_status === 'restored' ? 'أُعيدت قيم العميل السابقة مسبقًا' : 'لم يُطبَّق هذا الطلب على العميل، فلا شيء يُعاد',
                $r->apply_status === 'restored' ? 'The previous values were already put back' : 'This request was never applied to the customer: there is nothing to put back');
        }
        $customer = Customer::whereKey($r->customer_id)->lockForUpdate()->first()
            ?? throw AppError::notFound('CUSTOMER_NOT_FOUND', 'العميل غير موجود', 'Customer not found');
        $applied = (array) $r->applied_changes;
        $now = ['creditLimit' => Text::money($customer->credit_limit), 'terms' => $customer->terms];
        if ($now['creditLimit'] !== ($applied['creditLimit']['to'] ?? null) || (string) $now['terms'] !== (string) ($applied['terms']['to'] ?? '')) {
            // somebody changed the customer since: that decision is not this request's to overwrite
            throw AppError::rule('CREDIT_CUSTOMER_CHANGED_SINCE', "تغيّر حد العميل أو شرط سداده بعد تطبيق الطلب {$r->number} — لا تُكتب القيم السابقة فوق تغيير لاحق. عدّل العميل من بطاقته",
                "The customer's limit or terms changed after request {$r->number} was applied — the previous values are not written over a later change. Edit the customer from its card", ['now' => $now, 'applied' => ['creditLimit' => $applied['creditLimit']['to'] ?? null, 'terms' => $applied['terms']['to'] ?? null]]);
        }
        $previous = ['creditLimit' => (float) ($applied['creditLimit']['from'] ?? 0), 'terms' => ($applied['terms']['from'] ?? null) ?: PartnersService::CASH_TERMS];
        $this->partners->updateCustomer($by, $customer->id, $previous);
        $this->credit->sync($customer->id);
        $r->forceFill(['apply_status' => 'restored', 'restored_at' => now(), 'restored_by' => $by->nameAr])->save();
        $facts = ['creditLimit' => ['from' => $now['creditLimit'], 'to' => Text::money($previous['creditLimit'])], 'terms' => ['from' => $now['terms'], 'to' => $previous['terms']], 'customerCode' => $customer->code];
        $this->log->event($r, 'customer_restored', $by, Text::clean($reason, 500), $facts);
        $this->log->audit($by, $r, 'RESTORE', ['creditLimit' => $now['creditLimit'], 'terms' => $now['terms']], ['creditLimit' => Text::money($previous['creditLimit']), 'terms' => $previous['terms'], 'customer' => $customer->code]);
    }

    /** @return array{status:string} */
    private function blocked(AuthUser $by, CreditRequest $r, string $code, string $ar, string $en): array
    {
        $error = ['code' => $code, 'messageAr' => $ar, 'messageEn' => $en, 'at' => now()->toJSON(), 'by' => $by->nameAr];
        $r->forceFill(['apply_status' => 'blocked', 'apply_error' => $error])->save();
        $this->log->event($r, 'apply_blocked', $by, null, ['code' => $code, 'messageAr' => $ar, 'messageEn' => $en]);
        $this->log->audit($by, $r, 'APPLY.BLOCKED', null, ['code' => $code]);

        return ['status' => 'blocked'];
    }
}
