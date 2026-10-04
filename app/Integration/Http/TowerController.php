<?php

namespace App\Integration\Http;

use App\Integration\Services\MappingService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

/** Integration Control Tower — for people (session + `integration.*` permissions), never for other systems. */
class TowerController
{
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

    public function unmap(Request $request, MappingService $mappings, string $entity, string $externalId): array
    {
        return $mappings->unmap(AuthUser::current(), (string) ($request->query('system') ?: 'sales'), $entity, $externalId);
    }
}
