<?php

namespace App\Integration\Http;

use App\Integration\Services\Dispatcher;
use App\Integration\Services\InboxService;
use App\Integration\Services\IntegrationExceptions;
use App\Integration\Services\IntegrationRunner;
use App\Integration\Services\MappingService;
use App\Integration\Services\ReconciliationService;
use App\Integration\Services\TowerService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

/** Integration Control Tower — for people (session + `integration.*` permissions), never for other systems. */
class TowerController
{
    public function overview(TowerService $tower): array
    {
        return $tower->overview();
    }

    public function inbox(Request $request, TowerService $tower): array
    {
        return $tower->inbox(Paging::from($request), $request->only(['status', 'type', 'source']));
    }

    public function deliveries(Request $request, TowerService $tower): array
    {
        return $tower->deliveries(Paging::from($request), $request->only(['status', 'subscriber']));
    }

    public function exceptions(Request $request, IntegrationExceptions $exceptions): array
    {
        $v = $request->validate(['status' => 'nullable|in:open,resolved,ignored', 'code' => 'nullable|string|max:60']);

        return $exceptions->list(Paging::from($request), $v['status'] ?? null, $v['code'] ?? null);
    }

    public function payload(TowerService $tower, string $id): array
    {
        return $tower->payload($id);
    }

    public function trace(TowerService $tower, string $key): array
    {
        return $tower->trace($key);
    }

    public function replay(InboxService $inbox, string $id): array
    {
        return $inbox->replay(AuthUser::current(), $id)->toArray();
    }

    public function retry(Dispatcher $dispatcher, string $id): array
    {
        return $dispatcher->retry(AuthUser::current(), $id)->toArray();
    }

    public function resolve(Request $request, IntegrationExceptions $exceptions, string $id): array
    {
        $v = $request->validate(['status' => 'required|in:resolved,ignored', 'note' => 'required|string|min:3|max:500']);

        return $exceptions->resolve(AuthUser::current(), $id, $v['status'], $v['note'])->toArray();
    }

    public function run(IntegrationRunner $runner): array
    {
        return $runner->run(100, 'manual:'.AuthUser::current()->username);
    }

    public function reconcile(ReconciliationService $recon): array
    {
        return $recon->run();
    }

    public function mappings(Request $request, MappingService $mappings, string $entity): array
    {
        $v = $request->validate(['system' => 'nullable|string|max:40', 'state' => 'nullable|in:all,mapped,unmapped']);

        return $mappings->list(Paging::from($request), $v['system'] ?? 'sales', $entity, $v['state'] ?? 'all');
    }

    public function map(Request $request, MappingService $mappings, string $entity): array
    {
        $v = $request->validate(['system' => 'nullable|string|max:40', 'externalId' => 'required|string|max:120', 'internal' => 'required|string|max:120']);

        return $mappings->map(AuthUser::current(), $v['system'] ?? 'sales', $entity, $v['externalId'], $v['internal']);
    }

    /** Create the mirrored product in OPS and link it — the steward confirms the physical attributes. */
    public function adopt(Request $request, MappingService $mappings): array
    {
        $v = $request->validate([
            'system' => 'nullable|string|max:40', 'externalId' => 'required|string|max:120', 'sku' => 'nullable|string|min:3|max:32',
            'weightKg' => 'required|numeric|gt:0', 'lengthCm' => 'required|numeric|gt:0', 'widthCm' => 'required|numeric|gt:0', 'heightCm' => 'required|numeric|gt:0',
            'storageClass' => 'nullable|in:ambient,chilled,frozen', 'uomCode' => 'nullable|string|max:20', 'estimated' => 'nullable|boolean',
        ]);

        return $mappings->adopt(AuthUser::current(), $v['system'] ?? 'sales', $v['externalId'], $v);
    }

    public function unmap(Request $request, MappingService $mappings, string $entity, string $externalId): array
    {
        return $mappings->unmap(AuthUser::current(), (string) ($request->query('system') ?: 'sales'), $entity, $externalId);
    }
}
