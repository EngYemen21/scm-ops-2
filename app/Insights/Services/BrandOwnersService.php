<?php

namespace App\Insights\Services;

use App\Insights\Models\BrandAlert;
use App\Insights\Models\BrandOwner;
use App\Insights\Models\Franchisee;
use App\Insights\Support\Access;
use App\Insights\Support\Owned;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;

/**
 * Brand owners and their franchisees: who they are, what they may see, the brand's products and categories, the
 * franchisees with their targets. Every change needs `brandowner.manage` (route) and is audited; an owner is archived,
 * never deleted; a franchisee is stopped, never removed.
 */
class BrandOwnersService
{
    public function __construct(private readonly AuditService $audit, private readonly BrandScope $scope) {}

    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $q = BrandOwner::query()->with('user:id,username,name_ar,name_en');
        if (! Access::manager($user)) {
            $mine = Access::ownerOf($user);
            if (! $mine) {
                throw AppError::forbidden('BRAND_FORBIDDEN', 'لا تملك الاطلاع على العلامات', 'You may not see brand owners');
            }
            $q->where('id', $mine->id)->where('state', 'active');
        }
        if (! empty($f['state'])) {
            $q->where('state', $f['state']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('brand_name', 'like', $like)->orWhere('user_name', 'like', $like));
        }
        match ($page->sort) {
            'state' => $q->orderBy('state', $page->order),
            'createdAt' => $q->orderBy('created_at', $page->order),
            'brand' => $q->orderBy('brand_name', $page->order),
            default => $q->orderBy('name', $page->sort === 'name' ? $page->order : 'asc'),
        };
        $q->orderBy('id');
        $out = $page->paginate($q);
        $ids = array_map(fn (BrandOwner $o) => $o->id, $out['items']);
        $franchisees = DB::table('ins_franchisees')->whereIn('owner_id', $ids)->whereIn('status', Franchisee::BUYING)->groupBy('owner_id')->selectRaw('owner_id, count(*) as n')->pluck('n', 'owner_id');
        $alerts = DB::table('ins_brand_alerts')->whereIn('owner_id', $ids)->where('is_read', false)->groupBy('owner_id')->selectRaw('owner_id, count(*) as n')->pluck('n', 'owner_id');
        $out['items'] = array_map(fn (BrandOwner $o) => $this->row($o) + ['franchisees' => (int) ($franchisees[$o->id] ?? 0), 'unreadAlerts' => (int) ($alerts[$o->id] ?? 0)], $out['items']);

        return $out;
    }

    public function show(AuthUser $user, string $id): array
    {
        $o = Access::owner($user, $id);
        $flags = Access::flags($user, $o);
        $o->load(['user:id,username,name_ar,name_en', 'contact:id,code,name_ar,name_en', 'products:id,sku,name_ar,name_en,brand', 'categories:id,code,name_ar,name_en']);
        $franchisees = Franchisee::with('customer:id,code,name_ar,name_en,city,zone')->where('owner_id', $o->id)->orderByRaw("case status when 'active' then 0 when 'weak' then 1 when 'inactive' then 2 else 3 end")->orderBy('created_at')->orderBy('id')->get();

        return $this->row($o) + [
            'contact' => $o->contact ? ['id' => $o->contact->id, 'code' => $o->contact->code, 'nameAr' => $o->contact->name_ar, 'nameEn' => $o->contact->name_en] : ($o->contact_name ? ['name' => $o->contact_name] : null),
            'notes' => $o->notes, 'access' => $flags,
            'products' => $o->products->map(fn ($p) => ['id' => $p->id, 'sku' => $p->sku, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en, 'brand' => $p->brand])->values()->all(),
            'categories' => $o->categories->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en])->values()->all(),
            'productCount' => count($this->scope->productIds($o)),
            'franchisees' => $franchisees->map(fn (Franchisee $f) => $this->franchiseeRow($f))->all(),
            'unreadAlerts' => BrandAlert::where('owner_id', $o->id)->where('is_read', false)->count(),
            'next' => $flags['manager'] && ! Owned::bySource($o) ? BrandOwner::TRANSITIONS[$o->state] : [],
        ];
    }

    /** For the forms: OPS users, product categories. */
    public function lookups(): array
    {
        $linked = BrandOwner::whereNotNull('user_id')->pluck('id', 'user_id');

        return [
            'users' => User::where('active', true)->where('username', 'not like', 'svc.%')->orderBy('username')->get(['id', 'username', 'name_ar', 'name_en'])
                ->map(fn ($u) => ['id' => $u->id, 'username' => $u->username, 'nameAr' => $u->name_ar, 'nameEn' => $u->name_en, 'ownerId' => $linked[$u->id] ?? null])->all(),
            'categories' => ProductCategory::where('active', true)->orderBy('code')->get(['id', 'code', 'name_ar', 'name_en'])->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en])->all(),
            'brands' => DB::table('products')->whereNotNull('brand')->where('brand', '!=', '')->groupBy('brand')->orderBy('brand')->selectRaw('brand, count(*) as n')->get()->map(fn ($b) => ['brand' => $b->brand, 'products' => (int) $b->n])->all(),
            'states' => BrandOwner::STATES, 'franchiseeStates' => Franchisee::STATES,
        ];
    }

    public function create(AuthUser $user, array $d): array
    {
        $o = DB::transaction(function () use ($user, $d) {
            $userId = $this->freeUser($d['userId'], null);
            $o = BrandOwner::create([
                'name' => trim($d['name']), 'brand_name' => self::clean($d['brandName'] ?? null), 'user_id' => $userId, 'contact_customer_id' => $this->customerId($d['contactCustomerId'] ?? null),
                'state' => 'draft', 'date_start' => $d['dateStart'] ?? null, 'allow_invoice' => (bool) ($d['allowInvoice'] ?? true), 'allow_payment' => (bool) ($d['allowPayment'] ?? false),
                'allow_return' => (bool) ($d['allowReturn'] ?? true), 'allow_margin' => (bool) ($d['allowMargin'] ?? false), 'notes' => self::clean($d['notes'] ?? null), 'active' => true,
                'created_by' => $user->username, 'updated_by' => $user->username,
            ]);
            $this->syncScope($o, $d);
            $this->audit->log($user, ['action' => 'BRAND_OWNER.CREATE', 'entityType' => 'BrandOwner', 'entityId' => $o->id, 'entityNumber' => $o->name,
                'newValue' => ['name' => $o->name, 'brand' => $o->brand_name, 'user' => $o->user_id, 'products' => $d['productIds'] ?? [], 'categories' => $d['categoryIds'] ?? []]]);

            return $o;
        });

        return $this->show($user, $o->id);
    }

    public function update(AuthUser $user, string $id, array $d): array
    {
        DB::transaction(function () use ($user, $id, $d) {
            $o = BrandOwner::lockForUpdate()->find($id) ?? throw AppError::notFound('BRAND_OWNER_NOT_FOUND', 'مالك العلامة غير موجود', 'Brand owner not found');
            Owned::refuse($o, 'owner');
            if ($o->state === 'archived') {
                throw AppError::rule('BRAND_ARCHIVED', 'مالك العلامة مؤرشف — لا يُعدَّل', 'The brand owner is archived and cannot be changed');
            }
            $map = ['name' => 'name', 'brandName' => 'brand_name', 'dateStart' => 'date_start', 'allowInvoice' => 'allow_invoice', 'allowPayment' => 'allow_payment', 'allowReturn' => 'allow_return',
                'allowMargin' => 'allow_margin', 'notes' => 'notes'];
            $values = [];
            foreach ($map as $in => $col) {
                if (array_key_exists($in, $d)) {
                    $values[$col] = is_string($d[$in]) ? self::clean($d[$in]) : $d[$in];
                }
            }
            if (array_key_exists('userId', $d)) {
                $values['user_id'] = $this->freeUser($d['userId'], $o->id);
            }
            if (array_key_exists('contactCustomerId', $d)) {
                $values['contact_customer_id'] = $this->customerId($d['contactCustomerId']);
            }
            $old = array_map(fn ($c) => $o->getAttribute($c) instanceof \DateTimeInterface ? $o->getAttribute($c)->format('Y-m-d') : $o->getAttribute($c), array_combine(array_keys($values), array_keys($values)));
            $o->update($values + ['updated_by' => $user->username]);
            $scope = $this->syncScope($o, $d);
            $this->audit->log($user, ['action' => 'BRAND_OWNER.UPDATE', 'entityType' => 'BrandOwner', 'entityId' => $o->id, 'entityNumber' => $o->name, 'oldValue' => $old + $scope['old'], 'newValue' => $values + $scope['new']]);
        });

        return $this->show($user, $id);
    }

    public function transition(AuthUser $user, string $id, string $to, ?string $reason): array
    {
        DB::transaction(function () use ($user, $id, $to, $reason) {
            $o = BrandOwner::lockForUpdate()->find($id) ?? throw AppError::notFound('BRAND_OWNER_NOT_FOUND', 'مالك العلامة غير موجود', 'Brand owner not found');
            Owned::refuse($o, 'owner');
            if (! in_array($to, BrandOwner::TRANSITIONS[$o->state] ?? [], true)) {
                throw AppError::rule('BRAND_TRANSITION', "لا يمكن نقل مالك العلامة من «{$o->state}» إلى «{$to}»", "A brand owner cannot move from {$o->state} to {$to}", ['from' => $o->state, 'to' => $to, 'allowed' => BrandOwner::TRANSITIONS[$o->state] ?? []]);
            }
            if (in_array($to, ['suspended', 'archived'], true) && trim((string) $reason) === '') {
                throw AppError::validation('REASON_REQUIRED', 'اذكر السبب', 'A reason is required');
            }
            if ($to === 'active' && ! $o->user_id) {
                throw AppError::rule('BRAND_NO_USER', 'لا يُفعَّل مالك علامة بلا مستخدم مرتبط', 'A brand owner without a linked user cannot be activated');
            }
            $from = $o->state;
            $o->update(['state' => $to, 'active' => $to !== 'archived', 'updated_by' => $user->username]);
            $this->audit->status($user, 'BrandOwner', $o->id, $o->name, $from, $to, $reason);
        });

        return $this->show($user, $id);
    }

    // ───────────── franchisees ─────────────

    public function addFranchisee(AuthUser $user, string $ownerId, array $d): array
    {
        $f = DB::transaction(function () use ($user, $ownerId, $d) {
            $o = BrandOwner::lockForUpdate()->find($ownerId) ?? throw AppError::notFound('BRAND_OWNER_NOT_FOUND', 'مالك العلامة غير موجود', 'Brand owner not found');
            if ($o->state === 'archived') {
                throw AppError::rule('BRAND_ARCHIVED', 'مالك العلامة مؤرشف — لا يُضاف إليه فرع', 'The brand owner is archived');
            }
            $customer = Customer::where('id', $d['customerId'])->orWhere('code', $d['customerId'])->first() ?? throw AppError::notFound('CUSTOMER_NOT_FOUND', 'العميل غير موجود', 'Customer not found');
            if (Franchisee::where('owner_id', $o->id)->where('customer_id', $customer->id)->exists()) {
                throw AppError::conflict('FRANCHISEE_EXISTS', "العميل {$customer->code} فرع لهذه العلامة من قبل", "Customer {$customer->code} is already a franchisee of this brand");
            }
            $f = Franchisee::create([
                'owner_id' => $o->id, 'customer_id' => $customer->id, 'region' => self::clean($d['region'] ?? null) ?? $customer->zone, 'city' => self::clean($d['city'] ?? null) ?? $customer->city,
                'date_start' => $d['dateStart'] ?? null, 'monthly_target' => round((float) ($d['monthlyTarget'] ?? 0), 2), 'currency' => 'SAR', 'status' => 'active', 'active' => true,
                'notes' => self::clean($d['notes'] ?? null), 'created_by' => $user->username, 'updated_by' => $user->username,
            ]);
            $this->audit->log($user, ['action' => 'BRAND_FRANCHISEE.CREATE', 'entityType' => 'BrandFranchisee', 'entityId' => $f->id, 'entityNumber' => $customer->code,
                'newValue' => ['owner' => $o->name, 'customer' => $customer->code, 'monthlyTarget' => $f->monthly_target]]);

            return $f;
        });

        return $this->franchiseeRow($f->fresh('customer'));
    }

    public function updateFranchisee(AuthUser $user, string $id, array $d): array
    {
        $f = DB::transaction(function () use ($user, $id, $d) {
            $f = Franchisee::lockForUpdate()->find($id) ?? throw AppError::notFound('FRANCHISEE_NOT_FOUND', 'الفرع غير موجود', 'Franchisee not found');
            Owned::refuse($f, 'franchisee');
            if ($f->status === 'stopped') {
                throw AppError::rule('FRANCHISEE_STOPPED', 'الفرع متوقف — لا يُعدَّل', 'The franchisee is stopped and cannot be changed');
            }
            $values = [];
            foreach (['region' => 'region', 'city' => 'city', 'dateStart' => 'date_start', 'notes' => 'notes'] as $in => $col) {
                if (array_key_exists($in, $d)) {
                    $values[$col] = is_string($d[$in]) ? self::clean($d[$in]) : $d[$in];
                }
            }
            if (array_key_exists('monthlyTarget', $d)) {
                $values['monthly_target'] = round((float) $d['monthlyTarget'], 2);
            }
            $old = [];
            foreach ($values as $col => $v) {
                $cur = $f->getAttribute($col);
                $old[$col] = $cur instanceof \DateTimeInterface ? $cur->format('Y-m-d') : $cur;
            }
            $f->update($values + ['updated_by' => $user->username]);
            $this->audit->log($user, ['action' => 'BRAND_FRANCHISEE.UPDATE', 'entityType' => 'BrandFranchisee', 'entityId' => $f->id, 'oldValue' => $old, 'newValue' => $values]);

            return $f;
        });

        return $this->franchiseeRow($f->fresh('customer'));
    }

    public function franchiseeStatus(AuthUser $user, string $id, string $to, ?string $reason): array
    {
        $f = DB::transaction(function () use ($user, $id, $to, $reason) {
            $f = Franchisee::lockForUpdate()->find($id) ?? throw AppError::notFound('FRANCHISEE_NOT_FOUND', 'الفرع غير موجود', 'Franchisee not found');
            Owned::refuse($f, 'franchisee');
            if (! in_array($to, Franchisee::TRANSITIONS[$f->status] ?? [], true)) {
                throw AppError::rule('FRANCHISEE_TRANSITION', "لا يمكن نقل الفرع من «{$f->status}» إلى «{$to}»", "A franchisee cannot move from {$f->status} to {$to}", ['from' => $f->status, 'to' => $to, 'allowed' => Franchisee::TRANSITIONS[$f->status] ?? []]);
            }
            if ($to === 'stopped' && trim((string) $reason) === '') {
                throw AppError::validation('REASON_REQUIRED', 'اذكر سبب الإيقاف', 'A reason is required to stop a franchisee');
            }
            $from = $f->status;
            $f->update(['status' => $to, 'active' => $to !== 'stopped', 'updated_by' => $user->username]);
            $this->audit->status($user, 'BrandFranchisee', $f->id, null, $from, $to, $reason);

            return $f;
        });

        return $this->franchiseeRow($f->fresh('customer'));
    }

    // ───────────── helpers ─────────────

    public function row(BrandOwner $o): array
    {
        return [
            'id' => $o->id, 'name' => $o->name, 'brandName' => $o->brand_name, 'state' => $o->state, 'dateStart' => $o->date_start?->toDateString(),
            'user' => $o->user ? ['id' => $o->user->id, 'username' => $o->user->username, 'nameAr' => $o->user->name_ar, 'nameEn' => $o->user->name_en] : null,
            'userName' => $o->user_name,
            'allowInvoice' => $o->allow_invoice, 'allowPayment' => $o->allow_payment, 'allowReturn' => $o->allow_return, 'allowMargin' => $o->allow_margin,
            'sourceSystem' => $o->source_system, 'externalRef' => $o->external_ref, 'ownedBySource' => Owned::bySource($o),
            'createdAt' => $o->created_at?->toJSON(), 'updatedAt' => $o->updated_at?->toJSON(),
        ];
    }

    public function franchiseeRow(Franchisee $f): array
    {
        return [
            'id' => $f->id, 'ownerId' => $f->owner_id, 'customer' => $f->customer ? ['id' => $f->customer->id, 'code' => $f->customer->code, 'nameAr' => $f->customer->name_ar, 'nameEn' => $f->customer->name_en] : null,
            'region' => $f->region, 'city' => $f->city, 'dateStart' => $f->date_start?->toDateString(), 'monthlyTarget' => $f->monthly_target, 'currency' => $f->currency,
            'status' => $f->status, 'notes' => $f->notes, 'next' => Owned::bySource($f) ? [] : Franchisee::TRANSITIONS[$f->status], 'sourceSystem' => $f->source_system, 'ownedBySource' => Owned::bySource($f),
        ];
    }

    /** The products and categories named on the owner, when the payload carries them. @return array{old:array, new:array} */
    private function syncScope(BrandOwner $o, array $d): array
    {
        $old = $new = [];
        if (array_key_exists('productIds', $d)) {
            $ids = array_values(array_unique($d['productIds'] ?? []));
            $found = Product::whereIn('id', $ids)->pluck('id')->all();
            if (count($found) !== count($ids)) {
                throw AppError::validation('PRODUCT_NOT_FOUND', 'منتج من القائمة غير موجود', 'A product of the list does not exist', ['missing' => array_values(array_diff($ids, $found))]);
            }
            $old['products'] = $o->products()->pluck('products.id')->all();
            $o->products()->sync($ids);
            $new['products'] = $ids;
        }
        if (array_key_exists('categoryIds', $d)) {
            $ids = array_values(array_unique($d['categoryIds'] ?? []));
            $found = ProductCategory::whereIn('id', $ids)->pluck('id')->all();
            if (count($found) !== count($ids)) {
                throw AppError::validation('CATEGORY_NOT_FOUND', 'فئة من القائمة غير موجودة', 'A category of the list does not exist', ['missing' => array_values(array_diff($ids, $found))]);
            }
            $old['categories'] = $o->categories()->pluck('product_categories.id')->all();
            $o->categories()->sync($ids);
            $new['categories'] = $ids;
        }

        return ['old' => $old, 'new' => $new];
    }

    /** A user may be the user of one brand owner only. */
    private function freeUser(?string $userId, ?string $ownerId): ?string
    {
        if ($userId === null || $userId === '') {
            return null;
        }
        $u = User::where('id', $userId)->orWhere('username', $userId)->first() ?? throw AppError::notFound('USER_NOT_FOUND', 'المستخدم غير موجود', 'User not found');
        $taken = BrandOwner::where('user_id', $u->id)->when($ownerId, fn ($q) => $q->where('id', '!=', $ownerId))->first();
        if ($taken) {
            throw AppError::conflict('BRAND_USER_TAKEN', "المستخدم {$u->username} مرتبط بمالك علامة آخر", "User {$u->username} is linked to another brand owner");
        }

        return $u->id;
    }

    private function customerId(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return Customer::where('id', $id)->orWhere('code', $id)->value('id') ?? throw AppError::notFound('CUSTOMER_NOT_FOUND', 'العميل غير موجود', 'Customer not found');
    }

    private static function clean(?string $s): ?string
    {
        $s = $s === null ? null : trim($s);

        return $s === '' ? null : $s;
    }
}
