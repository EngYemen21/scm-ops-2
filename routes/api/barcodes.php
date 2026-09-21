<?php

use App\Http\Controllers\Api\Barcodes\BarcodesController;
use Illuminate\Support\Facades\Route;

// Printable label images (SVG). CODE128 for shipping / shelf labels, QR for runs and customers.
Route::prefix('barcodes')->group(function () {
    Route::get('code128', [BarcodesController::class, 'code128']);
    Route::get('qr', [BarcodesController::class, 'qr']);
});
