<?php

namespace App\Pricing\Support;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Pricing\Models\PriceList;
use App\Pricing\Models\PriceListCustomer;
use App\Pricing\Models\PriceRule;
use App\Pricing\Models\ProductListPrice;
use App\Pricing\Models\SupplierPrice;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The JSON of the section (camelCase, the contract the Vue client reads). A price leaves as a decimal string with two
 * to four decimals ("47.00", "12.125") like every amount of the API, a quantity or a percentage as a number, a day as
 * `YYYY-MM-DD`, a moment as ISO-8601; `owned` says the record is Odoo's right now (read-only here).
 */
final class Shape
{
    /** A price as the API prints it: at least two decimals, at most four. */
    public static function dec(float|int|string|null $v, int $min = 2): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        [$int, $frac] = explode('.', number_format((float) $v, 4, '.', '')) + [1 => ''];

        return $int.'.'.str_pad(rtrim($frac, '0'), $min, '0');
    }

    /** A quantity or a percentage: 12 stays 12, 0.5 stays 0.5. */
    public static function num(float|int|string|null $v): float|int|null
    {
        if ($v === null || $v === '') {
            return null;
        }
        $f = round((float) $v, 4);

        return $f == (int) $f ? (int) $f : $f;
    }

    public static function day(?DateTimeInterface $d): ?string
    {
        return $d?->format('Y-m-d');
    }

    public static function at(?DateTimeInterface $d): ?string
    {
        return $d ? Carbon::instance($d)->toIso8601String() : null;
    }

    /** expired | future | valid — where the day stands against a period. */
    public static function period(?DateTimeInterface $from, ?DateTimeInterface $to, Carbon $day): string
    {
        if ($to !== null && Carbon::instance($to)->startOfDay()->lt($day)) {
            return 'expired';
        }

        return $from !== null && Carbon::instance($from)->startOfDay()->gt($day) ? 'future' : 'valid';
    }

    /** @return array{sourceSystem:?string, externalRef:?string, owned:bool} */
    public static function origin(Model $row): array
    {
        return ['sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref'), 'owned' => Owned::bySource($row)];
    }

    public static function product(?Product $p): ?array
    {
        return $p ? ['id' => $p->id, 'sku' => $p->sku, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en, 'active' => (bool) $p->active] : null;
    }

    public static function party(Customer|Supplier|null $p): ?array
    {
        return $p ? ['id' => $p->id, 'code' => $p->code, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en, 'active' => (bool) $p->active] : null;
    }

    public static function category(?ProductCategory $c): ?array
    {
        return $c ? ['id' => $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en] : null;
    }

    /** A list as a reference inside another record. */
    public static function listRef(?PriceList $l): ?array
    {
        return $l ? ['id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'currency' => $l->currency, 'active' => $l->active, 'isDefault' => $l->is_default] : null;
    }

    /** A list as a row and as the head of its page; the counts are there when the query asked for them (withCount). */
    public static function list(PriceList $l, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $out = self::listRef($l) + [
            'sequence' => $l->sequence, 'validFrom' => self::day($l->valid_from), 'validTo' => self::day($l->valid_to), 'period' => self::period($l->valid_from, $l->valid_to, $today),
            'usable' => $l->usableOn($today), 'note' => $l->note, 'createdBy' => $l->created_by, 'updatedBy' => $l->updated_by,
            'createdAt' => self::at($l->created_at), 'updatedAt' => self::at($l->updated_at), 'sourceUpdatedAt' => self::at($l->source_updated_at), 'sourceDeletedAt' => self::at($l->source_deleted_at),
        ] + self::origin($l);
        foreach (['rules_count' => 'rulesCount', 'active_rules_count' => 'activeRulesCount', 'customers_count' => 'customersCount'] as $attribute => $key) {
            if ($l->getAttribute($attribute) !== null) {
                $out[$key] = (int) $l->getAttribute($attribute);
            }
        }

        return $out;
    }

    public static function rule(PriceRule $r, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        return [
            'id' => $r->id, 'listId' => $r->price_list_id, 'appliesTo' => $r->applies_to,
            'product' => $r->product_id && $r->relationLoaded('product') ? self::product($r->product) : null, 'productId' => $r->product_id,
            'category' => $r->category_id && $r->relationLoaded('category') ? self::category($r->category) : null, 'categoryId' => $r->category_id,
            'minQty' => self::num($r->min_qty), 'validFrom' => self::day($r->valid_from), 'validTo' => self::day($r->valid_to), 'period' => self::period($r->valid_from, $r->valid_to, $today),
            'compute' => $r->compute, 'fixedPrice' => self::dec($r->fixed_price), 'percent' => self::num($r->percent), 'base' => $r->base,
            'baseList' => $r->base_list_id && $r->relationLoaded('baseList') ? self::listRef($r->baseList) : null, 'baseListId' => $r->base_list_id,
            'discountPct' => self::num($r->discount_pct), 'surcharge' => self::dec($r->surcharge), 'roundTo' => self::dec($r->round_to), 'minMargin' => self::dec($r->min_margin), 'maxMargin' => self::dec($r->max_margin),
            'active' => $r->active, 'note' => $r->note, 'pending' => $r->pending(), 'pendingLabel' => $r->pending_label, 'text' => self::ruleText($r),
            'updatedAt' => self::at($r->updated_at), 'updatedBy' => $r->updated_by,
        ] + self::origin($r);
    }

    /** How a rule makes its price, in a sentence. @return array{ar:string, en:string} */
    public static function ruleText(PriceRule $r): array
    {
        if ($r->compute === 'fixed') {
            return ['ar' => 'سعر ثابت '.self::dec($r->fixed_price), 'en' => 'Fixed price '.self::dec($r->fixed_price)];
        }
        $listName = $r->base === 'pricelist' && $r->relationLoaded('baseList') ? $r->baseList?->name : null;
        [$baseAr, $baseEn] = match ($r->base) {
            'cost' => ['التكلفة', 'the cost'],
            'pricelist' => [$listName ? "سعر قائمة «{$listName}»" : 'سعر قائمة أخرى', $listName ? "the price on list “{$listName}”" : 'the price on another list'],
            default => ['السعر المعلن', 'the list price'],
        };
        if ($r->compute === 'percent') {
            $p = (float) $r->percent;
            $n = self::num(abs($p));

            return $p >= 0
                ? ['ar' => "خصم {$n}% من {$baseAr}", 'en' => "{$n}% off {$baseEn}"]
                : ['ar' => "زيادة {$n}% على {$baseAr}", 'en' => "{$n}% above {$baseEn}"];
        }
        $ar = [$baseAr];
        $en = [ucfirst($baseEn)];
        if ((float) $r->discount_pct != 0.0) {
            $d = (float) $r->discount_pct;
            $n = self::num(abs($d));
            $ar[] = $d > 0 ? "− {$n}%" : "+ {$n}%";
            $en[] = $d > 0 ? "− {$n}%" : "+ {$n}%";
        }
        if ((float) $r->round_to > 0) {
            $ar[] = 'تقريب إلى '.self::dec($r->round_to);
            $en[] = 'rounded to '.self::dec($r->round_to);
        }
        if ((float) $r->surcharge != 0.0) {
            $s = (float) $r->surcharge;
            $ar[] = ($s > 0 ? '+ ' : '− ').self::dec(abs($s));
            $en[] = ($s > 0 ? '+ ' : '− ').self::dec(abs($s));
        }
        if ((float) $r->min_margin != 0.0) {
            $ar[] = 'لا يقل عن الأساس '.self::signed($r->min_margin);
            $en[] = 'never below base '.self::signed($r->min_margin);
        }
        if ((float) $r->max_margin != 0.0) {
            $ar[] = 'لا يزيد على الأساس '.self::signed($r->max_margin);
            $en[] = 'never above base '.self::signed($r->max_margin);
        }

        return ['ar' => 'معادلة: '.implode(' · ', $ar), 'en' => 'Formula: '.implode(' · ', $en)];
    }

    private static function signed(float|int|string $v): string
    {
        return ((float) $v >= 0 ? '+ ' : '− ').self::dec(abs((float) $v));
    }

    /** The price list of a customer, with how OPS knows it. */
    public static function assignment(?PriceListCustomer $a): ?array
    {
        if (! $a) {
            return null;
        }

        return [
            'id' => $a->id, 'customerId' => $a->customer_id, 'listId' => $a->price_list_id, 'list' => $a->relationLoaded('priceList') ? self::listRef($a->priceList) : null,
            'basis' => $a->basis, 'basisRef' => $a->basis_ref, 'basisAt' => self::at($a->basis_at), 'note' => $a->note,
            'updatedAt' => self::at($a->updated_at ?? $a->created_at), 'updatedBy' => $a->updated_by ?? $a->created_by,
            'sourceSystem' => $a->source_system, 'externalRef' => $a->external_ref,
            // Odoo's own field is Odoo's; what was read from the orders is evidence a person may correct
            'owned' => $a->basis === 'partner' && Owned::bySource($a),
        ];
    }

    public static function listPrice(?ProductListPrice $p): ?array
    {
        return $p ? ['id' => $p->id, 'productId' => $p->product_id, 'price' => self::dec($p->price), 'currency' => $p->currency, 'updatedAt' => self::at($p->updated_at ?? $p->created_at),
            'updatedBy' => $p->updated_by ?? $p->created_by, 'sourceUpdatedAt' => self::at($p->source_updated_at)] + self::origin($p) : null;
    }

    public static function supplierPrice(SupplierPrice $s, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        return [
            'id' => $s->id, 'supplier' => $s->supplier_id && $s->relationLoaded('supplier') ? self::party($s->supplier) : null, 'supplierId' => $s->supplier_id,
            'product' => $s->product_id && $s->relationLoaded('product') ? self::product($s->product) : null, 'productId' => $s->product_id,
            'price' => self::dec($s->price), 'currency' => $s->currency, 'discountPct' => self::num($s->discount_pct), 'netPrice' => self::dec($s->net()),
            'minQty' => self::num($s->min_qty), 'leadDays' => $s->lead_days, 'validFrom' => self::day($s->valid_from), 'validTo' => self::day($s->valid_to), 'period' => $s->periodOn($today),
            'supplierSku' => $s->supplier_sku, 'supplierProductName' => $s->supplier_product_name, 'sequence' => $s->sequence, 'active' => $s->active, 'note' => $s->note,
            'pending' => $s->pending(), 'pendingLabel' => $s->pending_label, 'updatedAt' => self::at($s->updated_at ?? $s->created_at), 'updatedBy' => $s->updated_by ?? $s->created_by,
        ] + self::origin($s);
    }
}
