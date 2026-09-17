<?php

namespace App\Services\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Response-shaping helpers shared by the master-data services. The reference API (Prisma) exposes relation counts
 * as `_count: { relation: n }` and audits only the keys that really changed; both are reproduced here.
 */
final class Shape
{
    /**
     * Serialises a model loaded with `withCount([...])` the way Prisma does: the `<relation>_count` attributes move
     * under `_count`, keyed by the (camelCase) relation name.
     *
     * @param  string[]  $relations  relation names exactly as passed to withCount()
     */
    public static function withCounts(Model $model, array $relations): array
    {
        $out = $model->toArray();
        $counts = [];
        foreach ($relations as $relation) {
            $attribute = Str::snake($relation).'_count';
            $counts[$relation] = (int) ($model->getAttribute($attribute) ?? 0);
            unset($out[Str::camel($attribute)]);
        }
        $out['_count'] = $counts;

        return $out;
    }

    /**
     * Keys of $after (camelCase => value) whose value differs from the model's current attribute.
     *
     * @return array{oldValue: array<string,mixed>, newValue: array<string,mixed>, changed: int}
     */
    public static function diff(Model $before, array $after): array
    {
        $old = [];
        $new = [];
        foreach ($after as $key => $value) {
            $current = $before->getAttribute(Str::snake($key));
            if (! self::same($value, $current)) {
                $old[$key] = $current;
                $new[$key] = $value;
            }
        }

        return ['oldValue' => $old, 'newValue' => $new, 'changed' => count($new)];
    }

    /** camelCase payload => snake_case attributes. */
    public static function snake(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[Str::snake($key)] = $value;
        }

        return $out;
    }

    /** JS-like `String(a ?? '') === String(b ?? '')`, where 5 and "5.00" (a decimal column) are the same value. */
    public static function same(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b && ($a !== null) === ($b !== null);
        }
        $numberInvolved = is_int($a) || is_float($a) || is_int($b) || is_float($b);
        if ($numberInvolved && is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 1e-9;
        }

        return (string) ($a ?? '') === (string) ($b ?? '');
    }
}
