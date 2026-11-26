<?php

namespace App\Credit\Http;

use App\Credit\Services\ConfigService;
use App\Credit\Support\Flow;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the section is made of: lookups (every user of the page) and the manager's stages, terms, payment methods and policies. */
class ConfigController extends Controller
{
    public function __construct(private readonly ConfigService $config) {}

    public function lookups(): array
    {
        return $this->config->lookups(AuthUser::current());
    }

    public function storeStage(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:40', 'nameAr' => 'required|string|min:1|max:120', 'state' => 'required|in:'.implode(',', Flow::STATES)] + self::stageRules());

        return response()->json($this->config->createStage(AuthUser::current(), $data), 201);
    }

    public function updateStage(Request $request, string $id): array
    {
        $data = $request->validate(['code' => 'sometimes|string|max:40', 'nameAr' => 'sometimes|string|min:1|max:120', 'state' => 'sometimes|in:'.implode(',', Flow::STATES), 'active' => 'sometimes|boolean'] + self::stageRules());

        return $this->config->updateStage(AuthUser::current(), $id, $data);
    }

    public function terms(): array
    {
        return $this->config->terms();
    }

    public function storeTerms(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|min:1|max:190', 'version' => 'required|string|min:1|max:40', 'effectiveDate' => 'required|date_format:Y-m-d'] + self::termsRules());

        return response()->json($this->config->createTerms(AuthUser::current(), $data), 201);
    }

    public function updateTerms(Request $request, string $id): array
    {
        $data = $request->validate(['name' => 'sometimes|string|min:1|max:190', 'version' => 'sometimes|string|min:1|max:40', 'effectiveDate' => 'sometimes|date_format:Y-m-d'] + self::termsRules());

        return $this->config->updateTerms(AuthUser::current(), $id, $data);
    }

    public function publishTerms(string $id): array
    {
        return $this->config->publishTerms(AuthUser::current(), $id);
    }

    public function retireTerms(Request $request, string $id): array
    {
        return $this->config->retireTerms(AuthUser::current(), $id, $request->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? null);
    }

    public function storeMethod(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:40', 'nameAr' => 'required|string|min:1|max:120', 'nameEn' => 'nullable|string|max:120', 'sequence' => 'nullable|integer|min:0|max:9999']);

        return response()->json($this->config->createMethod(AuthUser::current(), $data), 201);
    }

    public function updateMethod(Request $request, string $id): array
    {
        $data = $request->validate(['nameAr' => 'sometimes|string|min:1|max:120', 'nameEn' => 'sometimes|nullable|string|max:120', 'sequence' => 'sometimes|integer|min:0|max:9999', 'active' => 'sometimes|boolean']);

        return $this->config->updateMethod(AuthUser::current(), $id, $data);
    }

    public function putSettings(Request $request, string $group): array
    {
        return $this->config->putSettings(AuthUser::current(), $group, (array) $request->validate(['value' => 'required|array'])['value']);
    }

    private static function stageRules(): array
    {
        return [
            'nameEn' => 'sometimes|nullable|string|max:120', 'sequence' => 'sometimes|integer|min:0|max:9999', 'approvalMode' => 'sometimes|in:'.implode(',', Flow::APPROVAL_MODES),
            'responsibleRole' => 'sometimes|nullable|string|max:60', 'responsibleUserId' => 'sometimes|nullable|string|max:60', 'escalationRole' => 'sometimes|nullable|string|max:60',
            'slaHours' => 'sometimes|nullable|numeric|min:0|max:2000', 'reminderBeforeHours' => 'sometimes|nullable|numeric|min:0|max:2000', 'escalateAfterHours' => 'sometimes|nullable|numeric|min:0|max:2000',
            'fold' => 'sometimes|boolean', 'requireDocuments' => 'sometimes|boolean', 'requireSignature' => 'sometimes|boolean', 'autoSendSignature' => 'sometimes|boolean',
            'lockRequestFields' => 'sometimes|boolean', 'portalVisible' => 'sometimes|boolean',
        ];
    }

    private static function termsRules(): array
    {
        return ['termsAr' => 'sometimes|nullable|string|max:60000', 'termsEn' => 'sometimes|nullable|string|max:60000', 'jurisdictionCity' => 'sometimes|nullable|string|max:120'];
    }
}
