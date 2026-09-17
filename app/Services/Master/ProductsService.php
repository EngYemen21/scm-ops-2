<?php

namespace App\Services\Master;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductStorageRequirement;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Products, barcodes, product ↔ supplier links, the category tree and units of measure.
 * Payload arrays are camelCase and validated by the controller; a present key with a null value means "sent empty".
 */
class ProductsService
{
    /** Arabic labels for per-field product audit rows. */
    private const FIELD_LABELS = [
        'nameAr' => 'الاسم', 'nameEn' => 'الاسم الإنجليزي', 'barcode' => 'الباركود', 'category' => 'التصنيف', 'brand' => 'العلامة', 'uom' => 'الوحدة',
        'lengthCm' => 'الطول', 'widthCm' => 'العرض', 'heightCm' => 'الارتفاع', 'weightKg' => 'الوزن', 'storageClass' => 'التخزين', 'shelfLifeDays' => 'العمر',
        'reorderMin' => 'حد الطلب', 'reorderMax' => 'الحد الأقصى', 'purchasePrice' => 'سعر الشراء', 'preferredSupplier' => 'المورد', 'unitsPerPallet' => 'وحدات الطبلية',
        'tracksExpiry' => 'تتبع الصلاحية', 'homeWarehouse' => 'المستودع الرئيسي', 'active' => 'الحالة',
    ];

    private const HOME_WAREHOUSE = 'homeWarehouse:id,code,name_ar,name_en';

    public function __construct(private readonly AuditService $audit) {}

    // ───────────────────────────── products ─────────────────────────────

    /** @param  array{category?:?string, storageClass?:?string, active?:?string, warehouse?:?string, supplier?:?string}  $filters */
    public function list(Paging $page, array $filters): array
    {
        $query = Product::query()->with(['category', 'baseUom', self::HOME_WAREHOUSE, 'barcodes' => fn ($q) => $q->where('is_primary', true)->orderBy('id')]);
        $active = PartnersService::flag($filters['active'] ?? null);
        if ($active !== null) {
            $query->where('active', $active);
        }
        if (! empty($filters['storageClass'])) {
            $query->where('storage_class', self::storageKey($filters['storageClass']));
        }
        if (! empty($filters['category'])) {
            $category = $filters['category'];
            $query->whereHas('category', fn ($c) => $c->where(fn ($w) => $w->where('code', $category)->orWhere('id', $category)
                ->orWhereHas('parent', fn ($p) => $p->where('code', $category)->orWhere('id', $category))));
        }
        if (! empty($filters['warehouse'])) {
            $query->whereHas('homeWarehouse', fn ($w) => $w->where('code', $filters['warehouse']));
        }
        if (! empty($filters['supplier'])) {
            $query->whereHas('suppliers.supplier', fn ($s) => $s->where('code', $filters['supplier']));
        }
        if ($page->q) {
            $q = $page->q;
            $query->where(function ($w) use ($q) {
                foreach (['sku', 'name_ar', 'name_en', 'brand'] as $column) {
                    $w->orWhere($column, 'like', "%{$q}%");
                }
                $w->orWhereHas('barcodes', fn ($b) => $b->where('barcode', 'like', "%{$q}%"));
            });
        }
        $sortable = ['sku' => 'sku', 'nameAr' => 'name_ar', 'createdAt' => 'created_at', 'weightKg' => 'weight_kg', 'reorderMin' => 'reorder_min'];
        if (isset($sortable[$page->sort ?? ''])) {
            $query->orderBy($sortable[$page->sort], $page->order);
        } else {
            $query->orderBy('sku');
        }
        $query->orderBy('id');

        return $page->paginate($query, function (Product $p) {
            $row = $p->toArray();
            $row['barcodes'] = array_slice($row['barcodes'] ?? [], 0, 1);
            $row['primaryBarcode'] = $row['barcodes'][0]['barcode'] ?? null;

            return $row;
        });
    }

    public function get(string $idOrSku): array
    {
        $p = Product::where(fn ($w) => $w->where('id', $idOrSku)->orWhere('sku', $idOrSku))->with([
            'category.parent', 'baseUom', self::HOME_WAREHOUSE, 'storageReq',
            'barcodes' => fn ($q) => $q->with('uom')->orderByDesc('is_primary')->orderBy('barcode'),
            'suppliers' => fn ($q) => $q->with('supplier:id,code,name_ar,name_en,score,lead_days,is_new,active')->orderByDesc('preferred')->orderBy('id'),
        ])->first() ?? throw AppError::notFound('PRODUCT_NOT_FOUND', 'المنتج غير موجود', 'Product not found');

        return $p->toArray() + ['primaryBarcode' => self::primaryOf($p->barcodes)?->barcode];
    }

    public function create(AuthUser $actor, array $dto): array
    {
        $sku = mb_strtoupper(trim($dto['sku']));
        $this->checkPhysical($dto, false);
        if (Product::where('sku', $sku)->exists()) {
            throw AppError::conflict('SKU_TAKEN', "رمز المنتج {$sku} مستخدم", "SKU {$sku} already exists");
        }
        $barcode = trim((string) ($dto['barcode'] ?? '')) ?: null;
        if ($barcode && ProductBarcode::where('barcode', $barcode)->exists()) {
            throw AppError::conflict('BARCODE_TAKEN', "الباركود {$barcode} مستخدم لمنتج آخر", 'Barcode already assigned');
        }
        $refs = $this->resolveRefs($dto);
        $storageClass = self::storageKey($dto['storageClass'] ?? null);
        $id = DB::transaction(function () use ($actor, $dto, $sku, $barcode, $refs, $storageClass) {
            $created = Product::create([
                'sku' => $sku, 'name_ar' => $dto['nameAr'], 'name_en' => ($dto['nameEn'] ?? null) ?: $dto['nameAr'], 'brand' => ($dto['brand'] ?? null) ?: null,
                'category_id' => ($refs['category'] ?? null)?->id, 'base_uom_id' => ($refs['uom'] ?? null)?->id, 'storage_class' => $storageClass,
                'weight_kg' => $dto['weightKg'], 'units_per_pallet' => $dto['unitsPerPallet'] ?? null, 'purchase_price' => $dto['purchasePrice'] ?? null,
                'tracks_expiry' => $dto['tracksExpiry'] ?? $storageClass !== 'ambient', 'reorder_min' => $dto['reorderMin'] ?? 0, 'reorder_max' => $dto['reorderMax'] ?? null,
                'length_cm' => $dto['lengthCm'], 'width_cm' => $dto['widthCm'], 'height_cm' => $dto['heightCm'],
                'volume_m3' => self::volume($dto['lengthCm'], $dto['widthCm'], $dto['heightCm']), 'shelf_life_days' => $dto['shelfLifeDays'] ?? null,
                'home_warehouse_id' => ($refs['warehouse'] ?? null)?->id, 'preferred_supplier_name' => ($refs['supplier'] ?? null)?->name_ar,
            ]);
            ProductStorageRequirement::create([
                'product_id' => $created->id, 'storage_class' => $storageClass,
                'min_temp_c' => match ($storageClass) {
                    'chilled' => 2, 'frozen' => -20, default => null
                },
                'max_temp_c' => match ($storageClass) {
                    'chilled' => 6, 'frozen' => -16, default => null
                },
            ]);
            if ($barcode) {
                ProductBarcode::create(['product_id' => $created->id, 'barcode' => $barcode, 'is_primary' => true, 'uom_id' => ($refs['uom'] ?? null)?->id]);
            }
            if ($refs['supplier'] ?? null) {
                ProductSupplier::create(['product_id' => $created->id, 'supplier_id' => $refs['supplier']->id, 'price' => $dto['purchasePrice'] ?? null, 'preferred' => true]);
            }
            $this->audit->log($actor, ['action' => 'PRODUCT.CREATE', 'entityType' => 'Product', 'entityId' => $created->id, 'entityNumber' => $sku, 'newValue' => ['sku' => $sku] + $dto]);

            return $created->id;
        });

        return $this->get($id);
    }

    /** Edit with mandatory reason; every changed field gets its own audit row. Historical documents keep their snapshots. */
    public function update(AuthUser $actor, string $idOrSku, array $dto): array
    {
        $reason = trim((string) ($dto['reason'] ?? ''));
        if ($reason === '') {
            throw AppError::validation('REASON_REQUIRED', 'سبب التعديل مطلوب', 'Edit reason is required');
        }
        $this->checkPhysical($dto, true);
        $p = $this->findProduct($idOrSku);
        $refs = $this->resolveRefs($dto);
        $barcode = array_key_exists('barcode', $dto) ? trim((string) $dto['barcode']) : null; // null = not sent, '' = remove
        if ($barcode) {
            $other = ProductBarcode::where('barcode', $barcode)->first();
            if ($other && $other->product_id !== $p->id) {
                throw AppError::conflict('BARCODE_TAKEN', "الباركود {$barcode} مستخدم لمنتج آخر", 'Barcode already assigned');
            }
        }

        $changes = [];
        $data = [];
        $set = function (string $field, string $column, mixed $old, mixed $new, mixed $dbValue = null, bool $useDbValue = false) use (&$changes, &$data) {
            if (Shape::same($old, $new)) {
                return;
            }
            $changes[] = ['field' => $field, 'old' => self::plain($old), 'new' => self::plain($new)];
            $data[$column] = $useDbValue ? $dbValue : $new;
        };
        if (isset($dto['nameAr'])) {
            $set('nameAr', 'name_ar', $p->name_ar, $dto['nameAr']);
        }
        if (array_key_exists('nameEn', $dto)) {
            $set('nameEn', 'name_en', $p->name_en, $dto['nameEn'] ?: $p->name_en);
        }
        if (array_key_exists('brand', $dto)) {
            $set('brand', 'brand', $p->brand, $dto['brand'] ?: null);
        }
        if (array_key_exists('category', $refs)) {
            $set('category', 'category_id', $p->category?->code, $refs['category']?->code, $refs['category']?->id, true);
        }
        if (array_key_exists('uom', $refs)) {
            $set('uom', 'base_uom_id', $p->baseUom?->code, $refs['uom']?->code, $refs['uom']?->id, true);
        }
        if (array_key_exists('warehouse', $refs)) {
            $set('homeWarehouse', 'home_warehouse_id', $p->homeWarehouse?->code, $refs['warehouse']?->code, $refs['warehouse']?->id, true);
        }
        if (array_key_exists('supplier', $refs)) {
            $set('preferredSupplier', 'preferred_supplier_name', $p->preferred_supplier_name, $refs['supplier']?->name_ar);
        }
        if (isset($dto['storageClass'])) {
            $set('storageClass', 'storage_class', $p->storage_class, self::storageKey($dto['storageClass']));
        }
        foreach (['weightKg' => 'weight_kg', 'unitsPerPallet' => 'units_per_pallet', 'reorderMin' => 'reorder_min', 'reorderMax' => 'reorder_max', 'lengthCm' => 'length_cm', 'widthCm' => 'width_cm', 'heightCm' => 'height_cm', 'shelfLifeDays' => 'shelf_life_days', 'purchasePrice' => 'purchase_price'] as $k => $column) {
            if (isset($dto[$k])) {
                $current = $p->getAttribute($column);
                $set($k, $column, $current === null ? null : $current + 0, $dto[$k]);
            }
        }
        if (isset($dto['tracksExpiry'])) {
            $set('tracksExpiry', 'tracks_expiry', (bool) $p->tracks_expiry, (bool) $dto['tracksExpiry']);
        }
        if (isset($dto['active'])) {
            $set('active', 'active', (bool) $p->active, (bool) $dto['active']);
        }
        $primary = self::primaryOf($p->barcodes);
        $oldBarcode = $primary?->barcode;
        $barcodeChanged = $barcode !== null && (string) $oldBarcode !== $barcode;
        if ($barcodeChanged) {
            $changes[] = ['field' => 'barcode', 'old' => $oldBarcode, 'new' => $barcode ?: null];
        }
        if (! $changes) {
            return ['ok' => true, 'changed' => 0, 'messageAr' => 'لا تغييرات', 'messageEn' => 'No changes'];
        }
        if (array_intersect(['lengthCm', 'widthCm', 'heightCm'], array_column($changes, 'field'))) {
            $data['volume_m3'] = self::volume($data['length_cm'] ?? $p->length_cm, $data['width_cm'] ?? $p->width_cm, $data['height_cm'] ?? $p->height_cm);
        }

        DB::transaction(function () use ($actor, $p, $dto, $data, $refs, $barcode, $barcodeChanged, $primary, $changes, $reason) {
            $wasActive = (bool) $p->active;
            if ($data) {
                Product::where('id', $p->id)->update($data + ['updated_at' => now()]);
            }
            if (! empty($data['storage_class'])) {
                ProductStorageRequirement::updateOrCreate(['product_id' => $p->id], ['storage_class' => $data['storage_class']]);
            }
            if ($barcodeChanged) {
                if (! $barcode) {
                    $primary?->delete();
                } elseif ($primary) {
                    $primary->update(['barcode' => $barcode, 'is_primary' => true]);
                } else {
                    ProductBarcode::create(['product_id' => $p->id, 'barcode' => $barcode, 'is_primary' => true, 'uom_id' => $p->base_uom_id]);
                }
            }
            if ($refs['supplier'] ?? null) {
                ProductSupplier::where('product_id', $p->id)->update(['preferred' => false]);
                $link = ProductSupplier::where('product_id', $p->id)->where('supplier_id', $refs['supplier']->id)->first();
                if ($link) {
                    $link->update(['preferred' => true]);
                } else {
                    ProductSupplier::create(['product_id' => $p->id, 'supplier_id' => $refs['supplier']->id, 'preferred' => true, 'price' => $dto['purchasePrice'] ?? null]);
                }
            }
            foreach ($changes as $c) {
                $this->audit->log($actor, ['action' => 'PRODUCT.EDIT', 'entityType' => 'Product', 'entityId' => $p->id, 'entityNumber' => $p->sku, 'field' => $c['field'], 'oldValue' => $c['old'], 'newValue' => $c['new']]);
            }
            $this->audit->log($actor, ['action' => 'PRODUCT.UPDATE', 'entityType' => 'Product', 'entityId' => $p->id, 'entityNumber' => $p->sku, 'field' => 'reason',
                'newValue' => ['reason' => $reason, 'changed' => array_map(fn ($c) => self::FIELD_LABELS[$c['field']] ?? $c['field'], $changes)]]);
            if (isset($dto['active']) && (bool) $dto['active'] !== $wasActive) {
                $this->audit->status($actor, 'Product', $p->id, $p->sku, $wasActive ? 'active' : 'inactive', $dto['active'] ? 'active' : 'inactive', $reason);
            }
        });
        $n = count($changes);

        return [
            'ok' => true, 'changed' => $n,
            'fields' => array_map(fn ($c) => ['field' => $c['field'], 'labelAr' => self::FIELD_LABELS[$c['field']] ?? $c['field'], 'old' => $c['old'], 'new' => $c['new']], $changes),
            'messageAr' => "حُفظ التعديل — {$n} حقل مسجل في Audit Trail", 'messageEn' => "Saved — {$n} field(s) recorded in the audit trail",
        ];
    }

    public function setActive(AuthUser $actor, string $idOrSku, bool $active, ?string $reason = null): array
    {
        $p = $this->findProduct($idOrSku);
        if ((bool) $p->active === $active) {
            throw AppError::rule('PRODUCT_STATE', $active ? 'المنتج نشط بالفعل' : 'المنتج موقوف بالفعل', $active ? 'Product already active' : 'Product already inactive');
        }
        DB::transaction(function () use ($actor, $p, $active, $reason) {
            $from = $p->active ? 'active' : 'inactive';
            $p->update(['active' => $active]);
            $this->audit->status($actor, 'Product', $p->id, $p->sku, $from, $active ? 'active' : 'inactive', $reason ?: null);
        });

        return [
            'ok' => true, 'active' => $active,
            'messageAr' => $active ? "أُعيد تفعيل {$p->name_ar}" : "أُوقف المنتج {$p->name_ar} — البيانات التاريخية محفوظة",
            'messageEn' => $active ? 'Product activated' : 'Product deactivated — history preserved',
        ];
    }

    // ───────────────────────────── barcodes ─────────────────────────────

    public function addBarcode(AuthUser $actor, string $idOrSku, array $dto): ProductBarcode
    {
        $p = $this->findProduct($idOrSku);
        $barcode = trim($dto['barcode']);
        $existing = ProductBarcode::where('barcode', $barcode)->first();
        if ($existing) {
            throw AppError::conflict('BARCODE_TAKEN', $existing->product_id === $p->id ? "الباركود {$barcode} مسجل لهذا المنتج" : "الباركود {$barcode} مستخدم لمنتج آخر", 'Barcode already assigned');
        }
        $uomId = null;
        if (! empty($dto['uomCode'])) {
            $uom = Uom::where('code', $dto['uomCode'])->orWhere('id', $dto['uomCode'])->first() ?? throw AppError::validation('BAD_UOM', 'وحدة القياس غير معروفة', 'Unknown UoM');
            $uomId = $uom->id;
        }
        $isPrimary = ! empty($dto['isPrimary']) || $p->barcodes->isEmpty();

        return DB::transaction(function () use ($actor, $p, $dto, $barcode, $uomId, $isPrimary) {
            if ($isPrimary) {
                ProductBarcode::where('product_id', $p->id)->update(['is_primary' => false]);
            }
            $row = ProductBarcode::create(['product_id' => $p->id, 'barcode' => $barcode, 'uom_id' => $uomId, 'is_primary' => $isPrimary]);
            $this->audit->log($actor, ['action' => 'PRODUCT.BARCODE_ADD', 'entityType' => 'Product', 'entityId' => $p->id, 'entityNumber' => $p->sku, 'field' => 'barcode',
                'newValue' => ['barcode' => $barcode, 'uom' => $dto['uomCode'] ?? null, 'isPrimary' => $isPrimary]]);

            return $row;
        });
    }

    public function removeBarcode(AuthUser $actor, string $idOrSku, string $barcode): array
    {
        $p = $this->findProduct($idOrSku);
        $row = $p->barcodes->first(fn (ProductBarcode $b) => $b->barcode === $barcode || $b->id === $barcode)
            ?? throw AppError::notFound('BARCODE_NOT_FOUND', 'الباركود غير مسجل لهذا المنتج', 'Barcode not found on product');
        DB::transaction(function () use ($actor, $p, $row) {
            $row->delete();
            if ($row->is_primary) {
                $p->barcodes->first(fn (ProductBarcode $b) => $b->id !== $row->id)?->update(['is_primary' => true]);
            }
            $this->audit->log($actor, ['action' => 'PRODUCT.BARCODE_REMOVE', 'entityType' => 'Product', 'entityId' => $p->id, 'entityNumber' => $p->sku, 'field' => 'barcode', 'oldValue' => $row->barcode]);
        });

        return ['ok' => true];
    }

    // ───────────────────────────── product ↔ supplier ─────────────────────────────

    public function linkSupplier(AuthUser $actor, string $idOrSku, array $dto): ProductSupplier
    {
        $p = $this->findProduct($idOrSku);
        $s = Supplier::where('code', $dto['supplierCode'])->orWhere('id', $dto['supplierCode'])->first()
            ?? throw AppError::notFound('SUPPLIER_NOT_FOUND', 'المورد غير موجود', 'Supplier not found');
        $prev = $p->suppliers->firstWhere('supplier_id', $s->id);
        $preferred = $dto['preferred'] ?? null;

        return DB::transaction(function () use ($actor, $p, $s, $dto, $prev, $preferred) {
            if ($preferred) {
                ProductSupplier::where('product_id', $p->id)->update(['preferred' => false]);
            }
            if ($prev) {
                $row = ProductSupplier::findOrFail($prev->id);
                $row->update([
                    'supplier_sku' => $dto['supplierSku'] ?? $prev->supplier_sku, 'price' => $dto['price'] ?? $prev->price,
                    'lead_days' => $dto['leadDays'] ?? $prev->lead_days, 'preferred' => $preferred ?? $prev->preferred ?? false,
                ]);
            } else {
                $row = ProductSupplier::create([
                    'product_id' => $p->id, 'supplier_id' => $s->id, 'supplier_sku' => ($dto['supplierSku'] ?? null) ?: null, 'price' => $dto['price'] ?? null,
                    'lead_days' => $dto['leadDays'] ?? null, 'preferred' => (bool) $preferred,
                ]);
            }
            if ($preferred) {
                $p->update(['preferred_supplier_name' => $s->name_ar]);
            }
            $this->audit->log($actor, [
                'action' => $prev ? 'PRODUCT.SUPPLIER_UPDATE' : 'PRODUCT.SUPPLIER_LINK', 'entityType' => 'Product', 'entityId' => $p->id, 'entityNumber' => $p->sku, 'field' => 'supplier',
                'oldValue' => $prev ? ['supplier' => $s->code, 'price' => $prev->price === null ? null : (float) $prev->price, 'leadDays' => $prev->lead_days, 'preferred' => $prev->preferred] : null,
                'newValue' => ['supplier' => $s->code, 'price' => $dto['price'] ?? null, 'leadDays' => $dto['leadDays'] ?? null, 'preferred' => $preferred],
            ]);

            return $row->refresh()->load('supplier');
        });
    }

    public function unlinkSupplier(AuthUser $actor, string $idOrSku, string $supplierCode): array
    {
        $p = $this->findProduct($idOrSku);
        $link = $p->suppliers->first(fn (ProductSupplier $x) => $x->supplier?->code === $supplierCode || $x->supplier_id === $supplierCode || $x->id === $supplierCode)
            ?? throw AppError::notFound('LINK_NOT_FOUND', 'المورد غير مرتبط بهذا المنتج', 'Supplier not linked to product');
        DB::transaction(function () use ($actor, $p, $link) {
            ProductSupplier::where('id', $link->id)->delete();
            if ($link->preferred) {
                $p->update(['preferred_supplier_name' => null]);
            }
            $this->audit->log($actor, ['action' => 'PRODUCT.SUPPLIER_UNLINK', 'entityType' => 'Product', 'entityId' => $p->id, 'entityNumber' => $p->sku, 'field' => 'supplier', 'oldValue' => $link->supplier?->code]);
        });

        return ['ok' => true];
    }

    // ───────────────────────────── categories (tree) ─────────────────────────────

    /** Three levels, each node with `_count.products`; the deepest level carries no `children` key (as the reference). */
    public function categoryTree(bool $includeInactive = true): array
    {
        $all = ProductCategory::query()->when(! $includeInactive, fn ($q) => $q->where('active', true))->withCount('products')->orderBy('name_ar')->orderBy('id')->get();
        $byParent = $all->groupBy(fn (ProductCategory $c) => $c->parent_id ?? '');
        $node = function (ProductCategory $c, int $depth) use (&$node, $byParent) {
            $row = Shape::withCounts($c, ['products']);
            if ($depth < 3) {
                $row['children'] = ($byParent->get($c->id) ?? collect())->map(fn (ProductCategory $child) => $node($child, $depth + 1))->values()->all();
            }

            return $row;
        };

        return ($byParent->get('') ?? collect())->map(fn (ProductCategory $c) => $node($c, 1))->values()->all();
    }

    public function categoriesFlat(): array
    {
        // Roots last, like the reference (PostgreSQL sorts NULLs after values on ASC).
        return ProductCategory::query()->withCount(['products', 'children'])->with('parent:id,code,name_ar')
            ->orderByRaw('parent_id is null')->orderBy('parent_id')->orderBy('name_ar')->orderBy('id')->get()
            ->map(fn (ProductCategory $c) => Shape::withCounts($c, ['products', 'children']))->values()->all();
    }

    public function getCategory(string $idOrCode): array
    {
        $c = $this->findCategory($idOrCode)->load(['parent', 'children' => fn ($q) => $q->withCount('products')])->loadCount('products');
        $row = Shape::withCounts($c, ['products']);
        $row['children'] = $c->children->map(fn (ProductCategory $child) => Shape::withCounts($child, ['products']))->values()->all();

        return $row;
    }

    public function createCategory(AuthUser $actor, array $dto): array
    {
        $code = mb_strtolower(trim($dto['code']));
        self::assertCategoryCode($code);
        if (ProductCategory::where('code', $code)->exists()) {
            throw AppError::conflict('CATEGORY_CODE_TAKEN', "الرمز {$code} مستخدم", 'Category code already exists');
        }
        $parent = $this->resolveParent($dto['parentId'] ?? null);
        $c = DB::transaction(function () use ($actor, $dto, $code, $parent) {
            $r = ProductCategory::create(['code' => $code, 'name_ar' => $dto['nameAr'], 'name_en' => ($dto['nameEn'] ?? null) ?: $dto['nameAr'], 'parent_id' => $parent?->id, 'active' => $dto['active'] ?? true]);
            $this->audit->log($actor, ['action' => 'CATEGORY.CREATE', 'entityType' => 'ProductCategory', 'entityId' => $r->id, 'entityNumber' => $code, 'newValue' => $dto + ['parent' => $parent?->code]]);

            return $r->refresh();
        });

        return $c->toArray() + ['messageAr' => $parent ? "أُضيف {$dto['nameAr']} تحت {$parent->name_ar}" : "أُضيف التصنيف {$dto['nameAr']}"];
    }

    public function updateCategory(AuthUser $actor, string $idOrCode, array $dto): array
    {
        $c = $this->findCategory($idOrCode);
        $data = [];
        if (isset($dto['code'])) {
            $code = mb_strtolower(trim($dto['code']));
            self::assertCategoryCode($code);
            if ($code !== $c->code && ProductCategory::where('code', $code)->exists()) {
                throw AppError::conflict('CATEGORY_CODE_TAKEN', "الرمز {$code} مستخدم", 'Category code already exists');
            }
            $data['code'] = $code;
        }
        if (isset($dto['nameAr'])) {
            $data['nameAr'] = $dto['nameAr'];
        }
        if (array_key_exists('nameEn', $dto)) {
            $data['nameEn'] = $dto['nameEn'] ?: (($dto['nameAr'] ?? null) ?: $c->name_ar);
        }
        if (isset($dto['active'])) {
            $data['active'] = (bool) $dto['active'];
        }
        if (array_key_exists('parentId', $dto)) {
            $data['parentId'] = $this->resolveParent($dto['parentId'], $c->id)?->id;
        }
        DB::transaction(function () use ($actor, $c, $data) {
            $old = ['code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en, 'parentId' => $c->parent_id, 'active' => $c->active];
            $c->update(Shape::snake($data));
            if (($data['active'] ?? null) === false) {
                ProductCategory::where('parent_id', $c->id)->update(['active' => false]);
            }
            $this->audit->log($actor, ['action' => 'CATEGORY.UPDATE', 'entityType' => 'ProductCategory', 'entityId' => $c->id, 'entityNumber' => $old['code'], 'oldValue' => $old, 'newValue' => $data]);
        });

        return ['ok' => true, 'messageAr' => 'حُفظ التصنيف'];
    }

    // ───────────────────────────── units of measure ─────────────────────────────

    public function uoms(): array
    {
        return Uom::withCount('products')->orderBy('code')->get()->map(fn (Uom $u) => Shape::withCounts($u, ['products']))->values()->all();
    }

    public function createUom(AuthUser $actor, array $dto): Uom
    {
        $code = mb_strtolower(trim($dto['code']));
        if (Uom::where('code', $code)->exists()) {
            throw AppError::conflict('UOM_CODE_TAKEN', "رمز الوحدة {$code} مستخدم", 'UoM code already exists');
        }

        return DB::transaction(function () use ($actor, $dto, $code) {
            $u = Uom::create(['code' => $code, 'name_ar' => $dto['nameAr'], 'name_en' => ($dto['nameEn'] ?? null) ?: $dto['nameAr']]);
            $this->audit->log($actor, ['action' => 'UOM.CREATE', 'entityType' => 'Uom', 'entityId' => $u->id, 'entityNumber' => $code, 'newValue' => $dto]);

            return $u;
        });
    }

    public function updateUom(AuthUser $actor, string $idOrCode, array $dto): Uom
    {
        $u = Uom::where('id', $idOrCode)->orWhere('code', $idOrCode)->first() ?? throw AppError::notFound('UOM_NOT_FOUND', 'وحدة القياس غير موجودة', 'UoM not found');
        $data = [];
        if (isset($dto['nameAr'])) {
            $data['nameAr'] = $dto['nameAr'];
        }
        if (array_key_exists('nameEn', $dto)) {
            $data['nameEn'] = $dto['nameEn'] ?: $u->name_ar;
        }

        return DB::transaction(function () use ($actor, $u, $data) {
            $old = ['nameAr' => $u->name_ar, 'nameEn' => $u->name_en];
            $u->update(Shape::snake($data));
            $this->audit->log($actor, ['action' => 'UOM.UPDATE', 'entityType' => 'Uom', 'entityId' => $u->id, 'entityNumber' => $u->code, 'oldValue' => $old, 'newValue' => $data]);

            return $u->refresh();
        });
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /** Normalises the prototype's `Ambient|Chilled|Frozen|مبرد|مجمد` spellings to the storage-class key. */
    public static function storageKey(?string $value): string
    {
        $s = mb_strtolower((string) $value);
        if (str_starts_with($s, 'froz') || str_contains($s, 'مجمد')) {
            return 'frozen';
        }
        if (str_starts_with($s, 'chill') || str_contains($s, 'مبرد')) {
            return 'chilled';
        }

        return 'ambient';
    }

    private static function volume(mixed $l, mixed $w, mixed $h): ?float
    {
        return $l && $w && $h ? round(((float) $l * (float) $w * (float) $h) / 1e6, 6) : null;
    }

    /** Whole floats become ints so audit rows read "30", not "30.0" (same text as the reference). */
    private static function plain(mixed $value): mixed
    {
        return is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX ? (int) $value : $value;
    }

    /** @param  Collection<int, ProductBarcode>  $barcodes */
    private static function primaryOf(Collection $barcodes): ?ProductBarcode
    {
        return $barcodes->firstWhere('is_primary', true) ?? $barcodes->first();
    }

    private function findProduct(string $idOrSku): Product
    {
        return Product::where(fn ($w) => $w->where('id', $idOrSku)->orWhere('sku', $idOrSku))
            ->with(['barcodes' => fn ($q) => $q->orderBy('id'), 'suppliers.supplier', 'category', 'baseUom', 'homeWarehouse', 'storageReq'])->first()
            ?? throw AppError::notFound('PRODUCT_NOT_FOUND', 'المنتج غير موجود', 'Product not found');
    }

    private function findCategory(string $idOrCode): ProductCategory
    {
        return ProductCategory::where('id', $idOrCode)->orWhere('code', $idOrCode)->first()
            ?? throw AppError::notFound('CATEGORY_NOT_FOUND', 'التصنيف غير موجود', 'Category not found');
    }

    /**
     * Resolves human codes (category/uom/warehouse/supplier) to rows, failing loudly on unknown references.
     * A key is only present in the result when the matching input key was sent (null = clear the reference).
     *
     * @return array{category?:?ProductCategory, uom?:?Uom, warehouse?:?Warehouse, supplier?:?Supplier}
     */
    private function resolveRefs(array $dto): array
    {
        $out = [];
        if (array_key_exists('categoryCode', $dto)) {
            $v = $dto['categoryCode'];
            $out['category'] = $v ? (ProductCategory::where('code', $v)->orWhere('id', $v)->first() ?? throw AppError::validation('BAD_CATEGORY', 'التصنيف غير معروف', 'Unknown category')) : null;
        }
        if (array_key_exists('uomCode', $dto)) {
            $v = $dto['uomCode'];
            $out['uom'] = $v ? (Uom::where('code', $v)->orWhere('id', $v)->first() ?? throw AppError::validation('BAD_UOM', 'وحدة القياس غير معروفة', 'Unknown UoM')) : null;
        }
        if (array_key_exists('homeWarehouseCode', $dto)) {
            $v = $dto['homeWarehouseCode'];
            $out['warehouse'] = $v ? (Warehouse::where('code', mb_strtoupper($v))->orWhere('id', $v)->first() ?? throw AppError::validation('BAD_WAREHOUSE', 'المستودع غير معروف', 'Unknown warehouse')) : null;
        }
        if (array_key_exists('preferredSupplierCode', $dto)) {
            $v = $dto['preferredSupplierCode'];
            $out['supplier'] = $v ? (Supplier::where('code', $v)->orWhere('id', $v)->orWhere('name_ar', $v)->first() ?? throw AppError::validation('BAD_SUPPLIER', 'المورد غير معروف', 'Unknown supplier')) : null;
        }

        return $out;
    }

    private function checkPhysical(array $dto, bool $partial): void
    {
        $bad = fn (mixed $v) => $partial ? $v !== null && ! ((float) $v > 0) : ! ((float) $v > 0);
        foreach (['lengthCm', 'widthCm', 'heightCm'] as $k) {
            if ($bad($dto[$k] ?? null)) {
                throw AppError::validation('PRODUCT_DIMS', 'الأبعاد يجب أن تكون أكبر من صفر', 'Dimensions must be greater than zero');
            }
        }
        if ($bad($dto['weightKg'] ?? null)) {
            throw AppError::validation('PRODUCT_WEIGHT', 'الوزن يجب أن يكون أكبر من صفر', 'Weight must be greater than zero');
        }
    }

    private function resolveParent(?string $parentId, ?string $selfId = null): ?ProductCategory
    {
        if (! $parentId) {
            return null;
        }
        $parent = ProductCategory::where('id', $parentId)->orWhere('code', $parentId)->first()
            ?? throw AppError::validation('BAD_PARENT', 'التصنيف الأب غير موجود', 'Parent category not found');
        if ($selfId && $parent->id === $selfId) {
            throw AppError::validation('SELF_PARENT', 'لا يمكن جعل التصنيف أبًا لنفسه', 'Category cannot be its own parent');
        }
        if ($selfId && $parent->parent_id === $selfId) {
            throw AppError::validation('CYCLIC_PARENT', 'التصنيف الأب هو تصنيف فرعي من هذا التصنيف', 'Cyclic category tree');
        }

        return $parent;
    }

    private static function assertCategoryCode(string $code): void
    {
        if (! preg_match('/^[a-z0-9_-]{2,}$/', $code)) {
            throw AppError::validation('CATEGORY_CODE', 'الرمز حروف لاتينية وأرقام فقط', 'Code must be Latin letters/digits');
        }
    }
}
