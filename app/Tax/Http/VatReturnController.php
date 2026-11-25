<?php

namespace App\Tax\Http;

use App\Http\Controllers\Controller;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Tax\Services\VatReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The VAT returns: the list, one return with its form and its reconciliation with the ledger, the documents behind a
 * figure, and the way of a return from draft to locked. Thin: validation here, every rule in VatReturnService.
 */
class VatReturnController extends Controller
{
    public function __construct(private readonly VatReturnService $returns) {}

    public function index(Request $request): array
    {
        return $this->returns->list(Paging::from($request, 24), $request->only(['status', 'year', 'frequency']));
    }

    public function show(string $id): array
    {
        return $this->returns->get($id, AuthUser::current());
    }

    public function entries(Request $request, string $id): array
    {
        return $this->returns->entries($id, Paging::from($request, 50), $request->only(['box', 'column', 'side', 'docKind', 'classified', 'mismatch', 'late']));
    }

    public function export(Request $request, string $id): StreamedResponse
    {
        if (! AuthUser::current()->can('report.export')) {
            throw AppError::forbidden('FORBIDDEN', 'التصدير يحتاج صلاحية تصدير التقارير', 'Exporting needs the report export permission');
        }
        $csv = $this->returns->export($id, $request->only(['box', 'column', 'side', 'docKind', 'classified', 'mismatch', 'late']));
        $name = 'vat-return-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $id).'.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['frequency' => 'nullable|string|in:monthly,quarterly', 'year' => 'required|integer|min:2018|max:2100', 'period' => 'required|integer|min:1|max:12', 'notes' => 'nullable|string|max:2000']);

        return response()->json($this->returns->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate(['creditCarried' => 'sometimes|nullable', 'notes' => 'sometimes|nullable|string|max:2000']);

        return $this->returns->update(AuthUser::current(), $id, $data);
    }

    public function refresh(string $id): array
    {
        return $this->returns->refresh(AuthUser::current(), $id);
    }

    public function addAdjustment(Request $request, string $id): array
    {
        $data = $request->validate(['box' => 'required|integer|min:1|max:16', 'base' => 'nullable', 'vat' => 'nullable', 'reason' => 'required|string|max:1000', 'ledgerLineId' => 'nullable|string|max:60']);

        return $this->returns->addAdjustment(AuthUser::current(), $id, $data);
    }

    public function voidAdjustment(Request $request, string $id, string $adjustment): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        return $this->returns->voidAdjustment(AuthUser::current(), $id, $adjustment, (string) ($data['reason'] ?? ''));
    }

    public function explain(Request $request, string $id): array
    {
        $data = $request->validate(['journalLineId' => 'required|string|max:60', 'treatment' => 'required|string|max:20', 'reason' => 'required|string|max:1000']);

        return $this->returns->explain(AuthUser::current(), $id, $data);
    }

    public function withdrawExplanation(string $id, string $note): array
    {
        return $this->returns->withdrawExplanation(AuthUser::current(), $id, $note);
    }

    public function review(Request $request, string $id): array
    {
        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        return $this->returns->review(AuthUser::current(), $id, $data['note'] ?? null);
    }

    public function reopen(Request $request, string $id): array
    {
        return $this->returns->reopen(AuthUser::current(), $id, self::reason($request));
    }

    public function file(Request $request, string $id): array
    {
        $data = $request->validate(['reference' => 'nullable|string|max:80', 'filedOn' => 'nullable|date_format:Y-m-d']);

        return $this->returns->file(AuthUser::current(), $id, $data);
    }

    public function unfile(Request $request, string $id): array
    {
        return $this->returns->unfile(AuthUser::current(), $id, self::reason($request));
    }

    public function lock(string $id): array
    {
        return $this->returns->lock(AuthUser::current(), $id);
    }

    public function cancel(Request $request, string $id): array
    {
        return $this->returns->cancel(AuthUser::current(), $id, self::reason($request));
    }

    /** A reason is checked by the service (REASON_REQUIRED, 5 characters): an empty body reaches it as "". */
    private static function reason(Request $request): string
    {
        return (string) ($request->validate(['reason' => 'nullable|string|max:1000'])['reason'] ?? '');
    }
}
