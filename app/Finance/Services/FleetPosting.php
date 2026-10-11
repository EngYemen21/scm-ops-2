<?php

namespace App\Finance\Services;

use App\Finance\Models\Expense;
use App\Models\FuelRecord;
use App\Models\MaintenanceOrder;
use App\Models\Trip;
use App\Models\TripCost;
use App\Services\Core\SettingsService;
use App\Support\AuthUser;

/**
 * Fleet costs → ledger and trip cost sheet.
 *  - Fuel record: fuel expense on the vehicle's cost center (accrued until the station is paid through an expense/payment);
 *  - Maintenance close: maintenance expense at the final cost;
 *  - `trip_costs` is rebuilt from the facts linked to the trip (fuel with a trip, expense vouchers with a trip) — it was
 *    always zero before; the columns nothing feeds (driver, ot, dep …) stay 0 and the UI says so.
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
        $cost = round((float) $rec->cost, 2);
        if ($cost <= 0) {
            return;
        }
        [$net, $vat] = $this->split($cost);
        $cc = $this->map->forVehicle($rec->vehicle_id)?->id;
        $dims = ['vehicleId' => $rec->vehicle_id, 'tripId' => $rec->trip_id, 'costCenterId' => $cc, 'referenceType' => 'FuelRecord', 'referenceId' => $rec->id];
        $j = $this->ledger->post($user, [
            'eventKey' => "fuel:{$rec->id}", 'date' => $rec->date, 'source' => ['type' => 'FuelRecord', 'id' => $rec->id, 'number' => $rec->number],
            'memo' => "وقود {$rec->number} — ".($rec->vehicle?->code ?? '').($rec->station ? " — {$rec->station}" : ''),
            'lines' => array_merge(
                [['role' => 'fuel', 'debit' => $net, 'description' => "{$rec->liters} لتر"] + $dims],
                $vat > 0 ? [['role' => 'vat_input', 'debit' => $vat, 'description' => 'ضريبة مدخلات وقود'] + $dims] : [],
                [['role' => 'accrued_expenses', 'credit' => $cost, 'description' => "{$rec->number} — بانتظار التسوية"] + $dims],
            ),
        ]);
        $rec->update(['journal_id' => $j->id]);
        if ($rec->trip_id) {
            $this->rebuildTripCosts($rec->trip_id);
        }
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

    /** Rebuild the trip's cost sheet from linked facts (fuel, expense vouchers by kind). */
    public function rebuildTripCosts(string $tripId): void
    {
        $fuel = round((float) FuelRecord::where('trip_id', $tripId)->sum('cost'), 2);
        $expenses = Expense::where('trip_id', $tripId)->where('status', 'posted')->with('account:id,role')->get();
        $by = fn (array $roles) => round((float) $expenses->filter(fn ($e) => in_array($e->account?->role, $roles, true))->sum('total'), 2);
        $tolls = $by(['fleet_other']);
        $maint = $by(['maintenance']);
        $other = round((float) $expenses->sum('total') - $tolls - $maint, 2);
        TripCost::updateOrCreate(['trip_id' => $tripId], ['fuel' => $fuel, 'tolls' => $tolls, 'maint' => $maint, 'other' => max(0, $other)]);
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
