<?php

namespace App\Portal\Http;

use App\Http\Controllers\Controller;
use App\Portal\Models\StatementLine;
use App\Portal\Services\StatementsService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer statements: Odoo's history and the statements issued here from the OPS receivables. Thin: validate, call a
 * service, return.
 */
class StatementsController extends Controller
{
    public function __construct(private readonly StatementsService $statements) {}

    public function index(Request $request): array
    {
        $filters = PortalRules::filters($request, [
            'customer' => 'nullable|string|max:160', 'branch' => 'nullable|string|max:40', 'origin' => 'nullable|in:ops,odoo', 'status' => 'nullable|string|max:40',
            'from' => ['nullable', PortalRules::DAY], 'to' => ['nullable', PortalRules::DAY],
        ]);

        return $this->statements->list(Paging::from($request, 25), $filters);
    }

    public function show(string $id): array
    {
        return $this->statements->get($id);
    }

    public function lines(Request $request, string $id): array
    {
        $filters = PortalRules::filters($request, ['kind' => 'nullable|in:'.implode(',', StatementLine::KINDS), 'branch' => 'nullable|string|max:40']);

        return $this->statements->lines($id, Paging::from($request, 100), $filters);
    }

    /** The statement of a customer (or one branch) for a period, worked out now from the OPS receivables — nothing is stored. */
    public function preview(Request $request): array
    {
        return $this->statements->preview($request->validate(self::scopeRules()));
    }

    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate(self::scopeRules() + ['note' => 'nullable|string|max:2000']);

        return response()->json($this->statements->issue(AuthUser::current(), $data), 201);
    }

    public function void(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:500']);

        return $this->statements->void(AuthUser::current(), $id, $data['reason']);
    }

    /** The statement's lines as a CSV file (UTF-8 with a BOM, so a spreadsheet opens the Arabic as it is). */
    public function export(string $id): Response
    {
        $file = $this->statements->export($id);

        return response($file['body'], 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$file['name'].'"', 'Cache-Control' => 'private, no-store']);
    }

    private static function scopeRules(): array
    {
        return ['customer' => 'required|string|max:160', 'branch' => 'nullable|string|max:40', 'from' => ['required', PortalRules::DAY], 'to' => ['required', PortalRules::DAY]];
    }
}
