<?php

namespace App\Pos\Http;

use App\Http\Controllers\Controller;
use App\Pos\Services\CatalogService;
use App\Pos\Services\OrdersService;
use App\Pos\Services\ReceiptService;
use App\Pos\Services\RefundService;
use App\Pos\Services\SaleService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The selling screen: catalogue, quote, sale, refund, the orders and their receipts. */
class OrdersController extends Controller
{
    public function __construct(
        private readonly SaleService $sales,
        private readonly RefundService $refunds,
        private readonly OrdersService $orders,
        private readonly CatalogService $catalog,
        private readonly ReceiptService $receipts,
    ) {}

    public function catalog(Request $request): array
    {
        $data = $request->validate(['register' => 'required|string|max:60', 'customer' => 'nullable|string|max:60']);

        return $this->catalog->search(Paging::from($request, 24), $data['register'], $data['customer'] ?? null);
    }

    public function scan(Request $request): array
    {
        $data = $request->validate(['register' => 'required|string|max:60', 'code' => 'required|string|max:120', 'customer' => 'nullable|string|max:60']);

        return $this->catalog->scan($data['register'], $data['code'], $data['customer'] ?? null);
    }

    public function quote(Request $request): array
    {
        return $this->sales->quote(AuthUser::current(), $request->validate(['session' => 'required|string|max:60', 'customer' => 'nullable|string|max:60'] + self::lineRules()));
    }

    public function sell(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session' => 'required|string|max:60', 'clientKey' => 'required|string|min:8|max:64', 'customer' => 'nullable|string|max:60', 'expectedTotal' => 'nullable|numeric',
            'note' => 'nullable|string|max:500', 'heldId' => 'nullable|string|max:60',
        ] + self::lineRules() + self::paymentRules());
        $out = $this->sales->sell(AuthUser::current(), $data);

        return response()->json($out, $out['replayed'] ? 200 : 201);
    }

    public function refund(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'session' => 'required|string|max:60', 'clientKey' => 'required|string|min:8|max:64', 'reason' => 'required|string|max:500',
            'lines' => 'required|array|min:1|max:300', 'lines.*.lineId' => 'required|string|max:60', 'lines.*.qty' => 'required|integer|min:0|max:100000',
        ] + self::paymentRules());
        $out = $this->refunds->refund(AuthUser::current(), $id, $data);

        return response()->json($out, $out['replayed'] ? 200 : 201);
    }

    public function issuePending(string $id): array
    {
        return $this->sales->issuePending(AuthUser::current(), $id);
    }

    public function index(Request $request): array
    {
        return $this->orders->list(Paging::from($request), $request->only(['kind', 'session', 'register', 'customer', 'cashier', 'from', 'to', 'pending', 'source']), AuthUser::current());
    }

    public function show(string $id): array
    {
        return $this->orders->get($id, AuthUser::current());
    }

    public function receipt(Request $request, string $id): Response
    {
        $data = $request->validate(['format' => 'nullable|in:80mm,a4']);

        return response($this->receipts->html($id, $data['format'] ?? '80mm'), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private static function lineRules(): array
    {
        return ['lines' => 'required|array|min:1|max:300', 'lines.*.productId' => 'required|string|max:60', 'lines.*.qty' => 'required|integer|min:1|max:100000', 'lines.*.discountPct' => 'nullable|numeric|min:0|max:100'];
    }

    private static function paymentRules(): array
    {
        return ['payments' => 'required|array|min:1|max:10', 'payments.*.method' => 'required|string|max:60', 'payments.*.amount' => 'required|numeric|min:0.01|max:100000000', 'payments.*.reference' => 'nullable|string|max:80'];
    }
}
