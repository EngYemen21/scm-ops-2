<?php

namespace App\Pricing\Http;

use App\Http\Controllers\Controller;
use App\Pricing\Services\ListPricesService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

/** The list price of each product («السعر المعلن»): what discounts start from and what a customer without a rule pays. */
class ListPricesController extends Controller
{
    public function __construct(private readonly ListPricesService $prices) {}

    public function index(Request $request): array
    {
        return $this->prices->list(AuthUser::current(), Paging::from($request, 25), $request->only(['has', 'source', 'active']));
    }

    public function set(Request $request): array
    {
        $data = $request->validate(['product' => 'required|string|min:1|max:120', 'price' => 'required|numeric|gt:0|max:99999999', 'currency' => 'nullable|string|alpha|size:3']);

        return $this->prices->set(AuthUser::current(), $data['product'], (float) $data['price'], $data['currency'] ?? null);
    }

    /** A pasted sheet of "code, price". */
    public function setMany(Request $request): array
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:'.ListPricesService::BULK_LIMIT, 'items.*.sku' => 'required|string|min:1|max:120', 'items.*.price' => 'required|numeric|gt:0|max:99999999',
        ]);

        return $this->prices->setMany(AuthUser::current(), $data['items']);
    }

    public function clear(string $product): array
    {
        return $this->prices->clear(AuthUser::current(), $product);
    }
}
