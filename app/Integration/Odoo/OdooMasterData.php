<?php

namespace App\Integration\Odoo;

use App\Integration\IntegrationBootstrap;
use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Services\ExternalRefs;
use App\Integration\Support\PackEstimator;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Services\Master\PartnersService;
use App\Services\Master\ProductsService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * From the mirror to the OPS master data: Odoo's products, customers and suppliers become OPS products, customers and
 * suppliers — created when OPS does not have them, linked when it does, refreshed when Odoo changes them.
 *
 * LINKING is by identity only, never by a similar name: an existing link, then the product code (Odoo's internal
 * reference = the OPS SKU), then for a supplier its VAT number. A customer has no such key: an Odoo customer whose name
 * is exactly the name of an OPS customer that is not linked yet is NOT created — it is listed for a person to decide.
 *
 * WHO OWNS WHAT. Odoo owns identity: name, code, category, "has an expiry date", active — and for partners name, VAT,
 * phone, city. OPS owns what Odoo does not hold and OPS people measure or decide: storage class, weight and dimensions,
 * reorder points, delivery zone, credit terms, supplier scores. A refresh never touches an OPS-owned value.
 *
 * ESTIMATES. Odoo has no weights or dimensions for these products. A new OPS product gets them estimated from the pack
 * text in its name ("1kg*12pcs" → 12 kg, a carton sized for that) and its storage class guessed from the name and the
 * category; the product's audit trail says so, and measured values replace them from the product screen.
 *
 * plan() counts what apply() would do; apply() walks the whole mirror (idempotent); consume() applies only what the
 * change feed reports — that is how an edit in Odoo reaches the OPS screens. Work is attributed to `svc.odoo`.
 *
 * COST. A whole-mirror pass reads the links and the OPS rows once and then works in memory, so a plan is a handful of
 * queries, not several per record. consume() works record by record within the time it is given, marks what it has
 * done as it goes, and one record that fails does not hold the others back.
 */
class OdooMasterData
{
    public const SYSTEM = 'odoo';

    /** Models whose changes this projection consumes. */
    public const MODELS = ['product.category', 'product.template', 'product.product', 'res.partner'];

    /**
     * The most records an apply started from the screen may write. A first load (thousands of products) is a job for
     * the command line (`php artisan scm:odoo-master --apply`), not for a web request.
     */
    public const WEB_APPLY_LIMIT = 150;

    /** The longest internal reference of Odoo that is taken as an OPS product code. */
    public const MAX_CODE = 64;

    private const ENTITY = ['product.category' => 'categories', 'product.template' => 'products', 'product.product' => 'products', 'res.partner' => 'customers'];

    private bool $dry = false;

    /** A whole-mirror pass (plan / apply): the OPS rows are read once, not one by one. */
    private bool $whole = false;

    /** Records of this pass that could not be applied. */
    private int $failed = 0;

    /** @var array<string, true> records of the feed this pass has already handled ("model:id") */
    private array $seen = [];

    /** @var array<string, array<int|string, string>> per entity: Odoo id → OPS id — read once per pass, kept current */
    private array $links = [];

    /** @var array<string, array<int|string, int|string>> per entity: OPS id → Odoo id */
    private array $back = [];

    /** @var array<int|string, Product>|null OPS products by id (a whole-mirror pass) */
    private ?array $opsProducts = null;

    /** @var array<int|string, Product>|null OPS products by upper-case code (a whole-mirror pass) */
    private ?array $codes = null;

    /** @var array<int|string, Customer>|null */
    private ?array $opsCustomers = null;

    /** @var array<int|string, Supplier>|null */
    private ?array $opsSuppliers = null;

    /** @var array<int|string, array<int, array{id:string, code:string}>>|null OPS customers by their Arabic name */
    private ?array $customerNames = null;

    /** @var array<int|string, Supplier[]>|null OPS suppliers by the digits of their VAT number */
    private ?array $supplierVats = null;

    /** @var array<int, string>|null names of Odoo's product categories by id */
    private ?array $odooCategories = null;

    /** @var array<int|string, ProductCategory>|null OPS categories by id */
    private ?array $opsCategories = null;

    /** @var array<int|string, int> codes a plan already gave to an Odoo product (code → Odoo id) */
    private array $plannedCodes = [];

    private ?AuthUser $actor = null;

    /** @var array<string, array<string, int>> */
    private array $counts = [];

    /** @var array<string, array<int, string>> a few examples per finding, for the person who reads the plan */
    private array $notes = [];

    /** @var array<int, array>|null product.template data by id */
    private ?array $templates = null;

    /** @var array<int, int[]>|null template id → variant ids */
    private ?array $variants = null;

    /** @var array<int, bool> categories a plan already counted */
    private array $plannedCategories = [];

    public function __construct(
        private readonly ExternalRefs $refs,
        private readonly ProductsService $products,
        private readonly PartnersService $partners,
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
    ) {}

    public function continuous(): bool
    {
        return (bool) config('odoo.project.master');
    }

    /** The mirror holds everything this projection reads. */
    public function ready(): bool
    {
        return OdooModel::whereIn('model', self::MODELS)->where('status', 'active')->where('full_state', 'done')->count() === count(self::MODELS);
    }

    /** What apply() would do, without doing it. */
    public function plan(): array
    {
        return $this->walk(true);
    }

    /** Creates, links and refreshes — the whole mirror, idempotent. */
    public function apply(): array
    {
        $out = $this->walk(false);
        // everything the feed reported so far is covered by this pass
        OdooChange::whereIn('model', self::MODELS)->whereNull('processed_at')->update(['processed_at' => now()]);

        return $out;
    }

    /**
     * Applies what changed in Odoo since the last time (the feed), oldest first, for at most `$seconds` (at least one
     * change is handled). What it handled is marked as it goes, so a run that is cut short loses nothing. A record that
     * fails is reported and counted (`failed`) and does not hold the others back; a whole-mirror apply() picks it up.
     *
     * @return array{changes:int, failed?:int, counts:array, notes?:array}
     */
    public function consume(int $max = 500, float $seconds = 10.0): array
    {
        $this->reset(false);
        $until = microtime(true) + $seconds;
        $changes = OdooChange::whereIn('model', self::MODELS)->whereNull('processed_at')->orderBy('id')->limit($max)->get(['id', 'model', 'odoo_id', 'kind']);
        if ($changes->isEmpty()) {
            return ['changes' => 0, 'counts' => []];
        }
        $done = [];
        $handled = 0;
        $mark = function () use (&$done) {
            if ($done) {
                OdooChange::whereIn('id', $done)->update(['processed_at' => now()]);
                $done = [];
            }
        };
        foreach ($changes as $c) {
            if ($handled > 0 && microtime(true) >= $until) {
                break; // the rest waits for the next run
            }
            $this->guarded(self::ENTITY[$c->model], $c->model, (int) $c->odoo_id, fn () => $this->one($c->model, (int) $c->odoo_id));
            $done[] = $c->id;
            $handled++;
            if (count($done) >= 50) {
                $mark();
            }
        }
        $mark();

        return ['changes' => $handled, 'counts' => $this->counts] + ($this->failed ? ['failed' => $this->failed, 'notes' => $this->notes] : []);
    }

    /** One record of the feed, as the mirror holds it now — once per pass, however many changes name it. */
    private function one(string $model, int $odooId): void
    {
        if (isset($this->seen["{$model}:{$odooId}"])) {
            return;
        }
        $this->seen["{$model}:{$odooId}"] = true;
        $r = OdooRecord::where('model', $model)->where('odoo_id', $odooId)->first();
        if (! $r) {
            return;
        }
        if ($model === 'product.template') {
            // a template carries the name, the category and the expiry flag of its variants
            foreach ($this->variantsOf($odooId) as $variantId) {
                $this->one('product.product', $variantId);
            }

            return;
        }
        match ($model) {
            'product.category' => $this->category($odooId),
            'product.product' => $this->product($r),
            'res.partner' => $this->partner($r),
        };
    }

    /**
     * One record's work. A record that cannot be applied — a value OPS cannot hold, a rule that refuses it — is reported
     * and counted as `failed`, and the pass goes on with the others; a pass that fails again and again is stopped,
     * because then the fault is not the record's.
     */
    private function guarded(string $entity, string $model, int $odooId, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            if (++$this->failed > 40) {
                throw $e;
            }
            report($e);
            $this->count($entity, 'failed', "{$model} #{$odooId}: ".mb_substr($e instanceof AppError ? $e->messageAr : $e->getMessage(), 0, 160));
        }
    }

    /**
     * What people see before deciding: what is linked so far, how many changes of Odoo wait to be applied and — when
     * asked for — the plan with the number of records an apply would write.
     */
    public function overview(bool $withPlan = false): array
    {
        $ready = $this->ready();
        $linked = IntExternalRef::where('system', self::SYSTEM)->selectRaw('entity, count(*) as n')->groupBy('entity')->pluck('n', 'entity');
        $out = [
            'continuous' => $this->continuous(), 'ready' => $ready,
            'linked' => ['products' => (int) ($linked['product'] ?? 0), 'customers' => (int) ($linked['customer'] ?? 0), 'suppliers' => (int) ($linked['supplier'] ?? 0), 'categories' => (int) ($linked['category'] ?? 0)],
            'waiting' => OdooChange::whereIn('model', self::MODELS)->whereNull('processed_at')->count(),
            'applyLimit' => self::WEB_APPLY_LIMIT, 'counts' => null, 'notes' => null, 'work' => null,
        ];
        if ($ready && $withPlan) {
            $plan = $this->plan();
            $out = ['counts' => $plan['counts'], 'notes' => $plan['notes'], 'work' => self::work($plan['counts'])] + $out;
        }

        return $out;
    }

    /** How many records an apply() of these counts writes (creates, links or refreshes). */
    public static function work(array $counts): int
    {
        $n = 0;
        foreach ($counts as $actions) {
            foreach (['created', 'linked', 'updated'] as $what) {
                $n += (int) ($actions[$what] ?? 0);
            }
        }

        return $n;
    }

    private function walk(bool $dry): array
    {
        if (! $this->ready()) {
            throw AppError::rule('ODOO_COPY_INCOMPLETE', 'النسخة الأولى من الأصناف والشركاء لم تكتمل بعد', 'The first copy of products and partners is not complete yet');
        }
        $this->reset($dry, true);
        foreach (OdooRecord::where('model', 'product.product')->orderBy('odoo_id')->lazy(500) as $r) {
            $this->guarded('products', 'product.product', $r->odoo_id, fn () => $this->product($r));
        }
        foreach (OdooRecord::where('model', 'res.partner')->orderBy('odoo_id')->lazy(500) as $r) {
            $this->guarded('customers', 'res.partner', $r->odoo_id, fn () => $this->partner($r));
        }

        return ['dryRun' => $dry, 'counts' => $this->counts, 'notes' => $this->notes];
    }

    private function reset(bool $dry, bool $whole = false): void
    {
        $this->dry = $dry;
        $this->whole = $whole;
        $this->failed = 0;
        $this->seen = [];
        $this->counts = ['products' => [], 'customers' => [], 'suppliers' => [], 'categories' => []];
        $this->notes = [];
        $this->templates = $this->variants = null;
        $this->plannedCategories = $this->plannedCodes = $this->links = $this->back = [];
        $this->opsProducts = $this->codes = $this->opsCustomers = $this->opsSuppliers = null;
        $this->customerNames = $this->supplierVats = $this->odooCategories = $this->opsCategories = null;
    }

    // ───────────── links and OPS rows of a pass ─────────────

    private function loadLinks(string $entity): void
    {
        if (! isset($this->links[$entity])) {
            $this->links[$entity] = IntExternalRef::where('system', self::SYSTEM)->where('entity', $entity)->pluck('internal_id', 'external_id')->all();
            $this->back[$entity] = array_flip($this->links[$entity]);
        }
    }

    /** The OPS id an Odoo record is linked to. */
    private function linked(string $entity, int|string $odooId): ?string
    {
        $this->loadLinks($entity);

        return $this->links[$entity][$odooId] ?? null;
    }

    /** The Odoo id an OPS row is linked to. */
    private function linkedFrom(string $entity, string $opsId): ?string
    {
        $this->loadLinks($entity);

        return isset($this->back[$entity][$opsId]) ? (string) $this->back[$entity][$opsId] : null;
    }

    private function link(string $entity, int|string $odooId, string $opsId, ?string $code): void
    {
        $this->refs->link(self::SYSTEM, $entity, (string) $odooId, $opsId, $code, null, 'svc.odoo');
        $this->linkedNow($entity, $odooId, $opsId);
    }

    /** Keeps the pass's picture of the links current after one was written. */
    private function linkedNow(string $entity, int|string $odooId, string $opsId): void
    {
        $this->loadLinks($entity);
        $this->links[$entity][$odooId] = $opsId;
        $this->back[$entity][$opsId] = $odooId;
    }

    private function loadProducts(): void
    {
        if ($this->opsProducts === null) {
            $this->opsProducts = $this->codes = [];
            foreach (Product::all() as $p) {
                $this->opsProducts[$p->id] = $p;
                $this->codes[mb_strtoupper((string) $p->sku)] = $p;
            }
        }
    }

    private function opsProduct(string $id): ?Product
    {
        if (! $this->whole) {
            return Product::find($id);
        }
        $this->loadProducts();

        return $this->opsProducts[$id] ?? null;
    }

    /** The OPS product that carries this code, whatever the letter case it was typed in. `$sku` is upper-case. */
    private function productByCode(string $sku): ?Product
    {
        if (! $this->whole) {
            return Product::whereRaw('upper(sku) = ?', [$sku])->first();
        }
        $this->loadProducts();

        return $this->codes[$sku] ?? null;
    }

    private function opsCustomer(string $id): ?Customer
    {
        if (! $this->whole) {
            return Customer::find($id);
        }
        $this->opsCustomers ??= Customer::all()->keyBy('id')->all();

        return $this->opsCustomers[$id] ?? null;
    }

    private function opsSupplier(string $id): ?Supplier
    {
        if (! $this->whole) {
            return Supplier::find($id);
        }
        $this->opsSuppliers ??= Supplier::all()->keyBy('id')->all();

        return $this->opsSuppliers[$id] ?? null;
    }

    /** OPS customers that carry this Arabic name (letter case and spacing aside). @return array<int, array{id:string, code:string}> */
    private function customersNamed(string $ar): array
    {
        if ($this->customerNames === null) {
            $this->customerNames = [];
            foreach (Customer::get(['id', 'code', 'name_ar']) as $c) {
                $this->customerNames[self::nameKey((string) $c->name_ar)][] = ['id' => (string) $c->id, 'code' => (string) $c->code];
            }
        }

        return $this->customerNames[self::nameKey($ar)] ?? [];
    }

    /** OPS suppliers that carry this VAT number (its digits). @return Supplier[] */
    private function suppliersWithVat(string $vat): array
    {
        if ($this->supplierVats === null) {
            $this->supplierVats = [];
            foreach (Supplier::whereNotNull('vat')->get() as $s) {
                if (($digits = self::digits($s->vat)) !== null) {
                    $this->supplierVats[$digits][] = $s;
                }
            }
        }

        return $this->supplierVats[$vat] ?? [];
    }

    private static function nameKey(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
    }

    private function count(string $entity, string $what, ?string $note = null): void
    {
        $this->counts[$entity][$what] = ($this->counts[$entity][$what] ?? 0) + 1;
        if ($note !== null && count($this->notes["{$entity}.{$what}"] ?? []) < 15) {
            $this->notes["{$entity}.{$what}"][] = $note;
        }
    }

    // ───────────── products ─────────────

    private function product(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $tpl = $this->template((int) ($d['product_tmpl_id'][0] ?? 0));
        $sku = mb_strtoupper(trim((string) ($d['default_code'] ?? '')));
        $linkedId = $this->linked('product', $r->odoo_id);
        $alive = $r->deleted_at === null && $r->active !== false && ($tpl['active'] ?? true) !== false;

        if (! $linkedId) {
            if (! $alive) {
                $this->count('products', 'skippedArchived');

                return;
            }
            if ($sku === '') {
                $this->count('products', 'skippedNoCode', (string) $r->display_name);

                return;
            }
            // a code is a short key people read and scan; a sentence typed into Odoo's reference field is not one
            if (mb_strlen($sku) > self::MAX_CODE || preg_match('/[\x00-\x1F]/u', $sku)) {
                $this->count('products', 'badCode', mb_substr((string) $r->display_name, 0, 80).' — '.mb_substr($sku, 0, 30).'…');

                return;
            }
            if (! in_array($tpl['detailed_type'] ?? $tpl['type'] ?? 'product', ['product', 'consu'], true)) {
                $this->count('products', 'skippedService', $sku);

                return;
            }
            $existing = $this->productByCode($sku);
            // two Odoo products with one code: the first is the product, the second is for a person to sort out in Odoo
            $other = $existing ? $this->linkedFrom('product', (string) $existing->id) : null;
            if ($other === null && $this->dry) {
                $other = $this->plannedCodes[$sku] ?? null; // a plan writes no link: it remembers the codes it has given
            }
            if ($other !== null) {
                $this->count('products', 'duplicateCode', "{$sku}: Odoo #{$other} و #{$r->odoo_id}");

                return;
            }
            $this->plannedCodes[$sku] = $r->odoo_id;
            if ($existing) {
                $this->count('products', 'linked');
                if (! $this->dry) {
                    $this->link('product', $r->odoo_id, (string) $existing->id, $existing->sku);
                    $this->refreshProduct($existing, $r, $tpl);
                }

                return;
            }
            if (! $this->dry) {
                $this->createProduct($sku, $r, $tpl);
            } else {
                $this->category((int) ($tpl['categ_id'][0] ?? 0));
            }
            $this->count('products', 'created');

            return;
        }
        $p = $this->opsProduct($linkedId);
        if (! $p) {
            return;
        }
        if ($this->dry) {
            $this->count('products', $this->productChanges($p, $r, $tpl, $alive) ? 'updated' : 'unchanged');

            return;
        }
        $this->count('products', $this->refreshProduct($p, $r, $tpl, $alive) ? 'updated' : 'unchanged');
    }

    private function createProduct(string $sku, OdooRecord $r, array $tpl): void
    {
        [$ar, $en] = self::names((string) ($tpl['name'] ?? $r->display_name));
        $category = $this->category((int) ($tpl['categ_id'][0] ?? 0));
        $kg = self::packWeightKg($en.' '.$ar);
        $guess = PackEstimator::estimate($ar, null, $category?->name_ar);
        $kg ??= $guess['weightKg'];
        $k = (($kg * 1.5 * 1000) / (4 * 3 * 2.5)) ** (1 / 3); // the same 4 : 3 : 2.5 carton PackEstimator sizes for a weight
        $barcode = trim((string) ($r->data['barcode'] ?? ''));
        if ($barcode !== '' && ProductBarcode::where('barcode', $barcode)->exists()) {
            $this->count('products', 'barcodeTaken', "{$sku}: {$barcode}");
            $barcode = '';
        }
        $p = DB::transaction(function () use ($sku, $r, $tpl, $ar, $en, $category, $kg, $k, $guess, $barcode) {
            $p = $this->products->create($this->actor(), [
                'sku' => $sku, 'nameAr' => $ar, 'nameEn' => $en, 'categoryCode' => $category?->code,
                'weightKg' => round($kg, 2), 'lengthCm' => max(10, (int) round(4 * $k)), 'widthCm' => max(8, (int) round(3 * $k)), 'heightCm' => max(5, (int) round(2.5 * $k)),
                'storageClass' => $guess['storageClass'], 'tracksExpiry' => ! empty($tpl['use_expiration_date']), 'reorderMin' => 0,
                'shelfLifeDays' => ((int) ($tpl['expiration_time'] ?? 0)) ?: null, 'barcode' => $barcode ?: null,
                'origin' => "from Odoo product #{$r->odoo_id} — weight, dimensions and storage class are estimates from its name",
            ]);
            $this->refs->link(self::SYSTEM, 'product', (string) $r->odoo_id, $p['id'], $p['sku'], null, 'svc.odoo');

            return $p;
        });
        $this->linkedNow('product', $r->odoo_id, (string) $p['id']);
        // a later Odoo product with the same code must find this one (a whole-mirror pass reads the products only once)
        if ($this->whole && ($row = Product::find($p['id']))) {
            $this->loadProducts();
            $this->opsProducts[$row->id] = $row;
            $this->codes[$sku] = $row;
        }
    }

    /** The Odoo-owned values of the product that differ from OPS. */
    private function productChanges(Product $p, OdooRecord $r, array $tpl, bool $alive = true): array
    {
        [$ar, $en] = self::names((string) ($tpl['name'] ?? $r->display_name));
        $dto = [];
        if ($ar !== '' && $p->name_ar !== $ar) {
            $dto['nameAr'] = $ar;
        }
        if ($en !== '' && $p->name_en !== $en) {
            $dto['nameEn'] = $en;
        }
        if ((bool) $p->tracks_expiry !== ! empty($tpl['use_expiration_date'])) {
            $dto['tracksExpiry'] = ! empty($tpl['use_expiration_date']);
        }
        if ((bool) $p->active !== $alive) {
            $dto['active'] = $alive;
        }
        $categoryId = (int) ($tpl['categ_id'][0] ?? 0);
        if ($categoryId) {
            $linked = $this->linked('category', $categoryId);
            if (! $linked || (string) $linked !== (string) $p->category_id) {
                $dto['__category'] = $categoryId;
            }
        }

        return $dto;
    }

    private function refreshProduct(Product $p, OdooRecord $r, array $tpl, bool $alive = true): bool
    {
        $dto = $this->productChanges($p, $r, $tpl, $alive);
        if (isset($dto['__category'])) {
            $category = $this->category($dto['__category']);
            unset($dto['__category']);
            if ($category && $category->id !== $p->category_id) {
                $dto['categoryCode'] = $category->code;
            }
        }
        if (! $dto) {
            return false;
        }
        try {
            $this->products->update($this->actor(), $p->id, $dto + ['reason' => 'تحديث من أودو']);
        } catch (AppError $e) {
            $this->count('products', 'refused', "{$p->sku}: {$e->messageAr}"); // an OPS rule said no (for example stock on a product being archived)

            return false;
        }
        if ($this->whole) {
            $p->refresh(); // the pass keeps this row: it must show what was just written
        }

        return true;
    }

    /** Odoo ids of the variants of a template (the link lives on the variant). @return int[] */
    private function variantsOf(int $templateId): array
    {
        if ($this->variants === null) {
            $this->variants = [];
            foreach (OdooRecord::where('model', 'product.product')->get(['odoo_id', 'data']) as $v) {
                $this->variants[(int) ($v->data['product_tmpl_id'][0] ?? 0)][] = $v->odoo_id;
            }
        }

        return $this->variants[$templateId] ?? [];
    }

    /** product.template values by id: all of them at once in a whole-mirror pass, otherwise only the one asked for. */
    private function template(int $id): array
    {
        if (! $id) {
            return [];
        }
        $values = fn (OdooRecord $t) => ((array) $t->data) + ['active' => $t->active];
        if ($this->whole) {
            $this->templates ??= OdooRecord::where('model', 'product.template')->get(['odoo_id', 'data', 'active'])->mapWithKeys(fn ($t) => [$t->odoo_id => $values($t)])->all();

            return $this->templates[$id] ?? [];
        }
        $this->templates ??= [];

        return $this->templates[$id] ??= ($t = OdooRecord::where('model', 'product.template')->where('odoo_id', $id)->first(['odoo_id', 'data', 'active'])) ? $values($t) : [];
    }

    /** The OPS category of an Odoo category: linked, or the OPS category of exactly that name, or a new one. */
    private function category(int $odooId): ?ProductCategory
    {
        if (! $odooId) {
            return null;
        }
        $this->odooCategories ??= OdooRecord::where('model', 'product.category')->get(['odoo_id', 'data'])->mapWithKeys(fn ($c) => [$c->odoo_id => trim((string) ($c->data['name'] ?? ''))])->all();
        $this->opsCategories ??= ProductCategory::all()->keyBy('id')->all();
        $name = $this->odooCategories[$odooId] ?? '';
        $linked = $this->linked('category', $odooId);
        if ($linked && ($c = $this->opsCategories[$linked] ?? null)) {
            if (! $this->dry && $name !== '' && $c->name_ar !== $name && str_starts_with($c->code, 'od-')) {
                $c->update(['name_ar' => $name, 'name_en' => $name]);
                $this->count('categories', 'updated');
            }

            return $c;
        }
        if ($name === '') {
            return null;
        }
        // the OPS category of exactly that name, or the one an earlier pass made for this Odoo category
        $named = fn (ProductCategory $x) => self::nameKey((string) $x->name_ar) === self::nameKey($name);
        $c = collect($this->opsCategories)->first($named) ?? collect($this->opsCategories)->first(fn (ProductCategory $x) => $x->code === "od-{$odooId}");
        if (! $c) {
            if ($this->dry) {
                if (! isset($this->plannedCategories[$odooId])) {
                    $this->plannedCategories[$odooId] = true;
                    $this->count('categories', 'created', $name);
                }

                return null;
            }
            $this->count('categories', 'created');
            $c = ProductCategory::create(['code' => "od-{$odooId}", 'name_ar' => $name, 'name_en' => $name, 'active' => true]);
            $this->opsCategories[$c->id] = $c;
            $this->audit->log($this->actor(), ['action' => 'CATEGORY.CREATE', 'entityType' => 'ProductCategory', 'entityId' => $c->id, 'entityNumber' => $c->code,
                'newValue' => ['nameAr' => $name, 'origin' => "from Odoo category #{$odooId}"]]);
        }
        if (! $this->dry) {
            $this->link('category', $odooId, (string) $c->id, $c->code);
        }

        return $c;
    }

    // ───────────── partners ─────────────

    private function partner(OdooRecord $r): void
    {
        $d = (array) $r->data;
        if (! empty($d['parent_id'])) {
            return; // a contact or an address of a company: the company is the partner
        }
        if (($d['customer_rank'] ?? 0) > 0 || $this->linked('customer', $r->odoo_id)) {
            $this->customer($r, $d);
        }
        if (($d['supplier_rank'] ?? 0) > 0 || $this->linked('supplier', $r->odoo_id)) {
            $this->supplier($r, $d);
        }
    }

    private function customer(OdooRecord $r, array $d): void
    {
        [$ar, $en] = self::names((string) ($d['name'] ?? $r->display_name));
        $alive = $r->deleted_at === null && $r->active !== false;
        $linkedId = $this->linked('customer', $r->odoo_id);
        $values = ['name_ar' => $ar, 'name_en' => $en, 'city' => self::text($d['city'] ?? null), 'vat_no' => self::digits($d['vat'] ?? null), 'active' => $alive];
        if ($linkedId) {
            $c = $this->opsCustomer($linkedId);
            if ($c) {
                $this->count('customers', $this->refresh($c, array_filter($values, fn ($v) => $v !== null), 'Customer', $c->code) ? 'updated' : 'unchanged');
            }

            return;
        }
        if (! $alive || $ar === '') {
            $this->count('customers', 'skippedArchived');

            return;
        }
        // no hard key links a customer: the same name on an unlinked OPS customer is a person's decision, not a guess
        $same = collect($this->customersNamed($ar))->first(fn (array $c) => $this->linkedFrom('customer', $c['id']) === null);
        if ($same) {
            $this->count('customers', 'needsReview', "{$ar} — {$same['code']}");

            return;
        }
        if ($this->dry) {
            $this->count('customers', 'created');

            return;
        }
        $id = DB::transaction(function () use ($r, $d, $ar, $en, $values) {
            $c = $this->partners->createCustomer($this->actor(), [
                'nameAr' => $ar, 'nameEn' => $en, 'city' => $values['city'], 'zone' => $values['city'] ?: 'غير محدد',
                'contact' => self::text($d['phone'] ?? null) ?: self::text($d['mobile'] ?? null) ?: '—',
                'address' => self::text(trim(($d['street'] ?? '').' '.($d['street2'] ?? ''))),
                'lat' => ((float) ($d['partner_latitude'] ?? 0)) ?: null, 'lng' => ((float) ($d['partner_longitude'] ?? 0)) ?: null,
            ]);
            // commercial identity is Odoo's from now on (PartnersService refuses edits of it in OPS)
            Customer::whereKey($c['id'])->update(['source_system' => self::SYSTEM, 'vat_no' => $values['vat_no']]);
            $this->refs->link(self::SYSTEM, 'customer', (string) $r->odoo_id, $c['id'], $c['code'], null, 'svc.odoo');

            return (string) $c['id'];
        });
        $this->linkedNow('customer', $r->odoo_id, $id);
        $this->count('customers', 'created');
    }

    private function supplier(OdooRecord $r, array $d): void
    {
        [$ar, $en] = self::names((string) ($d['name'] ?? $r->display_name));
        $alive = $r->deleted_at === null && $r->active !== false;
        $vat = self::digits($d['vat'] ?? null);
        $linkedId = $this->linked('supplier', $r->odoo_id);
        $values = ['name_ar' => $ar, 'name_en' => $en, 'active' => $alive] + ($vat ? ['vat' => $vat] : []);
        if ($linkedId) {
            $s = $this->opsSupplier($linkedId);
            if ($s) {
                $this->count('suppliers', $this->refresh($s, $values, 'Supplier', $s->code) ? 'updated' : 'unchanged');
            }

            return;
        }
        if (! $alive || $ar === '') {
            $this->count('suppliers', 'skippedArchived');

            return;
        }
        // the VAT number is a supplier's identity: the OPS supplier that carries it is the same supplier
        $existing = $vat ? collect($this->suppliersWithVat($vat))->first(fn (Supplier $s) => $this->linkedFrom('supplier', (string) $s->id) === null) : null;
        if ($existing) {
            $this->count('suppliers', 'linked');
            if (! $this->dry) {
                $this->link('supplier', $r->odoo_id, (string) $existing->id, $existing->code);
                $this->refresh($existing, $values, 'Supplier', $existing->code);
            }

            return;
        }
        if ($this->dry) {
            $this->count('suppliers', 'created');

            return;
        }
        $id = DB::transaction(function () use ($r, $d, $ar, $en, $vat) {
            $code = $this->numbering->next('SUP');
            // written directly: the supplier form demands a CR and a VAT number, Odoo is the source here and has what it has
            $s = Supplier::create([
                'code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'lead_days' => 5, 'score' => PartnersService::NEW_SUPPLIER_SCORE, 'otif' => 0, 'fill_rate' => 0, 'orders_count' => 0,
                'is_new' => true, 'vat' => $vat, 'contact' => self::text($d['phone'] ?? null) ?: self::text($d['mobile'] ?? null), 'email' => self::text($d['email'] ?? null),
                'address' => self::text(trim(($d['street'] ?? '').' '.($d['city'] ?? ''))), 'status' => 'active', 'active' => true,
            ]);
            $this->audit->log($this->actor(), ['action' => 'SUPPLIER.CREATE', 'entityType' => 'Supplier', 'entityId' => $s->id, 'entityNumber' => $code,
                'newValue' => ['nameAr' => $ar, 'vat' => $vat, 'origin' => "from Odoo partner #{$r->odoo_id}"]]);
            $this->refs->link(self::SYSTEM, 'supplier', (string) $r->odoo_id, $s->id, $code, null, 'svc.odoo');

            return (string) $s->id;
        });
        $this->linkedNow('supplier', $r->odoo_id, $id);
        $this->count('suppliers', 'created');
    }

    /** Writes the Odoo-owned values that differ and audits them. @return bool something changed */
    private function refresh(Customer|Supplier $row, array $values, string $type, string $code): bool
    {
        $old = $new = [];
        foreach ($values as $column => $value) {
            $current = $row->getAttribute($column);
            if (is_bool($value) ? (bool) $current !== $value : (string) $current !== (string) $value) {
                $old[$column] = $current;
                $new[$column] = $value;
            }
        }
        if (! $new || $this->dry) {
            return (bool) $new;
        }
        DB::transaction(function () use ($row, $old, $new, $type, $code) {
            $row->update($new + ($row instanceof Supplier && array_key_exists('active', $new) ? ['status' => $new['active'] ? 'active' : 'inactive'] : []));
            $this->audit->log($this->actor(), ['action' => strtoupper($type).'.UPDATE', 'entityType' => $type, 'entityId' => $row->id, 'entityNumber' => $code,
                'oldValue' => $old, 'newValue' => $new + ['origin' => 'Odoo']]);
        });

        return true;
    }

    // ───────────── helpers ─────────────

    /** The service user every change of this projection is attributed to (it cannot sign in). */
    private function actor(): AuthUser
    {
        if ($this->actor) {
            return $this->actor;
        }
        if (! DB::table('users')->where('username', 'svc.odoo')->exists()) {
            IntegrationBootstrap::ensure(); // the `integration` role
            $id = (string) Str::ulid();
            DB::table('users')->insert(['id' => $id, 'username' => 'svc.odoo', 'password_hash' => Hash::make(Str::random(64)), 'name_ar' => 'تكامل — أودو', 'name_en' => 'Integration — Odoo',
                'initials' => 'OD', 'active' => true, 'must_change_password' => false, 'created_at' => now(), 'updated_at' => now()]);
            if ($role = DB::table('roles')->where('key', 'integration')->value('id')) {
                DB::table('user_roles')->insert(['user_id' => $id, 'role_id' => $role]);
            }
        }

        return $this->actor = IntegrationBootstrap::actor('odoo');
    }

    /**
     * Odoo keeps one name, usually "Arabic name English name (pack)" — sometimes "Arabic name (pack) English name".
     * It is split at the first Latin word that is part of the English name (a unit inside the pack text, "5 kg", is
     * not one). The pack text is written once and belongs to both names. A leading "[CODE]" is Odoo's display
     * decoration, not part of the name. @return array{0:string, 1:string} [Arabic, English]
     */
    public static function names(string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', preg_replace('/^\s*\[[^\]]+\]\s*/u', '', $name)));
        if (! preg_match('/\p{Arabic}/u', $name)) {
            return [$name, $name];
        }
        // hide what is inside parentheses (same length), then look for the first Latin word that is not a unit
        $masked = preg_replace_callback('/\([^()]*\)/u', fn ($m) => str_repeat(' ', strlen($m[0])), $name);
        $at = null;
        if (preg_match_all('/[A-Za-z]{2,}/', $masked, $words, PREG_OFFSET_CAPTURE)) {
            foreach ($words[0] as [$word, $offset]) {
                if (! preg_match('/^(kgs?|kilo|gms?|gr|ml|ltrs?|lt|pcs?|ps|box|pkt|pack|ctn|can|btl|bag|x)$/i', $word)) {
                    $at = $offset;
                    break;
                }
            }
        }
        if ($at === null) {
            return [$name, $name];
        }
        $ar = trim(substr($name, 0, $at), " \t-–—|/");
        $en = trim(substr($name, $at));
        if ($ar === '' || ! preg_match('/\p{Arabic}/u', $ar)) {
            return [$name, $en ?: $name]; // the Latin text comes first: the whole name is kept as the Arabic one
        }
        if (preg_match('/\(([^()]*\d[^()]*)\)/u', $name, $p)) {
            $pack = '('.trim($p[1]).')';
            $ar = str_contains($ar, $p[0]) ? $ar : "{$ar} {$pack}";
            $en = str_contains($en, $p[0]) ? $en : "{$en} {$pack}";
        }

        return [mb_substr($ar, 0, 250), mb_substr($en, 0, 250)];
    }

    /**
     * The weight a pack text states, in kg: "1kg*12pcs" → 12, "125ml*18pcs" → 2.25, "45g*12ps*12box" → 6.48,
     * "24 x 330 ml" → 7.92, "5 L" → 5 (a litre counted as a kilogram). Null when no measure is written in Latin units.
     */
    public static function packWeightKg(string $text): ?float
    {
        $unit = '(kgs?|kilo|gms?|gr|g|ml|ltrs?|lt|l)';
        $kg = fn (float $n, string $u) => $n * match (true) {
            str_starts_with(strtolower($u), 'k') => 1.0, in_array(strtolower($u), ['l', 'lt', 'ltr', 'ltrs'], true) => 1.0, default => 0.001,
        };
        $num = '(\d+(?:\.\d+)?)';
        // a measure followed by one or more counts: 45g*12ps*12box
        if (preg_match("/{$num}\s*{$unit}\s*((?:[*x×]\s*\d+\s*[a-z]*\s*)+)/iu", $text, $m)) {
            $w = $kg((float) $m[1], $m[2]);
            preg_match_all('/[*x×]\s*(\d+)/iu', $m[3], $counts);
            foreach ($counts[1] as $n) {
                $w *= (int) $n;
            }
        } elseif (preg_match("/(\d+)\s*[*x×]\s*{$num}\s*{$unit}\b/iu", $text, $m)) {
            $w = (int) $m[1] * $kg((float) $m[2], $m[3]);
        } elseif (preg_match("/{$num}\s*{$unit}\b/iu", $text, $m)) {
            $w = $kg((float) $m[1], $m[2]);
        } else {
            return null;
        }

        return $w > 0 ? round(min(max($w, 0.1), 1000), 2) : null;
    }

    private static function text(mixed $v): ?string
    {
        $s = is_string($v) ? trim($v) : '';

        return $s === '' ? null : mb_substr($s, 0, 250);
    }

    /** The digits of a number people type with spaces, dashes or letters around it (a VAT number); never longer than OPS can hold. */
    private static function digits(mixed $v): ?string
    {
        $s = is_string($v) ? substr(preg_replace('/\D+/', '', $v), 0, 40) : '';

        return $s === '' ? null : $s;
    }
}
