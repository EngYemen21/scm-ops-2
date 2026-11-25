<?php

namespace App\Portal\Services;

use App\Models\CustomerSite;
use App\Portal\Models\FavoriteList;
use App\Portal\Models\OrderRequest;
use App\Portal\Models\PortalBranch;
use App\Portal\Models\Statement;
use App\Portal\Support\Owned;
use App\Portal\Support\Parties;
use App\Portal\Support\PortalLog;
use App\Portal\Support\Shape;
use App\Portal\Support\Text;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Customer branches: the units of a customer that order through the portal.
 *
 * WHAT IS WHOSE on a branch that came from Odoo while the section follows Odoo: its name, its customer, its place in
 * the customer's list of branches and whether it is active are Odoo's (`SOURCE_KEYS`); where it is, whom to call, when
 * it takes deliveries, its delivery site and the notes are OPS's — Odoo holds none of them — and are edited here on
 * every branch. A branch made in OPS is OPS's entirely.
 *
 * A branch is never deleted: it is archived with a reason and can be restored. The customer of a branch is its
 * identity and does not change — a branch entered under the wrong customer is archived and entered again.
 *
 * Payload arrays are camelCase and already validated by the controller. In an edit, a key that is present with a null
 * value means "sent empty" (clear it) and an absent key means "leave as it is".
 */
class BranchesService
{
    private const WITH = [Parties::CUSTOMER, 'site:id,name,city,active'];

    private const SORTS = ['name' => 'name', 'sequence' => 'sequence', 'city' => 'city', 'createdAt' => 'created_at'];

    /** What the source system owns on its branches: camelCase key → column. */
    private const SOURCE_KEYS = ['name' => 'name', 'code' => 'code', 'sequence' => 'sequence'];

    /** OPS's own on every branch: camelCase key → column (text). */
    private const OWN_TEXT = [
        'contactName' => 'contact_name', 'phone' => 'phone', 'email' => 'email', 'city' => 'city', 'district' => 'district', 'address' => 'address',
        'windowFrom' => 'window_from', 'windowTo' => 'window_to', 'deliveryNotes' => 'delivery_notes', 'notes' => 'notes',
    ];

    public function __construct(private readonly AuditService $audit) {}

    // ───────────── reading ─────────────

    /** @param  array{customer?:?string, state?:?string, origin?:?string, linked?:?string, site?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $query = $this->filtered($f, $page->q)->with(self::WITH);
        $column = self::SORTS[$page->sort ?? ''] ?? null;
        if ($column === 'city') {
            $query->orderByRaw('city IS NULL');
        }
        $column ? $query->orderBy($column, $page->order)->orderBy('id', $page->order) : $query->orderByDesc('created_at')->orderByDesc('id');

        return $page->paginate($query, fn (PortalBranch $b) => Shape::branch($b));
    }

    public function get(string $id): array
    {
        $b = $this->find($id);
        $owned = Owned::bySource($b);

        return Shape::branch($b, true) + [
            // the delivery sites of the branch's customer, for the picker
            'sites' => $b->customer_id ? CustomerSite::where('customer_id', $b->customer_id)->orderByDesc('active')->orderBy('name')->limit(200)->get(['id', 'name', 'city', 'active'])
                ->map(fn (CustomerSite $s) => ['id' => $s->id, 'name' => $s->name, 'city' => $s->city, 'active' => (bool) $s->active])->all() : [],
            'counts' => [
                'lists' => FavoriteList::where('branch_id', $b->id)->count(),
                'requests' => OrderRequest::where('branch_id', $b->id)->count(),
                'statements' => Statement::where('branch_id', $b->id)->count(),
            ],
            'events' => PortalLog::of('branch', $b->id, 100),
            // what may be done to it now — the client shows a button only for what would be accepted
            'can' => ['edit' => true, 'editIdentity' => ! $owned, 'archive' => ! $owned && $b->active, 'restore' => ! $owned && ! $b->active],
        ];
    }

    public function filtered(array $f, ?string $q): Builder
    {
        $query = PortalBranch::query();
        match ($f['state'] ?? 'active') {
            'archived' => $query->where('active', false),
            'all' => null,
            default => $query->where('active', true),
        };
        Parties::filterCustomer($query, $f['customer'] ?? null);
        Parties::filterLinked($query, $f['linked'] ?? null);
        Parties::filterOrigin($query, $f['origin'] ?? null);
        match ($f['site'] ?? null) {
            'yes' => $query->whereNotNull('site_id'),
            'no' => $query->whereNull('site_id'),
            default => null,
        };
        if ($q !== null && $q !== '') {
            $like = Text::like($q);
            $op = Sql::like();
            $query->where(function ($w) use ($like, $op) {
                foreach (['name', 'code', 'city', 'district', 'contact_name', 'phone'] as $column) {
                    $w->orWhere($column, $op, $like);
                }
                Parties::searchCustomer($w, $like);
            });
        }

        return $query;
    }

    // ───────────── writing ─────────────

    public function create(AuthUser $user, array $dto): array
    {
        $id = DB::transaction(function () use ($user, $dto) {
            $customer = Parties::customer($dto['customerId'] ?? null);
            if (! $customer->active) {
                throw AppError::rule('CUSTOMER_INACTIVE', "العميل {$customer->name_ar} ({$customer->code}) موقوف — لا يُضاف له فرع", "Customer {$customer->code} is inactive");
            }
            $data = $this->values($dto, null, $customer->id);
            $this->nameIsFree($customer->id, $data['name'], null);
            $b = PortalBranch::create($data + ['customer_id' => $customer->id, 'sequence' => 10, 'active' => true, 'created_by' => $user->username, 'updated_by' => $user->username]);
            PortalLog::add('branch', $b->id, 'created', $user);
            $this->audit->log($user, ['action' => 'PORTAL.BRANCH.CREATE', 'entityType' => 'PortalBranch', 'entityId' => $b->id, 'entityNumber' => $b->name,
                'newValue' => ['customer' => $customer->code] + $dto]);

            return $b->id;
        });

        return $this->get($id);
    }

    public function update(AuthUser $user, string $id, array $dto): array
    {
        DB::transaction(function () use ($user, $id, $dto) {
            $b = $this->locked($id);
            if (array_key_exists('customerId', $dto) && $dto['customerId'] && Parties::customer($dto['customerId'])->id !== $b->customer_id) {
                throw AppError::rule('PORTAL_BRANCH_CUSTOMER_FIXED', 'عميل الفرع لا يتغيّر — أرشف هذا الفرع وأضف الفرع تحت عميله الصحيح', 'The customer of a branch does not change — archive this branch and enter it under the right customer');
            }
            $data = $this->values($dto, $b, $b->customer_id);
            $changes = Shape::changes($b, $data);
            if (! $changes) {
                return;
            }
            // a request that changes what Odoo owns is refused as a whole and names those fields; sending them unchanged is harmless
            $sources = array_values(array_intersect(array_keys($changes), array_values(self::SOURCE_KEYS)));
            if ($sources) {
                Owned::refuse($b, 'branch', array_keys(array_intersect(self::SOURCE_KEYS, $sources)));
            }
            if (isset($changes['name'])) {
                $this->nameIsFree($b->customer_id, $changes['name'], $b->id);
            }
            $old = array_map(fn (string $column) => $b->getAttribute($column), array_combine(array_keys($changes), array_keys($changes)));
            $b->update($changes + ['updated_by' => $user->username]);
            PortalLog::add('branch', $b->id, 'updated', $user, ['data' => ['fields' => array_map(fn (string $c) => Shape::camel($c), array_keys($changes))]]);
            $this->audit->log($user, ['action' => 'PORTAL.BRANCH.UPDATE', 'entityType' => 'PortalBranch', 'entityId' => $b->id, 'entityNumber' => $b->name, 'oldValue' => $old, 'newValue' => $changes]);
        });

        return $this->get($id);
    }

    public function archive(AuthUser $user, string $id, ?string $reason): array
    {
        DB::transaction(function () use ($user, $id, $reason) {
            $b = $this->locked($id);
            Owned::refuse($b, 'branch');
            if (! $b->active) {
                throw AppError::rule('PORTAL_BRANCH_ARCHIVED', "الفرع «{$b->name}» مؤرشف أصلًا", "Branch \"{$b->name}\" is already archived");
            }
            $pending = OrderRequest::where('branch_id', $b->id)->where('status', 'pending')->count();
            if ($pending > 0) {
                throw AppError::rule('PORTAL_BRANCH_HAS_REQUESTS', "للفرع {$pending} طلب شراء بانتظار القرار — يُبتّ فيها أولًا", "The branch has {$pending} order request(s) waiting for a decision — decide them first", ['pending' => $pending]);
            }
            $reason = Text::clean($reason, 500);
            $b->update(['active' => false, 'archived_at' => now(), 'archived_by' => $user->username, 'archive_reason' => $reason, 'updated_by' => $user->username]);
            PortalLog::add('branch', $b->id, 'archived', $user, ['note' => $reason]);
            $this->audit->status($user, 'PortalBranch', $b->id, $b->name, 'active', 'archived', $reason);
        });

        return $this->get($id);
    }

    public function restore(AuthUser $user, string $id): array
    {
        DB::transaction(function () use ($user, $id) {
            $b = $this->locked($id);
            Owned::refuse($b, 'branch');
            if ($b->active) {
                throw AppError::rule('PORTAL_BRANCH_ACTIVE', "الفرع «{$b->name}» نشط أصلًا", "Branch \"{$b->name}\" is already active");
            }
            if ($b->customer_id) {
                $this->nameIsFree($b->customer_id, $b->name, $b->id);
            }
            $b->update(['active' => true, 'archived_at' => null, 'archived_by' => null, 'archive_reason' => null, 'updated_by' => $user->username]);
            PortalLog::add('branch', $b->id, 'restored', $user);
            $this->audit->status($user, 'PortalBranch', $b->id, $b->name, 'archived', 'active');
        });

        return $this->get($id);
    }

    // ───────────── pieces ─────────────

    public function find(string $id): PortalBranch
    {
        return PortalBranch::with(self::WITH)->find($id) ?? throw AppError::notFound('PORTAL_BRANCH_NOT_FOUND', 'الفرع غير موجود', 'Branch not found');
    }

    private function locked(string $id): PortalBranch
    {
        return PortalBranch::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('PORTAL_BRANCH_NOT_FOUND', 'الفرع غير موجود', 'Branch not found');
    }

    /** A customer never has two active branches of one name. */
    private function nameIsFree(?string $customerId, string $name, ?string $except): void
    {
        if (! $customerId) {
            return;
        }
        $twin = PortalBranch::where('customer_id', $customerId)->where('active', true)->when($except, fn ($q) => $q->where('id', '!=', $except))->get(['id', 'name'])
            ->first(fn (PortalBranch $x) => Text::nameKey($x->name) === Text::nameKey($name));
        if ($twin) {
            throw AppError::rule('PORTAL_BRANCH_EXISTS', "للعميل فرع نشط بالاسم نفسه «{$twin->name}»", "The customer already has an active branch named \"{$twin->name}\"", ['branchId' => $twin->id]);
        }
    }

    /**
     * The columns a payload sets. For a new branch (`$b` null) empty values are simply left out.
     *
     * @return array<string, mixed>
     */
    private function values(array $dto, ?PortalBranch $b, ?string $customerId): array
    {
        $data = [];
        foreach (['name' => 190, 'code' => 40] as $key => $max) {
            if (array_key_exists($key, $dto)) {
                $data[self::SOURCE_KEYS[$key]] = Text::clean($dto[$key], $max);
            }
        }
        if (array_key_exists('name', $data) && $data['name'] === null) {
            throw AppError::validation('PORTAL_BRANCH_NAME', 'اسم الفرع إلزامي', 'The branch needs a name', [['path' => 'name', 'message' => 'هذا الحقل إلزامي']]);
        }
        if ($b === null && ! array_key_exists('name', $data)) {
            throw AppError::validation('PORTAL_BRANCH_NAME', 'اسم الفرع إلزامي', 'The branch needs a name', [['path' => 'name', 'message' => 'هذا الحقل إلزامي']]);
        }
        if (array_key_exists('sequence', $dto) && $dto['sequence'] !== null) {
            $data['sequence'] = (int) $dto['sequence'];
        }
        foreach (self::OWN_TEXT as $key => $column) {
            if (array_key_exists($key, $dto)) {
                $data[$column] = Text::clean($dto[$key], in_array($key, ['address', 'deliveryNotes'], true) ? 500 : ($key === 'notes' ? 4000 : 190));
            }
        }
        // a delivery window is two hours of one day, the first before the second
        $from = array_key_exists('window_from', $data) ? $data['window_from'] : $b?->window_from;
        $to = array_key_exists('window_to', $data) ? $data['window_to'] : $b?->window_to;
        if (($from === null) !== ($to === null)) {
            throw AppError::validation('PORTAL_WINDOW', 'نافذة التسليم تحتاج وقت البداية ووقت النهاية معًا', 'A delivery window needs both its start and its end', [['path' => $from === null ? 'windowFrom' : 'windowTo', 'message' => 'هذا الحقل إلزامي']]);
        }
        if ($from !== null && $from >= $to) {
            throw AppError::validation('PORTAL_WINDOW', 'بداية نافذة التسليم يجب أن تسبق نهايتها', 'The delivery window must start before it ends', [['path' => 'windowTo', 'message' => 'يجب أن تكون بعد البداية']]);
        }
        if (array_key_exists('deliveryDays', $dto)) {
            $days = array_values(array_intersect(PortalBranch::DAYS, array_map('strval', (array) ($dto['deliveryDays'] ?? []))));
            $data['delivery_days'] = $days && count($days) < count(PortalBranch::DAYS) ? $days : null; // every day = no restriction
        }
        if (array_key_exists('lat', $dto) || array_key_exists('lng', $dto)) {
            $lat = $dto['lat'] ?? null;
            $lng = $dto['lng'] ?? null;
            if (($lat === null) !== ($lng === null)) {
                throw AppError::validation('PORTAL_COORDINATES', 'الموقع يحتاج خط العرض وخط الطول معًا', 'A location needs both its latitude and its longitude', [['path' => $lat === null ? 'lat' : 'lng', 'message' => 'هذا الحقل إلزامي']]);
            }
            $data['lat'] = $lat === null ? null : (float) $lat;
            $data['lng'] = $lng === null ? null : (float) $lng;
        }
        if (array_key_exists('siteId', $dto)) {
            $data['site_id'] = $dto['siteId'] ? $this->site((string) $dto['siteId'], $customerId)->id : null;
        }
        if ($b === null) {
            $data = array_filter($data, fn ($v) => $v !== null);
        }

        return $data;
    }

    /** The delivery site a branch is tied to is a site of the branch's own customer. */
    private function site(string $id, ?string $customerId): CustomerSite
    {
        $s = CustomerSite::find($id);
        if (! $s || ! $customerId || $s->customer_id !== $customerId) {
            throw AppError::validation('PORTAL_SITE_UNKNOWN', 'موقع التسليم ليس من مواقع عميل هذا الفرع', 'The delivery site is not one of this branch\'s customer', [['path' => 'siteId', 'message' => 'موقع غير موجود لهذا العميل']]);
        }

        return $s;
    }
}
