<?php

namespace App\Integration\Odoo\Http;

use App\Integration\Odoo\OdooMirror;
use App\Support\AppError;
use Closure;
use Illuminate\Http\Request;

/**
 * The code can reach a server before its migration has run. Until the mirror's tables exist, every endpoint that reads
 * them answers with a plain refusal (the status endpoint says the same thing to the screen) instead of a database error.
 */
class EnsureOdooInstalled
{
    public function __construct(private readonly OdooMirror $mirror) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->mirror->installed()) {
            throw AppError::rule('ODOO_NOT_INSTALLED', 'جداول نسخة أودو لم تُنشأ على هذا الخادم بعد — شغّل php artisan migrate', 'The Odoo mirror tables do not exist on this server yet — run php artisan migrate');
        }

        return $next($request);
    }
}
