<?php

namespace App\Portal\Services;

use App\Portal\Models\Application;
use App\Portal\Support\Owned;
use App\Portal\Support\Parties;
use App\Portal\Support\PortalLog;
use App\Portal\Support\Shape;
use App\Portal\Support\Text;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Onboarding applications: a company asks to become a customer, hands in its papers, and a person reviews it.
 *
 * STATE MACHINE (`Application::TRANSITIONS`). draft → submitted; a reviewer asks for a correction (with what to
 * correct), the company completes it and it is submitted again; a reviewer approves — the company becomes an OPS
 * customer, see OnboardingApproval — or refuses with a reason. A draft or a correction nobody completes is expired; a
 * rejected or expired application is reopened as a draft. Every move is one line of the application's timeline and of
 * the audit trail, and a move the table does not allow is refused (`PORTAL_APPLICATION_TRANSITION`).
 *
 * OWNERSHIP. While the section follows Odoo an application that came from Odoo is Odoo's: its particulars and its
 * state are refused here with `PORTAL_OWNED_BY_SOURCE`. Notes and documents added in OPS are OPS's own on every
 * application.
 *
 * PRIVACY. The owner's identity number is a person's identity paper: it is shown in full only to who decides
 * applications (`portal.manage`), masked to other readers of the page, and never written to the audit trail, the
 * timeline or a log.
 *
 * Payload arrays are camelCase and already validated by the controller. In an edit, a key that is present with a null
 * value means "sent empty" (clear it) and an absent key means "leave as it is".
 */
class ApplicationsService
{
    private const SORTS = ['createdAt' => 'created_at', 'number' => 'number', 'companyNameAr' => 'company_name_ar', 'submittedAt' => 'submitted_at', 'decidedAt' => 'decided_at', 'status' => 'status'];

    private const SPARSE = ['company_name_ar', 'submitted_at', 'decided_at'];

    /** Groups of states the lists ask for by one word. */
    public const GROUPS = ['open' => ['draft', 'submitted', 'correction', 'accepted'], 'review' => ['submitted', 'accepted'], 'closed' => ['approved', 'rejected', 'expired']];

    /** Text of the form: camelCase key → [column, longest]. */
    private const TEXT = [
        'companyNameAr' => ['company_name_ar', 250], 'companyNameEn' => ['company_name_en', 250], 'ownerName' => ['owner_name', 190], 'managerName' => ['manager_name', 190],
        'email' => ['email', 190], 'mobile' => ['mobile', 40], 'street' => ['street', 250], 'buildingNo' => ['building_no', 20], 'additionalNo' => ['additional_no', 20],
        'district' => ['district', 120], 'city' => ['city', 120], 'region' => ['region', 120], 'postalCode' => ['postal_code', 20], 'termsVersion' => ['terms_version', 120],
    ];

    /** Numbers people type with spaces or dashes: camelCase key → column. Kept as digits. */
    private const NUMBERS = ['cr' => 'cr', 'vatNo' => 'vat_no', 'unifiedNo' => 'unified_no', 'ownerNationalId' => 'owner_national_id'];

    /** Never written to the audit trail or the timeline. */
    private const PRIVATE_KEYS = ['ownerNationalId', 'owner_national_id'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
        private readonly OnboardingApproval $approval,
        private readonly ApplicationDocuments $documents,
    ) {}

    // ───────────── reading ─────────────

    /** @param  array{status?:?string, origin?:?string, customer?:?string, from?:?string, to?:?string}  $f */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $query = $this->filtered($f, $page->q)->with([Parties::CUSTOMER]);
        $column = self::SORTS[$page->sort ?? ''] ?? null;
        if ($column === null) {
            $query->orderByDesc('created_at')->orderByDesc('id');
        } else {
            if (in_array($column, self::SPARSE, true)) {
                $query->orderByRaw("{$column} IS NULL");
            }
            $query->orderBy($column, $page->order)->orderBy('id', $page->order);
        }

        return $page->paginate($query, fn (Application $a) => Shape::application($a));
    }

    public function get(AuthUser $user, string $id): array
    {
        $a = $this->find($id);
        $decides = $user->can('portal.manage');
        $owned = Owned::bySource($a);
        $st = $a->status;
        $free = fn (string ...$from) => ! $owned && in_array($st, $from, true);

        return Shape::application($a, true, $decides) + [
            'documents' => $this->documents->of($a, $decides),
            'events' => PortalLog::of('application', $a->id, 200),
            'checks' => self::checks($a, $this->documents->kinds($a)),
            // who this company would be in OPS: the customer identity already names, or the ones a person may recognise
            'match' => in_array($st, ['draft', 'submitted', 'correction', 'accepted'], true) ? $this->approval->preview($a) : null,
            // what may be done to it now — the client shows a button only for what would be accepted
            'can' => [
                'edit' => $free(...Application::EDITABLE), 'submit' => $free('draft', 'correction'), 'requestCorrection' => $free('submitted'), 'approve' => $free('submitted', 'accepted'),
                'reject' => $free('submitted', 'correction', 'accepted'), 'expire' => $free('draft', 'correction'), 'reopen' => $free('rejected', 'expired'), 'note' => true, 'addDocument' => true,
            ],
        ];
    }

    public function filtered(array $f, ?string $q): Builder
    {
        $query = Application::query();
        $status = trim((string) ($f['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $states = self::GROUPS[$status] ?? array_values(array_intersect(Application::STATUSES, explode(',', $status)));
            $states ? $query->whereIn('status', $states) : $query->whereRaw('1 = 0');
        }
        Parties::filterOrigin($query, $f['origin'] ?? null);
        Parties::filterCustomer($query, $f['customer'] ?? null);
        if (! empty($f['from'])) {
            $query->where('created_at', '>=', Carbon::parse($f['from'])->startOfDay());
        }
        if (! empty($f['to'])) {
            $query->where('created_at', '<', Carbon::parse($f['to'])->startOfDay()->addDay());
        }
        if ($q !== null && $q !== '') {
            $like = Text::like($q);
            $op = Sql::like();
            $query->where(function ($w) use ($like, $op, $q) {
                foreach (['number', 'company_name_ar', 'company_name_en', 'cr', 'vat_no', 'unified_no', 'mobile', 'email', 'city'] as $column) {
                    $w->orWhere($column, $op, $like);
                }
                if (ctype_digit($q)) {
                    $w->orWhere('external_ref', $q); // the number people know the application by in Odoo
                }
            });
        }

        return $query;
    }

    // ───────────── writing ─────────────

    /** A new application, entered for a company by a member of staff or handed in by the sales platform. `submit` sends it for review at once. */
    public function create(AuthUser $user, array $dto): array
    {
        $id = DB::transaction(function () use ($user, $dto) {
            $data = $this->values($dto, null);
            if (empty($data['company_name_ar'])) {
                throw AppError::validation('PORTAL_APPLICATION_NAME', 'اسم المنشأة بالعربية إلزامي', 'The company\'s Arabic name is required', [['path' => 'companyNameAr', 'message' => 'هذا الحقل إلزامي']]);
            }
            $a = Application::create($data + [
                'number' => $this->numbering->next('APL'), 'status' => 'draft', 'language' => 'ar', 'created_by' => $user->username, 'updated_by' => $user->username,
            ]);
            PortalLog::add('application', $a->id, 'created', $user);
            $this->audit->log($user, ['action' => 'PORTAL.APPLICATION.CREATE', 'entityType' => 'PortalApplication', 'entityId' => $a->id, 'entityNumber' => $a->number, 'newValue' => self::safe($dto)]);
            if (! empty($dto['submit'])) {
                $this->toSubmitted($user, $a);
            }

            return $a->id;
        });

        return $this->get($user, $id);
    }

    public function update(AuthUser $user, string $id, array $dto): array
    {
        DB::transaction(function () use ($user, $id, $dto) {
            $a = self::locked($id);
            Owned::refuse($a, 'application');
            if (! in_array($a->status, Application::EDITABLE, true)) {
                throw AppError::rule('PORTAL_APPLICATION_CLOSED', "طلب فتح الحساب {$a->number} ".self::stateAr($a->status).' — لا تُعدَّل بياناته', "Application {$a->number} is {$a->status} — its particulars cannot be changed", ['status' => $a->status]);
            }
            $changes = Shape::changes($a, $this->values($dto, $a));
            if (array_key_exists('company_name_ar', $changes) && $changes['company_name_ar'] === null) {
                throw AppError::validation('PORTAL_APPLICATION_NAME', 'اسم المنشأة بالعربية إلزامي', 'The company\'s Arabic name is required', [['path' => 'companyNameAr', 'message' => 'هذا الحقل إلزامي']]);
            }
            if (! $changes) {
                return;
            }
            $old = [];
            foreach (array_keys($changes) as $column) {
                $old[$column] = $a->getAttribute($column);
            }
            $a->update($changes + ['updated_by' => $user->username]);
            PortalLog::add('application', $a->id, 'updated', $user, ['data' => ['fields' => array_map(fn (string $c) => Shape::camel($c), array_keys($changes))]]);
            $this->audit->log($user, ['action' => 'PORTAL.APPLICATION.UPDATE', 'entityType' => 'PortalApplication', 'entityId' => $a->id, 'entityNumber' => $a->number,
                'oldValue' => self::safe($old), 'newValue' => self::safe($changes)]);
        });

        return $this->get($user, $id);
    }

    /** draft | correction → submitted: the application is complete enough to be reviewed. */
    public function submit(AuthUser $user, string $id): array
    {
        DB::transaction(function () use ($user, $id) {
            $a = self::locked($id);
            Owned::refuse($a, 'application');
            $this->toSubmitted($user, $a);
        });

        return $this->get($user, $id);
    }

    /** submitted → correction: the company is told what to complete or correct. */
    public function requestCorrection(AuthUser $user, string $id, string $note): array
    {
        DB::transaction(function () use ($user, $id, $note) {
            $a = self::locked($id);
            Owned::refuse($a, 'application');
            $from = self::move($a, 'correction');
            $a->update(['status' => 'correction', 'correction_note' => trim($note), 'updated_by' => $user->username]);
            PortalLog::add('application', $a->id, 'correction', $user, ['note' => $note]);
            $this->audit->status($user, 'PortalApplication', $a->id, $a->number, $from, 'correction', mb_substr(trim($note), 0, 500));
            $this->notify->activity($user, 'PortalApplication', $a->id, $a->number, "طُلب تصحيح طلب فتح الحساب {$a->number} — {$a->company_name_ar}", "Correction requested on application {$a->number}");
        });

        return $this->get($user, $id);
    }

    /** submitted | correction | accepted → rejected, with the reason the company is given. */
    public function reject(AuthUser $user, string $id, string $reason): array
    {
        DB::transaction(function () use ($user, $id, $reason) {
            $a = self::locked($id);
            Owned::refuse($a, 'application');
            $from = self::move($a, 'rejected');
            $a->update(['status' => 'rejected', 'rejection_reason' => trim($reason), 'decided_at' => now(), 'decided_by' => $user->username, 'updated_by' => $user->username]);
            PortalLog::add('application', $a->id, 'rejected', $user, ['note' => $reason]);
            $this->audit->status($user, 'PortalApplication', $a->id, $a->number, $from, 'rejected', mb_substr(trim($reason), 0, 500));
            $this->notify->activity($user, 'PortalApplication', $a->id, $a->number, "رُفض طلب فتح الحساب {$a->number} — {$a->company_name_ar}", "Application {$a->number} rejected");
        });

        return $this->get($user, $id);
    }

    /** draft | correction → expired: nobody completed it. */
    public function expire(AuthUser $user, string $id, ?string $reason): array
    {
        DB::transaction(function () use ($user, $id, $reason) {
            $a = self::locked($id);
            Owned::refuse($a, 'application');
            $from = self::move($a, 'expired');
            $a->update(['status' => 'expired', 'expired_at' => now(), 'updated_by' => $user->username]);
            PortalLog::add('application', $a->id, 'expired', $user, ['note' => $reason]);
            $this->audit->status($user, 'PortalApplication', $a->id, $a->number, $from, 'expired', Text::clean($reason, 500));
        });

        return $this->get($user, $id);
    }

    /** rejected | expired → draft: the company gets another go; what was decided before stays on the timeline. */
    public function reopen(AuthUser $user, string $id, ?string $note): array
    {
        DB::transaction(function () use ($user, $id, $note) {
            $a = self::locked($id);
            Owned::refuse($a, 'application');
            $from = self::move($a, 'draft');
            $a->update(['status' => 'draft', 'rejection_reason' => null, 'decided_at' => null, 'decided_by' => null, 'expired_at' => null, 'updated_by' => $user->username]);
            PortalLog::add('application', $a->id, 'reopened', $user, ['note' => $note, 'data' => ['from' => $from]]);
            $this->audit->status($user, 'PortalApplication', $a->id, $a->number, $from, 'draft', Text::clean($note, 500));
        });

        return $this->get($user, $id);
    }

    /** A note on the timeline. OPS's own on every application — also on one that Odoo owns. */
    public function note(AuthUser $user, string $id, string $text): array
    {
        DB::transaction(function () use ($user, $id, $text) {
            $a = self::locked($id);
            PortalLog::add('application', $a->id, 'note', $user, ['note' => $text]);
            $this->audit->log($user, ['action' => 'PORTAL.APPLICATION.NOTE', 'entityType' => 'PortalApplication', 'entityId' => $a->id, 'entityNumber' => $a->number, 'newValue' => mb_substr(trim($text), 0, 300)]);
        });

        return $this->get($user, $id);
    }

    // ───────────── pieces ─────────────

    public function find(string $id): Application
    {
        return Application::with([Parties::CUSTOMER])->where(fn ($w) => $w->where('id', $id)->orWhere('number', $id))->first()
            ?? throw AppError::notFound('PORTAL_APPLICATION_NOT_FOUND', 'طلب فتح الحساب غير موجود', 'Application not found');
    }

    /** The application, its row held for the rest of the transaction (two people cannot decide it at once). */
    public static function locked(string $id): Application
    {
        return Application::where(fn ($w) => $w->where('id', $id)->orWhere('number', $id))->lockForUpdate()->first()
            ?? throw AppError::notFound('PORTAL_APPLICATION_NOT_FOUND', 'طلب فتح الحساب غير موجود', 'Application not found');
    }

    /** Refuses a move the state machine does not allow. @return string the state it leaves */
    public static function move(Application $a, string $to): string
    {
        if (! in_array($to, Application::TRANSITIONS[$a->status] ?? [], true)) {
            throw AppError::rule('PORTAL_APPLICATION_TRANSITION', "طلب فتح الحساب {$a->number} ".self::stateAr($a->status).' — لا ينتقل إلى «'.self::stateAr($to).'»', "Application {$a->number} is {$a->status} — it cannot become {$to}",
                ['from' => $a->status, 'to' => $to, 'allowed' => Application::TRANSITIONS[$a->status] ?? []]);
        }

        return $a->status;
    }

    private function toSubmitted(AuthUser $user, Application $a): void
    {
        $from = self::move($a, 'submitted');
        $missing = array_values(array_filter(self::checks($a, $this->documents->kinds($a)), fn (array $c) => $c['blocking'] && ! $c['ok']));
        if ($missing) {
            throw AppError::rule('PORTAL_APPLICATION_INCOMPLETE', 'الطلب غير مكتمل: '.implode('، ', array_column($missing, 'ar')), 'The application is incomplete: '.implode(', ', array_column($missing, 'en')),
                array_map(fn (array $c) => ['path' => $c['key'], 'message' => $c['ar']], $missing));
        }
        $a->update(['status' => 'submitted', 'submitted_at' => now(), 'updated_by' => $user->username]);
        PortalLog::add('application', $a->id, $from === 'correction' ? 'resubmitted' : 'submitted', $user);
        $this->audit->status($user, 'PortalApplication', $a->id, $a->number, $from, 'submitted');
        $this->notify->activity($user, 'PortalApplication', $a->id, $a->number, "طلب فتح حساب بانتظار المراجعة: {$a->number} — {$a->company_name_ar}", "Application {$a->number} is waiting for review — {$a->company_name_ar}",
            self::reviewerRoles());
    }

    /**
     * What an application must hold, each with whether it does. `blocking` ones stop it from being submitted or
     * approved; the others are for the reviewer's eye — a missing paper is a judgement, not a rule of the system.
     *
     * @param  string[]  $documentKinds  kinds of the papers that are on the application now
     * @return array<int, array{key:string, ok:bool, blocking:bool, ar:string, en:string}>
     */
    public static function checks(Application $a, array $documentKinds): array
    {
        $has = fn (string $kind) => in_array($kind, $documentKinds, true);

        return [
            ['key' => 'companyNameAr', 'ok' => (bool) $a->company_name_ar, 'blocking' => true, 'ar' => 'اسم المنشأة بالعربية', 'en' => 'the company\'s Arabic name'],
            ['key' => 'cr', 'ok' => (bool) ($a->cr || $a->vat_no), 'blocking' => true, 'ar' => 'السجل التجاري أو الرقم الضريبي', 'en' => 'the commercial registration or the VAT number'],
            ['key' => 'mobile', 'ok' => (bool) ($a->mobile || $a->email), 'blocking' => true, 'ar' => 'جوال أو بريد للتواصل', 'en' => 'a mobile number or an e-mail'],
            ['key' => 'doc.cr', 'ok' => $has('cr'), 'blocking' => false, 'ar' => 'صورة السجل التجاري', 'en' => 'the commercial registration certificate'],
            ['key' => 'doc.vat', 'ok' => ! $a->vat_no || $has('vat'), 'blocking' => false, 'ar' => 'شهادة التسجيل الضريبي', 'en' => 'the VAT certificate'],
            ['key' => 'doc.national_address', 'ok' => $has('national_address'), 'blocking' => false, 'ar' => 'العنوان الوطني', 'en' => 'the national address'],
            ['key' => 'termsAccepted', 'ok' => (bool) $a->terms_accepted, 'blocking' => false, 'ar' => 'الموافقة على الشروط', 'en' => 'acceptance of the terms'],
        ];
    }

    /** Role keys that hold `portal.manage` — who is told that an application waits for review. @return string[] */
    public static function reviewerRoles(): array
    {
        return DB::table('roles')->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.key', 'portal.manage')->where('roles.key', '!=', 'integration')->pluck('roles.key')->unique()->values()->all();
    }

    /**
     * The columns a payload sets. For a new application (`$a` null) empty values are simply left out.
     *
     * @return array<string, mixed>
     */
    private function values(array $dto, ?Application $a): array
    {
        $data = [];
        foreach (self::TEXT as $key => [$column, $max]) {
            if (array_key_exists($key, $dto)) {
                $data[$column] = Text::clean($dto[$key], $max);
            }
        }
        foreach (self::NUMBERS as $key => $column) {
            if (array_key_exists($key, $dto)) {
                $data[$column] = Text::digits($dto[$key]);
            }
        }
        // the two numbers a Saudi company is recognised by have a fixed length: a mistyped one is caught here, not at the approval
        foreach (['cr' => [10, 'السجل التجاري', 'commercial registration'], 'vat_no' => [15, 'الرقم الضريبي', 'VAT number']] as $column => [$length, $ar, $en]) {
            if (isset($data[$column]) && strlen($data[$column]) !== $length) {
                $key = $column === 'cr' ? 'cr' : 'vatNo';
                throw AppError::validation('PORTAL_APPLICATION_NUMBER', "{$ar} يجب أن يكون {$length} رقمًا", "The {$en} must be {$length} digits", [['path' => $key, 'message' => "{$length} رقمًا"]]);
            }
        }
        if (array_key_exists('legalEntityType', $dto)) {
            $data['legal_entity_type'] = $dto['legalEntityType'] ?: null;
        }
        if (array_key_exists('language', $dto) && $dto['language']) {
            $data['language'] = $dto['language'];
        }
        if (array_key_exists('contacts', $dto)) {
            $contacts = [];
            foreach ((array) ($dto['contacts'] ?? []) as $c) {
                $name = Text::clean($c['name'] ?? null, 190);
                if ($name === null) {
                    continue;
                }
                $contacts[] = array_filter(['role' => in_array($c['role'] ?? null, Application::CONTACT_ROLES, true) ? $c['role'] : 'other', 'name' => $name,
                    'mobile' => Text::clean($c['mobile'] ?? null, 40), 'email' => Text::clean($c['email'] ?? null, 190)], fn ($v) => $v !== null);
            }
            $data['contacts'] = $contacts ?: null;
        }
        if (array_key_exists('termsAccepted', $dto) && $dto['termsAccepted'] !== null) {
            $accepted = filter_var($dto['termsAccepted'], FILTER_VALIDATE_BOOLEAN);
            $data['terms_accepted'] = $accepted;
            if ($accepted !== (bool) $a?->terms_accepted) {
                $data['terms_accepted_at'] = $accepted ? now() : null;
            }
        }
        if ($a === null) {
            $data = array_filter($data, fn ($v) => $v !== null);
        }

        return $data;
    }

    /** A payload as it may be written to the audit trail: without a person's identity number. */
    private static function safe(array $values): array
    {
        foreach (self::PRIVATE_KEYS as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = $values[$key] === null ? null : '•••';
            }
        }

        return $values;
    }

    public static function stateAr(string $status): string
    {
        return ['draft' => 'مسودة', 'submitted' => 'بانتظار المراجعة', 'correction' => 'بانتظار تصحيح العميل', 'accepted' => 'بانتظار توقيع العميل', 'approved' => 'معتمد',
            'rejected' => 'مرفوض', 'expired' => 'منتهي'][$status] ?? $status;
    }
}
