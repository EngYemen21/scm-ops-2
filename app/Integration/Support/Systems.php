<?php

namespace App\Integration\Support;

/** Registry of the systems in config/integration.php: keys, scopes, emitted and subscribed event types. */
final class Systems
{
    /** @return array<string, array> */
    public static function all(): array
    {
        return (array) config('integration.systems', []);
    }

    public static function get(string $code): ?array
    {
        $s = self::all()[$code] ?? null;

        return is_array($s) ? $s + ['code' => $code] : null;
    }

    /** "k1:secret,k2:secret" → ['k1' => 'secret', 'k2' => 'secret'] (blank / malformed pairs ignored). */
    public static function keys(string $code): array
    {
        $out = [];
        foreach (explode(',', (string) (self::get($code)['keys'] ?? '')) as $pair) {
            $pair = trim($pair);
            $at = strpos($pair, ':');
            if ($at === false || $at === 0 || $at === strlen($pair) - 1) {
                continue;
            }
            $out[substr($pair, 0, $at)] = substr($pair, $at + 1);
        }

        return $out;
    }

    /** The key that signs requests we send to $code: the first configured pair. @return array{0:string,1:string}|null */
    public static function signingKey(string $code): ?array
    {
        $keys = self::keys($code);
        if (! $keys) {
            return null;
        }
        $id = array_key_first($keys);

        return [(string) $id, $keys[$id]];
    }

    public static function enabled(string $code): bool
    {
        return (bool) (self::get($code)['enabled'] ?? false);
    }

    public static function hasScope(string $code, string $scope): bool
    {
        return in_array($scope, (array) (self::get($code)['scopes'] ?? []), true);
    }

    public static function mayEmit(string $code, string $type): bool
    {
        return in_array($type, (array) (self::get($code)['emits'] ?? []), true);
    }

    /** Systems that want an event of $type: enabled, with a delivery URL, and a matching pattern (`order.*`). @return string[] */
    public static function subscribersOf(string $type): array
    {
        $out = [];
        foreach (self::all() as $code => $s) {
            if (empty($s['enabled']) || empty($s['deliver_url'])) {
                continue;
            }
            foreach ((array) ($s['subscribes'] ?? []) as $pattern) {
                if ($pattern === $type || (str_ends_with($pattern, '.*') && str_starts_with($type, substr($pattern, 0, -1)))) {
                    $out[] = (string) $code;
                    break;
                }
            }
        }

        return $out;
    }
}
