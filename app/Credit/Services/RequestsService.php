<?php

namespace App\Credit\Services;

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
use App\Credit\Support\Access;
use App\Credit\Support\Flow;
use App\Credit\Support\Owned;
use App\Credit\Support\People;
use App\Credit\Support\Privacy;
use App\Credit\Support\Text;
use App\Models\Customer;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Services\Sales\CreditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The credit requests themselves: raising one, filling it in (the company, its owner and address, what is asked for,
 * how the customer pays, the responsible persons, the authorised signatories, the bank accounts), recording which
 * terms the customer accepted, comments — and reading them: the lists and one request with everything around it.
 * Moving a request through the workflow is WorkflowEngine's; its papers DocumentsService's; its signature
 * SignatureService's.
 *
 * WHO SEES WHICH REQUEST. A user who raises requests (`credit.request`) and holds none of the reviewing permissions
 * sees the requests he entered. Every other user of the page — the reviewers, approvers and managers, and a reader of
 * the page such as the auditor — sees them all. What is personal (identity numbers, bank accounts, the papers) is masked
 * in every answer and read in full only through `privateValues()` (App\Credit\Support\Privacy).
 *
 * ONE OPEN REQUEST PER CUSTOMER. A customer has at most one request that is not closed: the customer's row is locked
 * while a request is raised or moved to another customer, so two people cannot raise two.
 *
 * A PERSONAL VALUE IS NEVER SENT BACK BY ACCIDENT. The forms show masks; a line or a field whose personal key is absent,
 * or still carries the mask, keeps what it holds. A value that is typed is kept encrypted with who typed it.
 */
class RequestsService
{
    public const NUMBER_KEY = 'CRA';

    /** Text of the form: camelCase key → [column, length]. */
    public const TEXT = [
        'companyName' => ['company_name', 190], 'commercialRegistration' => ['commercial_registration', 40], 'businessActivity' => ['business_activity', 190],
        'legalEntityType' => ['legal_entity_type', 120], 'ownerName' => ['owner_name', 190], 'phone' => ['phone', 40], 'mobile' => ['mobile', 40], 'email' => ['email', 190],
        'website' => ['website', 250], 'poBox' => ['po_box', 40], 'postalCode' => ['postal_code', 20], 'street' => ['street', 250], 'district' => ['district', 120],
        'buildingNo' => ['building_no', 20], 'city' => ['city', 120],
    ];

    public const STATE_FILTERS = [...Flow::STATES, 'open', 'closed', 'work'];

    public const SCOPES = ['all', 'mine', 'waiting'];

    private const SORTS = [
        'number' => 'number', 'requestDate' => 'request_date', 'companyName' => 'company_name', 'requestedCreditLimit' => 'requested_credit_limit',
        'approvedCreditLimit' => 'approved_credit_limit', 'slaDeadline' => 'sla_deadline', 'submittedAt' => 'submitted_at', 'stageEnteredAt' => 'stage_entered_at',
        'createdAt' => 'created_at', 'updatedAt' => 'updated_at', 'state' => 'state',
    ];

    /** Columns that are often empty: the empty ones come last whichever way the list is sorted. */
    private const SPARSE = ['approved_credit_limit', 'sla_deadline', 'submitted_at', 'stage_entered_at'];

    public const MAX_LINES = ['responsibles' => 20, 'signatories' => 20, 'bankLines' => 10];

    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly DocumentsService $documents,
        private readonly SignatureService $signature,
        private readonly AgreementService $agreement,
        private readonly CreditLog $log,
        private readonly People $people,
        private readonly NumberingService $numbering,
        private readonly SettingsService $settings,
        private readonly CreditService $credit,
    ) {}

    // ───────────── raising and filling in ─────────────

    /** @return array the request as its detail */
    public function create(AuthUser $u, array $dto): array
    {
        $id = DB::transaction(function () use ($u, $dto) {
            $customer = $this->lockCustomer((string) $dto['customerId']);
            $this->assertNoOpenRequest($customer);
            $draft = $this->engine->single('draft');
            $currency = (string) (((array) $this->settings->get('company'))['currency'] ?? 'SAR') ?: 'SAR';
            $r = new CreditRequest;
            $r->forceFill([
                'number' => $this->numbering->next(self::NUMBER_KEY), 'request_date' => now((string) config('app.business_timezone', 'UTC'))->toDateString(), 'source' => 'internal',
                'customer_id' => $customer->id, 'partner_name' => $customer->name_ar, 'currency' => $currency, 'stage_id' => $draft->id, 'state' => $draft->state, 'round' => 0,
                'requester_id' => $u->id, 'requester_name' => $u->nameAr, 'company_name' => $customer->name_ar,
                'commercial_registration' => Text::clean($customer->getAttribute('cr'), 40), 'city' => Text::clean($customer->city, 120),
                'attachments_complete' => false, 'signature_status' => 'none', 'apply_status' => 'none', 'created_by' => $u->nameAr,
            ]);
            $this->fill($u, $r, $dto);
            $r->save();
            $this->lines($u, $r, $dto);
            $this->documents->recount($r);
            $this->log->event($r, 'created', $u, null, ['customer' => $customer->code]);
            $this->log->audit($u, $r, 'CREATE', null, ['customer' => $customer->code, 'requestedCreditLimit' => Text::money($r->requested_credit_limit), 'requestedPaymentDays' => $r->requested_payment_days]);
            $this->log->status($u, $r, null, $r->state);

            return $r->id;
        });

        return $this->get($u, $id);
    }

    public function update(AuthUser $u, string $id, array $dto): array
    {
        DB::transaction(function () use ($u, $id, $dto) {
            $r = CreditRequest::locate($id, true);
            $this->assertVisible($u, $r);
            if ($e = Access::blockEdit($u, $r, $r->stage)) {
                throw $e;
            }
            $before = $this->snapshotForAudit($r);
            if (array_key_exists('customerId', $dto) && (string) $dto['customerId'] !== (string) $r->customer_id) {
                if (! in_array($r->state, Flow::EDITABLE, true)) {
                    throw AppError::rule('CREDIT_CUSTOMER_LOCKED', 'لا يتغيّر عميل الطلب بعد تقديمه — يُسحب الطلب للتعديل أولًا', 'The customer of a request does not change once it is submitted — withdraw it first');
                }
                $customer = $this->lockCustomer((string) $dto['customerId']);
                $this->assertNoOpenRequest($customer, $r->id);
                $r->forceFill(['customer_id' => $customer->id, 'partner_name' => $customer->name_ar]);
            }
            $this->fill($u, $r, $dto);
            $lines = $this->lines($u, $r, $dto);
            $after = $this->snapshotForAudit($r);
            $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));
            if (! $changed && ! $lines) {
                return;
            }
            $r->forceFill(['updated_by' => $u->nameAr])->save();
            $this->documents->recount($r);
            $fields = [...$changed, ...$lines];
            $this->log->event($r, 'edited', $u, null, ['fields' => $fields]);
            $this->log->audit($u, $r, 'UPDATE', array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)) + ($lines ? ['lines' => $lines] : []));
        });

        return $this->get($u, $id);
    }

    /** The customer accepts the terms version in force: it is frozen on the request, whatever is published later. */
    public function acceptTerms(AuthUser $u, string $id, ?string $versionId): array
    {
        DB::transaction(function () use ($u, $id, $versionId) {
            $r = CreditRequest::locate($id, true);
            $this->assertVisible($u, $r);
            if (Owned::bySource($r)) {
                throw Owned::error($r, 'request');
            }
            if (! in_array($r->state, Flow::EDITABLE, true)) {
                throw AppError::rule('CREDIT_TERMS_STATE', "يُسجَّل قبول الشروط قبل تقديم الطلب أو عند إعادته للتعديل — حالة الطلب {$r->number} الآن ".Flow::label($r->state)[0], "The acceptance of the terms is recorded before the request is submitted or when it is sent back for changes — request {$r->number} is ".Flow::label($r->state)[1]);
            }
            if (! Privacy::isRequester($u, $r) && ! Access::manages($u)) {
                throw Access::notYours($r);
            }
            $inForce = AgreementService::termsInForce()
                ?? throw AppError::rule('CREDIT_NO_TERMS', 'لا توجد نسخة شروط منشورة سارية — يُنشرها مدير الائتمان أولًا', 'No published terms version is in force — the credit manager publishes one first');
            if ($versionId !== null && $versionId !== '' && $versionId !== $inForce->id) {
                throw AppError::rule('CREDIT_TERMS_OUTDATED', "النسخة السارية من الشروط هي {$inForce->version} — يُسجَّل قبول النسخة السارية فقط", "The terms version in force is {$inForce->version} — only the version in force is accepted", ['inForce' => $inForce->id]);
            }
            $snapshot = $inForce->snapshot();
            $had = $r->terms_snapshot['version'] ?? null;
            $r->forceFill(['terms_version_id' => $inForce->id, 'terms_snapshot' => $snapshot, 'terms_accepted_at' => now(), 'terms_accepted_by' => $u->nameAr, 'updated_by' => $u->nameAr])->save();
            $this->log->event($r, 'terms_accepted', $u, null, ['version' => $inForce->version, 'hash' => $snapshot['hash'], 'previous' => $had]);
            $this->log->audit($u, $r, 'TERMS.ACCEPT', $had ? ['version' => $had] : null, ['version' => $inForce->version, 'hash' => $snapshot['hash']]);
        });

        return $this->get($u, $id);
    }

    /** A comment on the request's timeline — also on a closed request and on one Odoo still manages (a comment is OPS's own). */
    public function comment(AuthUser $u, string $id, string $text): array
    {
        $text = Text::clean($text, 4000);
        if ($text === null) {
            throw AppError::validation('CREDIT_COMMENT_EMPTY', 'اكتب التعليق', 'Write the comment', [['path' => 'text', 'message' => 'required']]);
        }
        DB::transaction(function () use ($u, $id, $text) {
            $r = CreditRequest::locate($id, true);
            $this->assertVisible($u, $r);
            $this->log->event($r, 'comment', $u, $text);
            $this->log->audit($u, $r, 'COMMENT', null, ['length' => mb_strlen($text)]);
        });

        return $this->get($u, $id);
    }

    // ───────────── reading ─────────────

    /** @param  array<string, ?string>  $f  state · stage · scope · sla · origin · customer · apply · from · to */
    public function list(AuthUser $u, Paging $page, array $f): array
    {
        $query = $this->filtered($u, $f, $page->q)->with(['stage', 'customer:id,code,name_ar,name_en']);
        $this->order($query, $page->sort, $page->order);
        $rows = $query->forPage($page->page, $page->pageSize)->get();
        $total = $this->filtered($u, $f, $page->q)->toBase()->getCountForPagination();
        $this->people->load($rows->pluck('requester_id')->all());

        return $page->wrap($rows->map(fn (CreditRequest $r) => $this->card($r))->values()->all(), $total)
            + ['totals' => ['requestedCreditLimit' => Text::money($this->filtered($u, $f, $page->q)->sum('requested_credit_limit')), 'approvedCreditLimit' => Text::money($this->filtered($u, $f, $page->q)->sum('approved_credit_limit'))]];
    }

    /** The counts of the page head, for this user. */
    public function summary(AuthUser $u): array
    {
        $base = fn () => $this->visible($u, CreditRequest::query());
        $now = now();

        return [
            'waiting' => Access::decides($u) ? $this->waitingFor($u, $base())->count() : 0,
            'mine' => $base()->where('requester_id', $u->id)->whereNotIn('state', Flow::FINAL)->count(),
            'inReview' => $base()->whereIn('state', Flow::WORK)->count(),
            'late' => $base()->whereIn('state', [...Flow::WORK, ...Flow::EDITABLE])->whereNotNull('sla_deadline')->where('sla_deadline', '<=', $now)->count(),
            'escalated' => $base()->whereIn('state', Flow::WORK)->whereNotNull('escalated_at')->count(),
            'applyBlocked' => $base()->where('state', 'approved')->where('apply_status', 'blocked')->count(),
            'approved' => $base()->where('state', 'approved')->count(),
        ];
    }

    public function get(AuthUser $u, string $id): array
    {
        $r = CreditRequest::locate($id);
        $this->assertVisible($u, $r);
        $r->load(['stage', 'customer', 'paymentMethods', 'responsibles', 'signatories', 'bankLines']);

        return $this->detail($u, $r);
    }

    /**
     * The personal values of a request this user may read, in full — each read written to the audit trail (which request,
     * how many values; never the values).
     */
    public function privateValues(AuthUser $u, string $id): array
    {
        $r = CreditRequest::locate($id);
        $this->assertVisible($u, $r);
        if (! Privacy::mayReveal($u, $r)) {
            Privacy::refuse($u, $r, 'values');
        }
        $r->load(['responsibles', 'bankLines']);
        $n = 0;
        $value = function (?string $v, ?string $by) use ($u, $r, &$n) {
            if ($v === null || $v === '') {
                return ['value' => null, 'hidden' => false];
            }
            if (! Privacy::sees($u, $r, $by)) {
                return ['value' => null, 'hidden' => true];
            }
            $n++;

            return ['value' => $v, 'hidden' => false];
        };
        $out = [
            'ownerNationalId' => $value($r->owner_national_id, $r->owner_national_id_by),
            'responsibles' => $r->responsibles->map(fn (CreditResponsible $l) => ['id' => $l->id, 'nationalId' => $value($l->national_id, $l->entered_by_id)])->all(),
            'bankLines' => $r->bankLines->map(fn (CreditBankLine $l) => ['id' => $l->id, 'accountNumber' => $value($l->account_number, $l->entered_by_id), 'iban' => $value($l->iban, $l->entered_by_id)])->all(),
        ];
        if ($n === 0) {
            Privacy::refuse($u, $r, 'values');
        }
        $this->log->audit($u, $r, 'PRIVATE.READ', null, ['values' => $n]);

        return $out;
    }

    /** The agreement of the request, as data and as one printable bilingual page. */
    public function agreement(AuthUser $u, string $id): array
    {
        $r = CreditRequest::locate($id);
        $this->assertVisible($u, $r);
        $a = $this->agreement->build($r);

        return ['agreement' => $a, 'html' => $this->agreement->html($a)];
    }

    /** Customers to raise a request for: name or code, with their credit today and the request that is open for them. */
    public function customers(AuthUser $u, ?string $q): array
    {
        $query = Customer::query()->where('active', true);
        if ($q !== null && trim($q) !== '') {
            $like = Text::like($q);
            $query->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like));
        }
        $rows = $query->orderBy('name_ar')->orderBy('id')->limit(20)->get(['id', 'code', 'name_ar', 'name_en', 'city', 'terms', 'credit_limit', 'balance', 'source_system']);
        $open = CreditRequest::whereIn('customer_id', $rows->pluck('id'))->whereNotIn('state', Flow::FINAL)->pluck('number', 'customer_id');

        return ['items' => $rows->map(fn (Customer $c) => [
            'id' => $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en, 'city' => $c->city, 'terms' => $c->terms,
            'creditLimit' => Text::money($c->credit_limit), 'balance' => Text::money($c->balance), 'sourceSystem' => $c->getAttribute('source_system'),
            'openRequest' => $open[$c->id] ?? null,
        ])->values()->all()];
    }

    /** May this user see this request at all? (A request he may not see is answered as not found: its number says nothing.) */
    public function assertVisible(AuthUser $u, CreditRequest $r): void
    {
        if (! self::seesAll($u) && ! Privacy::isRequester($u, $r)) {
            throw AppError::notFound('CREDIT_REQUEST_NOT_FOUND', "طلب الائتمان {$r->number} غير موجود", "Credit request {$r->number} not found");
        }
    }

    /** Everybody but a user who only raises requests sees every request. */
    public static function seesAll(AuthUser $u): bool
    {
        return Privacy::privileged($u) || ! $u->can('credit.request');
    }

    // ───────────── the shapes ─────────────

    /** One row of a list. */
    public function card(CreditRequest $r): array
    {
        [$ar, $en] = Flow::label($r->state);

        return [
            'id' => $r->id, 'number' => $r->number, 'sourceName' => $r->source_name, 'requestDate' => $r->request_date?->toDateString(), 'source' => $r->source,
            'origin' => $r->fromOdoo() ? 'odoo' : 'ops', 'ownedBySource' => Owned::bySource($r),
            'state' => $r->state, 'stateAr' => $ar, 'stateEn' => $en, 'stage' => $r->stage?->brief(), 'round' => (int) $r->round,
            'customer' => $r->customer ? ['id' => $r->customer->id, 'code' => $r->customer->code, 'nameAr' => $r->customer->name_ar, 'nameEn' => $r->customer->name_en] : null,
            'partnerName' => $r->partner_name, 'companyName' => $r->company_name, 'commercialRegistration' => $r->commercial_registration, 'city' => $r->city,
            'requestedCreditLimit' => Text::money($r->requested_credit_limit), 'requestedPaymentDays' => $r->requested_payment_days,
            'approvedCreditLimit' => Text::money($r->approved_credit_limit), 'approvedPaymentDays' => $r->approved_payment_days, 'currency' => $r->currency ?: 'SAR',
            'requester' => $this->people->present($r->requester_id, $r->requester_name, $r->requester_login),
            'submittedAt' => Text::at($r->submitted_at), 'stageEnteredAt' => Text::at($r->stage_entered_at), 'slaDeadline' => Text::at($r->sla_deadline), 'sla' => WorkflowEngine::sla($r),
            'applyStatus' => $r->apply_status ?: 'none', 'signatureStatus' => $r->signature_status ?: 'none', 'attachmentsComplete' => (bool) $r->attachments_complete,
            'createdAt' => Text::at($r->created_at), 'updatedAt' => Text::at($r->updated_at),
        ];
    }

    private function detail(AuthUser $u, CreditRequest $r): array
    {
        $privileged = Privacy::privileged($u);
        $files = CreditFile::where('request_id', $r->id)->orderBy('created_at')->orderBy('id')->get();
        $lines = CreditApproval::where('request_id', $r->id)->with('stage')->orderBy('created_at')->orderBy('id')->get();
        $events = CreditEvent::where('request_id', $r->id)->orderBy('at')->orderBy('id')->get();
        $envelopes = CreditEnvelope::where('request_id', $r->id)->orderByDesc('created_at')->orderByDesc('id')->get();
        $this->people->load([...$lines->pluck('user_id')->all(), ...$lines->pluck('decided_by_id')->all(), ...$events->pluck('user_id')->all(), $r->requester_id]);
        $stages = CreditStage::whereIn('id', $events->pluck('stage_id')->filter()->unique()->values())->get()->keyBy('id');
        $open = $this->engine->openLines($r);
        $mask = fn (?string $v, ?string $by) => ['masked' => Privacy::mask($v), 'has' => $v !== null && $v !== '', 'mine' => $v !== null && $v !== '' && Privacy::sees($u, $r, $by)];
        $inForce = AgreementService::termsInForce();
        $snapshot = $r->terms_snapshot ?: null;
        $signatureFiles = $files->where('slot', 'signed_agreement')->keyBy('id');
        $customer = $r->customer;

        return $this->card($r) + [
            'businessActivity' => $r->business_activity, 'legalEntityType' => $r->legal_entity_type, 'ownerName' => $r->owner_name,
            'ownerNationalId' => $mask($r->owner_national_id, $r->owner_national_id_by),
            'phone' => $r->phone, 'mobile' => $r->mobile, 'email' => $r->email, 'website' => $r->website, 'poBox' => $r->po_box, 'postalCode' => $r->postal_code,
            'street' => $r->street, 'district' => $r->district, 'buildingNo' => $r->building_no,
            'monthlyPurchaseVolume' => Text::money($r->monthly_purchase_volume), 'approvedTerm' => $r->approved_term,
            'paymentMethods' => $r->paymentMethods->map(fn (CreditPaymentMethod $m) => $m->brief())->values()->all(),
            'responsibles' => $r->responsibles->map(fn (CreditResponsible $l) => ['id' => $l->id, 'name' => $l->name, 'position' => $l->position, 'mobile' => $l->mobile, 'nationalId' => $mask($l->national_id, $l->entered_by_id)])->values()->all(),
            'signatories' => $r->signatories->map(fn (CreditSignatory $l) => ['id' => $l->id, 'name' => $l->name, 'jobTitle' => $l->job_title, 'mobile' => $l->mobile, 'signatureNote' => $l->signature_note])->values()->all(),
            'bankLines' => $r->bankLines->map(fn (CreditBankLine $l) => ['id' => $l->id, 'bankName' => $l->bank_name, 'branch' => $l->branch, 'swift' => $l->swift,
                'accountNumber' => $mask($l->account_number, $l->entered_by_id), 'iban' => $mask($l->iban, $l->entered_by_id)])->values()->all(),
            'financeRecommendation' => $r->finance_recommendation, 'legalNotes' => $r->legal_notes, 'ceoNotes' => $r->ceo_notes,
            'rejectionReason' => $r->rejection_reason, 'rejectedAt' => Text::at($r->rejected_at), 'rejectedBy' => $r->rejected_by, 'changesRequestedNote' => $r->changes_requested_note,
            'cancelledAt' => Text::at($r->cancelled_at), 'cancelledBy' => $r->cancelled_by, 'cancelReason' => $r->cancel_reason,
            'approvedAt' => Text::at($r->approved_at), 'approvedBy' => $r->approved_by,
            'applied' => ['status' => $r->apply_status ?: 'none', 'at' => Text::at($r->applied_at), 'by' => $r->applied_by, 'changes' => $r->applied_changes, 'error' => $r->apply_error,
                'restoredAt' => Text::at($r->restored_at), 'restoredBy' => $r->restored_by],
            'customerCredit' => $customer && ($privileged || Privacy::isRequester($u, $r)) ? [
                'code' => $customer->code, 'creditLimit' => Text::money($customer->credit_limit), 'terms' => $customer->terms, 'sourceSystem' => $customer->getAttribute('source_system'),
                'exposure' => array_map(fn ($v) => is_float($v) ? Text::money($v) : $v, $this->credit->exposure($customer->id)),
            ] : null,
            'terms' => [
                'accepted' => $snapshot ? ['id' => $snapshot['id'] ?? null, 'name' => $snapshot['name'] ?? null, 'version' => $snapshot['version'] ?? null, 'effectiveDate' => $snapshot['effectiveDate'] ?? null,
                    'hash' => $snapshot['hash'] ?? null, 'acceptedAt' => Text::at($r->terms_accepted_at), 'acceptedBy' => $r->terms_accepted_by] : null,
                'inForce' => $inForce ? ['id' => $inForce->id, 'version' => $inForce->version, 'name' => $inForce->name, 'effectiveDate' => $inForce->effective_date?->toDateString()] : null,
                'current' => ! $inForce || ($snapshot !== null && ($snapshot['hash'] ?? null) === $inForce->snapshot()['hash']),
            ],
            'steps' => $this->engine->steps($r),
            'requirements' => $this->engine->requirements($r),
            'missingToSend' => in_array($r->state, Flow::EDITABLE, true) ? $this->engine->missingToSend($r) : [],
            'waitingFor' => $this->waitingPeople($r, $open),
            'approvals' => $lines->map(fn (CreditApproval $l) => [
                'id' => $l->id, 'stage' => $l->stage?->brief(), 'round' => (int) $l->round, 'state' => $l->state, 'role' => $this->people->role($l->role_key, $l->role_name),
                'person' => $this->people->present($l->user_id, $l->user_name, $l->user_login), 'personal' => (bool) $l->personal, 'deadline' => Text::at($l->deadline),
                'decidedBy' => $this->people->present($l->decided_by_id, $l->decided_by, $l->decided_by_login), 'decidedAt' => Text::at($l->decided_at), 'note' => $l->note,
                'reminderCount' => (int) $l->reminder_count, 'lastReminderAt' => Text::at($l->last_reminder_at), 'escalatedAt' => Text::at($l->escalated_at),
                'escalatedTo' => $l->escalated_at ? $this->people->role($l->escalated_to_role) : null, 'origin' => $l->source_system === Owned::SYSTEM ? 'odoo' : 'ops',
            ])->values()->all(),
            'envelopes' => $envelopes->map(fn (CreditEnvelope $e) => $this->signature->shape($u, $r, $e) + [
                'signedFile' => $e->signed_file_id && ($f = $signatureFiles->get($e->signed_file_id)) ? $this->documents->shape($u, $r, $f) : null,
            ])->values()->all(),
            'signatureProvider' => $this->signature->provider(),
            'documents' => $this->documents->slots($u, $r, $files->where('slot', '!=', 'signed_agreement')->values()),
            'events' => $events->map(fn (CreditEvent $e) => [
                'id' => $e->id, 'kind' => $e->kind, 'note' => $e->note, 'data' => $e->data, 'at' => Text::at($e->at),
                'by' => $e->user_id || $e->user_name ? $this->people->present($e->user_id, $e->user_name) : null, 'stage' => $e->stage_id ? $stages->get($e->stage_id)?->brief() : null,
            ])->values()->all(),
            'notifications' => $privileged ? $this->notifications($r) : null,
            'extra' => $privileged ? $r->extra : null,
            'can' => $this->can($u, $r, $open),
        ];
    }

    /** What the buttons of the request may do for this user — the server checks every one again when it is pressed. */
    private function can(AuthUser $u, CreditRequest $r, $open): array
    {
        $ok = fn (?AppError $e) => $e === null;
        $decide = $this->engine->blockDecide($u, $r, $open);
        $final = $r->stage && $r->stage->isWork() && $this->engine->isLast($r->stage);

        return [
            'edit' => $ok(Access::blockEdit($u, $r, $r->stage)) && ! Owned::bySource($r),
            'acceptTerms' => ! Owned::bySource($r) && in_array($r->state, Flow::EDITABLE, true) && (Privacy::isRequester($u, $r) || Access::manages($u)),
            'submit' => $ok($this->engine->blockSubmit($u, $r)),
            'decide' => $ok($decide), 'decideCode' => $decide?->errorCode, 'final' => $final,
            'assess' => $ok($this->engine->blockAssess($u, $r)), 'ceoNotes' => $u->can('credit.approve') && $ok($this->engine->blockAssess($u, $r)),
            'skip' => $ok($this->engine->blockSkip($u, $r)), 'refreshApprovers' => $ok($this->engine->blockManage($u, $r)),
            'withdraw' => $ok($this->engine->blockWithdraw($u, $r)), 'cancel' => $ok($this->engine->blockCancel($u, $r)), 'reopen' => $ok($this->engine->blockReopen($u, $r)),
            'apply' => $ok($this->engine->blockApply($u, $r)),
            'restore' => ! Owned::bySource($r) && Access::manages($u) && $r->state === 'cancelled' && $r->approved_at !== null && $r->apply_status === 'applied',
            'issueSignature' => $ok($this->signature->blockIssue($u, $r)),
            'addDocument' => $ok($this->documents->blockAdd($u, $r, 'other')),
            'addOwnDocument' => $ok($this->documents->blockAdd($u, $r, 'commercial_registration')),
            'reveal' => Privacy::mayReveal($u, $r), 'comment' => true,
        ];
    }

    /** Who the request waits for now: the people and roles of the open lines (a request in the requester's hands waits for him). */
    private function waitingPeople(CreditRequest $r, $open): array
    {
        if (in_array($r->state, Flow::EDITABLE, true)) {
            return ['requester' => true, 'people' => [], 'roles' => []];
        }
        $people = $roles = [];
        foreach ($open as $line) {
            if ($p = $this->people->present($line->user_id, $line->user_name, $line->user_login)) {
                $people[] = $p;
            }
            if ($role = $this->people->role($line->role_key, $line->role_name)) {
                $roles[] = $role;
            }
            if ($line->state === 'escalated') {
                $roles[] = $this->people->role($line->escalated_to_role) ?? ['key' => 'credit.manage', 'nameAr' => 'مدير الائتمان', 'nameEn' => 'Credit manager'];
            }
        }

        return ['requester' => false, 'people' => array_values(array_unique($people, SORT_REGULAR)), 'roles' => array_values(array_unique($roles, SORT_REGULAR)),
            'anyone' => $open->contains(fn (CreditApproval $l) => ! $l->personal && $l->user_id === null && $l->user_login === null && $l->role_key === null && $l->role_name === null)];
    }

    /** The notification log of a request: for the reviewers, approvers and managers. */
    private function notifications(CreditRequest $r): array
    {
        return CreditNotification::where('request_id', $r->id)->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get()->map(fn (CreditNotification $n) => [
            'id' => $n->id, 'channel' => $n->channel, 'template' => $n->template_code, 'status' => $n->status, 'sentAt' => Text::at($n->sent_at), 'error' => $n->error_message,
            'recipient' => $n->recipient_user_id ? $this->people->present($n->recipient_user_id)
                : ($n->recipient_role ? $this->people->role($n->recipient_role) : ($n->recipient_name ? ['nameAr' => $n->recipient_name, 'nameEn' => $n->recipient_name] : null)),
            'to' => $n->recipient_value, 'createdAt' => Text::at($n->created_at), 'origin' => $n->source_system === Owned::SYSTEM ? 'odoo' : 'ops',
        ])->values()->all();
    }

    // ───────────── the lists ─────────────

    private function visible(AuthUser $u, Builder $q): Builder
    {
        return self::seesAll($u) ? $q : $q->where('requester_id', $u->id);
    }

    private function filtered(AuthUser $u, array $f, ?string $q): Builder
    {
        $query = $this->visible($u, CreditRequest::query());
        $state = $f['state'] ?? null;
        match ($state) {
            null, '', 'all' => null,
            'open' => $query->whereNotIn('state', Flow::FINAL),
            'closed' => $query->whereIn('state', Flow::FINAL),
            'work' => $query->whereIn('state', Flow::WORK),
            default => $query->where('state', $state),
        };
        if ($stage = $f['stage'] ?? null) {
            $query->where('stage_id', $stage);
        }
        match ($f['scope'] ?? null) {
            'mine' => $query->where('requester_id', $u->id),
            'waiting' => Access::decides($u) ? $this->waitingFor($u, $query) : $query->whereRaw('1 = 0'),
            default => null,
        };
        $now = now();
        match ($f['sla'] ?? null) {
            'late' => $query->whereNotIn('state', Flow::FINAL)->whereNotNull('sla_deadline')->where('sla_deadline', '<=', $now)->whereNull('escalated_at'),
            'escalated' => $query->whereNotIn('state', Flow::FINAL)->whereNotNull('escalated_at'),
            'due_soon' => $query->whereNotIn('state', Flow::FINAL)->whereNull('escalated_at')->where('sla_deadline', '>', $now)->whereNotNull('sla_due_soon_at')->where('sla_due_soon_at', '<=', $now),
            'on_track' => $query->whereNotIn('state', Flow::FINAL)->whereNull('escalated_at')->where('sla_deadline', '>', $now)->where(fn ($w) => $w->whereNull('sla_due_soon_at')->orWhere('sla_due_soon_at', '>', $now)),
            'none' => $query->where(fn ($w) => $w->whereNull('sla_deadline')->orWhereIn('state', Flow::FINAL)),
            default => null,
        };
        match ($f['origin'] ?? null) {
            'odoo' => $query->where('source_system', Owned::SYSTEM),
            'ops' => $query->whereNull('source_system'),
            default => null,
        };
        if ($customer = $f['customer'] ?? null) {
            $query->where('customer_id', $customer);
        }
        if (in_array($f['apply'] ?? null, ['none', 'applied', 'blocked', 'restored'], true)) {
            $f['apply'] === 'none' ? $query->where(fn ($w) => $w->whereNull('apply_status')->orWhere('apply_status', 'none')) : $query->where('apply_status', $f['apply']);
        }
        if ($from = $f['from'] ?? null) {
            $query->where('request_date', '>=', $from);
        }
        if ($to = $f['to'] ?? null) {
            $query->where('request_date', '<=', $to);
        }
        if ($q !== null && trim($q) !== '') {
            $like = Text::like($q);
            // never an identity number or a bank account: those are not searched
            $query->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('source_name', 'like', $like)->orWhere('company_name', 'like', $like)
                ->orWhere('partner_name', 'like', $like)->orWhere('commercial_registration', 'like', $like)->orWhere('owner_name', 'like', $like)->orWhere('city', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)));
        }

        return $query;
    }

    /**
     * The requests whose stage waits for this user's answer — the same people WorkflowEngine::addressed() recognises,
     * as one query: his own line, a line of his role, a line escalated to his role (or to the managers), a line open to
     * any holder; never his own request; the final approval only with credit.approve.
     */
    private function waitingFor(AuthUser $u, Builder $query): Builder
    {
        $logins = $this->people->identities($u);
        $roles = $u->roles;
        $manages = Access::manages($u);
        $query->whereIn('state', Flow::WORK)->where(fn ($w) => $w->whereNull('requester_id')->orWhere('requester_id', '!=', $u->id))
            ->whereExists(function ($x) use ($u, $logins, $roles, $manages) {
                $x->select(DB::raw('1'))->from('credit_approvals as a')->whereColumn('a.request_id', 'credit_requests.id')->whereColumn('a.stage_id', 'credit_requests.stage_id')
                    ->whereColumn('a.round', 'credit_requests.round')->whereIn('a.state', Flow::LINE_OPEN)
                    ->where(function ($w) use ($u, $logins, $roles, $manages) {
                        $w->where('a.user_id', $u->id);
                        if ($logins) {
                            $w->orWhere(fn ($v) => $v->whereNull('a.user_id')->whereIn('a.user_login', $logins));
                        }
                        if ($roles) {
                            $w->orWhere(fn ($v) => $v->where('a.personal', false)->whereIn('a.role_key', $roles));
                            $w->orWhere(fn ($v) => $v->where('a.state', 'escalated')->whereIn('a.escalated_to_role', $roles));
                        }
                        if ($manages) {
                            $w->orWhere(fn ($v) => $v->where('a.state', 'escalated')->whereNull('a.escalated_to_role'));
                        }
                        $w->orWhere(fn ($v) => $v->where('a.personal', false)->whereNull('a.user_id')->whereNull('a.user_login')->whereNull('a.role_key')->whereNull('a.role_name'));
                    });
            });
        if (! $u->can('credit.approve') && ($last = $this->engine->path()->last())) {
            $query->where('stage_id', '!=', $last->id);
        }

        return $query;
    }

    private function order(Builder $query, ?string $sort, string $order): void
    {
        $column = self::SORTS[$sort ?? ''] ?? null;
        if ($column === null) {
            $query->orderByDesc('created_at')->orderByDesc('id');

            return;
        }
        if (in_array($column, self::SPARSE, true)) {
            $query->orderByRaw("{$column} IS NULL");
        }
        $query->orderBy($column, $order)->orderBy('id', $order);
    }

    // ───────────── filling in ─────────────

    /** The request's own fields from the form. A key that is absent leaves the field as it is; an empty one clears it. */
    private function fill(AuthUser $u, CreditRequest $r, array $dto): void
    {
        foreach (self::TEXT as $key => [$column, $max]) {
            if (array_key_exists($key, $dto)) {
                $r->setAttribute($column, Text::clean($dto[$key], $max));
            }
        }
        if (array_key_exists('email', $dto) && $r->email !== null) {
            $r->email = mb_strtolower($r->email);
        }
        foreach (['requestedCreditLimit' => 'requested_credit_limit', 'monthlyPurchaseVolume' => 'monthly_purchase_volume'] as $key => $column) {
            if (array_key_exists($key, $dto)) {
                $r->setAttribute($column, $dto[$key] === null || $dto[$key] === '' ? null : round((float) $dto[$key], 2));
            }
        }
        if (array_key_exists('requestedPaymentDays', $dto)) {
            $r->requested_payment_days = $dto['requestedPaymentDays'] === null || $dto['requestedPaymentDays'] === '' ? null : (int) $dto['requestedPaymentDays'];
        }
        if (array_key_exists('ownerNationalId', $dto) && ! self::masked($dto['ownerNationalId'])) {
            $value = Text::clean($dto['ownerNationalId'], 20);
            if ($value !== $r->owner_national_id) {
                $r->forceFill(['owner_national_id' => $value, 'owner_national_id_by' => $value === null ? null : $u->id]);
            }
        }
    }

    /**
     * The lines of the form: responsible persons, authorised signatories, bank accounts, payment methods. A list that is
     * sent replaces the lines of its kind (a line keeps its id); a list that is absent is left as it is.
     *
     * @return string[] the kinds of lines that changed
     */
    private function lines(AuthUser $u, CreditRequest $r, array $dto): array
    {
        $changed = [];
        if (array_key_exists('paymentMethodIds', $dto)) {
            $ids = array_values(array_unique(array_map('strval', (array) $dto['paymentMethodIds'])));
            $found = CreditPaymentMethod::whereIn('id', $ids)->pluck('active', 'id');
            foreach ($ids as $id) {
                if (! isset($found[$id])) {
                    throw AppError::validation('CREDIT_PAYMENT_METHOD_UNKNOWN', 'طريقة سداد غير معروفة', 'Unknown payment method', [['path' => 'paymentMethodIds', 'message' => $id]]);
                }
            }
            $before = $r->paymentMethods()->pluck('credit_payment_methods.id')->map(fn ($id) => (string) $id)->sort()->values()->all();
            $after = collect($ids)->sort()->values()->all();
            if ($before !== $after) {
                // a method archived since stays on a request that had it; a new choice must be an active one
                foreach (array_diff($after, $before) as $id) {
                    if (! $found[$id]) {
                        throw AppError::validation('CREDIT_PAYMENT_METHOD_ARCHIVED', 'طريقة السداد هذه مؤرشفة', 'This payment method is archived', [['path' => 'paymentMethodIds', 'message' => $id]]);
                    }
                }
                $r->paymentMethods()->sync($ids);
                $r->unsetRelation('paymentMethods');
                $changed[] = 'paymentMethods';
            }
        }
        if (array_key_exists('responsibles', $dto)) {
            if ($this->replace($r, CreditResponsible::class, (array) $dto['responsibles'], 'responsibles', function (CreditResponsible $l, array $in) use ($u) {
                $l->name = (string) Text::clean($in['name'] ?? null, 190);
                $l->position = Text::clean($in['position'] ?? null, 120);
                $l->mobile = Text::clean($in['mobile'] ?? null, 40);
                if (array_key_exists('nationalId', $in) && ! self::masked($in['nationalId'])) {
                    $value = Text::clean($in['nationalId'], 20);
                    if ($value !== $l->national_id) {
                        $l->national_id = $value;
                        $l->entered_by_id = $value === null ? null : $u->id;
                    }
                }
            })) {
                $changed[] = 'responsibles';
            }
        }
        if (array_key_exists('signatories', $dto)) {
            if ($this->replace($r, CreditSignatory::class, (array) $dto['signatories'], 'signatories', function (CreditSignatory $l, array $in) {
                $l->name = (string) Text::clean($in['name'] ?? null, 190);
                $l->job_title = Text::clean($in['jobTitle'] ?? null, 120);
                $l->mobile = Text::clean($in['mobile'] ?? null, 40);
                $l->signature_note = Text::clean($in['signatureNote'] ?? null, 250);
            })) {
                $changed[] = 'signatories';
            }
        }
        if (array_key_exists('bankLines', $dto)) {
            if ($this->replace($r, CreditBankLine::class, (array) $dto['bankLines'], 'bankLines', function (CreditBankLine $l, array $in) use ($u) {
                $l->bank_name = (string) Text::clean($in['bankName'] ?? null, 120);
                $l->branch = Text::clean($in['branch'] ?? null, 120);
                $l->swift = ($s = Text::clean($in['swift'] ?? null, 11)) ? mb_strtoupper($s) : null;
                $typed = false;
                if (array_key_exists('accountNumber', $in) && ! self::masked($in['accountNumber'])) {
                    $value = Text::clean($in['accountNumber'], 34);
                    if ($value !== $l->account_number) {
                        $l->account_number = $value;
                        $typed = true;
                    }
                }
                if (array_key_exists('iban', $in) && ! self::masked($in['iban'])) {
                    $raw = Text::clean($in['iban'], 42);
                    $value = $raw === null ? null : Text::iban($raw);
                    if ($raw !== null && $value === null) {
                        throw AppError::validation('CREDIT_IBAN_INVALID', 'رقم الآيبان غير صحيح (رمز الدولة ورقم التحقق)', 'The IBAN is not valid (country code and check digits)', [['path' => 'bankLines.iban', 'message' => 'invalid']]);
                    }
                    if ($value !== $l->iban) {
                        $l->iban = $value;
                        $typed = true;
                    }
                }
                if ($typed) {
                    $l->entered_by_id = $u->id;
                }
            })) {
                $changed[] = 'bankLines';
            }
        }

        return $changed;
    }

    /** Replaces the lines of one kind; true when anything changed. */
    private function replace(CreditRequest $r, string $class, array $incoming, string $kind, callable $apply): bool
    {
        if (count($incoming) > self::MAX_LINES[$kind]) {
            throw AppError::validation('CREDIT_TOO_MANY_LINES', 'عدد الأسطر أكبر من المسموح', 'Too many lines', [['path' => $kind, 'message' => 'max '.self::MAX_LINES[$kind]]]);
        }
        $existing = $class::where('request_id', $r->id)->get()->keyBy('id');
        $keep = [];
        $changed = false;
        foreach (array_values($incoming) as $i => $in) {
            $in = (array) $in;
            $id = isset($in['id']) ? (string) $in['id'] : null;
            if ($id !== null && ! $existing->has($id)) {
                throw AppError::validation('CREDIT_LINE_UNKNOWN', 'سطر غير موجود على هذا الطلب', 'The line is not on this request', [['path' => "{$kind}.{$i}.id", 'message' => $id]]);
            }
            $line = $id !== null ? $existing->get($id) : new $class;
            $line->request_id = $r->id;
            $line->line_no = $i + 1;
            $apply($line, $in);
            if (trim((string) ($line->name ?? $line->bank_name ?? '')) === '') {
                throw AppError::validation('CREDIT_LINE_NAME_REQUIRED', 'كل سطر يحتاج اسمًا', 'Every line needs a name', [['path' => "{$kind}.{$i}", 'message' => 'required']]);
            }
            if (! $line->exists || $line->isDirty()) {
                $changed = true;
                $line->save();
            }
            $keep[] = (string) $line->id;
        }
        foreach ($existing as $id => $line) {
            if (! in_array((string) $id, $keep, true)) {
                $line->delete();
                $changed = true;
            }
        }
        if ($changed) {
            $r->unsetRelation($kind);
        }

        return $changed;
    }

    /** The values of the request the audit compares — personal ones as the fact that one is there. */
    private function snapshotForAudit(CreditRequest $r): array
    {
        $out = ['customerId' => (string) $r->customer_id];
        foreach (self::TEXT as $key => [$column]) {
            $out[$key] = (string) $r->getAttribute($column);
        }
        $out['requestedCreditLimit'] = (string) Text::money($r->requested_credit_limit);
        $out['monthlyPurchaseVolume'] = (string) Text::money($r->monthly_purchase_volume);
        $out['requestedPaymentDays'] = (string) $r->requested_payment_days;
        $out['ownerNationalId'] = $r->owner_national_id === null ? '' : hash('sha256', (string) $r->owner_national_id); // the audit gets '•••' (CreditLog scrubs the key)

        return $out;
    }

    private static function masked(mixed $v): bool
    {
        return is_string($v) && str_contains($v, '•');
    }

    private function lockCustomer(string $id): Customer
    {
        $customer = Customer::where(fn ($w) => $w->where('id', $id)->orWhere('code', $id))->lockForUpdate()->first()
            ?? throw AppError::notFound('CUSTOMER_NOT_FOUND', 'العميل غير موجود', 'Customer not found');
        if (! $customer->active) {
            throw AppError::rule('CREDIT_CUSTOMER_INACTIVE', "العميل {$customer->code} موقوف — لا يُطلب له ائتمان", "Customer {$customer->code} is inactive — no credit is requested for it");
        }

        return $customer;
    }

    private function assertNoOpenRequest(Customer $customer, ?string $except = null): void
    {
        $open = CreditRequest::where('customer_id', $customer->id)->whereNotIn('state', Flow::FINAL)->when($except, fn ($q) => $q->where('id', '!=', $except))->value('number');
        if ($open) {
            throw AppError::conflict('CREDIT_OPEN_REQUEST_EXISTS', "للعميل {$customer->code} طلب ائتمان مفتوح ({$open}) — يُكمَل أو يُلغى قبل طلب جديد", "Customer {$customer->code} already has an open credit request ({$open}) — complete or cancel it first");
        }
    }
}
