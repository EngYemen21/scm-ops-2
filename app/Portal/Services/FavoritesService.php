<?php

namespace App\Portal\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Portal\Models\FavoriteItem;
use App\Portal\Models\FavoriteList;
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
 * Favourite lists: the products a customer orders again and again, in the customer's own order, each with the quantity
 * it usually takes.
 *
 * VOLUME. The items of every list of every customer live in one table (~156 k rows): a list is read here by its header
 * (`items_count` is kept on it) and its items are read one page at a time through the index (list, sequence). Nothing
 * in this service loads a customer's favourites as a whole.
 *
 * WHAT IS WHOSE on a list that came from Odoo while the section follows Odoo: its name, its place among the customer's
 * lists and its items are Odoo's. The branch a list is kept for and the notes are OPS's own on every list; so is
 * "order again" (ReorderService), which makes a document of the sales module and changes nothing here.
 *
 * A list is archived, never deleted. A favourite is simply removed: it is a choice of the customer, not a document —
 * who removed which product stays in the list's timeline and in the audit trail.
 */
class FavoritesService
{
    public const WITH = [Parties::CUSTOMER, Parties::BRANCH];

    private const SORTS = ['name' => 'name', 'itemsCount' => 'items_count', 'sequence' => 'sequence', 'createdAt' => 'created_at', 'updatedAt' => 'updated_at'];

    public const MAX_QTY = 9999999;

    public function __construct(private readonly AuditService $audit) {}

    // ───────────── reading ─────────────

    /** @param  array{customer?:?string, branch?:?string, state?:?string, origin?:?string, linked?:?string, product?:?string}  $f */
    public function lists(Paging $page, array $f): array
    {
        $query = $this->filtered($f, $page->q)->with(self::WITH);
        $column = self::SORTS[$page->sort ?? ''] ?? null;
        if ($column === 'updated_at') {
            $query->orderByRaw('updated_at IS NULL');
        }
        $column ? $query->orderBy($column, $page->order)->orderBy('id', $page->order) : $query->orderByDesc('created_at')->orderByDesc('id');

        return $page->paginate($query, fn (FavoriteList $l) => Shape::list($l));
    }

    public function getList(string $id): array
    {
        $l = $this->find($id);
        $owned = Owned::bySource($l);
        $customer = $l->customer_id ? $l->customer : null;

        return Shape::list($l, true) + [
            'events' => PortalLog::of('list', $l->id, 60),
            // what may be done to it now — the client shows a button only for what would be accepted
            'can' => [
                'edit' => ! $owned, 'assign' => (bool) $customer, 'items' => ! $owned && $l->active, 'archive' => ! $owned && $l->active, 'restore' => ! $owned && ! $l->active,
                'orderAgain' => (bool) $customer?->active && $l->active && $l->items_count > 0,
            ],
        ];
    }

    /**
     * One page of a list, in the list's order. `q` looks for a product inside the list; a position is the item's place
     * in the whole list and is only known while nothing is filtered.
     */
    public function items(string $listId, Paging $page): array
    {
        $l = $this->find($listId, []);
        $query = FavoriteItem::where('list_id', $l->id)->with([Parties::PRODUCT, 'product.baseUom:id,code'])->orderBy('sequence')->orderBy('id');
        if ($page->q !== null) {
            $like = Text::like($page->q);
            $query->where(fn ($w) => Parties::searchProduct($w, $like));
        }
        $first = ($page->page - 1) * $page->pageSize;
        $n = 0;

        return $page->paginate($query, function (FavoriteItem $i) use ($page, $first, &$n) {
            return Shape::item($i, $page->q === null ? $first + (++$n) : null);
        });
    }

    public function filtered(array $f, ?string $q): Builder
    {
        $query = FavoriteList::query();
        match ($f['state'] ?? 'active') {
            'archived' => $query->where('active', false),
            'all' => null,
            default => $query->where('active', true),
        };
        Parties::filterCustomer($query, $f['customer'] ?? null);
        Parties::filterLinked($query, $f['linked'] ?? null);
        Parties::filterOrigin($query, $f['origin'] ?? null);
        if (! empty($f['branch'])) {
            $f['branch'] === 'none' ? $query->whereNull('branch_id') : $query->where('branch_id', $f['branch']);
        }
        if (! empty($f['product'])) {
            // the lists a product is on, straight from the index (product, list)
            $ids = Product::where('id', $f['product'])->orWhere('sku', $f['product'])->pluck('id');
            $query->whereIn('id', FavoriteItem::query()->whereIn('product_id', $ids->all() ?: ['-'])->select('list_id'));
        }
        if ($q !== null && $q !== '') {
            $like = Text::like($q);
            $op = Sql::like();
            $query->where(function ($w) use ($like, $op) {
                $w->where('name', $op, $like);
                Parties::searchCustomer($w, $like);
            });
        }

        return $query;
    }

    // ───────────── lists ─────────────

    /** @param  array{customerId:string, name:string, branchId?:?string, notes?:?string, items?:array<int, array{sku:string, qty?:float|int|string|null}>}  $dto */
    public function createList(AuthUser $user, array $dto): array
    {
        $id = DB::transaction(function () use ($user, $dto) {
            $customer = Parties::customer($dto['customerId'] ?? null);
            if (! $customer->active) {
                throw AppError::rule('CUSTOMER_INACTIVE', "العميل {$customer->name_ar} ({$customer->code}) موقوف — لا تُنشأ له قائمة", "Customer {$customer->code} is inactive");
            }
            $name = Text::clean($dto['name'] ?? null, 190) ?? throw AppError::validation('PORTAL_LIST_NAME', 'اسم القائمة إلزامي', 'The list needs a name', [['path' => 'name', 'message' => 'هذا الحقل إلزامي']]);
            $this->nameIsFree($customer->id, $name, null);
            $branch = ! empty($dto['branchId']) ? Parties::branch($dto['branchId'], $customer) : null;
            $l = FavoriteList::create([
                'name' => $name, 'customer_id' => $customer->id, 'branch_id' => $branch?->id, 'notes' => Text::clean($dto['notes'] ?? null, 4000), 'sequence' => 10, 'items_count' => 0, 'active' => true,
                'created_by' => $user->username, 'updated_by' => $user->username,
            ]);
            $seen = [];
            foreach (array_values((array) ($dto['items'] ?? [])) as $i => $item) {
                $p = $this->product($item['sku'] ?? null, "items.{$i}.sku");
                if (isset($seen[$p->id])) {
                    throw AppError::validation('PORTAL_FAVORITE_EXISTS', "المنتج {$p->sku} مكرر في القائمة", "Product {$p->sku} is listed twice", [['path' => "items.{$i}.sku", 'message' => 'مكرر']]);
                }
                $seen[$p->id] = true;
                FavoriteItem::create(['list_id' => $l->id, 'product_id' => $p->id, 'qty' => self::quantity($item['qty'] ?? 1, "items.{$i}.qty"), 'sequence' => ($i + 1) * 10, 'created_by' => $user->username]);
            }
            $l->update(['items_count' => count($seen)]);
            PortalLog::add('list', $l->id, 'created', $user, ['data' => ['items' => count($seen)]]);
            $this->audit->log($user, ['action' => 'PORTAL.FAVLIST.CREATE', 'entityType' => 'FavoriteList', 'entityId' => $l->id, 'entityNumber' => $l->name,
                'newValue' => ['customer' => $customer->code, 'name' => $name, 'branch' => $branch?->name, 'items' => count($seen)]]);

            return $l->id;
        });

        return $this->getList($id);
    }

    /** @param  array{name?:?string, sequence?:?int, branchId?:?string, notes?:?string}  $dto */
    public function updateList(AuthUser $user, string $id, array $dto): array
    {
        DB::transaction(function () use ($user, $id, $dto) {
            $l = $this->locked($id);
            $data = [];
            if (array_key_exists('name', $dto)) {
                $data['name'] = Text::clean($dto['name'], 190) ?? throw AppError::validation('PORTAL_LIST_NAME', 'اسم القائمة إلزامي', 'The list needs a name', [['path' => 'name', 'message' => 'هذا الحقل إلزامي']]);
            }
            if (array_key_exists('sequence', $dto) && $dto['sequence'] !== null) {
                $data['sequence'] = (int) $dto['sequence'];
            }
            if (array_key_exists('notes', $dto)) {
                $data['notes'] = Text::clean($dto['notes'], 4000);
            }
            if (array_key_exists('branchId', $dto)) {
                if ($dto['branchId']) {
                    $customer = $l->customer_id ? Customer::find($l->customer_id) : null;
                    if (! $customer) {
                        throw AppError::rule('PORTAL_NO_CUSTOMER', 'صاحب هذه القائمة ليس عميلًا في نظام العمليات بعد — لا فروع له هنا', 'The owner of this list is not an OPS customer yet — it has no branches here');
                    }
                    $data['branch_id'] = Parties::branch($dto['branchId'], $customer)->id;
                } else {
                    $data['branch_id'] = null;
                }
            }
            $changes = Shape::changes($l, $data);
            if (! $changes) {
                return;
            }
            $sources = array_values(array_intersect(array_keys($changes), ['name', 'sequence']));
            if ($sources) {
                Owned::refuse($l, 'list', $sources);
            }
            if (isset($changes['name']) && $l->customer_id) {
                $this->nameIsFree($l->customer_id, $changes['name'], $l->id);
            }
            $old = array_map(fn (string $column) => $l->getAttribute($column), array_combine(array_keys($changes), array_keys($changes)));
            $l->update($changes + ['updated_by' => $user->username]);
            PortalLog::add('list', $l->id, 'updated', $user, ['data' => ['fields' => array_map(fn (string $c) => Shape::camel($c), array_keys($changes))]]);
            $this->audit->log($user, ['action' => 'PORTAL.FAVLIST.UPDATE', 'entityType' => 'FavoriteList', 'entityId' => $l->id, 'entityNumber' => $l->name, 'oldValue' => $old, 'newValue' => $changes]);
        });

        return $this->getList($id);
    }

    public function archiveList(AuthUser $user, string $id, ?string $reason): array
    {
        DB::transaction(function () use ($user, $id, $reason) {
            $l = $this->locked($id);
            Owned::refuse($l, 'list');
            if (! $l->active) {
                throw AppError::rule('PORTAL_LIST_ARCHIVED', "القائمة «{$l->name}» مؤرشفة أصلًا", "List \"{$l->name}\" is already archived");
            }
            $reason = Text::clean($reason, 500);
            $l->update(['active' => false, 'archived_at' => now(), 'archived_by' => $user->username, 'archive_reason' => $reason, 'updated_by' => $user->username]);
            PortalLog::add('list', $l->id, 'archived', $user, ['note' => $reason]);
            $this->audit->status($user, 'FavoriteList', $l->id, $l->name, 'active', 'archived', $reason);
        });

        return $this->getList($id);
    }

    public function restoreList(AuthUser $user, string $id): array
    {
        DB::transaction(function () use ($user, $id) {
            $l = $this->locked($id);
            Owned::refuse($l, 'list');
            if ($l->active) {
                throw AppError::rule('PORTAL_LIST_ACTIVE', "القائمة «{$l->name}» نشطة أصلًا", "List \"{$l->name}\" is already active");
            }
            if ($l->customer_id) {
                $this->nameIsFree($l->customer_id, $l->name, $l->id);
            }
            $l->update(['active' => true, 'archived_at' => null, 'archived_by' => null, 'archive_reason' => null, 'updated_by' => $user->username]);
            PortalLog::add('list', $l->id, 'restored', $user);
            $this->audit->status($user, 'FavoriteList', $l->id, $l->name, 'archived', 'active');
        });

        return $this->getList($id);
    }

    // ───────────── items ─────────────

    /**
     * Adds a product at the end of the list.
     *
     * @param  array{sku:string, qty?:float|int|string|null}  $dto
     * @return array{list:array, item:array}
     */
    public function addItem(AuthUser $user, string $listId, array $dto): array
    {
        $itemId = DB::transaction(function () use ($user, $listId, $dto) {
            $l = $this->editable($listId);
            $p = $this->product($dto['sku'] ?? null, 'sku');
            if (FavoriteItem::where('list_id', $l->id)->where('product_id', $p->id)->exists()) {
                throw AppError::rule('PORTAL_FAVORITE_EXISTS', "المنتج {$p->name_ar} ({$p->sku}) موجود في القائمة — عدّل كميته", "Product {$p->sku} is already on the list — change its quantity", ['sku' => $p->sku]);
            }
            $count = FavoriteItem::where('list_id', $l->id)->count();
            if ($count >= FavoriteList::MAX_ITEMS) {
                throw AppError::rule('PORTAL_LIST_FULL', 'القائمة بلغت حدها ('.FavoriteList::MAX_ITEMS.' منتج) — أنشئ قائمة أخرى', 'The list is full ('.FavoriteList::MAX_ITEMS.' products) — start another list', ['max' => FavoriteList::MAX_ITEMS]);
            }
            $qty = self::quantity($dto['qty'] ?? 1, 'qty');
            $last = (int) FavoriteItem::where('list_id', $l->id)->max('sequence');
            $item = FavoriteItem::create(['list_id' => $l->id, 'product_id' => $p->id, 'qty' => $qty, 'sequence' => $last + 10, 'created_by' => $user->username]);
            $l->update(['items_count' => $count + 1, 'updated_by' => $user->username]);
            PortalLog::add('list', $l->id, 'item_added', $user, ['data' => ['sku' => $p->sku, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en, 'qty' => Text::qty($qty)]]);
            $this->audit->log($user, ['action' => 'PORTAL.FAVORITE.ADD', 'entityType' => 'FavoriteList', 'entityId' => $l->id, 'entityNumber' => $l->name, 'newValue' => ['sku' => $p->sku, 'qty' => $qty]]);

            return $item->id;
        });

        return ['list' => $this->getList($listId), 'item' => Shape::item(FavoriteItem::with([Parties::PRODUCT, 'product.baseUom:id,code'])->findOrFail($itemId))];
    }

    /** The usual quantity of one item. */
    public function updateItem(AuthUser $user, string $listId, string $itemId, array $dto): array
    {
        DB::transaction(function () use ($user, $listId, $itemId, $dto) {
            $l = $this->editable($listId);
            $item = $this->item($l, $itemId);
            $qty = self::quantity($dto['qty'] ?? null, 'qty');
            if (Shape::same($item->qty, $qty)) {
                return;
            }
            $old = Text::qty($item->qty);
            $item->update(['qty' => $qty]);
            $l->update(['updated_by' => $user->username]);
            $p = $item->product_id ? Product::find($item->product_id) : null;
            PortalLog::add('list', $l->id, 'item_qty', $user, ['data' => ['sku' => $p?->sku, 'nameAr' => $p?->name_ar ?? $item->product_name, 'nameEn' => $p?->name_en ?? $item->product_name, 'from' => $old, 'to' => Text::qty($qty)]]);
            $this->audit->log($user, ['action' => 'PORTAL.FAVORITE.QTY', 'entityType' => 'FavoriteList', 'entityId' => $l->id, 'entityNumber' => $l->name, 'field' => $p?->sku ?? $item->product_name,
                'oldValue' => $old, 'newValue' => Text::qty($qty)]);
        });

        return ['list' => $this->getList($listId), 'item' => Shape::item(FavoriteItem::with([Parties::PRODUCT, 'product.baseUom:id,code'])->findOrFail($itemId))];
    }

    public function removeItem(AuthUser $user, string $listId, string $itemId): array
    {
        DB::transaction(function () use ($user, $listId, $itemId) {
            $l = $this->editable($listId);
            $item = $this->item($l, $itemId);
            $p = $item->product_id ? Product::find($item->product_id) : null;
            $facts = ['sku' => $p?->sku, 'nameAr' => $p?->name_ar ?? $item->product_name, 'nameEn' => $p?->name_en ?? $item->product_name, 'qty' => Text::qty($item->qty)];
            $item->delete();
            $l->update(['items_count' => FavoriteItem::where('list_id', $l->id)->count(), 'updated_by' => $user->username]);
            PortalLog::add('list', $l->id, 'item_removed', $user, ['data' => $facts]);
            $this->audit->log($user, ['action' => 'PORTAL.FAVORITE.REMOVE', 'entityType' => 'FavoriteList', 'entityId' => $l->id, 'entityNumber' => $l->name, 'oldValue' => $facts]);
        });

        return $this->getList($listId);
    }

    /**
     * Puts an item at a place of the list (1 = first). The list is renumbered in steps of ten; only the rows whose
     * number changes are written, so moving an item a few places touches a few rows.
     */
    public function moveItem(AuthUser $user, string $listId, string $itemId, int $position): array
    {
        DB::transaction(function () use ($user, $listId, $itemId, $position) {
            $l = $this->editable($listId);
            $rows = FavoriteItem::where('list_id', $l->id)->orderBy('sequence')->orderBy('id')->get(['id', 'sequence'])->all();
            $from = null;
            foreach ($rows as $i => $row) {
                if ($row->id === $itemId) {
                    $from = $i;
                    break;
                }
            }
            if ($from === null) {
                throw AppError::notFound('PORTAL_FAVORITE_NOT_FOUND', 'المنتج غير موجود في هذه القائمة', 'The product is not on this list');
            }
            $to = max(0, min(count($rows) - 1, $position - 1));
            $moved = array_splice($rows, $from, 1);
            array_splice($rows, $to, 0, $moved);
            foreach ($rows as $i => $row) {
                if ((int) $row->sequence !== ($i + 1) * 10) {
                    FavoriteItem::whereKey($row->id)->update(['sequence' => ($i + 1) * 10]);
                }
            }
            if ($from !== $to) {
                $l->update(['updated_by' => $user->username]);
                $this->audit->log($user, ['action' => 'PORTAL.FAVORITE.MOVE', 'entityType' => 'FavoriteList', 'entityId' => $l->id, 'entityNumber' => $l->name, 'field' => $itemId, 'oldValue' => $from + 1, 'newValue' => $to + 1]);
            }
        });

        return $this->getList($listId);
    }

    // ───────────── pieces ─────────────

    public function find(string $id, array $with = self::WITH): FavoriteList
    {
        return FavoriteList::with($with)->find($id) ?? throw AppError::notFound('PORTAL_LIST_NOT_FOUND', 'قائمة المفضلة غير موجودة', 'Favourite list not found');
    }

    /** The list, its row held for the rest of the transaction (two people cannot renumber or count it at once). */
    private function locked(string $id): FavoriteList
    {
        return FavoriteList::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('PORTAL_LIST_NOT_FOUND', 'قائمة المفضلة غير موجودة', 'Favourite list not found');
    }

    /** A list whose items may be changed here and now. */
    private function editable(string $id): FavoriteList
    {
        $l = $this->locked($id);
        Owned::refuse($l, 'list', ['items']);
        if (! $l->active) {
            throw AppError::rule('PORTAL_LIST_ARCHIVED', "القائمة «{$l->name}» مؤرشفة — استعدها قبل تعديل منتجاتها", "List \"{$l->name}\" is archived — restore it before changing its products");
        }

        return $l;
    }

    private function item(FavoriteList $l, string $itemId): FavoriteItem
    {
        return FavoriteItem::where('list_id', $l->id)->whereKey($itemId)->first()
            ?? throw AppError::notFound('PORTAL_FAVORITE_NOT_FOUND', 'المنتج غير موجود في هذه القائمة', 'The product is not on this list');
    }

    /** A product that may be put on a list: it exists and is sold. */
    private function product(?string $key, string $field): Product
    {
        $p = Parties::product($key, $field);
        if (! $p->active) {
            throw AppError::validation('SKU_INACTIVE', "المنتج {$p->name_ar} ({$p->sku}) موقوف", "Product {$p->sku} is inactive", [['path' => $field, 'message' => 'منتج موقوف']]);
        }

        return $p;
    }

    public static function quantity(mixed $v, string $field): float
    {
        $qty = is_numeric($v) ? round((float) $v, 3) : 0.0;
        if ($qty <= 0 || $qty > self::MAX_QTY) {
            throw AppError::validation('PORTAL_QTY', 'الكمية يجب أن تكون أكبر من صفر', 'The quantity must be greater than zero', [['path' => $field, 'message' => 'كمية غير صالحة']]);
        }

        return $qty;
    }

    /** A customer never has two active lists of one name. */
    private function nameIsFree(string $customerId, string $name, ?string $except): void
    {
        $twin = FavoriteList::where('customer_id', $customerId)->where('active', true)->when($except, fn ($q) => $q->where('id', '!=', $except))->get(['id', 'name'])
            ->first(fn (FavoriteList $x) => Text::nameKey($x->name) === Text::nameKey($name));
        if ($twin) {
            throw AppError::rule('PORTAL_LIST_EXISTS', "للعميل قائمة نشطة بالاسم نفسه «{$twin->name}»", "The customer already has an active list named \"{$twin->name}\"", ['listId' => $twin->id]);
        }
    }
}
