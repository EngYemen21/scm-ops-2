<?php

namespace App\Crm\Services;

use App\Models\Quotation;
use App\Services\Sales\SalesService;
use Illuminate\Support\Facades\DB;

/**
 * The quotations made for a lead, as the lead shows them. A quotation is a document of the sales module: its status,
 * totals and validity are read through that module's own service, so the figures here are the figures there.
 */
class LeadQuotations
{
    public function __construct(private readonly SalesService $sales) {}

    /** @return array<int, array<string, mixed>> newest first */
    public function of(string $leadId): array
    {
        $links = DB::table('crm_lead_quotations')->where('lead_id', $leadId)->orderByDesc('created_at')->orderByDesc('id')->get(['quotation_id', 'created_by', 'created_at']);
        if ($links->isEmpty()) {
            return [];
        }
        $numbers = Quotation::whereIn('id', $links->pluck('quotation_id'))->pluck('number', 'id');
        $out = [];
        foreach ($links as $link) {
            if (! isset($numbers[$link->quotation_id])) {
                continue; // the quotation is gone: the link says nothing any more
            }
            $q = $this->sales->getQuotation((string) $link->quotation_id);
            $out[] = [
                'id' => $q['id'], 'number' => $q['number'], 'status' => $q['status'], 'date' => $q['date'] ?? null, 'validUntil' => $q['validUntil'] ?? null,
                'lines' => count($q['lines'] ?? []), 'totals' => $q['totals'] ?? null, 'validity' => $q['validity'] ?? null, 'discount' => $q['discount'] ?? null,
                'so' => $q['so'] ?? null, 'createdBy' => $q['createdBy'] ?? null, 'linkedBy' => $link->created_by,
            ];
        }

        return $out;
    }
}
