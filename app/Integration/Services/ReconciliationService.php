<?php

namespace App\Integration\Services;

use App\Integration\Support\Signature;
use App\Integration\Support\Systems;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reconciliation (ARCHITECTURE §20): asks the source system how IT sees the orders it sent, compares with OPS and
 * records every disagreement as an `int_exceptions` row (RECON_MISMATCH / RECON_MISSING). It never changes either side:
 * a person decides. Runs at most every INTERVAL_MINUTES from the integration cycle, or on demand from the tower.
 */
class ReconciliationService
{
    public const LAST_KEY = 'int:last-recon';

    public const INTERVAL_MINUTES = 15;

    /** Orders of the last LOOKBACK_DAYS, or still open, are compared. */
    public const LOOKBACK_DAYS = 14;

    private const OPS_DELIVERED = ['delivered', 'partial', 'completed'];

    private const OPS_OPEN = ['confirmed', 'allocated', 'preparing', 'picking', 'picked', 'packed', 'readydisp', 'loaded', 'outfordel'];

    public function __construct(private readonly IntegrationExceptions $exceptions) {}

    public function runIfDue(): ?array
    {
        $last = Cache::get(self::LAST_KEY);
        if ($last && now()->diffInMinutes($last['at'], true) < self::INTERVAL_MINUTES) {
            return null;
        }

        return $this->run();
    }

    /** @return array{at:string, systems:array} */
    public function run(): array
    {
        $out = ['at' => now()->toIso8601ZuluString(), 'systems' => []];
        foreach (Systems::all() as $code => $s) {
            if (empty($s['enabled']) || empty($s['reconcile_url']) || ! Systems::signingKey((string) $code)) {
                continue;
            }
            $out['systems'][$code] = $this->reconcile((string) $code, (string) $s['reconcile_url']);
        }
        Cache::put(self::LAST_KEY, $out, now()->addDays(7));

        return $out;
    }

    private function reconcile(string $system, string $url): array
    {
        $orders = SalesOrder::where('source_system', $system)
            ->where(fn ($q) => $q->where('updated_at', '>=', now()->subDays(self::LOOKBACK_DAYS))->orWhereIn('status', self::OPS_OPEN))
            ->orderBy('updated_at')->limit(500)->get(['id', 'number', 'status', 'external_ref']);
        $stats = ['compared' => 0, 'mismatches' => 0, 'error' => null];
        foreach ($orders->chunk(100) as $chunk) {
            $ids = $chunk->pluck('external_ref')->implode(',');
            $parts = parse_url($url);
            $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
            $path = ($parts['path'] ?? '/').'?ids='.rawurlencode($ids);
            [$kid, $secret] = Systems::signingKey($system);
            try {
                $res = Http::timeout(10)->withHeaders(Signature::headers('ops', $kid, $secret, 'GET', $path, '') + ['Accept' => 'application/json'])
                    ->get($origin.$path);
            } catch (Throwable $e) {
                $stats['error'] = mb_substr($e->getMessage(), 0, 200);
                break;
            }
            if (! $res->successful()) {
                $stats['error'] = "HTTP {$res->status()}";
                break;
            }
            $theirs = collect((array) $res->json('orders'))->keyBy('id');
            foreach ($chunk as $so) {
                $stats['compared']++;
                $problem = $this->compare($so, $theirs->get($so->external_ref));
                if ($problem) {
                    $stats['mismatches']++;
                    $this->exceptions->raise($problem[0], $problem[1], [
                        'system' => $system, 'entity' => 'order', 'entityRef' => $so->external_ref, 'correlationId' => $so->external_ref,
                        'severity' => 'warn', 'details' => ['opsOrder' => $so->number, 'opsStatus' => $so->status, 'theirs' => $theirs->get($so->external_ref)],
                    ]);
                } else {
                    // agreement again: a previous mismatch for this order is no longer true
                    $this->exceptions->autoResolve('RECON_MISMATCH', $so->external_ref, 'statuses agree again');
                }
            }
        }

        return $stats;
    }

    /** @return array{0:string,1:string}|null [code, message] when the two systems disagree */
    private function compare(SalesOrder $so, ?array $theirs): ?array
    {
        $ref = $so->external_ref;
        if (! $theirs) {
            return ['RECON_MISSING', "الطلب {$ref} موجود في العمليات ({$so->number}) ولا تعرفه المبيعات"];
        }
        $st = (string) ($theirs['st'] ?? '');
        $theirOpsRef = $theirs['opsRef'] ?? null;
        return match (true) {
            $theirOpsRef !== null && $theirOpsRef !== $so->number => ['RECON_MISMATCH', "{$ref}: المبيعات تربطه بـ {$theirOpsRef} والعمليات بـ {$so->number}"],
            in_array($st, ['done', 'short'], true) && ! in_array($so->status, self::OPS_DELIVERED, true) => ['RECON_MISMATCH', "{$ref}: المبيعات تقول «استُلم» والعمليات «{$so->status}»"],
            in_array($so->status, self::OPS_DELIVERED, true) && in_array($st, ['b2b', 'ops', 'purch'], true) => ['RECON_MISMATCH', "{$ref}: سُلّم في العمليات والمبيعات ما زالت «{$st}» — أحداث لم تصل"],
            $so->status === 'outfordel' && $st === 'b2b' => ['RECON_MISMATCH', "{$ref}: خرج للتوصيل في العمليات والمبيعات ما زالت «قيد التجهيز»"],
            $so->status === 'cancelled' && ! in_array($st, ['rej', 'hold'], true) => ['RECON_MISMATCH', "{$ref}: أُلغي في العمليات وما زال «{$st}» في المبيعات"],
            $st === 'rej' && in_array($so->status, self::OPS_OPEN, true) => ['RECON_MISMATCH', "{$ref}: مرفوض في المبيعات وما زال قيد التنفيذ في العمليات ({$so->status})"],
            default => null,
        };
    }
}
