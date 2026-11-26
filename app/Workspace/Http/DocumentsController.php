<?php

namespace App\Workspace\Http;

use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Workspace\Models\Document;
use App\Workspace\Models\Folder;
use App\Workspace\Services\DocumentsService;
use App\Workspace\Services\FilesService;
use App\Workspace\Services\FoldersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Documents: the tree of folders, the list of a folder (or of everything the caller may read), one document with its
 * versions, and what is done to it. Folders and tags are kept by the documents manager (`doc.manage` on those routes);
 * what a caller may do to a document is decided by the service from the access of its folder.
 */
class DocumentsController extends Controller
{
    public function __construct(private readonly DocumentsService $documents, private readonly FoldersService $folders) {}

    public function lookups(): array
    {
        return $this->documents->lookups();
    }

    // ── folders

    public function folders(Request $request): array
    {
        $data = $request->validate(['archived' => 'nullable|boolean']);

        return $this->folders->tree(AuthUser::current(), filter_var($data['archived'] ?? false, FILTER_VALIDATE_BOOLEAN));
    }

    public function storeFolder(Request $request): JsonResponse
    {
        return response()->json($this->folders->create(AuthUser::current(), $request->validate(['name' => 'required|string|min:1|max:190'] + self::folderRules())), 201);
    }

    public function updateFolder(Request $request, string $id): array
    {
        return $this->folders->update(AuthUser::current(), $id, $request->validate(['name' => 'sometimes|string|min:1|max:190'] + self::folderRules()));
    }

    public function archiveFolder(string $id): array
    {
        return $this->folders->archive(AuthUser::current(), $id);
    }

    public function restoreFolder(string $id): array
    {
        return $this->folders->restore(AuthUser::current(), $id);
    }

    // ── documents

    public function index(Request $request): array
    {
        $rules = [
            'folder' => 'nullable|string|max:40', 'deep' => 'nullable|boolean', 'state' => ['nullable', Rule::in(DocumentsService::STATES)], 'tag' => 'nullable|string|max:40', 'owner' => 'nullable|string|max:200',
            'kind' => ['nullable', Rule::in(Document::KINDS)], 'type' => ['nullable', Rule::in(DocumentsService::TYPES)], 'refType' => 'nullable|string|max:30', 'refNumber' => 'nullable|string|max:80',
            'origin' => 'nullable|in:ops,odoo',
        ];
        $filters = array_intersect_key($request->validate($rules + TicketsController::listRules()), $rules);

        return $this->documents->list(AuthUser::current(), Paging::from($request, 25), $filters);
    }

    public function show(string $id): array
    {
        return $this->documents->get(AuthUser::current(), $id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['folderId' => 'required|string|max:40', 'name' => 'nullable|string|max:250', 'url' => 'nullable|string|max:2000', 'file' => 'nullable|array']
            + FilesService::rules('file.', 'file') + self::fieldRules());

        return response()->json($this->documents->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        return $this->documents->update(AuthUser::current(), $id, $request->validate(['name' => 'sometimes|string|min:1|max:250', 'url' => 'sometimes|nullable|string|max:2000'] + self::fieldRules()));
    }

    public function move(Request $request, string $id): array
    {
        $data = $request->validate(['folderId' => 'required|string|max:40']);

        return $this->documents->move(AuthUser::current(), $id, $data['folderId']);
    }

    public function version(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['file' => 'required|array', 'note' => 'nullable|string|max:500'] + FilesService::rules('file.'));

        return response()->json($this->documents->version(AuthUser::current(), $id, $data['file'], $data['note'] ?? null), 201);
    }

    public function archive(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        return $this->documents->archive(AuthUser::current(), $id, $data['reason'] ?? null);
    }

    public function restore(string $id): array
    {
        return $this->documents->restore(AuthUser::current(), $id);
    }

    // ── tags (doc.manage)

    public function storeTag(Request $request): JsonResponse
    {
        return response()->json($this->documents->storeTag(AuthUser::current(), $request->validate(['name' => 'required|string|min:1|max:120'] + self::tagRules())), 201);
    }

    public function updateTag(Request $request, string $id): array
    {
        return $this->documents->updateTag(AuthUser::current(), $id, $request->validate(['name' => 'sometimes|string|min:1|max:120', 'active' => 'sometimes|nullable|boolean'] + self::tagRules()));
    }

    // ── rules

    private static function fieldRules(): array
    {
        return ['description' => 'sometimes|nullable|string|max:20000', 'tagIds' => 'sometimes|array|max:30', 'tagIds.*' => 'string|max:40',
            'refType' => 'sometimes|nullable|string|max:30', 'refNumber' => 'sometimes|nullable|string|max:80'];
    }

    private static function folderRules(): array
    {
        return [
            'parentId' => 'sometimes|nullable|string|max:40', 'description' => 'sometimes|nullable|string|max:2000', 'sequence' => 'sometimes|nullable|integer|min:0|max:100000',
            'readScope' => ['sometimes', 'nullable', Rule::in(Folder::SCOPES)], 'readRoles' => 'sometimes|nullable|array|max:50', 'readRoles.*' => 'string|max:60',
            'writeScope' => ['sometimes', 'nullable', Rule::in(Folder::SCOPES)], 'writeRoles' => 'sometimes|nullable|array|max:50', 'writeRoles.*' => 'string|max:60',
            'ownRead' => 'sometimes|nullable|boolean', 'ownWrite' => 'sometimes|nullable|boolean',
        ];
    }

    private static function tagRules(): array
    {
        return ['group' => 'sometimes|nullable|string|max:120', 'color' => 'sometimes|nullable|integer|min:0|max:11', 'folderId' => 'sometimes|nullable|string|max:40'];
    }
}
