<?php

namespace App\Services\Sales;

/**
 * Response shaping for the sales / fulfillment documents.
 *
 * The reference API returns related records through Prisma `select`, i.e. with a fixed list of keys. Eloquent loads the
 * whole related model, so after `toArray()` the nested nodes are trimmed back to the contract with a path spec:
 *
 *   Shape::apply($so->toArray(), ['customer' => ['code', 'nameAr'], 'lines.*.product' => ['sku', 'nameAr']]);
 *
 * Paths are camelCase (they address the serialised array); `*` walks every item of a list. List parents before children.
 */
final class Shape
{
    /** @param  array<string, string[]>  $spec */
    public static function apply(array $data, array $spec): array
    {
        foreach ($spec as $path => $keys) {
            $data = self::walk($data, explode('.', $path), $keys);
        }

        return $data;
    }

    /**
     * @param  string[]  $segments
     * @param  string[]  $keys
     */
    private static function walk(mixed $node, array $segments, array $keys): mixed
    {
        if (! is_array($node)) {
            return $node;
        }
        if ($segments === []) {
            $out = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $node)) {
                    $out[$key] = $node[$key];
                }
            }

            return $out;
        }
        $segment = array_shift($segments);
        if ($segment === '*') {
            return array_map(fn ($child) => self::walk($child, $segments, $keys), $node);
        }
        if (array_key_exists($segment, $node)) {
            $node[$segment] = self::walk($node[$segment], $segments, $keys);
        }

        return $node;
    }
}
