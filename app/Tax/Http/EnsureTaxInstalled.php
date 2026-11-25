<?php

namespace App\Tax\Http;

use App\Support\AppError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The code can reach a server before its migration has run. Until the tables of the tax section exist every endpoint
 * answers with a plain refusal instead of a database error.
 */
class EnsureTaxInstalled
{
    /** Remembered once it is true; asked again on every request until then (the migration may have run meanwhile). */
    private static bool $installed = false;

    public function handle(Request $request, Closure $next): mixed
    {
        if (! self::$installed) {
            self::$installed = Schema::hasTable('tax_einvoices') && Schema::hasTable('tax_returns');
        }
        if (! self::$installed) {
            throw AppError::rule('TAX_NOT_INSTALLED', 'جداول قسم الضريبة والفوترة الإلكترونية لم تُنشأ على هذا الخادم بعد — شغّل php artisan migrate', 'The tables of the tax section do not exist on this server yet — run php artisan migrate');
        }

        return $next($request);
    }
}
