<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API group. The client's warehouse selector uses "all" for "no warehouse filter"; list endpoints filter by warehouse
 * code, so an "all" that slips through would match nothing. It is dropped here once, for every endpoint.
 */
class NormalizeApiQuery
{
    private const NO_FILTER = ['warehouse' => 'all', 'wh' => 'all'];

    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::NO_FILTER as $param => $value) {
            if ($request->query($param) === $value) {
                $request->query->remove($param);
            }
        }

        return $next($request);
    }
}
