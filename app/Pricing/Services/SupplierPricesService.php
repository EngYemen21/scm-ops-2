<?php

namespace App\Pricing\Services;

use App\Models\Supplier;
use App\Pricing\Models\SupplierPrice;
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
 * Supplier price lists: what each supplier charges for each product, from a quantity and inside a period — with the
 * lead time and the supplier's own code of the product. The price service reads them for "the best supplier price".
 * Nothing is deleted: a price that no longer holds is archived, or simply given an end date.
 *
 * One supplier may have several prices for one product (quantity tiers, successive periods); two active prices of the
 * same supplier, product and minimum quantity whose periods overlap are refused — the buyer could not tell which holds.
 */
class SupplierPricesService
{
    private const WITH = ['supplier:id,code,name_ar,name_en,active,status,lead_days', 'product:id,sku,name_ar,name_en,active'];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{supplier?:?string, product?:?string, state?:?string, period?:?string, source?:?string}  $f
     *                                                                                                           state: active (default) | archived | pending | all · period: valid | expired | future · source: odoo | ops
     */
    public function list(Paging $page, array $f): array
    {
        $today = Day::today();
        $day = $today->toDateString();
        $query = SupplierPrice::query()->with(self::WITH)->leftJoin('products', 'products.id', '=', 'supplier_prices.product_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'supplier_prices.supplier_id')->select('supplier_prices.*');
        match ($f['state'] ?? 'active') {
            'archived' => $query->where('supplier_prices.active', false),
            'pending' => $query->where('supplier_prices.active', true)->where(fn ($w) => $w->whereNull('supplier_prices.supplier_id')->orWhereNull('supplier_prices.product_id')),
            'all' => null,
            default => $query->where('supplier_prices.active', true),
        };
        match ($f['period'] ?? '') {
            'valid' => $query->where(fn ($w) => $w->whereNull('supplier_prices.valid_from')->orWhere('supplier_prices.valid_from', '<=', $day))
                ->where(fn ($w) => $w->whereNull('supplier_prices.valid_to')->orWhere('supplier_prices.valid_to', '>=', $day)),
            'expired' => $query->where('supplier_prices.valid_to', '<', $day),
            'future' => $query->where('supplier_prices.valid_from', '>', $day),
            default => null,
        };
        if (($f['source'] ?? null) === 'odoo') {
            $query->where('supplier_prices.source_system', 'odoo');
        } elseif (($f['source'] ?? null) === 'ops') {
            $query->whereNull('supplier_prices.source_system');
        }
        if (! empty($f['supplier'])) {
            $query->where('supplier_prices.supplier_id', Find::supplier((string) $f['supplier'])->id);
        }
        if (! empty($f['product'])) {
            $query->where('supplier_prices.product_id', Find::product((string) $f['product'])->id);
        }
        if ($page->q) {
            $like = '%'.$page->q.'%';
            $query->where(fn ($w) => $w->where('products.sku', 'like', $like)->orWhere('products.name_ar', 'like', $like)->orWhere('products.name_en', 'like', $like)
                ->orWhere('suppliers.code', 'like', $like)->orWhere('suppliers.name_ar', 'like', $like)->orWhere('suppliers.name_en', 'like', $like)
                ->orWhere('supplier_prices.supplier_sku', 'like', $like)->orWhere('supplier_prices.pending_label', 'like', $like));
        }
        $sortable = ['sku' => 'products.sku', 'supplier' => 'suppliers.name_ar', 'price' => 'supplier_prices.price', 'minQty' => 'supplier_prices.min_qty', 'leadDays' => 'supplier_prices.lead_days',
            'validTo' => 'supplier_prices.valid_to', 'updatedAt' => 'supplier_prices.updated_at', 'sequence' => 'supplier_prices.sequence'];
        if (isset($sortable[$page->sort ?? ''])) {
            $query->orderBy($sortable[$page->sort], $page->order);
        } else {
            $query->orderByRaw('products.sku IS NULL')->orderBy('products.sku')->orderBy('supplier_prices.sequence')->orderBy('supplier_prices.price');
        }

        return $page->paginate($query->orderBy('supplier_prices.id'), fn (SupplierPrice $s) => Shape::supplierPrice($s, $today)) + ['follows' => Owned::following()];
    }

    /** The suppliers that have prices: how many, for how many products, changed when — one "price list" per supplier. */
    public function suppliers(Paging $page): array
    {
        $day = Day::today()->toDateString();
        $agg = DB::table('supplier_prices')->whereNotNull('supplier_id')->where('active', true)->groupBy('supplier_id')
            ->selectRaw('supplier_id, COUNT(*) AS prices, COUNT(DISTINCT product_id) AS products, MAX(updated_at) AS last_at, SUM(CASE WHEN valid_to IS NOT NULL AND valid_to < ? THEN 1 ELSE 0 END) AS expired', [$day]);
        $query = Supplier::query()->joinSub($agg, 'sp', 'sp.supplier_id', '=', 'suppliers.id')->select('suppliers.*', 'sp.prices', 'sp.products', 'sp.last_at', 'sp.expired');
        if ($page->q) {
            $like = '%'.$page->q.'%';
            $query->where(fn ($w) => $w->where('suppliers.code', 'like', $like)->orWhere('suppliers.name_ar', 'like', $like)->orWhere('suppliers.name_en', 'like', $like));
        }
        $sortable = ['code' => 'suppliers.code', 'nameAr' => 'suppliers.name_ar', 'prices' => 'sp.prices', 'products' => 'sp.products', 'updatedAt' => 'sp.last_at'];
        if (isset($sortable[$page->sort ?? ''])) {
            $query->orderBy($sortable[$page->sort], $page->order);
        } else {
            $query->orderByDesc('sp.prices');
        }

        return $page->paginate($query->orderBy('suppliers.code')->orderBy('suppliers.id'), fn (Supplier $s) => Shape::party($s) + [
            'status' => $s->status, 'leadDays' => $s->lead_days, 'prices' => (int) $s->prices, 'products' => (int) $s->products, 'expired' => (int) $s->expired,
            'lastAt' => $s->last_at ? Carbon::parse($s->last_at)->toIso8601String() : null,
        ]);
    }

    public function get(string $id): array
    {
        return Shape::supplierPrice($this->find($id)->load(self::WITH));
    }

    public function create(AuthUser $user, array $dto): array
    {
        $row = DB::transaction(function () use ($user, $dto) {
            $values = $this->values($dto, null);
            $values['sequence'] ??= (int) SupplierPrice::where('product_id', $values['product_id'])->max('sequence') + 1;
            $s = SupplierPrice::create($values + ['active' => true, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'PRICING.SUPPLIERPRICE.CREATE', 'entityType' => 'SupplierPrice', 'entityId' => $s->id, 'entityNumber' => $this->label($s), 'newValue' => $dto]);

            return $s;
        });

        return Shape::supplierPrice($row->refresh()->load(self::WITH));
    }

    public function update(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $s = $this->find($id, true);
            Owned::refuse($s, 'supplierPrice');
            $values = $this->values($dto, $s);
            $old = $new = [];
            foreach ($values as $column => $value) {
                $current = $s->getAttribute($column);
                $current = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : $current;
                $same = is_numeric($value) && is_numeric($current) ? abs((float) $value - (float) $current) < 0.00005 : (string) ($current ?? '') === (string) ($value ?? '');
                if (! $same) {
                    $old[$column] = $current;
                    $new[$column] = $value;
                }
            }
            if ($new) {
                $s->update($new + ['updated_by' => $user->username]);
                $this->audit->log($user, ['action' => 'PRICING.SUPPLIERPRICE.UPDATE', 'entityType' => 'SupplierPrice', 'entityId' => $s->id, 'entityNumber' => $this->label($s), 'oldValue' => $old, 'newValue' => $new]);
            }

            return Shape::supplierPrice($s->refresh()->load(self::WITH)) + ['changed' => count($new)];
        });
    }

    public function archive(AuthUser $user, string $id, ?string $reason = null): array
    {
        return DB::transaction(function () use ($user, $id, $reason) {
            $s = $this->find($id, true);
            Owned::refuse($s, 'supplierPrice');
            if ($s->active) {
                $s->update(['active' => false, 'updated_by' => $user->username]);
                $this->audit->status($user, 'SupplierPrice', $s->id, $this->label($s), 'active', 'archived', $reason);
            }

            return Shape::supplierPrice($s->refresh()->load(self::WITH));
        });
    }

    public function restore(AuthUser $user, string $id): array
    {
        return DB::transaction(function () use ($user, $id) {
            $s = $this->find($id, true);
            Owned::refuse($s, 'supplierPrice');
            if (! $s->active) {
                if (! $s->pending()) {
                    $this->refuseTwin($s->supplier_id, $s->product_id, (float) $s->min_qty, $s->valid_from?->format('Y-m-d'), $s->valid_to?->format('Y-m-d'), $s->id);
                }
                $s->update(['active' => true, 'updated_by' => $user->username]);
                $this->audit->status($user, 'SupplierPrice', $s->id, $this->label($s), 'archived', 'active');
            }

            return Shape::supplierPrice($s->refresh()->load(self::WITH));
        });
    }

    // ───────────── inside ─────────────

    private function find(string $id, bool $lock = false): SupplierPrice
    {
        return SupplierPrice::whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->first()
            ?? throw AppError::notFound('SUPPLIER_PRICE_NOT_FOUND', 'سعر المورد غير موجود', 'Supplier price not found');
    }

    /** "SUP-0012 · P00325" — what the audit trail calls the record. */
    private function label(SupplierPrice $s): string
    {
        $s->loadMissing(self::WITH);

        return trim(($s->supplier?->code ?? '—').' · '.($s->product?->sku ?? '—'));
    }

    /** The columns of a supplier price from a payload laid over what it holds, checked as a whole. */
    private function values(array $dto, ?SupplierPrice $s): array
    {
        $has = fn (string $k) => array_key_exists($k, $dto);
        $bad = fn (string $path, string $ar, string $en) => AppError::validation('SUPPLIER_PRICE_INVALID', $ar, $en, [['path' => $path, 'message' => $ar]]);

        $supplierId = $has('supplier') && trim((string) $dto['supplier']) !== '' ? Find::supplier(trim((string) $dto['supplier']))->id : $s?->supplier_id;
        $productId = $has('product') && trim((string) $dto['product']) !== '' ? Find::product(trim((string) $dto['product']))->id : $s?->product_id;
        if (! $supplierId) {
            throw $bad('supplier', 'حدد المورد', 'Pick the supplier');
        }
        if (! $productId) {
            throw $bad('product', 'حدد المنتج', 'Pick the product');
        }
        $num = fn (string $k, float $current) => $has($k) && $dto[$k] !== null && $dto[$k] !== '' ? (float) $dto[$k] : ($has($k) ? 0.0 : $current);
        $price = round($num('price', (float) ($s?->price ?? 0)), 4);
        if ($price <= 0) {
            throw $bad('price', 'السعر يجب أن يكون أكبر من صفر', 'The price must be greater than zero');
        }
        $discount = round($num('discountPct', (float) ($s?->discount_pct ?? 0)), 4);
        if ($discount < 0 || $discount >= 100) {
            throw $bad('discountPct', 'الخصم بين 0 وأقل من 100', 'The discount is between 0 and less than 100');
        }
        $minQty = round($num('minQty', (float) ($s?->min_qty ?? 0)), 3);
        if ($minQty < 0) {
            throw $bad('minQty', 'الحد الأدنى للكمية لا يكون سالبًا', 'The minimum quantity cannot be negative');
        }
        $lead = $has('leadDays') ? ($dto['leadDays'] === null || $dto['leadDays'] === '' ? null : (int) $dto['leadDays']) : $s?->lead_days;
        if ($lead !== null && ($lead < 0 || $lead > 3650)) {
            throw $bad('leadDays', 'مهلة التوريد بالأيام بين 0 و3650', 'The lead time is between 0 and 3650 days');
        }
        $from = $has('validFrom') ? ($dto['validFrom'] ? Day::of($dto['validFrom'])->toDateString() : null) : $s?->valid_from?->format('Y-m-d');
        $to = $has('validTo') ? ($dto['validTo'] ? Day::of($dto['validTo'])->toDateString() : null) : $s?->valid_to?->format('Y-m-d');
        if ($from && $to && $from > $to) {
            throw $bad('validTo', 'نهاية المدة قبل بدايتها', 'The period ends before it starts');
        }
        $text = fn (string $k, ?string $current, int $max) => $has($k) ? (mb_substr(trim((string) $dto[$k]), 0, $max) ?: null) : $current;
        $v = [
            'supplier_id' => $supplierId, 'product_id' => $productId, 'price' => $price, 'currency' => $has('currency') && $dto['currency'] ? strtoupper(trim((string) $dto['currency'])) : ($s?->currency ?? 'SAR'),
            'discount_pct' => $discount, 'min_qty' => $minQty, 'lead_days' => $lead, 'valid_from' => $from, 'valid_to' => $to,
            'supplier_sku' => $text('supplierSku', $s?->supplier_sku, 120), 'supplier_product_name' => $text('supplierProductName', $s?->supplier_product_name, 250),
            'note' => $text('note', $s?->note, 2000), 'pending_label' => null,
        ];
        if ($has('sequence') && $dto['sequence'] !== null && $dto['sequence'] !== '') {
            $v['sequence'] = (int) $dto['sequence'];
        } elseif (! $s) {
            $v['sequence'] = null; // decided by create(): after the product's other suppliers
        }
        if (! $s || $s->active) {
            $this->refuseTwin($supplierId, $productId, $minQty, $from, $to, $s?->id);
        }

        return $v;
    }

    private function refuseTwin(string $supplierId, string $productId, float $minQty, ?string $from, ?string $to, ?string $exceptId): void
    {
        $twin = SupplierPrice::where('supplier_id', $supplierId)->where('product_id', $productId)->where('active', true)
            ->whereBetween('min_qty', [$minQty - 0.0005, $minQty + 0.0005])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->when($to, fn ($q) => $q->where(fn ($w) => $w->whereNull('valid_from')->orWhere('valid_from', '<=', $to)))
            ->when($from, fn ($q) => $q->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>=', $from)))
            ->first();
        if ($twin) {
            throw new AppError('CONFLICT', 'SUPPLIER_PRICE_DUPLICATE', 'لهذا المورد سعر فعّال للمنتج نفسه والحد الأدنى نفسه ومدته تتقاطع مع هذه — عدّله أو أنهِ مدته بدل إضافة سعر ثانٍ',
                'This supplier already has an active price for the same product and minimum quantity whose period overlaps — change it or end its period instead of adding a second one', ['id' => $twin->id]);
        }
    }
}
