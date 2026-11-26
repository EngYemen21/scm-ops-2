<?php

namespace App\Payroll\Http;

use App\Http\Controllers\Controller;
use App\Payroll\Services\SkillsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The skills catalogue, and the skills and résumé of the employees. */
class SkillsController extends Controller
{
    private const KINDS = ['types' => 'type', 'skills' => 'skill', 'levels' => 'level', 'resume-types' => 'resumeType'];

    private const ENTRY = ['nameEn' => 'sometimes|nullable|string|max:190', 'typeId' => 'sometimes|nullable|string|max:40', 'sequence' => 'sometimes|nullable|integer|min:0|max:100000',
        'progress' => 'sometimes|nullable|integer|min:0|max:100', 'isDefault' => 'sometimes|boolean', 'active' => 'sometimes|boolean'];

    private const RESUME = ['typeId' => 'sometimes|nullable|string|max:40', 'dateStart' => 'sometimes|nullable|date', 'dateEnd' => 'sometimes|nullable|date', 'description' => 'sometimes|nullable|string|max:4000'];

    public function __construct(private readonly SkillsService $skills) {}

    public function catalog(Request $request): array
    {
        return $this->skills->catalog($request->boolean('all'));
    }

    public function storeEntry(Request $request, string $kind): JsonResponse
    {
        return response()->json($this->skills->save(AuthUser::current(), self::kind($kind), null, $request->validate(['nameAr' => 'required|string|min:1|max:190'] + self::ENTRY)), 201);
    }

    public function updateEntry(Request $request, string $kind, string $id): array
    {
        return $this->skills->save(AuthUser::current(), self::kind($kind), $id, $request->validate(['nameAr' => 'sometimes|string|min:1|max:190'] + self::ENTRY));
    }

    public function employees(Request $request): array
    {
        return $this->skills->employees(AuthUser::current(), Paging::from($request, 25), $request->only(['department', 'type', 'skill', 'level', 'has']));
    }

    public function employee(string $id): array
    {
        return $this->skills->employee(AuthUser::current(), $id);
    }

    public function setSkill(Request $request, string $id): JsonResponse
    {
        return response()->json($this->skills->setSkill(AuthUser::current(), $id, $request->validate(['skillId' => 'required|string|max:40', 'levelId' => 'nullable|string|max:40'])), 201);
    }

    public function removeSkill(string $id, string $skill): array
    {
        return $this->skills->removeSkill(AuthUser::current(), $id, $skill);
    }

    public function storeResume(Request $request, string $id): JsonResponse
    {
        return response()->json($this->skills->saveResumeLine(AuthUser::current(), $id, null, $request->validate(['title' => 'required|string|min:2|max:250'] + self::RESUME)), 201);
    }

    public function updateResume(Request $request, string $id, string $line): array
    {
        return $this->skills->saveResumeLine(AuthUser::current(), $id, $line, $request->validate(['title' => 'sometimes|string|min:2|max:250'] + self::RESUME));
    }

    public function removeResume(string $id, string $line): array
    {
        return $this->skills->removeResumeLine(AuthUser::current(), $id, $line);
    }

    private static function kind(string $segment): string
    {
        return self::KINDS[$segment] ?? throw AppError::notFound('NOT_FOUND', 'العنصر غير موجود', 'Not found');
    }
}
