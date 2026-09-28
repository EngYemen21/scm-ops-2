<?php

namespace App\Services\Transport;

use App\Services\Delivery\PhoneTrackingService;
use App\Services\Integrations\Adapters\AdapterFactory;
use App\Services\Integrations\Adapters\MapboxMaps;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Turns a recorded GPS trail (a phone's or a truck's) into lines that follow the streets, through the maps provider's
 * trace matching.
 *
 * The trail is cut into blocks: at most 100 fixes (the provider's limit), never across a clock hour, and never across
 * a gap (no fix for 15 min, or a jump of 3 km — the line breaks there instead of inventing a road). Consecutive blocks
 * share one fix so their pieces join. Blocks are therefore stable as the trail grows or its window slides, and each
 * is matched once and cached. The last block keeps growing: it is re-matched at most every OPEN_BLOCK_SECONDS
 * (shared by all viewers), and fixes newer than its match are appended as recorded meanwhile. At most
 * NEW_BLOCKS_PER_CALL blocks are sent per page load, so a long trail fills in over a few refreshes; any block that is
 * not matched (provider off, error, low confidence) is drawn as recorded.
 */
class TraceMatcher
{
    public const BLOCK = MapboxMaps::MATCH_MAX;

    public const NEW_BLOCKS_PER_CALL = 8;

    public const GAP_SECONDS = 900;

    public const GAP_METRES = 3000;

    public const OPEN_BLOCK_SECONDS = 120;

    /**
     * @param  list<array{lat:float, lng:float, accuracy?:float|null, at:DateTimeInterface|string}>  $points  oldest first
     * @return array{matched:bool, lines:list<list<array{0:float, 1:float}>>} matched = every block follows the streets
     */
    public function lines(string $key, array $points): array
    {
        $blocks = array_values(array_filter(self::blocks(array_values($points)), fn ($b) => count($b) > 1));
        if (! $blocks) {
            return ['matched' => false, 'lines' => []];
        }
        $maps = AdapterFactory::maps();
        if (! $maps->configured()) {
            return ['matched' => false, 'lines' => array_map(self::raw(...), $blocks)];
        }
        $lines = [];
        $all = true;
        $budget = self::NEW_BLOCKS_PER_CALL;
        $match = function (array $block) use ($maps, &$budget) {
            $budget--;
            $r = $maps->matchTrace($block);

            return $r['status'] === 'ok' ? $r['lines'] : [];
        };
        $last = count($blocks) - 1;
        foreach ($blocks as $k => $block) {
            $first = self::ts($block[0]);
            $end = self::ts($block[count($block) - 1]);
            if ($k < $last) { // closed: its fixes will not change
                $cacheKey = sprintf('trace:%s:%d:%d:%d', $key, $first, count($block), $end);
                $hit = Cache::get($cacheKey);
                if ($hit === null && $budget > 0) {
                    $hit = $match($block);
                    // a failure is retried after a while (it may be the network); a success is final
                    Cache::put($cacheKey, $hit, $hit ? now()->addDays(7) : now()->addMinutes(10));
                }
                if ($hit) {
                    array_push($lines, ...$hit);
                } else {
                    $all = false;
                    $lines[] = self::raw($block);
                }

                continue;
            }
            // the growing last block
            $openKey = sprintf('trace:%s:%d:open', $key, $first);
            $c = Cache::get($openKey);
            $now = now()->getTimestamp();
            $stale = ! $c || $c['until'] > $end || ($c['until'] < $end && $now - $c['at'] >= self::OPEN_BLOCK_SECONDS);
            if ($stale && $budget > 0) {
                $c = ['lines' => $match($block), 'until' => $end, 'at' => $now];
                Cache::put($openKey, $c, now()->addDay());
            }
            if ($c && $c['lines']) {
                array_push($lines, ...$c['lines']);
                $tail = array_values(array_filter($block, fn ($p) => self::ts($p) >= $c['until'])); // from the last matched fix on
                if (count($tail) > 1) {
                    $lines[] = self::raw($tail);
                }
            } else {
                $all = false;
                $lines[] = self::raw($block);
            }
        }

        return ['matched' => $all, 'lines' => $lines];
    }

    /** @return list<list<array>> */
    private static function blocks(array $points): array
    {
        $blocks = [];
        $cur = [];
        $hour = null;
        foreach ($points as $i => $p) {
            if ($cur) {
                $prev = $points[$i - 1];
                $gap = self::ts($p) - self::ts($prev) > self::GAP_SECONDS
                    || PhoneTrackingService::metresBetween((float) $prev['lat'], (float) $prev['lng'], (float) $p['lat'], (float) $p['lng']) > self::GAP_METRES;
                if ($gap || intdiv(self::ts($p), 3600) !== $hour || count($cur) >= self::BLOCK) {
                    $blocks[] = $cur;
                    $cur = $gap ? [] : [$prev];
                    $hour = intdiv(self::ts($p), 3600);
                }
            } else {
                $hour = intdiv(self::ts($p), 3600);
            }
            $cur[] = $p;
        }
        if ($cur) {
            $blocks[] = $cur;
        }

        return $blocks;
    }

    private static function raw(array $block): array
    {
        return array_map(fn ($p) => [(float) $p['lng'], (float) $p['lat']], array_values($block));
    }

    private static function ts(array $p): int
    {
        return $p['at'] instanceof DateTimeInterface ? $p['at']->getTimestamp() : (int) strtotime((string) $p['at']);
    }

    /**
     * Drops fixes that add nothing to the drawn line: not newer than the previous one, or within $minMetres of it
     * (standing still makes the reported position wander). The latest fix is always kept so the line reaches the marker.
     */
    public static function declutter(array $points, float $minMetres): array
    {
        $out = [];
        $n = count($points);
        foreach (array_values($points) as $i => $p) {
            $prev = $out ? $out[count($out) - 1] : null;
            if ($prev) {
                if (self::ts($p) <= self::ts($prev)) {
                    continue;
                }
                if (PhoneTrackingService::metresBetween((float) $prev['lat'], (float) $prev['lng'], (float) $p['lat'], (float) $p['lng']) < $minMetres) {
                    if ($i !== $n - 1) {
                        continue;
                    }
                    array_pop($out); // the latest fix, still inside the noise: move the end of the line there
                }
            }
            $out[] = $p;
        }

        return $out;
    }

    /** Evenly thins a trail to at most $max points, keeping the first and the last (what is sent to a browser). */
    public static function thin(array $trail, int $max = 3000): array
    {
        $trail = array_values($trail);
        $n = count($trail);
        if ($n <= $max) {
            return $trail;
        }
        $out = [];
        for ($k = 0; $k < $max - 1; $k++) {
            $out[] = $trail[intdiv($k * ($n - 1), $max - 1)];
        }
        $out[] = $trail[$n - 1];

        return $out;
    }
}
