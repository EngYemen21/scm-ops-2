<?php

namespace App\Pricing\Services;

use App\Models\Product;
use App\Pricing\Models\PriceList;
use App\Pricing\Models\PriceListCustomer;
use App\Pricing\Models\PriceRule;
use App\Pricing\Support\Day;
use App\Pricing\Support\Find;
use App\Pricing\Support\Owned;
use App\Pricing\Support\Shape;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Customer price lists and their rules. Payloads are camelCase and already validated for shape by the controller; the
 * rules of the business are here. Nothing is deleted: a list or a rule that is no longer wanted is archived and stays
 * readable (and a price that was given with it stays explainable).
 *
 * What a list refuses: being archived while it is the default list, while customers are priced with it or while
 * another list's rule starts from it; a second rule with the same target, minimum quantity and overlapping period
 * (one of the two would never be used); a rule that starts from a list which starts from this one (a loop).
 * What came from Odoo is read-only while the section follows Odoo (422 PRICING_OWNED_BY_SOURCE) — `duplicate` makes an
 * OPS copy of any list.
 */
class PriceListsService
{
    /** The most lines one "set prices" call takes (a pasted sheet). */
    public const BULK_LIMIT = 500;

    private const RULE_WITH = ['product:id,sku,name_ar,name_en,active', 'category:id,code,name_ar,name_en', 'baseList'];

    public function __construct(private readonly AuditService $audit, private readonly NumberingService $numbering) {}

    // ───────────── lists ─────────────

    /** @param  array{state?:?string, source?:?string, currency?:?string}  $f  state: active (default) | archived | all · source: odoo | ops */
    public function list(Paging $page, array $f): array
    {
        $query = $this->counted(PriceList::query());
        match ($f['state'] ?? 'active') {
            'archived' => $query->where('active', false),
            'all' => null,
            default => $query->where('active', true),
        };
        if (($f['source'] ?? null) === 'odoo') {
            $query->where('source_system', 'odoo');
        } elseif (($f['source'] ?? null) === 'ops') {
            $query->whereNull('source_system');
        }
        if (! empty($f['currency'])) {
            $query->where('currency', strtoupper((string) $f['currency']));
        }
        if ($page->q) {
            $like = '%'.$page->q.'%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like));
        }
        $sortable = ['name' => 'name', 'code' => 'code', 'rulesCount' => 'rules_count', 'customersCount' => 'customers_count', 'updatedAt' => 'updated_at', 'sequence' => 'sequence'];
        if (isset($sortable[$page->sort ?? ''])) {
            $query->orderBy($sortable[$page->sort], $page->order);
        } else {
            $query->orderByDesc('is_default')->orderByDesc('customers_count')->orderBy('name');
        }
        $today = Day::today();

        return $page->paginate($query->orderBy('id'), fn (PriceList $l) => Shape::list($l, $today));
    }

    public function get(string $idOrCode): array
    {
        $l = $this->counted(PriceList::query())->where(fn ($w) => $w->where('id', $idOrCode)->orWhere('code', $idOrCode))->first()
            ?? throw AppError::notFound('PRICE_LIST_NOT_FOUND', 'قائمة الأسعار غير موجودة', 'Price list not found');

        return Shape::list($l) + [
            'pendingRules' => PriceRule::where('price_list_id', $l->id)->where('active', true)
                ->where(fn ($w) => $w->where(fn ($x) => $x->where('applies_to', 'product')->whereNull('product_id'))->orWhere(fn ($x) => $x->where('applies_to', 'category')->whereNull('category_id')))->count(),
            // what stands in the way of archiving it — shown before the button is pressed
            'baseFor' => $this->basedOn($l->id)->count(),
            'extra' => $l->extra,
        ];
    }

    public function create(AuthUser $user, array $dto): array
    {
        $id = DB::transaction(function () use ($user, $dto) {
            $values = $this->listValues($dto, null);
            if (! empty($dto['isDefault'])) {
                $this->releaseDefault($user, null);
            }
            $l = PriceList::create($values + [
                'code' => $this->numbering->next('PRL'), 'is_default' => ! empty($dto['isDefault']), 'sequence' => (int) ($dto['sequence'] ?? ((int) PriceList::max('sequence') + 1)),
                'active' => true, 'created_by' => $user->username,
            ]);
            $this->audit->log($user, ['action' => 'PRICING.LIST.CREATE', 'entityType' => 'PriceList', 'entityId' => $l->id, 'entityNumber' => $l->code, 'newValue' => $dto]);

            return $l->id;
        });

        return $this->get($id);
    }

    public function update(AuthUser $user, string $idOrCode, array $dto): array
    {
        $id = DB::transaction(function () use ($user, $idOrCode, $dto) {
            $l = Find::list($idOrCode, true);
            Owned::refuse($l, 'list');
            $values = $this->listValues($dto, $l);
            if (isset($values['currency']) && strcasecmp($values['currency'], $l->currency) !== 0
                && (PriceRule::where('price_list_id', $l->id)->where('active', true)->exists() || $this->basedOn($l->id)->exists())) {
                throw AppError::rule('PRICE_LIST_CURRENCY_LOCKED', 'لا تُغيَّر عملة قائمة فيها قواعد أو تعتمد عليها قائمة أخرى — أنشئ قائمة جديدة بالعملة المطلوبة', 'The currency of a list that has rules, or that another list starts from, cannot change — create a new list in that currency');
            }
            if (array_key_exists('sequence', $dto) && $dto['sequence'] !== null) {
                $values['sequence'] = (int) $dto['sequence'];
            }
            $this->save($user, $l, $values, 'PRICING.LIST.UPDATE', 'PriceList', $l->code);

            return $l->id;
        });

        return $this->get($id);
    }

    public function archive(AuthUser $user, string $idOrCode, ?string $reason = null): array
    {
        $id = DB::transaction(function () use ($user, $idOrCode, $reason) {
            $l = Find::list($idOrCode, true);
            Owned::refuse($l, 'list');
            if ($l->active) {
                if ($l->is_default) {
                    throw AppError::rule('PRICE_LIST_IS_DEFAULT', 'هذه هي القائمة الافتراضية — اجعل قائمة أخرى افتراضية قبل أرشفتها', 'This is the default list — make another list the default first');
                }
                $customers = PriceListCustomer::where('price_list_id', $l->id)->count();
                if ($customers > 0) {
                    throw AppError::rule('PRICE_LIST_IN_USE', "{$customers} عميلًا يُسعَّرون بهذه القائمة — انقلهم إلى قائمة أخرى أو ألغِ إسنادهم أولًا", "{$customers} customers are priced with this list — move or unassign them first", ['customers' => $customers]);
                }
                $based = $this->basedOn($l->id)->count();
                if ($based > 0) {
                    throw AppError::rule('PRICE_LIST_IS_BASE', "{$based} قاعدة في قوائم أخرى تبدأ من أسعار هذه القائمة — عدّلها أولًا", "{$based} rules of other lists start from this list's prices — change them first", ['rules' => $based]);
                }
                $l->update(['active' => false, 'updated_by' => $user->username]);
                $this->audit->status($user, 'PriceList', $l->id, $l->code, 'active', 'archived', $reason);
            }

            return $l->id;
        });

        return $this->get($id);
    }

    public function restore(AuthUser $user, string $idOrCode): array
    {
        $id = DB::transaction(function () use ($user, $idOrCode) {
            $l = Find::list($idOrCode, true);
            Owned::refuse($l, 'list');
            if (! $l->active) {
                $l->update(['active' => true, 'updated_by' => $user->username]);
                $this->audit->status($user, 'PriceList', $l->id, $l->code, 'archived', 'active');
            }

            return $l->id;
        });

        return $this->get($id);
    }

    /** The list of every customer that has none of its own. One at most; while the section follows Odoo, Odoo's default stands. */
    public function makeDefault(AuthUser $user, string $idOrCode): array
    {
        $id = DB::transaction(function () use ($user, $idOrCode) {
            $l = Find::list($idOrCode, true);
            Owned::refuse($l, 'list');
            if (! $l->active) {
                throw AppError::rule('PRICE_LIST_ARCHIVED', 'القائمة مؤرشفة — استعدها قبل جعلها افتراضية', 'The list is archived — restore it before making it the default');
            }
            if (! $l->is_default) {
                $this->releaseDefault($user, $l->id);
                $l->update(['is_default' => true, 'updated_by' => $user->username]);
                $this->audit->log($user, ['action' => 'PRICING.LIST.DEFAULT', 'entityType' => 'PriceList', 'entityId' => $l->id, 'entityNumber' => $l->code, 'field' => 'isDefault', 'oldValue' => false, 'newValue' => true]);
            }

            return $l->id;
        });

        return $this->get($id);
    }

    /** No default list any more: a customer without a list of its own pays the product's list price. */
    public function clearDefault(AuthUser $user, string $idOrCode): array
    {
        $id = DB::transaction(function () use ($user, $idOrCode) {
            $l = Find::list($idOrCode, true);
            Owned::refuse($l, 'list');
            if ($l->is_default) {
                $l->update(['is_default' => false, 'updated_by' => $user->username]);
                $this->audit->log($user, ['action' => 'PRICING.LIST.DEFAULT', 'entityType' => 'PriceList', 'entityId' => $l->id, 'entityNumber' => $l->code, 'field' => 'isDefault', 'oldValue' => true, 'newValue' => false]);
            }

            return $l->id;
        });

        return $this->get($id);
    }

    /**
     * A copy of a list as a list of OPS: same currency and validity, its active rules that can be used (a rule that
     * waits for a product is left behind). The way to price differently from a list Odoo owns.
     *
     * @param  array{name?:?string, note?:?string}  $dto
     */
    public function duplicate(AuthUser $user, string $idOrCode, array $dto): array
    {
        $id = DB::transaction(function () use ($user, $idOrCode, $dto) {
            $from = Find::list($idOrCode);
            $name = trim((string) ($dto['name'] ?? '')) ?: mb_substr($from->name, 0, 180).' — نسخة';
            $l = PriceList::create([
                'code' => $this->numbering->next('PRL'), 'name' => $name, 'currency' => $from->currency, 'is_default' => false, 'sequence' => (int) PriceList::max('sequence') + 1,
                'valid_from' => $from->valid_from, 'valid_to' => $from->valid_to, 'note' => ($dto['note'] ?? null) ?: "نسخة من {$from->code}", 'active' => true, 'created_by' => $user->username,
            ]);
            $seq = (int) PriceRule::max('seq');
            $copied = 0;
            $now = now()->format('Y-m-d H:i:s.v');
            PriceRule::where('price_list_id', $from->id)->where('active', true)->orderBy('seq')->orderBy('id')->chunk(300, function ($rules) use ($l, $user, &$seq, &$copied, $now) {
                $rows = [];
                foreach ($rules as $r) {
                    if ($r->pending()) {
                        continue;
                    }
                    $rows[] = [
                        'id' => $r->newUniqueId(), 'price_list_id' => $l->id, 'applies_to' => $r->applies_to, 'product_id' => $r->product_id, 'category_id' => $r->category_id,
                        'min_qty' => $r->min_qty, 'valid_from' => $r->valid_from?->format('Y-m-d'), 'valid_to' => $r->valid_to?->format('Y-m-d'), 'compute' => $r->compute, 'fixed_price' => $r->fixed_price,
                        'percent' => $r->percent, 'base' => $r->base, 'base_list_id' => $r->base_list_id, 'discount_pct' => $r->discount_pct, 'surcharge' => $r->surcharge, 'round_to' => $r->round_to,
                        'min_margin' => $r->min_margin, 'max_margin' => $r->max_margin, 'seq' => ++$seq, 'active' => true, 'note' => $r->note, 'created_by' => $user->username, 'created_at' => $now, 'updated_at' => $now,
                    ];
                    $copied++;
                }
                if ($rows) {
                    DB::table('price_list_rules')->insert($rows);
                }
            });
            $this->audit->log($user, ['action' => 'PRICING.LIST.DUPLICATE', 'entityType' => 'PriceList', 'entityId' => $l->id, 'entityNumber' => $l->code, 'newValue' => ['from' => $from->code, 'name' => $name, 'rules' => $copied]]);

            return $l->id;
        });

        return $this->get($id);
    }

    // ───────────── rules ─────────────

    /** @param  array{appliesTo?:?string, compute?:?string, state?:?string, product?:?string}  $f  state: active (default) | archived | pending | all */
    public function rules(string $listIdOrCode, Paging $page, array $f): array
    {
        $list = Find::list($listIdOrCode);
        $query = PriceRule::query()->with(self::RULE_WITH)->where('price_list_rules.price_list_id', $list->id)
            ->leftJoin('products', 'products.id', '=', 'price_list_rules.product_id')->select('price_list_rules.*');
        match ($f['state'] ?? 'active') {
            'archived' => $query->where('price_list_rules.active', false),
            'pending' => $query->where('price_list_rules.active', true)->where(fn ($w) => $w->where(fn ($x) => $x->where('applies_to', 'product')->whereNull('price_list_rules.product_id'))
                ->orWhere(fn ($x) => $x->where('applies_to', 'category')->whereNull('price_list_rules.category_id'))),
            'all' => null,
            default => $query->where('price_list_rules.active', true),
        };
        if (in_array($f['appliesTo'] ?? null, PriceRule::APPLIES, true)) {
            $query->where('applies_to', $f['appliesTo']);
        }
        if (in_array($f['compute'] ?? null, PriceRule::COMPUTES, true)) {
            $query->where('compute', $f['compute']);
        }
        if (! empty($f['product'])) {
            $query->where(fn ($w) => $w->where('products.id', $f['product'])->orWhere('products.sku', $f['product']));
        }
        if ($page->q) {
            $like = '%'.$page->q.'%';
            $query->where(fn ($w) => $w->where('products.sku', 'like', $like)->orWhere('products.name_ar', 'like', $like)->orWhere('products.name_en', 'like', $like)
                ->orWhere('price_list_rules.pending_label', 'like', $like)->orWhere('price_list_rules.note', 'like', $like)
                ->orWhereIn('price_list_rules.category_id', fn ($c) => $c->select('id')->from('product_categories')->where('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)));
        }
        $sortable = ['sku' => 'products.sku', 'name' => 'products.name_ar', 'minQty' => 'price_list_rules.min_qty', 'fixedPrice' => 'price_list_rules.fixed_price', 'updatedAt' => 'price_list_rules.updated_at', 'validTo' => 'price_list_rules.valid_to'];
        if (isset($sortable[$page->sort ?? ''])) {
            $query->orderBy($sortable[$page->sort], $page->order);
        } else {
            // the rules of everything and of categories first (few, and they decide the most), then the products by code
            $query->orderByRaw('products.sku IS NOT NULL')->orderBy('products.sku')->orderByDesc('price_list_rules.min_qty');
        }
        $today = Day::today();

        return $page->paginate($query->orderByDesc('price_list_rules.seq')->orderBy('price_list_rules.id'), fn (PriceRule $r) => Shape::rule($r, $today)) + ['list' => Shape::listRef($list) + Shape::origin($list)];
    }

    public function createRule(AuthUser $user, string $listIdOrCode, array $dto): array
    {
        $rule = DB::transaction(function () use ($user, $listIdOrCode, $dto) {
            $list = Find::list($listIdOrCode, true);
            Owned::refuse($list, 'list');
            $values = $this->ruleValues($list, $dto, null);
            $r = PriceRule::create($values + ['price_list_id' => $list->id, 'seq' => (int) PriceRule::max('seq') + 1, 'active' => true, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'PRICING.RULE.CREATE', 'entityType' => 'PriceRule', 'entityId' => $r->id, 'entityNumber' => $list->code, 'newValue' => $dto]);

            return $r;
        });

        return Shape::rule($rule->refresh()->load(self::RULE_WITH));
    }

    public function updateRule(AuthUser $user, string $ruleId, array $dto): array
    {
        return DB::transaction(function () use ($user, $ruleId, $dto) {
            $r = $this->findRule($ruleId, true);
            Owned::refuse($r, 'rule');
            $list = Find::list($r->price_list_id, true);
            Owned::refuse($list, 'list');
            $values = $this->ruleValues($list, $dto, $r);
            $changed = $this->save($user, $r, $values, 'PRICING.RULE.UPDATE', 'PriceRule', $list->code);

            return Shape::rule($r->refresh()->load(self::RULE_WITH)) + ['changed' => $changed];
        });
    }

    public function archiveRule(AuthUser $user, string $ruleId, ?string $reason = null): array
    {
        return DB::transaction(function () use ($user, $ruleId, $reason) {
            $r = $this->findRule($ruleId, true);
            Owned::refuse($r, 'rule');
            if ($r->active) {
                $r->update(['active' => false, 'updated_by' => $user->username]);
                $this->audit->status($user, 'PriceRule', $r->id, $r->priceList?->code, 'active', 'archived', $reason);
            }

            return Shape::rule($r->refresh()->load(self::RULE_WITH));
        });
    }

    public function restoreRule(AuthUser $user, string $ruleId): array
    {
        return DB::transaction(function () use ($user, $ruleId) {
            $r = $this->findRule($ruleId, true);
            Owned::refuse($r, 'rule');
            if (! $r->active) {
                $list = Find::list($r->price_list_id, true);
                $this->refuseTwin($list, ['applies_to' => $r->applies_to, 'product_id' => $r->product_id, 'category_id' => $r->category_id, 'min_qty' => $r->min_qty,
                    'valid_from' => $r->valid_from, 'valid_to' => $r->valid_to], $r->id);
                $r->update(['active' => true, 'updated_by' => $user->username]);
                $this->audit->status($user, 'PriceRule', $r->id, $list->code, 'archived', 'active');
            }

            return Shape::rule($r->refresh()->load(self::RULE_WITH));
        });
    }

    /**
     * Fixed prices for many products at once — a pasted sheet of "code, price". For each line the list's open-ended
     * fixed rule of that product and minimum quantity is changed, or one is added. All or nothing: an unknown code or a
     * repeated line refuses the whole call and names every faulty line.
     *
     * @param  array<int, array{sku:string, price:float|int|string, minQty?:float|int|string|null}>  $items
     * @return array{created:int, updated:int, unchanged:int, list:array}
     */
    public function setPrices(AuthUser $user, string $listIdOrCode, array $items): array
    {
        return DB::transaction(function () use ($user, $listIdOrCode, $items) {
            $list = Find::list($listIdOrCode, true);
            Owned::refuse($list, 'list');
            $products = Product::query()->whereIn('sku', array_values(array_unique(array_map(fn ($i) => (string) $i['sku'], $items))))->get(['id', 'sku'])
                ->keyBy(fn (Product $p) => mb_strtolower($p->sku));
            $errors = [];
            $seen = [];
            foreach ($items as $i => $item) {
                $p = $products[mb_strtolower(trim((string) $item['sku']))] ?? null;
                if (! $p) {
                    $errors[] = ['path' => "items.{$i}.sku", 'message' => "منتج غير معروف: {$item['sku']}"];

                    continue;
                }
                $key = $p->id.'|'.round((float) ($item['minQty'] ?? 0), 3);
                if (isset($seen[$key])) {
                    $errors[] = ['path' => "items.{$i}.sku", 'message' => "السطر مكرر: {$item['sku']}"];
                }
                $seen[$key] = true;
            }
            if ($errors) {
                throw AppError::validation('PRICE_LINES_INVALID', 'في الأسطر أخطاء — لم يُحفظ شيء', 'Some lines are faulty — nothing was saved', $errors);
            }
            $existing = PriceRule::where('price_list_id', $list->id)->where('active', true)->where('applies_to', 'product')->where('compute', 'fixed')
                ->whereNull('valid_from')->whereNull('valid_to')->whereIn('product_id', $products->pluck('id')->all())->orderBy('seq')->orderBy('id')->get()
                ->keyBy(fn (PriceRule $r) => $r->product_id.'|'.round($r->min_qty, 3)); // of two twins the newer one (the one that is used) stays under the key
            $seq = (int) PriceRule::max('seq');
            $out = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
            foreach ($items as $item) {
                $p = $products[mb_strtolower(trim((string) $item['sku']))];
                $minQty = round((float) ($item['minQty'] ?? 0), 3);
                $price = round((float) $item['price'], 4);
                $r = $existing[$p->id.'|'.$minQty] ?? null;
                if ($r) {
                    Owned::refuse($r, 'rule');
                    if (abs((float) $r->fixed_price - $price) < 0.00005) {
                        $out['unchanged']++;

                        continue;
                    }
                    $old = $r->fixed_price;
                    $r->update(['fixed_price' => $price, 'updated_by' => $user->username]);
                    $this->audit->log($user, ['action' => 'PRICING.RULE.UPDATE', 'entityType' => 'PriceRule', 'entityId' => $r->id, 'entityNumber' => $list->code, 'field' => 'fixedPrice',
                        'oldValue' => ['sku' => $p->sku, 'fixedPrice' => $old], 'newValue' => ['sku' => $p->sku, 'fixedPrice' => $price]]);
                    $out['updated']++;

                    continue;
                }
                $r = PriceRule::create(['price_list_id' => $list->id, 'applies_to' => 'product', 'product_id' => $p->id, 'min_qty' => $minQty, 'compute' => 'fixed', 'fixed_price' => $price,
                    'base' => 'list', 'seq' => ++$seq, 'active' => true, 'created_by' => $user->username]);
                $this->audit->log($user, ['action' => 'PRICING.RULE.CREATE', 'entityType' => 'PriceRule', 'entityId' => $r->id, 'entityNumber' => $list->code, 'newValue' => ['sku' => $p->sku, 'fixedPrice' => $price, 'minQty' => $minQty, 'via' => 'bulk']]);
                $out['created']++;
            }

            return $out + ['list' => Shape::listRef($list)];
        });
    }

    // ───────────── inside ─────────────

    private function counted(Builder $query): Builder
    {
        return $query->withCount(['rules', 'rules as active_rules_count' => fn ($q) => $q->where('active', true), 'customers']);
    }

    /** The active rules of OTHER lists that start from this list's prices. */
    private function basedOn(string $listId): Builder
    {
        return PriceRule::query()->where('base', 'pricelist')->where('base_list_id', $listId)->where('active', true)->where('price_list_id', '!=', $listId);
    }

    /** The current default list gives the title up (Odoo's default stands while the section follows Odoo). */
    private function releaseDefault(AuthUser $user, ?string $exceptId): void
    {
        foreach (PriceList::where('is_default', true)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->lockForUpdate()->get() as $was) {
            Owned::refuse($was, 'list');
            $was->update(['is_default' => false, 'updated_by' => $user->username]);
            $this->audit->log($user, ['action' => 'PRICING.LIST.DEFAULT', 'entityType' => 'PriceList', 'entityId' => $was->id, 'entityNumber' => $was->code, 'field' => 'isDefault', 'oldValue' => true, 'newValue' => false]);
        }
    }

    /** The columns a list payload sets. A key that is absent is left as it is. */
    private function listValues(array $dto, ?PriceList $l): array
    {
        $v = [];
        if (array_key_exists('name', $dto) || ! $l) {
            $v['name'] = trim((string) ($dto['name'] ?? ''));
            if ($v['name'] === '') {
                throw AppError::validation('PRICE_LIST_NAME', 'اسم القائمة إلزامي', 'The list needs a name', [['path' => 'name', 'message' => 'هذا الحقل إلزامي']]);
            }
        }
        if (array_key_exists('currency', $dto) || ! $l) {
            $v['currency'] = strtoupper(trim((string) ($dto['currency'] ?? ''))) ?: ($l?->currency ?? 'SAR');
        }
        foreach (['validFrom' => 'valid_from', 'validTo' => 'valid_to'] as $key => $column) {
            if (array_key_exists($key, $dto)) {
                $v[$column] = $dto[$key] ? Day::of($dto[$key])->toDateString() : null;
            }
        }
        if (array_key_exists('note', $dto)) {
            $v['note'] = trim((string) $dto['note']) ?: null;
        }
        $from = array_key_exists('valid_from', $v) ? $v['valid_from'] : $l?->valid_from?->format('Y-m-d');
        $to = array_key_exists('valid_to', $v) ? $v['valid_to'] : $l?->valid_to?->format('Y-m-d');
        if ($from && $to && $from > $to) {
            throw AppError::validation('PRICE_PERIOD', 'نهاية الصلاحية قبل بدايتها', 'The validity ends before it starts', [['path' => 'validTo', 'message' => 'يجب أن يكون بعد تاريخ البداية']]);
        }

        return $v;
    }

    private function findRule(string $id, bool $lock = false): PriceRule
    {
        return PriceRule::whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->first()
            ?? throw AppError::notFound('PRICE_RULE_NOT_FOUND', 'القاعدة غير موجودة', 'Rule not found');
    }

    /**
     * The columns of a rule from a payload laid over what the rule holds (a change sends only what changes), checked
     * as a whole: its target, its numbers, its period, its base — and that it has no twin and closes no loop.
     */
    private function ruleValues(PriceList $list, array $dto, ?PriceRule $r): array
    {
        $has = fn (string $k) => array_key_exists($k, $dto);
        $bad = fn (string $path, string $ar, string $en) => AppError::validation('PRICE_RULE_INVALID', $ar, $en, [['path' => $path, 'message' => $ar]]);

        $applies = $has('appliesTo') ? (string) $dto['appliesTo'] : ($r?->applies_to ?? 'product');
        if (! in_array($applies, PriceRule::APPLIES, true)) {
            throw $bad('appliesTo', 'اختر ما تنطبق عليه القاعدة: منتج أو فئة أو كل المنتجات', 'Choose what the rule covers: a product, a category or everything');
        }
        $productId = $categoryId = null;
        if ($applies === 'product') {
            $given = $has('product') ? trim((string) $dto['product']) : null;
            $productId = $given !== null && $given !== '' ? Find::product($given)->id : ($has('product') ? null : ($r?->applies_to === 'product' ? $r->product_id : null));
            if (! $productId) {
                throw $bad('product', 'حدد المنتج', 'Pick the product');
            }
        } elseif ($applies === 'category') {
            $given = $has('category') ? trim((string) $dto['category']) : null;
            $categoryId = $given !== null && $given !== '' ? Find::category($given)->id : ($has('category') ? null : ($r?->applies_to === 'category' ? $r->category_id : null));
            if (! $categoryId) {
                throw $bad('category', 'حدد الفئة', 'Pick the category');
            }
        }

        $num = fn (string $k, float $current) => $has($k) && $dto[$k] !== null && $dto[$k] !== '' ? (float) $dto[$k] : ($has($k) ? 0.0 : $current);
        $minQty = round($num('minQty', (float) ($r?->min_qty ?? 0)), 3);
        if ($minQty < 0) {
            throw $bad('minQty', 'الحد الأدنى للكمية لا يكون سالبًا', 'The minimum quantity cannot be negative');
        }
        $from = $has('validFrom') ? ($dto['validFrom'] ? Day::of($dto['validFrom'])->toDateString() : null) : $r?->valid_from?->format('Y-m-d');
        $to = $has('validTo') ? ($dto['validTo'] ? Day::of($dto['validTo'])->toDateString() : null) : $r?->valid_to?->format('Y-m-d');
        if ($from && $to && $from > $to) {
            throw $bad('validTo', 'نهاية المدة قبل بدايتها', 'The period ends before it starts');
        }

        $compute = $has('compute') ? (string) $dto['compute'] : ($r?->compute ?? 'fixed');
        if (! in_array($compute, PriceRule::COMPUTES, true)) {
            throw $bad('compute', 'طريقة الحساب غير معروفة', 'Unknown way of computing');
        }
        $v = [
            'applies_to' => $applies, 'product_id' => $productId, 'category_id' => $categoryId, 'min_qty' => $minQty, 'valid_from' => $from, 'valid_to' => $to, 'compute' => $compute,
            'fixed_price' => null, 'percent' => null, 'base' => 'list', 'base_list_id' => null, 'discount_pct' => 0, 'surcharge' => 0, 'round_to' => 0, 'min_margin' => 0, 'max_margin' => 0,
            'pending_label' => null,
        ];
        if ($has('note') || ! $r) {
            $v['note'] = trim((string) ($dto['note'] ?? '')) ?: null;
        }
        if ($compute === 'fixed') {
            $v['fixed_price'] = round($num('fixedPrice', (float) ($r?->fixed_price ?? 0)), 4);
            if ($v['fixed_price'] <= 0) {
                throw $bad('fixedPrice', 'السعر الثابت يجب أن يكون أكبر من صفر', 'The fixed price must be greater than zero');
            }
        } else {
            $base = $has('base') && $dto['base'] ? (string) $dto['base'] : ($r && $r->compute !== 'fixed' ? $r->base : 'list');
            if (! in_array($base, PriceRule::BASES, true)) {
                throw $bad('base', 'أساس الحساب غير معروف', 'Unknown base');
            }
            $v['base'] = $base;
            if ($base === 'pricelist') {
                $given = $has('baseList') ? trim((string) $dto['baseList']) : null;
                $other = $given ? Find::list($given) : ($has('baseList') ? null : ($r?->base_list_id ? PriceList::find($r->base_list_id) : null));
                if (! $other) {
                    throw $bad('baseList', 'حدد القائمة التي يبدأ منها الحساب', 'Pick the list the price starts from');
                }
                if ($other->id === $list->id) {
                    throw $bad('baseList', 'القاعدة لا تبدأ من القائمة نفسها', 'A rule cannot start from its own list');
                }
                if (strcasecmp($other->currency, $list->currency) !== 0) {
                    throw AppError::rule('PRICE_RULE_CURRENCY', "قائمة الأساس «{$other->name}» بعملة {$other->currency} وهذه القائمة بعملة {$list->currency} — لا تحويل عملات في النظام", "The base list is in {$other->currency} and this list in {$list->currency} — OPS converts no currency");
                }
                if ($this->leadsTo($other->id, $list->id)) {
                    throw AppError::rule('PRICE_RULE_LOOP', "قائمة «{$other->name}» تبدأ أسعارها من هذه القائمة — القاعدة تصنع حلقة", "List “{$other->name}” starts from this list's prices — the rule would make a loop");
                }
                $v['base_list_id'] = $other->id;
            }
            if ($compute === 'percent') {
                $v['percent'] = round($num('percent', (float) ($r?->percent ?? 0)), 4);
                if ($v['percent'] == 0.0 || $v['percent'] > 100 || $v['percent'] < -1000) {
                    throw $bad('percent', 'نسبة الخصم بين 0 و100 (سالبة للزيادة) ولا تكون صفرًا', 'The discount is between 0 and 100 (negative for a mark-up) and not zero');
                }
            } else {
                $same = $r && $r->compute === 'formula';
                $v['discount_pct'] = round($num('discountPct', $same ? (float) $r->discount_pct : 0), 4);
                $v['surcharge'] = round($num('surcharge', $same ? (float) $r->surcharge : 0), 4);
                $v['round_to'] = round($num('roundTo', $same ? (float) $r->round_to : 0), 4);
                $v['min_margin'] = round($num('minMargin', $same ? (float) $r->min_margin : 0), 4);
                $v['max_margin'] = round($num('maxMargin', $same ? (float) $r->max_margin : 0), 4);
                if ($v['discount_pct'] > 100 || $v['discount_pct'] < -1000) {
                    throw $bad('discountPct', 'نسبة الخصم لا تزيد على 100', 'The discount cannot exceed 100%');
                }
                if ($v['round_to'] < 0) {
                    throw $bad('roundTo', 'مضاعف التقريب لا يكون سالبًا', 'The rounding multiple cannot be negative');
                }
                if ($v['min_margin'] != 0.0 && $v['max_margin'] != 0.0 && $v['min_margin'] > $v['max_margin']) {
                    throw $bad('maxMargin', 'الحد الأعلى أقل من الحد الأدنى', 'The ceiling is below the floor');
                }
                if ($v['discount_pct'] == 0.0 && $v['surcharge'] == 0.0 && $v['round_to'] == 0.0 && $v['min_margin'] == 0.0 && $v['max_margin'] == 0.0 && $base !== 'pricelist') {
                    throw $bad('discountPct', 'المعادلة لا تغيّر الأساس — أدخل خصمًا أو إضافة أو تقريبًا', 'The formula changes nothing — give a discount, a surcharge or a rounding');
                }
            }
        }
        if (! $r || $r->active) {
            $this->refuseTwin($list, $v, $r?->id);
        }

        return $v;
    }

    /**
     * Two active rules of one list with the same target, the same minimum quantity and periods that overlap: only the
     * newer would ever be used. The second is refused, and the answer names the first so the screen can open it.
     */
    private function refuseTwin(PriceList $list, array $v, ?string $exceptId): void
    {
        $from = $v['valid_from'] ?? null;
        $to = $v['valid_to'] ?? null;
        $from = $from instanceof \DateTimeInterface ? $from->format('Y-m-d') : ($from ? substr((string) $from, 0, 10) : null);
        $to = $to instanceof \DateTimeInterface ? $to->format('Y-m-d') : ($to ? substr((string) $to, 0, 10) : null);
        $min = round((float) $v['min_qty'], 3);
        $twin = PriceRule::where('price_list_id', $list->id)->where('active', true)->where('applies_to', $v['applies_to'])
            ->when($v['applies_to'] === 'product', fn ($q) => $q->where('product_id', $v['product_id']))
            ->when($v['applies_to'] === 'category', fn ($q) => $q->where('category_id', $v['category_id']))
            ->whereBetween('min_qty', [$min - 0.0005, $min + 0.0005])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->when($to, fn ($q) => $q->where(fn ($w) => $w->whereNull('valid_from')->orWhere('valid_from', '<=', $to)))
            ->when($from, fn ($q) => $q->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>=', $from)))
            ->orderByDesc('seq')->first();
        if ($twin) {
            throw new AppError('CONFLICT', 'PRICE_RULE_DUPLICATE', 'في القائمة قاعدة فعّالة للهدف نفسه والحد الأدنى للكمية نفسه ومدتها تتقاطع مع هذه — عدّلها بدل إضافة قاعدة ثانية',
                'The list already has an active rule for the same target and minimum quantity whose period overlaps — change that rule instead of adding a second one', ['ruleId' => $twin->id]);
        }
    }

    /** Does pricing with list `$from` end up asking list `$target` (through rules that start from another list)? */
    private function leadsTo(string $from, string $target): bool
    {
        $seen = [];
        $todo = [$from];
        while ($todo && count($seen) < 200) {
            $id = array_pop($todo);
            if ($id === $target) {
                return true;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach (PriceRule::where('price_list_id', $id)->where('active', true)->where('base', 'pricelist')->whereNotNull('base_list_id')->distinct()->pluck('base_list_id') as $next) {
                $todo[] = (string) $next;
            }
        }

        return false;
    }

    /** Writes what really changed, audits it (old → new). @return int how many values changed */
    private function save(AuthUser $user, PriceList|PriceRule $row, array $values, string $action, string $type, ?string $label): int
    {
        $old = $new = [];
        foreach ($values as $column => $value) {
            $current = $row->getAttribute($column);
            $current = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : $current;
            $same = is_numeric($value) && is_numeric($current) ? abs((float) $value - (float) $current) < 0.00005 : (string) ($current ?? '') === (string) ($value ?? '');
            if (! $same) {
                $old[$column] = $current;
                $new[$column] = $value;
            }
        }
        if ($new) {
            $row->update($new + ['updated_by' => $user->username]);
            $this->audit->log($user, ['action' => $action, 'entityType' => $type, 'entityId' => $row->getKey(), 'entityNumber' => $label, 'oldValue' => $old, 'newValue' => $new]);
        }

        return count($new);
    }
}
