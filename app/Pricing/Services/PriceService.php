<?php

namespace App\Pricing\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use App\Pricing\Models\PriceList;
use App\Pricing\Models\PriceListCustomer;
use App\Pricing\Models\PriceRule;
use App\Pricing\Models\ProductListPrice;
use App\Pricing\Models\SupplierPrice;
use App\Pricing\Support\Day;
use App\Pricing\Support\Find;
use App\Pricing\Support\Shape;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * THE price service — the one place OPS asks "what does it cost?". It reads and never writes.
 *
 *   customerPrice(C, P, Q, D)       the price of product P for customer C, quantity Q, on day D, and the rule that decided it
 *   customerPrices(C, lines, D)     the same for the lines of a document, in two queries
 *   listPrice(L, P, Q, D)           the price of P on one list, whoever the customer is
 *   bestSupplierPrice(P, Q, D)      the best supplier price of P for quantity Q on day D, and every other offer
 *   supplierPrice(S, P, Q, D)       what supplier S charges for P
 *
 * HOW A CUSTOMER PRICE IS FOUND (the way Odoo does it, so the same customer gets the same price in both systems):
 *  1. The list: the customer's own list when it is active and inside its validity; otherwise the default list;
 *     otherwise none.
 *  2. The rule: of the rules of that list that cover the product (the product itself, its category or a parent
 *     category, everything), are active, hold on that day and whose minimum quantity is reached, the first one in this
 *     order wins: product before category before "all"; then the highest minimum quantity; then the nearest category;
 *     then the newest rule.
 *  3. The price: fixed — the price itself; percent — the base less the percentage; formula — the base less a
 *     percentage, rounded to a multiple, plus a surcharge, kept between a floor and a ceiling measured from the base.
 *     The base is the product's list price, its cost (average cost, else purchase price) or its price on another list.
 *  4. No rule covers the product: its list price. No list price either: no price (`found = false`) — never a guess.
 *
 * Every answer carries `reason` (Arabic and English) and, with `$explain`, the steps of the calculation and the other
 * rules that were looked at with why each lost. Prices are unit prices with up to four decimals; a document that keeps
 * two rounds when it takes one.
 */
class PriceService
{
    /** How deep "price on another list" may chain before it is called a loop. */
    public const MAX_DEPTH = 4;

    /** @var array<string, ?string>|null category id → parent id */
    private ?array $parents = null;

    /** @var array<string, ?ProductListPrice> product id → its list price (null = none) */
    private array $listPrices = [];

    /** @var array<string, PriceRule[]> "list|product" → the rules that cover the product, best first */
    private array $rules = [];

    /** @var array<string, array> "customer|day" → the list the customer is priced with (the lines of one document ask once) */
    private array $customerLists = [];

    // ───────────── what a customer pays ─────────────

    /**
     * @return array{found:bool, price:?float, currency:string, source:string, qty:float|int, date:string, customer:?array, product:array, priceList:?array,
     *               assignment:?array, rule:?array, listPrice:?float, discountPct:?float, reason:array{ar:string,en:string}, notes:array, problem:?string,
     *               steps?:array, considered?:array}
     */
    public function customerPrice(string $customerId, string $productId, float|int $qty = 1, DateTimeInterface|string|null $date = null, bool $explain = false): array
    {
        $this->forget();

        return $this->quote(Find::customer($customerId), null, Find::product($productId), self::qty($qty), Day::of($date), $explain);
    }

    /**
     * The lines of one document. `$lines`: [['productId' | 'sku' => …, 'qty' => …], …] — answers come in the same order.
     * An unknown product gives `found = false` with the problem `PRODUCT_NOT_FOUND` for its line and does not stop the others.
     *
     * @return array<int, array>
     */
    public function customerPrices(string $customerId, array $lines, DateTimeInterface|string|null $date = null): array
    {
        $this->forget();
        $c = Find::customer($customerId);
        $day = Day::of($date);
        $keys = array_values(array_unique(array_map(fn ($l) => (string) ($l['productId'] ?? $l['sku'] ?? ''), $lines)));
        $found = Product::query()->where(fn ($w) => $w->whereIn('id', $keys)->orWhereIn('sku', $keys))->get();
        $byKey = [];
        foreach ($found as $p) {
            $byKey[mb_strtolower((string) $p->id)] = $byKey[mb_strtolower((string) $p->sku)] = $p;
        }
        [$list] = $this->listOf($c, $day);
        $this->prime($found, $list);
        $out = [];
        foreach ($lines as $l) {
            $key = (string) ($l['productId'] ?? $l['sku'] ?? '');
            $p = $byKey[mb_strtolower($key)] ?? null;
            $out[] = $p
                ? $this->quote($c, null, $p, self::qty($l['qty'] ?? 1), $day, false)
                : ['found' => false, 'price' => null, 'source' => 'none', 'problem' => 'PRODUCT_NOT_FOUND', 'product' => null, 'qty' => Shape::num($l['qty'] ?? 1),
                    'reason' => ['ar' => "المنتج {$key} غير موجود", 'en' => "Product {$key} not found"]];
        }

        return $out;
    }

    /** The price of a product on one list — a list being checked, or the base of another list's rule. */
    public function listPrice(string $listId, string $productId, float|int $qty = 1, DateTimeInterface|string|null $date = null, bool $explain = false): array
    {
        $this->forget();

        return $this->quote(null, Find::list($listId), Find::product($productId), self::qty($qty), Day::of($date), $explain);
    }

    private function quote(?Customer $c, ?PriceList $given, Product $p, float $qty, Carbon $day, bool $explain): array
    {
        $notes = [];
        $assignment = null;
        if ($given) {
            [$list, $via] = [$given, 'given'];
            if (! $given->usableOn($day)) {
                $notes[] = $given->active
                    ? ['ar' => "القائمة «{$given->name}» خارج مدة صلاحيتها في هذا التاريخ — لا تُستخدم للعملاء", 'en' => "List “{$given->name}” is outside its validity on that day — customers are not priced with it"]
                    : ['ar' => "القائمة «{$given->name}» مؤرشفة — لا تُستخدم للعملاء", 'en' => "List “{$given->name}” is archived — customers are not priced with it"];
            }
        } else {
            [$list, $via, $assignment, $notes] = $this->listOf($c, $day);
        }
        $res = $list ? $this->onList($list, $p, $qty, $day, $explain, [$list->id]) : $this->withoutList($p);
        $lp = $this->listPriceOf($p);
        $listPrice = $lp && $lp->price > 0 ? round($lp->price, 4) : null;
        $price = $res['found'] ? round(max(0.0, (float) $res['price']), 4) : null;
        if ($res['found'] && $price <= 0.0) {
            $notes[] = ['ar' => 'السعر الناتج صفر — راجع القاعدة قبل استخدامه في مستند', 'en' => 'The resulting price is zero — check the rule before a document uses it'];
        }
        $rule = $res['rule'] ?? null;
        $out = [
            'found' => $res['found'], 'price' => $price, 'currency' => $res['currency'] ?? ($list?->currency ?? $lp?->currency ?? 'SAR'), 'source' => $res['source'],
            'qty' => Shape::num($qty), 'date' => $day->toDateString(), 'customer' => Shape::party($c), 'product' => Shape::product($p),
            'priceList' => $list ? Shape::listRef($list) + ['via' => $via] : null, 'assignment' => Shape::assignment($assignment),
            'rule' => $rule ? Shape::rule($rule->loadMissing(self::RULE_WITH), $day) : null,
            'listPrice' => $listPrice, 'discountPct' => $price !== null && $listPrice ? round((1 - $price / $listPrice) * 100, 2) : null,
            'reason' => $res['reason'], 'notes' => $notes, 'problem' => $res['problem'] ?? null,
        ];
        if ($explain) {
            $out['steps'] = $res['steps'] ?? [];
            $out['considered'] = $res['considered'] ?? [];
        }

        return $out;
    }

    private const RULE_WITH = ['product:id,sku,name_ar,name_en,active', 'category:id,code,name_ar,name_en', 'baseList'];

    /**
     * The list a customer is priced with on that day.
     *
     * @return array{0:?PriceList, 1:string, 2:?PriceListCustomer, 3:array} [list, assigned | default | none, the customer's assignment, notes]
     */
    private function listOf(Customer $c, Carbon $day): array
    {
        return $this->customerLists["{$c->id}|{$day->toDateString()}"] ??= $this->findListOf($c, $day);
    }

    private function findListOf(Customer $c, Carbon $day): array
    {
        $notes = [];
        $a = PriceListCustomer::with('priceList')->where('customer_id', $c->id)->first();
        if ($a?->priceList) {
            if ($a->priceList->usableOn($day)) {
                return [$a->priceList, 'assigned', $a, $notes];
            }
            $notes[] = $a->priceList->active
                ? ['ar' => "قائمة العميل «{$a->priceList->name}» خارج مدة صلاحيتها في هذا التاريخ — سُعِّر بالقائمة الافتراضية", 'en' => "The customer's list “{$a->priceList->name}” is outside its validity on that day — the default list was used"]
                : ['ar' => "قائمة العميل «{$a->priceList->name}» مؤرشفة — سُعِّر بالقائمة الافتراضية", 'en' => "The customer's list “{$a->priceList->name}” is archived — the default list was used"];
        }
        $default = PriceList::where('is_default', true)->orderBy('sequence')->orderBy('id')->first();
        if ($default?->usableOn($day)) {
            return [$default, 'default', $a, $notes];
        }

        return [null, 'none', $a, $notes];
    }

    /**
     * The price of a product on a list: the winning rule, else the product's list price.
     *
     * @param  string[]  $chain  the lists already being worked out (a rule based on another list)
     * @return array{found:bool, price:?float, currency:string, source:string, rule:?PriceRule, reason:array, problem:?string, steps:array, considered:array}
     */
    private function onList(PriceList $list, Product $p, float $qty, Carbon $day, bool $explain, array $chain): array
    {
        $winner = null;
        $considered = [];
        foreach ($this->rulesOf($list, $p) as $r) {
            $outcome = match (true) {
                $winner !== null => 'outranked',
                $r->min_qty > $qty + 1e-9 => 'belowMinQty',
                $r->valid_from !== null && $r->valid_from->gt($day) => 'notStarted',
                $r->valid_to !== null && $r->valid_to->lt($day) => 'expired',
                default => 'applied',
            };
            if ($outcome === 'applied') {
                $winner = $r;
            }
            if ($explain && count($considered) < 12) {
                $considered[] = ['outcome' => $outcome, 'why' => self::outcomeText($outcome, $r, $winner), 'rule' => Shape::rule($r->loadMissing(self::RULE_WITH), $day)];
            }
        }
        $base = ['currency' => $list->currency, 'considered' => $considered, 'steps' => [], 'rule' => $winner, 'problem' => null];
        if ($winner) {
            $calc = $this->compute($winner, $list, $p, $qty, $day, $chain);
            $text = Shape::ruleText($winner->loadMissing('baseList'));
            [$onAr, $onEn] = self::targetText($winner);
            $from = $winner->min_qty > 0 ? [' (من كمية '.Shape::num($winner->min_qty).')', ' (from quantity '.Shape::num($winner->min_qty).')'] : ['', ''];
            if (! $calc['ok']) {
                return ['found' => false, 'price' => null, 'source' => 'none', 'problem' => $calc['problem'], 'steps' => $calc['steps'],
                    'reason' => ['ar' => "قائمة «{$list->name}» — {$onAr}: {$text['ar']}{$from[0]} — {$calc['why']['ar']}", 'en' => "List “{$list->name}” — {$onEn}: {$text['en']}{$from[1]} — {$calc['why']['en']}"]] + $base;
            }

            return ['found' => true, 'price' => $calc['price'], 'source' => 'rule', 'steps' => $calc['steps'],
                'reason' => ['ar' => "قائمة «{$list->name}» — {$onAr}: {$text['ar']}{$from[0]}", 'en' => "List “{$list->name}” — {$onEn}: {$text['en']}{$from[1]}"]] + $base;
        }
        $lp = $this->listPriceOf($p);
        if ($lp && $lp->price > 0) {
            if (strcasecmp($lp->currency, $list->currency) !== 0) {
                return ['found' => false, 'price' => null, 'source' => 'none', 'problem' => 'CURRENCY_MISMATCH',
                    'reason' => ['ar' => "لا قاعدة لهذا المنتج في قائمة «{$list->name}» وسعره المعلن بعملة أخرى ({$lp->currency}) — لا تحويل عملات في النظام", 'en' => "No rule for this product in list “{$list->name}” and its list price is in another currency ({$lp->currency}) — OPS converts no currency"]] + $base;
            }

            return ['found' => true, 'price' => $lp->price, 'source' => 'list_price', 'steps' => $explain ? [self::step('list', 'السعر المعلن للمنتج', "The product's list price", $lp->price)] : [],
                'reason' => ['ar' => "لا قاعدة لهذا المنتج في قائمة «{$list->name}» — السعر المعلن للمنتج", 'en' => "No rule for this product in list “{$list->name}” — the product's list price"]] + $base;
        }

        return ['found' => false, 'price' => null, 'source' => 'none', 'problem' => 'NO_PRICE',
            'reason' => ['ar' => "لا سعر: لا قاعدة لهذا المنتج في قائمة «{$list->name}» ولا سعر معلن له", 'en' => "No price: list “{$list->name}” has no rule for this product and the product has no list price"]] + $base;
    }

    /** A customer with no list of its own where no default list exists: the product's list price. */
    private function withoutList(Product $p): array
    {
        $lp = $this->listPriceOf($p);
        $base = ['currency' => $lp?->currency ?? 'SAR', 'rule' => null, 'steps' => [], 'considered' => [], 'problem' => null];
        if ($lp && $lp->price > 0) {
            return ['found' => true, 'price' => $lp->price, 'source' => 'list_price',
                'reason' => ['ar' => 'العميل بلا قائمة أسعار ولا توجد قائمة افتراضية — السعر المعلن للمنتج', 'en' => "The customer has no price list and there is no default list — the product's list price"]] + $base;
        }

        return ['found' => false, 'price' => null, 'source' => 'none', 'problem' => 'NO_PRICE',
            'reason' => ['ar' => 'لا سعر: العميل بلا قائمة أسعار ولا سعر معلن للمنتج', 'en' => 'No price: the customer has no price list and the product has no list price']] + $base;
    }

    /**
     * What one rule makes of a product.
     *
     * @return array{ok:bool, price:?float, steps:array, problem:?string, why:?array}
     */
    private function compute(PriceRule $r, PriceList $list, Product $p, float $qty, Carbon $day, array $chain): array
    {
        if ($r->compute === 'fixed') {
            return ['ok' => true, 'price' => (float) $r->fixed_price, 'steps' => [self::step('fixed', 'سعر ثابت في القاعدة', 'Fixed price of the rule', (float) $r->fixed_price)], 'problem' => null, 'why' => null];
        }
        $fail = fn (string $problem, string $ar, string $en, array $steps = []) => ['ok' => false, 'price' => null, 'steps' => $steps, 'problem' => $problem, 'why' => ['ar' => $ar, 'en' => $en]];
        // the base
        if ($r->base === 'cost') {
            $cost = $p->avg_cost !== null ? (float) $p->avg_cost : ($p->purchase_price !== null ? (float) $p->purchase_price : null);
            if ($cost === null || $cost <= 0) {
                return $fail('NO_COST', 'القاعدة تبدأ من التكلفة والمنتج بلا تكلفة مسجلة', 'the rule starts from the cost and the product has none');
            }
            $base = $cost;
            $steps = [self::step('cost', 'الأساس: تكلفة المنتج', 'Base: the cost of the product', $base)];
        } elseif ($r->base === 'pricelist') {
            $other = $r->base_list_id ? PriceList::find($r->base_list_id) : null;
            if (! $other) {
                return $fail('NO_BASE_LIST', 'القاعدة تبدأ من قائمة أخرى غير موجودة في النظام', 'the rule starts from another list that does not exist in OPS');
            }
            if (in_array($other->id, $chain, true) || count($chain) >= self::MAX_DEPTH) {
                return $fail('RULE_LOOP', "القاعدة تبدأ من قائمة «{$other->name}» التي تعود إلى هذه القائمة — حلقة", "the rule starts from list “{$other->name}”, which leads back to this one — a loop");
            }
            if (strcasecmp($other->currency, $list->currency) !== 0) {
                return $fail('CURRENCY_MISMATCH', "قائمة الأساس «{$other->name}» بعملة أخرى — لا تحويل عملات في النظام", "the base list “{$other->name}” is in another currency — OPS converts no currency");
            }
            $on = $this->onList($other, $p, $qty, $day, false, [...$chain, $other->id]);
            if (! $on['found']) {
                return $fail('NO_BASE_PRICE', "لا سعر للمنتج في قائمة الأساس «{$other->name}»", "the base list “{$other->name}” has no price for the product");
            }
            $base = (float) $on['price'];
            $steps = [self::step('pricelist', "الأساس: سعر المنتج في قائمة «{$other->name}»", "Base: the product's price on list “{$other->name}”", $base)];
        } else {
            $lp = $this->listPriceOf($p);
            if (! $lp || $lp->price <= 0) {
                return $fail('NO_LIST_PRICE', 'القاعدة تبدأ من السعر المعلن والمنتج بلا سعر معلن', 'the rule starts from the list price and the product has none');
            }
            if (strcasecmp($lp->currency, $list->currency) !== 0) {
                return $fail('CURRENCY_MISMATCH', "السعر المعلن للمنتج بعملة أخرى ({$lp->currency}) — لا تحويل عملات في النظام", "the product's list price is in another currency ({$lp->currency}) — OPS converts no currency");
            }
            $base = (float) $lp->price;
            $steps = [self::step('list', 'الأساس: السعر المعلن للمنتج', "Base: the product's list price", $base)];
        }
        if ($r->compute === 'percent') {
            $price = $base * (1 - (float) $r->percent / 100);
            $n = Shape::num(abs((float) $r->percent));
            $steps[] = (float) $r->percent >= 0 ? self::step('discount', "خصم {$n}%", "Less {$n}%", $price) : self::step('discount', "زيادة {$n}%", "Plus {$n}%", $price);

            return ['ok' => true, 'price' => self::clean($price), 'steps' => $steps, 'problem' => null, 'why' => null];
        }
        // formula: discount → rounding → surcharge → floor → ceiling (the order Odoo applies them in)
        $price = $base * (1 - (float) $r->discount_pct / 100);
        if ((float) $r->discount_pct != 0.0) {
            $n = Shape::num(abs((float) $r->discount_pct));
            $steps[] = (float) $r->discount_pct > 0 ? self::step('discount', "خصم {$n}%", "Less {$n}%", $price) : self::step('discount', "زيادة {$n}%", "Plus {$n}%", $price);
        }
        if ((float) $r->round_to > 0) {
            $price = round($price / (float) $r->round_to) * (float) $r->round_to;
            $steps[] = self::step('round', 'تقريب إلى مضاعفات '.Shape::dec($r->round_to), 'Rounded to a multiple of '.Shape::dec($r->round_to), $price);
        }
        if ((float) $r->surcharge != 0.0) {
            $price += (float) $r->surcharge;
            $steps[] = self::step('surcharge', ((float) $r->surcharge > 0 ? 'إضافة ' : 'طرح ').Shape::dec(abs((float) $r->surcharge)), ((float) $r->surcharge > 0 ? 'Plus ' : 'Minus ').Shape::dec(abs((float) $r->surcharge)), $price);
        }
        if ((float) $r->min_margin != 0.0 && $price < $base + (float) $r->min_margin) {
            $price = $base + (float) $r->min_margin;
            $steps[] = self::step('floor', 'رُفع إلى الحد الأدنى (الأساس + '.Shape::dec($r->min_margin).')', 'Raised to the floor (base + '.Shape::dec($r->min_margin).')', $price);
        }
        if ((float) $r->max_margin != 0.0 && $price > $base + (float) $r->max_margin) {
            $price = $base + (float) $r->max_margin;
            $steps[] = self::step('ceiling', 'خُفض إلى الحد الأعلى (الأساس + '.Shape::dec($r->max_margin).')', 'Lowered to the ceiling (base + '.Shape::dec($r->max_margin).')', $price);
        }

        return ['ok' => true, 'price' => self::clean($price), 'steps' => $steps, 'problem' => null, 'why' => null];
    }

    /**
     * The rules of a list that cover a product, best first: product before category before "all"; the highest minimum
     * quantity; the nearest category; the newest rule.
     *
     * @return PriceRule[]
     */
    private function rulesOf(PriceList $list, Product $p): array
    {
        $key = "{$list->id}|{$p->id}";
        if (isset($this->rules[$key])) {
            return $this->rules[$key];
        }
        $chain = $this->categoryChain($p);
        $rows = PriceRule::query()->where('price_list_id', $list->id)->where('active', true)
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('applies_to', 'product')->where('product_id', $p->id))
                ->orWhere(fn ($x) => $x->where('applies_to', 'category')->whereIn('category_id', $chain ?: ['-']))
                ->orWhere('applies_to', 'all'))->get()->all();

        return $this->rules[$key] = self::ranked($rows, $chain);
    }

    /** @param  PriceRule[]  $rows  @param  string[]  $chain  the product's category, then its parents  @return PriceRule[] */
    private static function ranked(array $rows, array $chain): array
    {
        $depth = array_flip($chain);
        usort($rows, fn (PriceRule $a, PriceRule $b) => [PriceRule::RANK[$a->applies_to] ?? 9, -$a->min_qty, $depth[(string) $a->category_id] ?? 0, -$a->seq, $b->id]
            <=> [PriceRule::RANK[$b->applies_to] ?? 9, -$b->min_qty, $depth[(string) $b->category_id] ?? 0, -$b->seq, $a->id]);

        return $rows;
    }

    /** One query for the rules of a list over many products, one for their list prices: the lines of a document. */
    private function prime(Collection $products, ?PriceList $list): void
    {
        $ids = $products->pluck('id')->all();
        if (! $ids) {
            return;
        }
        $prices = ProductListPrice::whereIn('product_id', $ids)->get()->keyBy('product_id');
        foreach ($ids as $id) {
            $this->listPrices[$id] = $prices[$id] ?? null;
        }
        if (! $list) {
            return;
        }
        $rows = PriceRule::query()->where('price_list_id', $list->id)->where('active', true)
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('applies_to', 'product')->whereIn('product_id', $ids))->orWhereIn('applies_to', ['category', 'all']))->get();
        $own = $rows->where('applies_to', 'product')->groupBy('product_id');
        $broad = $rows->where('applies_to', '!=', 'product');
        foreach ($products as $p) {
            $chain = $this->categoryChain($p);
            $cover = $broad->filter(fn (PriceRule $r) => $r->applies_to === 'all' || ($r->category_id !== null && in_array($r->category_id, $chain, true)));
            $this->rules["{$list->id}|{$p->id}"] = self::ranked([...($own[$p->id] ?? collect())->all(), ...$cover->all()], $chain);
        }
    }

    private function listPriceOf(Product $p): ?ProductListPrice
    {
        if (! array_key_exists($p->id, $this->listPrices)) {
            $this->listPrices[$p->id] = ProductListPrice::where('product_id', $p->id)->first();
        }

        return $this->listPrices[$p->id];
    }

    /** The product's category and its parents, nearest first. @return string[] */
    private function categoryChain(Product $p): array
    {
        if (! $p->category_id) {
            return [];
        }
        $this->parents ??= ProductCategory::query()->pluck('parent_id', 'id')->all();
        $chain = [];
        for ($id = (string) $p->category_id; $id !== '' && ! in_array($id, $chain, true) && count($chain) < 20; $id = (string) ($this->parents[$id] ?? '')) {
            $chain[] = $id;
        }

        return $chain;
    }

    /** Nothing is remembered from one question to the next: the lists may have changed in between. */
    private function forget(): void
    {
        $this->parents = null;
        $this->listPrices = $this->rules = $this->customerLists = [];
    }

    // ───────────── what a supplier charges ─────────────

    /**
     * The best supplier price of a product for a quantity on a day: of every supplier's price that holds on that day
     * and whose minimum quantity is reached, the lowest after discount (then the supplier that comes first for the
     * product, then the shortest lead time). A supplier that is suspended or under review is listed and never chosen.
     * With `$fallback`, a product without any supplier price answers with what procurement used before this section:
     * the price on the product's supplier link, then the product's purchase price — the `source` says which.
     *
     * @return array{found:bool, price:?float, currency:string, source:string, supplier:?array, leadDays:?int, best:?array, offers:array, others:array,
     *               product:array, qty:float|int, date:string, reason:array{ar:string,en:string}}
     */
    public function bestSupplierPrice(string $productId, float|int $qty = 1, DateTimeInterface|string|null $date = null, bool $fallback = true): array
    {
        return $this->supplierQuote(Find::product($productId), null, self::qty($qty), Day::of($date), $fallback);
    }

    /** What one supplier charges for a product (same answer shape; `offers` holds that supplier only). */
    public function supplierPrice(string $supplierId, string $productId, float|int $qty = 1, DateTimeInterface|string|null $date = null, bool $fallback = true): array
    {
        return $this->supplierQuote(Find::product($productId), Find::supplier($supplierId), self::qty($qty), Day::of($date), $fallback);
    }

    private function supplierQuote(Product $p, ?Supplier $only, float $qty, Carbon $day, bool $fallback): array
    {
        $rows = SupplierPrice::with('supplier')->where('product_id', $p->id)->where('active', true)->whereNotNull('supplier_id')
            ->when($only, fn ($q) => $q->where('supplier_id', $only->id))->orderBy('sequence')->orderBy('id')->get();
        $offers = $others = [];
        foreach ($rows->groupBy('supplier_id') as $group) {
            $supplier = $group->first()->supplier;
            if (! $supplier) {
                continue;
            }
            $fit = $group->filter(fn (SupplierPrice $r) => $r->min_qty <= $qty + 1e-9 && $r->periodOn($day) === 'valid')
                ->sort(fn (SupplierPrice $a, SupplierPrice $b) => [-$a->min_qty, $a->net(), $a->sequence, $b->id] <=> [-$b->min_qty, $b->net(), $b->sequence, $a->id])->values();
            $chosen = $fit->first();
            if ($chosen) {
                $orderable = (bool) $supplier->active && ! in_array($supplier->status, ['suspended', 'review'], true);
                $offers[] = Shape::supplierPrice($chosen, $day) + [
                    'net' => $chosen->net(), 'leadDaysEffective' => $chosen->lead_days ?? ($supplier->lead_days !== null ? (int) $supplier->lead_days : null), 'orderable' => $orderable,
                    'notOrderableWhy' => $orderable ? null : (! $supplier->active || $supplier->status === 'suspended'
                        ? ['ar' => 'المورد موقوف', 'en' => 'The supplier is suspended'] : ['ar' => 'المورد تحت المراجعة', 'en' => 'The supplier is under review']),
                ];
            }
            foreach ($group as $r) {
                if ($chosen && $r->id === $chosen->id) {
                    continue;
                }
                $why = match (true) {
                    $r->periodOn($day) === 'expired' => ['expired', 'انتهت مدته', 'Its period has ended'],
                    $r->periodOn($day) === 'future' => ['future', 'لم تبدأ مدته بعد', 'Its period has not started'],
                    $r->min_qty > $qty + 1e-9 => ['belowMinQty', 'يبدأ من كمية '.Shape::num($r->min_qty), 'Starts at quantity '.Shape::num($r->min_qty)],
                    default => ['tier', 'شريحة أخرى لهذا المورد تنطبق على هذه الكمية', 'Another tier of this supplier applies to this quantity'],
                };
                $others[] = Shape::supplierPrice($r, $day) + ['outcome' => $why[0], 'why' => ['ar' => $why[1], 'en' => $why[2]]];
            }
        }
        usort($offers, fn ($a, $b) => [! $a['orderable'], $a['net'], $a['sequence'], $a['leadDaysEffective'] ?? PHP_INT_MAX, $a['supplier']['code']]
            <=> [! $b['orderable'], $b['net'], $b['sequence'], $b['leadDaysEffective'] ?? PHP_INT_MAX, $b['supplier']['code']]);
        $best = $offers && $offers[0]['orderable'] ? $offers[0] : null;
        foreach ($offers as $i => $o) {
            $offers[$i]['best'] = $best !== null && $i === 0;
        }
        $head = ['product' => Shape::product($p), 'qty' => Shape::num($qty), 'date' => $day->toDateString(), 'offers' => $offers, 'others' => $others];
        if ($best) {
            $n = count($offers);
            $name = $best['supplier']['nameAr'];

            return ['found' => true, 'price' => $best['net'], 'currency' => $best['currency'], 'source' => 'supplier_price', 'supplier' => $best['supplier'], 'leadDays' => $best['leadDaysEffective'], 'best' => $best + ['best' => true],
                'reason' => $only
                    ? ['ar' => "سعر المورد {$name} لهذه الكمية في قائمة أسعاره", 'en' => "The price of {$best['supplier']['nameEn']} for this quantity on its price list"]
                    : ['ar' => $n > 1 ? "أقل سعر بين {$n} موردين لهذه الكمية: {$name}" : "المورد الوحيد المسعِّر لهذه الكمية: {$name}", 'en' => $n > 1 ? "The lowest of {$n} suppliers for this quantity: {$best['supplier']['nameEn']}" : "The only supplier priced for this quantity: {$best['supplier']['nameEn']}"],
            ] + $head;
        }
        $none = ['found' => false, 'price' => null, 'currency' => 'SAR', 'source' => 'none', 'supplier' => null, 'leadDays' => null, 'best' => null] + $head;
        if ($offers) {
            return ['reason' => ['ar' => 'كل الموردين المسعِّرين لهذه الكمية موقوفون أو تحت المراجعة', 'en' => 'Every supplier priced for this quantity is suspended or under review']] + $none;
        }
        if ($fallback) {
            $link = ProductSupplier::with('supplier')->where('product_id', $p->id)->whereNotNull('price')->when($only, fn ($q) => $q->where('supplier_id', $only->id))
                ->orderByDesc('preferred')->orderBy('id')->first();
            if ($link && $link->supplier && (float) $link->price > 0) {
                return ['found' => true, 'price' => round((float) $link->price, 4), 'source' => 'product_supplier', 'supplier' => Shape::party($link->supplier), 'leadDays' => $link->lead_days ?? ($link->supplier->lead_days !== null ? (int) $link->supplier->lead_days : null),
                    'reason' => ['ar' => 'لا سعر في قوائم أسعار الموردين — السعر المسجل على ربط المنتج بالمورد', 'en' => "No supplier price list entry — the price kept on the product's supplier link"]] + $none;
            }
            if (! $only && $p->purchase_price !== null && (float) $p->purchase_price > 0) {
                return ['found' => true, 'price' => round((float) $p->purchase_price, 4), 'source' => 'purchase_price',
                    'reason' => ['ar' => 'لا سعر في قوائم أسعار الموردين — سعر الشراء المسجل على المنتج', 'en' => "No supplier price list entry — the product's own purchase price"]] + $none;
            }
        }

        return ['reason' => $others
            ? ['ar' => 'لا سعر مورد ينطبق على هذه الكمية في هذا التاريخ', 'en' => 'No supplier price applies to this quantity on that day']
            : ['ar' => 'لا أسعار موردين لهذا المنتج', 'en' => 'No supplier prices for this product']] + $none;
    }

    // ───────────── small things ─────────────

    private static function qty(mixed $qty): float
    {
        return is_numeric($qty) && (float) $qty > 0 ? (float) $qty : 1.0;
    }

    /** A computed price without the noise of binary fractions. */
    private static function clean(float $price): float
    {
        return round($price, 6);
    }

    private static function step(string $kind, string $ar, string $en, float $value): array
    {
        return ['kind' => $kind, 'ar' => $ar, 'en' => $en, 'value' => round($value, 4)];
    }

    /** @return array{0:string, 1:string} what the rule is about, in Arabic and English */
    private static function targetText(PriceRule $r): array
    {
        if ($r->applies_to === 'category') {
            $c = $r->loadMissing('category:id,code,name_ar,name_en')->category;

            return ["قاعدة لفئة «{$c?->name_ar}»", "rule for the category “{$c?->name_en}”"];
        }

        return $r->applies_to === 'all' ? ['قاعدة لكل المنتجات', 'rule for every product'] : ['قاعدة للمنتج', 'rule for the product'];
    }

    private static function outcomeText(string $outcome, PriceRule $r, ?PriceRule $winner): array
    {
        return match ($outcome) {
            'applied' => ['ar' => 'هذه القاعدة هي التي طُبِّقت', 'en' => 'This is the rule that was applied'],
            'belowMinQty' => ['ar' => 'الكمية أقل من حدها الأدنى '.Shape::num($r->min_qty), 'en' => 'The quantity is below its minimum of '.Shape::num($r->min_qty)],
            'notStarted' => ['ar' => 'تبدأ في '.Shape::day($r->valid_from), 'en' => 'Starts on '.Shape::day($r->valid_from)],
            'expired' => ['ar' => 'انتهت في '.Shape::day($r->valid_to), 'en' => 'Ended on '.Shape::day($r->valid_to)],
            default => $winner && $winner->applies_to !== $r->applies_to
                ? ['ar' => 'سبقتها قاعدة أخصّ', 'en' => 'A more specific rule came first']
                : ($winner && abs($winner->min_qty - $r->min_qty) > 1e-9
                    ? ['ar' => 'سبقتها قاعدة بحد أدنى للكمية أعلى', 'en' => 'A rule with a higher minimum quantity came first']
                    : ['ar' => 'سبقتها قاعدة أحدث لها الهدف نفسه — هذه القاعدة لا تُستخدم', 'en' => 'A newer rule with the same target came first — this one is never used']),
        };
    }
}
