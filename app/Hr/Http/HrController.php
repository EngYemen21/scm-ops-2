<?php

namespace App\Hr\Http;

use App\Hr\Services\AlertsService;
use App\Hr\Services\CategoriesService;
use App\Hr\Services\FilesService;
use App\Hr\Services\SummaryService;
use App\Http\Controllers\Controller;
use App\Services\Integrations\FileStore;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the whole section shares: the summary, the lookups of its forms, expense categories, what is due, and its files. */
class HrController extends Controller
{
    public function __construct(
        private readonly SummaryService $summary,
        private readonly CategoriesService $categories,
        private readonly AlertsService $alerts,
        private readonly FilesService $files,
    ) {}

    public function summary(): array
    {
        return $this->summary->summary(AuthUser::current());
    }

    public function lookups(): array
    {
        return $this->summary->lookups(AuthUser::current());
    }

    // ── expense categories
    public function categories(Request $request): array
    {
        return $this->categories->list(AuthUser::current(), $request->boolean('archived'));
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'min:2', 'max:40', 'regex:/^[A-Za-z0-9_.\-]+$/'], 'nameAr' => 'required|string|min:2|max:200', 'nameEn' => 'nullable|string|max:200',
            'accountCode' => 'nullable|string|max:20', 'vatPct' => 'nullable|numeric|min:0|max:100']);

        return response()->json($this->categories->create(AuthUser::current(), $data), 201);
    }

    public function updateCategory(Request $request, string $id): array
    {
        $data = $request->validate(['nameAr' => 'sometimes|string|min:2|max:200', 'nameEn' => 'sometimes|nullable|string|max:200', 'accountCode' => 'sometimes|nullable|string|max:20',
            'vatPct' => 'sometimes|nullable|numeric|min:0|max:100', 'active' => 'sometimes|boolean']);

        return $this->categories->update(AuthUser::current(), $id, $data);
    }

    // ── what is due
    public function alerts(Request $request): array
    {
        return $this->alerts->list(AuthUser::current(), Paging::from($request, 25), $request->only(['stage', 'category', 'employee', 'department', 'order']));
    }

    public function refreshAlerts(): JsonResponse
    {
        return response()->json($this->alerts->refresh() + ['counts' => $this->alerts->counts(AuthUser::current())], 201);
    }

    /**
     * A file of the section for the person asking (the service checks the permission of what the file hangs on).
     * Default: the bytes, PDF and pictures inline. `?as=data` answers JSON with a data URL — what the client uses,
     * because an `<img>` or a link cannot send the bearer token.
     */
    public function file(Request $request, string $id)
    {
        $file = $this->files->read(AuthUser::current(), $id);
        if ($request->query('as') === 'data') {
            return ['id' => $id, 'fileName' => $file['fileName'], 'mime' => $file['mime'], 'size' => $file['size'], 'dataUrl' => "data:{$file['mime']};base64,".base64_encode($file['bytes'])];
        }
        $inline = in_array($file['mime'], FileStore::INLINE_MIMES, true);

        return response($file['bytes'], 200, [
            'Content-Type' => $inline ? $file['mime'] : 'application/octet-stream',
            'Content-Length' => (string) $file['size'],
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($file['fileName']),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', // a person's paper is not left in a shared cache
        ]);
    }
}
