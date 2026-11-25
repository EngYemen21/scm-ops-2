<?php

namespace App\Portal\Services;

use App\Models\Product;
use App\Portal\Models\FavoriteItem;
use App\Portal\Models\FavoriteList;
use App\Portal\Support\Access;
use App\Portal\Support\Parties;
use App\Portal\Support\PortalLog;
use App\Portal\Support\Text;
use App\Services\Core\AuditService;
use App\Services\Sales\SalesService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

/**
 * "Order again": a favourite list becomes a DRAFT quotation of the sales module for the list's customer.
 *
 * The quotation is made by the sales module's own service (`SalesService::createQuotation`), so every sales rule holds
 * — discount limit, validity, inactive products — and the document is followed from there (sent, approved, converted
 * into an order). It needs `sales.manage` besides `portal.manage`. Nothing of the list changes; the list's timeline
 * remembers which quotations were made from it.
 *
 * It is OPS's own on every list, also on one that Odoo owns: the customer must be an OPS customer (a list whose owner
 * is not linked yet is refused with the reason) and only products OPS has and sells go on the quotation — the others
 * are named as left out, never dropped silently.
 */
class ReorderService
{
    /** The most lines one quotation is made of. */
    public const MAX_LINES = 300;

    public const VALID_DAYS = 14;

    public function __construct(
        private readonly FavoritesService $favorites,
        private readonly SalesService $sales,
        private readonly PriceHints $prices,
        private readonly AuditService $audit,
    ) {}

    /**
     * What ordering the list again would put on a quotation: its lines with the usual quantity and a price to start
     * from, the products that are left out and why — or why it cannot be done at all.
     */
    public function preview(string $listId): array
    {
        $l = $this->favorites->find($listId);
        $refusal = $this->refusal($l);
        $items = FavoriteItem::where('list_id', $l->id)->with([Parties::PRODUCT, 'product.baseUom:id,code'])->orderBy('sequence')->orderBy('id')->limit(self::MAX_LINES + 1)->get();
        $more = $items->count() > self::MAX_LINES;
        $items = $items->take(self::MAX_LINES);
        $hints = $l->customer_id ? $this->prices->for($l->customer_id, $items->pluck('product_id')->filter()->all()) : [];
        $lines = $skipped = [];
        foreach ($items as $item) {
            $p = $item->product_id ? $item->product : null;
            if (! $p) {
                $skipped[] = ['name' => $item->product_name ?? "#{$item->product_ref}", 'reason' => 'unlinked'];

                continue;
            }
            if (! $p->active) {
                $skipped[] = ['name' => $p->name_ar, 'sku' => $p->sku, 'reason' => 'inactive'];

                continue;
            }
            $hint = $hints[$p->id] ?? null;
            $lines[] = [
                'itemId' => $item->id, 'sku' => $p->sku, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en, 'uom' => $p->baseUom?->code,
                // a quotation counts whole units: the usual quantity is rounded up, never down to nothing
                'qty' => max(1, (int) ceil((float) $item->qty)), 'usualQty' => Text::qty($item->qty),
                'price' => $hint ? $hint['price'] : null, 'priceSource' => $hint['source'] ?? null, 'priceRef' => $hint['ref'] ?? null, 'priceAt' => $hint['at'] ?? null,
            ];
        }
        if (! $refusal && ! $lines) {
            $refusal = AppError::rule('PORTAL_LIST_NOTHING_TO_ORDER', 'لا منتج في القائمة يمكن تسعيره: منتجاتها غير مربوطة بأصناف نظام العمليات أو موقوفة', 'No product of the list can be quoted: its products are not OPS products yet, or are inactive');
        }

        return [
            'list' => ['id' => $l->id, 'name' => $l->name, 'itemsCount' => $l->items_count], 'customer' => Parties::customerOf($l), 'branch' => Parties::branchOf($l),
            'allowed' => $refusal === null, 'code' => $refusal?->errorCode, 'reasonAr' => $refusal?->messageAr, 'reasonEn' => $refusal?->messageEn,
            'validUntil' => now()->addDays(self::VALID_DAYS)->toDateString(), 'lines' => $lines, 'skipped' => $skipped, 'more' => $more, 'maxLines' => self::MAX_LINES,
            'policy' => $this->sales->policy(),
        ];
    }

    /**
     * @param  array{validUntil:string, notes?:?string, terms?:?string, delivery?:?string, lines:array<int, array{sku:string, qty:int|string, price:float|int|string, discPct?:float|int|string|null}>}  $dto
     * @return array{quotation:array, list:array}
     */
    public function order(AuthUser $user, string $listId, array $dto): array
    {
        // before the transaction: a refused permission is written to the audit trail and must stay there
        Access::need($user, 'sales.manage', 'إنشاء عرض سعر من قائمة المفضلة', 'making a quotation from a favourite list');

        $quotation = DB::transaction(function () use ($user, $listId, $dto) {
            $l = $this->favorites->find($listId);
            if ($refusal = $this->refusal($l)) {
                throw $refusal;
            }
            $lines = array_values($dto['lines']);
            if (count($lines) > self::MAX_LINES) {
                throw AppError::validation('PORTAL_TOO_MANY_LINES', 'عدد الأسطر يتجاوز '.self::MAX_LINES, 'More than '.self::MAX_LINES.' lines', [['path' => 'lines', 'message' => 'عدد الأسطر كبير']]);
            }
            // "again" means the products of this list — another product is quoted from the sales page
            $skus = array_map(fn (array $x) => (string) $x['sku'], $lines);
            $onList = Product::whereIn('sku', $skus)->whereIn('id', FavoriteItem::query()->where('list_id', $l->id)->whereNotNull('product_id')->select('product_id'))
                ->pluck('sku')->map(fn ($s) => mb_strtoupper((string) $s))->flip();
            $seen = [];
            foreach ($skus as $i => $sku) {
                $key = mb_strtoupper($sku);
                if (! isset($onList[$key])) {
                    throw AppError::validation('PORTAL_NOT_ON_LIST', "المنتج {$sku} ليس من منتجات هذه القائمة", "Product {$sku} is not on this list", [['path' => "lines.{$i}.sku", 'message' => 'ليس في القائمة']]);
                }
                if (isset($seen[$key])) {
                    throw AppError::validation('PORTAL_LINE_TWICE', "المنتج {$sku} مكرر في الأسطر", "Product {$sku} appears twice", [['path' => "lines.{$i}.sku", 'message' => 'مكرر']]);
                }
                $seen[$key] = true;
            }
            $branch = $l->branch_id ? $l->branch : null;
            $made = $this->sales->createQuotation($user, [
                'customerCode' => $l->customer->code, 'validUntil' => $dto['validUntil'], 'terms' => $dto['terms'] ?? null, 'delivery' => $dto['delivery'] ?? null,
                'notes' => Text::clean($dto['notes'] ?? null, 2000) ?? "طلب مكرر من قائمة المفضلة «{$l->name}»".($branch ? " — فرع {$branch->name}" : ''),
                'action' => 'draft', 'lines' => $lines,
            ]);
            $facts = ['number' => $made['number'], 'id' => $made['id'], 'lines' => count($lines), 'total' => $made['totals']['total'] ?? null];
            PortalLog::add('list', $l->id, 'quotation', $user, ['data' => $facts]);
            $this->audit->log($user, ['action' => 'PORTAL.FAVLIST.ORDER_AGAIN', 'entityType' => 'FavoriteList', 'entityId' => $l->id, 'entityNumber' => $l->name, 'newValue' => $facts + ['customer' => $l->customer->code]]);

            return $made;
        });

        return [
            'quotation' => ['id' => $quotation['id'], 'number' => $quotation['number'], 'status' => $quotation['status'], 'validUntil' => $quotation['validUntil'] ?? null,
                'totals' => $quotation['totals'] ?? null, 'lines' => count($quotation['lines'] ?? [])],
            'list' => $this->favorites->getList($listId),
        ];
    }

    /** Why this list cannot be ordered again (null = it can). */
    private function refusal(FavoriteList $l): ?AppError
    {
        $c = $l->customer_id ? $l->customer : null;
        if (! $c) {
            return AppError::rule('PORTAL_NO_CUSTOMER', 'صاحب هذه القائمة ليس عميلًا في نظام العمليات بعد — يُسعَّر له بعد أن يُربط بعميل', 'The owner of this list is not an OPS customer yet — it can be quoted once it is linked to one',
                ['customerRef' => $l->customer_ref, 'customerName' => $l->customer_name]);
        }
        if (! $c->active) {
            return AppError::rule('CUSTOMER_INACTIVE', "العميل {$c->name_ar} ({$c->code}) موقوف", "Customer {$c->code} is inactive");
        }
        if (! $l->active) {
            return AppError::rule('PORTAL_LIST_ARCHIVED', "القائمة «{$l->name}» مؤرشفة", "List \"{$l->name}\" is archived");
        }
        if ($l->items_count < 1) {
            return AppError::rule('PORTAL_LIST_EMPTY', "القائمة «{$l->name}» فارغة", "List \"{$l->name}\" is empty");
        }

        return null;
    }
}
