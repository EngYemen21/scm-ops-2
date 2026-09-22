<?php

namespace App\Services\Delivery;

use App\Models\DeliveryRecord;
use App\Models\Driver;
use App\Models\FoLine;
use App\Models\FulfillmentOrder;
use App\Models\PodAttachment;
use App\Models\ProofOfDelivery;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Trip;
use App\Models\TripEvent;
use App\Models\TripStop;
use App\Models\Warehouse;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Services\Exceptions\ExceptionsService;
use App\Services\Integrations\Adapters\AdapterFactory;
use App\Services\Returns\ReturnsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Driver workflow + POD: the driver sees only their own trips; "arrived" is required before any POD; a stop accepts
 * exactly one POD (unique stop_id) so a duplicate POD is impossible; delivered / partial (remaining → return) /
 * customer rejected / unable to deliver (structured reason → exception). Signature / photo are stored as attachment
 * references with status `integration_pending` until object storage is connected — never claimed as uploaded.
 */
class DeliveryService
{
    private const DRIVER_TRIP_STATUSES = ['dassigned', 'vassigned', 'loading', 'ready', 'dispatched', 'onroute', 'partial', 'completed', 'returning'];

    private const CLOSED_STOP_STATUSES = ['delivered', 'partial', 'failed', 'rejected', 'skipped'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
        private readonly SettingsService $settings,
        private readonly ExceptionsService $exceptions,
        private readonly ReturnsService $returns,
    ) {}

    /** Trips visible to a driver user (only theirs); a dispatcher / super user may pass a driver code. */
    public function myTrips(AuthUser $user, ?string $driverCode = null): array
    {
        $driverId = $user->driverId ?: null;
        if (! $driverId || ($driverCode && $user->can('trip.manage'))) {
            $driver = $driverCode ? Driver::where('code', $driverCode)->first() : null;
            if ($driver) {
                $driverId = $driver->id;
            }
        }
        if (! $driverId) {
            throw AppError::forbidden('NOT_A_DRIVER', 'الحساب غير مرتبط بسائق', 'User is not linked to a driver');
        }
        $trips = Trip::with($this->tripWith())->where('driver_id', $driverId)->whereIn('status', self::DRIVER_TRIP_STATUSES)
            ->orderBy('status')->orderByDesc('date')->orderByDesc('id')->get();
        $current = $trips->first(fn (Trip $t) => in_array($t->status, ['onroute', 'partial', 'completed', 'returning'], true)) ?? $trips->first();

        return [
            'current' => $current ? $this->decorate($current) : null,
            'upcoming' => $trips->reject(fn (Trip $t) => $t->id === $current?->id)->map(fn (Trip $t) => $this->decorate($t))->values()->all(),
            'gps' => AdapterFactory::gps()->configured() ? ['status' => 'connected', 'note' => 'تتبع المركبة الحي مربوط بمزود Telematics'] : ['status' => 'integration_pending', 'note' => 'تتبع المركبة الحي يتطلب ربط مزود Telematics — غير متصل'],
        ];
    }

    public function trip(AuthUser $user, string $idOrNumber): array
    {
        return $this->decorate($this->findTrip($user, $idOrNumber));
    }

    /** @return array{ok:bool} */
    public function start(AuthUser $user, string $idOrNumber): array
    {
        $t = $this->findTrip($user, $idOrNumber);
        if ($t->status !== 'onroute') {
            throw AppError::rule('TRIP_NOT_DISPATCHED', 'لا يمكن بدء الرحلة قبل Dispatch من المستودع', 'Trip not dispatched yet');
        }

        return DB::transaction(function () use ($user, $t) {
            Trip::whereKey($t->id)->lockForUpdate()->first();
            if (TripEvent::where('trip_id', $t->id)->where('label', 'START')->exists()) {
                throw AppError::conflict('TRIP_STARTED', 'الرحلة بدأت مسبقًا');
            }
            TripEvent::create(['trip_id' => $t->id, 'at' => now(), 'label' => 'START',
                'text_ar' => 'بدء الرحلة من تطبيق السائق · ETA يتطلب ربط الخرائط (Integration Pending)', 'text_en' => 'Trip started by driver']);
            $this->audit->log($user, ['action' => 'TRIP.START', 'entityType' => 'Trip', 'entityId' => $t->id, 'entityNumber' => $t->number]);

            return ['ok' => true];
        });
    }

    /**
     * @param  ?array{lat:float|int, lng:float|int, accuracy?:float|int|null}  $gps
     * @return array{ok:bool, stop:int}
     */
    public function arrive(AuthUser $user, string $stopId, ?array $gps = null): array
    {
        $this->stopFor($user, $stopId);

        return DB::transaction(function () use ($user, $stopId, $gps) {
            $s = TripStop::with('trip')->whereKey($stopId)->lockForUpdate()->firstOrFail();
            if (! in_array($s->trip->status, ['onroute', 'partial'], true)) {
                throw AppError::rule('TRIP_STATE', 'الرحلة ليست في الطريق', 'Trip is not on route');
            }
            if ($s->status !== 'pending') {
                throw AppError::conflict('STOP_STATE', 'الوقفة '.($s->status === 'arrived' ? 'مسجَّلة الوصول مسبقًا' : 'مُقفلة'), 'Stop is not pending');
            }
            $now = now();
            $s->update(['status' => 'arrived', 'arrived_at' => $now, 'actual_time' => $now->format('H:i')]);
            TripEvent::create(['trip_id' => $s->trip_id, 'at' => $now, 'label' => $now->format('H:i'),
                'text_ar' => "الوصول للمحطة {$s->seq} — {$s->customer_ar}".($gps ? " · GPS {$gps['lat']},{$gps['lng']}" : ''), 'text_en' => "Arrived at stop {$s->seq}"]);
            $this->audit->status($user, 'TripStop', $s->id, "{$s->trip->number}#{$s->seq}", 'pending', 'arrived');

            return ['ok' => true, 'stop' => $s->seq];
        });
    }

    public function deliver(AuthUser $user, string $stopId, array $dto): array
    {
        return $this->closeStop($user, $stopId, 'delivered', $dto);
    }

    public function partial(AuthUser $user, string $stopId, array $dto): array
    {
        return $this->closeStop($user, $stopId, 'partial', $dto);
    }

    /** @param  array{reason:string, notes?:?string}  $dto */
    public function fail(AuthUser $user, string $stopId, array $dto): array
    {
        return $this->closeStop($user, $stopId, $dto['reason'] === 'rejected' ? 'rejected' : 'failed', ['failReason' => $dto['reason'], 'notes' => $dto['notes'] ?? null]);
    }

    /** @param  array{trip?:?string, fo?:?string, from?:?string, to?:?string}  $filters */
    public function pods(Paging $page, array $filters): array
    {
        $query = ProofOfDelivery::with(['trip:id,number', 'fo:id,number', 'driver:id,code,name_ar', 'vehicle:id,code', 'customer:id,name_ar', 'attachments']);
        if (! empty($filters['trip'])) {
            $query->whereHas('trip', fn ($t) => $t->where('number', $filters['trip']));
        }
        if (! empty($filters['fo'])) {
            $query->whereHas('fo', fn ($f) => $f->where('number', $filters['fo']));
        }
        if (! empty($filters['from'])) {
            $query->where('at', '>=', Carbon::parse($filters['from']));
        }
        if (! empty($filters['to'])) {
            $query->where('at', '<=', Carbon::parse($filters['to']));
        }

        return $page->paginate($query->orderByDesc('at')->orderByDesc('id'));
    }

    public function pod(string $idOrNumber): ProofOfDelivery
    {
        return ProofOfDelivery::with(['trip:id,number', 'fo:id,number,so_id', 'stop', 'driver', 'vehicle', 'customer', 'attachments'])
            ->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('POD_NOT_FOUND', 'POD غير موجود');
    }

    // ───────────── internals ─────────────

    /**
     * Closes a stop with exactly one POD. Partial / failed / rejected send the remainder back through an already
     * approved return (the goods are on the truck) and raise an exception for dispatch.
     *
     * @param  array{receiverName?:?string, notes?:?string, gps?:?array, gpsStatus?:?string, signature?:?string, photo?:?string, deliveredQty?:?int, failReason?:?string}  $dto
     */
    private function closeStop(AuthUser $user, string $stopId, string $result, array $dto): array
    {
        $this->stopFor($user, $stopId);
        $autoReturn = (bool) $this->settings->get('delivery.autoReturnOnPartial');

        return DB::transaction(function () use ($user, $stopId, $result, $dto, $autoReturn) {
            // The stop row is locked: two simultaneous PODs for one stop are serialised and the second is a POD_DUPLICATE.
            $s = TripStop::with(['trip', 'fo.lines.product', 'fo.customer', 'pod'])->whereKey($stopId)->lockForUpdate()->firstOrFail();
            $failed = $result === 'failed' || $result === 'rejected';
            if (! in_array($s->trip->status, ['onroute', 'partial'], true)) {
                throw AppError::rule('TRIP_STATE', 'الوقفة ليست في الطريق — لا POD مكرر', 'Trip not on route');
            }
            if ($s->pod) {
                throw AppError::conflict('POD_DUPLICATE', "الوقفة مُقفلة ({$s->status}) — POD مكرر مرفوض", 'Duplicate POD rejected');
            }
            if ($s->status !== 'arrived') {
                throw AppError::rule('ARRIVE_FIRST', $s->status === 'pending' ? 'سجّل الوصول أولًا' : "الوقفة مُقفلة ({$s->status}) — POD مكرر مرفوض", 'Record arrival first');
            }
            $fo = $s->fo ?? throw AppError::rule('NO_FO', 'الوقفة بلا أمر تنفيذ');
            $receiver = trim((string) ($dto['receiverName'] ?? ''));
            if (! $failed && $receiver === '') {
                throw AppError::validation('RECEIVER_REQUIRED', 'اكتب اسم المستلم أولًا — POD إلزامي', 'Receiver name is required');
            }
            $total = (int) $fo->lines->sum('qty');
            $delivered = $total;
            $returned = 0;
            if ($result === 'partial') {
                $q = (int) ($dto['deliveredQty'] ?? 0);
                if (! ($q > 0 && $q < $total)) {
                    $max = $total - 1;
                    throw AppError::validation('PARTIAL_QTY', "أدخل كمية مسلَّمة بين 1 و{$max} للتسليم الجزئي", "Delivered quantity must be between 1 and {$max}");
                }
                $delivered = $q;
                $returned = $total - $q;
            }
            $failReason = $dto['failReason'] ?? null;
            if ($failed) {
                $delivered = 0;
                $returned = $total;
                if (! $failReason) {
                    throw AppError::validation('REASON_REQUIRED', 'اختر سبب الفشل — الأسباب Structured إلزامية', 'A structured failure reason is required');
                }
            }
            $gps = $dto['gps'] ?? null;
            $notes = $dto['notes'] ?? null;
            $now = now();
            $stopRef = "{$s->trip->number}#{$s->seq}";

            $delivery = DeliveryRecord::create(['trip_id' => $s->trip_id, 'stop_id' => $s->id, 'fo_id' => $fo->id, 'driver_id' => $s->trip->driver_id, 'vehicle_id' => $s->trip->vehicle_id,
                'result' => $result, 'delivered_qty' => $delivered, 'returned_qty' => $returned, 'fail_reason' => $failReason, 'arrived_at' => $s->arrived_at, 'completed_at' => $now]);
            $gpsStatus = $gps ? 'captured' : (($dto['gpsStatus'] ?? null) ?: 'unavailable');
            $pod = ProofOfDelivery::create(['number' => $this->numbering->next('POD'), 'delivery_id' => $delivery->id, 'trip_id' => $s->trip_id, 'stop_id' => $s->id, 'fo_id' => $fo->id,
                'driver_id' => $s->trip->driver_id, 'vehicle_id' => $s->trip->vehicle_id, 'customer_id' => $s->customer_id ?: $fo->customer_id, 'result' => $result,
                'receiver_name' => $receiver !== '' ? $receiver : null, 'delivered_qty' => $delivered, 'returned_qty' => $returned,
                'gps_lat' => $gps['lat'] ?? null, 'gps_lng' => $gps['lng'] ?? null, 'gps_accuracy' => $gps['accuracy'] ?? null, 'gps_status' => $gpsStatus,
                'notes' => $notes, 'fail_reason' => $failReason, 'at' => $now, 'created_by_id' => $user->id]);
            // Honest attachment status: nothing is uploaded anywhere until object storage is connected.
            foreach (['signature' => $dto['signature'] ?? null, 'photo' => $dto['photo'] ?? null] as $kind => $ref) {
                if ($ref) {
                    PodAttachment::create(['pod_id' => $pod->id, 'kind' => $kind, 'file_name' => mb_strlen($ref) > 200 ? "{$kind}-{$pod->number}.png" : $ref, 'status' => 'integration_pending']);
                }
            }
            $stopStatus = $result;
            $s->update(['status' => $stopStatus, 'completed_at' => $now, 'fail_reason' => $failReason, 'actual_time' => $now->format('H:i')]);
            $foStatus = $failed ? 'failed' : $result;
            FulfillmentOrder::whereKey($fo->id)->update(['status' => $foStatus, 'delivered_at' => $now]);
            $lineDelivered = fn (FoLine $l) => $result === 'delivered' ? $l->qty : ($result === 'partial' ? (int) round(($l->qty / $total) * $delivered) : 0);
            foreach ($fo->lines as $l) {
                $dq = $lineDelivered($l);
                $l->update(['delivered_qty' => $dq, 'returned_qty' => $l->qty - $dq]);
                if ($l->so_line_id) {
                    SalesOrderLine::whereKey($l->so_line_id)->update(['delivered_qty' => $dq, 'returned_qty' => $l->qty - $dq]);
                }
            }
            if ($fo->so_id) {
                SalesOrder::whereKey($fo->so_id)->update(['status' => $foStatus]);
            }
            $this->audit->status($user, 'TripStop', $s->id, $stopRef, 'arrived', $stopStatus, $pod->number);
            $this->audit->status($user, 'FulfillmentOrder', $fo->id, $fo->number, 'onroute', $foStatus, $pod->number);
            $this->audit->log($user, ['action' => 'POD.CREATE', 'entityType' => 'ProofOfDelivery', 'entityId' => $pod->id, 'entityNumber' => $pod->number,
                'newValue' => ['result' => $result, 'delivered' => $delivered, 'returned' => $returned, 'receiver' => $dto['receiverName'] ?? null, 'gps' => $gpsStatus]]);

            $rtn = null;
            if ($returned > 0 && ($result !== 'partial' || $autoReturn)) {
                $parts = $fo->lines->map(fn (FoLine $l) => ['sku' => $l->product->sku, 'qty' => $l->qty - $lineDelivered($l)])->filter(fn ($p) => $p['qty'] > 0)->values()->all();
                $wh = Warehouse::findOrFail($fo->warehouse_id);
                // goods are physically on the truck coming back → the return is created already approved (awaiting receipt at the warehouse)
                $rtn = $this->returns->create($user, [
                    'type' => $result === 'failed' ? 'del' : 'cust', 'source' => $fo->customer?->name_ar ?: $s->customer_ar, 'reference' => $fo->number,
                    'customerCode' => $fo->customer?->code, 'foNumber' => $fo->number, 'tripNumber' => $s->trip->number, 'warehouseCode' => $wh->code,
                    'reasonCode' => $result === 'partial' ? 'partial' : ($result === 'rejected' ? 'cust_reject' : 'del_fail'), 'notes' => $notes, 'lines' => $parts,
                ], 'approved')->number;
            }
            $excBase = ['ownerRole' => 'disp', 'entityType' => 'FulfillmentOrder', 'entityId' => $fo->id, 'entityNumber' => $fo->number,
                'documentType' => 'Trip', 'documentId' => $s->trip_id, 'documentNumber' => $s->trip->number];
            $reasonAr = config("scm.FAIL_REASON_LABELS.{$failReason}.ar");
            $reasonEn = config("scm.FAIL_REASON_LABELS.{$failReason}.en");
            if ($result === 'partial') {
                $this->exceptions->raise($user, $excBase + ['kind' => 'partial', 'severity' => 'w',
                    'textAr' => "تسليم جزئي {$fo->number} — سُلّم {$delivered} من {$total}، {$returned} يعود للمستودع".($rtn ? " عبر {$rtn}" : ''), 'textEn' => "Partial delivery {$fo->number}"]);
            }
            if ($failed) {
                $this->exceptions->raise($user, $excBase + ['kind' => 'faildel', 'severity' => 'c',
                    'textAr' => "فشل تسليم {$fo->number} — {$s->customer_ar} · السبب: ".($reasonAr ?: $failReason).' · البضاعة تعود للمستودع للفحص'.($rtn ? " ({$rtn})" : ''),
                    'textEn' => "Failed delivery {$fo->number} — ".($reasonEn ?: $failReason)]);
            }
            $eventAr = $result === 'delivered' ? 'مسلّمة · POD ✓' : ($result === 'partial' ? "تسليم جزئي {$delivered}/{$total} · POD" : 'فشل تسليم · Exception');
            TripEvent::create(['trip_id' => $s->trip_id, 'at' => $now, 'label' => $now->format('H:i'), 'text_ar' => "المحطة {$s->seq} {$eventAr}", 'text_en' => "Stop {$s->seq} {$result}"]);

            // trip status once every stop is closed
            if (TripStop::where('trip_id', $s->trip_id)->whereIn('status', ['pending', 'arrived'])->count() === 0) {
                $anyFail = TripStop::where('trip_id', $s->trip_id)->whereIn('status', ['failed', 'rejected', 'partial'])->exists();
                $tripStatus = $anyFail ? 'partial' : 'completed';
                $tripFrom = $s->trip->status;
                Trip::whereKey($s->trip_id)->update(['status' => $tripStatus]);
                $this->audit->status($user, 'Trip', $s->trip_id, $s->trip->number, $tripFrom, $tripStatus);
            }
            $this->notify->event($result === 'delivered' ? 'DeliveryCompleted' : ($result === 'partial' ? 'PartialShipment' : 'DeliveryFailed'),
                ['fo' => $fo->number, 'trip' => $s->trip->number, 'pod' => $pod->number, 'delivered' => $delivered, 'returned' => $returned, 'reason' => $failReason]);
            $activity = $result === 'delivered' ? "سُلّم {$fo->number} ✓ — POD {$pod->number} أُقفل"
                : ($result === 'partial' ? "تسليم جزئي {$delivered}/{$total} — {$fo->number} · مرتجع ".($rtn ?: '—') : "فشل تسليم {$fo->number} — {$reasonAr}");
            $this->notify->activity($user, 'ProofOfDelivery', $pod->id, $pod->number, $activity, null, ['disp']);

            return ['pod' => $pod->number, 'result' => $result, 'delivered' => $delivered, 'returned' => $returned, 'return' => $rtn,
                'attachments' => ['signature' => ! empty($dto['signature']) ? 'integration_pending' : null, 'photo' => ! empty($dto['photo']) ? 'integration_pending' : null],
                'gps' => $gpsStatus];
        });
    }

    /** A driver only reaches trips assigned to their own driver record; users with trip.manage reach all. */
    private function assertOwnTrip(AuthUser $user, ?string $tripDriverId, ?string $messageEn = null): void
    {
        if ($user->driverId && ! $user->can('trip.manage') && $tripDriverId !== $user->driverId) {
            throw AppError::forbidden('NOT_YOUR_TRIP', 'السائق لا يرى رحلات غيره', $messageEn);
        }
    }

    private function findTrip(AuthUser $user, string $idOrNumber): Trip
    {
        $t = Trip::with($this->tripWith())->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('TRIP_NOT_FOUND', 'الرحلة غير موجودة');
        $this->assertOwnTrip($user, $t->driver_id, 'Drivers see only their own trips');

        return $t;
    }

    private function stopFor(AuthUser $user, string $stopId): TripStop
    {
        $s = TripStop::with('trip')->whereKey($stopId)->first() ?? throw AppError::notFound('STOP_NOT_FOUND', 'الوقفة غير موجودة');
        $this->assertOwnTrip($user, $s->trip->driver_id);

        return $s;
    }

    private function tripWith(): array
    {
        return [
            'vehicle:id,code,plate_ar,plate_en,kind,type_ar', 'driver:id,code,name_ar,mobile', 'warehouse:id,code,name_ar',
            'stops' => fn ($q) => $q->orderBy('seq'), 'stops.customer:id,code,name_ar,contact,address,zone',
            'stops.fo.lines.product:id,sku,name_ar,name_en', 'stops.fo.packages',
            'stops.pod:id,stop_id,number,result,receiver_name,at,delivered_qty,returned_qty,fail_reason',
            'events' => fn ($q) => $q->orderBy('at')->orderBy('id'),
        ];
    }

    /** Adds progress / nextStop / eta (ETA is honest: no maps provider is connected). */
    private function decorate(Trip $t): array
    {
        $stops = $t->stops;
        $next = $stops->first(fn (TripStop $s) => in_array($s->status, ['pending', 'arrived'], true));

        // array_merge on purpose: the computed `eta` replaces the trip's planned-eta column, as in the reference
        return array_merge($t->toArray(), [
            'progress' => [
                'total' => $stops->count(),
                'done' => $stops->filter(fn (TripStop $s) => in_array($s->status, self::CLOSED_STOP_STATUSES, true))->count(),
                'delivered' => $stops->where('status', 'delivered')->count(),
                'failed' => $stops->filter(fn (TripStop $s) => in_array($s->status, ['failed', 'rejected'], true))->count(),
            ],
            'nextStop' => $next ? ['seq' => $next->seq, 'customer' => $next->customer_ar, 'fo' => $next->fo?->number] : null,
            'eta' => ['status' => 'integration_pending'],
        ]);
    }
}
