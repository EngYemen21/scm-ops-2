<?php

namespace App\Pricing\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Pricing\Models\PriceList;
use App\Pricing\Models\PriceListCustomer;
use App\Pricing\Models\PriceRule;
use App\Pricing\Models\ProductListPrice;
use App\Pricing\Models\SupplierPrice;
use App\Pricing\OdooPricingProjection;
use App\Pricing\Support\Cost;
use App\Pricing\Support\Day;
use App\Pricing\Support\Owned;
use App\Pricing\Support\Shape;
use App\Support\AuthUser;

/** What every screen of the section starts from: the lookups of its forms and the figures of its head. */
class PricingOverview
{
    /** Currencies a new list is offered besides the ones already in use (a list is in one currency; OPS converts none). */
    private const CURRENCIES = ['AED', 'BHD', 'KWD', 'OMR', 'QAR', 'EGP', 'JOD', 'USD', 'EUR', 'GBP', 'CNY', 'INR', 'TRY'];

    /** The lists and categories the forms offer, and what the caller may see and do. */
    public function lookups(AuthUser $user): array
    {
        return [
            // the section follows Odoo: what came from Odoo is read-only here
            'follows' => Owned::following(),
            'costVisible' => Cost::visible($user),
            'lists' => PriceList::query()->orderByDesc('is_default')->orderByDesc('active')->orderBy('name')->orderBy('id')->get()->map(fn (PriceList $l) => Shape::listRef($l) + Shape::origin($l))->all(),
            'categories' => ProductCategory::query()->where('active', true)->orderBy('name_ar')->orderBy('id')->get(['id', 'code', 'name_ar', 'name_en', 'parent_id'])
                ->map(fn (ProductCategory $c) => Shape::category($c) + ['parentId' => $c->parent_id])->all(),
            // the company's currency first, then the ones lists already use, then the usual ones of the region and of imports
            'currencies' => array_values(array_unique(['SAR', ...array_map('strtoupper', PriceList::query()->distinct()->orderBy('currency')->pluck('currency')->all()), ...self::CURRENCIES])),
            'applies' => PriceRule::APPLIES, 'computes' => PriceRule::COMPUTES, 'bases' => PriceRule::BASES,
        ];
    }

    /** The figures of the head of the page. Supplier figures only for those who may see what the company pays. */
    public function summary(AuthUser $user): array
    {
        $today = Day::today()->toDateString();
        $default = PriceList::where('is_default', true)->orderBy('sequence')->orderBy('id')->first();
        $customers = Customer::where('active', true)->count();
        $byBasis = PriceListCustomer::query()->whereIn('customer_id', fn ($s) => $s->select('id')->from('customers')->where('active', true))
            ->selectRaw('basis, COUNT(*) AS n')->groupBy('basis')->pluck('n', 'basis');
        $assigned = (int) $byBasis->sum();
        $products = Product::where('active', true)->count();
        $priced = ProductListPrice::where('price', '>', 0)->whereIn('product_id', fn ($s) => $s->select('id')->from('products')->where('active', true))->count();
        $pendingRule = fn ($w) => $w->where(fn ($x) => $x->where('applies_to', 'product')->whereNull('product_id'))->orWhere(fn ($x) => $x->where('applies_to', 'category')->whereNull('category_id'));
        $out = [
            'follows' => Owned::following(),
            'defaultList' => Shape::listRef($default),
            'lists' => ['active' => PriceList::where('active', true)->count(), 'archived' => PriceList::where('active', false)->count(), 'fromOdoo' => PriceList::where('source_system', 'odoo')->count()],
            'rules' => ['active' => PriceRule::where('active', true)->count(), 'pending' => PriceRule::where('active', true)->where($pendingRule)->count()],
            'customers' => ['active' => $customers, 'assigned' => $assigned, 'onDefault' => max(0, $customers - $assigned),
                'manual' => (int) ($byBasis['manual'] ?? 0), 'partner' => (int) ($byBasis['partner'] ?? 0), 'order' => (int) ($byBasis['order'] ?? 0)],
            'listPrices' => ['products' => $products, 'priced' => $priced, 'missing' => max(0, $products - $priced)],
            'costVisible' => Cost::visible($user),
            'supplierPrices' => null,
            'odoo' => $this->odoo(),
        ];
        if ($out['costVisible']) {
            $active = SupplierPrice::where('active', true);
            $out['supplierPrices'] = [
                'active' => (clone $active)->count(),
                'suppliers' => (clone $active)->whereNotNull('supplier_id')->distinct()->count('supplier_id'),
                'products' => (clone $active)->whereNotNull('product_id')->distinct()->count('product_id'),
                'pending' => (clone $active)->where(fn ($w) => $w->whereNull('supplier_id')->orWhereNull('product_id'))->count(),
                'expired' => (clone $active)->whereNotNull('valid_to')->where('valid_to', '<', $today)->count(),
            ];
        }

        return $out;
    }

    /** Where the section stands against the copy of Odoo: does it follow it, is the copy there, what waits. */
    private function odoo(): array
    {
        $projection = app(OdooPricingProjection::class);
        $ready = $projection->ready();

        return ['follows' => Owned::following(), 'ready' => $ready, 'waiting' => $ready ? $projection->waiting() : 0];
    }
}
