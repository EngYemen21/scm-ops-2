<?php

namespace App\Services\Fulfillment;

use App\Models\Carton;
use App\Models\FulfillmentOrder;
use App\Models\Package;
use App\Models\SalesOrder;

/**
 * What a scanner reads at the loading dock and at the customer's door, resolved to the fulfilment order it identifies.
 * Accepted: the order's own number (FO-…), its sales order (SO-… or the SO QR, which is the app URL `/so/<number>`),
 * its package number (PKG-…), its shipping-label reference (LBL-FO-…) and the code of any of its cartons (FO-…-C01).
 * Typing a code is the same as scanning it — the server cannot tell them apart and does not pretend to.
 */
class ScanCodes
{
    /** Upper-case, no spaces; a URL / path is reduced to its last segment. */
    public static function normalize(?string $code): string
    {
        $c = trim((string) $code);
        if ($c !== '' && (str_contains($c, '/') || str_contains($c, '?'))) {
            $path = parse_url($c, PHP_URL_PATH) ?: $c;
            $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
            $c = rawurldecode((string) end($segments));
        }

        return preg_replace('/\s+/u', '', mb_strtoupper($c));
    }

    /** The fulfilment order a code belongs to, or null. A cancelled order is never returned. */
    public static function fo(?string $code): ?FulfillmentOrder
    {
        $c = self::normalize($code);
        if ($c === '') {
            return null;
        }
        $live = fn ($q) => $q->where('status', '!=', 'cancelled');
        if ($fo = $live(FulfillmentOrder::where('number', $c))->first()) {
            return $fo;
        }
        if (str_starts_with($c, 'LBL-') && ($fo = $live(FulfillmentOrder::where('number', substr($c, 4)))->first())) {
            return $fo;
        }
        if ($carton = Carton::where('code', $c)->where('status', '!=', 'void')->first()) {
            return $live(FulfillmentOrder::whereKey($carton->fo_id))->first();
        }
        if ($pkg = Package::where('number', $c)->orWhere('label_ref', $c)->first()) {
            return $live(FulfillmentOrder::whereKey($pkg->fo_id))->first();
        }
        if ($so = SalesOrder::where('number', $c)->first()) {
            return $live(FulfillmentOrder::where('so_id', $so->id))->orderByDesc('created_at')->first();
        }

        return null;
    }

    /** True when the code identifies exactly this order. */
    public static function matches(?string $code, FulfillmentOrder $fo): bool
    {
        return self::fo($code)?->id === $fo->id;
    }
}
