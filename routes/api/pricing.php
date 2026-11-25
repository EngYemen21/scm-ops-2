<?php

use App\Pricing\Http\AssignmentsController;
use App\Pricing\Http\ListPricesController;
use App\Pricing\Http\PriceCheckController;
use App\Pricing\Http\PriceListsController;
use App\Pricing\Http\SupplierPricesController;
use Illuminate\Support\Facades\Route;

/*
 * «قوائم الأسعار» — /api/pricing (docs/odoo/WAVE3.md, P1). Opening the section, reading it and checking a price need
 * page.pricing; customer price lists, their rules, the list prices and the list of each customer need pricelist.manage;
 * supplier prices need supplierprice.manage. Supplier prices are purchase costs: READING them also needs
 * inventory.view_cost or supplierprice.manage (403 PRICING_COST_HIDDEN, checked in the controller).
 * A record that came from Odoo is read-only while the section follows Odoo (422 PRICING_OWNED_BY_SOURCE).
 */
Route::prefix('pricing')->group(function () {
    Route::middleware('perm:page.pricing')->group(function () {
        Route::get('lookups', [PriceCheckController::class, 'lookups']);
        Route::get('summary', [PriceCheckController::class, 'summary']);
        // the price service: what a customer pays and why · the lines of a document · the best supplier price
        Route::get('price', [PriceCheckController::class, 'price']);
        Route::post('prices', [PriceCheckController::class, 'prices']);
        Route::get('supplier-price', [PriceCheckController::class, 'supplierPrice']);

        Route::get('lists', [PriceListsController::class, 'index']);
        Route::get('lists/{id}', [PriceListsController::class, 'show']);
        Route::get('lists/{id}/rules', [PriceListsController::class, 'rules']);
        Route::get('lists/{id}/customers', [AssignmentsController::class, 'ofList']);
        Route::get('customers', [AssignmentsController::class, 'index']);
        Route::get('customers/{id}', [AssignmentsController::class, 'show']);
        Route::get('list-prices', [ListPricesController::class, 'index']);
        Route::get('supplier-prices', [SupplierPricesController::class, 'index']);
        Route::get('supplier-prices/suppliers', [SupplierPricesController::class, 'suppliers']);
        Route::get('supplier-prices/{id}', [SupplierPricesController::class, 'show']);
    });

    Route::middleware('perm:pricelist.manage')->group(function () {
        // a list and its life
        Route::post('lists', [PriceListsController::class, 'store']);
        Route::patch('lists/{id}', [PriceListsController::class, 'update']);
        Route::post('lists/{id}/archive', [PriceListsController::class, 'archive']);
        Route::post('lists/{id}/restore', [PriceListsController::class, 'restore']);
        Route::post('lists/{id}/default', [PriceListsController::class, 'makeDefault']);
        Route::post('lists/{id}/undefault', [PriceListsController::class, 'clearDefault']);
        Route::post('lists/{id}/duplicate', [PriceListsController::class, 'duplicate']);
        // its rules
        Route::post('lists/{id}/rules', [PriceListsController::class, 'storeRule']);
        Route::post('lists/{id}/prices', [PriceListsController::class, 'setPrices']);
        Route::patch('rules/{rule}', [PriceListsController::class, 'updateRule']);
        Route::post('rules/{rule}/archive', [PriceListsController::class, 'archiveRule']);
        Route::post('rules/{rule}/restore', [PriceListsController::class, 'restoreRule']);
        // the list of each customer
        Route::post('lists/{id}/customers', [AssignmentsController::class, 'assignMany']);
        Route::post('customers/{id}/assign', [AssignmentsController::class, 'assign']);
        Route::post('customers/{id}/unassign', [AssignmentsController::class, 'unassign']);
        // the list price of each product
        Route::post('list-prices', [ListPricesController::class, 'set']);
        Route::post('list-prices/bulk', [ListPricesController::class, 'setMany']);
        Route::post('list-prices/{product}/clear', [ListPricesController::class, 'clear']);
    });

    Route::middleware('perm:supplierprice.manage')->group(function () {
        Route::post('supplier-prices', [SupplierPricesController::class, 'store']);
        Route::patch('supplier-prices/{id}', [SupplierPricesController::class, 'update']);
        Route::post('supplier-prices/{id}/archive', [SupplierPricesController::class, 'archive']);
        Route::post('supplier-prices/{id}/restore', [SupplierPricesController::class, 'restore']);
    });
});
