<?php

namespace App\Services\Transport;

use App\Finance\Models\Expense;
use App\Models\Driver;
use App\Models\FuelRecord;
use App\Models\MaintenanceOrder;
use App\Models\Trip;
use App\Models\TripCost;
use App\Models\Vehicle;
use App\Services\Core\SettingsService;
use App\Support\AppError;
use Illuminate\Support\Collection;

/**
 * The cost sheet of one trip (`trip_costs`): fuel, driver time and overtime, maintenance and depreciation allocated by
 * distance, tolls / parking / third party / other from the vouchers linked to the trip.
 *
 * compute() is called when a trip is closed (TripsService::close), whenever a fact linked to the trip changes (fuel
 * record, expense voucher — through FleetPosting::rebuildTripCosts) and on demand; it is idempotent, never invents a
 * figure (a component with no data source is 0 and says `not_configured` in `basis`), and never throws on missing
 * master data — closing a trip does not depend on costing.
 *
 * Trip costing is management accounting: it posts NO journal. Fuel records, maintenance orders and expense vouchers
 * have already posted theirs; driver time, the maintenance share and depreciation are allocations of costs the ledger
 * carries elsewhere (payroll, maintenance expense, fixed-asset depreciation).
 *
 * `basis` (json) — one entry per component: `{ source, detail }`
 *   source: actual        measured facts linked to the trip (fuel records, vouchers, actual hours × the hourly cost)
 *           estimated     derived from the vehicle's own history (fuel: km ÷ km/L × last price per litre)
 *           allocated     a rate per km applied to the trip's distance (maintenance share, depreciation)
 *           not_configured no data source — the figure is 0 and `detail.reason` says what is missing
 *   detail.reason: no_vehicle | no_driver | no_km | no_times | no_rate | no_history | no_account_role
 *
 * @see docs/ops-hardening/DECISIONS.ar.md (الأسطول ١)
 */
class TripCostService
{
    public const FIELDS = ['fuel', 'driver', 'ot', 'maint', 'dep', 'tolls', 'parking', 'third', 'other'];

    /** The maintenance rate per km comes from the vehicle's own last N days … */
    public const HISTORY_DAYS = 365;

    /** … when its closed trips of that window cover at least this distance; otherwise from `fleet.maintCostPerKm`. */
    public const HISTORY_MIN_KM = 500;

    /** A fuel estimate needs at least this many (non-voided) fuel records of the vehicle. */
    public const FUEL_MIN_RECORDS = 2;

    public function __construct(private readonly SettingsService $settings) {}

    /** @return array the cost sheet row as an array (camelCase), including `basis`, `computedAt` and `total` */
    public function compute(Trip|string $trip): array
    {
        $key = $trip instanceof Trip ? $trip->id : $trip;
        // always the stored trip: a caller in the middle of closing may hold an instance from before its own update
        $t = Trip::where('id', $key)->orWhere('number', $key)->first()
            ?? throw AppError::notFound('TRIP_NOT_FOUND', "الرحلة {$key} غير موجودة", "Trip {$key} not found");

        $vehicle = $t->vehicle_id ? Vehicle::find($t->vehicle_id) : null;
        $driver = $t->driver_id ? Driver::find($t->driver_id) : null;
        $km = max(0.0, (float) ($t->km ?? 0));
        $vouchers = Expense::where('trip_id', $t->id)->where('status', 'posted')->with('account:id,role')->get();

        $values = [];
        $basis = [];
        [$values['fuel'], $basis['fuel']] = $this->fuel($t, $vehicle, $km);
        [$values['driver'], $basis['driver'], $values['ot'], $basis['ot']] = $this->driverTime($t, $driver);
        [$values['maint'], $basis['maint']] = $this->maintenance($t, $vehicle, $km, $vouchers);
        [$values['dep'], $basis['dep']] = $this->depreciation($vehicle, $km);
        $values += $this->voucherValues($vouchers, $basis);

        $row = TripCost::updateOrCreate(['trip_id' => $t->id], $values + ['computed_at' => now(), 'basis' => $basis]);

        return $row->refresh()->toArray() + ['total' => round(array_sum($values), 2)];
    }

    /**
     * Fuel: the non-voided fuel records linked to the trip (actual); with none, the vehicle's own consumption applied to
     * the trip's distance (estimated) when the vehicle has a fuel history.
     *
     * @return array{0: float, 1: array}
     */
    private function fuel(Trip $t, ?Vehicle $v, float $km): array
    {
        $linked = FuelRecord::where('trip_id', $t->id)->whereNull('voided_at')->orderBy('date')->orderBy('id')->get();
        if ($linked->isNotEmpty()) {
            return [round((float) $linked->sum('cost'), 2), self::basis('actual', [
                'records' => $linked->count(), 'liters' => round((float) $linked->sum('liters'), 2), 'numbers' => $linked->pluck('number')->take(10)->values()->all(),
            ])];
        }
        if (! $v) {
            return [0.0, self::missing('no_vehicle')];
        }
        if ($km <= 0) {
            return [0.0, self::missing('no_km')];
        }
        $history = FuelRecord::where('vehicle_id', $v->id)->whereNull('voided_at')->orderBy('date')->orderBy('created_at')->orderBy('id')->get();
        if ($history->count() < self::FUEL_MIN_RECORDS) {
            return [0.0, self::missing('no_history', ['records' => $history->count(), 'needed' => self::FUEL_MIN_RECORDS])];
        }
        // litre-weighted consumption of the fills that measured a distance; the vehicle card's average when none did
        $measured = $history->filter(fn (FuelRecord $r) => $r->km_per_l > 0 && $r->liters > 0);
        $liters = (float) $measured->sum('liters');
        $kmPerL = $liters > 0 ? $measured->sum(fn (FuelRecord $r) => $r->km_per_l * $r->liters) / $liters : (float) $v->avg_km_l;
        $last = $history->last(fn (FuelRecord $r) => $r->liters > 0 && $r->cost > 0);
        if ($kmPerL <= 0 || ! $last) {
            return [0.0, self::missing('no_history', ['records' => $history->count(), 'needed' => self::FUEL_MIN_RECORDS])];
        }
        $price = $last->cost / $last->liters;

        return [round($km / $kmPerL * $price, 2), self::basis('estimated', [
            'km' => $km, 'kmPerL' => round($kmPerL, 2), 'kmPerLSource' => $liters > 0 ? 'records' : 'vehicle', 'pricePerL' => round($price, 3),
            'priceFrom' => $last->number, 'records' => $history->count(),
        ])];
    }

    /**
     * Driver time: actual hours (actual_end − actual_start) × the driver's hourly cost (or `fleet.driverHourlyCost`);
     * the hours beyond `fleet.overtimeAfterHours` are costed × `fleet.overtimeMultiplier`.
     *
     * @return array{0: float, 1: array, 2: float, 3: array} driver, its basis, overtime, its basis
     */
    private function driverTime(Trip $t, ?Driver $d): array
    {
        if (! $d) {
            return [0.0, self::missing('no_driver'), 0.0, self::missing('no_driver')];
        }
        $own = $d->hourly_cost !== null;
        $rate = max(0.0, (float) ($own ? $d->hourly_cost : $this->setting('fleet.driverHourlyCost')));
        $rateSource = $own ? 'driver' : 'policy';
        if ($rate <= 0) {
            return [0.0, self::missing('no_rate', ['rateSource' => $rateSource]), 0.0, self::missing('no_rate', ['rateSource' => $rateSource])];
        }
        $hours = $t->actual_start && $t->actual_end ? max(0.0, ($t->actual_end->getTimestamp() - $t->actual_start->getTimestamp()) / 3600) : 0.0;
        if ($hours <= 0) {
            $why = ['rate' => $rate, 'rateSource' => $rateSource];

            return [0.0, self::missing('no_times', $why), 0.0, self::missing('no_times', $why)];
        }
        $after = max(0.0, $this->setting('fleet.overtimeAfterHours'));
        $multiplier = max(0.0, $this->setting('fleet.overtimeMultiplier'));
        $regular = min($hours, $after);
        $extra = max(0.0, $hours - $after);

        return [
            round($regular * $rate, 2),
            self::basis('actual', ['hours' => round($regular, 2), 'tripHours' => round($hours, 2), 'rate' => $rate, 'rateSource' => $rateSource]),
            round($extra * $rate * $multiplier, 2),
            self::basis('actual', ['hours' => round($extra, 2), 'afterHours' => $after, 'rate' => $rate, 'multiplier' => $multiplier, 'rateSource' => $rateSource]),
        ];
    }

    /**
     * Maintenance: the maintenance vouchers charged to the trip (actual) + distance × a rate per km. The rate is the
     * vehicle's own closed maintenance cost of the last HISTORY_DAYS ÷ the km of its closed trips in that window when
     * that distance reaches HISTORY_MIN_KM, otherwise `fleet.maintCostPerKm`.
     *
     * @param  Collection<int, Expense>  $vouchers
     * @return array{0: float, 1: array}
     */
    private function maintenance(Trip $t, ?Vehicle $v, float $km, Collection $vouchers): array
    {
        $direct = self::sumByRole($vouchers, ['maintenance']);
        $detail = ['direct' => $direct, 'allocated' => 0.0, 'km' => $km];
        $reason = ! $v ? 'no_vehicle' : ($km <= 0 ? 'no_km' : null);
        if ($reason) {
            return [$direct, $direct > 0 ? self::basis('actual', $detail + ['reason' => $reason]) : self::missing($reason, $detail)];
        }
        // the window ends when the trip ended (a recomputation later gives the same figure); bounds keep their milliseconds
        $end = $t->actual_end ?? $t->closed_at ?? now();
        $until = $end->format('Y-m-d H:i:s.v');
        $from = $end->copy()->subDays(self::HISTORY_DAYS)->format('Y-m-d H:i:s.v');
        $historyKm = (float) Trip::where('vehicle_id', $v->id)->where('status', 'closed')->where('closed_at', '>=', $from)->where('closed_at', '<=', $until)->sum('km');
        if ($historyKm >= self::HISTORY_MIN_KM) {
            $historyCost = (float) MaintenanceOrder::where('vehicle_id', $v->id)->where('status', 'closed')->where('end_date', '>=', $from)->where('end_date', '<=', $until)->sum('cost');
            $rate = $historyCost / $historyKm;
            $detail += ['rateSource' => 'history', 'historyKm' => round($historyKm, 1), 'historyCost' => round($historyCost, 2), 'historyDays' => self::HISTORY_DAYS];
        } else {
            $rate = max(0.0, $this->setting('fleet.maintCostPerKm'));
            $detail += ['rateSource' => 'policy', 'historyKm' => round($historyKm, 1), 'historyMinKm' => self::HISTORY_MIN_KM];
        }
        $allocated = round($km * $rate, 2);
        $detail = ['allocated' => $allocated, 'ratePerKm' => round($rate, 4)] + $detail;
        $value = round($direct + $allocated, 2);
        if ($rate > 0 || $detail['rateSource'] === 'history') {
            return [$value, self::basis('allocated', $detail)];
        }

        return [$value, $direct > 0 ? self::basis('actual', $detail + ['reason' => 'no_rate']) : self::missing('no_rate', $detail)];
    }

    /**
     * Depreciation by distance: (purchase cost − salvage value) ÷ useful life in km when the vehicle card has them,
     * otherwise `fleet.depreciationPerKm`.
     *
     * @return array{0: float, 1: array}
     */
    private function depreciation(?Vehicle $v, float $km): array
    {
        if (! $v) {
            return [0.0, self::missing('no_vehicle')];
        }
        if ($km <= 0) {
            return [0.0, self::missing('no_km')];
        }
        $purchase = $v->purchase_cost !== null ? (float) $v->purchase_cost : 0.0;
        $life = (int) ($v->life_km ?? 0);
        if ($purchase > 0 && $life > 0) {
            $salvage = max(0.0, (float) ($v->salvage_value ?? 0));
            $rate = max(0.0, $purchase - $salvage) / $life;
            $detail = ['rateSource' => 'vehicle', 'purchaseCost' => $purchase, 'salvageValue' => $salvage, 'lifeKm' => $life];
        } else {
            $rate = max(0.0, $this->setting('fleet.depreciationPerKm'));
            $detail = ['rateSource' => 'policy'];
        }
        $detail = ['km' => $km, 'ratePerKm' => round($rate, 4)] + $detail;
        if ($rate <= 0) {
            return [0.0, self::missing('no_rate', $detail)];
        }

        return [round($km * $rate, 2), self::basis('allocated', $detail)];
    }

    /**
     * Posted expense vouchers linked to the trip, by the role of their account: `fleet_other` (tolls, parking and other
     * fleet costs — one account in the chart) → tolls; `maintenance` is counted by maintenance(); everything else → other.
     * The chart has no account role that tells parking or third-party transport apart, so those two stay 0 and say so.
     *
     * @param  Collection<int, Expense>  $vouchers
     * @return array{tolls: float, parking: float, third: float, other: float}
     */
    private function voucherValues(Collection $vouchers, array &$basis): array
    {
        $tolls = self::sumByRole($vouchers, ['fleet_other']);
        $maint = self::sumByRole($vouchers, ['maintenance']);
        $other = max(0.0, round((float) $vouchers->sum('total') - $tolls - $maint, 2));
        $count = fn (callable $test) => $vouchers->filter(fn (Expense $e) => $test($e->account?->role))->count();
        $basis['tolls'] = self::basis('actual', ['vouchers' => $count(fn ($role) => $role === 'fleet_other'), 'accountRole' => 'fleet_other']);
        $basis['parking'] = self::missing('no_account_role', ['includedIn' => 'tolls']);
        $basis['third'] = self::missing('no_account_role', ['includedIn' => 'other']);
        $basis['other'] = self::basis('actual', ['vouchers' => $count(fn ($role) => ! in_array($role, ['fleet_other', 'maintenance'], true))]);

        return ['tolls' => $tolls, 'parking' => 0.0, 'third' => 0.0, 'other' => $other];
    }

    /** @param  Collection<int, Expense>  $vouchers */
    private static function sumByRole(Collection $vouchers, array $roles): float
    {
        return round((float) $vouchers->filter(fn (Expense $e) => in_array($e->account?->role, $roles, true))->sum('total'), 2);
    }

    private function setting(string $key): float
    {
        return (float) ($this->settings->get($key) ?? 0);
    }

    private static function basis(string $source, array $detail = []): array
    {
        return ['source' => $source, 'detail' => $detail];
    }

    private static function missing(string $reason, array $detail = []): array
    {
        return self::basis('not_configured', ['reason' => $reason] + $detail);
    }
}
