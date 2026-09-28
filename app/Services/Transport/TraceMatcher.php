<?php

namespace App\Services\Transport;

use App\Services\Integrations\Adapters\AdapterFactory;
use App\Services\Integrations\Adapters\MapboxMaps;
use Illuminate\Support\Facades\Cache;

/**
 * Turns a recorded GPS trail into lines that follow the streets (the maps provider's trace matching). Fixes are sent
 * in blocks of 100 that overlap by one point, so consecutive matched pieces join. A block's answer is cached under its
 * own first/last timestamps: a finished block is matched once, only the growing last block is asked for again when
 * new fixes arrive. At most NEW_BLOCKS_PER_CALL unmatched blocks are sent per page load (a long trail fills in over a
 * few refreshes); any block that is not matched — provider off, error, low confidence — is drawn as recorded.
 */
class TraceMatcher
{
    public const BLOCK = MapboxMaps::MATCH_MAX;

    public const NEW_BLOCKS_PER_CALL = 6;

    /**
     * @param  list<array{lat:float, lng:float, accuracy?:float|null, at:\DateTimeInterface}>  $points  oldest first
     * @return array{matched:bool, lines:list<list<array{0:float, 1:float}>>} matched = every block follows the streets
     */
    public function lines(string $key, array $points): array
    {
        $points = array_values($points);
        $n = count($points);
        if ($n < 2) {
            return ['matched' => false, 'lines' => []];
        }
        $raw = fn (array $block) => array_map(fn ($p) => [(float) $p['lng'], (float) $p['lat']], $block);
        $maps = AdapterFactory::maps();
        if (! $maps->configured()) {
            return ['matched' => false, 'lines' => [$raw($points)]];
        }
        $lines = [];
        $all = true;
        $budget = self::NEW_BLOCKS_PER_CALL;
        for ($i = 0; $i < $n - 1; $i += self::BLOCK - 1) {
            $block = array_slice($points, $i, self::BLOCK);
            $full = count($block) === self::BLOCK;
            $cacheKey = sprintf('trace:%s:%d:%d:%d:%d', $key, $i, count($block), $block[0]['at']->getTimestamp(), $block[count($block) - 1]['at']->getTimestamp());
            $hit = Cache::get($cacheKey);
            if ($hit === null && $budget > 0) {
                $budget--;
                $r = $maps->matchTrace($block);
                $hit = $r['status'] === 'ok' ? $r['lines'] : [];
                // a failure is retried after a while (it may be the network); a success is final for a full block
                Cache::put($cacheKey, $hit, $hit ? ($full ? now()->addDays(7) : now()->addHour()) : now()->addMinutes(10));
            }
            if ($hit) {
                array_push($lines, ...$hit);
            } else {
                $all = false;
                $lines[] = $raw($block);
            }
        }

        return ['matched' => $all, 'lines' => $lines];
    }
}
