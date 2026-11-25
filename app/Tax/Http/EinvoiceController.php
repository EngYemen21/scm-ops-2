<?php

namespace App\Tax\Http;

use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Tax\Services\EinvoiceHistoryService;
use App\Tax\Services\EinvoiceService;
use App\Tax\Services\EinvoiceSubmitter;
use App\Tax\Services\EinvoiceUnitsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * E-invoicing: the register of e-invoices, the queue to the authority, the e-invoicing units and their onboarding,
 * and the history that came from Odoo. No answer of this controller ever carries a key, a certificate request, a
 * certificate or a secret of a unit (Shape::unit says "held: yes / no").
 */
class EinvoiceController extends Controller
{
    public function __construct(
        private readonly EinvoiceService $register,
        private readonly EinvoiceSubmitter $queue,
        private readonly EinvoiceUnitsService $units,
        private readonly EinvoiceHistoryService $history,
    ) {}

    // ───────────── the register ─────────────

    public function summary(): array
    {
        return $this->register->summary();
    }

    public function index(Request $request): array
    {
        return $this->register->list(Paging::from($request), $request->only(['status', 'profile', 'docKind', 'from', 'to', 'customer', 'unit', 'current', 'signed', 'issues']));
    }

    public function pending(Request $request): array
    {
        return $this->register->pending(Paging::from($request, 25));
    }

    public function chain(Request $request): array
    {
        return $this->register->chain($request->query('unit') ?: null);
    }

    public function show(string $id): array
    {
        return $this->register->get($id);
    }

    /** The XML as a file: the one made here, or (`?cleared=1`) the copy the authority stamped and returned. */
    public function xml(Request $request, string $id): Response
    {
        $file = $this->register->xml($id, filter_var($request->query('cleared', false), FILTER_VALIDATE_BOOLEAN));

        return response($file['xml'], 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$file['name'].'"']);
    }

    public function make(Request $request): JsonResponse
    {
        $data = $request->validate(['invoice' => 'required|string|max:60']);

        return response()->json($this->register->makeFor(AuthUser::current(), $data['invoice']), 201);
    }

    public function makePending(Request $request): array
    {
        $data = $request->validate(['limit' => 'nullable|integer|min:1|max:500']);

        return $this->register->makePending(AuthUser::current(), (int) ($data['limit'] ?? 200));
    }

    public function remake(Request $request, string $id): array
    {
        return $this->register->remake(AuthUser::current(), $id, self::reason($request));
    }

    public function queue(string $id): array
    {
        return $this->queue->queue(AuthUser::current(), $id);
    }

    public function unqueue(Request $request, string $id): array
    {
        return $this->queue->unqueue(AuthUser::current(), $id, self::reason($request));
    }

    public function sendNow(string $id): array
    {
        return $this->queue->sendNow(AuthUser::current(), $id);
    }

    public function queueBacklog(Request $request): array
    {
        $data = $request->validate(['from' => 'nullable|date_format:Y-m-d']);

        return $this->queue->queueBacklog(AuthUser::current(), $data['from'] ?? null);
    }

    /** What the scheduler does, on demand: make what is missing, then send what is due (a unit that is not onboarded sends nothing). */
    public function run(): array
    {
        return ['made' => $this->register->makePending(AuthUser::current()), 'sent' => $this->queue->run()];
    }

    // ───────────── units ─────────────

    public function units(): array
    {
        return ['items' => $this->units->list()];
    }

    public function unit(string $id): array
    {
        return $this->units->show($id);
    }

    public function storeUnit(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120'] + self::unitRules());

        return response()->json($this->units->create(AuthUser::current(), $data), 201);
    }

    public function updateUnit(Request $request, string $id): array
    {
        $data = $request->validate(['name' => 'sometimes|string|max:120', 'sending' => 'sometimes|boolean', 'isDefault' => 'sometimes|boolean'] + self::unitRules());
        foreach (['sending', 'isDefault'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = filter_var($data[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $this->units->update(AuthUser::current(), $id, $data);
    }

    public function makeRequest(string $id): array
    {
        return $this->units->makeRequest(AuthUser::current(), $id);
    }

    public function compliance(Request $request, string $id): array
    {
        return $this->units->requestCompliance(AuthUser::current(), $id, self::otp($request));
    }

    public function checks(string $id): array
    {
        return $this->units->runComplianceChecks(AuthUser::current(), $id);
    }

    public function production(string $id): array
    {
        return $this->units->requestProduction(AuthUser::current(), $id);
    }

    public function renew(Request $request, string $id): array
    {
        return $this->units->renew(AuthUser::current(), $id, self::otp($request));
    }

    public function retire(Request $request, string $id): array
    {
        return $this->units->retire(AuthUser::current(), $id, self::reason($request));
    }

    // ───────────── the history that came from Odoo ─────────────

    public function historyIndex(Request $request): array
    {
        return $this->history->list(Paging::from($request), $request->only(['status', 'moveType', 'from', 'to', 'customer']));
    }

    public function historyShow(string $id): array
    {
        return $this->history->get($id);
    }

    // ───────────── helpers ─────────────

    private static function unitRules(): array
    {
        return ['serial' => 'sometimes|nullable|string|max:190', 'environment' => 'sometimes|string|in:sandbox,simulation,production', 'invoiceTypes' => 'sometimes|string|size:4',
            'branchName' => 'sometimes|nullable|string|max:128', 'location' => 'sometimes|nullable|string|max:128', 'industry' => 'sometimes|nullable|string|max:128'];
    }

    /** The service checks it (six digits) and says so in both languages; it is never stored nor logged. */
    private static function otp(Request $request): string
    {
        return (string) ($request->validate(['otp' => 'nullable|string|max:10'])['otp'] ?? '');
    }

    private static function reason(Request $request): string
    {
        return (string) ($request->validate(['reason' => 'nullable|string|max:1000'])['reason'] ?? '');
    }
}
