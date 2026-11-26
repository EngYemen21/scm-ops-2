<?php

namespace App\Pricing\Services;

use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Services\ExternalRefs;
use App\Models\Customer;
use App\Pricing\Models\PriceList;
use App\Pricing\Models\PriceListCustomer;
use App\Pricing\Support\Day;
use App\Pricing\Support\Find;
use App\Pricing\Support\Owned;
use App\Pricing\Support\Shape;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The price list of each customer. A customer has one list at most; a customer without one is priced with the default
 * list. Every change is audited on the customer (old list → new list).
 *
 * While the section follows Odoo: a list that Odoo's own field names (`basis = partner`) is Odoo's — and once the copy
 * carries that field, so is the list (or the absence of one) of every customer OPS shares with Odoo. A list that was
 * only read from the customer's orders (`basis = order`) is evidence, not a decision: a person may confirm or correct
 * it here, and that choice stands until Odoo's own field arrives in the copy.
 */
class AssignmentsService
{
    /** The Odoo field that names a partner's price list (docs: computed in Odoo, kept per company). */
    public const PARTNER_FIELD = 'property_product_pricelist';

    public const MANY_LIMIT = 200;

    public function __construct(private readonly AuditService $audit, private readonly ExternalRefs $refs) {}

    /**
     * Customers with the list each is priced with.
     *
     * @param  array{list?:?string, assigned?:?string, basis?:?string, active?:?string}  $f  assigned: yes | no · basis: manual | partner | order · active: all | false (default: active only)
     */
    public function customers(Paging $page, array $f): array
    {
        $query = Customer::query();
        match ($f['active'] ?? '') {
            'all' => null,
            'false' => $query->where('active', false),
            default => $query->where('active', true),
        };
        if (! empty($f['list'])) {
            $list = Find::list((string) $f['list']);
            $query->whereIn('id', fn ($s) => $s->select('customer_id')->from('price_list_customers')->where('price_list_id', $list->id));
        }
        if (in_array($f['basis'] ?? null, PriceListCustomer::BASES, true)) {
            $query->whereIn('id', fn ($s) => $s->select('customer_id')->from('price_list_customers')->where('basis', $f['basis']));
        }
        if (($f['assigned'] ?? null) === 'yes') {
            $query->whereIn('id', fn ($s) => $s->select('customer_id')->from('price_list_customers'));
        } elseif (($f['assigned'] ?? null) === 'no') {
            $query->whereNotIn('id', fn ($s) => $s->select('customer_id')->from('price_list_customers'));
        }
        if ($page->q) {
            $like = '%'.$page->q.'%';
            $query->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('city', 'like', $like));
        }
        $sortable = ['code' => 'code', 'nameAr' => 'name_ar', 'city' => 'city'];
        $query->orderBy($sortable[$page->sort ?? ''] ?? 'code', isset($sortable[$page->sort ?? '']) ? $page->order : 'asc')->orderBy('id');

        $total = (clone $query)->toBase()->getCountForPagination();
        $rows = $query->forPage($page->page, $page->pageSize)->get();
        $by = PriceListCustomer::with('priceList')->whereIn('customer_id', $rows->pluck('id')->all())->get()->keyBy('customer_id');
        $default = $this->defaultList();
        $today = Day::today();
        // whose list is Odoo's right now: the row says so, and the screen offers no change that would be refused
        $follows = Owned::following();
        $shared = $follows && $this->partnerFieldCopied()
            ? IntExternalRef::where('system', OdooProjection::SYSTEM)->where('entity', 'customer')->whereIn('internal_id', $rows->pluck('id')->all())->pluck('internal_id')->flip()->all() : [];

        return $page->wrap($rows->map(function (Customer $c) use ($by, $default, $today, $follows, $shared) {
            $a = $by[$c->id] ?? null;

            return $this->row($c, $a, $default, $today) + ['locked' => isset($shared[$c->id]) || ($follows && $a && $a->basis === 'partner' && $a->source_system === OdooProjection::SYSTEM)];
        })->all(), $total) + ['defaultList' => Shape::listRef($default), 'follows' => $follows];
    }

    /** One customer: its list, how OPS knows it, and whether it may be changed here. */
    public function customer(string $idOrCode): array
    {
        $c = Find::customer($idOrCode);
        $a = PriceListCustomer::with('priceList')->where('customer_id', $c->id)->first();
        $locked = $this->lockedBySource($c, $a);

        return $this->row($c, $a, $this->defaultList(), Day::today()) + ['locked' => $locked];
    }

    public function assign(AuthUser $user, string $customerIdOrCode, string $listIdOrCode, ?string $note = null): array
    {
        $c = Find::customer($customerIdOrCode);
        DB::transaction(function () use ($user, $c, $listIdOrCode, $note) {
            Customer::whereKey($c->id)->lockForUpdate()->first(); // two people assigning one customer go one after the other
            $list = Find::list($listIdOrCode);
            if (! $list->active) {
                throw AppError::rule('PRICE_LIST_ARCHIVED', "القائمة «{$list->name}» مؤرشفة — لا يُسند إليها عميل", "List “{$list->name}” is archived — no customer can be assigned to it");
            }
            $a = PriceListCustomer::with('priceList')->where('customer_id', $c->id)->lockForUpdate()->first();
            if ($this->lockedBySource($c, $a)) {
                throw Owned::assignmentRefusal(['customer' => $c->code]);
            }
            if ($a && $a->price_list_id === $list->id && $a->basis === 'manual') {
                return;
            }
            $old = $a ? ['list' => $a->priceList?->code, 'basis' => $a->basis, 'basisRef' => $a->basis_ref] : null;
            $values = ['price_list_id' => $list->id, 'basis' => 'manual', 'basis_ref' => null, 'basis_odoo_id' => null, 'basis_at' => null, 'note' => $note ?: null, 'source_system' => null, 'external_ref' => null];
            if ($a) {
                $a->update($values + ['updated_by' => $user->username]);
            } else {
                PriceListCustomer::create($values + ['customer_id' => $c->id, 'created_by' => $user->username]);
            }
            $this->audit->log($user, ['action' => 'PRICING.ASSIGN', 'entityType' => 'Customer', 'entityId' => $c->id, 'entityNumber' => $c->code, 'field' => 'priceList',
                'oldValue' => $old, 'newValue' => ['list' => $list->code, 'basis' => 'manual', 'note' => $note ?: null]]);
        });

        return $this->customer($c->id);
    }

    /** The customer goes back to the default list. */
    public function unassign(AuthUser $user, string $customerIdOrCode, ?string $note = null): array
    {
        $c = Find::customer($customerIdOrCode);
        DB::transaction(function () use ($user, $c, $note) {
            $a = PriceListCustomer::with('priceList')->where('customer_id', $c->id)->lockForUpdate()->first();
            if (! $a) {
                return;
            }
            if ($this->lockedBySource($c, $a)) {
                throw Owned::assignmentRefusal(['customer' => $c->code]);
            }
            $old = ['list' => $a->priceList?->code, 'basis' => $a->basis, 'basisRef' => $a->basis_ref];
            $a->delete();
            $this->audit->log($user, ['action' => 'PRICING.UNASSIGN', 'entityType' => 'Customer', 'entityId' => $c->id, 'entityNumber' => $c->code, 'field' => 'priceList', 'oldValue' => $old, 'newValue' => ['list' => null, 'note' => $note ?: null]]);
        });

        return $this->customer($c->id);
    }

    /**
     * Several customers onto one list. Each customer is its own decision: one that Odoo owns is reported as refused and
     * does not hold the others back.
     *
     * @param  string[]  $customers  ids or codes
     * @return array{assigned:int, refused:array<int, array{customer:string, code:string, message:string, messageEn:string}>, list:array}
     */
    public function assignMany(AuthUser $user, string $listIdOrCode, array $customers, ?string $note = null): array
    {
        $list = Find::list($listIdOrCode);
        $out = ['assigned' => 0, 'refused' => []];
        foreach (array_values(array_unique(array_map('strval', $customers))) as $key) {
            try {
                $this->assign($user, $key, $list->id, $note);
                $out['assigned']++;
            } catch (AppError $e) {
                if ($e->errorCode === 'PRICE_LIST_ARCHIVED') {
                    throw $e; // the fault is the list's: every customer would fail the same way
                }
                $out['refused'][] = ['customer' => $key, 'code' => $e->errorCode, 'message' => $e->messageAr, 'messageEn' => $e->messageEn ?? $e->messageAr];
            }
        }

        return $out + ['list' => Shape::listRef($list)];
    }

    // ───────────── inside ─────────────

    private function defaultList(): ?PriceList
    {
        return PriceList::where('is_default', true)->orderBy('sequence')->orderBy('id')->first();
    }

    private function row(Customer $c, ?PriceListCustomer $a, ?PriceList $default, Carbon $today): array
    {
        $own = $a?->priceList;
        $effective = $own && $own->usableOn($today) ? ['list' => Shape::listRef($own), 'via' => 'assigned']
            : ($default && $default->usableOn($today) ? ['list' => Shape::listRef($default), 'via' => 'default'] : ['list' => null, 'via' => 'none']);

        return Shape::party($c) + ['city' => $c->city, 'sourceSystem' => $c->source_system, 'assignment' => Shape::assignment($a), 'effective' => $effective];
    }

    /**
     * Is the customer's list Odoo's right now? Yes when Odoo's own field named it — and also for every customer OPS
     * shares with Odoo once the copy carries that field: having no list of its own is then Odoo's decision too.
     */
    private function lockedBySource(Customer $c, ?PriceListCustomer $a): bool
    {
        if (! Owned::following()) {
            return false;
        }
        if ($a && $a->basis === 'partner' && $a->source_system === OdooProjection::SYSTEM) {
            return true;
        }

        return $this->partnerFieldCopied() && $this->refs->externalId(OdooProjection::SYSTEM, 'customer', $c->id) !== null;
    }

    /**
     * The copy of Odoo carries the partner's own price-list field (it is listed among the copied fields of
     * `res.partner`). Asked each time: the service outlives a request, and the copy may gain the field in between.
     */
    private function partnerFieldCopied(): bool
    {
        return array_key_exists(self::PARTNER_FIELD, (array) OdooModel::where('model', 'res.partner')->where('status', 'active')->first()?->fields);
    }
}
