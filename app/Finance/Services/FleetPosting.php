<?php

namespace App\Finance\Services;

use App\Finance\Models\Journal;
use App\Models\FuelRecord;
use App\Models\MaintenanceOrder;
use App\Models\Trip;
use App\Services\Core\SettingsService;
use App\Services\Transport\TripCostService;
use App\Support\AuthUser;

/**
 * Fleet costs → ledger and trip cost sheet.
 *  - Fuel record: fuel expense on the vehicle's cost center (accrued until the station is paid through an expense/payment);
 *    a corrected record reverses its journal and posts again under a new event key (`fuel:{id}:v{n}`), a voided one
 *    reverses it;
 *  - Maintenance close: maintenance expense at the final cost;
 *  - `trip_costs` is management accounting and posts nothing: rebuildTripCosts() hands the trip to
 *    TripCostService::compute(), the one implementation of the cost sheet.
 * `finance.fleetExpensesIncludeVat` (default false, CFO decision) splits VAT input out of fuel/maintenance amounts.
 */
class FleetPosting
{
    public function __construct(private readonly LedgerService $ledger, private readonly AccountMap $map, private readonly SettingsService $settings) {}

    public function fuel(?AuthUser $user, FuelRecord $rec, ?string $tripNumber = null): void
    {
        $trip = $tripNumber ? Trip::where('number', $tripNumber)->orWhere('id', $tripNumber)->first() : null;
        if ($trip && ! $rec->trip_id) {
            $rec->update(['trip_id' => $trip->id]);
        }
        if ($rec->voided_at) {
            return;
        }
        $this->postFuel($user, $rec, "fuel:{$rec->id}");
        if ($rec->trip_id) {
            $this->rebuildTripCosts($rec->trip_id);
        }
    }

    /**
     * A corrected fuel record (amount, date or trip changed): the posted journal is reversed and the record is posted
     * again as it now stands. A record that never reached the ledger (written before finance went live) stays out of it.
     * The caller rebuilds the cost sheets of the trips involved.
     */
    public function repostFuel(AuthUser $user, FuelRecord $rec, string $reason): ?Journal
    {
        if (! $this->reverseFuel($user, $rec, $reason)) {
            return null;
        }
        $version = Journal::where('source_type', 'FuelRecord')->where('source_id', $rec->id)->where('kind', 'auto')->count() + 1;

        return $this->postFuel($user, $rec, "fuel:{$rec->id}:v{$version}");
    }

    /** Reverses the record's posted journal (voided or about to be re-posted). Null when there is nothing posted to reverse. */
    public function reverseFuel(AuthUser $user, FuelRecord $rec, string $reason): ?Journal
    {
        $journal = $rec->journal_id ? Journal::find($rec->journal_id) : null;
        if (! $journal || $journal->status !== 'posted') {
            return null;
        }

        return $this->ledger->reverse($user, $journal->id, $reason);
    }

    private function postFuel(?AuthUser $user, FuelRecord $rec, string $eventKey): ?Journal
    {
        $cost = round((float) $rec->cost, 2);
        if ($cost <= 0) {
            return null;
        }
        [$net, $vat] = $this->split($cost);
        $cc = $this->map->forVehicle($rec->vehicle_id)?->id;
        $dims = ['vehicleId' => $rec->vehicle_id, 'tripId' => $rec->trip_id, 'costCenterId' => $cc, 'referenceType' => 'FuelRecord', 'referenceId' => $rec->id];
        $j = $this->ledger->post($user, [
            'eventKey' => $eventKey, 'date' => $rec->date, 'source' => ['type' => 'FuelRecord', 'id' => $rec->id, 'number' => $rec->number],
            'memo' => "وقود {$rec->number} — ".($rec->vehicle?->code ?? '').($rec->station ? " — {$rec->station}" : ''),
            'lines' => array_merge(
                [['role' => 'fuel', 'debit' => $net, 'description' => "{$rec->liters} لتر"] + $dims],
                $vat > 0 ? [['role' => 'vat_input', 'debit' => $vat, 'description' => 'ضريبة مدخلات وقود'] + $dims] : [],
                [['role' => 'accrued_expenses', 'credit' => $cost, 'description' => "{$rec->number} — بانتظار التسوية"] + $dims],
            ),
        ]);
        $rec->update(['journal_id' => $j->id]);

        return $j;
    }

    public function maintenance(?AuthUser $user, MaintenanceOrder $mo): void
    {
        $cost = round((float) $mo->cost, 2);
        if ($cost <= 0 || $mo->journal_id) {
            return;
        }
        [$net, $vat] = $this->split($cost);
        $cc = $this->map->forVehicle($mo->vehicle_id)?->id;
        $dims = ['vehicleId' => $mo->vehicle_id, 'costCenterId' => $cc, 'referenceType' => 'MaintenanceOrder', 'referenceId' => $mo->id];
        $j = $this->ledger->post($user, [
            'eventKey' => "maint:{$mo->id}", 'date' => $mo->end_date ?? now(), 'source' => ['type' => 'MaintenanceOrder', 'id' => $mo->id, 'number' => $mo->number],
            'memo' => "صيانة {$mo->number} — ".($mo->vehicle?->code ?? '').($mo->shop ? " — {$mo->shop}" : ''),
            'lines' => array_merge(
                [['role' => 'maintenance', 'debit' => $net, 'description' => trim(($mo->type_ar ?? '').' '.($mo->desc_ar ?? ''))] + $dims],
                $vat > 0 ? [['role' => 'vat_input', 'debit' => $vat, 'description' => 'ضريبة مدخلات صيانة'] + $dims] : [],
                [['role' => 'accrued_expenses', 'credit' => $cost, 'description' => "{$mo->number} — بانتظار التسوية"] + $dims],
            ),
        ]);
        $mo->update(['journal_id' => $j->id]);
    }

    /**
     * Rebuild the trip's cost sheet. Entry point for everything that changes a fact linked to a trip (fuel records,
     * expense vouchers); the figures themselves are TripCostService's — there is one implementation.
     */
    public function rebuildTripCosts(string $tripId): void
    {
        if (Trip::whereKey($tripId)->exists()) {
            app(TripCostService::class)->compute($tripId);
        }
    }

    /** @return array{0: float, 1: float} net, VAT */
    private function split(float $gross): array
    {
        if (! (bool) $this->settings->get('finance.fleetExpensesIncludeVat')) {
            return [$gross, 0.0];
        }
        $pct = (float) ($this->settings->get('sales.vatPct') ?? 15);
        $net = round($gross / (1 + $pct / 100), 2);

        return [$net, round($gross - $net, 2)];
    }
}
