<?php

namespace App\ProductRequests\Services;

use App\Integration\Odoo\OdooMasterData;
use App\Integration\Support\PackEstimator;
use App\Models\Product;
use App\ProductRequests\Models\ProductRequest;
use App\Services\Master\ProductsService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * From an approved request to a product of the catalogue.
 *
 * The product is created THROUGH `ProductsService::create` — the one door into the catalogue — so its rules hold
 * here too: a code and a barcode belong to one product (SKU_TAKEN / BARCODE_TAKEN, 409), weight and dimensions are
 * greater than zero, the category, the units and the supplier must exist, and the creation is on the product's own
 * audit trail (with the request it came from).
 *
 * THE CODE. Products are numbered in one series (P02697 …). The reviewer decides the code; `nextSku()` proposes the
 * next free one of the series most products use — free in the catalogue AND never given by Odoo to a product the
 * catalogue does not hold (the copy is asked), so a code is not handed out twice while Odoo still numbers products.
 *
 * WEIGHT AND DIMENSIONS. A request often knows only the name. `estimate()` reads the pack text of the name
 * ("1kg*12pcs" → 12 kg, a carton sized for it) and guesses the storage class — the same estimate the Odoo master data
 * uses. It is a proposal for the reviewer, who confirms or corrects it: nothing is estimated silently.
 */
class Catalogue
{
    /** What `ProductsService::create` cannot do without, as the keys of the request form. */
    public const PHYSICAL = ['weightKg' => 'weight_kg', 'lengthCm' => 'length_cm', 'widthCm' => 'width_cm', 'heightCm' => 'height_cm'];

    private const PHYSICAL_LABELS = ['weightKg' => ['الوزن', 'weight'], 'lengthCm' => ['الطول', 'length'], 'widthCm' => ['العرض', 'width'], 'heightCm' => ['الارتفاع', 'height']];

    /** The series a first product of an empty catalogue starts. */
    private const DEFAULT_PREFIX = 'P';

    private const DEFAULT_WIDTH = 5;

    public function __construct(private readonly ProductsService $products) {}

    /** The physical values the product still needs before it can be created. @return string[] keys of the form */
    public function missing(ProductRequest $r): array
    {
        return array_values(array_filter(array_keys(self::PHYSICAL), fn (string $key) => ! ((float) $r->getAttribute(self::PHYSICAL[$key]) > 0)));
    }

    /** Refuses the approval of a request the product cannot be created from yet (422 PREQ_INCOMPLETE, one line per value). */
    public function assertComplete(ProductRequest $r): void
    {
        $missing = $this->missing($r);
        if ($missing) {
            $ar = implode('، ', array_map(fn (string $k) => self::PHYSICAL_LABELS[$k][0], $missing));
            $en = implode(', ', array_map(fn (string $k) => self::PHYSICAL_LABELS[$k][1], $missing));
            throw AppError::rule('PREQ_INCOMPLETE', "لا يُنشأ الصنف بلا: {$ar} — أكملها ثم اعتمد", "The product cannot be created without: {$en} — complete them, then approve",
                array_map(fn (string $k) => ['path' => $k, 'message' => 'مطلوب قبل الاعتماد (أكبر من صفر)'], $missing));
        }
    }

    /**
     * Weight, a carton and a storage class read from the name. `basis`: `name` (a measure is written in the name or
     * the pack), `default` (nothing is written: a plain 10 kg carton — a placeholder a person must replace).
     *
     * @return array{weightKg:float, lengthCm:int, widthCm:int, heightCm:int, storageClass:string, uomCode:?string, basis:string}
     */
    public function estimate(string $nameAr, ?string $nameEn = null, ?string $packText = null, ?string $categoryName = null): array
    {
        $guess = PackEstimator::estimate($nameAr, $packText, $categoryName);
        $kg = OdooMasterData::packWeightKg(trim(($packText ?? '').' '.($nameEn ?? '').' '.$nameAr));
        $basis = $kg !== null || $guess['basis'] !== 'default' ? 'name' : 'default';
        $kg ??= $guess['weightKg'];
        $k = (($kg * 1.5 * 1000) / (4 * 3 * 2.5)) ** (1 / 3); // the 4 : 3 : 2.5 carton PackEstimator sizes for a weight

        return [
            'weightKg' => round($kg, 2), 'lengthCm' => max(10, (int) round(4 * $k)), 'widthCm' => max(8, (int) round(3 * $k)), 'heightCm' => max(5, (int) round(2.5 * $k)),
            'storageClass' => $guess['storageClass'], 'uomCode' => $guess['uomCode'], 'basis' => $basis,
        ];
    }

    /** The next free code of the series most products are numbered in ("P02698"). */
    public function nextSku(): string
    {
        $series = [];
        foreach (DB::table('products')->pluck('sku') as $sku) {
            if (preg_match('/^([A-Za-z]{1,4})(\d{3,9})$/', (string) $sku, $m)) {
                $prefix = strtoupper($m[1]);
                $series[$prefix] ??= ['n' => 0, 'max' => 0, 'width' => strlen($m[2])];
                $series[$prefix]['n']++;
                if ((int) $m[2] >= $series[$prefix]['max']) {
                    $series[$prefix]['max'] = (int) $m[2];
                    $series[$prefix]['width'] = strlen($m[2]);
                }
            }
        }
        $prefix = self::DEFAULT_PREFIX;
        foreach ($series as $p => $s) {
            if ($s['n'] > ($series[$prefix]['n'] ?? 0)) {
                $prefix = $p;
            }
        }
        $max = $series[$prefix]['max'] ?? 0;
        $width = $series[$prefix]['width'] ?? self::DEFAULT_WIDTH;
        // the codes Odoo gave, including to products the catalogue does not hold (archived there, or not loaded yet)
        $taken = [];
        if (Schema::hasTable('odoo_records')) {
            foreach (DB::table('odoo_records')->where('model', 'product.product')->select(['data->default_code as code'])->pluck('code') as $code) {
                if (is_string($code) && preg_match('/^'.preg_quote($prefix, '/').'(\d{3,9})$/i', trim($code), $m)) {
                    $max = max($max, (int) $m[1]);
                    $taken[strtoupper(trim($code))] = true;
                }
            }
        }
        do {
            $sku = $prefix.str_pad((string) ++$max, $width, '0', STR_PAD_LEFT);
        } while (isset($taken[$sku]) || Product::where('sku', $sku)->exists());

        return $sku;
    }

    /** A product code as the catalogue takes it: upper-case, 3–32 Latin letters, digits, dot, dash or underscore. */
    public static function sku(string $value): string
    {
        $sku = mb_strtoupper(trim($value));
        if (! preg_match('/^[A-Z0-9][A-Z0-9._-]{2,31}$/', $sku)) {
            throw AppError::validation('PREQ_BAD_SKU', 'رمز الصنف من 3 إلى 32 حرفًا لاتينيًا أو رقمًا (ويُقبل . - _)', 'A product code is 3 to 32 Latin letters or digits (. - _ are accepted)');
        }

        return $sku;
    }

    /**
     * Creates the product of an approved request. Runs inside the caller's transaction: a refusal of the catalogue
     * (SKU_TAKEN, BARCODE_TAKEN, an unknown category…) undoes the whole approval.
     *
     * @return array the product as `ProductsService::get` returns it
     */
    public function create(AuthUser $actor, ProductRequest $r, string $sku): array
    {
        $this->assertComplete($r);
        // references are given by id: a code or a name of one record can never be read as another's
        $dto = [
            'sku' => $sku, 'nameAr' => $r->name_ar, 'nameEn' => $r->name_en ?: $r->name_ar, 'brand' => $r->brand,
            'storageClass' => $r->storage_class ?: 'ambient', 'tracksExpiry' => (bool) $r->tracks_expiry, 'shelfLifeDays' => $r->shelf_life_days,
            'weightKg' => (float) $r->weight_kg, 'lengthCm' => (float) $r->length_cm, 'widthCm' => (float) $r->width_cm, 'heightCm' => (float) $r->height_cm,
            'purchasePrice' => $r->cost_price === null ? null : round((float) $r->cost_price, 2), 'reorderMin' => 0,
            'barcode' => $r->barcode ?: null, 'packQty' => $r->pack_qty ?: 1,
            'origin' => "new-product request {$r->number}",
        ];
        foreach (['categoryCode' => $r->category_id, 'uomCode' => $r->uom_id, 'packUomCode' => $r->pack_uom_id, 'preferredSupplierCode' => $r->supplier_id] as $key => $id) {
            if ($id) {
                $dto[$key] = (string) $id;
            }
        }

        return $this->products->create($actor, $dto);
    }
}
