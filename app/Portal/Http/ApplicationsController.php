<?php

namespace App\Portal\Http;

use App\Http\Controllers\Controller;
use App\Portal\Models\Application;
use App\Portal\Models\ApplicationDocument;
use App\Portal\Services\ApplicationDocuments;
use App\Portal\Services\ApplicationsService;
use App\Portal\Services\OnboardingApproval;
use App\Services\Integrations\FileStore;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Onboarding applications: the list, one application with its papers and timeline, and what happens to it — edit,
 * submit, ask for a correction, approve (the company becomes a customer), refuse, expire, reopen, note, papers.
 * Thin: validate (camelCase keys, exactly as the client sends them), call a service, return.
 */
class ApplicationsController extends Controller
{
    public function __construct(
        private readonly ApplicationsService $applications,
        private readonly OnboardingApproval $approval,
        private readonly ApplicationDocuments $documents,
    ) {}

    public function index(Request $request): array
    {
        $filters = PortalRules::filters($request, [
            'status' => 'nullable|string|max:120', 'origin' => 'nullable|in:ops,odoo', 'customer' => 'nullable|string|max:160', 'from' => ['nullable', PortalRules::DAY], 'to' => ['nullable', PortalRules::DAY],
        ]);

        return $this->applications->list(AuthUser::current(), Paging::from($request, 25), $filters);
    }

    public function show(string $id): array
    {
        return $this->applications->get(AuthUser::current(), $id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['companyNameAr' => 'required|string|min:2|max:250', 'submit' => 'sometimes|boolean'] + self::fieldRules());
        $data['submit'] = filter_var($data['submit'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return response()->json($this->applications->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        return $this->applications->update(AuthUser::current(), $id, $request->validate(['companyNameAr' => 'sometimes|nullable|string|max:250'] + self::fieldRules()));
    }

    public function submit(string $id): array
    {
        return $this->applications->submit(AuthUser::current(), $id);
    }

    public function requestCorrection(Request $request, string $id): array
    {
        $data = $request->validate(['note' => 'required|string|min:3|max:2000']);

        return $this->applications->requestCorrection(AuthUser::current(), $id, $data['note']);
    }

    public function approve(Request $request, string $id): array
    {
        $data = $request->validate([
            'customerId' => 'nullable|string|max:160', 'confirmNew' => 'sometimes|boolean', 'zone' => 'nullable|string|max:190', 'contact' => 'nullable|string|max:250', 'address' => 'nullable|string|max:500',
            'terms' => 'nullable|string|max:120', 'creditLimit' => 'nullable|numeric|min:0|max:99999999999', 'priceList' => 'nullable|string|max:20', 'note' => 'nullable|string|max:1000',
        ]);
        $data['confirmNew'] = filter_var($data['confirmNew'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $user = AuthUser::current();
        $done = $this->approval->approve($user, $id, $data);

        return $this->applications->get($user, $done['id']) + ['approval' => ['by' => $done['by'], 'customer' => $done['customer']]];
    }

    public function reject(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:2000']);

        return $this->applications->reject(AuthUser::current(), $id, $data['reason']);
    }

    public function expire(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->applications->expire(AuthUser::current(), $id, $data['reason'] ?? null);
    }

    public function reopen(Request $request, string $id): array
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);

        return $this->applications->reopen(AuthUser::current(), $id, $data['note'] ?? null);
    }

    public function note(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['text' => 'required|string|min:1|max:5000']);

        return response()->json($this->applications->note(AuthUser::current(), $id, $data['text']), 201);
    }

    // ── the papers of the application
    public function attach(Request $request, string $id): JsonResponse
    {
        // base64 of a 5 MB file is ~6.7 MB of text (+ an optional data-URL prefix)
        $maxBase64 = (int) ceil(ApplicationDocuments::MAX_BYTES / 3) * 4 + 128;
        $data = $request->validate([
            'kind' => 'required|string|in:'.implode(',', ApplicationDocument::KINDS), 'fileName' => 'required|string|min:1|max:200', 'mime' => 'nullable|string|max:120',
            'base64' => "required|string|min:1|max:{$maxBase64}",
        ]);
        $user = AuthUser::current();

        return response()->json($this->applications->get($user, $this->documents->add($user, $id, $data)), 201);
    }

    public function detach(Request $request, string $id, string $document): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);
        $user = AuthUser::current();

        return $this->applications->get($user, $this->documents->remove($user, $id, $document, $data['reason'] ?? null));
    }

    /** The bytes of one paper: images and PDF inline, anything else as a download. Never cached by a shared cache. */
    public function file(string $id, string $document): Response
    {
        $file = $this->documents->open(AuthUser::current(), $id, $document);
        $inline = in_array($file['mime'], FileStore::INLINE_MIMES, true);
        $headers = [
            'Content-Type' => $inline ? $file['mime'] : 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($file['fileName']),
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ];
        if (isset($file['disk'])) {
            // a file of the Odoo archive can be large: it is streamed from its disk, never loaded whole
            return response()->stream(function () use ($file) {
                $stream = Storage::disk($file['disk'])->readStream($file['path']);
                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            }, 200, $headers);
        }

        return response($file['bytes'], 200, $headers + ['Content-Length' => (string) strlen($file['bytes'])]);
    }

    /** What the form of an application may carry besides the company's Arabic name. Every key is optional; in an edit an empty value clears. */
    private static function fieldRules(): array
    {
        return [
            'companyNameEn' => PortalRules::text(250), 'legalEntityType' => 'sometimes|nullable|in:'.implode(',', Application::LEGAL_TYPES), 'language' => 'sometimes|nullable|in:ar,en',
            'cr' => PortalRules::text(60), 'vatNo' => PortalRules::text(60), 'unifiedNo' => PortalRules::text(60), 'ownerName' => PortalRules::text(190), 'ownerNationalId' => PortalRules::text(60),
            'managerName' => PortalRules::text(190), 'email' => 'sometimes|nullable|email|max:190', 'mobile' => PortalRules::text(40), 'street' => PortalRules::text(250),
            'buildingNo' => PortalRules::text(20), 'additionalNo' => PortalRules::text(20), 'district' => PortalRules::text(120), 'city' => PortalRules::text(120), 'region' => PortalRules::text(120),
            'postalCode' => PortalRules::text(20), 'termsAccepted' => 'sometimes|nullable|boolean', 'termsVersion' => PortalRules::text(120),
            'contacts' => 'sometimes|nullable|array|max:20', 'contacts.*.role' => 'nullable|string|in:'.implode(',', Application::CONTACT_ROLES), 'contacts.*.name' => 'required|string|min:1|max:190',
            'contacts.*.mobile' => 'nullable|string|max:40', 'contacts.*.email' => 'nullable|email|max:190',
        ];
    }
}
