<?php

namespace App\Credit;

use App\Credit\Models\CreditApproval;
use App\Credit\Models\CreditBankLine;
use App\Credit\Models\CreditEnvelope;
use App\Credit\Models\CreditEvent;
use App\Credit\Models\CreditFile;
use App\Credit\Models\CreditNotification;
use App\Credit\Models\CreditPaymentMethod;
use App\Credit\Models\CreditRequest;
use App\Credit\Models\CreditResponsible;
use App\Credit\Models\CreditSignatory;
use App\Credit\Models\CreditStage;
use App\Credit\Models\CreditTermsVersion;
use App\Credit\Services\RequestsService;
use App\Credit\Support\Flow;
use App\Credit\Support\People;
use App\Credit\Support\Privacy;
use App\Credit\Support\Slots;
use App\Credit\Support\Text;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Odoo\Projection\Val;
use App\Integration\Services\ExternalRefs;
use App\Models\Customer;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * From the copy of Odoo's "B2B Deferred Credit Account" module (ten models, docs/odoo/shapes/unreadable.txt) to the
 * credit section: payment methods, terms versions, stages, requests with their responsible persons, signatories, bank
 * accounts and nine papers, the approval audit, the signature envelopes and the notification log become records of
 * «حسابات الائتمان الآجل», marked `source_system = 'odoo'` with the Odoo id in `external_ref`. Idempotent.
 *
 * TODAY ODOO REFUSES THESE RECORDS to the connection user: the copy holds their dictionary and no record, so a pass
 * finds nothing (plan() answers ODOO_COPY_INCOMPLETE while the ten models are `skipped`). The day the user is given the
 * access, the mirror copies them and this class loads them; it is written against the field names of the shapes file
 * and proven on records of that shape (tests/Feature/Credit/OdooCreditProjectionTest).
 *
 * LINKING is by identity only. A request's customer is the OPS customer its Odoo partner (or that partner's commercial
 * company) is linked to (`int_external_refs`, `odoo` / `customer`). A person is the OPS user whose login or e-mail is
 * the Odoo user's — one user or none: then the login and the name are kept. A security group of Odoo is an OPS role
 * only when one role is named exactly like it; otherwise its name is kept and a person sets the role (the stage cannot
 * be entered by a request worked in OPS until then). A stage, a payment method of the same CODE as one OPS has — and
 * the single stage of a state a request is sent to (draft, approved…) — is adopted, never doubled.
 *
 * WHAT IS NEVER COPIED: the request's portal access token and URL (a key to the customer portal), the payload of an
 * envelope and of a notification (the mirror does not copy them). Personal values (identity numbers, bank accounts)
 * are kept encrypted like those typed in OPS and never appear in the audit trail or in a count note.
 *
 * A record deleted in Odoo: a request, a stage, a version, a method is archived / marked here and keeps its history; a
 * line of the request's form (responsible, signatory, bank account) is taken off with it; the approval audit and the
 * notification log are never removed.
 */
class OdooCreditProjection extends OdooProjection
{
    public const MODELS = [
        'methods' => 'b2b.credit.payment.method', 'terms' => 'b2b.credit.terms.version', 'stages' => 'b2b.credit.stage', 'requests' => 'b2b.credit.request',
        'responsibles' => 'b2b.credit.responsible.line', 'signatories' => 'b2b.credit.authorization.line', 'banks' => 'b2b.credit.bank.line',
        'approvals' => 'b2b.credit.approval', 'envelopes' => 'b2b.credit.signit.envelope', 'notifications' => 'b2b.credit.notification.log',
    ];

    private const CLASSES = [
        'methods' => CreditPaymentMethod::class, 'terms' => CreditTermsVersion::class, 'stages' => CreditStage::class, 'requests' => CreditRequest::class,
        'responsibles' => CreditResponsible::class, 'signatories' => CreditSignatory::class, 'banks' => CreditBankLine::class,
        'approvals' => CreditApproval::class, 'envelopes' => CreditEnvelope::class, 'notifications' => CreditNotification::class,
    ];

    /** Arabic for what Odoo ships in English. */
    private const ARABIC = [
        'draft' => 'مسودة', 'submitted' => 'مُقدَّم', 'in review' => 'قيد المراجعة', 'pending signature' => 'بانتظار التوقيع', 'ceo approval' => 'اعتماد الإدارة العليا',
        'approved' => 'معتمد', 'rejected' => 'مرفوض', 'changes requested' => 'مطلوب تعديل', 'cancelled' => 'ملغى', 'sales review' => 'مراجعة المبيعات',
        'finance review' => 'المراجعة المالية', 'legal review' => 'المراجعة القانونية', 'bank transfer' => 'تحويل بنكي', 'cheque' => 'شيك', 'check' => 'شيك', 'cash' => 'نقدًا',
        'sadad' => 'سداد', 'promissory note' => 'سند لأمر',
    ];

    private const CHANNELS = ['odoo' => 'in_app', 'email' => 'email', 'whatsapp' => 'whatsapp'];

    /** What Odoo holds on a request and OPS has no column for — kept as it is, when it holds something. */
    private const REQUEST_EXTRA = ['sla_status', 'current_group_id', 'approved_payment_term_id', 'approved_payment_term_snapshot', 'commercial_partner_id', 'company_id'];

    private bool $installed = true;

    /** @var array<string, array<int|string, Model>> per entity: Odoo id → the OPS row */
    private array $rows = [];

    /** @var array<string, array<int, true>> per entity: Odoo ids a plan decided to create */
    private array $planned = [];

    /** @var array<string, string> lower-case username → OPS user id */
    private array $userByName = [];

    /** @var array<string, string> lower-case e-mail → OPS user id */
    private array $userByEmail = [];

    /** @var array<int, array{0:?string, 1:?string, 2:?string}> Odoo user id → [OPS user id, login, name] */
    private array $users = [];

    /** @var array<int, array{0:?string, 1:?string}> Odoo group id → [OPS role, group name] */
    private array $groups = [];

    public function __construct(
        ExternalRefs $refs,
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly People $people,
    ) {
        parent::__construct($refs);
    }

    public function key(): string
    {
        return 'credit';
    }

    /** Parents before children. */
    public function models(): array
    {
        return array_values(self::MODELS);
    }

    protected function begin(): void
    {
        $this->installed = Schema::hasTable('credit_requests');
        if (! $this->installed && ! $this->dry) {
            throw AppError::rule('CREDIT_NOT_INSTALLED', 'جداول قسم الائتمان لم تُنشأ بعد — شغّل الترحيل أولًا (php artisan migrate)', 'The tables of the credit section do not exist yet — run the migration first');
        }
        $this->planned = $this->users = $this->groups = $this->userByName = $this->userByEmail = [];
        foreach (['methods', 'terms', 'stages', 'requests'] as $entity) {
            $this->rows[$entity] = $this->installed ? (self::CLASSES[$entity])::where('source_system', self::SYSTEM)->get()->keyBy('external_ref')->all() : [];
        }
        foreach (DB::table('users')->where('username', 'not like', People::SERVICE_PREFIX.'%')->get(['id', 'username', 'email']) as $u) {
            $this->userByName[mb_strtolower((string) $u->username)] = (string) $u->id;
            if ($u->email) {
                $this->userByEmail[mb_strtolower((string) $u->email)] = (string) $u->id;
            }
        }
    }

    protected function project(OdooRecord $r): void
    {
        match ($r->model) {
            self::MODELS['methods'] => $this->method($r),
            self::MODELS['terms'] => $this->terms($r),
            self::MODELS['stages'] => $this->stage($r),
            self::MODELS['requests'] => $this->request($r),
            self::MODELS['responsibles'] => $this->line('responsibles', $r),
            self::MODELS['signatories'] => $this->line('signatories', $r),
            self::MODELS['banks'] => $this->line('banks', $r),
            self::MODELS['approvals'] => $this->approval($r),
            self::MODELS['envelopes'] => $this->envelope($r),
            self::MODELS['notifications'] => $this->notification($r),
            default => null,
        };
    }

    // ───────────── payment methods, terms versions, stages ─────────────

    private function method(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $code = self::code(Val::text($d['code'] ?? null, 40), $r->odoo_id);
        [$ar, $en] = self::names(Val::text($d['name'] ?? null, 120) ?? Val::text($r->display_name, 120) ?? $code);
        $this->dictionary('methods', $r, ['code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'sequence' => Val::int($d['sequence'] ?? 10), 'active' => $this->alive($r)],
            fn () => CreditPaymentMethod::whereNull('source_system')->where('code', $code)->first());
    }

    private function terms(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $version = Val::text($d['version'] ?? null, 40) ?? "odoo-{$r->odoo_id}";
        $ar = Val::plain($d['terms_ar'] ?? null, 60000);
        $en = Val::plain($d['terms_en'] ?? null, 60000);
        $city = Val::text($d['jurisdiction_city'] ?? null, 120);
        $alive = $this->alive($r);
        $published = Val::at($d['create_date'] ?? null) ?? Val::at($r->write_date);
        $this->dictionary('terms', $r, [
            'name' => Val::text($d['name'] ?? null, 190) ?? $version, 'version' => $version, 'status' => $alive ? 'published' : 'retired',
            'effective_date' => Val::date($d['effective_date'] ?? null) ?? ($published?->toDateString() ?? now()->toDateString()),
            'terms_ar' => $ar, 'terms_en' => $en, 'jurisdiction_city' => $city, 'content_hash' => CreditTermsVersion::hashOf($version, $ar, $en, $city),
            'published_at' => $published, 'published_by' => 'Odoo', 'retired_at' => $alive ? null : ($r->deleted_at ?? Val::at($r->write_date)), 'retired_by' => $alive ? null : 'Odoo',
        ]);
    }

    private function stage(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $code = self::code(Val::text($d['code'] ?? null, 40), $r->odoo_id);
        $state = in_array($d['state_mapping'] ?? null, Flow::STATES, true) ? $d['state_mapping'] : 'submitted';
        [$ar, $en] = self::names(Val::text($d['name'] ?? null, 120) ?? Val::text($r->display_name, 120) ?? $code);
        [$role, $roleName] = $this->group($d['responsible_group_id'] ?? null);
        [$escalation, $escalationName] = $this->group($d['escalation_group_id'] ?? null);
        [$userId, $login, $userName] = $this->user($d['responsible_user_id'] ?? null);
        $hours = fn (string $field) => ($v = Val::float($d[$field] ?? 0)) > 0 ? round($v, 2) : null;
        $values = [
            'code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'state' => $state, 'sequence' => Val::int($d['sequence'] ?? 0), 'active' => $this->alive($r), 'fold' => Val::bool($d['fold'] ?? false),
            'responsible_role_name' => $roleName, 'responsible_user_id' => $userId, 'responsible_user_login' => $login, 'responsible_user_name' => $userName,
            'approval_mode' => in_array($d['approval_mode'] ?? null, Flow::APPROVAL_MODES, true) ? $d['approval_mode'] : 'any_user',
            'sla_hours' => $hours('sla_hours'), 'reminder_before_hours' => $hours('reminder_before_deadline_hours'), 'escalate_after_hours' => $hours('escalate_after_deadline_hours'),
            'escalation_role_name' => $escalationName, 'require_documents' => Val::bool($d['require_documents'] ?? false), 'require_signature' => Val::bool($d['require_signature'] ?? false),
            'auto_send_signature' => Val::bool($d['auto_send_signit'] ?? false), 'lock_request_fields' => Val::bool($d['lock_request_fields'] ?? false), 'portal_visible' => Val::bool($d['portal_visible'] ?? false),
        ];
        // the OPS role a group answers to is OPS's own once a person set it: a pass sets it only when it recognises the group
        if ($role !== null) {
            $values['responsible_role'] = $role;
        }
        if ($escalation !== null) {
            $values['escalation_role'] = $escalation;
        }
        $row = $this->rows['stages'][$r->odoo_id] ?? null;
        $holder = $this->installed ? CreditStage::where('code', $code)->first() : null;
        if ($holder && $row && $holder->id !== $row->id) {
            $values['code'] = $row->code; // Odoo renamed it to a code another stage carries here: it keeps the one it has
        } elseif ($holder && ! $row && $holder->source_system !== null) {
            $values['code'] = self::code(null, $r->odoo_id); // another stage of Odoo still carries the code: this one keeps its id as its code
        }
        $this->dictionary('stages', $r, $values, function () use ($code, $state) {
            return CreditStage::whereNull('source_system')->where('code', $code)->first()
                // the one stage a request is sent to for such a state is the same stage, whatever its code
                ?? (in_array($state, Flow::SINGLE, true) ? CreditStage::whereNull('source_system')->where('state', $state)->where('active', true)->orderBy('sequence')->first() : null);
        }, ['code']);
    }

    /**
     * One record of a small table: created, adopted (an OPS row of the same identity) or brought up to date.
     *
     * @param  string[]  $keepOnAdopt  columns an adopted OPS row keeps (its code may be what other OPS rows refer to)
     */
    private function dictionary(string $entity, OdooRecord $r, array $values, ?callable $twinOf = null, array $keepOnAdopt = []): ?Model
    {
        $row = $this->rows[$entity][$r->odoo_id] ?? null;
        $label = (string) ($values['code'] ?? $values['version'] ?? $r->odoo_id);
        if (! $row) {
            if ($r->deleted_at !== null) {
                $this->count("{$entity}.skippedDeleted");

                return null;
            }
            $twin = $this->installed && $twinOf ? $twinOf() : null;
            $this->count($entity.($twin ? '.linked' : '.created'), $twin ? $label : null);
            if ($this->dry) {
                $this->planned[$entity][$r->odoo_id] = true;

                return null;
            }
            $row = DB::transaction(function () use ($entity, $r, $values, $twin, $label, $keepOnAdopt) {
                $origin = ['source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id];
                if ($twin) {
                    $twin->forceFill(array_diff_key($values, array_flip($keepOnAdopt)) + $origin)->save();
                    $row = $twin;
                } else {
                    $row = new (self::CLASSES[$entity]);
                    $row->forceFill($values + $origin + ['created_by' => 'Odoo'])->save();
                }
                $this->audit->log($this->actor(), ['action' => 'CREDIT.'.strtoupper(rtrim($entity, 's')).($twin ? '.LINK' : '.CREATE'), 'entityType' => class_basename(self::CLASSES[$entity]),
                    'entityId' => $row->getKey(), 'entityNumber' => $label, 'newValue' => ['origin' => 'from Odoo '.self::MODELS[$entity]." #{$r->odoo_id}"]]);

                return $row;
            });
            $this->rows[$entity][$r->odoo_id] = $row;

            return $row;
        }
        if ($r->deleted_at !== null) {
            $values = $entity === 'terms' ? ['status' => 'retired', 'retired_at' => $r->deleted_at, 'retired_by' => 'Odoo'] : ['active' => false];
        }
        $changes = self::changes($row, $values);
        if (! $changes) {
            $this->count("{$entity}.unchanged");

            return $row;
        }
        $this->count($entity.(($changes['active'] ?? null) === false || ($changes['status'] ?? null) === 'retired' ? '.archived' : '.updated'));
        if ($this->dry) {
            return $row;
        }
        DB::transaction(function () use ($entity, $row, $changes, $label) {
            $row->forceFill($changes)->save();
            $this->audit->log($this->actor(), ['action' => 'CREDIT.'.strtoupper(rtrim($entity, 's')).'.UPDATE', 'entityType' => class_basename(self::CLASSES[$entity]), 'entityId' => $row->getKey(),
                'entityNumber' => $label, 'newValue' => ['fields' => array_keys($changes), 'origin' => 'Odoo']]);
        });

        return $row;
    }

    // ───────────── requests ─────────────

    private function request(OdooRecord $r): ?CreditRequest
    {
        $d = (array) $r->data;
        $row = $this->rows['requests'][$r->odoo_id] ?? null;
        $gone = $r->deleted_at !== null;
        if (! $row && $gone) {
            $this->count('requests.skippedDeleted');

            return null;
        }
        $partner = Val::id($d['partner_id'] ?? null);
        $customerId = $this->customer($partner) ?? $this->customer(Val::id($d['commercial_partner_id'] ?? null));
        if ($customerId) {
            $this->count('requests.withCustomer');
        } else {
            $this->count('requests.noCustomer', 'partner #'.($partner ?? '-'));
        }
        [$requesterId, $requesterLogin, $requesterName] = $this->user($d['assigned_user_id'] ?? null);
        $state = in_array($d['state'] ?? null, Flow::STATES, true) ? $d['state'] : 'draft';
        $written = Val::at($r->write_date);
        $termsId = $this->ref('terms', Val::id($d['terms_version_id'] ?? null));
        $values = [
            'source_name' => Val::text($d['name'] ?? null, 60), 'request_date' => Val::date($d['request_date'] ?? null) ?? Val::date($d['create_date'] ?? null) ?? now()->toDateString(),
            'source' => in_array($d['source'] ?? null, Flow::SOURCES, true) ? $d['source'] : 'internal',
            'customer_id' => $customerId, 'partner_ref' => $partner ? (string) $partner : null, 'partner_name' => Val::text(Val::label($d['partner_id'] ?? null), 190),
            'currency' => Val::text(Val::label($d['currency_id'] ?? null), 10) ?? 'SAR', 'stage_id' => $this->ref('stages', Val::id($d['stage_id'] ?? null)), 'state' => $state,
            'requester_id' => $requesterId, 'requester_login' => $requesterLogin, 'requester_name' => $requesterName,
            'submitted_at' => Val::at($d['submitted_at'] ?? null), 'stage_entered_at' => Val::at($d['stage_entered_at'] ?? null), 'sla_deadline' => Val::at($d['sla_deadline'] ?? null),
            'last_reminder_at' => Val::at($d['last_reminder_at'] ?? null), 'sla_checked_at' => Val::at($d['sla_last_checked_at'] ?? null), 'escalation_count' => Val::int($d['escalation_count'] ?? 0),
            'escalated_at' => ($d['sla_status'] ?? null) === 'escalated' && Flow::isOpen($state) ? (($row?->escalated_at) ?? $written) : null,
            'requested_credit_limit' => self::money($d['requested_credit_limit'] ?? null), 'requested_payment_days' => self::days($d['requested_payment_days'] ?? null),
            'monthly_purchase_volume' => self::money($d['monthly_purchase_volume'] ?? null), 'approved_credit_limit' => self::money($d['approved_credit_limit'] ?? null),
            'approved_payment_days' => self::days($d['approved_payment_days'] ?? null), 'approved_term' => Val::text(Val::label($d['approved_payment_term_id'] ?? null), 120),
            'finance_recommendation' => Val::plain($d['finance_recommendation'] ?? null, 8000), 'legal_notes' => Val::plain($d['legal_notes'] ?? null, 8000), 'ceo_notes' => Val::plain($d['ceo_notes'] ?? null, 8000),
            'rejection_reason' => Val::plain($d['rejection_reason'] ?? null, 2000), 'changes_requested_note' => Val::plain($d['changes_requested_note'] ?? null, 2000),
            'attachments_complete' => Val::bool($d['attachments_complete'] ?? false),
            'signature_status' => in_array($d['signit_status'] ?? null, Flow::ENVELOPE_STATUSES, true) || ($d['signit_status'] ?? null) === 'none' ? $d['signit_status'] : 'none',
            'terms_version_id' => $termsId,
            'extra' => array_filter(array_intersect_key($d, array_flip(self::REQUEST_EXTRA)), fn ($v) => $v !== false && $v !== null && $v !== '') ?: null,
            'source_updated_at' => $written, 'source_deleted_at' => $gone ? $r->deleted_at : null,
        ];
        foreach (RequestsService::TEXT as $key => [$column, $max]) {
            $values[$column] = Val::text($d[$column] ?? null, $max);
        }
        if ($state === 'approved' && ! $row?->approved_at) {
            $values['approved_at'] = $written;
        }
        if ($state === 'rejected' && ! $row?->rejected_at) {
            $values['rejected_at'] = $written;
        }
        if ($state === 'cancelled' && ! $row?->cancelled_at) {
            $values['cancelled_at'] = $written;
        }
        // the terms the customer accepted are frozen on the request the first time they are known, and when Odoo names another version
        if ($termsId && ($row?->terms_version_id !== $termsId || ! $row?->terms_snapshot) && ($version = $this->rows['terms'][Val::id($d['terms_version_id'] ?? null)] ?? null)) {
            $values['terms_snapshot'] = $version->snapshot();
        }
        $nationalId = Val::text($d['owner_national_id'] ?? null, 20);
        $personal = $row === null || $nationalId !== $row->owner_national_id;
        $changes = $row ? self::changes($row, $values) : $values;
        if ($row && ! $changes && ! $personal) {
            $this->count('requests.unchanged');
            $this->files($row, $d, $r->odoo_id);

            return $row;
        }
        $this->count('requests.'.($row ? ($gone ? 'archived' : 'updated') : 'created'));
        if ($this->dry) {
            $this->planned['requests'][$r->odoo_id] = true;

            return $row;
        }
        $row = DB::transaction(function () use ($row, $r, $d, $changes, $personal, $nationalId, $state) {
            $new = $row === null;
            $req = $row ?? new CreditRequest;
            $from = $req->state;
            $req->forceFill($changes + ($personal ? ['owner_national_id' => $nationalId, 'owner_national_id_by' => null] : []));
            if ($new) {
                $req->forceFill(['number' => $this->numbering->next(RequestsService::NUMBER_KEY), 'source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id,
                    'round' => Flow::isWork($state) || Flow::isFinal($state) || $state === 'changes_requested' ? 1 : 0, 'apply_status' => 'none', 'created_by' => Val::label($d['create_uid'] ?? null) ?? 'Odoo',
                    'created_at' => Val::at($d['create_date'] ?? null) ?? now()]);
            }
            $req->save();
            $methods = array_values(array_filter(array_map(fn (int $id) => $this->ref('methods', $id), Val::ids($d['payment_method_ids'] ?? []))));
            $req->paymentMethods()->sync(array_values(array_filter($methods, fn ($id) => ! str_starts_with((string) $id, 'new:'))));
            $origin = ['origin' => self::SYSTEM];
            if ($new) {
                CreditEvent::create(['request_id' => $req->id, 'kind' => 'created', 'stage_id' => $req->stage_id, 'data' => $origin + ['odoo' => (string) $r->odoo_id], 'user_name' => 'Odoo', 'at' => $req->created_at ?? now()]);
            } elseif ($from !== $req->state) {
                CreditEvent::create(['request_id' => $req->id, 'kind' => 'stage', 'stage_id' => $req->stage_id, 'data' => $origin + ['from' => $from, 'to' => $req->state], 'user_name' => 'Odoo', 'at' => $req->source_updated_at ?? now()]);
                $this->audit->status($this->actor(), 'CreditRequest', $req->id, $req->number, $from, $req->state, 'Odoo');
            }
            // names of fields only: what changed, never what a personal field holds
            $fields = array_keys(Privacy::scrub($changes + ($personal && ! $new ? ['ownerNationalId' => '•'] : [])));
            $this->audit->log($this->actor(), ['action' => $new ? 'CREDIT.CREATE' : 'CREDIT.UPDATE', 'entityType' => 'CreditRequest', 'entityId' => $req->id, 'entityNumber' => $req->number,
                'newValue' => ['origin' => 'from Odoo b2b.credit.request #'.$r->odoo_id, 'fields' => $new ? count($fields) : $fields]]);

            return $req;
        });
        $this->rows['requests'][$r->odoo_id] = $row;
        $this->files($row, $d, $r->odoo_id);

        return $row;
    }

    /**
     * The nine papers of an Odoo request: a paper is a row of `credit_files` without bytes (`storage = odoo`), whose
     * content is the Odoo backup's attachment (`attachment_ref`). A paper that changed name in Odoo replaces the one
     * before; a slot Odoo emptied is closed as removed.
     */
    private function files(?CreditRequest $req, array $d, int $odooId): void
    {
        if (! $req || $this->dry) {
            foreach (Slots::keys() as $slot) {
                if (self::filled($d, $slot)) {
                    $this->count('files.seen');
                }
            }

            return;
        }
        $attachments = $this->attachments($odooId);
        $current = CreditFile::where('request_id', $req->id)->where('source_system', self::SYSTEM)->where('status', CreditFile::CURRENT)->get()->keyBy('slot');
        foreach (Slots::keys() as $slot) {
            $has = self::filled($d, $slot);
            $name = Val::text($d["filename_{$slot}"] ?? null, 190) ?? Slots::label($slot)[1];
            $att = $attachments["file_{$slot}"] ?? null;
            $row = $current->get($slot);
            if (! $has) {
                if ($row) {
                    $row->forceFill(['status' => CreditFile::REMOVED, 'closed_at' => now(), 'closed_by' => 'Odoo', 'close_reason' => 'أُزيل في أودو'])->save();
                    $this->count('files.removed');
                }

                continue;
            }
            if ($row && $row->name === $name) {
                if ($att && (string) $row->attachment_ref !== (string) $att['id']) {
                    $row->forceFill(['attachment_ref' => (string) $att['id'], 'size' => $att['size'], 'mime' => $att['mime'] ?? $row->mime])->save();
                    $this->count('files.updated');
                } else {
                    $this->count('files.unchanged');
                }

                continue;
            }
            $row?->forceFill(['status' => CreditFile::REPLACED, 'closed_at' => now(), 'closed_by' => 'Odoo'])->save();
            CreditFile::create(['request_id' => $req->id, 'slot' => $slot, 'name' => $name, 'mime' => $att['mime'] ?? self::mimeOf($name), 'size' => (int) ($att['size'] ?? 0), 'storage' => 'odoo',
                'attachment_ref' => $att ? (string) $att['id'] : null, 'status' => CreditFile::CURRENT, 'uploaded_by' => 'Odoo', 'source_system' => self::SYSTEM, 'external_ref' => "{$odooId}:{$slot}"]);
            $this->count($row ? 'files.replaced' : 'files.created');
        }
        // `attachments_complete` of a request of Odoo stays what Odoo says (DocumentsService::recount leaves it alone)
    }

    /** @return array<string, array{id:int, size:int, mime:?string}> the binary fields of the request kept as attachments in the copy, by field */
    private function attachments(int $odooId): array
    {
        $out = [];
        $rows = OdooRecord::where('model', 'ir.attachment')->where('data->res_model', self::MODELS['requests'])->where('data->res_id', $odooId)->get(['odoo_id', 'data']);
        foreach ($rows as $a) {
            $field = $a->data['res_field'] ?? null;
            if (is_string($field) && $field !== '') {
                $out[$field] = ['id' => (int) $a->odoo_id, 'size' => Val::int($a->data['file_size'] ?? 0), 'mime' => Val::text($a->data['mimetype'] ?? null, 120)];
            }
        }

        return $out;
    }

    private static function filled(array $d, string $slot): bool
    {
        $file = $d["file_{$slot}"] ?? false;

        return Val::text($d["filename_{$slot}"] ?? null) !== null || ($file !== false && $file !== null && $file !== '');
    }

    private static function mimeOf(string $name): ?string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', default => null,
        };
    }

    // ───────────── the lines of a request ─────────────

    private function line(string $entity, OdooRecord $r): void
    {
        $d = (array) $r->data;
        $class = self::CLASSES[$entity];
        $requestId = $this->ref('requests', Val::id($d['request_id'] ?? null));
        $row = $this->installed ? $class::where('source_system', self::SYSTEM)->where('external_ref', (string) $r->odoo_id)->first() : null;
        if ($r->deleted_at !== null) {
            // a line of the request's form: taken off with it in Odoo, taken off here
            if ($row) {
                $this->count("{$entity}.removed");
                if (! $this->dry) {
                    $row->delete();
                }
            } else {
                $this->count("{$entity}.skippedDeleted");
            }

            return;
        }
        if (! $requestId) {
            $this->count("{$entity}.skippedNoRequest", self::MODELS[$entity]." #{$r->odoo_id}");

            return;
        }
        $values = match ($entity) {
            'responsibles' => ['name' => Val::text($d['name'] ?? null, 190) ?? '—', 'position' => Val::text($d['position'] ?? null, 120), 'mobile' => Val::text($d['mobile'] ?? null, 40)],
            'signatories' => ['name' => Val::text($d['name'] ?? null, 190) ?? '—', 'job_title' => Val::text($d['job_title'] ?? null, 120), 'mobile' => Val::text($d['mobile'] ?? null, 40),
                'signature_note' => Val::text($d['signature_note'] ?? null, 250)],
            'banks' => ['bank_name' => Val::text($d['bank_name'] ?? null, 120) ?? '—', 'branch' => Val::text($d['branch'] ?? null, 120), 'swift' => Val::text($d['swift'] ?? null, 11)],
        };
        $secret = match ($entity) {
            'responsibles' => ['national_id' => Val::text($d['national_id'] ?? null, 20)],
            'banks' => ['account_number' => Val::text($d['account_number'] ?? null, 34), 'iban' => ($iban = Val::text($d['iban'] ?? null, 42)) ? (Text::iban($iban) ?? mb_strtoupper((string) preg_replace('/\s+/u', '', $iban))) : null],
            default => [],
        };
        if (str_starts_with((string) $requestId, 'new:')) {
            $this->count("{$entity}.created");

            return;
        }
        $values['request_id'] = $requestId;
        $changes = $row ? self::changes($row, $values) : $values;
        $secretChanged = array_filter($secret, fn ($v, $k) => $row === null || $v !== $row->getAttribute($k), ARRAY_FILTER_USE_BOTH);
        if ($row && ! $changes && ! $secretChanged) {
            $this->count("{$entity}.unchanged");

            return;
        }
        $this->count("{$entity}.".($row ? 'updated' : 'created'));
        if ($this->dry) {
            return;
        }
        $row ??= new $class;
        if (! $row->exists) {
            $row->forceFill(['line_no' => (int) $class::where('request_id', $requestId)->max('line_no') + 1, 'source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id]);
        }
        $row->forceFill($changes + $secretChanged + ($secretChanged ? ['entered_by_id' => null] : []))->save();
    }

    // ───────────── the approval audit, the envelopes, the notification log ─────────────

    private function approval(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $requestId = $this->ref('requests', Val::id($d['request_id'] ?? null));
        $row = $this->installed ? CreditApproval::where('source_system', self::SYSTEM)->where('external_ref', (string) $r->odoo_id)->first() : null;
        if ($r->deleted_at !== null) {
            $this->count($row ? 'approvals.deletedInOdooKept' : 'approvals.skippedDeleted'); // the audit is never removed

            return;
        }
        if (! $requestId) {
            $this->count('approvals.skippedNoRequest', "b2b.credit.approval #{$r->odoo_id}");

            return;
        }
        [$role, $roleName] = $this->group($d['group_id'] ?? null);
        [$userId, $login, $userName] = $this->user($d['user_id'] ?? null);
        [$byId, $byLogin, $byName] = $this->user($d['approved_by'] ?? null);
        $state = in_array($d['state'] ?? null, Flow::LINE_STATES, true) ? $d['state'] : 'pending';
        $values = [
            'request_id' => $requestId, 'stage_id' => $this->ref('stages', Val::id($d['stage_id'] ?? null)), 'state' => $state,
            'role_key' => $role, 'role_name' => $roleName, 'user_id' => $userId, 'user_login' => $login, 'user_name' => $userName, 'personal' => ($userId !== null || $login !== null) && $roleName === null,
            'deadline' => Val::at($d['deadline'] ?? null), 'decided_by_id' => $byId, 'decided_by_login' => $byLogin, 'decided_by' => $byName, 'decided_at' => Val::at($d['approved_at'] ?? null),
            'note' => Val::plain($d['note'] ?? null, 2000), 'reminder_count' => Val::int($d['reminder_count'] ?? 0), 'last_reminder_at' => Val::at($d['last_reminder_at'] ?? null),
            'escalated_at' => Val::at($d['escalated_at'] ?? null),
        ];
        if (str_starts_with((string) $requestId, 'new:') || str_starts_with((string) $values['stage_id'], 'new:')) {
            $this->count('approvals.created');

            return;
        }
        $changes = $row ? self::changes($row, $values) : $values;
        if ($row && ! $changes) {
            $this->count('approvals.unchanged');

            return;
        }
        $this->count('approvals.'.($row ? 'updated' : 'created'));
        if ($this->dry) {
            return;
        }
        if ($row) {
            // Odoo's own line: the copy follows what Odoo says (a line decided in OPS is never rewritten — it has no Odoo id)
            DB::table('credit_approvals')->where('id', $row->id)->update(self::plainValues($changes) + ['updated_at' => now()]);

            return;
        }
        $round = (int) (CreditRequest::whereKey($requestId)->value('round') ?: 1);
        $line = new CreditApproval;
        $line->forceFill($values + ['round' => $round, 'source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id, 'created_at' => Val::at($d['create_date'] ?? null) ?? now()])->save();
    }

    private function envelope(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $requestId = $this->ref('requests', Val::id($d['request_id'] ?? null));
        $row = $this->installed ? CreditEnvelope::where('source_system', self::SYSTEM)->where('external_ref', (string) $r->odoo_id)->first() : null;
        if (! $requestId || str_starts_with((string) $requestId, 'new:')) {
            $this->count($requestId ? 'envelopes.created' : 'envelopes.skippedNoRequest');

            return;
        }
        $status = in_array($d['status'] ?? null, Flow::ENVELOPE_STATUSES, true) ? $d['status'] : 'draft';
        $values = [
            'request_id' => $requestId, 'provider' => 'signit', 'status' => $r->deleted_at !== null && in_array($status, Flow::ENVELOPE_ACTIVE, true) ? 'voided' : $status,
            'external_envelope_id' => Val::text($d['external_envelope_id'] ?? null, 120), 'signer_name' => Val::text($d['signer_name'] ?? null, 190),
            'signer_email' => Val::text($d['signer_email'] ?? null, 190), 'signer_mobile' => Val::text($d['signer_mobile'] ?? null, 40),
            'sent_at' => Val::at($d['sent_at'] ?? null), 'signed_at' => Val::at($d['signed_at'] ?? null), 'error_message' => Val::text($d['error_message'] ?? null, 500),
        ];
        $values['closed_at'] = in_array($values['status'], Flow::ENVELOPE_ACTIVE, true) ? null : ($values['signed_at'] ?? $row?->closed_at ?? Val::at($r->write_date));
        $changes = $row ? self::changes($row, $values) : $values;
        $pdf = Val::id($d['signed_pdf_attachment_id'] ?? null);
        if ($row && ! $changes && ($pdf === null || $row->signed_file_id !== null)) {
            $this->count('envelopes.unchanged');

            return;
        }
        $this->count('envelopes.'.($row ? 'updated' : 'created'));
        if ($this->dry) {
            return;
        }
        DB::transaction(function () use ($row, $r, $d, $changes, $pdf, $requestId) {
            $env = $row ?? new CreditEnvelope;
            if (! $env->exists) {
                $env->forceFill(['source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id, 'created_by' => 'Odoo', 'created_at' => Val::at($d['create_date'] ?? null) ?? now()]);
            }
            $env->forceFill($changes)->save();
            if ($pdf !== null && $env->signed_file_id === null) {
                $file = CreditFile::create(['request_id' => $requestId, 'slot' => Slots::SIGNED_AGREEMENT, 'name' => Val::text(Val::label($d['signed_pdf_attachment_id']), 190) ?? 'signed-agreement.pdf',
                    'mime' => 'application/pdf', 'size' => 0, 'storage' => 'odoo', 'attachment_ref' => (string) $pdf, 'status' => CreditFile::CURRENT, 'uploaded_by' => 'Odoo',
                    'source_system' => self::SYSTEM, 'external_ref' => "envelope:{$r->odoo_id}"]);
                $env->forceFill(['signed_file_id' => $file->id])->save();
            }
        });
    }

    private function notification(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $requestOdoo = Val::id($d['request_id'] ?? null);
        $requestId = $requestOdoo ? $this->ref('requests', $requestOdoo) : null;
        $row = $this->installed ? CreditNotification::where('source_system', self::SYSTEM)->where('external_ref', (string) $r->odoo_id)->first() : null;
        if ($r->deleted_at !== null) {
            $this->count($row ? 'notifications.deletedInOdooKept' : 'notifications.skippedDeleted');

            return;
        }
        if (str_starts_with((string) $requestId, 'new:')) {
            $this->count('notifications.created');

            return;
        }
        [$userId, , $userName] = $this->user($d['recipient_user_id'] ?? null);
        $values = [
            'request_id' => $requestId, 'channel' => self::CHANNELS[$d['channel'] ?? ''] ?? 'in_app', 'template_code' => Val::text($d['template_code'] ?? null, 80),
            'status' => in_array($d['status'] ?? null, Flow::NOTIFICATION_STATUSES, true) ? $d['status'] : 'pending', 'sent_at' => Val::at($d['sent_at'] ?? null),
            'error_message' => Val::text($d['error_message'] ?? null, 500), 'recipient_user_id' => $userId,
            'recipient_partner_ref' => ($p = Val::id($d['recipient_partner_id'] ?? null)) ? (string) $p : null,
            'recipient_name' => Val::text(Val::label($d['recipient_partner_id'] ?? null), 190) ?? $userName, 'recipient_value' => Val::text($d['recipient_value'] ?? null, 190),
        ];
        $changes = $row ? self::changes($row, $values) : $values;
        if ($row && ! $changes) {
            $this->count('notifications.unchanged');

            return;
        }
        $this->count('notifications.'.($row ? 'updated' : 'created'));
        if ($this->dry) {
            return;
        }
        $row ??= new CreditNotification;
        if (! $row->exists) {
            $row->forceFill(['source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id, 'created_at' => Val::at($d['create_date'] ?? null) ?? Val::at($r->write_date) ?? now()]);
        }
        $row->forceFill($changes)->save();
    }

    // ───────────── links ─────────────

    /**
     * The OPS id of an Odoo method / version / stage / request. One the pass has not met yet (a change of the feed that
     * names a brand-new parent) is made from the copy on the spot. In a plan: the id, or a marker for "will be made".
     */
    private function ref(string $entity, ?int $odooId): ?string
    {
        if (! $odooId) {
            return null;
        }
        if ($row = $this->rows[$entity][$odooId] ?? null) {
            return (string) $row->getKey();
        }
        if (isset($this->planned[$entity][$odooId])) {
            return "new:{$entity}:{$odooId}";
        }
        if ($r = $this->mirrored(self::MODELS[$entity], $odooId)) {
            $this->project($r);
        }
        if ($row = $this->rows[$entity][$odooId] ?? null) {
            return (string) $row->getKey();
        }

        return isset($this->planned[$entity][$odooId]) ? "new:{$entity}:{$odooId}" : null;
    }

    /** The OPS customer an Odoo partner is linked to (a link to a customer that is gone names nobody). */
    private function customer(?int $partnerId): ?string
    {
        $id = $partnerId ? $this->linked('customer', $partnerId) : null;

        return $id && Customer::whereKey($id)->exists() ? (string) $id : null;
    }

    /**
     * The OPS user an Odoo user is — by login or e-mail, one user or none.
     *
     * @return array{0:?string, 1:?string, 2:?string} [OPS user id, the login kept when there is none, the name]
     */
    private function user(mixed $many2one): array
    {
        $odooId = Val::id($many2one);
        if (! $odooId) {
            return [null, null, null];
        }
        if (! isset($this->users[$odooId])) {
            $name = Val::label($many2one);
            $login = null;
            $keys = [];
            if ($u = $this->mirrored('res.users', $odooId)) {
                $login = Val::text($u->data['login'] ?? null, 190);
                $keys = [$login, Val::text($u->data['email'] ?? null), Val::text($this->mirrored('res.partner', Val::id($u->data['partner_id'] ?? null))?->data['email'] ?? null)];
                $name ??= Val::text($u->display_name, 190);
            }
            $found = [];
            foreach (array_filter($keys) as $key) {
                $key = mb_strtolower($key);
                foreach ([$this->userByEmail[$key] ?? null, $this->userByName[$key] ?? null] as $id) {
                    if ($id) {
                        $found[$id] = true;
                    }
                }
            }
            $id = count($found) === 1 ? (string) array_key_first($found) : null;
            $this->users[$odooId] = [$id, $id ? null : ($login ? mb_strtolower($login) : null), mb_substr((string) ($name ?? "#{$odooId}"), 0, 190)];
            $this->count($id ? 'people.matched' : 'people.byName');
        }

        return $this->users[$odooId];
    }

    /** @return array{0:?string, 1:?string} [the OPS role named exactly like the group, else null; the group's name] */
    private function group(mixed $many2one): array
    {
        $odooId = Val::id($many2one);
        if (! $odooId) {
            return [null, null];
        }
        if (! isset($this->groups[$odooId])) {
            $name = Val::label($many2one) ?? Val::text($this->mirrored('res.groups', $odooId)?->display_name, 190) ?? "#{$odooId}";
            // "B2B Credit / Finance Reviewer": the last part is the group's own name
            $short = trim((string) last(explode('/', $name)));
            $role = $this->installed ? ($this->people->roleNamed($name) ?? $this->people->roleNamed($short)) : null;
            $this->groups[$odooId] = [$role, mb_substr($name, 0, 190)];
            $this->count($role ? 'groups.matched' : 'groups.unmapped', $role ? null : $name);
        }

        return $this->groups[$odooId];
    }

    // ───────────── helpers ─────────────

    private static function code(?string $code, int $odooId): string
    {
        $code = $code === null ? '' : mb_strtolower((string) preg_replace('/[^A-Za-z0-9_]+/', '_', $code));
        $code = trim($code, '_');

        return $code !== '' && preg_match('/^[a-z]/', $code) ? mb_substr($code, 0, 40) : "odoo_{$odooId}";
    }

    /** @return array{0:string, 1:string} [Arabic, English] */
    private static function names(string $name): array
    {
        return preg_match('/\p{Arabic}/u', $name) ? [$name, $name] : [self::ARABIC[mb_strtolower($name)] ?? $name, $name];
    }

    private static function money(mixed $v): ?float
    {
        return $v === false || $v === null ? null : round(Val::float($v), 2);
    }

    private static function days(mixed $v): ?int
    {
        return $v === false || $v === null ? null : Val::int($v);
    }

    /** Values for the query builder: a moment as text, an array as JSON. */
    private static function plainValues(array $values): array
    {
        return array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s.v') : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v), $values);
    }

    /**
     * The values that differ from what the row holds. A moment equals the same moment, a day its `YYYY-MM-DD`, 5 equals
     * "5.00", and a marker of a plan ("new:…") always differs.
     *
     * @return array<string, mixed>
     */
    private static function changes(Model $row, array $values): array
    {
        $out = [];
        foreach ($values as $column => $value) {
            $current = $row->getAttribute($column);
            if ($current instanceof DateTimeInterface || $value instanceof DateTimeInterface) {
                $format = is_string($value) && strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';
                $same = ($current instanceof DateTimeInterface ? $current->format($format) : $current) === ($value instanceof DateTimeInterface ? $value->format($format) : $value);
            } elseif (is_bool($current) || is_bool($value)) {
                $same = $current !== null && $value !== null && (bool) $current === (bool) $value;
            } elseif (is_array($current) || is_array($value)) {
                [$a, $b] = [(array) ($current ?: []), (array) ($value ?: [])];
                ksort($a);
                ksort($b);
                $same = $a == $b;
            } elseif (is_numeric($current) && is_numeric($value) && (is_int($value) || is_float($value) || is_int($current) || is_float($current))) {
                $same = abs((float) $current - (float) $value) < 0.005;
            } else {
                $same = (string) ($current ?? '') === (string) ($value ?? '');
            }
            if (! $same) {
                $out[$column] = $value;
            }
        }

        return $out;
    }
}
