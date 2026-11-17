<?php

namespace App\Services\Delivery;

use App\Finance\Services\CustomerInvoiceService;
use App\Integration\Services\OrderEvents;
use App\Models\Carton;
use App\Models\DeliveryRecord;
use App\Models\Driver;
use App\Models\FoLine;
use App\Models\FulfillmentOrder;
use App\Models\OpsException;
use App\Models\PickTask;
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
use App\Services\Fulfillment\ScanCodes;
use App\Services\Integrations\Adapters\AdapterFactory;
use App\Services\Integrations\FileStore;
use App\Services\Returns\ReturnsService;
use App\Services\Sales\CreditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Driver workflow + POD: the driver sees only their own trips; "arrived" is required before any POD; a stop accepts
 * exactly one POD (unique stop_id) so a duplicate POD is impossible; delivered / partial (remaining → return) /
 * customer rejected / unable to deliver (structured reason → exception).
 *
 * What a proof of delivery proves (policies `delivery.requireScan`, `delivery.requireSignature`):
 *  - the RIGHT goods: a scan of the order, its package, its label or one of its cartons at the customer's door — or a
 *    written reason, which raises a warning for dispatch; a scan of another order's goods refuses the delivery;
 *  - the RECEIVER: a signature or a photo, really stored (object store, or the database when none is configured) and
 *    readable back through `GET /integrations/files/{key}` — never a reference to a file nobody kept;
 *  - the QUANTITY: a partial delivery is recorded per item, exactly as the driver counted it. The invoice, the order
 *    lines and the return all use those figures, and the return carries the batch of what comes back.
 */
class DeliveryService
{
    private const DRIVER_TRIP_STATUSES = ['dassigned', 'vassigned', 'loading', 'ready', 'dispatched', 'onroute', 'partial', 'completed', 'returning'];

    private const CLOSED_STOP_STATUSES = ['delivered', 'partial', 'failed', 'rejected', 'skipped'];

    /** Why a delivery was recorded without a scan: code => [Arabic, English]. Free text is accepted too. */
    public const NO_SCAN_REASONS = [
        'label_damaged' => ['الملصق تالف أو غير مقروء', 'Label damaged / unreadable'],
        'scanner_failed' => ['تعطل الماسح أو الكاميرا', 'Scanner / camera failed'],
        'on_behalf' => ['تسجيل بالنيابة عن السائق', 'Recorded on behalf of the driver'],
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
        private readonly SettingsService $settings,
        private readonly ExceptionsService $exceptions,
        private readonly ReturnsService $returns,
        private readonly FileStore $files,
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
            'current' => $current ? $this->decorate($current, $user) : null,
            'upcoming' => $trips->reject(fn (Trip $t) => $t->id === $current?->id)->map(fn (Trip $t) => $this->decorate($t, $user))->values()->all(),
            'gps' => AdapterFactory::gps()->configured() ? ['status' => 'connected', 'note' => 'تتبع المركبة الحي مربوط بمزود Telematics'] : ['status' => 'integration_pending', 'note' => 'تتبع المركبة الحي يتطلب ربط مزود Telematics — غير متصل'],
            'policy' => $this->policy(),
        ];
    }

    public function trip(AuthUser $user, string $idOrNumber): array
    {
        return $this->decorate($this->findTrip($user, $idOrNumber), $user);
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
            TripEvent::create(['trip_id' => $t->id, 'at' => now(), 'label' => 'START', 'text_ar' => 'بدء الرحلة من تطبيق السائق', 'text_en' => 'Trip started by driver']);
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

    /**
     * Checks one scanned code at an arrived stop, so the driver knows at once — before collecting a signature — that
     * the carton in hand is the right one. Same rule as the delivery itself (matchScan): a code of another order is
     * refused with WRONG_PACKAGE and dispatch is told.
     *
     * @return array{code:string, match:bool, order:string}
     */
    public function scan(AuthUser $user, string $stopId, string $code): array
    {
        $this->stopFor($user, $stopId);
        $s = TripStop::with(['trip', 'fo.so', 'pod'])->whereKey($stopId)->firstOrFail();
        $this->assertStopOpen($s);
        $fo = $s->fo ?? throw AppError::rule('NO_FO', 'الوقفة بلا أمر تنفيذ');

        return ['code' => trim($code), 'match' => $this->matchScan($user, $s, $fo, trim($code)), 'order' => $fo->number];
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

    /** The delivery policies the driver app must respect (it shows them; the checks themselves are here, in closeStop). */
    public function policy(): array
    {
        return [
            'requireScan' => (bool) $this->settings->get('delivery.requireScan'),
            'requireSignature' => (bool) $this->settings->get('delivery.requireSignature'),
            'noScanReasons' => array_map(fn ($code, $l) => ['code' => $code, 'ar' => $l[0], 'en' => $l[1]], array_keys(self::NO_SCAN_REASONS), self::NO_SCAN_REASONS),
            'signatureMaxBytes' => FileStore::SIGNATURE_MAX_BYTES, 'photoMaxBytes' => FileStore::PHOTO_MAX_BYTES,
        ];
    }

    // ───────────── internals ─────────────

    /**
     * Closes a stop with exactly one POD. Partial / failed / rejected send the remainder back through an already
     * approved return (the goods are on the truck) and raise an exception for dispatch.
     *
     * Everything that can refuse the request is checked BEFORE the transaction: the refusal of a wrong package raises
     * an exception for dispatch, and that row must not be rolled back with the delivery it refused. The state of the
     * stop is checked again under its row lock.
     *
     * @param  array{receiverName?:?string, notes?:?string, gps?:?array, gpsStatus?:?string, signature?:?string, photo?:?string, deliveredQty?:?int,
     *               lines?:?array<int, array{lineNo:int, deliveredQty:int}>, scans?:?string[], noScanReason?:?string, deliveredCartons?:?int, failReason?:?string}  $dto
     */
    private function closeStop(AuthUser $user, string $stopId, string $result, array $dto): array
    {
        $this->stopFor($user, $stopId);
        $autoReturn = (bool) $this->settings->get('delivery.autoReturnOnPartial');
        $failed = $result === 'failed' || $result === 'rejected';

        $pre = TripStop::with(['trip', 'fo.lines', 'fo.so', 'pod'])->whereKey($stopId)->firstOrFail();
        $this->assertStopOpen($pre);
        $preFo = $pre->fo ?? throw AppError::rule('NO_FO', 'الوقفة بلا أمر تنفيذ');
        $receiver = trim((string) ($dto['receiverName'] ?? ''));
        if (! $failed && $receiver === '') {
            throw AppError::validation('RECEIVER_REQUIRED', 'اكتب اسم المستلم أولًا — POD إلزامي', 'Receiver name is required');
        }
        $failReason = $dto['failReason'] ?? null;
        if ($failed && ! $failReason) {
            throw AppError::validation('REASON_REQUIRED', 'اختر سبب الفشل — الأسباب Structured إلزامية', 'A structured failure reason is required');
        }
        $plan = $this->deliveryPlan($preFo, $result, $dto);
        $files = $failed ? [] : array_filter([
            'signature' => FileStore::decodeImage($dto['signature'] ?? null, FileStore::SIGNATURE_MAX_BYTES, ['ar' => 'التوقيع', 'en' => 'The signature']),
            'photo' => FileStore::decodeImage($dto['photo'] ?? null, FileStore::PHOTO_MAX_BYTES, ['ar' => 'صورة التسليم', 'en' => 'The delivery photo']),
        ]);
        $proof = $failed ? ['scans' => null, 'scanOk' => null, 'noScanReason' => null, 'deliveredCartons' => null, 'onBehalf' => false]
            : $this->checkProof($user, $pre, $preFo, $dto, $files);

        return DB::transaction(function () use ($user, $stopId, $result, $dto, $autoReturn, $failed, $receiver, $failReason, $plan, $files, $proof) {
            // The stop row is locked: two simultaneous PODs for one stop are serialised and the second is a POD_DUPLICATE.
            $s = TripStop::with(['trip', 'fo.lines.product', 'fo.customer', 'pod'])->whereKey($stopId)->lockForUpdate()->firstOrFail();
            $this->assertStopOpen($s);
            $fo = $s->fo ?? throw AppError::rule('NO_FO', 'الوقفة بلا أمر تنفيذ');
            $total = $plan['total'];
            $delivered = $plan['delivered'];
            $returned = $plan['returned'];
            $deliveredByLine = $plan['byLine'];
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
                'notes' => $notes, 'fail_reason' => $failReason, 'at' => $now, 'created_by_id' => $user->id,
                'scans' => $proof['scans'], 'scan_ok' => $proof['scanOk'], 'no_scan_reason' => $proof['noScanReason'], 'delivered_cartons' => $proof['deliveredCartons']]);
            // The signature and the photo are kept for real: the object store when one is configured, the database otherwise
            // (and the database too when the object store does not answer — a POD is taken once, at the customer's door).
            $attachments = ['signature' => null, 'photo' => null];
            foreach ($files as $kind => $file) {
                $put = $this->files->put("pod/{$pod->number}/{$kind}.{$file['ext']}", $file['bytes'], $file['mime'], neverLose: true);
                PodAttachment::create(['pod_id' => $pod->id, 'kind' => $kind, 'file_name' => "{$kind}-{$pod->number}.{$file['ext']}", 'mime' => $file['mime'], 'size' => $file['size'],
                    'storage_key' => $put['key'] ?? null, 'url' => $put['url'] ?? null, 'status' => $put['status']]);
                $attachments[$kind] = $put['status'];
            }
            $stopStatus = $result;
            $s->update(['status' => $stopStatus, 'completed_at' => $now, 'fail_reason' => $failReason, 'actual_time' => $now->format('H:i')]);
            $foStatus = $failed ? 'failed' : $result;
            FulfillmentOrder::whereKey($fo->id)->update(['status' => $foStatus, 'delivered_at' => $now]);
            foreach ($fo->lines as $l) {
                $dq = (int) ($deliveredByLine[$l->id] ?? 0);
                $l->update(['delivered_qty' => $dq, 'returned_qty' => $l->qty - $dq]);
                if ($l->so_line_id) {
                    SalesOrderLine::whereKey($l->so_line_id)->update(['delivered_qty' => $dq, 'returned_qty' => $l->qty - $dq]);
                }
            }
            if ($fo->so_id) {
                SalesOrder::whereKey($fo->so_id)->update(['status' => $foStatus]);
            }
            // finance: control transferred → customer invoice (delivered qty × order price) + cost of goods sold, in this transaction
            app(CustomerInvoiceService::class)->issueFromDelivery($user, $fo, $pod, $deliveredByLine);
            if ($failed) {
                app(CreditService::class)->sync($fo->customer_id); // nothing was invoiced: the order leaves the customer's exposure now
            }
            $this->audit->status($user, 'TripStop', $s->id, $stopRef, 'arrived', $stopStatus, $pod->number);
            $this->audit->status($user, 'FulfillmentOrder', $fo->id, $fo->number, 'onroute', $foStatus, $pod->number);
            $this->audit->log($user, ['action' => 'POD.CREATE', 'entityType' => 'ProofOfDelivery', 'entityId' => $pod->id, 'entityNumber' => $pod->number,
                'newValue' => ['result' => $result, 'delivered' => $delivered, 'returned' => $returned, 'receiver' => $dto['receiverName'] ?? null, 'gps' => $gpsStatus,
                    'scanOk' => $proof['scanOk'], 'noScanReason' => $proof['noScanReason'], 'signature' => $attachments['signature'], 'photo' => $attachments['photo'], 'onBehalf' => $proof['onBehalf'] ?: null]]);

            $rtn = null;
            $parts = $returned > 0 && ($result !== 'partial' || $autoReturn) ? $this->returnLines($fo, $deliveredByLine) : [];
            if ($parts) { // a return exists only when it has lines
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
            if (! $failed && $proof['scanOk'] === false && $proof['noScanReason'] !== null) {
                // delivered without the scan that proves the right goods: accepted with its reason, and dispatch is told
                [$whyAr, $whyEn] = self::NO_SCAN_REASONS[$proof['noScanReason']] ?? [$proof['noScanReason'], $proof['noScanReason']];
                $this->exceptions->raise($user, $excBase + ['kind' => 'other', 'severity' => 'w',
                    'textAr' => "تسليم بلا مسح {$fo->number} — {$s->customer_ar} · السبب: {$whyAr} · {$pod->number}", 'textEn' => "Delivered without a scan {$fo->number} — {$whyEn} · {$pod->number}"]);
            }
            $eventAr = $result === 'delivered' ? 'مسلّمة · POD ✓' : ($result === 'partial' ? "تسليم جزئي {$delivered}/{$total} · POD" : 'فشل تسليم · Exception');
            TripEvent::create(['trip_id' => $s->trip_id, 'at' => $now, 'label' => $now->format('H:i'), 'text_ar' => "المحطة {$s->seq} {$eventAr}".($proof['onBehalf'] ? " · سُجّل بالنيابة ({$user->nameAr})" : ''), 'text_en' => "Stop {$s->seq} {$result}"]);

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
            // an order from Sales: the delivery result + proof of delivery go back to it (GPS only as captured / not)
            app(OrderEvents::class)->emit($fo->so_id, $result === 'delivered' ? 'delivery.completed' : ($result === 'partial' ? 'delivery.partial' : 'delivery.failed'), [
                'fulfilmentOrder' => $fo->number, 'trip' => $s->trip->number, 'pod' => $pod->number, 'receiver' => $pod->receiver_name,
                'deliveredQty' => $delivered, 'returnedQty' => $returned, 'reason' => $failReason, 'reasonAr' => $reasonAr,
                'gps' => $gpsStatus === 'captured', 'return' => $rtn,
                'lines' => $fo->lines->map(fn (FoLine $l) => ['sku' => $l->product?->sku, 'delivered' => (int) ($deliveredByLine[$l->id] ?? 0), 'returned' => $l->qty - (int) ($deliveredByLine[$l->id] ?? 0)])->all(),
            ]);
            $activity = $result === 'delivered' ? "سُلّم {$fo->number} ✓ — POD {$pod->number} أُقفل"
                : ($result === 'partial' ? "تسليم جزئي {$delivered}/{$total} — {$fo->number} · مرتجع ".($rtn ?: '—') : "فشل تسليم {$fo->number} — {$reasonAr}");
            $this->notify->activity($user, 'ProofOfDelivery', $pod->id, $pod->number, $activity, null, ['disp']);

            return ['pod' => $pod->number, 'result' => $result, 'delivered' => $delivered, 'returned' => $returned, 'return' => $rtn,
                'attachments' => $attachments, 'gps' => $gpsStatus, 'scanOk' => $proof['scanOk']];
        });
    }

    /**
     * A stop takes a POD only while its trip is on the road, it has none yet, and the driver recorded the arrival.
     * A stop that carries a warehouse transfer never takes one: the destination warehouse receives it, quantity by
     * quantity, on the transfer itself (TransferTripService::arrived closes the stop) — the driver only reports "arrived".
     */
    private function assertStopOpen(TripStop $s): void
    {
        if ($s->transfer_id) {
            throw AppError::rule('TRANSFER_STOP', 'محطة تحويل مخزني — يستلمها المستودع المستقبِل من شاشة التحويلات، ولا يُسجَّل لها إثبات تسليم', 'This stop delivers a warehouse transfer — the destination warehouse receives it on the transfer; no proof of delivery is recorded');
        }
        if (! in_array($s->trip->status, ['onroute', 'partial'], true)) {
            throw AppError::rule('TRIP_STATE', 'الوقفة ليست في الطريق — لا POD مكرر', 'Trip not on route');
        }
        if ($s->pod) {
            throw AppError::conflict('POD_DUPLICATE', "الوقفة مُقفلة ({$s->status}) — POD مكرر مرفوض", 'Duplicate POD rejected');
        }
        if ($s->status !== 'arrived') {
            throw AppError::rule('ARRIVE_FIRST', $s->status === 'pending' ? 'سجّل الوصول أولًا' : "الوقفة مُقفلة ({$s->status}) — POD مكرر مرفوض", 'Record arrival first');
        }
    }

    /**
     * What is handed over, per order line — the driver's own figures, never a proportion.
     *  delivered → every line in full · failed / rejected → nothing ·
     *  partial → `lines: [{lineNo, deliveredQty}]` (0 … line quantity; a line that is not listed was delivered in full).
     *  A partial delivery must leave at least one line short and hand over at least one unit. The old single figure
     *  (`deliveredQty`) is still accepted for an order with ONE line; with more lines nobody can say which item is short.
     *
     * @return array{byLine:array<string,int>, delivered:int, returned:int, total:int}
     */
    private function deliveryPlan(FulfillmentOrder $fo, string $result, array $dto): array
    {
        $lines = $fo->lines->sortBy('line_no')->values();
        $total = (int) $lines->sum('qty');
        $byLine = [];
        if ($result !== 'partial') {
            foreach ($lines as $l) {
                $byLine[$l->id] = $result === 'delivered' ? (int) $l->qty : 0;
            }
        } elseif (! empty($dto['lines'])) {
            $bad = fn (string $ar, string $en) => AppError::validation('PARTIAL_LINES', $ar, $en);
            $given = [];
            foreach ($dto['lines'] as $row) {
                $no = (int) ($row['lineNo'] ?? 0);
                if (isset($given[$no])) {
                    throw $bad("السطر {$no} مكرر في كميات التسليم الجزئي", "Line {$no} is listed twice");
                }
                $given[$no] = (int) ($row['deliveredQty'] ?? -1);
            }
            foreach ($lines as $l) {
                $q = array_key_exists($l->line_no, $given) ? $given[$l->line_no] : (int) $l->qty;
                if ($q < 0 || $q > $l->qty) {
                    throw $bad("الكمية المسلَّمة للسطر {$l->line_no} يجب أن تكون بين 0 و{$l->qty}", "Delivered quantity of line {$l->line_no} must be between 0 and {$l->qty}");
                }
                $byLine[$l->id] = $q;
                unset($given[$l->line_no]);
            }
            if ($given) {
                throw $bad('سطر غير موجود في الطلب: '.implode('، ', array_keys($given)), 'Unknown order line: '.implode(', ', array_keys($given)));
            }
            $sum = array_sum($byLine);
            if ($sum < 1 || $sum >= $total) {
                throw $bad('التسليم الجزئي: يجب تسليم وحدة واحدة على الأقل وأن ينقص صنف واحد على الأقل — للتسليم الكامل استخدم «إقفال التسليم»، ولعدم التسليم «تعذر التسليم»',
                    'A partial delivery hands over at least one unit and leaves at least one item short — use "Confirm delivery" for everything, "Unable to deliver" for nothing');
            }
        } else {
            if ($lines->count() > 1) {
                throw AppError::validation('LINES_REQUIRED', 'الطلب فيه أكثر من صنف — حدّد الكمية المسلَّمة لكل صنف', 'The order has several items — give the delivered quantity per item (lines)');
            }
            $q = (int) ($dto['deliveredQty'] ?? 0);
            if (! ($q > 0 && $q < $total)) {
                $max = $total - 1;
                throw AppError::validation('PARTIAL_QTY', "أدخل كمية مسلَّمة بين 1 و{$max} للتسليم الجزئي", "Delivered quantity must be between 1 and {$max}");
            }
            foreach ($lines as $l) {
                $byLine[$l->id] = $q;
            }
        }
        $delivered = array_sum($byLine);

        return ['byLine' => $byLine, 'delivered' => $delivered, 'returned' => $total - $delivered, 'total' => $total];
    }

    /**
     * The proof rules of a delivery (full or partial).
     *
     * Signature (`delivery.requireSignature`): a signature or a photo. Someone recording on behalf of the driver
     * (holds trip.manage and is not the trip's driver) has neither in hand and writes the reason in the notes instead.
     *
     * Scan (`delivery.requireScan`): one scanned code must identify this stop's order (order, sales order, package,
     * label or carton), or a reason is given. A code that identifies ANOTHER order is always refused — handing over
     * someone else's goods is wrong whatever the policy says — and dispatch gets an exception.
     *
     * @param  array<string, array>  $files  decoded signature / photo
     * @return array{scans:?array, scanOk:?bool, noScanReason:?string, deliveredCartons:?int, onBehalf:bool}
     */
    private function checkProof(AuthUser $user, TripStop $s, FulfillmentOrder $fo, array $dto, array $files): array
    {
        $onBehalf = $user->can('trip.manage') && $user->driverId !== $s->trip->driver_id;
        if ($this->settings->get('delivery.requireSignature') && ! $files) {
            if (! $onBehalf) {
                throw AppError::rule('SIGNATURE_REQUIRED', 'التقط توقيع المستلم أو صورة التسليم أولًا — إثبات التسليم يحتاج أحدهما', 'A receiver signature or a delivery photo is required');
            }
            if (trim((string) ($dto['notes'] ?? '')) === '') {
                throw AppError::rule('SIGNATURE_REASON', 'تسجيل بالنيابة عن السائق بلا توقيع أو صورة — اكتب السبب في الملاحظات', 'Recording on behalf of the driver without a signature or photo — write the reason in the notes');
            }
        }

        $scans = array_values(array_unique(array_filter(array_map(fn ($c) => trim((string) $c), (array) ($dto['scans'] ?? [])), fn ($c) => $c !== '')));
        $valid = false;
        foreach ($scans as $code) {
            $valid = $this->matchScan($user, $s, $fo, $code) || $valid;
        }
        $reason = trim((string) ($dto['noScanReason'] ?? ''));
        $reason = $reason === '' ? null : mb_substr($reason, 0, 200);
        $required = (bool) $this->settings->get('delivery.requireScan');
        if ($required && ! $valid && $reason === null) {
            throw AppError::rule('SCAN_REQUIRED', $scans ? 'لم يطابق أي رمز ممسوح طلب هذه المحطة — امسح ملصق الطلب أو أحد كراتينه، أو اختر «لا أستطيع المسح» مع السبب' : 'امسح ملصق الطلب أو أحد كراتينه قبل التسليم، أو اختر «لا أستطيع المسح» مع السبب',
                "Scan the order's label or one of its cartons before delivering, or choose \"I cannot scan\" with a reason");
        }

        $cartons = $dto['deliveredCartons'] ?? null;
        if ($cartons !== null) {
            $cartons = (int) $cartons;
            $max = Carton::where('fo_id', $fo->id)->where('status', '!=', 'void')->count() ?: (int) $fo->cartons;
            if ($max > 0 && $cartons > $max) {
                throw AppError::validation('CARTONS_EXCEED', "عدد الكراتين المسلَّمة ({$cartons}) أكبر من كراتين الطلب ({$max})", "Delivered cartons ({$cartons}) exceed the order's cartons ({$max})");
            }
        }

        return [
            'scans' => $scans ?: null,
            // true: a scan proved the goods · false: delivered on a written reason / with no matching scan · null: nothing scanned and the policy does not ask
            'scanOk' => $valid ? true : ($required || $scans || $reason !== null ? false : null),
            'noScanReason' => $valid ? null : $reason,
            'deliveredCartons' => $cartons,
            'onBehalf' => $onBehalf,
        ];
    }

    /**
     * One scanned (or typed) code against the order of a stop: true when it identifies this order (the order itself,
     * its sales order, package, shipping label or one of its cartons), false when the system does not know the code.
     * A code of ANOTHER order refuses the request with WRONG_PACKAGE and raises an exception for dispatch — raised
     * here, outside any transaction of the caller, so it survives the refusal.
     */
    private function matchScan(AuthUser $user, TripStop $s, FulfillmentOrder $fo, string $code): bool
    {
        $hit = ScanCodes::fo($code);
        if ($hit?->id === $fo->id || ($fo->so && ScanCodes::normalize($code) === mb_strtoupper($fo->so->number))) {
            return true;
        }
        if (! $hit) {
            return false;
        }
        $textAr = "مسح طرد خاطئ عند {$s->customer_ar}: «{$code}» يخص {$hit->number} وليس {$fo->number} — المحطة {$s->seq} · {$s->trip->number}";
        // one exception per wrong code and stop, however many times the driver tries again
        if (! OpsException::where('kind', 'wrong')->where('entity_id', $fo->id)->where('status', '!=', 'resolved')->where('text_ar', $textAr)->exists()) {
            $this->exceptions->raise($user, ['kind' => 'wrong', 'severity' => 'c', 'ownerRole' => 'disp', 'entityType' => 'FulfillmentOrder', 'entityId' => $fo->id, 'entityNumber' => $fo->number,
                'documentType' => 'Trip', 'documentId' => $s->trip_id, 'documentNumber' => $s->trip->number,
                'textAr' => $textAr, 'textEn' => "Wrong package scanned at stop {$s->seq} of {$s->trip->number}: {$code} belongs to {$hit->number}, not {$fo->number}"]);
        }
        throw AppError::rule('WRONG_PACKAGE', "طرد خاطئ — «{$code}» يخص الطلب {$hit->number} وليس طلب هذه المحطة {$fo->number}. لا تسلّمه؛ أُبلغ الإرسال",
            "Wrong package — {$code} belongs to order {$hit->number}, not this stop's order {$fo->number}. Do not hand it over; dispatch was notified", ['scanned' => $code, 'belongsTo' => $hit->number, 'expected' => $fo->number]);
    }

    /**
     * The lines of the return of what was not delivered, WITH the batch: the returned quantity of an order line is
     * taken from that line's pick tasks, last picked first (what was picked first — the earliest expiry — is what the
     * customer received), one return line per batch. A quantity no pick task accounts for comes back without a batch.
     *
     * @param  array<string,int>  $deliveredByLine  fo_line id → delivered qty
     * @return array<int, array{sku:string, qty:int, batchNo?:string}>
     */
    private function returnLines(FulfillmentOrder $fo, array $deliveredByLine): array
    {
        $parts = [];
        foreach ($fo->lines->sortBy('line_no') as $l) {
            $left = $l->qty - (int) ($deliveredByLine[$l->id] ?? 0);
            if ($left <= 0) {
                continue;
            }
            $byBatch = [];
            $tasks = PickTask::with('batch:id,batch_no')->where('fo_line_id', $l->id)->where('picked_qty', '>', 0)->get()
                ->sortByDesc(fn (PickTask $t) => sprintf('%020d-%010d-%s', $t->picked_at?->getTimestampMs() ?? 0, $t->seq, $t->id));
            foreach ($tasks as $t) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, (int) $t->picked_qty);
                $batchNo = (string) ($t->batch?->batch_no ?? $t->batch_no ?? '');
                $byBatch[$batchNo] = ($byBatch[$batchNo] ?? 0) + $take;
                $left -= $take;
            }
            if ($left > 0) {
                $byBatch[''] = ($byBatch[''] ?? 0) + $left;
            }
            foreach ($byBatch as $batchNo => $qty) {
                $parts[] = ['sku' => $l->product->sku, 'qty' => $qty] + ((string) $batchNo !== '' ? ['batchNo' => (string) $batchNo] : []);
            }
        }

        return $parts;
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
            'stops.fo.lines' => fn ($q) => $q->orderBy('line_no'), 'stops.fo.lines.product:id,sku,name_ar,name_en', 'stops.fo.packages',
            'stops.pod:id,stop_id,number,result,receiver_name,at,delivered_qty,returned_qty,fail_reason,scan_ok,no_scan_reason,delivered_cartons',
            'stops.pod.attachments:id,pod_id,kind,file_name,mime,size,storage_key,status',
            'events' => fn ($q) => $q->orderBy('at')->orderBy('id'),
        ];
    }

    /**
     * Adds progress / nextStop / eta / policy. `eta` is the PLANNED arrival at the next stop (TripsService::recomputeEtas:
     * maps provider when it answers, an estimate otherwise) — `unavailable` when it could not be planned (no coordinates).
     * It is a plan, not live tracking: nothing here follows the truck.
     */
    private function decorate(Trip $t, AuthUser $user): array
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
            'eta' => $next?->eta ? ['status' => 'planned', 'time' => $next->eta, 'source' => $next->eta_source, 'windowOk' => $next->window_ok, 'stop' => $next->seq] : ['status' => 'unavailable'],
            'policy' => $this->policy(),
            // the signed-in user records for the driver (dispatch on the phone-less driver's behalf): no signature in hand → a written reason
            'onBehalf' => $user->can('trip.manage') && $user->driverId !== $t->driver_id,
        ]);
    }
}
