<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

class QuotationsController extends Controller
{
    public function __construct(private readonly SalesService $sales) {}

    /** The configurable sales rules (max discount, VAT, backorders, what a zero credit limit means) for the forms. */
    public function policy(): array
    {
        return $this->sales->policy();
    }

    public function index(Request $request): array
    {
        return $this->sales->listQuotations(Paging::from($request), $request->only(['status', 'customer', 'discount']));
    }

    public function show(string $id): array
    {
        return $this->sales->getQuotation($id);
    }

    public function store(Request $request): array
    {
        $data = $request->validate(SalesRules::lines() + [
            'customerCode' => 'required|string|min:1', 'validUntil' => ['required', 'string', SalesRules::DATE],
            'terms' => 'nullable|string', 'delivery' => 'nullable|string', 'notes' => 'nullable|string',
            'action' => 'sometimes|in:draft,sent', 'attachments' => 'sometimes|array', 'attachments.*' => 'string',
        ]);

        return $this->sales->createQuotation(AuthUser::current(), $data + ['action' => 'draft']);
    }

    public function send(string $id)
    {
        return $this->sales->setQuotationStatus(AuthUser::current(), $id, 'sent');
    }

    public function approve(Request $request, string $id)
    {
        $data = $request->validate(SalesRules::NOTE);

        return $this->sales->setQuotationStatus(AuthUser::current(), $id, 'approved', $data['note'] ?? null);
    }

    public function reject(Request $request, string $id)
    {
        $data = $request->validate(SalesRules::NOTE);

        return $this->sales->setQuotationStatus(AuthUser::current(), $id, 'rejected', ($data['note'] ?? null) ?: ($data['reason'] ?? null));
    }

    /** A holder of `discount.approve` allows a discount above the limit (action button: the note is optional). */
    public function approveDiscount(Request $request, string $id): array
    {
        $data = $request->validate(SalesRules::NOTE);

        return $this->sales->approveQuotationDiscount(AuthUser::current(), $id, ($data['note'] ?? null) ?: ($data['reason'] ?? null));
    }

    public function extend(Request $request, string $id): array
    {
        $data = $request->validate(['validUntil' => ['required', 'string', SalesRules::DATE]]);

        return $this->sales->extendQuotation(AuthUser::current(), $id, $data['validUntil']);
    }

    public function duplicate(string $id): array
    {
        return $this->sales->duplicateQuotation(AuthUser::current(), $id);
    }

    public function convert(Request $request, string $id): array
    {
        $data = $request->validate(['warehouseCode' => 'nullable|string', 'dueDate' => 'nullable|string', 'window' => 'nullable|string', 'priority' => 'nullable|string', 'allowBackorder' => 'sometimes|boolean']);

        return $this->sales->convertQuotation(AuthUser::current(), $id, $data);
    }
}
