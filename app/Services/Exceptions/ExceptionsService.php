<?php

namespace App\Services\Exceptions;

use App\Models\ExceptionEvent;
use App\Models\OpsException;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Persistent operational exceptions: raised automatically by the inbound / putaway / loading / delivery flows and
 * manually by users. Each one has an owner role, an SLA and an event trail.
 *
 * raise() joins the caller's transaction when there is one. Flows that REJECT an operation must call it outside
 * (after) their rolled-back transaction, otherwise the exception row disappears with the rollback.
 */
class ExceptionsService
{
    /** Default owner role per exception kind. */
    private const OWNER = ['damage' => 'proc', 'rejected' => 'proc', 'shortage' => 'proc', 'wrongloc' => 'wm', 'wrongveh' => 'disp', 'capacity' => 'disp', 'faildel' => 'disp', 'partial' => 'disp', 'temp' => 'wm', 'transfer' => 'wm'];

    public function __construct(
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array{kind:string, severity?:string, ownerRole?:?string, slaHours?:int|float|null, entityType?:?string, entityId?:?string,
     *               entityNumber?:?string, documentType?:?string, documentId?:?string, documentNumber?:?string, textAr:string, textEn?:?string}  $e
     */
    public function raise(?AuthUser $user, array $e): OpsException
    {
        return DB::transaction(function () use ($user, $e) {
            $sla = (array) $this->settings->get('exceptions.sla');
            $number = $this->numbering->next('EXC');
            $owner = ($e['ownerRole'] ?? null) ?: (self::OWNER[$e['kind']] ?? 'wm');
            $exception = OpsException::create([
                'number' => $number, 'kind' => $e['kind'], 'severity' => $e['severity'] ?? 'w', 'owner_role' => $owner,
                'sla_hours' => $e['slaHours'] ?? $sla[$e['kind']] ?? $sla['other'] ?? 24,
                'entity_type' => $e['entityType'] ?? null, 'entity_id' => $e['entityId'] ?? null, 'entity_number' => $e['entityNumber'] ?? null,
                'document_type' => $e['documentType'] ?? null, 'document_id' => $e['documentId'] ?? null, 'document_number' => $e['documentNumber'] ?? null,
                'text_ar' => $e['textAr'], 'text_en' => $e['textEn'] ?? $e['textAr'], 'created_by_id' => $user?->id, 'created_by' => $user?->username ?? 'system',
            ]);
            ExceptionEvent::create(['exception_id' => $exception->id, 'to_status' => 'open', 'user_id' => $user?->id, 'username' => $user?->username ?? 'system']);
            $this->audit->log($user, ['action' => 'EXCEPTION.RAISE', 'entityType' => 'OpsException', 'entityId' => $exception->id, 'entityNumber' => $number,
                'newValue' => ['kind' => $e['kind'], 'severity' => $e['severity'] ?? null, 'entity' => $e['entityNumber'] ?? null]]);
            $textEn = $e['textEn'] ?? $e['textAr'];
            $this->notify->activity($user, 'OpsException', $exception->id, $number, "استثناء {$number} ({$e['kind']}): {$e['textAr']}", "Exception {$number} ({$e['kind']}): {$textEn}", [$owner]);

            return $exception->refresh();
        });
    }

    /** @param  array{status?:?string, kind?:?string, severity?:?string, owner?:?string, entity?:?string}  $filters */
    public function list(Paging $page, array $filters): array
    {
        $query = OpsException::with(['events' => fn ($q) => $q->orderBy('at')])->orderByDesc('created_at')->orderByDesc('id');
        foreach (['status' => 'status', 'kind' => 'kind', 'severity' => 'severity', 'owner' => 'owner_role'] as $param => $column) {
            if (! empty($filters[$param])) {
                $query->where($column, $filters[$param]);
            }
        }
        $entity = $filters['entity'] ?? null;
        if ($entity || $page->q) {
            $query->where(function ($w) use ($entity, $page) {
                if ($entity) {
                    $w->orWhere('entity_number', 'like', "%{$entity}%")->orWhere('document_number', 'like', "%{$entity}%");
                }
                if ($page->q) {
                    $w->orWhere('number', 'like', "%{$page->q}%")->orWhere('text_ar', 'like', "%{$page->q}%")->orWhere('text_en', 'like', "%{$page->q}%");
                }
            });
        }

        return $page->paginate($query, fn (OpsException $e) => $e->toArray() + ['sla' => $this->slaInfo($e)]);
    }

    public function get(string $idOrNumber): array
    {
        $e = $this->find($idOrNumber)->load(['events' => fn ($q) => $q->orderBy('at')]);

        return $e->toArray() + ['sla' => $this->slaInfo($e)];
    }

    public function setStatus(AuthUser $user, string $idOrNumber, string $to, ?string $note = null, ?string $ownerUserId = null): OpsException
    {
        $e = $this->find($idOrNumber);
        if ($e->status === 'resolved') {
            throw AppError::rule('EXC_CLOSED', 'الاستثناء مُغلق', 'Exception already resolved');
        }
        Sm::assert('EXCEPTION_TRANSITIONS', $e->status, $to, 'EXC_TRANSITION');

        return DB::transaction(function () use ($user, $e, $to, $note, $ownerUserId) {
            $from = $e->status;
            $e->update([
                'status' => $to,
                'resolution' => $to === 'resolved' ? ($note ?: $e->resolution) : $e->resolution,
                'acknowledged_at' => $to === 'ack' ? now() : $e->acknowledged_at,
                'resolved_at' => $to === 'resolved' ? now() : null,
                'owner_user_id' => $ownerUserId ?: ($e->owner_user_id ?: $user->id),
            ]);
            ExceptionEvent::create(['exception_id' => $e->id, 'from_status' => $from, 'to_status' => $to, 'user_id' => $user->id, 'username' => $user->username, 'note' => $note]);
            $this->audit->status($user, 'OpsException', $e->id, $e->number, $from, $to, $note);
            $label = $to === 'ack' ? 'قيد المعالجة' : 'مُغلق';
            $this->notify->activity($user, 'OpsException', $e->id, $e->number, "الاستثناء {$e->number} → {$label}".($note ? ' — '.$note : ''));

            return $e->refresh();
        });
    }

    /** @return array{dueAt:string, leftMin:int, breached:bool, labelAr:string} */
    public function slaInfo(OpsException $e): array
    {
        $due = Carbon::parse($e->created_at)->addMinutes((int) round($e->sla_hours * 60));
        $ref = $e->status === 'resolved' && $e->resolved_at ? $e->resolved_at : now();
        $leftMin = (int) round(($due->getTimestamp() - $ref->getTimestamp()) / 60);
        $left = $leftMin >= 60 ? intdiv($leftMin, 60).' س '.($leftMin % 60).' د' : $leftMin.' د';
        $hours = rtrim(rtrim(number_format($e->sla_hours, 2, '.', ''), '0'), '.');

        return [
            'dueAt' => $due->toJSON(), 'leftMin' => $leftMin, 'breached' => $leftMin < 0,
            'labelAr' => $leftMin < 0 ? 'SLA متجاوز بـ '.abs($leftMin).' د' : "SLA {$hours}h · متبقٍ {$left}",
        ];
    }

    private function find(string $idOrNumber): OpsException
    {
        return OpsException::where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first()
            ?? throw AppError::notFound('EXC_NOT_FOUND', 'الاستثناء غير موجود', 'Exception not found');
    }
}
