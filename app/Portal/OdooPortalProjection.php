<?php

namespace App\Portal;

use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Odoo\Projection\Val;
use App\Integration\Services\ExternalRefs;
use App\Models\Customer;
use App\Models\Product;
use App\Portal\Models\Application;
use App\Portal\Models\ApplicationDocument;
use App\Portal\Models\FavoriteList;
use App\Portal\Models\OrderRequest;
use App\Portal\Models\OrderRequestLine;
use App\Portal\Models\PortalBranch;
use App\Portal\Models\Statement;
use App\Portal\Support\PortalLog;
use App\Portal\Support\Shape;
use App\Services\Core\AuditService;
use App\Support\AppError;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * From the copy of Odoo's "Customer Portal Hub" and "Branch Invoice Statement" to «بوابة العملاء»: Odoo's customer
 * branches, favourite lists and their items, onboarding applications with the list of their papers, order requests
 * with their lines, the log of order-line changes and the customer statements with their lines become records of the
 * section, marked `source_system = 'odoo'` with the Odoo id in `external_ref`. Idempotent: a second pass changes
 * nothing.
 *
 *   portal.customer.branch         → portal_branches              customer.statement       → portal_statements
 *   customer.portal.favorite.list  → portal_favorite_lists        customer.statement.line  → portal_statement_lines
 *   customer.portal.favorite       → portal_favorite_items        hub.order.request        → portal_order_requests
 *   portal.onboarding.application  → portal_applications          hub.order.request.line   → portal_order_request_lines
 *     (+ its papers, listed from ir.attachment)                   hub.order.line.change    → portal_line_changes
 *
 * VOLUME. Favourites are ~156 k records, the change log ~10 k, statement lines ~6 k. In a whole pass (plan / apply)
 * those three are read from the copy 500 at a time and written 500 at a time — one read of what OPS already holds, one
 * insert of what is new, one update per row that really changed — never one statement per record. The feed
 * (`consume`) applies one record at a time through the very same code. A batch that cannot be written is done again
 * record by record, so one bad record is left out and reported, not its 499 neighbours.
 *
 * LINKING is by identity only. A customer is the OPS customer its Odoo partner — or that partner's company — is
 * linked to (`int_external_refs`, system `odoo`, entity `customer`); a product the OPS product its Odoo product is
 * linked to (entity `product`). A partner or product without a link keeps its Odoo id and name on the record, and the
 * link is filled the day it exists (`relink()`, looked at on every run and only worked through when the links changed).
 *
 * WHAT IS ODOO'S on such a record is what Odoo holds; a pass writes those values and nothing else. What OPS adds —
 * a branch's address, contact, delivery window and delivery site, the branch a list is kept for, notes, papers added
 * here, the customer an application was approved as here — is never touched.
 *
 * GONE FROM ODOO. A branch or a list is archived, an application / request / statement is marked and stays, a line of
 * the change log stays (it is history). A favourite and a statement line follow Odoo: Odoo regenerates a statement by
 * replacing its lines, and a favourite the customer removed is not a favourite any more.
 *
 * NOT TAKEN: the text Odoo extracted from an applicant's papers (`extraction_log`) — it repeats what the papers say
 * about a person and stays in the copy only. plan() also runs where the section's tables do not exist yet (it then
 * counts everything as new) — it never writes.
 */
class OdooPortalProjection extends OdooProjection
{
    private const CHUNK = 500;

    /** The models a whole pass writes in batches → the name of their line in the counts. */
    private const BULK = ['customer.portal.favorite' => 'favorites', 'hub.order.line.change' => 'lineChanges', 'customer.statement.line' => 'statementLines'];

    /** The Odoo model behind each parent a child points at. */
    private const PARENT_MODEL = ['branches' => 'portal.customer.branch', 'lists' => 'customer.portal.favorite.list', 'requests' => 'hub.order.request', 'statements' => 'customer.statement'];

    private const PARENT_CLASS = ['branches' => PortalBranch::class, 'lists' => FavoriteList::class, 'requests' => OrderRequest::class, 'statements' => Statement::class];

    /** The word of each entity in the actions of the audit trail (`PORTAL.<WORD>.CREATE`). */
    private const AUDIT_WORD = ['branches' => 'BRANCH', 'lists' => 'FAVLIST', 'requests' => 'REQUEST', 'statements' => 'STATEMENT'];

    /** Odoo's states of an application → the states of the OPS review (the Odoo word is kept beside it). */
    private const APPLICATION_STATE = [
        'draft' => 'draft', 'extracting' => 'draft', 'pending_review' => 'submitted', 'pending_signature' => 'accepted', 'signed' => 'accepted',
        'activated' => 'approved', 'rejected' => 'rejected', 'expired' => 'expired',
    ];

    /** The papers Odoo keeps on an application: its field → the kind of paper. */
    private const DOCUMENTS = ['cr' => 'cr', 'vat' => 'vat', 'national_address' => 'national_address', 'owner_id' => 'owner_id', 'bank_letter' => 'bank_letter', 'chamber' => 'chamber'];

    private const STATEMENT_KIND = ['invoice' => 'invoice', 'payment' => 'payment', 'credit note' => 'credit_note'];

    /** A whole-mirror pass (plan / apply): the big models are written in batches, the small ones read once. */
    private bool $whole = false;

    /** The section's tables exist (a plan may run before the migration). */
    private bool $installed = true;

    /** @var array<string, true> big models a whole pass has already walked */
    private array $walked = [];

    /** @var array<string, int> what this pass counted (the parent keeps its own, privately) */
    private array $tally = [];

    /** @var array<string, array<int|string, string>> per parent: Odoo id → OPS id */
    private array $ids = [];

    /** @var array<string, array<int, true>> per parent: Odoo ids a plan decided to create */
    private array $planned = [];

    /** @var array<string, array<int|string, Model>> per entity: Odoo id → the OPS row (a whole pass reads the small tables once) */
    private array $rows = [];

    /** @var array<string, true> OPS customer ids */
    private array $customerIds = [];

    /** @var array<string, true> OPS product ids */
    private array $productIds = [];

    /** @var array<int, ?string> Odoo partner id → the OPS customer it (or its company) is */
    private array $customerOfPartner = [];

    /** @var array<int, ?int> Odoo partner id → its company (parent) */
    private array $parents = [];

    /** @var array<int, array{partner:mixed, branch:?int, request:?int}> Odoo sale order id → what the change log needs of it */
    private array $orders = [];

    /** @var array<int, ?int> Odoo journal entry id → the Odoo branch it names */
    private array $moveBranch = [];

    /** @var array<int, array<string, array{id:int, name:?string, mime:?string, size:?int}>>|null application Odoo id → its papers in the copy, by field */
    private ?array $attachments = null;

    /** @var array<int, string> Odoo company id → its currency code */
    private array $currencies = [];

    /** @var array<string, array<string, true>> parents whose children changed in this pass: lists | requests | statements → OPS ids */
    private array $touched = [];

    public function __construct(ExternalRefs $refs, private readonly AuditService $audit)
    {
        parent::__construct($refs);
    }

    public function key(): string
    {
        return 'portal';
    }

    /** Parents before children: a favourite points at its list, a line at its request or statement, a request at its branch. */
    public function models(): array
    {
        return [
            'portal.customer.branch', 'customer.portal.favorite.list', 'customer.portal.favorite', 'portal.onboarding.application',
            'hub.order.request', 'hub.order.request.line', 'hub.order.line.change', 'customer.statement', 'customer.statement.line',
        ];
    }

    public function plan(): array
    {
        return $this->pass(true, fn () => parent::plan());
    }

    public function apply(): array
    {
        return $this->pass(true, fn () => parent::apply());
    }

    public function consume(int $max = 500, float $seconds = 8.0): array
    {
        $this->relinkWhenLinksChanged();

        return $this->pass(false, fn () => parent::consume($max, $seconds));
    }

    private function pass(bool $whole, callable $work): array
    {
        $this->whole = $whole;
        $this->walked = $this->tally = $this->touched = [];
        try {
            $out = $work();
            if (! $this->dry) {
                $this->settle();
                $this->record();
            }

            return $out;
        } finally {
            $this->whole = false;
            $this->rows = $this->orders = $this->moveBranch = [];
            $this->attachments = null;
        }
    }

    protected function begin(): void
    {
        $this->installed = Schema::hasTable('portal_branches');
        if (! $this->installed && ! $this->dry) {
            throw AppError::rule('PORTAL_NOT_INSTALLED', 'جداول قسم بوابة العملاء لم تُنشأ بعد — شغّل الترحيل أولًا (php artisan migrate)', 'The tables of the customer portal section do not exist yet — run the migration first');
        }
        $this->planned = $this->rows = $this->customerOfPartner = $this->parents = $this->orders = $this->moveBranch = $this->currencies = [];
        $this->attachments = null;
        foreach (self::PARENT_CLASS as $entity => $class) {
            $this->ids[$entity] = $this->installed ? $class::where('source_system', self::SYSTEM)->pluck('id', 'external_ref')->all() : [];
        }
        $this->customerIds = array_fill_keys(Customer::query()->toBase()->pluck('id')->all(), true);
        $this->productIds = array_fill_keys(Product::query()->toBase()->pluck('id')->all(), true);
        if ($this->whole && $this->installed) {
            // the small tables are read once; the three big ones are read a batch at a time
            foreach (['branches' => PortalBranch::class, 'lists' => FavoriteList::class, 'applications' => Application::class, 'requests' => OrderRequest::class, 'statements' => Statement::class] as $entity => $class) {
                $this->rows[$entity] = $class::where('source_system', self::SYSTEM)->get()->keyBy('external_ref')->all();
            }
        }
    }

    protected function project(OdooRecord $r): void
    {
        if (isset(self::BULK[$r->model])) {
            if (! $this->whole) {
                $this->batch($r->model, [$r]);
            } elseif (! isset($this->walked[$r->model])) {
                // the first record of a big model: the whole model is done now, in batches; its other records pass by
                $this->walked[$r->model] = true;
                $this->bulk($r->model);
            }

            return;
        }
        match ($r->model) {
            'portal.customer.branch' => $this->branch($r),
            'customer.portal.favorite.list' => $this->favoriteList($r),
            'portal.onboarding.application' => $this->application($r),
            'hub.order.request' => $this->request($r),
            'hub.order.request.line' => $this->requestLine($r),
            'customer.statement' => $this->statement($r),
            default => null,
        };
    }

    protected function count(string $what, ?string $note = null): void
    {
        parent::count($what, $note);
        $this->tally[$what] = ($this->tally[$what] ?? 0) + 1;
    }

    // ───────────── branches ─────────────

    private function branch(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $gone = $r->deleted_at !== null;
        $row = $this->row('branches', PortalBranch::class, $r->odoo_id);
        if (! $row && $gone) {
            $this->count('branches.skippedDeleted');

            return;
        }
        $users = [];
        foreach (Val::ids($d['user_ids'] ?? []) as $userId) {
            $u = $this->mirrored('res.users', $userId);
            $users[] = ['ref' => (string) $userId, 'name' => self::cut(Val::text($u?->display_name) ?? "#{$userId}", 190), 'role' => Val::text($u?->data['hub_role'] ?? null, 40)];
        }
        $values = $this->customerOf($d['company_partner_id'] ?? null, 'branches') + [
            'name' => self::cut(Val::text($d['name'] ?? null) ?? Val::text($r->display_name) ?? "#{$r->odoo_id}", 190), 'code' => Val::text($d['code'] ?? null, 40),
            'sequence' => Val::int($d['sequence'] ?? 10), 'active' => $this->alive($r), 'portal_users' => $users ?: null,
            'extra' => array_filter(['warehouse' => Val::label($d['b2b_warehouse_id'] ?? null), 'deliveryAddress' => Val::label($d['delivery_partner_id'] ?? null)]) ?: null,
            'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $gone ? $r->deleted_at : null,
        ];
        $this->save('branches', PortalBranch::class, 'PortalBranch', $r, $row, $values, ['created_by' => Val::label($d['create_uid'] ?? null), 'created_at' => Val::at($d['create_date'] ?? null) ?? now()],
            fn (Model $b) => $b->name, isset($row) && $row->active && ! $values['active']);
    }

    // ───────────── favourite lists ─────────────

    private function favoriteList(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $gone = $r->deleted_at !== null;
        $row = $this->row('lists', FavoriteList::class, $r->odoo_id);
        if (! $row && $gone) {
            $this->count('lists.skippedDeleted');

            return;
        }
        $values = $this->customerOf($d['partner_id'] ?? null, 'lists') + [
            'name' => self::cut(Val::text($d['name'] ?? null) ?? Val::text($r->display_name) ?? "#{$r->odoo_id}", 190), 'sequence' => Val::int($d['sequence'] ?? 10),
            'active' => $this->alive($r), 'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $gone ? $r->deleted_at : null,
        ];
        // two thousand lists: their arrival is told in one audit line per pass (record()), not one each
        $this->save('lists', FavoriteList::class, null, $r, $row, $values, ['created_by' => Val::label($d['create_uid'] ?? null), 'created_at' => Val::at($d['create_date'] ?? null) ?? now(), 'items_count' => 0],
            fn (Model $l) => $l->name, isset($row) && $row->active && ! $values['active']);
    }

    // ───────────── onboarding applications ─────────────

    private function application(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $gone = $r->deleted_at !== null;
        /** @var Application|null $row */
        $row = $this->row('applications', Application::class, $r->odoo_id);
        if (! $row && $gone) {
            $this->count('applications.skippedDeleted');

            return;
        }
        $state = Val::text($d['state'] ?? null, 30) ?? 'draft';
        if (! isset(self::APPLICATION_STATE[$state])) {
            $this->count('applications.unknownState', "#{$ref}: {$state}");
        }
        $status = self::APPLICATION_STATE[$state] ?? 'draft';
        $partner = Val::id($d['created_partner_id'] ?? null);
        $values = [
            'status' => $status, 'source_state' => $state, 'language' => in_array($d['language'] ?? null, ['ar', 'en'], true) ? $d['language'] : 'ar',
            'company_name_ar' => Val::text($d['company_name_ar'] ?? null), 'company_name_en' => Val::text($d['company_name_en'] ?? null),
            'legal_entity_type' => in_array($d['legal_entity_type'] ?? null, Application::LEGAL_TYPES, true) ? $d['legal_entity_type'] : null,
            'cr' => self::digits($d['commercial_registration'] ?? null), 'vat_no' => self::digits($d['vat_number'] ?? null), 'unified_no' => self::digits($d['unified_number'] ?? null),
            'owner_name' => Val::text($d['owner_full_name'] ?? null, 190), 'owner_national_id' => self::digits($d['owner_national_id'] ?? null), 'manager_name' => Val::text($d['manager_name'] ?? null, 190),
            'email' => Val::text($d['email'] ?? null, 190), 'mobile' => Val::text($d['mobile'] ?? null, 40), 'street' => Val::text($d['street'] ?? null), 'building_no' => Val::text($d['building_no'] ?? null, 20),
            'additional_no' => Val::text($d['additional_no'] ?? null, 20), 'district' => Val::text($d['district'] ?? null, 120), 'city' => Val::text($d['city'] ?? null, 120),
            'region' => Val::text($d['region'] ?? null, 120), 'postal_code' => Val::text($d['postal_code'] ?? null, 20),
            'terms_accepted' => Val::bool($d['terms_accepted'] ?? false), 'terms_accepted_at' => Val::at($d['terms_accepted_at'] ?? null), 'terms_version' => self::cut(Val::label($d['terms_version_id'] ?? null), 120),
            'signed_at' => Val::at($d['signed_at'] ?? null), 'approved_at' => Val::at($d['activated_at'] ?? null), 'rejection_reason' => Val::text($d['rejection_reason'] ?? null, 4000),
            'customer_ref' => $partner ? (string) $partner : null, 'extra' => array_filter(['portalUser' => Val::label($d['created_user_id'] ?? null)]) ?: null,
            'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $gone ? $r->deleted_at : null,
        ];
        // the customer follows Odoo's partner — unless the application already names one (a person approved it here)
        $linked = $partner ? $this->customerId($partner) : null;
        if (! $row?->customer_id && $linked) {
            $values += ['customer_id' => $linked, 'customer_by' => 'link'];
        } elseif ($row?->customer_id && $linked && $linked !== $row->customer_id) {
            $this->count('applications.customerConflict', "{$row->number}: أودو يدل على عميل آخر غير المسجَّل على الطلب هنا");
        }
        if ($partner && ! $linked && ! $row?->customer_id) {
            $this->count('applications.customerPending');
        }

        if (! $row) {
            $this->count('applications.created');
            if (! $this->dry) {
                $row = DB::transaction(function () use ($r, $d, $ref, $values, $status) {
                    $created = Val::at($d['create_date'] ?? null) ?? now();
                    $number = self::cut(Val::text($d['name'] ?? null) ?? Val::text($r->display_name) ?? "ONB/{$ref}", 40);
                    if (Application::where('number', $number)->exists()) {
                        $number = self::cut($number, 30)."-od{$ref}"; // the number is somebody else's here: it stays recognisable and becomes unique
                    }
                    $a = Application::create($values + ['number' => $number, 'source_system' => self::SYSTEM, 'external_ref' => $ref, 'created_by' => Val::label($d['create_uid'] ?? null), 'created_at' => $created]);
                    PortalLog::add('application', $a->id, 'created', null, ['origin' => self::SYSTEM, 'at' => $created, 'username' => Val::label($d['create_uid'] ?? null) ?? self::SYSTEM]);
                    if ($status !== 'draft') {
                        PortalLog::add('application', $a->id, $status, null, ['origin' => self::SYSTEM, 'at' => $a->approved_at ?? $a->source_updated_at ?? $created,
                            'username' => Val::label($d['write_uid'] ?? null) ?? self::SYSTEM, 'data' => ['sourceState' => $a->source_state]]);
                    }
                    $this->audit->log($this->actor(), ['action' => 'PORTAL.APPLICATION.CREATE', 'entityType' => 'PortalApplication', 'entityId' => $a->id, 'entityNumber' => $a->number,
                        'newValue' => ['origin' => "from Odoo portal.onboarding.application #{$ref}", 'status' => $status]]);

                    return $a;
                });
                $this->rows['applications'][$r->odoo_id] = $row;
            }
        } else {
            $changes = Shape::changes($row, $values);
            if (! $changes) {
                $this->count('applications.unchanged');
            } else {
                $this->count('applications.updated');
                if (! $this->dry) {
                    DB::transaction(function () use ($row, $d, $changes) {
                        $from = $row->status;
                        $row->update($changes);
                        if (isset($changes['status'])) {
                            // what happened in Odoo is told on the application's timeline, at the time Odoo gives for it
                            PortalLog::add('application', $row->id, $row->status, null, ['origin' => self::SYSTEM, 'at' => $row->source_updated_at,
                                'username' => Val::label($d['write_uid'] ?? null) ?? self::SYSTEM, 'data' => ['from' => $from, 'sourceState' => $row->source_state]]);
                            $this->audit->status($this->actor(), 'PortalApplication', $row->id, $row->number, $from, $row->status, 'Odoo');
                        }
                        // a person's identity number never reaches the audit trail
                        $this->audit->log($this->actor(), ['action' => 'PORTAL.APPLICATION.UPDATE', 'entityType' => 'PortalApplication', 'entityId' => $row->id, 'entityNumber' => $row->number,
                            'newValue' => ['origin' => 'Odoo', 'fields' => array_keys($changes)]]);
                    });
                }
            }
        }
        if (! $gone) {
            $this->papers($row, $r->odoo_id, $d);
        }
    }

    /**
     * The papers Odoo holds for an application: each is listed on the OPS application (linked, never copied — the
     * content is read from the Odoo file archive once it is kept there); one that is gone from Odoo is taken off.
     */
    private function papers(?Application $application, int $odooId, array $d): void
    {
        $have = $application ? ApplicationDocument::where('application_id', $application->id)->where('source_system', self::SYSTEM)->get()->keyBy('kind') : collect();
        $attached = $this->attachmentsOf($odooId);
        $changed = false;
        foreach (self::DOCUMENTS as $field => $kind) {
            $file = $attached["doc_{$field}"] ?? null;
            $name = Val::text($d["filename_{$field}"] ?? null) ?? $file['name'] ?? null;
            /** @var ApplicationDocument|null $doc */
            $doc = $have->get($kind);
            if ($name === null && ! $file) {
                if ($doc && $doc->removed_at === null) {
                    $this->count('documents.removed');
                    if (! $this->dry) {
                        $doc->forceFill(['removed_at' => now(), 'removed_by' => 'svc.odoo', 'remove_reason' => 'أُزيل من أودو'])->save();
                        $changed = true;
                    }
                }

                continue;
            }
            $values = [
                'file_name' => self::cut($name ?? "{$kind}", 250), 'mime' => self::cut($file['mime'] ?? null, 150), 'size' => $file['size'] ?? null, 'status' => ApplicationDocument::ODOO,
                // the attachment's id when the copy lists it; until then a key of its own, so the paper is still on the application
                'external_ref' => $file ? (string) $file['id'] : "{$odooId}:{$kind}", 'removed_at' => null, 'removed_by' => null, 'remove_reason' => null,
            ];
            if (! $doc) {
                $this->count('documents.created');
                if (! $this->dry && $application) {
                    $doc = new ApplicationDocument;
                    $doc->forceFill($values + ['application_id' => $application->id, 'kind' => $kind, 'source_system' => self::SYSTEM, 'uploaded_by' => 'svc.odoo'])->save();
                    $changed = true;
                }

                continue;
            }
            $diff = Shape::changes($doc, $values);
            $this->count($diff ? 'documents.updated' : 'documents.unchanged');
            if ($diff && ! $this->dry) {
                $doc->forceFill($diff)->save();
                $changed = true;
            }
        }
        if ($changed && $application) {
            $n = ApplicationDocument::where('application_id', $application->id)->whereNull('removed_at')->count();
            if ($application->documents_count !== $n) {
                $application->update(['documents_count' => $n]);
            }
        }
    }

    /** @return array<string, array{id:int, name:?string, mime:?string, size:?int}> the papers of one application in the copy, by Odoo field */
    private function attachmentsOf(int $applicationId): array
    {
        $read = function (?int $only): array {
            $out = [];
            $query = OdooRecord::where('model', 'ir.attachment')->where('data->res_model', 'portal.onboarding.application')->whereNull('deleted_at')
                ->when($only, fn ($q) => $q->where('data->res_id', (string) $only))->orderBy('odoo_id');
            foreach ($query->get(['odoo_id', 'data']) as $a) {
                $field = Val::text($a->data['res_field'] ?? null, 60);
                $app = Val::id($a->data['res_id'] ?? null);
                if ($field && $app) {
                    $out[$app][$field] = ['id' => $a->odoo_id, 'name' => Val::text($a->data['name'] ?? null), 'mime' => Val::text($a->data['mimetype'] ?? null, 150),
                        'size' => Val::int($a->data['file_size'] ?? 0) ?: null];
                }
            }

            return $out;
        };
        if (! $this->whole) {
            return $read($applicationId)[$applicationId] ?? []; // the feed: one application, one look
        }
        $this->attachments ??= $read(null); // a whole pass: one pass over the list of attachments

        return $this->attachments[$applicationId] ?? [];
    }

    // ───────────── order requests ─────────────

    private function request(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $gone = $r->deleted_at !== null;
        /** @var OrderRequest|null $row */
        $row = $this->row('requests', OrderRequest::class, $r->odoo_id);
        if (! $row && $gone) {
            $this->count('requests.skippedDeleted');

            return;
        }
        $status = in_array($d['state'] ?? null, OrderRequest::STATUSES, true) ? $d['state'] : 'pending';
        $order = Val::id($d['sale_order_id'] ?? null);
        $hours = Val::float($d['approval_duration_hours'] ?? 0);
        $currency = Val::label($d['currency_id'] ?? null);
        $values = $this->customerOf($d['company_partner_id'] ?? null, 'requests') + [
            'status' => $status, 'branch_id' => $this->parentId('branches', Val::id($d['branch_id'] ?? null)),
            'requested_by' => self::cut(Val::label($d['requested_by_id'] ?? null), 190), 'request_date' => Val::at($d['request_date'] ?? null) ?? Val::at($d['create_date'] ?? null),
            'decided_by' => self::cut(Val::label($d['approved_by_id'] ?? null), 190), 'decided_at' => Val::at($d['approval_date'] ?? null), 'decision_hours' => $hours > 0 ? round($hours, 2) : null,
            'amount_total' => round(Val::float($d['amount_total'] ?? 0), 2), 'currency' => $currency !== null && preg_match('/^[A-Za-z]{3}$/', $currency) ? strtoupper($currency) : 'SAR',
            'price_list' => self::cut(Val::label($d['pricelist_id'] ?? null), 190), 'note' => Val::text($d['note'] ?? null, 4000), 'rejection_reason' => Val::text($d['rejection_reason'] ?? null, 4000),
            'order_ref' => $order ? (string) $order : null, 'order_number' => self::cut(Val::label($d['sale_order_id'] ?? null), 60),
            'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $gone ? $r->deleted_at : null,
        ];
        $number = self::cut(Val::text($d['name'] ?? null) ?? Val::text($r->display_name) ?? "REQ/{$ref}", 40);
        if (! $row && ! $this->dry && OrderRequest::where('number', $number)->exists()) {
            $number = self::cut($number, 30)."-od{$ref}";
        }
        $from = $row?->status;
        $saved = $this->save('requests', OrderRequest::class, 'PortalOrderRequest', $r, $row, $values,
            ['number' => $number, 'created_by' => Val::label($d['create_uid'] ?? null), 'created_at' => Val::at($d['create_date'] ?? null) ?? now()], fn (Model $x) => $x->number);
        if ($saved && ! $this->dry) {
            // the request's own story, at the times Odoo gives for it
            if (! $row) {
                PortalLog::add('request', $saved->id, 'created', null, ['origin' => self::SYSTEM, 'at' => $saved->request_date, 'username' => $saved->requested_by ?? self::SYSTEM]);
            }
            if ($saved->status !== 'pending' && $saved->status !== $from) {
                PortalLog::add('request', $saved->id, $saved->status, null, ['origin' => self::SYSTEM, 'at' => $saved->decided_at ?? $saved->source_updated_at, 'username' => $saved->decided_by ?? self::SYSTEM,
                    'note' => $saved->status === 'rejected' ? $saved->rejection_reason : null, 'data' => array_filter(['order' => $saved->order_number])]);
            }
        }
    }

    private function requestLine(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $gone = $r->deleted_at !== null;
        $row = $this->installed ? OrderRequestLine::where('source_system', self::SYSTEM)->where('external_ref', $ref)->first() : null;
        if ($gone) {
            // the request was changed in Odoo before it was decided: the line is no longer part of it
            $this->count($row ? 'requestLines.removed' : 'requestLines.skippedDeleted');
            if ($row && ! $this->dry) {
                $this->touched['requests'][$row->request_id] = true;
                $row->delete();
            }

            return;
        }
        $requestId = $this->parentId('requests', Val::id($d['request_id'] ?? null));
        if (! $requestId) {
            $this->count('requestLines.skippedNoRequest', "#{$ref}");

            return;
        }
        $values = $this->productOf($d['product_id'] ?? null, 'requestLines') + [
            'request_id' => $requestId, 'description' => Val::text($d['name'] ?? null, 500), 'qty' => round(Val::float($d['product_uom_qty'] ?? 0), 3),
            'price' => round(Val::float($d['price_unit'] ?? 0), 2), 'subtotal' => round(Val::float($d['price_subtotal'] ?? 0), 2),
        ];
        if (! $row) {
            $this->count('requestLines.created');
            if (! $this->dry) {
                OrderRequestLine::create($values + ['line_no' => 0, 'source_system' => self::SYSTEM, 'external_ref' => $ref]);
                $this->touched['requests'][$requestId] = true;
            }

            return;
        }
        $changes = Shape::changes($row, $values);
        $this->count($changes ? 'requestLines.updated' : 'requestLines.unchanged');
        if ($changes && ! $this->dry) {
            $this->touched['requests'][$row->request_id] = $this->touched['requests'][$requestId] = true;
            $row->update($changes);
        }
    }

    // ───────────── statements ─────────────

    private function statement(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $gone = $r->deleted_at !== null;
        $row = $this->row('statements', Statement::class, $r->odoo_id);
        if (! $row && $gone) {
            $this->count('statements.skippedDeleted');

            return;
        }
        $from = Val::date($d['date_from'] ?? null);
        $to = Val::date($d['date_to'] ?? null);
        if (! $from || ! $to) {
            $this->count('statements.skippedNoPeriod', "#{$ref}");

            return;
        }
        $issued = ($d['state'] ?? null) !== 'draft';
        // money keeps Odoo's own figures and currency: nothing is recomputed
        $values = $this->customerOf($d['partner_id'] ?? null, 'statements') + [
            'status' => $issued ? 'issued' : 'draft', 'date_from' => $from, 'date_to' => $to, 'opening_balance' => round(Val::float($d['opening_balance'] ?? 0), 2),
            'total_debit' => round(Val::float($d['total_debit'] ?? 0), 2), 'total_credit' => round(Val::float($d['total_credit'] ?? 0), 2), 'closing_balance' => round(Val::float($d['closing_balance'] ?? 0), 2),
            'currency' => $this->currencyOf(Val::id($d['company_id'] ?? null)), 'issued_at' => $issued ? Val::at($d['create_date'] ?? null) : null, 'issued_by' => $issued ? Val::label($d['create_uid'] ?? null) : null,
            'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $gone ? $r->deleted_at : null,
        ];
        $number = self::cut(Val::text($d['name'] ?? null) ?? Val::text($r->display_name) ?? "STMT/{$ref}", 40);
        if (! $row && ! $this->dry && Statement::where('number', $number)->exists()) {
            $number = self::cut($number, 30)."-od{$ref}";
        }
        $this->save('statements', Statement::class, 'PortalStatement', $r, $row, $values,
            ['number' => $number, 'created_by' => Val::label($d['create_uid'] ?? null), 'created_at' => Val::at($d['create_date'] ?? null) ?? now(), 'lines_count' => 0], fn (Model $x) => $x->number);
    }

    // ───────────── one record of a small table ─────────────

    /** The OPS row an Odoo record became: from what the pass read once, or looked up (the feed). */
    private function row(string $entity, string $class, int $odooId): ?Model
    {
        if (! $this->installed) {
            return null;
        }
        if ($this->whole) {
            return $this->rows[$entity][$odooId] ?? null;
        }

        return $this->rows[$entity][$odooId] ??= $class::where('source_system', self::SYSTEM)->where('external_ref', (string) $odooId)->first();
    }

    /**
     * Creates the row, or writes the values Odoo owns that differ. Counts created · updated · archived · unchanged
     * under the entity's name.
     *
     * @param  array<string, mixed>  $values  the columns Odoo owns, as they should be now
     * @param  array<string, mixed>  $once  what only a new row gets (its number, who made it in Odoo and when)
     * @param  string|null  $auditType  the entity type of its audit lines (null = told once per pass, see record())
     * @param  callable(Model): ?string  $label  what people call the row
     * @return Model|null the row (null in a plan that would create it)
     */
    private function save(string $entity, string $class, ?string $auditType, OdooRecord $r, ?Model $row, array $values, array $once, callable $label, bool $archived = false): ?Model
    {
        if (! $row) {
            $this->count("{$entity}.created");
            if ($this->dry) {
                if (isset(self::PARENT_MODEL[$entity])) {
                    $this->planned[$entity][$r->odoo_id] = true;
                }

                return null;
            }
            $row = DB::transaction(function () use ($class, $auditType, $entity, $r, $values, $once, $label) {
                $row = $class::create($values + $once + ['source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id]);
                if ($auditType) {
                    $this->audit->log($this->actor(), ['action' => 'PORTAL.'.self::AUDIT_WORD[$entity].'.CREATE', 'entityType' => $auditType, 'entityId' => $row->getKey(), 'entityNumber' => $label($row),
                        'newValue' => ['origin' => "from Odoo {$r->model} #{$r->odoo_id}"]]);
                }

                return $row;
            });
            $this->rows[$entity][$r->odoo_id] = $row;
            if (isset(self::PARENT_MODEL[$entity])) {
                $this->ids[$entity][$r->odoo_id] = (string) $row->getKey();
            }

            return $row;
        }
        $changes = Shape::changes($row, $values);
        if (! $changes) {
            $this->count("{$entity}.unchanged");

            return $row;
        }
        $this->count($entity.($archived ? '.archived' : '.updated'));
        if ($this->dry) {
            return $row;
        }
        DB::transaction(function () use ($auditType, $entity, $row, $changes, $label) {
            $old = [];
            foreach (array_keys($changes) as $column) {
                $current = $row->getAttribute($column);
                $old[$column] = $current instanceof DateTimeInterface ? $current->format('Y-m-d H:i:s') : $current;
            }
            $row->update($changes);
            if ($auditType) {
                $this->audit->log($this->actor(), ['action' => 'PORTAL.'.self::AUDIT_WORD[$entity].'.UPDATE', 'entityType' => $auditType, 'entityId' => $row->getKey(), 'entityNumber' => $label($row),
                    'oldValue' => $old, 'newValue' => array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, $changes) + ['origin' => 'Odoo']]);
            }
        });

        return $row;
    }

    // ───────────── the three big models ─────────────

    /** A whole model of the copy, 500 records at a time. */
    private function bulk(string $model): void
    {
        $entity = self::BULK[$model];
        $failed = 0;
        $chunk = [];
        $flush = function () use (&$chunk, &$failed, $model, $entity) {
            if (! $chunk) {
                return;
            }
            try {
                $this->batch($model, $chunk);
            } catch (Throwable $e) {
                // the batch could not be written (nothing of it was, nothing of it was counted): record by record, so only the record at fault is left out
                foreach ($chunk as $r) {
                    try {
                        $this->batch($model, [$r]);
                    } catch (Throwable $one) {
                        report($one);
                        $this->count("{$entity}.failed", "{$model} #{$r->odoo_id}: ".mb_substr($one->getMessage(), 0, 160));
                        if (++$failed > 40) {
                            throw $one; // then the fault is not the record's
                        }
                    }
                }
            }
            $chunk = [];
        };
        foreach (OdooRecord::where('model', $model)->lazyById(self::CHUNK, 'odoo_id') as $r) {
            $chunk[] = $r;
            if (count($chunk) >= self::CHUNK) {
                $flush();
            }
        }
        $flush();
    }

    /**
     * Applies a batch of records of one big model: one read of the rows OPS already holds for them, then — in one
     * transaction — one insert of the new rows, one update per row that changed and one delete of the rows that are
     * gone. Nothing is counted before the batch is written, so a batch that fails can be done again without counting
     * twice.
     *
     * @param  OdooRecord[]  $records
     */
    private function batch(string $model, array $records): void
    {
        [$table, $entity, $columns] = match ($model) {
            'customer.portal.favorite' => ['portal_favorite_items', 'favorites', ['list_id', 'product_id', 'product_ref', 'product_name', 'qty', 'sequence']],
            'hub.order.line.change' => ['portal_line_changes', 'lineChanges', ['changed_at', 'change_type', 'field', 'field_label', 'old_value', 'new_value', 'changed_by', 'request_id', 'order_ref', 'order_number',
                'customer_id', 'customer_ref', 'customer_name', 'branch_id', 'product_id', 'product_ref', 'product_name']],
            'customer.statement.line' => ['portal_statement_lines', 'statementLines', ['statement_id', 'date', 'seq', 'kind', 'reference', 'description', 'debit', 'credit', 'balance', 'branch_id', 'doc_type', 'doc_id']],
        };
        $have = [];
        if ($this->installed) {
            $refs = array_map(fn (OdooRecord $r) => (string) $r->odoo_id, $records);
            foreach (DB::table($table)->where('source_system', self::SYSTEM)->whereIn('external_ref', $refs)->get(array_merge(['id', 'external_ref'], $columns)) as $row) {
                $have[$row->external_ref] = $row;
            }
        }
        $this->prime($model, $records);
        $now = now()->format('Y-m-d H:i:s.v');
        // the parent whose count of children a write changes (the change log has none)
        $parentColumn = ['favorites' => 'list_id', 'statementLines' => 'statement_id'][$entity] ?? null;
        $insert = $update = $delete = $outcomes = $touched = [];
        foreach ($records as $r) {
            $ref = (string) $r->odoo_id;
            $row = $have[$ref] ?? null;
            if ($r->deleted_at !== null) {
                if (! $row) {
                    $outcomes[] = ["{$entity}.skippedDeleted", null];
                } elseif ($entity === 'lineChanges') {
                    $outcomes[] = ["{$entity}.kept", null]; // the log is history: what it recorded did happen
                } else {
                    $delete[] = $row->id;
                    $outcomes[] = ["{$entity}.removed", null];
                    $touched[(string) $row->{$parentColumn}] = true;
                }

                continue;
            }
            $found = match ($entity) {
                'favorites' => $this->favoriteValues($r),
                'lineChanges' => $this->changeValues($r),
                'statementLines' => $this->statementLineValues($r),
            };
            if (is_string($found)) {
                $outcomes[] = ["{$entity}.{$found}", "#{$ref}"]; // it points at a parent the copy does not hold

                continue;
            }
            [$values, $notes] = $found;
            foreach ($notes as $note) {
                $outcomes[] = ["{$entity}.{$note}", null];
            }
            if (! $row) {
                $insert[] = self::stored($values) + ['id' => (string) Str::ulid(), 'source_system' => self::SYSTEM, 'external_ref' => $ref, 'created_at' => $now]
                    + ($entity === 'favorites' ? ['created_by' => 'svc.odoo', 'updated_at' => $now] : []);
                $outcomes[] = ["{$entity}.created", null];
                if ($parentColumn) {
                    $touched[(string) $values[$parentColumn]] = true;
                }

                continue;
            }
            $changes = [];
            foreach ($values as $column => $value) {
                if (! Shape::same($row->{$column}, $value)) {
                    $changes[$column] = $value;
                }
            }
            if (! $changes) {
                $outcomes[] = ["{$entity}.unchanged", null];

                continue;
            }
            $update[$row->id] = self::stored($changes) + ($entity === 'favorites' ? ['updated_at' => $now] : []);
            $outcomes[] = ["{$entity}.updated", null];
            if ($parentColumn) {
                // moved to another parent: both counts change
                $touched[(string) $values[$parentColumn]] = $touched[(string) $row->{$parentColumn}] = true;
            }
        }
        if (! $this->dry && ($insert || $update || $delete)) {
            DB::transaction(function () use ($table, $insert, $update, $delete) {
                if ($insert) {
                    DB::table($table)->insert($insert);
                }
                foreach ($update as $id => $changes) {
                    DB::table($table)->where('id', $id)->update($changes);
                }
                if ($delete) {
                    DB::table($table)->whereIn('id', $delete)->delete();
                }
            });
            $parentOf = ['favorites' => 'lists', 'statementLines' => 'statements'][$entity] ?? null;
            foreach ($parentOf ? array_keys($touched) : [] as $id) {
                $this->touched[$parentOf][(string) $id] = true;
            }
        }
        foreach ($outcomes as [$what, $note]) {
            $this->count($what, $note);
        }
    }

    /** What a batch looks up again and again, read once for the batch. */
    private function prime(string $model, array $records): void
    {
        if ($model === 'hub.order.line.change') {
            $ids = [];
            foreach ($records as $r) {
                if (($id = Val::id($r->data['order_id'] ?? null)) && ! array_key_exists($id, $this->orders)) {
                    $ids[$id] = true;
                }
            }
            foreach (array_chunk(array_keys($ids), self::CHUNK) as $chunk) {
                foreach (DB::table('odoo_records')->where('model', 'sale.order')->whereIn('odoo_id', $chunk)->get(['odoo_id', 'data->partner_id as partner', 'data->branch_id as branch', 'data->hub_request_id as request']) as $o) {
                    $this->orders[(int) $o->odoo_id] = ['partner' => self::json($o->partner), 'branch' => Val::id(self::json($o->branch)), 'request' => Val::id(self::json($o->request))];
                }
                foreach ($chunk as $id) {
                    $this->orders[$id] ??= ['partner' => null, 'branch' => null, 'request' => null]; // the copy does not hold the order
                }
            }
        }
        if ($model === 'customer.statement.line') {
            $ids = [];
            foreach ($records as $r) {
                if (($id = Val::id($r->data['move_id'] ?? null)) && ! array_key_exists($id, $this->moveBranch)) {
                    $ids[$id] = true;
                }
            }
            foreach (array_chunk(array_keys($ids), self::CHUNK) as $chunk) {
                foreach (DB::table('odoo_records')->where('model', 'account.move')->whereIn('odoo_id', $chunk)->get(['odoo_id', 'data->branch_id as branch']) as $m) {
                    $this->moveBranch[(int) $m->odoo_id] = Val::id(self::json($m->branch));
                }
                foreach ($chunk as $id) {
                    $this->moveBranch[$id] ??= null;
                }
            }
            if (! $this->whole) {
                $this->moveBranch = array_slice($this->moveBranch, -5000, null, true);
            }
        }
    }

    /** @return array{0:array<string, mixed>, 1:string[]}|string the columns of a favourite and what to note about it — or why it is left out */
    private function favoriteValues(OdooRecord $r): array|string
    {
        $d = (array) $r->data;
        $listId = $this->parentId('lists', Val::id($d['list_id'] ?? null));
        if (! $listId) {
            return 'skippedNoList';
        }
        $product = $this->productOf($d['product_id'] ?? null);
        $qty = round(Val::float($d['default_quantity'] ?? 1), 3);

        return [['list_id' => $listId] + $product + ['qty' => $qty > 0 ? $qty : 1.0, 'sequence' => Val::int($d['sequence'] ?? 10)], $product['product_id'] === null && $product['product_ref'] !== null ? ['productPending'] : []];
    }

    /** @return array{0:array<string, mixed>, 1:string[]} */
    private function changeValues(OdooRecord $r): array
    {
        $d = (array) $r->data;
        $orderId = Val::id($d['order_id'] ?? null);
        $order = $orderId ? ($this->orders[$orderId] ?? ['partner' => null, 'branch' => null, 'request' => null]) : ['partner' => null, 'branch' => null, 'request' => null];
        $label = Val::text($d['field_label'] ?? null, 60);
        $type = Val::text($d['change_type'] ?? null, 10) ?? 'modified';
        $product = $this->productOf($d['product_id'] ?? null);
        $customer = $this->customerOf($order['partner']);
        $at = Val::at($d['change_date'] ?? null) ?? Val::at($d['create_date'] ?? null) ?? now();

        return [[
            'changed_at' => $at, 'change_type' => $type, 'field' => ['quantity' => 'qty', 'unit price' => 'price'][mb_strtolower((string) $label)] ?? null, 'field_label' => $label,
            'old_value' => self::scalar($d['old_value'] ?? null), 'new_value' => self::scalar($d['new_value'] ?? null), 'changed_by' => self::cut(Val::label($d['changed_by_id'] ?? null), 190),
            'request_id' => $this->parentId('requests', $order['request']), 'order_ref' => $orderId ? (string) $orderId : null, 'order_number' => self::cut(Val::label($d['order_id'] ?? null), 60),
        ] + $customer + [
            'branch_id' => $this->parentId('branches', $order['branch']),
            // the name is part of the log: what the product was called when the line was changed
            'product_id' => $product['product_id'], 'product_ref' => $product['product_ref'], 'product_name' => Val::text($d['product_name'] ?? null) ?? $product['product_name'] ?? Val::label($d['product_id'] ?? null),
        ], array_merge($customer['customer_id'] === null && $customer['customer_ref'] !== null ? ['customerPending'] : [], $product['product_id'] === null && $product['product_ref'] !== null ? ['productPending'] : [])];
    }

    /** @return array{0:array<string, mixed>, 1:string[]}|string */
    private function statementLineValues(OdooRecord $r): array|string
    {
        $d = (array) $r->data;
        $statementId = $this->parentId('statements', Val::id($d['statement_id'] ?? null));
        $date = Val::date($d['date'] ?? null);
        if (! $statementId || ! $date) {
            return 'skippedNoStatement';
        }
        $move = Val::id($d['move_id'] ?? null);
        $type = Val::text($d['transaction_type'] ?? null, 40);

        // money keeps Odoo's own figures: debit, credit and the running balance are Odoo's
        return [[
            'statement_id' => $statementId, 'date' => $date, 'seq' => $r->odoo_id, 'kind' => self::STATEMENT_KIND[mb_strtolower((string) $type)] ?? 'other',
            'reference' => Val::text($d['reference'] ?? null, 80), 'description' => Val::text($d['name'] ?? null, 500) ?? ($type && ! isset(self::STATEMENT_KIND[mb_strtolower($type)]) ? $type : null),
            'debit' => round(Val::float($d['debit'] ?? 0), 2), 'credit' => round(Val::float($d['credit'] ?? 0), 2), 'balance' => round(Val::float($d['balance'] ?? 0), 2),
            'branch_id' => $move ? $this->parentId('branches', $this->moveBranch[$move] ?? null) : null, 'doc_type' => $move ? 'account.move' : null, 'doc_id' => $move ? (string) $move : null,
        ], []];
    }

    // ───────────── after a pass ─────────────

    /** Keeps what a parent says about its children true: a list's number of items, a request's lines and their numbers, a statement's number of lines. */
    private function settle(): void
    {
        foreach (array_chunk(array_keys($this->touched['lists'] ?? []), self::CHUNK) as $ids) {
            $this->recount('portal_favorite_lists', 'items_count', 'portal_favorite_items', 'list_id', $ids);
        }
        foreach (array_chunk(array_keys($this->touched['statements'] ?? []), self::CHUNK) as $ids) {
            $this->recount('portal_statements', 'lines_count', 'portal_statement_lines', 'statement_id', $ids);
        }
        foreach (array_keys($this->touched['requests'] ?? []) as $requestId) {
            // Odoo keeps no line numbers: the lines are numbered in the order Odoo made them
            $lines = OrderRequestLine::where('request_id', $requestId)->get(['id', 'line_no', 'external_ref'])->sortBy(fn (OrderRequestLine $l) => [(int) $l->external_ref, $l->id])->values();
            foreach ($lines as $i => $line) {
                if ($line->line_no !== $i + 1) {
                    OrderRequestLine::whereKey($line->id)->update(['line_no' => $i + 1]);
                }
            }
            OrderRequest::whereKey($requestId)->where('lines_count', '!=', $lines->count())->update(['lines_count' => $lines->count()]);
        }
        $this->touched = [];
    }

    /** @param  string[]  $ids */
    private function recount(string $parent, string $column, string $child, string $key, array $ids): void
    {
        $counts = DB::table($child)->whereIn($key, $ids)->selectRaw("{$key} AS k, COUNT(*) AS n")->groupBy($key)->pluck('n', 'k');
        foreach (DB::table($parent)->whereIn('id', $ids)->get(['id', $column]) as $row) {
            $n = (int) ($counts[$row->id] ?? 0);
            if ((int) $row->{$column} !== $n) {
                DB::table($parent)->where('id', $row->id)->update([$column => $n]);
            }
        }
    }

    /** One audit line for what a pass wrote in bulk (the lists and the three big models are not audited row by row). */
    private function record(): void
    {
        $written = [];
        foreach ($this->tally as $what => $n) {
            [$entity, $outcome] = array_pad(explode('.', $what, 2), 2, '');
            if (in_array($entity, ['lists', 'favorites', 'lineChanges', 'statementLines', 'requestLines', 'documents'], true) && in_array($outcome, ['created', 'updated', 'archived', 'removed'], true)) {
                $written[$what] = $n;
            }
        }
        if ($written) {
            $this->audit->log($this->actor(), ['action' => 'PORTAL.IMPORT', 'entityType' => 'OdooProjection', 'entityId' => $this->key(), 'entityNumber' => $this->whole ? 'apply' : 'feed', 'newValue' => $written + ['origin' => 'Odoo']]);
        }
    }

    // ───────────── links that appear later ─────────────

    /**
     * Fills the OPS customer / product of the records that came from Odoo before their partner or product was linked.
     * Set-based and cheap: per table one read of the distinct unlinked ids, then one update per id that now has a link.
     *
     * @return array<string, int> table → rows that got their link
     */
    public function relink(): array
    {
        if (! Schema::hasTable('portal_branches')) {
            return [];
        }
        $links = fn (string $entity) => IntExternalRef::where('system', self::SYSTEM)->where('entity', $entity)->pluck('internal_id', 'external_id')->all();
        $out = [];

        // customers: the partner itself, or the company it is a contact of
        $customerLinks = $links('customer');
        $customers = array_fill_keys(Customer::query()->toBase()->pluck('id')->all(), true);
        $tables = ['portal_branches', 'portal_favorite_lists', 'portal_applications', 'portal_order_requests', 'portal_line_changes', 'portal_statements'];
        $waiting = [];
        foreach ($tables as $table) {
            $waiting[$table] = DB::table($table)->where('source_system', self::SYSTEM)->whereNull('customer_id')->whereNotNull('customer_ref')->distinct()->pluck('customer_ref')->all();
        }
        $refs = array_values(array_unique(array_merge(...array_values($waiting))));
        $parents = [];
        $orphans = array_values(array_filter($refs, fn ($ref) => ctype_digit((string) $ref) && ! isset($customerLinks[$ref])));
        foreach (array_chunk($orphans, self::CHUNK) as $chunk) {
            foreach (DB::table('odoo_records')->where('model', 'res.partner')->whereIn('odoo_id', array_map('intval', $chunk))->get(['odoo_id', 'data->parent_id as parent']) as $p) {
                $parents[(string) $p->odoo_id] = Val::id(self::json($p->parent));
            }
        }
        foreach ($tables as $table) {
            foreach ($waiting[$table] as $ref) {
                $id = $customerLinks[$ref] ?? (($parent = $parents[(string) $ref] ?? null) ? ($customerLinks[$parent] ?? null) : null);
                if (! $id || ! isset($customers[$id])) {
                    continue;
                }
                $n = DB::table($table)->where('source_system', self::SYSTEM)->where('customer_ref', $ref)->whereNull('customer_id')
                    ->update(['customer_id' => $id] + ($table === 'portal_applications' ? ['customer_by' => 'link'] : []));
                if ($n) {
                    $out[$table] = ($out[$table] ?? 0) + $n;
                }
            }
        }

        // products
        $productLinks = $links('product');
        $products = array_fill_keys(Product::query()->toBase()->pluck('id')->all(), true);
        foreach (['portal_favorite_items', 'portal_order_request_lines', 'portal_line_changes'] as $table) {
            foreach (DB::table($table)->where('source_system', self::SYSTEM)->whereNull('product_id')->whereNotNull('product_ref')->distinct()->pluck('product_ref')->all() as $ref) {
                $id = $productLinks[$ref] ?? null;
                if (! $id || ! isset($products[$id])) {
                    continue;
                }
                // the change log keeps the name the product had then; elsewhere the OPS product carries the name from now on
                $n = DB::table($table)->where('source_system', self::SYSTEM)->where('product_ref', $ref)->whereNull('product_id')
                    ->update(['product_id' => $id] + ($table === 'portal_line_changes' ? [] : ['product_name' => null]));
                if ($n) {
                    $out[$table] = ($out[$table] ?? 0) + $n;
                }
            }
        }
        if ($out) {
            $this->audit->log($this->actor(), ['action' => 'PORTAL.RELINK', 'entityType' => 'OdooProjection', 'entityId' => $this->key(), 'newValue' => $out]);
        }

        return $out;
    }

    /** relink() only when the identity links of customers or products changed since the last look (one small query per run). */
    private function relinkWhenLinksChanged(): void
    {
        try {
            $now = IntExternalRef::where('system', self::SYSTEM)->whereIn('entity', ['customer', 'product'])->selectRaw('COUNT(*) AS n, MAX(updated_at) AS latest')->toBase()->first();
            $mark = ($now->n ?? 0).'|'.($now->latest ?? '');
            if (Cache::get('portal:relink:mark') === $mark) {
                return;
            }
            $this->relink();
            Cache::forever('portal:relink:mark', $mark);
        } catch (Throwable $e) {
            report($e); // the links wait for the next run; the feed is still applied
        }
    }

    // ───────────── customers, products, parents ─────────────

    /**
     * The customer of a record: the OPS customer its Odoo partner (or that partner's company) is linked to, and always
     * the partner's Odoo id and name. `$count`: the entity under which an unlinked one is counted (`customerPending`).
     *
     * @return array{customer_id:?string, customer_ref:?string, customer_name:?string}
     */
    private function customerOf(mixed $many2one, ?string $count = null): array
    {
        $partner = Val::id($many2one);
        if (! $partner) {
            return ['customer_id' => null, 'customer_ref' => null, 'customer_name' => null];
        }
        $id = $this->customerId($partner);
        if (! $id && $count) {
            $this->count("{$count}.customerPending");
        }

        return ['customer_id' => $id, 'customer_ref' => (string) $partner, 'customer_name' => self::cut(Val::label($many2one) ?? "#{$partner}", 250)];
    }

    private function customerId(int $partner): ?string
    {
        if (array_key_exists($partner, $this->customerOfPartner)) {
            return $this->customerOfPartner[$partner];
        }
        $id = $this->linked('customer', $partner);
        if (! $id) {
            if (! array_key_exists($partner, $this->parents)) {
                $this->parents[$partner] = Val::id(self::json(DB::table('odoo_records')->where('model', 'res.partner')->where('odoo_id', $partner)->value('data->parent_id')));
            }
            $id = $this->parents[$partner] ? $this->linked('customer', $this->parents[$partner]) : null;
        }

        // a link to a customer that is no longer there names nobody
        return $this->customerOfPartner[$partner] = $id && isset($this->customerIds[$id]) ? (string) $id : null;
    }

    /**
     * The product of a record: the OPS product its Odoo product is linked to, and always the Odoo id; the Odoo name is
     * kept only while there is no link (the OPS product carries the name afterwards).
     *
     * @return array{product_id:?string, product_ref:?string, product_name:?string}
     */
    private function productOf(mixed $many2one, ?string $count = null): array
    {
        $product = Val::id($many2one);
        if (! $product) {
            return ['product_id' => null, 'product_ref' => null, 'product_name' => null];
        }
        $id = $this->linked('product', $product);
        $id = $id && isset($this->productIds[$id]) ? (string) $id : null;
        if (! $id && $count) {
            $this->count("{$count}.productPending");
        }

        return ['product_id' => $id, 'product_ref' => (string) $product, 'product_name' => $id ? null : self::cut(Val::label($many2one) ?? "#{$product}", 250)];
    }

    /**
     * The OPS id of an Odoo branch / list / request / statement. One the pass has not met yet (a change of the feed
     * that names a brand-new parent) is made from the copy on the spot. In a plan: the id, or a marker for "will be made".
     */
    private function parentId(string $entity, ?int $odooId): ?string
    {
        if (! $odooId) {
            return null;
        }
        if (isset($this->ids[$entity][$odooId])) {
            return (string) $this->ids[$entity][$odooId];
        }
        if (isset($this->planned[$entity][$odooId])) {
            return "new:{$entity}:{$odooId}";
        }
        if (! $this->whole && ($r = $this->mirrored(self::PARENT_MODEL[$entity], $odooId))) {
            $this->project($r);
        }

        return isset($this->ids[$entity][$odooId]) ? (string) $this->ids[$entity][$odooId] : (isset($this->planned[$entity][$odooId]) ? "new:{$entity}:{$odooId}" : null);
    }

    private function currencyOf(?int $companyId): string
    {
        if (! $companyId) {
            return 'SAR';
        }

        return $this->currencies[$companyId] ??= (function () use ($companyId) {
            $label = Val::label($this->mirrored('res.company', $companyId)?->data['currency_id'] ?? null);

            return $label !== null && preg_match('/^[A-Za-z]{3}$/', $label) ? strtoupper($label) : 'SAR';
        })();
    }

    // ───────────── helpers ─────────────

    private static function cut(?string $s, int $max): ?string
    {
        return $s === null ? null : mb_substr($s, 0, $max);
    }

    private static function digits(mixed $v): ?string
    {
        $s = is_string($v) || is_int($v) ? substr((string) preg_replace('/\D+/', '', (string) $v), 0, 40) : '';

        return $s === '' ? null : $s;
    }

    /** The values of a row as they are written by a plain insert / update: a moment as text with milliseconds. */
    private static function stored(array $values): array
    {
        return array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s.v') : $v, $values);
    }

    /** A value of the change log (Odoo keeps "before" and "after" as short text; a number may arrive as one). */
    private static function scalar(mixed $v): ?string
    {
        if (is_int($v) || is_float($v)) {
            $v = (string) $v;
        }

        return Val::text($v, 60);
    }

    /** A value read through a JSON path of the copy: the database hands it back as JSON text (or already decoded). */
    private static function json(mixed $v): mixed
    {
        if (! is_string($v)) {
            return $v;
        }
        $decoded = json_decode($v, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $v;
    }
}
