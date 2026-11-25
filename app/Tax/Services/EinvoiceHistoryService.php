<?php

namespace App\Tax\Services;

use App\Support\AppError;
use App\Support\Paging;
use App\Tax\Models\EinvoiceHistory;
use App\Tax\Support\Shape;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The e-invoice history that came from Odoo: one row per e-invoice document Odoo made for one of its invoices — sent
 * and accepted, waiting, or refused with the authority's message. Read-only: it is the record of what Odoo did, kept
 * so that the invoices of the archive show their e-invoice status here as well. The XML Odoo sent is in the backup of
 * Odoo's attachments when that backup holds it (`fileUrl`).
 */
class EinvoiceHistoryService
{
    /** @param  array{status?:?string, moveType?:?string, from?:?string, to?:?string, customer?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $q = EinvoiceHistory::query();
        if (! empty($f['status'])) {
            $q->whereIn('status', array_map('trim', explode(',', $f['status'])));
        }
        if (! empty($f['moveType'])) {
            $q->whereIn('move_type', array_map('trim', explode(',', $f['moveType'])));
        }
        if (! empty($f['from'])) {
            $q->whereDate('invoice_date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('invoice_date', '<=', $f['to']);
        }
        if (! empty($f['customer'])) {
            $q->where('customer_id', $f['customer']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('move_number', 'like', $like)->orWhere('partner_name', 'like', $like)->orWhere('uuid', 'like', $like)->orWhere('attachment_name', 'like', $like));
        }
        $column = ['date' => 'invoice_date', 'number' => 'move_number', 'amount' => 'amount_total', 'status' => 'status', 'chain' => 'chain_index', 'partner' => 'partner_name'][$page->sort ?? ''] ?? 'invoice_date';
        // an invoice without a date (never posted) sorts last either way
        $q->orderByRaw("{$column} is null")->orderBy($column, $page->order)->orderBy('external_ref', $page->order)->orderBy('id', $page->order);
        $total = (clone $q)->toBase()->getCountForPagination();
        $rows = $q->forPage($page->page, $page->pageSize)->get();
        $held = $this->held($rows->pluck('attachment_ref')->filter()->all());

        return $page->wrap($rows->map(fn (EinvoiceHistory $h) => Shape::history($h, isset($held[$h->attachment_ref])))->values()->all(), $total) + ['summary' => $this->summary()];
    }

    public function get(string $id): array
    {
        $h = EinvoiceHistory::where('id', $id)->orWhere(fn ($w) => $w->where('source_system', 'odoo')->where('external_ref', $id))->first()
            ?? throw AppError::notFound('EINVOICE_HISTORY_NOT_FOUND', 'السجل غير موجود', 'History record not found');

        return Shape::history($h, isset($this->held(array_filter([$h->attachment_ref]))[$h->attachment_ref]));
    }

    public function summary(): array
    {
        $counts = EinvoiceHistory::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return ['total' => (int) $counts->sum(), 'byStatus' => collect(array_keys(EinvoiceHistory::LABELS))->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])->all(),
            'first' => Shape::day(EinvoiceHistory::query()->min('invoice_date') ? Carbon::parse(EinvoiceHistory::query()->min('invoice_date')) : null),
            'last' => Shape::day(EinvoiceHistory::query()->max('invoice_date') ? Carbon::parse(EinvoiceHistory::query()->max('invoice_date')) : null)];
    }

    /** The attachments whose content the backup of Odoo holds. @return array<string, true> */
    private function held(array $attachmentIds): array
    {
        if (! $attachmentIds || ! Schema::hasTable('odoo_files')) {
            return [];
        }

        return DB::table('odoo_files')->whereIn('attachment_id', array_map('intval', $attachmentIds))->where('status', 'stored')->pluck('attachment_id')->mapWithKeys(fn ($id) => [(string) $id => true])->all();
    }
}
