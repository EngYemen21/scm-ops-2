<?php

namespace App\Insights\Http;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequirePermission;
use App\Insights\Services\OperationsInsightsService;
use App\Insights\Services\StockCardService;
use App\Insights\Support\Access;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The stock card and the operations insights. Not for brand owners (outsiders); finance figures need fin.report. */
class OperationsController extends Controller
{
    public function __construct(private readonly OperationsInsightsService $ops, private readonly StockCardService $card) {}

    public function summary(Request $request): array
    {
        $u = AuthUser::current();
        Access::insider($u);
        $f = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'days' => 'nullable|integer|min:1|max:3650']);

        return $this->ops->summary($u, $f['from'] ?? null, $f['to'] ?? null, (int) ($f['days'] ?? 90));
    }

    public function slowMoving(Request $request): array
    {
        $u = AuthUser::current();
        Access::insider($u);
        $f = $request->validate(['days' => 'nullable|integer|min:1|max:3650', 'warehouse' => 'nullable|string|max:40']);

        return $this->ops->slowMoving($u, Paging::from($request, 50), $f);
    }

    public function expiryBuckets(): array
    {
        $u = AuthUser::current();
        Access::insider($u);

        return $this->ops->expiryBuckets($u);
    }

    public function customerMargin(Request $request): array
    {
        $this->finance($request);
        $f = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return $this->ops->customerMargin($f['from'] ?? null, $f['to'] ?? null);
    }

    public function pnl(Request $request): array
    {
        $this->finance($request);
        $f = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return $this->ops->pnl($f['from'] ?? null, $f['to'] ?? null);
    }

    public function ageing(Request $request): array
    {
        $this->finance($request);

        return $this->ops->ageing();
    }

    public function invoiceCounts(Request $request): array
    {
        $this->finance($request);
        $f = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return $this->ops->invoiceCounts($f['from'] ?? null, $f['to'] ?? null);
    }

    public function stockCard(Request $request): array
    {
        Access::insider(AuthUser::current());

        return $this->card->card(self::cardFilters($request), Paging::from($request, 100));
    }

    public function stockCardExport(Request $request): Response
    {
        Access::insider(AuthUser::current());
        app(RequirePermission::class)->handle($request, fn () => new Response, 'report.export');
        $csv = $this->card->csv(self::cardFilters($request));

        return response($csv['body'], 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="'.$csv['fileName'].'"']);
    }

    private static function cardFilters(Request $request): array
    {
        $f = $request->validate(['sku' => 'required|string|max:120', 'warehouse' => 'nullable|string|max:40', 'from' => 'nullable|date', 'to' => 'nullable|date', 'internal' => 'nullable|in:0,1,true,false']);
        $f['internal'] = in_array($f['internal'] ?? null, ['1', 'true'], true);

        return $f;
    }

    /** Finance figures: same permission as the finance reports (403 + security audit, like `perm:`). */
    private function finance(Request $request): void
    {
        Access::insider(AuthUser::current());
        app(RequirePermission::class)->handle($request, fn () => new Response, 'fin.report');
    }
}
