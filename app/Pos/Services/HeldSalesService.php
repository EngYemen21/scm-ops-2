<?php

namespace App\Pos\Services;

use App\Pos\Models\PosHeldSale;
use App\Pos\Models\PosSession;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

/**
 * Sales put aside at the counter. Kept on the server, so a held sale survives a reload, a crash of the tablet or a
 * change of device; it belongs to its session and is recalled into it (or discarded) before the session closes.
 */
class HeldSalesService
{
    public function __construct(private readonly AuditService $audit) {}

    public function list(string $sessionId): array
    {
        return PosHeldSale::where('session_id', $sessionId)->where('status', 'held')->orderByDesc('created_at')->orderByDesc('id')->get()->map->toArray()->all();
    }

    /** @param  array{session:string, label?:?string, customer?:?string, lines:array, total?:mixed, clientKey?:?string}  $dto */
    public function hold(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $s = PosSession::whereKey($dto['session'])->lockForUpdate()->first() ?? throw AppError::notFound('POS_SESSION_NOT_FOUND', 'الجلسة غير موجودة', 'Session not found');
            SaleService::assertSells($user, $s);
            if (! empty($dto['clientKey']) && ($twin = PosHeldSale::where('session_id', $s->id)->where('client_key', $dto['clientKey'])->where('status', 'held')->first())) {
                $twin->update(['payload' => ['customer' => $dto['customer'] ?? null, 'lines' => $dto['lines']], 'label' => $dto['label'] ?? $twin->label, 'total' => $dto['total'] ?? $twin->total]);

                return $twin->refresh()->toArray(); // the same cart held again: updated, not doubled
            }
            $h = PosHeldSale::create([
                'session_id' => $s->id, 'register_id' => $s->register_id, 'user_id' => $user->id, 'username' => $user->username,
                'label' => mb_substr(trim((string) ($dto['label'] ?? '')) ?: 'بيع معلّق '.now()->format('H:i'), 0, 120),
                'client_key' => $dto['clientKey'] ?? null, 'payload' => ['customer' => $dto['customer'] ?? null, 'lines' => $dto['lines']],
                'total' => $dto['total'] ?? null, 'status' => 'held',
            ]);
            $this->audit->log($user, ['action' => 'POS.HOLD', 'entityType' => 'PosHeldSale', 'entityId' => $h->id, 'entityNumber' => $s->number, 'newValue' => ['lines' => count($dto['lines']), 'label' => $h->label]]);

            return $h->toArray();
        });
    }

    /** Takes a held sale back to the screen. It stays "recalled" until the sale that pays it is recorded. */
    public function recall(AuthUser $user, string $id): array
    {
        return DB::transaction(function () use ($user, $id) {
            $h = $this->lock($id);
            $s = PosSession::findOrFail($h->session_id);
            SaleService::assertSells($user, $s);
            if ($h->status !== 'held') {
                throw AppError::rule('POS_HELD_STATE', 'هذا البيع المعلّق استُرجع أو أُلغي مسبقًا', 'This held sale was already recalled or discarded');
            }
            $h->update(['status' => 'recalled', 'recalled_at' => now()]);
            $this->audit->log($user, ['action' => 'POS.RECALL', 'entityType' => 'PosHeldSale', 'entityId' => $h->id, 'entityNumber' => $s->number]);

            return $h->refresh()->toArray();
        });
    }

    public function discard(AuthUser $user, string $id, ?string $reason): array
    {
        return DB::transaction(function () use ($user, $id, $reason) {
            $h = $this->lock($id);
            SaleService::assertSells($user, PosSession::findOrFail($h->session_id));
            if ($h->status !== 'held') {
                throw AppError::rule('POS_HELD_STATE', 'هذا البيع المعلّق استُرجع أو أُلغي مسبقًا', 'This held sale was already recalled or discarded');
            }
            $h->update(['status' => 'discarded', 'discard_reason' => $reason ? mb_substr($reason, 0, 250) : null]);
            $this->audit->log($user, ['action' => 'POS.HOLD.DISCARD', 'entityType' => 'PosHeldSale', 'entityId' => $h->id, 'newValue' => ['reason' => $reason]]);

            return $h->refresh()->toArray();
        });
    }

    private function lock(string $id): PosHeldSale
    {
        return PosHeldSale::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('POS_HELD_NOT_FOUND', 'البيع المعلّق غير موجود', 'Held sale not found');
    }
}
