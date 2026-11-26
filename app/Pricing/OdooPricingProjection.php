<?php

namespace App\Pricing;

use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Odoo\Projection\Val;
use App\Integration\Services\ExternalRefs;
use App\Pricing\Models\PriceList;
use App\Pricing\Models\PriceListCustomer;
use App\Pricing\Models\PriceRule;
use App\Pricing\Models\ProductListPrice;
use App\Pricing\Models\SupplierPrice;
use App\Pricing\Services\AssignmentsService;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * From the copy of Odoo's prices to «قوائم الأسعار»: Odoo's price lists, their rules, the list price of each product,
 * the price list of each customer and the supplier prices become records of the section, marked
 * `source_system = 'odoo'` with the Odoo id in `external_ref`. Idempotent: a second pass changes nothing.
 *
 * THROUGH THE CHANGE FEED (`models()`, the base class): `product.pricelist` → price lists, `product.pricelist.item` →
 * rules, `product.supplierinfo` → supplier prices.
 *
 * BESIDE THE FEED. Two things the section needs live on records whose feed belongs to the master data (one change is
 * consumed once): a product's sales price (`product.template.list_price` → the list price) and a partner's price list.
 * They are read from the copy by "changed since the last look" (`odoo_records.changed_at`, marks in
 * `pricing_sync_marks`), after the feed, in the same plan / apply / consume.
 *
 * THE PRICE LIST OF A CUSTOMER. Odoo names it in `res.partner.property_product_pricelist` — a value Odoo works out per
 * company, which the copy leaves out today (it is not a stored field). The day the copy carries it (the field is
 * listed in `odoo_models.fields` of `res.partner`), it decides for every partner (`basis = partner`; nothing there =
 * no list of its own). Until then the list is read from what the copy does hold: the price list of the customer's
 * latest order that was not cancelled (`basis = order`) — evidence a person may correct, and which Odoo's own field
 * replaces the day it is copied. A customer on Odoo's default list gets no row: it follows the default list here too.
 * The default list is Odoo's general fallback: the first active list, by sequence then id, not tied to a country group.
 *
 * LINKS are by identity only (`int_external_refs`, system `odoo`): a rule or a supplier price whose product (or
 * supplier, or category) is not an OPS record yet is still kept — unused, marked as waiting — and completed by itself
 * when the link appears. A product template with several variants gives one rule / price per linked variant.
 *
 * WHAT IS ODOO'S on such a record: everything. While the section follows Odoo these records are read-only in OPS;
 * OPS's own lists, rules and supplier prices are never touched by a pass. A record deleted in Odoo is archived here.
 * plan() also runs where the section's tables do not exist yet (everything then counts as new) — it never writes.
 */
class OdooPricingProjection extends OdooProjection
{
    private const TEMPLATES = 'product.template';

    private const PARTNERS = 'res.partner';

    private const ORDERS = 'sale.order';

    /** The most records of one model read beside the feed in one run. */
    private const SIDE_LIMIT = 2000;

    /** A page of the copy is stamped when it is read and committed a moment later: every look goes back this far. */
    private const OVERLAP_SECONDS = 120;

    /** The outcomes that wrote something. */
    private const WRITES = ['created', 'updated', 'archived', 'removed', 'adopted'];

    /** A whole-mirror pass (plan / apply): the OPS rows are read once, not one by one. */
    private bool $whole = false;

    /** The section's tables exist (a plan may run before the migration). */
    private bool $installed = true;

    /** @var array<string, int> every outcome of this pass — the feed's and what was read beside it */
    private array $tally = [];

    /** @var array<string, string[]> */
    private array $examples = [];

    private int $failedAside = 0;

    /** @var array<int|string, PriceList>|null OPS lists by Odoo id */
    private ?array $lists = null;

    /** @var array<int, true> Odoo lists a plan decided to create */
    private array $plannedLists = [];

    /** @var array{0:?int}|null the Odoo list that is the general default */
    private ?array $defaultList = null;

    /** @var array<int, int[]>|null Odoo template id → its variant ids */
    private ?array $variants = null;

    /** @var array<string, array<int|string, array<string, Model>>> per table (a whole pass): Odoo id → external_ref → row */
    private array $rows = [];

    /** @var array<string, ProductListPrice>|null (a whole pass) product id → list price */
    private ?array $listPrices = null;

    /** @var array<string, PriceListCustomer>|null (a whole pass) customer id → assignment */
    private ?array $assignments = null;

    /** @var array<string, bool> */
    private array $sourceReady = [];

    /** Does the copy carry Odoo's own price-list field of a partner (null = not looked at yet in this pass). */
    private ?bool $partnerField = null;

    private ?string $currency = null;

    /** @var array<string, object>|null where the look beside the feed stands (`pricing_sync_marks`, without the per-customer rows) */
    private ?array $marks = null;

    public function __construct(ExternalRefs $refs, private readonly AuditService $audit, private readonly NumberingService $numbering)
    {
        parent::__construct($refs);
    }

    public function key(): string
    {
        return 'pricing';
    }

    /** Lists before their rules. */
    public function models(): array
    {
        return ['product.pricelist', 'product.pricelist.item', 'product.supplierinfo'];
    }

    // ───────────── a pass: the feed, then what is read beside it ─────────────

    public function plan(): array
    {
        return $this->pass(true, true, fn () => parent::plan(), null);
    }

    public function apply(): array
    {
        return $this->pass(true, false, fn () => parent::apply(), null);
    }

    public function consume(int $max = 500, float $seconds = 8.0): array
    {
        $until = microtime(true) + $seconds;

        return $this->pass(false, false, fn () => parent::consume($max, $seconds), $until);
    }

    private function pass(bool $whole, bool $dry, callable $feed, ?float $until): array
    {
        $this->whole = $whole;
        $this->tally = $this->examples = [];
        $this->failedAside = 0;
        $this->forget();
        $this->installed = Schema::hasTable('price_lists');
        if (! $this->installed && ! $dry) {
            if (! $whole) {
                return ['changes' => 0, 'counts' => []]; // a run before the migration: nothing is taken from the feed, it waits
            }
            throw AppError::rule('PRICING_NOT_INSTALLED', 'جداول قسم قوائم الأسعار لم تُنشأ بعد — شغّل الترحيل أولًا (php artisan migrate)', 'The tables of the price lists section do not exist yet — run the migration first');
        }
        try {
            $started = now();
            $out = $feed();
            $before = $this->written();
            $from = $started->copy()->subSeconds(self::OVERLAP_SECONDS);
            if ($whole) {
                $this->listPricesWhole();
                $this->assignmentsWhole();
                if (! $dry) {
                    $this->ensureDefault();
                    foreach ([self::TEMPLATES, self::PARTNERS, self::ORDERS] as $model) {
                        $this->mark($model, $from, 0);
                    }
                    $this->mark('links', null, $this->linkCount());
                    $this->mark('partnerField', null, (int) $this->partnerFieldCopied());
                }
            } elseif ($this->markOf('links') !== null) {
                // (before the first load — `php artisan scm:odoo-project pricing --apply` — only the feed is followed:
                // a first load is thousands of records, not the work of a run)
                $relinked = $this->afterNewLinks();
                $this->since(self::TEMPLATES, ['data->list_price as list_price', 'write_date'], fn (object $row) => $this->templatePrice($row), $until);
                $copied = (int) $this->partnerFieldCopied();
                $known = $this->markOf('partnerField');
                if (! $known || (int) $known->seen_id !== $copied) {
                    // the copy started (or stopped) carrying Odoo's own field: every customer's list is looked at again, once
                    if (! $relinked) {
                        $this->assignmentsWhole();
                    }
                    $this->mark(self::PARTNERS, $from, 0);
                    $this->mark(self::ORDERS, $from, 0);
                    $this->mark('partnerField', null, $copied);
                } elseif ($copied) {
                    $this->since(self::PARTNERS, ['data->'.AssignmentsService::PARTNER_FIELD.' as pricelist'], fn (object $row) => $this->partnerList($row), $until);
                } else {
                    $this->since(self::ORDERS, ['data->partner_id as partner', 'data->pricelist_id as pricelist', 'data->date_order as date_order', 'data->state as state', 'data->name as name'],
                        fn (object $row) => $this->orderList($row), $until);
                }
                $this->ensureDefault();
            }
            $failed = (int) ($out['failed'] ?? 0) + $this->failedAside;
            if ($whole) {
                return ['counts' => $this->tally, 'notes' => $this->examples] + ($failed ? ['failed' => $failed] : []) + $out;
            }
            // the line of a run: the changes of the feed plus what the look beside it wrote
            $out = ['changes' => (int) $out['changes'] + ($this->written() - $before), 'counts' => $this->tally] + $out;
            unset($out['failed'], $out['notes']);

            return $out + ($failed ? ['failed' => $failed, 'notes' => $this->examples] : []);
        } finally {
            $this->whole = false;
            $this->forget();
        }
    }

    protected function project(OdooRecord $record): void
    {
        match ($record->model) {
            'product.pricelist' => $this->priceList($record),
            'product.pricelist.item' => $this->rule($record),
            'product.supplierinfo' => $this->supplierPrice($record),
            default => null,
        };
    }

    /** Every outcome is kept here as well: the answer of a pass covers the feed and what was read beside it. */
    protected function count(string $what, ?string $note = null): void
    {
        parent::count($what, $note);
        $this->tally[$what] = ($this->tally[$what] ?? 0) + 1;
        if ($note !== null && count($this->examples[$what] ?? []) < 15) {
            $this->examples[$what][] = $note;
        }
    }

    private function written(): int
    {
        $n = 0;
        foreach ($this->tally as $what => $count) {
            if (in_array(substr((string) strrchr('.'.$what, '.'), 1), self::WRITES, true)) {
                $n += $count;
            }
        }

        return $n;
    }

    private function forget(): void
    {
        $this->lists = $this->defaultList = $this->variants = $this->listPrices = $this->assignments = $this->currency = $this->partnerField = $this->marks = null;
        $this->plannedLists = $this->rows = $this->sourceReady = [];
    }

    /** One record read beside the feed: a failure is counted with its reason and does not stop the others. */
    private function attempt(string $what, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            if (++$this->failedAside > 40) {
                throw $e;
            }
            report($e);
            $this->count('failed', "{$what}: ".mb_substr($e instanceof AppError ? $e->messageAr : $e->getMessage(), 0, 160));
        }
    }

    // ───────────── price lists ─────────────

    private function priceList(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $name = Val::text($d['name'] ?? null, 190) ?? Val::text($r->display_name, 190) ?? "Odoo #{$r->odoo_id}";
        $row = $this->listRow($r->odoo_id);
        if (! $row && $r->deleted_at !== null) {
            $this->count('lists.skippedDeleted');

            return;
        }
        $alive = $this->alive($r);
        $default = $alive && $this->defaultOdooList() === $r->odoo_id;
        $values = [
            'name' => $name, 'currency' => self::currency($d['currency_id'] ?? null), 'sequence' => Val::int($d['sequence'] ?? 0), 'active' => $alive, 'is_default' => $default,
            'extra' => array_filter(['discountPolicy' => Val::text($d['discount_policy'] ?? null, 40), 'company' => Val::label($d['company_id'] ?? null), 'countryGroups' => Val::ids($d['country_group_ids'] ?? null) ?: null]) ?: null,
            'source_updated_at' => Val::at($r->write_date), 'source_deleted_at' => $r->deleted_at,
        ];
        if ($default) {
            $this->count('lists.default', "#{$r->odoo_id} {$name}");
        }
        if (! $row) {
            $this->plannedLists[$r->odoo_id] = true;
            $this->count('lists.created');
            if (! $this->dry) {
                $this->lists[$r->odoo_id] = DB::transaction(function () use ($r, $values) {
                    if ($values['is_default']) {
                        PriceList::where('is_default', true)->update(['is_default' => false]);
                    }
                    $l = PriceList::create($values + ['code' => $this->numbering->next('PRL'), 'source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id, 'created_by' => 'svc.odoo']);
                    $this->audit->log($this->actor(), ['action' => 'PRICING.LIST.CREATE', 'entityType' => 'PriceList', 'entityId' => $l->id, 'entityNumber' => $l->code,
                        'newValue' => ['name' => $values['name'], 'currency' => $values['currency'], 'active' => $values['active'], 'origin' => "from Odoo price list #{$r->odoo_id}"]]);

                    return $l;
                });
            }

            return;
        }
        [$old, $new] = self::changes($row, $values);
        unset($old['source_updated_at'], $new['source_updated_at']); // when Odoo last touched the record is not a change of the list
        $this->count(! $new ? 'lists.unchanged' : (array_key_exists('active', $new) && ! $new['active'] ? 'lists.archived' : 'lists.updated'));
        if ($this->dry || (! $new && self::same($row->source_updated_at, $values['source_updated_at']))) {
            return;
        }
        DB::transaction(function () use ($row, $values, $old, $new) {
            if (! empty($new['is_default'])) {
                PriceList::where('is_default', true)->where('id', '!=', $row->id)->update(['is_default' => false]);
            }
            $row->update($new + ['source_updated_at' => $values['source_updated_at']] + ($new ? ['updated_by' => 'svc.odoo'] : []));
            if ($new) {
                $this->audit->log($this->actor(), ['action' => 'PRICING.LIST.UPDATE', 'entityType' => 'PriceList', 'entityId' => $row->id, 'entityNumber' => $row->code, 'oldValue' => $old, 'newValue' => $new + ['origin' => 'Odoo']]);
            }
        });
    }

    /** The OPS list of an Odoo list. */
    private function listRow(?int $odooId): ?PriceList
    {
        if (! $odooId || ! $this->installed) {
            return null;
        }
        $this->lists ??= PriceList::where('source_system', self::SYSTEM)->get()->keyBy('external_ref')->all();

        return $this->lists[$odooId] ?? null;
    }

    /**
     * Odoo's general fallback list: the first active list — by sequence, then id — that is not tied to a country
     * group. It is what a partner without a list of its own is priced with.
     */
    private function defaultOdooList(): ?int
    {
        if ($this->defaultList === null) {
            $best = null;
            foreach (OdooRecord::where('model', 'product.pricelist')->whereNull('deleted_at')->get(['odoo_id', 'active', 'data']) as $l) {
                if ($l->active === false || Val::ids($l->data['country_group_ids'] ?? null)) {
                    continue;
                }
                $key = [Val::int($l->data['sequence'] ?? 0), $l->odoo_id];
                if ($best === null || $key < $best[0]) {
                    $best = [$key, $l->odoo_id];
                }
            }
            $this->defaultList = [$best[1] ?? null];
        }

        return $this->defaultList[0];
    }

    /** The currency a product's sales price is in: the company's — the one of Odoo's general list. */
    private function companyCurrency(): string
    {
        return $this->currency ??= self::currency($this->mirrored('product.pricelist', $this->defaultOdooList())?->data['currency_id'] ?? null);
    }

    /** The default flag follows Odoo's fallback list even when the record that changed was another list. */
    private function ensureDefault(): void
    {
        if ($this->dry || ! $this->installed || ! ($row = $this->listRow($this->defaultOdooList()))) {
            return;
        }
        if ($row->active && ! $row->is_default) {
            DB::transaction(function () use ($row) {
                PriceList::where('is_default', true)->where('id', '!=', $row->id)->update(['is_default' => false]);
                $row->update(['is_default' => true, 'updated_by' => 'svc.odoo']);
                $this->audit->log($this->actor(), ['action' => 'PRICING.LIST.DEFAULT', 'entityType' => 'PriceList', 'entityId' => $row->id, 'entityNumber' => $row->code, 'field' => 'isDefault',
                    'oldValue' => false, 'newValue' => true]);
            });
            $this->count('lists.updated');
        }
    }

    // ───────────── rules ─────────────

    private function rule(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $existing = $this->rowsOf('rules', PriceRule::class, $r->odoo_id);
        if ($r->deleted_at !== null) {
            $this->retire('rules', $existing, $r, 'PriceRule');

            return;
        }
        $listId = Val::id($d['pricelist_id'] ?? null);
        $list = $this->listRow($listId);
        if (! $list && $listId && ! isset($this->plannedLists[$listId]) && ! $this->dry && ($parent = $this->mirrored('product.pricelist', $listId))) {
            $this->priceList($parent); // the feed named the rule before its list was ever taken
            $list = $this->listRow($listId);
        }
        if (! $list && ! isset($this->plannedLists[(int) $listId])) {
            $this->count('rules.skippedNoList', "#{$r->odoo_id} → ".(Val::label($d['pricelist_id'] ?? null) ?? "list #{$listId}"));

            return;
        }
        $applied = (string) ($d['applied_on'] ?? '3_global');
        $compute = match ($d['compute_price'] ?? 'fixed') {
            'percentage' => 'percent', 'formula' => 'formula', default => 'fixed',
        };
        $base = $compute === 'fixed' ? 'list' : match ($d['base'] ?? 'list_price') {
            'standard_price' => 'cost', 'pricelist' => 'pricelist', default => 'list',
        };
        $common = [
            'price_list_id' => $list?->id, 'min_qty' => round(Val::float($d['min_quantity'] ?? 0), 3), 'valid_from' => self::localDay($d['date_start'] ?? null), 'valid_to' => self::localDay($d['date_end'] ?? null),
            'compute' => $compute, 'fixed_price' => $compute === 'fixed' ? round(Val::float($d['fixed_price'] ?? 0), 4) : null, 'percent' => $compute === 'percent' ? round(Val::float($d['percent_price'] ?? 0), 4) : null,
            'base' => $base, 'base_list_id' => $base === 'pricelist' ? $this->listRow(Val::id($d['base_pricelist_id'] ?? null))?->id : null,
            'discount_pct' => $compute === 'formula' ? round(Val::float($d['price_discount'] ?? 0), 4) : 0.0, 'surcharge' => $compute === 'formula' ? round(Val::float($d['price_surcharge'] ?? 0), 4) : 0.0,
            'round_to' => $compute === 'formula' ? round(Val::float($d['price_round'] ?? 0), 4) : 0.0, 'min_margin' => $compute === 'formula' ? round(Val::float($d['price_min_margin'] ?? 0), 4) : 0.0,
            'max_margin' => $compute === 'formula' ? round(Val::float($d['price_max_margin'] ?? 0), 4) : 0.0,
            'seq' => $r->odoo_id, 'active' => true, 'source_deleted_at' => null,
        ];
        // what the rule covers — one OPS rule per OPS product it names
        $targets = [];
        if (str_starts_with($applied, '0_') || str_starts_with($applied, '1_')) {
            $variants = str_starts_with($applied, '0_') ? array_filter([Val::id($d['product_id'] ?? null)]) : $this->variantsOf(Val::id($d['product_tmpl_id'] ?? null));
            $label = Val::label($d['product_id'] ?? null) ?? Val::label($d['product_tmpl_id'] ?? null) ?? 'Odoo product';
            foreach ($this->productTargets($r->odoo_id, $variants) as $ref => $productId) {
                $targets[$ref] = ['applies_to' => 'product', 'product_id' => $productId, 'category_id' => null, 'pending_label' => $productId ? null : mb_substr($label, 0, 250)];
                if (! $productId) {
                    $this->count('rules.waitingProduct', "#{$r->odoo_id} {$label}");
                }
            }
        } elseif (str_starts_with($applied, '2_')) {
            $categoryId = $this->linked('category', Val::id($d['categ_id'] ?? null));
            $label = Val::label($d['categ_id'] ?? null) ?? 'Odoo category';
            $targets[(string) $r->odoo_id] = ['applies_to' => 'category', 'product_id' => null, 'category_id' => $categoryId, 'pending_label' => $categoryId ? null : mb_substr($label, 0, 250)];
            if (! $categoryId) {
                $this->count('rules.waitingCategory', "#{$r->odoo_id} {$label}");
            }
        } else {
            $targets[(string) $r->odoo_id] = ['applies_to' => 'all', 'product_id' => null, 'category_id' => null, 'pending_label' => null];
        }
        $this->reconcile('rules', PriceRule::class, 'PriceRule', $existing, array_map(fn (array $t) => $t + $common, $targets), $r, $list?->code);
    }

    // ───────────── supplier prices ─────────────

    private function supplierPrice(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $existing = $this->rowsOf('supplierPrices', SupplierPrice::class, $r->odoo_id);
        if ($r->deleted_at !== null) {
            $this->retire('supplierPrices', $existing, $r, 'SupplierPrice');

            return;
        }
        $partnerId = Val::id($d['partner_id'] ?? null);
        $supplierId = $this->linked('supplier', $partnerId);
        $variants = Val::id($d['product_id'] ?? null) ? [Val::id($d['product_id'])] : $this->variantsOf(Val::id($d['product_tmpl_id'] ?? null));
        $productLabel = Val::label($d['product_id'] ?? null) ?? Val::label($d['product_tmpl_id'] ?? null) ?? 'Odoo product';
        $supplierLabel = Val::label($d['partner_id'] ?? null) ?? "Odoo partner #{$partnerId}";
        $common = [
            'supplier_id' => $supplierId, 'price' => round(Val::float($d['price'] ?? 0), 4), 'currency' => self::currency($d['currency_id'] ?? null), 'discount_pct' => round(Val::float($d['discount'] ?? 0), 4),
            'min_qty' => round(Val::float($d['min_qty'] ?? 0), 3), 'lead_days' => Val::int($d['delay'] ?? 0) ?: null, 'valid_from' => Val::date($d['date_start'] ?? null), 'valid_to' => Val::date($d['date_end'] ?? null),
            'supplier_sku' => Val::text($d['product_code'] ?? null, 120), 'supplier_product_name' => Val::text($d['product_name'] ?? null, 250), 'sequence' => Val::int($d['sequence'] ?? 0),
            'active' => true, 'source_deleted_at' => null,
        ];
        if (! $supplierId) {
            $this->count('supplierPrices.waitingSupplier', "#{$r->odoo_id} {$supplierLabel}");
        }
        $targets = [];
        foreach ($this->productTargets($r->odoo_id, $variants) as $ref => $productId) {
            $waits = array_filter([$supplierId ? null : "المورد: {$supplierLabel}", $productId ? null : "المنتج: {$productLabel}"]);
            $targets[$ref] = ['product_id' => $productId, 'pending_label' => $waits ? mb_substr(implode(' · ', $waits), 0, 250) : null] + $common;
            if (! $productId) {
                $this->count('supplierPrices.waitingProduct', "#{$r->odoo_id} {$productLabel}");
            }
        }
        $this->reconcile('supplierPrices', SupplierPrice::class, 'SupplierPrice', $existing, $targets, $r, mb_substr($supplierLabel, 0, 60));
    }

    // ───────────── shared by rules and supplier prices ─────────────

    /**
     * The OPS products an Odoo record prices, keyed by the `external_ref` of the OPS row that carries each: the record's
     * own id for the first, `<id>/<variant>` for the others. No linked product: one row that waits (product null).
     *
     * @param  int[]  $variants  Odoo product ids
     * @return array<string, ?string>
     */
    private function productTargets(int $odooId, array $variants): array
    {
        $out = [];
        sort($variants);
        foreach ($variants as $variant) {
            if ($productId = $this->linked('product', $variant)) {
                $out[$out ? "{$odooId}/{$variant}" : (string) $odooId] = $productId;
            }
        }

        return $out ?: [(string) $odooId => null];
    }

    /** The variants of a product template (the OPS product is linked to the variant). @return int[] */
    private function variantsOf(?int $templateId): array
    {
        if (! $templateId) {
            return [];
        }
        if ($this->variants === null) {
            $this->variants = [];
            foreach (OdooRecord::query()->toBase()->where('model', 'product.product')->whereNull('deleted_at')->orderBy('odoo_id')->get(['odoo_id', 'data->product_tmpl_id as tpl']) as $v) {
                if ($tpl = Val::id(self::json($v->tpl))) {
                    $this->variants[$tpl][] = (int) $v->odoo_id;
                }
            }
        }

        return $this->variants[$templateId] ?? [];
    }

    /** The OPS rows one Odoo record became, by `external_ref`. @return array<string, Model> */
    private function rowsOf(string $table, string $class, int $odooId): array
    {
        if (! $this->installed) {
            return [];
        }
        if (! $this->whole) {
            return $class::where('source_system', self::SYSTEM)->where(fn ($w) => $w->where('external_ref', (string) $odooId)->orWhere('external_ref', 'like', $odooId.'/%'))->get()->keyBy('external_ref')->all();
        }
        if (! isset($this->rows[$table])) {
            $this->rows[$table] = [];
            foreach ($class::where('source_system', self::SYSTEM)->get() as $row) {
                $this->rows[$table][(int) strtok((string) $row->external_ref, '/')][(string) $row->external_ref] = $row;
            }
        }

        return $this->rows[$table][$odooId] ?? [];
    }

    /**
     * Makes the OPS rows of one Odoo record what they should be: created, updated, left alone — and a row whose target
     * the record no longer names is archived.
     *
     * @param  array<string, Model>  $existing  @param  array<string, array>  $wanted  external_ref → columns
     */
    private function reconcile(string $table, string $class, string $type, array $existing, array $wanted, OdooRecord $r, ?string $label): void
    {
        $at = Val::at($r->write_date);
        foreach ($wanted as $ref => $values) {
            $row = $existing[$ref] ?? null;
            if (! $row) {
                $this->count("{$table}.created");
                if (! $this->dry) {
                    DB::transaction(function () use ($class, $type, $values, $ref, $at, $r, $label, $table) {
                        $row = $class::create($values + ['source_system' => self::SYSTEM, 'external_ref' => (string) $ref, 'source_updated_at' => $at, 'created_by' => 'svc.odoo']);
                        $this->audit->log($this->actor(), ['action' => 'PRICING.'.strtoupper($type === 'PriceRule' ? 'rule' : 'supplierprice').'.CREATE', 'entityType' => $type, 'entityId' => $row->id, 'entityNumber' => $label,
                            'newValue' => array_filter(['price' => $values['fixed_price'] ?? $values['price'] ?? null, 'minQty' => $values['min_qty'], 'origin' => "from Odoo {$r->model} #{$r->odoo_id}"], fn ($v) => $v !== null)]);
                        if ($this->whole) {
                            $this->rows[$table][$r->odoo_id][(string) $ref] = $row;
                        }
                    });
                }

                continue;
            }
            [$old, $new] = self::changes($row, $values);
            $this->count($new ? "{$table}.updated" : "{$table}.unchanged");
            if ($new && ! $this->dry) {
                DB::transaction(function () use ($row, $type, $old, $new, $at, $label) {
                    $row->update($new + ['source_updated_at' => $at, 'updated_by' => 'svc.odoo']);
                    $this->audit->log($this->actor(), ['action' => 'PRICING.'.strtoupper($type === 'PriceRule' ? 'rule' : 'supplierprice').'.UPDATE', 'entityType' => $type, 'entityId' => $row->id, 'entityNumber' => $label,
                        'oldValue' => $old, 'newValue' => $new + ['origin' => 'Odoo']]);
                });
            }
        }
        foreach (array_diff_key($existing, $wanted) as $row) {
            if (! $row->active) {
                continue; // retired by an earlier pass
            }
            $this->count("{$table}.archived");
            if (! $this->dry) {
                DB::transaction(function () use ($row, $type, $label) {
                    $row->update(['active' => false, 'updated_by' => 'svc.odoo']);
                    $this->audit->status($this->actor(), $type, $row->id, $label, 'active', 'archived', 'no longer named by its Odoo record');
                });
            }
        }
    }

    /** The record no longer exists in Odoo: its OPS rows are archived and say so. */
    private function retire(string $table, array $existing, OdooRecord $r, string $type): void
    {
        if (! $existing) {
            $this->count("{$table}.skippedDeleted");

            return;
        }
        foreach ($existing as $row) {
            if (! $row->active && $row->source_deleted_at !== null) {
                $this->count("{$table}.unchanged");

                continue;
            }
            $this->count("{$table}.archived");
            if (! $this->dry) {
                DB::transaction(function () use ($row, $r, $type) {
                    $row->update(['active' => false, 'source_deleted_at' => $r->deleted_at, 'updated_by' => 'svc.odoo']);
                    $this->audit->status($this->actor(), $type, $row->id, null, 'active', 'archived', "deleted in Odoo ({$r->model} #{$r->odoo_id})");
                });
            }
        }
    }

    /**
     * Something was linked since the last look — a product, a supplier, a category or a customer of Odoo became an OPS
     * record (the master data did it). What waited for it is taken again from the copy: the rules and supplier prices
     * that had no product or supplier, the list price of the product, the price list of the customer.
     *
     * @return bool it happened
     */
    private function afterNewLinks(): bool
    {
        $links = $this->linkCount();
        if ((int) $this->markOf('links')?->seen_id === $links) {
            return false;
        }
        $waiting = [
            'product.pricelist.item' => PriceRule::where('source_system', self::SYSTEM)->where('active', true)
                ->where(fn ($w) => $w->where(fn ($x) => $x->where('applies_to', 'product')->whereNull('product_id'))->orWhere(fn ($x) => $x->where('applies_to', 'category')->whereNull('category_id')))
                ->limit(1000)->pluck('external_ref'),
            'product.supplierinfo' => SupplierPrice::where('source_system', self::SYSTEM)->where('active', true)->where(fn ($w) => $w->whereNull('supplier_id')->orWhereNull('product_id'))->limit(1000)->pluck('external_ref'),
        ];
        foreach ($waiting as $model => $refs) {
            foreach ($refs->map(fn ($ref) => (int) strtok((string) $ref, '/'))->unique() as $odooId) {
                if ($record = $this->mirrored($model, $odooId)) {
                    $this->attempt("{$model} #{$odooId}", fn () => $this->project($record));
                }
            }
        }
        $this->listPricesWhole();
        $this->listPrices = null; // the rest of the run reads a product's row when it needs it
        $this->assignmentsWhole();
        $this->mark('links', null, $links);

        return true;
    }

    private function linkCount(): int
    {
        return IntExternalRef::where('system', self::SYSTEM)->whereIn('entity', ['product', 'supplier', 'category', 'customer'])->count();
    }

    // ───────────── the list price of each product ─────────────

    private function listPricesWhole(): void
    {
        if (! $this->sourceReady(self::TEMPLATES)) {
            $this->count('listPrices.copyNotReady');

            return;
        }
        $this->listPrices = $this->installed ? ProductListPrice::query()->get()->keyBy('product_id')->all() : [];
        foreach (OdooRecord::query()->toBase()->where('model', self::TEMPLATES)->orderBy('odoo_id')->select(['id', 'odoo_id', 'deleted_at', 'write_date', 'data->list_price as list_price'])->lazy(1000) as $row) {
            $this->attempt(self::TEMPLATES." #{$row->odoo_id}", fn () => $this->templatePrice($row));
        }
    }

    /** The sales price of one Odoo product template → the list price of the OPS product(s) it is. */
    private function templatePrice(object $row): void
    {
        if ($row->deleted_at !== null || $row->list_price === null) {
            return; // the product is gone from Odoo, or the copy does not carry its sales price: the last list price stays
        }
        $price = round(Val::float(self::json($row->list_price)), 4);
        $products = array_filter($this->productTargets((int) $row->odoo_id, $this->variantsOf((int) $row->odoo_id)));
        if (! $products) {
            $this->count('listPrices.skippedNoProduct');

            return;
        }
        foreach ($products as $productId) {
            $existing = $this->listPrices !== null ? ($this->listPrices[$productId] ?? null) : ($this->installed ? ProductListPrice::where('product_id', $productId)->first() : null);
            if (! $existing) {
                if ($price <= 0) {
                    $this->count('listPrices.skippedZero'); // Odoo holds no sales price for it: no list price here either

                    continue;
                }
                $this->count('listPrices.created');
                if (! $this->dry) {
                    DB::transaction(function () use ($productId, $price, $row) {
                        $created = ProductListPrice::create(['product_id' => $productId, 'price' => $price, 'currency' => $this->companyCurrency(), 'source_system' => self::SYSTEM,
                            'external_ref' => (string) $row->odoo_id, 'source_updated_at' => Val::at($row->write_date), 'created_by' => 'svc.odoo']);
                        $this->audit->log($this->actor(), ['action' => 'PRICING.LISTPRICE.SET', 'entityType' => 'Product', 'entityId' => $productId, 'field' => 'listPrice', 'oldValue' => null,
                            'newValue' => ['price' => $price, 'origin' => "from Odoo product template #{$row->odoo_id}"]]);
                        if ($this->listPrices !== null) {
                            $this->listPrices[$productId] = $created;
                        }
                    });
                }

                continue;
            }
            $same = abs($existing->price - $price) < 0.00005;
            $adopt = $existing->source_system !== self::SYSTEM;
            if ($same && ! $adopt) {
                $this->count('listPrices.unchanged');

                continue;
            }
            // a list price typed in OPS for a product that is Odoo's: the product's sales price is Odoo's while the section follows it
            $this->count($adopt ? 'listPrices.adopted' : 'listPrices.updated');
            if (! $this->dry) {
                DB::transaction(function () use ($existing, $price, $row, $productId, $same) {
                    $old = $existing->price;
                    $existing->update(['price' => $price, 'source_system' => self::SYSTEM, 'external_ref' => (string) $row->odoo_id, 'source_updated_at' => Val::at($row->write_date), 'updated_by' => 'svc.odoo']);
                    if (! $same) {
                        $this->audit->log($this->actor(), ['action' => 'PRICING.LISTPRICE.SET', 'entityType' => 'Product', 'entityId' => $productId, 'field' => 'listPrice',
                            'oldValue' => ['price' => $old], 'newValue' => ['price' => $price, 'origin' => 'Odoo']]);
                    }
                });
            }
        }
    }

    // ───────────── the price list of each customer ─────────────

    private function assignmentsWhole(): void
    {
        if (! $this->sourceReady(self::PARTNERS)) {
            $this->count('assignments.copyNotReady');

            return;
        }
        $customers = IntExternalRef::where('system', self::SYSTEM)->where('entity', 'customer')->pluck('internal_id', 'external_id')->all();
        $copied = $this->partnerFieldCopied();
        // 1. the partners OPS shares with Odoo: what Odoo's own field says of each (where the copy carries it), and who is whose contact
        $own = $parent = [];
        foreach (OdooRecord::query()->toBase()->where('model', self::PARTNERS)->orderBy('odoo_id')->select(['id', 'odoo_id', 'deleted_at', 'data->parent_id as parent', 'data->'.AssignmentsService::PARTNER_FIELD.' as pricelist'])->lazy(1000) as $row) {
            $id = (int) $row->odoo_id;
            $parent[$id] = Val::id(self::json($row->parent));
            if ($copied && isset($customers[$id]) && $row->deleted_at === null) {
                $own[$id] = Val::id(self::json($row->pricelist)); // nothing there = no list of its own in Odoo
            }
        }
        // 2. where the copy does not carry the field: the price list of the customer's latest order that was not cancelled
        $latest = [];
        if (! $copied && config('odoo.pricing.assign_from_orders', true) && $this->sourceReady(self::ORDERS)) {
            $columns = ['id', 'odoo_id', 'deleted_at', 'data->partner_id as partner', 'data->pricelist_id as pricelist', 'data->date_order as date_order', 'data->state as state', 'data->name as name'];
            foreach (OdooRecord::query()->toBase()->where('model', self::ORDERS)->orderBy('odoo_id')->select($columns)->lazy(1000) as $row) {
                if (! ($order = self::order($row))) {
                    continue;
                }
                $partner = $order['partner'];
                for ($i = 0; $i < 5 && ! isset($customers[$partner]) && ! empty($parent[$partner]); $i++) {
                    $partner = $parent[$partner];
                }
                if (isset($customers[$partner]) && (! isset($latest[$partner]) || [$order['at']->getTimestamp(), $order['id']] > [$latest[$partner]['at']->getTimestamp(), $latest[$partner]['id']])) {
                    $latest[$partner] = $order;
                }
            }
            if (! $this->dry) {
                // which order spoke last for each customer: an older order that changes later says nothing new
                DB::transaction(function () use ($latest) {
                    DB::table('pricing_sync_marks')->where('source', 'like', 'order:%')->delete();
                    $now = now()->format('Y-m-d H:i:s.v');
                    foreach (array_chunk(array_keys($latest), 500) as $partners) {
                        DB::table('pricing_sync_marks')->insert(array_map(fn ($partner) => ['source' => "order:{$partner}", 'seen_at' => $latest[$partner]['at']->format('Y-m-d H:i:s.v'),
                            'seen_id' => $latest[$partner]['id'], 'updated_at' => $now], $partners));
                    }
                });
            }
        }
        // 3. every customer OPS shares with Odoo
        $this->assignments = $this->installed ? PriceListCustomer::query()->get()->keyBy('customer_id')->all() : [];
        foreach ($customers as $partnerId => $customerId) {
            $partnerId = (int) $partnerId;
            $this->attempt(self::PARTNERS." #{$partnerId}", function () use ($partnerId, $customerId, $own, $latest) {
                if (array_key_exists($partnerId, $own)) {
                    $this->assign((string) $customerId, $partnerId, $own[$partnerId], 'partner');
                } elseif (isset($latest[$partnerId])) {
                    $o = $latest[$partnerId];
                    $this->assign((string) $customerId, $partnerId, $o['list'], 'order', $o['name'], $o['id'], $o['at']);
                } else {
                    $this->count('assignments.noEvidence'); // the copy neither names its list nor holds an order of it: the default list
                }
            });
        }
        if (! $this->whole) {
            $this->assignments = null; // the rest of a run reads a customer's row when it needs it
        }
    }

    /**
     * Does the copy carry Odoo's own "price list of the partner"? Then that field decides for every partner (an empty
     * one has no list of its own) and the orders are not asked.
     */
    private function partnerFieldCopied(): bool
    {
        return $this->partnerField ??= $this->sourceReady(self::PARTNERS)
            && array_key_exists(AssignmentsService::PARTNER_FIELD, (array) OdooModel::where('model', self::PARTNERS)->first()?->fields);
    }

    /** A partner that changed in the copy (which carries Odoo's own field): the customer's list is what the field says. */
    private function partnerList(object $row): void
    {
        if ($row->deleted_at !== null || ! ($customerId = $this->linked('customer', (int) $row->odoo_id))) {
            return;
        }
        $this->assign($customerId, (int) $row->odoo_id, Val::id(self::json($row->pricelist)), 'partner');
    }

    /** An order that changed in the copy: a newer order than the one a customer's list was read from moves the list. */
    private function orderList(object $row): void
    {
        if (! config('odoo.pricing.assign_from_orders', true) || ! ($order = self::order($row))) {
            return;
        }
        $partner = $order['partner'];
        for ($i = 0; $i < 5 && ! $this->linked('customer', $partner); $i++) {
            $up = Val::id($this->mirrored(self::PARTNERS, $partner)?->data['parent_id'] ?? null);
            if (! $up) {
                return;
            }
            $partner = $up;
        }
        if (! ($customerId = $this->linked('customer', $partner))) {
            return;
        }
        $last = DB::table('pricing_sync_marks')->where('source', "order:{$partner}")->first();
        if ($last && $last->seen_at && (int) $last->seen_id !== $order['id']
            && [$order['at']->getTimestamp(), $order['id']] < [Carbon::parse($last->seen_at, 'UTC')->getTimestamp(), (int) $last->seen_id]) {
            return; // an older order than the one that spoke last for this customer
        }
        $this->assign($customerId, $partner, $order['list'], 'order', $order['name'], $order['id'], $order['at']);
        $this->mark("order:{$partner}", $order['at'], $order['id']);
    }

    /** @return array{id:int, partner:int, list:int, at:Carbon, name:?string}|null an order that says something about its customer's price list */
    private static function order(object $row): ?array
    {
        if ($row->deleted_at !== null || self::json($row->state) === 'cancel') {
            return null;
        }
        $partner = Val::id(self::json($row->partner));
        $list = Val::id(self::json($row->pricelist));
        $at = Val::at(self::json($row->date_order));
        if (! $partner || ! $list || ! $at) {
            return null;
        }

        return ['id' => (int) $row->odoo_id, 'partner' => $partner, 'list' => $list, 'at' => $at, 'name' => Val::text(self::json($row->name), 60)];
    }

    /**
     * The list of one customer as Odoo has it. `$basis`: partner (Odoo's own field) | order (read from an order).
     * Odoo's own field stands against everything; what was read from an order never replaces a person's choice nor
     * Odoo's own field. The default list means "no list of its own": the customer's row goes.
     */
    private function assign(string $customerId, int $partnerId, ?int $odooListId, string $basis, ?string $ref = null, ?int $refId = null, ?Carbon $at = null): void
    {
        $toDefault = ! $odooListId || $odooListId === $this->defaultOdooList();
        $list = $toDefault ? null : $this->listRow($odooListId);
        if (! $toDefault && ! $list && ! isset($this->plannedLists[$odooListId])) {
            $this->count('assignments.skippedNoList', "partner #{$partnerId} → list #{$odooListId}");

            return;
        }
        $row = $this->assignments !== null ? ($this->assignments[$customerId] ?? null) : ($this->installed ? PriceListCustomer::where('customer_id', $customerId)->first() : null);
        if ($row && $basis === 'order' && $row->basis !== 'order') {
            $this->count($row->basis === 'manual' ? 'assignments.keptManual' : 'assignments.unchanged');

            return;
        }
        if ($toDefault) {
            if (! $row) {
                $this->count('assignments.default');

                return;
            }
            $this->count('assignments.removed');
            if (! $this->dry) {
                DB::transaction(function () use ($row, $customerId, $basis) {
                    $old = ['list' => PriceList::whereKey($row->price_list_id)->value('code'), 'basis' => $row->basis];
                    $row->delete();
                    $this->audit->log($this->actor(), ['action' => 'PRICING.UNASSIGN', 'entityType' => 'Customer', 'entityId' => $customerId, 'field' => 'priceList', 'oldValue' => $old,
                        'newValue' => ['list' => null, 'origin' => $basis === 'partner' ? 'Odoo: the partner is on the default list' : 'Odoo: the latest order used the default list']]);
                });
                unset($this->assignments[$customerId]);
            }

            return;
        }
        if ($this->whole) {
            $this->count($basis === 'partner' ? 'assignments.fromPartner' : 'assignments.fromOrders');
        }
        $values = ['price_list_id' => $list?->id, 'basis' => $basis, 'basis_ref' => $ref, 'basis_odoo_id' => $refId, 'basis_at' => $at, 'source_system' => self::SYSTEM, 'external_ref' => (string) $partnerId];
        if (! $row) {
            $this->count('assignments.created');
            if (! $this->dry) {
                DB::transaction(function () use ($values, $customerId, $list, $basis, $ref) {
                    $created = PriceListCustomer::create($values + ['customer_id' => $customerId, 'created_by' => 'svc.odoo']);
                    $this->audit->log($this->actor(), ['action' => 'PRICING.ASSIGN', 'entityType' => 'Customer', 'entityId' => $customerId, 'field' => 'priceList', 'oldValue' => null,
                        'newValue' => array_filter(['list' => $list->code, 'basis' => $basis, 'basisRef' => $ref, 'origin' => 'Odoo'])]);
                    if ($this->assignments !== null) {
                        $this->assignments[$customerId] = $created;
                    }
                });
            }

            return;
        }
        $sameList = $list !== null && $row->price_list_id === $list->id;
        $this->count($sameList ? 'assignments.unchanged' : 'assignments.updated');
        if ($this->dry || ($sameList && $row->basis === $basis && $row->basis_odoo_id === $refId)) {
            return;
        }
        DB::transaction(function () use ($row, $values, $sameList, $customerId, $list, $basis, $ref) {
            $old = ['list' => PriceList::whereKey($row->price_list_id)->value('code'), 'basis' => $row->basis];
            $row->update($values + ($sameList ? [] : ['note' => null]) + ['updated_by' => 'svc.odoo']);
            if (! $sameList || $old['basis'] !== $basis) {
                $this->audit->log($this->actor(), ['action' => 'PRICING.ASSIGN', 'entityType' => 'Customer', 'entityId' => $customerId, 'field' => 'priceList', 'oldValue' => $old,
                    'newValue' => array_filter(['list' => $list->code, 'basis' => $basis, 'basisRef' => $ref, 'origin' => 'Odoo'])]);
            }
        });
    }

    // ───────────── reading the copy beside the feed ─────────────

    /** The copy holds this model completely. */
    private function sourceReady(string $model): bool
    {
        return $this->sourceReady[$model] ??= OdooModel::where('model', $model)->where('status', 'active')->where('full_state', 'done')->exists();
    }

    /**
     * Runs `$work` on every record of a model that changed in the copy since the section last looked, oldest first,
     * until the time is up. Where it stopped is remembered, so a run that is cut short loses nothing.
     *
     * @param  string[]  $columns  values of the record, as `data->field as name`
     */
    private function since(string $model, array $columns, callable $work, ?float $until): void
    {
        if (! $this->sourceReady($model)) {
            return;
        }
        $mark = $this->markOf($model);
        $looked = now();
        $query = OdooRecord::query()->toBase()->where('model', $model)->select(array_merge(['id', 'odoo_id', 'changed_at', 'deleted_at'], $columns));
        if ($mark?->seen_at) {
            $query->where(fn ($w) => $w->where('changed_at', '>', $mark->seen_at)->orWhere(fn ($x) => $x->where('changed_at', $mark->seen_at)->where('id', '>', (int) $mark->seen_id)));
        }
        $rows = $query->orderBy('changed_at')->orderBy('id')->limit(self::SIDE_LIMIT + 1)->get();
        if ($rows->isEmpty()) {
            return; // nothing changed since the mark: it stays where it is
        }
        $left = $rows->count() > self::SIDE_LIMIT;
        $last = null;
        foreach ($rows->take(self::SIDE_LIMIT) as $row) {
            if ($until !== null && $last !== null && microtime(true) >= $until) {
                $left = true;
                break;
            }
            $this->attempt("{$model} #{$row->odoo_id}", fn () => $work($row));
            $last = $row;
        }
        if ($left) {
            $this->mark($model, $last->changed_at, (int) $last->id); // the next look starts right after it

            return;
        }
        $this->mark($model, $looked->subSeconds(self::OVERLAP_SECONDS), 0);
    }

    /** Where the look beside the feed stands for one source (the marks are read once per pass). */
    private function markOf(string $source): ?object
    {
        if (! $this->installed) {
            return null;
        }
        $this->marks ??= DB::table('pricing_sync_marks')->where('source', 'not like', 'order:%')->get()->keyBy('source')->all();

        return $this->marks[$source] ?? null;
    }

    private function mark(string $source, Carbon|string|null $seenAt, int $seenId): void
    {
        $values = ['seen_at' => $seenAt instanceof Carbon ? $seenAt->format('Y-m-d H:i:s.v') : $seenAt, 'seen_id' => $seenId, 'updated_at' => now()->format('Y-m-d H:i:s.v')];
        DB::table('pricing_sync_marks')->updateOrInsert(['source' => $source], $values);
        if ($this->marks !== null && ! str_starts_with($source, 'order:')) {
            $this->marks[$source] = (object) (['source' => $source] + $values);
        }
    }

    // ───────────── small things ─────────────

    /** A value selected out of `data` as text: `[412, "name"]`, `47.5`, `false`, or the bare text of a string. */
    private static function json(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);

        return $decoded === null && strtolower(trim($value)) !== 'null' ? $value : $decoded;
    }

    /** `[151, "SAR"]` → SAR (three letters, or the company's currency). */
    private static function currency(mixed $value): string
    {
        $code = strtoupper((string) Val::label($value));

        return preg_match('/^[A-Z]{3}$/', $code) ? $code : 'SAR';
    }

    /** The day of an Odoo moment (UTC text) as the business counts days; a plain date stays what it is. */
    private static function localDay(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        return Val::at($value)?->setTimezone((string) config('app.business_timezone', 'UTC'))->toDateString();
    }

    /**
     * What differs between an OPS row and the values Odoo gives. @return array{0:array, 1:array} [old, new]
     */
    private static function changes(Model $row, array $values): array
    {
        $old = $new = [];
        foreach ($values as $column => $value) {
            $current = $row->getAttribute($column);
            if (! self::same($current, $value)) {
                $old[$column] = $current instanceof \DateTimeInterface ? $current->format('Y-m-d H:i:s') : $current;
                $new[$column] = $value;
            }
        }

        return [$old, $new];
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if ($a instanceof \DateTimeInterface || $b instanceof \DateTimeInterface) {
            $day = fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : (is_string($v) && strlen($v) === 10 ? $v.' 00:00:00' : $v);

            return $day($a) === $day($b);
        }
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }
        if (is_array($a) || is_array($b)) {
            // a JSON column gives its keys back in the database's own order
            $canon = function (mixed $v) use (&$canon) {
                if (is_array($v)) {
                    ksort($v);

                    return array_map($canon, $v);
                }

                return $v;
            };

            return json_encode($canon($a ?: null)) === json_encode($canon($b ?: null));
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.00005;
        }

        return (string) ($a ?? '') === (string) ($b ?? '');
    }
}
