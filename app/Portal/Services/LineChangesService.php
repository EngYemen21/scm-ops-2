<?php

namespace App\Portal\Services;

use App\Models\Product;
use App\Portal\Models\LineChange;
use App\Portal\Models\OrderRequest;
use App\Portal\Support\Parties;
use App\Portal\Support\Shape;
use App\Portal\Support\Text;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The log of order-line changes: who added, changed or removed which product on which order, with the value before
 * and after. Odoo's history of it (every change customers and the sales team made to order lines) is kept here by the
 * projection; the changes people make to the lines of an order request in OPS are written by `record()`.
 *
 * Append-only: there is no call that edits or removes a line of the log. Ten thousand lines and growing — the list is
 * read by page, newest first, through the index of what it is filtered by (time, kind, order, request, customer,
 * product).
 */
class LineChangesService
{
    private const WITH = [Parties::CUSTOMER, Parties::PRODUCT, Parties::BRANCH, 'request:id,number'];

    /**
     * @param  array{type?:?string, field?:?string, customer?:?string, product?:?string, orderRef?:?string, request?:?string, by?:?string, origin?:?string, from?:?string, to?:?string}  $f
     */
    public function list(Paging $page, array $f): array
    {
        $query = $this->filtered($f, $page->q)->with(self::WITH);
        $page->order === 'asc' ? $query->orderBy('changed_at')->orderBy('id') : $query->orderByDesc('changed_at')->orderByDesc('id');
        // how many of each kind the same filters hold, whatever kind is being looked at — the figures of the filter pills
        $byType = $this->filtered(array_diff_key($f, ['type' => 1]), $page->q)->toBase()->selectRaw('change_type, COUNT(*) AS n')->groupBy('change_type')->pluck('n', 'change_type');

        return $page->paginate($query, fn (LineChange $c) => Shape::lineChange($c)) + [
            'totals' => ['added' => (int) ($byType['added'] ?? 0), 'modified' => (int) ($byType['modified'] ?? 0), 'removed' => (int) ($byType['removed'] ?? 0)],
        ];
    }

    /** The changes of one order request — and of the order it became in the source system — newest first. */
    public function of(OrderRequest $r, int $limit = 200): array
    {
        return LineChange::with(self::WITH)->where(function ($w) use ($r) {
            $w->where('request_id', $r->id);
            if ($r->order_ref) {
                $w->orWhere('order_ref', $r->order_ref);
            }
        })->orderByDesc('changed_at')->orderByDesc('id')->limit($limit)->get()->map(fn (LineChange $c) => Shape::lineChange($c))->all();
    }

    public function filtered(array $f, ?string $q): Builder
    {
        $query = LineChange::query();
        if (in_array($f['type'] ?? null, LineChange::TYPES, true)) {
            $query->where('change_type', $f['type']);
        }
        if (in_array($f['field'] ?? null, LineChange::FIELDS, true)) {
            $query->where('field', $f['field']);
        }
        Parties::filterCustomer($query, $f['customer'] ?? null);
        Parties::filterOrigin($query, $f['origin'] ?? null);
        if (! empty($f['product'])) {
            $product = (string) $f['product'];
            if (str_starts_with($product, 'ref:')) {
                $query->where('product_ref', substr($product, 4));
            } else {
                $ids = Product::where('id', $product)->orWhere('sku', $product)->pluck('id')->all();
                $ids ? $query->whereIn('product_id', $ids) : $query->whereRaw('1 = 0');
            }
        }
        if (! empty($f['orderRef'])) {
            // the order as the source system knows it: by its id there or by its number (`order` is the direction of the sort)
            $order = (string) $f['orderRef'];
            $query->where(fn ($w) => $w->where('order_ref', $order)->orWhere('order_number', $order));
        }
        if (! empty($f['request'])) {
            $query->where('request_id', $f['request']);
        }
        if (! empty($f['by'])) {
            $query->where('changed_by', $f['by']);
        }
        if (! empty($f['from'])) {
            $query->where('changed_at', '>=', Carbon::parse($f['from'])->startOfDay());
        }
        if (! empty($f['to'])) {
            $query->where('changed_at', '<', Carbon::parse($f['to'])->startOfDay()->addDay());
        }
        if ($q !== null && $q !== '') {
            $like = Text::like($q);
            $op = Sql::like();
            $query->where(function ($w) use ($like, $op) {
                $w->where('order_number', $op, $like)->orWhere('changed_by', $op, $like);
                Parties::searchProduct($w, $like);
                Parties::searchCustomer($w, $like);
            });
        }

        return $query;
    }

    /**
     * Writes one line of the log for a change made in OPS to a line of an order request. Call it inside the
     * transaction of the change, so the log never tells of something that was rolled back.
     *
     * @param  array{product_id:?string, product_ref?:?string, product_name:?string}  $product  the product of the line: an OPS product, or the one the source system names
     * @param  string  $type  added | modified | removed
     * @param  string|null  $field  qty | price — what a modification changed
     */
    public function record(AuthUser $user, OrderRequest $r, array $product, string $type, ?string $field, ?string $old, ?string $new): LineChange
    {
        return LineChange::create([
            'changed_at' => now(), 'change_type' => $type, 'field' => $field, 'field_label' => $field === 'qty' ? 'Quantity' : ($field === 'price' ? 'Unit Price' : null),
            'old_value' => $old, 'new_value' => $new, 'changed_by' => $user->nameAr ?: $user->username, 'changed_by_id' => $user->id, 'request_id' => $r->id,
            'customer_id' => $r->customer_id, 'customer_ref' => $r->customer_ref, 'customer_name' => $r->customer_name, 'branch_id' => $r->branch_id,
            'product_id' => $product['product_id'] ?? null, 'product_ref' => $product['product_ref'] ?? null, 'product_name' => $product['product_name'] ?? null,
        ]);
    }
}
