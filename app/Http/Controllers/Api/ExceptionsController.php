<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Exceptions\ExceptionsService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

class ExceptionsController extends Controller
{
    public function __construct(private readonly ExceptionsService $exceptions) {}

    public function index(Request $request): array
    {
        return $this->exceptions->list(Paging::from($request), $request->only(['status', 'kind', 'severity', 'owner', 'entity']));
    }

    public function show(string $id): array
    {
        return $this->exceptions->get($id);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'kind' => 'required|string|min:1', 'severity' => 'sometimes|in:c,w,i', 'ownerRole' => 'nullable|string',
            'slaHours' => 'sometimes|numeric|gt:0', 'entityType' => 'nullable|string', 'entityNumber' => 'nullable|string',
            'textAr' => 'required|string|min:1', 'textEn' => 'nullable|string',
        ]);

        return $this->exceptions->raise(AuthUser::current(), $data + ['severity' => 'w', 'slaHours' => 4]);
    }

    public function ack(Request $request, string $id)
    {
        $data = $request->validate(['note' => 'nullable|string', 'ownerUserId' => 'nullable|string']);

        return $this->exceptions->setStatus(AuthUser::current(), $id, 'ack', $data['note'] ?? null, $data['ownerUserId'] ?? null);
    }

    public function resolve(Request $request, string $id)
    {
        $data = $request->validate(['note' => 'nullable|string']);

        return $this->exceptions->setStatus(AuthUser::current(), $id, 'resolved', $data['note'] ?? null);
    }
}
