<?php

namespace App\Crm\Http;

use App\Crm\Services\ConversionService;
use App\Crm\Services\CrmConfigService;
use App\Crm\Services\CrmReportService;
use App\Crm\Services\LeadsService;
use App\Http\Controllers\Api\Sales\SalesRules;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Leads / opportunities: the list and the board, one lead with its timeline, and what happens to it — edit, move,
 * won, lost, reopen, note, customer, quotation. Thin: validate (camelCase keys, exactly as the client sends them),
 * call a service, return.
 */
class LeadsController extends Controller
{
    private const DAY = 'date_format:Y-m-d';

    public function __construct(
        private readonly LeadsService $leads,
        private readonly ConversionService $conversion,
        private readonly CrmConfigService $config,
        private readonly CrmReportService $report,
    ) {}

    public function lookups(): array
    {
        return $this->config->lookups();
    }

    public function index(Request $request): array
    {
        return $this->leads->list(AuthUser::current(), Paging::from($request, 25), $this->filters($request));
    }

    public function board(Request $request): array
    {
        $filters = $this->filters($request);
        $q = trim((string) $request->query('q', ''));

        return $this->leads->board(AuthUser::current(), $filters, $q === '' ? null : $q, min(50, max(1, (int) $request->query('per', 20))));
    }

    public function show(string $id): array
    {
        return $this->leads->get($id);
    }

    public function funnel(Request $request): array
    {
        $data = $request->validate(['team' => 'nullable|string|max:40', 'owner' => 'nullable|string|max:200', 'from' => ['nullable', self::DAY], 'to' => ['nullable', self::DAY]]);

        return $this->report->funnel(AuthUser::current(), $data);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|min:1|max:250', 'stageId' => 'nullable|string|max:40'] + self::fieldRules());

        return response()->json($this->leads->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        return $this->leads->update(AuthUser::current(), $id, $request->validate(['name' => 'sometimes|string|min:1|max:250'] + self::fieldRules()));
    }

    public function move(Request $request, string $id): array
    {
        $data = $request->validate(['stageId' => 'required|string|max:40', 'note' => 'nullable|string|max:1000']);

        return $this->leads->move(AuthUser::current(), $id, $data['stageId'], $data['note'] ?? null);
    }

    public function win(Request $request, string $id): array
    {
        $data = $request->validate(['stageId' => 'nullable|string|max:40', 'note' => 'nullable|string|max:1000']);

        return $this->leads->win(AuthUser::current(), $id, $data['stageId'] ?? null, $data['note'] ?? null);
    }

    public function lose(Request $request, string $id): array
    {
        $data = $request->validate(['reasonId' => 'required|string|max:40', 'note' => 'nullable|string|max:2000']);

        return $this->leads->lose(AuthUser::current(), $id, $data['reasonId'], $data['note'] ?? null);
    }

    public function reopen(Request $request, string $id): array
    {
        $data = $request->validate(['stageId' => 'nullable|string|max:40', 'note' => 'nullable|string|max:1000']);

        return $this->leads->reopen(AuthUser::current(), $id, $data['stageId'] ?? null, $data['note'] ?? null);
    }

    public function note(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['text' => 'required|string|min:1|max:5000']);

        return response()->json($this->leads->note(AuthUser::current(), $id, $data['text']), 201);
    }

    // ── from a won opportunity to a customer
    public function customer(Request $request, string $id): array
    {
        $data = $request->validate(['vatNo' => 'nullable|string|max:60']);

        return $this->conversion->preview($id, $data['vatNo'] ?? null);
    }

    public function convert(Request $request, string $id): array
    {
        $data = $request->validate([
            'customerId' => 'nullable|string|max:60', 'confirmNew' => 'sometimes|boolean', 'vatNo' => 'nullable|string|max:60', 'cr' => 'nullable|string|max:40',
            'nameAr' => 'nullable|string|max:250', 'nameEn' => 'nullable|string|max:250', 'city' => 'nullable|string|max:120', 'zone' => 'nullable|string|max:190',
            'contact' => 'nullable|string|max:250', 'address' => 'nullable|string|max:500', 'terms' => 'nullable|string|max:120', 'creditLimit' => 'nullable|numeric|min:0',
            'priceList' => 'nullable|string|max:20',
        ]);
        $data['confirmNew'] = filter_var($data['confirmNew'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $this->conversion->convert(AuthUser::current(), $id, $data);
    }

    // ── quotations of the lead
    public function quote(Request $request, string $id): JsonResponse
    {
        $data = $request->filled('number')
            ? $request->validate(['number' => 'required|string|max:60'])
            : $request->validate(SalesRules::lines() + ['validUntil' => ['required', 'string', SalesRules::DATE], 'terms' => 'nullable|string|max:120', 'delivery' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:2000']);

        return response()->json($this->conversion->quote(AuthUser::current(), $id, $data), 201);
    }

    public function unquote(string $id, string $quotation): array
    {
        return $this->conversion->unquote(AuthUser::current(), $id, $quotation);
    }

    /** The list filters, validated; paging, search and sort are read by Paging. */
    private function filters(Request $request): array
    {
        $rules = [
            'state' => 'nullable|in:'.implode(',', LeadsService::STATES), 'stage' => 'nullable|string|max:40', 'owner' => 'nullable|string|max:200', 'team' => 'nullable|string|max:40',
            'priority' => 'nullable|integer|min:0|max:3', 'tag' => 'nullable|string|max:40', 'kind' => 'nullable|in:lead,opportunity', 'activity' => 'nullable|in:overdue,today,upcoming,none',
            'customer' => 'nullable|string|max:60', 'origin' => 'nullable|in:ops,odoo', 'from' => ['nullable', self::DAY], 'to' => ['nullable', self::DAY],
        ];
        $data = $request->validate($rules + [
            'page' => 'sometimes|integer|min:1', 'pageSize' => 'sometimes|integer|min:1|max:200', 'per' => 'sometimes|integer|min:1|max:50', 'q' => 'nullable|string|max:120',
            'sort' => 'nullable|string|max:30', 'order' => 'sometimes|in:asc,desc',
        ]);

        return array_intersect_key($data, $rules);
    }

    /** What the form of a lead may carry besides its title. Every key is optional; in an edit an empty value clears. */
    private static function fieldRules(): array
    {
        $text = fn (int $max) => "sometimes|nullable|string|max:{$max}";

        return [
            'kind' => 'sometimes|nullable|in:lead,opportunity', 'contactName' => $text(190), 'companyName' => $text(190), 'jobTitle' => $text(120), 'phone' => $text(40), 'mobile' => $text(40),
            'email' => 'sometimes|nullable|email|max:190', 'website' => $text(500), 'street' => $text(250), 'street2' => $text(250), 'city' => $text(120), 'region' => $text(120),
            'zip' => $text(20), 'country' => $text(120), 'vatNo' => $text(60), 'customerId' => $text(60), 'ownerId' => $text(60), 'teamId' => $text(40),
            'expectedRevenue' => 'sometimes|nullable|numeric|min:0|max:99999999999', 'probability' => 'sometimes|nullable|numeric|min:0|max:100', 'priority' => 'sometimes|nullable|integer|min:0|max:3',
            'source' => $text(120), 'medium' => $text(120), 'campaign' => $text(190), 'referredBy' => $text(190), 'description' => $text(20000),
            'deadline' => ['sometimes', 'nullable', self::DAY], 'tagIds' => 'sometimes|array|max:20', 'tagIds.*' => 'string|max:40',
        ];
    }
}
