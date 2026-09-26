<?php

use Illuminate\Support\Facades\Route;

// Public privacy policy — the URL the App Store and Google Play listings point to. Must load without signing in.
Route::get('/privacy', fn () => view('privacy', ['contact' => config('app.privacy_contact'), 'updated' => config('app.privacy_updated')]));

// Single-page app: every non-API path serves the Vue shell; the client router takes it from there.
// (Unknown /api/* paths are answered by the API error contract, never by this HTML page.)
Route::get('/{any?}', fn () => view('app'))->where('any', '^(?!api/|up$).*$');
