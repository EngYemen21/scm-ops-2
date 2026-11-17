<?php

namespace App\Services\Fulfillment;

use App\Integration\Services\OrderEvents;
use App\Models\Carton;
use App\Models\FoLine;
use App\Models\FulfillmentOrder;
use App\Models\SalesOrder;
use App\Models\Trip;
use App\Models\TripEvent;
use App\Models\TripOrder;
use App\Models\TripStop;
use App\Services\Core\AuditService;
use App\Services\Core\SettingsService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Str;

/**
 * What picking, packing and loading share: document lookups with the lock order every fulfilment mutation follows
 * (sales order → fulfilment order → pick list → pick task → balance rows — the same order SalesService::cancelOrder
 * takes, so two people working on one order wait for each other instead of dead-locking), the trip side of an order
 * that stops being shippable, carton rows, and the shortage lines reported to the system an order came from.
 */
class FulfillmentSupport
{
    /** Trip states in which orders can still be loaded, unloaded or taken off the trip. */
    public const LOADABLE = ['planned', 'vassigned', 'dassigned', 'loading', 'ready'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly SettingsService $settings,
    ) {}

    public function findFo(string $idOrNumber): FulfillmentOrder
    {
        return FulfillmentOrder::where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first()
            ?? throw AppError::notFound('FO_NOT_FOUND', 'أمر التنفيذ غير موجود', 'Fulfillment order not found');
    }

    /**
     * Locks the sales order (when there is one) and then the fulfilment order, in that order. Needs a transaction.
     *
     * @return array{0: ?SalesOrder, 1: FulfillmentOrder}
     */
    public function lockOrder(string $foId): array
    {
        $soId = FulfillmentOrder::whereKey($foId)->value('so_id');
        $so = $soId ? SalesOrder::whereKey($soId)->lockForUpdate()->first() : null;
        $fo = FulfillmentOrder::whereKey($foId)->lockForUpdate()->firstOrFail();

        return [$so, $fo];
    }

    public function findTrip(string $idOrNumber, array $with = []): Trip
    {
        return Trip::with($with)->where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first()
            ?? throw AppError::notFound('TRIP_NOT_FOUND', 'الرحلة غير موجودة', 'Trip not found');
    }

    /** Volume of an order: what packing recorded, else cartons × the configured fallback. */
    public function cbmOf(FulfillmentOrder $fo): float
    {
        return $fo->cbm ?: round(($fo->cartons ?: 0) * (float) $this->settings->get('loading.cbmPerCarton') * 10) / 10;
    }

    /** Trip status by what is assigned — the rule of TripsService::statusFor. */
    public static function tripStatusFor(?string $vehicleId, ?string $driverId): string
    {
        return $vehicleId && $driverId ? 'dassigned' : ($vehicleId ? 'vassigned' : ($driverId ? 'dassigned' : 'planned'));
    }

    /** Totals of a trip from the orders that are on it (the figures TripsService::create derives). */
    public function recomputeTrip(string $tripId): array
    {
        $fos = FulfillmentOrder::whereIn('id', TripOrder::where('trip_id', $tripId)->select('fo_id'))->get();
        $totals = [
            'kg' => round($fos->sum(fn ($f) => (float) ($f->weight_kg ?: 0)) * 10) / 10,
            'cbm' => round($fos->sum(fn ($f) => (float) ($f->cbm ?: 0)) * 10) / 10,
            'pallets' => (int) $fos->sum(fn ($f) => max(1, (int) ceil(($f->cartons ?: 0) / 8))),
        ];
        Trip::whereKey($tripId)->update($totals + ['updated_at' => now()]);
        foreach ($fos as $f) {
            TripStop::where('trip_id', $tripId)->where('fo_id', $f->id)->update(['kg' => (float) ($f->weight_kg ?: 0)]);
        }

        return $totals;
    }

    /**
     * An order that will not ship on its trip (cancelled after a put-back, or nothing could be picked) leaves it: the
     * trip order goes, its stop is skipped and the trip totals are recomputed. Only while the trip has not left.
     * Returns the trip number, or null when the order was on no open trip.
     */
    public function detachFromTrip(?AuthUser $user, FulfillmentOrder $fo, string $why, ?string $txId = null): ?string
    {
        if (! $fo->trip_id) {
            return null;
        }
        $trip = Trip::whereKey($fo->trip_id)->lockForUpdate()->first();
        $fo->update(['trip_id' => null]);
        if (! $trip || ! in_array($trip->status, self::LOADABLE, true)) {
            return null;
        }
        TripOrder::where('trip_id', $trip->id)->where('fo_id', $fo->id)->delete();
        TripStop::where('trip_id', $trip->id)->where('fo_id', $fo->id)->where('status', 'pending')->update(['status' => 'skipped', 'note' => $why]);
        $this->recomputeTrip($trip->id);
        TripEvent::create(['trip_id' => $trip->id, 'label' => now()->format('H:i'), 'text_ar' => "خرج {$fo->number} من الرحلة — {$why}", 'text_en' => "{$fo->number} left the trip"]);
        $this->audit->log($user, ['action' => 'TRIP.REMOVE_ORDER', 'entityType' => 'Trip', 'entityId' => $trip->id, 'entityNumber' => $trip->number,
            'field' => 'order', 'oldValue' => $fo->number, 'newValue' => $why, 'transactionId' => $txId]);

        return $trip->number;
    }

    /**
     * One carton row per carton of a package: code `{FO number}-C{seq}` (two digits at least), weights as given or the
     * total divided evenly. Returns the rows as the API serves them.
     *
     * @param  float[]|null  $weights  one weight per carton, or null
     * @return array<int, array{code:string, seq:int, weightKg:?float, status:string}>
     */
    public function issueCartons(FulfillmentOrder $fo, string $packageId, int $count, ?float $totalKg, ?array $weights): array
    {
        $even = $totalKg !== null && $count > 0 ? round($totalKg / $count, 3) : null;
        $now = now()->format('Y-m-d H:i:s.v');
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'id' => strtolower((string) Str::ulid()), 'code' => $fo->number.'-C'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'fo_id' => $fo->id, 'package_id' => $packageId,
                'seq' => $i, 'weight_kg' => $weights !== null ? round((float) $weights[$i - 1], 3) : $even, 'status' => 'packed', 'created_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Carton::insert($chunk);
        }

        return array_map(fn ($r) => ['code' => $r['code'], 'seq' => $r['seq'], 'weightKg' => $r['weight_kg'], 'status' => 'packed'], $rows);
    }

    /**
     * Voids the live cartons of an order. A void carton gives its code back (the next packing prints C01 … again), so
     * the row keeps its history under a code no label carries.
     */
    public function voidCartons(string $foId): int
    {
        $live = Carton::where('fo_id', $foId)->where('status', '!=', 'void')->get(['id', 'code']);
        foreach ($live as $c) {
            Carton::whereKey($c->id)->update(['status' => 'void', 'code' => $c->code.'~'.mb_strtolower(substr($c->id, -8))]);
        }

        return $live->count();
    }

    /**
     * Validated carton weights of a pack / package-edit request, or null when none were sent.
     *
     * @return float[]|null
     */
    public static function cartonWeights(array $dto, int $cartons): ?array
    {
        $weights = $dto['cartonWeights'] ?? null;
        if (! is_array($weights) || $weights === []) {
            return null;
        }
        $weights = array_values($weights);
        if (count($weights) !== $cartons) {
            throw AppError::validation('CARTON_WEIGHTS', 'عدد أوزان الكراتين ('.count($weights).") لا يساوي عدد الكراتين ({$cartons})", 'Carton weights must list one weight per carton');
        }
        foreach ($weights as $w) {
            if (! is_numeric($w) || (float) $w <= 0) {
                throw AppError::validation('CARTON_WEIGHTS', 'وزن كل كرتون يجب أن يكون أكبر من صفر', 'Every carton weight must be greater than zero');
            }
        }

        return array_map('floatval', $weights);
    }

    /**
     * What the order really got, line by line — the payload of `order.short` / `picking.completed` for the system the
     * order came from: `ordered` is what was asked for on this fulfilment order, `short` what could not be picked.
     *
     * @return array<int, array{sku:?string, ordered:int, picked:int, short:int}>
     */
    public function shortLines(string $foId): array
    {
        return FoLine::with('product:id,sku')->where('fo_id', $foId)->orderBy('line_no')->get()
            ->map(fn (FoLine $l) => ['sku' => $l->product?->sku, 'ordered' => $l->qty + $l->short_qty, 'picked' => $l->picked_qty, 'short' => $l->short_qty])->all();
    }

    /** Lifecycle event for an order another system sent (does nothing for our own orders). */
    public function emit(?string $soId, string $type, array $data = []): void
    {
        app(OrderEvents::class)->emit($soId, $type, $data);
    }

    /** 3000.5 → "3,000.5" (JS toLocaleString('en-US')). */
    public static function fmt(float $n): string
    {
        $s = number_format($n, 3, '.', ',');

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
