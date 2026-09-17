<?php

use Illuminate\Support\Facades\Route;

// Single-page app: every non-API path serves the Vue shell; the client router takes it from there.
// (Unknown /api/* paths are answered by the API error contract, never by this HTML page.)
Route::get('/{any?}', fn () => view('app'))->where('any', '^(?!api/|up$).*$');
