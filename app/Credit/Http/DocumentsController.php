<?php

namespace App\Credit\Http;

use App\Credit\Models\CreditRequest;
use App\Credit\Services\DocumentsService;
use App\Credit\Services\RequestsService;
use App\Credit\Services\SignatureService;
use App\Credit\Support\Slots;
use App\Http\Controllers\Controller;
use App\Services\Integrations\FileStore;
use App\Support\AuthUser;
use Illuminate\Http\Request;

/**
 * The papers of a request (the nine slots) and the signature of its agreement (the envelopes). Who may do what is
 * the services' (DocumentsService, SignatureService); a paper is read only through `file()`, which asks who may read it
 * and writes the read to the audit trail.
 */
class DocumentsController extends Controller
{
    public function __construct(private readonly DocumentsService $documents, private readonly SignatureService $signature, private readonly RequestsService $requests) {}

    public function add(Request $request, string $id): array
    {
        $data = $request->validate(['slot' => 'required|string|in:'.implode(',', Slots::keys())] + DocumentsService::rules());
        $this->visible($id);

        return $this->answer($this->documents->add(AuthUser::current(), $id, $data['slot'], ['fileName' => $data['fileName'], 'base64' => $data['base64']]));
    }

    public function remove(Request $request, string $id, string $file): array
    {
        $this->visible($id);

        return $this->answer($this->documents->remove(AuthUser::current(), $id, $file, $request->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? null));
    }

    /** The paper itself. `?as=data` answers JSON with a data URL — what the client uses (a link cannot send the bearer token). */
    public function file(Request $request, string $id, string $file)
    {
        $this->visible($id);
        $out = $this->documents->open(AuthUser::current(), $id, $file);
        if ($request->query('as') === 'data') {
            return ['id' => $file, 'fileName' => $out['fileName'], 'mime' => $out['mime'], 'size' => $out['size'], 'dataUrl' => "data:{$out['mime']};base64,".base64_encode($out['bytes'])];
        }
        $inline = in_array($out['mime'], FileStore::INLINE_MIMES, true);

        return response($out['bytes'], 200, [
            'Content-Type' => $inline ? $out['mime'] : 'application/octet-stream',
            'Content-Length' => (string) $out['size'],
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($out['fileName']),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', // a person's paper is not left in a shared cache
        ]);
    }

    public function issue(Request $request, string $id): array
    {
        $data = $request->validate(['signerName' => 'nullable|string|max:190', 'signerEmail' => 'nullable|email|max:190', 'signerMobile' => 'nullable|string|max:40']);
        $this->visible($id);

        return $this->answer($this->signature->issue(AuthUser::current(), $id, $data));
    }

    public function delivered(string $id, string $envelope): array
    {
        $this->visible($id);

        return $this->answer($this->signature->delivered(AuthUser::current(), $id, $envelope));
    }

    public function declined(Request $request, string $id, string $envelope): array
    {
        $this->visible($id);

        return $this->answer($this->signature->declined(AuthUser::current(), $id, $envelope, $this->reason($request)));
    }

    public function void(Request $request, string $id, string $envelope): array
    {
        $this->visible($id);

        return $this->answer($this->signature->void(AuthUser::current(), $id, $envelope, $this->reason($request)));
    }

    public function signed(Request $request, string $id, string $envelope): array
    {
        $data = $request->validate(DocumentsService::rules());
        $this->visible($id);

        return $this->answer($this->signature->signed(AuthUser::current(), $id, $envelope, ['fileName' => $data['fileName'], 'base64' => $data['base64']]));
    }

    public function refresh(string $id, string $envelope): array
    {
        $this->visible($id);

        return $this->answer($this->signature->refresh(AuthUser::current(), $id, $envelope));
    }

    private function visible(string $id): void
    {
        $this->requests->assertVisible(AuthUser::current(), CreditRequest::locate($id));
    }

    private function answer(string $id): array
    {
        return $this->requests->get(AuthUser::current(), $id);
    }

    private function reason(Request $request): ?string
    {
        return $request->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? null;
    }
}
