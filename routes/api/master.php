<?php

use App\Http\Controllers\Api\Master\BinsController;
use App\Http\Controllers\Api\Master\CategoriesController;
use App\Http\Controllers\Api\Master\CustomersController;
use App\Http\Controllers\Api\Master\MasterDataController;
use App\Http\Controllers\Api\Master\ProductsController;
use App\Http\Controllers\Api\Master\RoutesController;
use App\Http\Controllers\Api\Master\SuppliersController;
use App\Http\Controllers\Api\Master\UomsController;
use App\Http\Controllers\Api\Master\WarehousesController;
use App\Http\Controllers\Api\Master\ZonesController;
use Illuminate\Support\Facades\Route;

// Master data. Reading is open to every signed-in user; each change declares its permission.

Route::get('master/lookups', [MasterDataController::class, 'lookups']);

// ── products ──
Route::get('products', [ProductsController::class, 'index']);
Route::get('products/{id}', [ProductsController::class, 'show']);
Route::post('products', [ProductsController::class, 'store'])->middleware('perm:product.manage');
Route::patch('products/{id}', [ProductsController::class, 'update'])->middleware('perm:product.manage');
Route::post('products/{id}/activate', [ProductsController::class, 'activate'])->middleware('perm:product.manage');
Route::post('products/{id}/deactivate', [ProductsController::class, 'deactivate'])->middleware('perm:product.manage');
Route::post('products/{id}/barcodes', [ProductsController::class, 'addBarcode'])->middleware('perm:product.manage');
Route::delete('products/{id}/barcodes/{barcode}', [ProductsController::class, 'removeBarcode'])->middleware('perm:product.manage');
Route::post('products/{id}/suppliers', [ProductsController::class, 'linkSupplier'])->middleware('perm:product.manage');
Route::delete('products/{id}/suppliers/{code}', [ProductsController::class, 'unlinkSupplier'])->middleware('perm:product.manage');

// ── categories + units of measure ──
Route::get('categories', [CategoriesController::class, 'index']);
Route::get('categories/{id}', [CategoriesController::class, 'show']);
Route::post('categories', [CategoriesController::class, 'store'])->middleware('perm:category.manage');
Route::patch('categories/{id}', [CategoriesController::class, 'update'])->middleware('perm:category.manage');

Route::get('uoms', [UomsController::class, 'index']);
Route::post('uoms', [UomsController::class, 'store'])->middleware('perm:product.manage');
Route::patch('uoms/{id}', [UomsController::class, 'update'])->middleware('perm:product.manage');

// ── suppliers + customers ──
Route::get('suppliers', [SuppliersController::class, 'index']);
Route::get('suppliers/{id}', [SuppliersController::class, 'show']);
Route::post('suppliers', [SuppliersController::class, 'store'])->middleware('perm:supplier.manage');
Route::patch('suppliers/{id}', [SuppliersController::class, 'update'])->middleware('perm:supplier.manage');

Route::get('customers', [CustomersController::class, 'index']);
Route::get('customers/{id}', [CustomersController::class, 'show']);
Route::post('customers', [CustomersController::class, 'store'])->middleware('perm:customer.manage');
Route::patch('customers/{id}', [CustomersController::class, 'update'])->middleware('perm:customer.manage');

// ── warehouses: layout, staff, dock appointments ──
Route::get('warehouses', [WarehousesController::class, 'index']);
Route::get('warehouses/{code}', [WarehousesController::class, 'show']);
Route::post('warehouses', [WarehousesController::class, 'store'])->middleware('perm:warehouse.manage');
Route::patch('warehouses/{code}', [WarehousesController::class, 'update'])->middleware('perm:warehouse.manage');

Route::get('warehouses/{code}/zones', [WarehousesController::class, 'zones']);
Route::post('warehouses/{code}/zones', [WarehousesController::class, 'createZone'])->middleware('perm:warehouse.manage');
Route::patch('warehouses/{code}/zones/{zone}', [WarehousesController::class, 'updateZone'])->middleware('perm:warehouse.manage');
Route::get('warehouses/{code}/zones/{zone}/racks', [WarehousesController::class, 'racks']);

Route::get('warehouses/{code}/bins', [WarehousesController::class, 'bins']);
Route::get('warehouses/{code}/bins/{bin}', [WarehousesController::class, 'bin']);
Route::post('warehouses/{code}/bins', [WarehousesController::class, 'createBin'])->middleware('perm:warehouse.manage');
Route::patch('warehouses/{code}/bins/{bin}/status', [WarehousesController::class, 'binStatus'])->middleware('perm:warehouse.manage');

Route::get('warehouses/{code}/staff', [WarehousesController::class, 'staff']);
Route::post('warehouses/{code}/staff', [WarehousesController::class, 'assignStaff'])->middleware('perm:warehouse.manage');
Route::delete('warehouses/{code}/staff/{id}', [WarehousesController::class, 'removeStaff'])->middleware('perm:warehouse.manage');

Route::get('warehouses/{code}/docks', [WarehousesController::class, 'docks']);
Route::post('warehouses/{code}/docks', [WarehousesController::class, 'bookDock'])->middleware('perm:warehouse.manage');
Route::delete('warehouses/{code}/docks/{id}', [WarehousesController::class, 'cancelDock'])->middleware('perm:warehouse.manage');

Route::get('zones/{id}/racks', [ZonesController::class, 'racks']);

Route::get('bins', [BinsController::class, 'index']);
Route::get('bins/{id}', [BinsController::class, 'show']);
Route::post('bins', [BinsController::class, 'store'])->middleware('perm:warehouse.manage');
Route::patch('bins/{id}', [BinsController::class, 'update'])->middleware('perm:warehouse.manage');
Route::patch('bins/{id}/status', [BinsController::class, 'status'])->middleware('perm:warehouse.manage');

// ── delivery routes ──
Route::get('routes', [RoutesController::class, 'index']);
Route::get('routes/{id}', [RoutesController::class, 'show']);
Route::post('routes', [RoutesController::class, 'store'])->middleware('perm:trip.manage');
Route::patch('routes/{id}', [RoutesController::class, 'update'])->middleware('perm:trip.manage');
Route::delete('routes/{id}', [RoutesController::class, 'destroy'])->middleware('perm:trip.manage');
