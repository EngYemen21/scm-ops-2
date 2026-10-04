<?php

namespace App\Integration\Services;

use App\Integration\Models\IntException;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;

/**
 * Integration exceptions: what a human must look at. An open exception with the same code and entity is counted
 * again instead of being duplicated, so a retried event does not flood the queue.
 */
class IntegrationExceptions
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param array{system?:?string, entity?:?string, entityRef?:?string, correlationId?:?string, eventId?:?string, severity?:string, details?:mixed} $ctx */
    public function raise(string $code, string $message, array $ctx = []): IntException
    {
        $open = IntException::where('status', 'open')->where('code', $code)
            ->where('entity_ref', $ctx['entityRef'] ?? null)->where('system', $ctx['system'] ?? null)->first();
        if ($open) {
            $open->update(['occurrences' => $open->occurrences + 1, 'last_at' => now(), 'message' => $message,
                'details' => $ctx['details'] ?? $open->details, 'event_id' => $ctx['eventId'] ?? $open->event_id]);

            return $open;
        }

        return IntException::create([
            'code' => $code, 'severity' => $ctx['severity'] ?? 'warn', 'system' => $ctx['system'] ?? null, 'entity' => $ctx['entity'] ?? null,
            'entity_ref' => $ctx['entityRef'] ?? null, 'correlation_id' => $ctx['correlationId'] ?? null, 'event_id' => $ctx['eventId'] ?? null,
            'message' => $message, 'details' => $ctx['details'] ?? null,
        ]);
    }

    /** Closes the open exceptions of a code for an entity (the cause went away, e.g. the product got mapped). */
    public function autoResolve(string $code, ?string $entityRef, string $why): int
    {
        return IntException::where('status', 'open')->where('code', $code)->where('entity_ref', $entityRef)
            ->update(['status' => 'resolved', 'resolved_by' => 'system', 'resolved_at' => now(), 'resolution' => $why]);
    }

    public function resolve(AuthUser $user, string $id, string $status, ?string $note): IntException
    {
        $e = IntException::find($id) ?? throw AppError::notFound('INT_EXCEPTION_NOT_FOUND', 'الاستثناء غير موجود', 'Exception not found');
        if ($e->status !== 'open') {
            throw AppError::rule('INT_EXCEPTION_CLOSED', 'الاستثناء مغلق مسبقًا', 'Exception already closed');
        }
        $e->update(['status' => $status, 'resolved_by' => $user->username, 'resolved_at' => now(), 'resolution' => $note]);
        $this->audit->log($user, ['action' => 'INTEGRATION.EXCEPTION_'.strtoupper($status), 'entityType' => 'int_exception', 'entityId' => $e->id,
            'entityNumber' => $e->code, 'oldValue' => 'open', 'newValue' => ['status' => $status, 'note' => $note]]);

        return $e->refresh();
    }

    public function list(Paging $page, ?string $status = null, ?string $code = null): array
    {
        $q = IntException::query()->when($status, fn ($x) => $x->where('status', $status))->when($code, fn ($x) => $x->where('code', $code));
        if ($page->q) {
            $like = '%'.addcslashes($page->q, '%_\\').'%';
            $q->where(fn ($x) => $x->where('entity_ref', 'like', $like)->orWhere('correlation_id', 'like', $like)->orWhere('message', 'like', $like));
        }

        return $page->paginate($q->orderBy('last_at', 'desc'));
    }
}
