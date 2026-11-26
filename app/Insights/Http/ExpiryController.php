<?php

namespace App\Insights\Http;

use App\Http\Controllers\Controller;
use App\Insights\Models\ExpiryLevel;
use App\Insights\Services\ExpiryService;
use App\Insights\Support\Access;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Expiry alert levels, the log of what was announced, and the lots coming up. Not for brand owners (outsiders). */
class ExpiryController extends Controller
{
    public function __construct(private readonly ExpiryService $expiry) {}

    public function levels(Request $request): array
    {
        Access::insider(AuthUser::current());

        return ['items' => $this->expiry->levels($request->validate(['active' => 'nullable|in:0,1,true,false']))];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|min:2|max:120', 'daysBeforeExpiry' => 'required|integer|min:0|max:3650', 'userIds' => 'required|array|min:1|max:100'] + self::rules());

        return response()->json($this->expiry->createLevel(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate(['name' => 'sometimes|string|min:2|max:120', 'daysBeforeExpiry' => 'sometimes|integer|min:0|max:3650', 'userIds' => 'sometimes|array|min:1|max:100', 'active' => 'sometimes|boolean'] + self::rules());

        return $this->expiry->updateLevel(AuthUser::current(), $id, $data);
    }

    public function log(Request $request): array
    {
        Access::insider(AuthUser::current());
        $f = $request->validate(['level' => 'nullable|string|max:60', 'sku' => 'nullable|string|max:120', 'source' => 'nullable|in:ops,odoo', 'from' => 'nullable|date', 'to' => 'nullable|date']);

        return $this->expiry->log(Paging::from($request, 50), $f);
    }

    public function upcoming(Request $request): array
    {
        Access::insider(AuthUser::current());

        return $this->expiry->upcoming(Paging::from($request, 50), []);
    }

    public function recipients(): array
    {
        return ['items' => $this->expiry->recipients()];
    }

    public function run(): array
    {
        return $this->expiry->run(AuthUser::current());
    }

    private static function rules(): array
    {
        return ['notificationType' => ['sometimes', Rule::in(ExpiryLevel::CHANNELS)], 'sequence' => 'sometimes|integer|min:0|max:10000', 'color' => 'sometimes|integer|min:0|max:11',
            'userIds.*' => 'string|max:60'];
    }
}
