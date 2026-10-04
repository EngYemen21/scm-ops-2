<?php

namespace App\Integration\Services;

use App\Integration\Models\IntDelivery;
use App\Integration\Models\IntException;
use App\Integration\Models\IntInbox;
use App\Integration\Support\Systems;
use App\Models\AuditLog;
use App\Models\IntegrationEvent;
use App\Models\OpsException;
use App\Models\ProofOfDelivery;
use App\Models\ReturnOrder;
use App\Models\SalesOrder;
use App\Support\AppError;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Integration Control Tower (ARCHITECTURE §16–§17): health of every connected system, throughput, queues (pending,
 * retrying, dead), exceptions, and the end-to-end trace of one business journey by its correlation id.
 */
class TowerService
{
    public function __construct(private readonly Dispatcher $dispatcher) {}

    public function overview(): array
    {
        $since = now()->subDay();
        $systems = [];
        foreach (Systems::all() as $code => $s) {
            $lastIn = IntInbox::where('source', $code)->max('received_at');
            $lastOut = IntDelivery::where('subscriber', $code)->where('status', 'sent')->max('sent_at');
            $systems[] = [
                'code' => $code, 'name' => $s['name'] ?? $code, 'enabled' => (bool) ($s['enabled'] ?? false),
                'keys' => count(Systems::keys((string) $code)), 'deliversTo' => (bool) ($s['deliver_url'] ?? null), 'cycle' => (bool) ($s['cycle_url'] ?? null),
                'scopes' => array_values((array) ($s['scopes'] ?? [])), 'breaker' => $this->dispatcher->breakerState((string) $code),
                'lastReceivedAt' => $lastIn, 'lastDeliveredAt' => $lastOut,
                'pending' => IntDelivery::where('subscriber', $code)->whereIn('status', ['pending', 'failed'])->count(),
                'dead' => IntDelivery::where('subscriber', $code)->where('status', 'dead')->count(),
                'health' => match (true) {
                    empty($s['enabled']) => 'disabled',
                    $this->dispatcher->breakerState((string) $code)['open'] => 'down',
                    IntDelivery::where('subscriber', $code)->where('status', 'dead')->where('created_at', '>=', $since)->exists() => 'degraded',
                    default => 'ok',
                },
            ];
        }
        $count = fn ($q) => (clone $q)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->map(fn ($v) => (int) $v)->all();

        return [
            'systems' => $systems,
            'inbox' => ['last24h' => $count(IntInbox::where('received_at', '>=', $since)), 'open' => $count(IntInbox::whereIn('status', ['failed', 'blocked', 'dead']))],
            'deliveries' => ['last24h' => $count(IntDelivery::where('created_at', '>=', $since)), 'open' => $count(IntDelivery::whereIn('status', ['pending', 'failed', 'dead']))],
            'exceptions' => IntException::where('status', 'open')->selectRaw('severity, count(*) c')->groupBy('severity')->pluck('c', 'severity')->map(fn ($v) => (int) $v)->all(),
            'avgProcessingMs' => (int) round((float) IntInbox::where('received_at', '>=', $since)->whereNotNull('duration_ms')->avg('duration_ms')),
            'avgDeliveryMs' => (int) round((float) IntDelivery::where('created_at', '>=', $since)->whereNotNull('response_ms')->avg('response_ms')),
            'integratedOrders' => ['open' => SalesOrder::whereNotNull('source_system')->whereNotIn('status', ['delivered', 'completed', 'cancelled', 'returned', 'partial', 'failed'])->count(),
                'backordered' => SalesOrder::whereNotNull('source_system')->where('status', 'confirmed')->count()],
            'lastRun' => Cache::get(IntegrationRunner::LAST_KEY),
            'lastReconciliation' => Cache::get(ReconciliationService::LAST_KEY),
        ];
    }

    public function inbox(Paging $page, array $f): array
    {
        $q = IntInbox::query()->when($f['status'] ?? null, fn ($x, $v) => $x->where('status', $v))->when($f['type'] ?? null, fn ($x, $v) => $x->where('type', $v))
            ->when($f['source'] ?? null, fn ($x, $v) => $x->where('source', $v));
        if ($page->q) {
            $q->where(fn ($x) => $x->where('subject', $page->q)->orWhere('correlation_id', $page->q)->orWhere('event_id', $page->q));
        }

        return $page->paginate($q->orderByDesc('received_at'));
    }

    public function deliveries(Paging $page, array $f): array
    {
        $q = IntDelivery::with('event:id,type,subject,sequence,correlation_id,created_at')
            ->when($f['status'] ?? null, fn ($x, $v) => $x->where('status', $v))->when($f['subscriber'] ?? null, fn ($x, $v) => $x->where('subscriber', $v));
        if ($page->q) {
            $q->whereHas('event', fn ($e) => $e->where('subject', $page->q)->orWhere('correlation_id', $page->q)->orWhere('id', $page->q));
        }

        return $page->paginate($q->orderByDesc('created_at'));
    }

    /** Payload of one received or published event (Inspect Payload). */
    public function payload(string $id): array
    {
        if ($row = IntInbox::find($id) ?? IntInbox::where('event_id', $id)->first()) {
            return ['direction' => 'in', 'envelope' => ['id' => $row->event_id, 'type' => $row->type, 'source' => $row->source, 'subject' => $row->subject,
                'sequence' => $row->sequence, 'time' => $row->event_time, 'schemaVersion' => $row->schema_version, 'correlationId' => $row->correlation_id,
                'causationId' => $row->causation_id, 'data' => $row->data], 'outcome' => $row->only(['status', 'attempts', 'last_code', 'last_error', 'result', 'duration_ms', 'received_at', 'processed_at'])];
        }
        $e = IntegrationEvent::find($id) ?? IntDelivery::find($id)?->event ?? throw AppError::notFound('EVENT_NOT_FOUND', 'الحدث غير موجود', 'Event not found');

        return ['direction' => 'out', 'envelope' => EventPublisher::envelope($e), 'deliveries' => IntDelivery::where('event_id', $e->id)->get()->toArray()];
    }

    /**
     * Everything that happened for one business journey (a Sales order id, or an OPS SO number), in time order: events
     * received and sent, the documents OPS created (SO, FO, trip, POD, returns), exceptions on both levels, and the audit
     * trail of the integration.
     */
    public function trace(string $key): array
    {
        $so = SalesOrder::with(['fos.trip', 'customer'])->where('external_ref', $key)->orWhere('number', $key)->first();
        $corr = $so?->external_ref ?? $key;
        $rows = [];
        foreach (IntInbox::where('correlation_id', $corr)->orWhere('subject', $corr)->get() as $e) {
            $rows[] = ['at' => $e->received_at, 'kind' => 'received', 'title' => $e->type, 'status' => $e->status, 'ref' => $e->event_id, 'detail' => $e->last_code ?: ($e->result['opsOrder'] ?? null), 'id' => $e->id];
        }
        foreach (IntegrationEvent::where('correlation_id', $corr)->where('source', 'ops')->with([])->get() as $e) {
            $d = IntDelivery::where('event_id', $e->id)->get(['subscriber', 'status', 'attempts', 'last_error']);
            $rows[] = ['at' => $e->created_at, 'kind' => 'published', 'title' => $e->type, 'status' => $d->pluck('status')->unique()->implode(',') ?: 'no-subscriber',
                'ref' => $e->id, 'detail' => '#'.$e->sequence.' '.$d->map(fn ($x) => "{$x->subscriber}:{$x->status}".($x->attempts > 1 ? "×{$x->attempts}" : ''))->implode(' '), 'id' => $e->id];
        }
        $documents = [];
        if ($so) {
            $documents[] = ['type' => 'SalesOrder', 'number' => $so->number, 'status' => $so->status, 'link' => "/so/{$so->number}"];
            foreach ($so->fos as $fo) {
                $documents[] = ['type' => 'FulfillmentOrder', 'number' => $fo->number, 'status' => $fo->status, 'link' => "/fo/{$fo->number}"];
                if ($fo->trip) {
                    $documents[] = ['type' => 'Trip', 'number' => $fo->trip->number, 'status' => $fo->trip->status, 'link' => "/trip/{$fo->trip->number}"];
                }
                foreach (ProofOfDelivery::where('fo_id', $fo->id)->get() as $pod) {
                    $documents[] = ['type' => 'ProofOfDelivery', 'number' => $pod->number, 'status' => $pod->result, 'receiver' => $pod->receiver_name, 'at' => $pod->at];
                }
                foreach (ReturnOrder::where('fo_id', $fo->id)->get() as $r) {
                    $documents[] = ['type' => 'Return', 'number' => $r->number, 'status' => $r->status, 'link' => "/rtn/{$r->number}"];
                }
            }
            foreach (DB::table('status_history')->where('entity_type', 'SalesOrder')->where('entity_id', $so->id)->get() as $h) {
                $rows[] = ['at' => Carbon::parse($h->at), 'kind' => 'ops', 'title' => "{$so->number}: ".($h->from_status ?? '—')." → {$h->to_status}", 'status' => $h->to_status, 'ref' => $h->username, 'detail' => $h->note];
            }
        }
        $intExc = IntException::where('correlation_id', $corr)->orWhere('entity_ref', $corr)->get();
        $opsExc = $so ? OpsException::where('document_number', $so->number)->orWhere('entity_number', $so->number)->get(['number', 'kind', 'severity', 'status', 'text_ar', 'created_at']) : collect();
        // to the millisecond: several steps of one journey happen within the same second
        $ms = fn ($at) => $at instanceof \DateTimeInterface ? (int) $at->format('Uv') : (int) Carbon::parse((string) $at)->format('Uv');
        usort($rows, fn ($a, $b) => $ms($a['at']) <=> $ms($b['at']));
        if (! $rows && ! $so) {
            throw AppError::notFound('TRACE_NOT_FOUND', 'لا يوجد أي أثر لهذا المعرّف في التكامل', 'Nothing found for this id');
        }

        return [
            'key' => $key, 'correlationId' => $corr, 'order' => $so ? ['number' => $so->number, 'status' => $so->status, 'fulfilment' => $so->fulfil_status,
                'customer' => $so->customer?->name_ar, 'source' => $so->source_system, 'externalRef' => $so->external_ref, 'commercial' => $so->commercial] : null,
            'documents' => $documents, 'timeline' => $rows,
            'exceptions' => ['integration' => $intExc->toArray(), 'operational' => $opsExc->toArray()],
            'audit' => AuditLog::where('action', 'like', 'INTEGRATION.%')->where(fn ($q) => $q->where('entity_number', 'like', "%{$corr}%"))->orderBy('at')->limit(100)->get(['action', 'username', 'entity_number', 'new_value', 'at'])->toArray(),
        ];
    }
}
