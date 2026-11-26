<?php

namespace App\Workspace\Http;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Integrations\FileStore;
use App\Support\AuthUser;
use App\Workspace\Services\DocumentsService;
use App\Workspace\Services\FilesService;
use App\Workspace\Services\TasksService;
use App\Workspace\Services\TicketsService;
use App\Workspace\Support\Owned;
use App\Workspace\Support\People;
use App\Workspace\Support\RecordLinks;
use App\Workspace\Support\Ws;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the three tools of the page share: the counters at its top, the lists its forms pick from, the lookup of an OPS
 * customer or record, and the one door every file of the section leaves through.
 */
class WorkspaceController extends Controller
{
    public function __construct(
        private readonly TicketsService $tickets,
        private readonly TasksService $tasks,
        private readonly DocumentsService $documents,
        private readonly FilesService $files,
    ) {}

    public function summary(): array
    {
        $user = AuthUser::current();

        return [
            'follows' => Owned::following(),
            'me' => ['id' => $user->id, 'username' => $user->username, 'manages' => ['tickets' => $user->can('ticket.manage'), 'projects' => $user->can('project.manage'), 'documents' => $user->can('doc.manage')]],
            'tickets' => $this->tickets->summary($user), 'tasks' => $this->tasks->summary($user), 'documents' => $this->documents->summary($user),
        ];
    }

    public function lookups(): array
    {
        return ['follows' => Owned::following(), 'users' => People::users(), 'roles' => People::roles(), 'priorities' => Ws::priorities(), 'recordTypes' => RecordLinks::types(), 'files' => FilesService::limits()];
    }

    /** The customers a ticket may be raised for: the first twenty that match, active ones only. */
    public function customers(Request $request): array
    {
        $data = $request->validate(['q' => 'nullable|string|max:120']);
        $query = Customer::query()->where('active', true);
        Ws::search($query, ['code', 'name_ar', 'name_en', 'city', 'contact'], $data['q'] ?? null);

        return $query->orderBy('name_ar')->orderBy('id')->limit(20)->get(['id', 'code', 'name_ar', 'name_en', 'city'])
            ->map(fn (Customer $c) => ['id' => $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en, 'city' => $c->city])->all();
    }

    /** Does a record of this kind carry this number? What a form asks while a link is being typed. */
    public function record(Request $request): array
    {
        $data = $request->validate(['type' => 'required|string|max:30', 'number' => 'required|string|max:80']);

        return RecordLinks::resolve($data['type'], $data['number']);
    }

    /**
     * A file of the section for somebody who may see what it hangs on. Default: the bytes (pictures and PDF inline,
     * everything else as a download). `?as=data` answers JSON with a data URL — for a preview inside the page.
     */
    public function file(Request $request, string $id): Response|array
    {
        $file = $this->files->open(AuthUser::current(), $id);
        $inline = in_array($file['mime'], FileStore::INLINE_MIMES, true);
        if ($request->query('as') === 'data') {
            $bytes = $file['bytes'] ?? (string) Storage::disk($file['disk'])->get($file['path']);

            return ['id' => $id, 'fileName' => $file['fileName'], 'mime' => $file['mime'], 'size' => strlen($bytes), 'dataUrl' => "data:{$file['mime']};base64,".base64_encode($bytes)];
        }
        $headers = [
            'Content-Type' => $inline ? $file['mime'] : 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($file['fileName']),
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ];
        if (isset($file['disk'])) {
            // a file of the Odoo backup can be large: it is streamed from its disk, never loaded whole
            return response()->stream(function () use ($file) {
                $stream = Storage::disk($file['disk'])->readStream($file['path']);
                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            }, 200, $headers);
        }

        return response($file['bytes'], 200, $headers + ['Content-Length' => (string) $file['size']]);
    }

    public function removeFile(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);
        $this->files->remove(AuthUser::current(), $id, $data['reason'] ?? null);

        return ['ok' => true];
    }
}
