<?php

namespace App\Credit\Http;

use App\Credit\Services\RequestsService;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Credit requests: the list and its counts, one request with everything around it, raising and filling one in, the
 * terms the customer accepted, comments, the personal values (audited) and the printable agreement. Thin: validate
 * (camelCase keys, exactly as the client sends them), call the service, return.
 */
class RequestsController extends Controller
{
    private const DAY = 'date_format:Y-m-d';

    public function __construct(private readonly RequestsService $requests) {}

    public function index(Request $request): array
    {
        $f = $request->validate([
            'state' => 'nullable|in:'.implode(',', [...RequestsService::STATE_FILTERS, 'all']), 'stage' => 'nullable|string|max:40', 'scope' => 'nullable|in:'.implode(',', RequestsService::SCOPES),
            'sla' => 'nullable|in:none,on_track,due_soon,late,escalated', 'origin' => 'nullable|in:odoo,ops', 'customer' => 'nullable|string|max:40',
            'apply' => 'nullable|in:none,applied,blocked,restored', 'from' => ['nullable', self::DAY], 'to' => ['nullable', self::DAY],
        ]);

        return $this->requests->list(AuthUser::current(), Paging::from($request, 25), $f);
    }

    public function summary(): array
    {
        return $this->requests->summary(AuthUser::current());
    }

    public function customers(Request $request): array
    {
        return $this->requests->customers(AuthUser::current(), $request->validate(['q' => 'nullable|string|max:120'])['q'] ?? null);
    }

    public function show(string $id): array
    {
        return $this->requests->get(AuthUser::current(), $id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['customerId' => 'required|string|max:40'] + self::rules());

        return response()->json($this->requests->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        return $this->requests->update(AuthUser::current(), $id, $request->validate(['customerId' => 'sometimes|string|max:40'] + self::rules()));
    }

    public function acceptTerms(Request $request, string $id): array
    {
        return $this->requests->acceptTerms(AuthUser::current(), $id, $request->validate(['versionId' => 'nullable|string|max:40'])['versionId'] ?? null);
    }

    public function comment(Request $request, string $id): array
    {
        return $this->requests->comment(AuthUser::current(), $id, (string) $request->validate(['text' => 'required|string|min:1|max:4000'])['text']);
    }

    public function privateValues(string $id): array
    {
        return $this->requests->privateValues(AuthUser::current(), $id);
    }

    public function agreement(string $id): array
    {
        return $this->requests->agreement(AuthUser::current(), $id);
    }

    /** The fields of the request form. A personal value may come back as its mask: the service then keeps what it holds. */
    private static function rules(): array
    {
        $text = fn (int $max) => "nullable|string|max:{$max}";
        $personal = 'nullable|string|max:40';

        return [
            'companyName' => $text(190), 'commercialRegistration' => ['nullable', 'string', 'regex:/^\d{10}$/'], 'businessActivity' => $text(190), 'legalEntityType' => $text(120),
            'ownerName' => $text(190), 'ownerNationalId' => ['nullable', 'string', 'max:40', 'regex:/^(\d{10}|[•\d]{1,40})$/u'],
            'phone' => $text(40), 'mobile' => $text(40), 'email' => 'nullable|email|max:190', 'website' => $text(250), 'poBox' => $text(40), 'postalCode' => $text(20),
            'street' => $text(250), 'district' => $text(120), 'buildingNo' => $text(20), 'city' => $text(120),
            'requestedCreditLimit' => 'nullable|numeric|min:0|max:999999999.99|decimal:0,2', 'requestedPaymentDays' => 'nullable|integer|min:1|max:365',
            'monthlyPurchaseVolume' => 'nullable|numeric|min:0|max:999999999.99|decimal:0,2',
            'paymentMethodIds' => 'sometimes|array|max:20', 'paymentMethodIds.*' => 'string|max:40',
            'responsibles' => 'sometimes|array|max:20', 'responsibles.*.id' => 'nullable|string|max:40', 'responsibles.*.name' => 'required|string|min:1|max:190',
            'responsibles.*.position' => $text(120), 'responsibles.*.mobile' => $text(40), 'responsibles.*.nationalId' => ['nullable', 'string', 'max:40', 'regex:/^(\d{10}|[•\d]{1,40})$/u'],
            'signatories' => 'sometimes|array|max:20', 'signatories.*.id' => 'nullable|string|max:40', 'signatories.*.name' => 'required|string|min:1|max:190',
            'signatories.*.jobTitle' => $text(120), 'signatories.*.mobile' => $text(40), 'signatories.*.signatureNote' => $text(250),
            'bankLines' => 'sometimes|array|max:10', 'bankLines.*.id' => 'nullable|string|max:40', 'bankLines.*.bankName' => 'required|string|min:1|max:120',
            'bankLines.*.branch' => $text(120), 'bankLines.*.swift' => ['nullable', 'string', 'regex:/^[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/'],
            'bankLines.*.accountNumber' => $personal, 'bankLines.*.iban' => 'nullable|string|max:42',
        ];
    }
}
