<?php

namespace App\Credit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A first workflow for the credit section: the stages a request is worked in and the five it can stand in otherwise
 * (draft, approved, rejected, changes requested, cancelled), and the usual contractual payment methods — only into
 * tables that are still empty, so it never adds to what people (or Odoo) already put there.
 *
 * Unlike the CRM pipeline it is written also where the section follows Odoo: Odoo refuses the connection user the
 * records of its credit module today, so without it the section could not take a single request. The day Odoo's stages
 * arrive, the projection adopts a stage of the same code (and the single stage of a state) instead of doubling it.
 *
 *   submitted          مراجعة المبيعات والائتمان — any holder of credit.review / credit.approve, 8 working hours,
 *                      the papers must be complete before it is left
 *   in_review          المراجعة المالية — the finance role, 16 working hours, escalated to the general manager
 *   pending_signature  توقيع الاتفاقية — the customer signs (the agreement is issued on entering), fields locked
 *   ceo_approval       اعتماد الإدارة العليا — the general manager role (credit.approve), 16 working hours
 *
 * Idempotent. Runs from the migration; `defaults()` is also what a test calls.
 */
final class CreditBootstrap
{
    /**
     * code => [Arabic, English, state, sequence, role, approval mode, target hours, remind before, escalate after, escalation role,
     *          documents, signature, issue the agreement on entering, lock the fields, shown to the customer, folded]
     */
    public const STAGES = [
        'draft' => ['مسودة', 'Draft', 'draft', 0, null, 'any_user', null, null, null, null, false, false, false, false, true, false],
        'sales_review' => ['مراجعة المبيعات والائتمان', 'Sales & credit review', 'submitted', 10, null, 'any_user', 8, 2, 8, null, true, false, false, false, true, false],
        'finance_review' => ['المراجعة المالية', 'Finance review', 'in_review', 20, 'finance', 'any_user', 16, 4, 8, 'gm', false, false, false, false, true, false],
        'signature' => ['توقيع الاتفاقية', 'Agreement signature', 'pending_signature', 30, null, 'any_user', 40, 8, 16, null, false, true, true, true, true, false],
        'ceo_approval' => ['اعتماد الإدارة العليا', 'CEO approval', 'ceo_approval', 40, 'gm', 'any_user', 16, 4, 8, null, false, false, false, true, true, false],
        'approved' => ['معتمد', 'Approved', 'approved', 90, null, 'any_user', null, null, null, null, false, false, false, true, true, true],
        'changes_requested' => ['مطلوب تعديل', 'Changes requested', 'changes_requested', 91, null, 'any_user', null, null, null, null, false, false, false, false, true, true],
        'rejected' => ['مرفوض', 'Rejected', 'rejected', 92, null, 'any_user', null, null, null, null, false, false, false, true, true, true],
        'cancelled' => ['ملغى', 'Cancelled', 'cancelled', 93, null, 'any_user', null, null, null, null, false, false, false, true, false, true],
    ];

    /** code => [Arabic, English, sequence] */
    public const PAYMENT_METHODS = [
        'bank_transfer' => ['تحويل بنكي', 'Bank transfer', 10],
        'cheque' => ['شيك', 'Cheque', 20],
        'sadad' => ['سداد', 'SADAD', 30],
        'cash' => ['نقدًا', 'Cash', 40],
    ];

    /** @return array{stages:int, paymentMethods:int} what was added */
    public static function ensure(): array
    {
        if (! Schema::hasTable('credit_stages')) {
            return ['stages' => 0, 'paymentMethods' => 0];
        }

        return self::defaults();
    }

    /** @return array{stages:int, paymentMethods:int} */
    public static function defaults(): array
    {
        $out = ['stages' => 0, 'paymentMethods' => 0];
        $now = now();
        if (DB::table('credit_stages')->count() === 0) {
            foreach (self::STAGES as $code => [$ar, $en, $state, $sequence, $role, $mode, $sla, $before, $after, $escalation, $documents, $signature, $auto, $lock, $portal, $fold]) {
                DB::table('credit_stages')->insert([
                    'id' => (string) Str::ulid(), 'code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'state' => $state, 'sequence' => $sequence, 'active' => true, 'fold' => $fold,
                    'responsible_role' => $role, 'approval_mode' => $mode, 'sla_hours' => $sla, 'reminder_before_hours' => $before, 'escalate_after_hours' => $after, 'escalation_role' => $escalation,
                    'require_documents' => $documents, 'require_signature' => $signature, 'auto_send_signature' => $auto, 'lock_request_fields' => $lock, 'portal_visible' => $portal,
                    'created_by' => 'system', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $out['stages']++;
            }
        }
        if (DB::table('credit_payment_methods')->count() === 0) {
            foreach (self::PAYMENT_METHODS as $code => [$ar, $en, $sequence]) {
                DB::table('credit_payment_methods')->insert(['id' => (string) Str::ulid(), 'code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'sequence' => $sequence, 'active' => true,
                    'created_by' => 'system', 'created_at' => $now, 'updated_at' => $now]);
                $out['paymentMethods']++;
            }
        }

        return $out;
    }
}
