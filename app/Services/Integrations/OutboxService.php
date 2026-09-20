<?php

namespace App\Services\Integrations;

use App\Models\IntegrationEvent;
use App\Services\Integrations\Adapters\AdapterFactory;
use App\Services\Integrations\Adapters\ErpAdapter;
use App\Services\Integrations\Adapters\Pending;
use App\Support\Paging;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Delivers `integration_events` (status pending, written by NotifyService::event) to the B2B webhook when set, else
 * to the ERP adapter when configured.
 *
 * Honesty rule: when nothing is configured events are NEVER marked sent — they stay pending with lastError
 * `integration_pending`. There is no background timer here: processPending() is the entry point for
 * POST /api/integrations/outbox/run and for a scheduled command, should one be added later.
 */
class OutboxService
{
    public const STATUSES = ['pending', 'sent', 'failed'];

    /** Business events the B2B platform / ERP consume. Documentation only — every pending event is delivered. */
    public const B2B_EVENT_TYPES = ['ShipmentDispatched', 'DeliveryCompleted', 'DeliveryFailed', 'PartialShipment', 'InventoryReceived', 'OrderPacked', 'PO_SENT', 'SupplierReturnCreated'];

    private const RUN_LOCK = 'scm_ops_outbox_run';

    private readonly ErpAdapter $erp;

    private readonly ErpAdapter $b2b;

    public function __construct(?ErpAdapter $erp = null, ?ErpAdapter $b2b = null)
    {
        $this->erp = $erp ?? AdapterFactory::erp();
        $this->b2b = $b2b ?? AdapterFactory::b2bWebhook();
    }

    /**
     * Where events go: B2B webhook first, ERP as fallback, nothing when neither is configured.
     *
     * @return array{kind:string, adapter:ErpAdapter}|null
     */
    public function target(): ?array
    {
        if ($this->b2b->configured()) {
            return ['kind' => 'b2b', 'adapter' => $this->b2b];
        }
        if ($this->erp->configured()) {
            return ['kind' => 'erp', 'adapter' => $this->erp];
        }

        return null;
    }

    /** Paginated outbox listing (`status`, `type`, `q` on type). */
    public function list(Paging $page, ?string $status = null, ?string $type = null): array
    {
        $query = IntegrationEvent::query();
        if ($status) {
            $query->where('status', $status);
        }
        if ($page->q) {
            $query->where('type', 'like', '%'.addcslashes($page->q, '%_\\').'%');
        } elseif ($type) {
            $query->where('type', $type);
        }

        return $page->paginate($query->orderBy('created_at', $page->order)->orderBy('id', $page->order));
    }

    /**
     * Processes up to $limit pending events (oldest first). Safe to call concurrently: an overlapping run is skipped.
     * $onlyIds restricts the run to specific events (used by tests so a shared database is never mass-delivered).
     *
     * @param  string[]|null  $onlyIds
     * @return array{target:?string, processed:int, sent:int, failed:int, pending:int} target is null when nothing is configured (events stay pending)
     */
    public function processPending(int $limit = 50, ?array $onlyIds = null): array
    {
        $target = $this->target();
        $result = ['target' => $target['kind'] ?? null, 'processed' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0];
        // One run at a time. A cache lock works on every database engine and behind connection poolers; it expires
        // by itself after 5 minutes if a run dies without releasing it.
        $lock = Cache::lock(self::RUN_LOCK, 300);
        if (! $lock->get()) {
            return $result;
        }
        try {
            $events = IntegrationEvent::where('status', 'pending')->when($onlyIds !== null, fn ($q) => $q->whereIn('id', $onlyIds))
                ->orderBy('created_at')->orderBy('id')->limit(max(1, min($limit, 1000)))->get();
            $result['processed'] = $events->count();
            if (! $target) {
                // Nothing configured: keep them pending and say so — never claim delivery.
                $ids = $events->filter(fn ($e) => $e->last_error !== Pending::STATUS)->pluck('id')->all();
                if ($ids) {
                    IntegrationEvent::whereIn('id', $ids)->where('status', 'pending')->update(['last_error' => Pending::STATUS]);
                }
                $result['pending'] = $events->count();

                return $result;
            }
            foreach ($events as $event) {
                $r = $target['adapter']->pushDocument($event->type, (array) ($event->payload ?? []));
                if ($r['status'] === 'ok') {
                    $event->update(['status' => 'sent', 'sent_at' => now(), 'attempts' => $event->attempts + 1, 'last_error' => ! empty($r['reference']) ? "ref:{$r['reference']}" : null]);
                    $result['sent']++;
                } elseif ($r['status'] === 'error') {
                    $detail = mb_substr((string) ($r['detail'] ?? 'error'), 0, 500);
                    $event->update(['status' => 'failed', 'attempts' => $event->attempts + 1, 'last_error' => $detail]);
                    $result['failed']++;
                    Log::warning("event {$event->type} ({$event->id}) failed via {$target['kind']}: {$detail}");
                } else {
                    $event->update(['last_error' => Pending::STATUS]);
                    $result['pending']++;
                }
            }
            if ($result['sent'] || $result['failed']) {
                Log::info("outbox via {$target['kind']}: sent {$result['sent']}, failed {$result['failed']}, pending {$result['pending']}");
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    /** Puts a failed event back to pending so the next run retries it. Null when the event does not exist. */
    public function retry(string $id): ?IntegrationEvent
    {
        $event = IntegrationEvent::find($id);
        if (! $event || $event->status !== 'failed') {
            return $event;
        }
        $event->update(['status' => 'pending', 'last_error' => null]);

        return $event->refresh();
    }
}
