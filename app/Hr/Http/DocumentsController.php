<?php

namespace App\Hr\Http;

use App\Hr\Models\Document;
use App\Hr\Services\DocumentsService;
use App\Hr\Services\FilesService;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The register of employee documents. Reading: `hr.manage` or `hr.private`; writing: `hr.manage`; a private document also needs `hr.private` (the service decides). */
class DocumentsController extends Controller
{
    public function __construct(private readonly DocumentsService $documents) {}

    public function index(Request $request): array
    {
        return $this->documents->list(AuthUser::current(), Paging::from($request, 25), $request->only(['employee', 'kind', 'status', 'due', 'department', 'order']));
    }

    public function show(string $id): array
    {
        return $this->documents->get(AuthUser::current(), $id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['employee' => 'required|string|max:40', 'kind' => ['required', Rule::in(array_keys(Document::KINDS))], 'private' => 'nullable|boolean'] + self::fields() + self::file());

        return response()->json($this->documents->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        $rules = ['kind' => ['sometimes', Rule::in(array_keys(Document::KINDS))], 'private' => 'sometimes|boolean'];
        foreach (self::fields() as $field => $rule) {
            $rules[$field] = "sometimes|{$rule}";
        }

        return $this->documents->update(AuthUser::current(), $id, $request->validate($rules));
    }

    public function renew(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['expiryDate' => 'required|date'] + self::fields() + self::file());

        return response()->json($this->documents->renew(AuthUser::current(), $id, $data), 201);
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json($this->documents->void(AuthUser::current(), $id, $data['reason']), 201);
    }

    public function attach(Request $request, string $id): JsonResponse
    {
        return response()->json($this->documents->attach(AuthUser::current(), $id, $request->validate(FilesService::rules())), 201);
    }

    public function removeFile(string $id, string $fileId): array
    {
        return $this->documents->removeFile(AuthUser::current(), $id, $fileId);
    }

    private static function fields(): array
    {
        return ['title' => 'nullable|string|max:200', 'number' => 'nullable|string|max:60', 'issueDate' => 'nullable|date', 'expiryDate' => 'nullable|date', 'notes' => 'nullable|string|max:2000'];
    }

    /** An optional scan sent with the document itself. */
    private static function file(): array
    {
        $rules = ['file' => 'nullable|array'];
        foreach (FilesService::rules('file.') as $key => $rule) {
            $rules[$key] = str_replace('required|', 'required_with:file|', $rule);
        }

        return $rules;
    }
}
