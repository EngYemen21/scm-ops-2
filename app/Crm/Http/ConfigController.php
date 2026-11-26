<?php

namespace App\Crm\Http;

use App\Crm\Models\CrmTag;
use App\Crm\Services\CrmConfigService;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the pipeline is made of: stages, sales teams, lost reasons, tags. Add and change — archive instead of delete. */
class ConfigController extends Controller
{
    public function __construct(private readonly CrmConfigService $config) {}

    public function storeStage(Request $request): JsonResponse
    {
        $data = $request->validate(['nameAr' => 'required|string|min:1|max:120'] + self::stageRules());

        return response()->json($this->config->createStage(AuthUser::current(), self::typed($data)), 201);
    }

    public function updateStage(Request $request, string $id): array
    {
        $data = $request->validate(['nameAr' => 'sometimes|string|min:1|max:120', 'active' => 'sometimes|boolean'] + self::stageRules());

        return $this->config->updateStage(AuthUser::current(), $id, self::typed($data));
    }

    public function storeTeam(Request $request): JsonResponse
    {
        $data = $request->validate(['nameAr' => 'required|string|min:1|max:120', 'nameEn' => 'nullable|string|max:120', 'leaderId' => 'nullable|string|max:60', 'sequence' => 'nullable|integer|min:0|max:100000']);

        return response()->json($this->config->createTeam(AuthUser::current(), self::typed($data)), 201);
    }

    public function updateTeam(Request $request, string $id): array
    {
        $data = $request->validate([
            'nameAr' => 'sometimes|string|min:1|max:120', 'nameEn' => 'sometimes|nullable|string|max:120', 'leaderId' => 'sometimes|nullable|string|max:60',
            'sequence' => 'sometimes|integer|min:0|max:100000', 'active' => 'sometimes|boolean', 'pipeline' => 'sometimes|boolean',
        ]);

        return $this->config->updateTeam(AuthUser::current(), $id, self::typed($data));
    }

    public function storeReason(Request $request): JsonResponse
    {
        $data = $request->validate(['nameAr' => 'required|string|min:1|max:190', 'nameEn' => 'nullable|string|max:190']);

        return response()->json($this->config->createReason(AuthUser::current(), $data), 201);
    }

    public function updateReason(Request $request, string $id): array
    {
        $data = $request->validate(['nameAr' => 'sometimes|string|min:1|max:190', 'nameEn' => 'sometimes|nullable|string|max:190', 'active' => 'sometimes|boolean']);

        return $this->config->updateReason(AuthUser::current(), $id, self::typed($data));
    }

    public function storeTag(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|min:1|max:80', 'color' => 'nullable|integer|min:0|max:'.(CrmTag::COLORS - 1)]);

        return response()->json($this->config->createTag(AuthUser::current(), self::typed($data)), 201);
    }

    public function updateTag(Request $request, string $id): array
    {
        $data = $request->validate(['name' => 'sometimes|string|min:1|max:80', 'color' => 'sometimes|integer|min:0|max:'.(CrmTag::COLORS - 1), 'active' => 'sometimes|boolean']);

        return $this->config->updateTag(AuthUser::current(), $id, self::typed($data));
    }

    private static function stageRules(): array
    {
        return [
            'nameEn' => 'sometimes|nullable|string|max:120', 'sequence' => 'sometimes|nullable|integer|min:0|max:100000', 'isWon' => 'sometimes|boolean', 'fold' => 'sometimes|boolean',
            'probability' => 'sometimes|nullable|numeric|min:0|max:100', 'requirements' => 'sometimes|nullable|string|max:2000',
        ];
    }

    /** Validated input may arrive as numeric strings and 0 / 1 flags: give the service real numbers and booleans. */
    private static function typed(array $data): array
    {
        foreach (['isWon', 'fold', 'active', 'pipeline'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = filter_var($data[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach (['sequence', 'color'] as $int) {
            if (isset($data[$int])) {
                $data[$int] = (int) $data[$int];
            }
        }
        if (isset($data['probability'])) {
            $data['probability'] = round((float) $data['probability'], 2);
        }

        return $data;
    }
}
