<?php

namespace App\Services\Fulfillment;

use App\Models\Carton;
use App\Models\FoLine;
use App\Models\Package;
use App\Models\PickTask;
use App\Models\StagingEntry;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Inventory\InventoryService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Sm;
use Illuminate\Support\Facades\DB;

/**
 * Packing: a picked order becomes ONE package made of numbered cartons (one row and one label each, "1 / N"), and its
 * stock moves PACK → STG-OUT. A packed order that is not on the truck yet can be corrected (cartons, weight, volume)
 * or unpacked — STG-OUT → PACK, package and cartons void — which is the only way its stock goes back.
 */
class PackingService
{
    /** A package is physical: more cartons than this in one order is a typing mistake, not a shipment. */
    public const MAX_CARTONS = 2000;

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
        private readonly InventoryService $inventory,
        private readonly FulfillmentSupport $support,
    ) {}

    /**
     * @param  array{cartons?:int|string, weightKg?:float|int|string|null, volumeM3?:float|int|string|null, cartonWeights?:array<int,float|int|string>|null}  $dto
     * @return array{package:string, status:string, cartons:int, cartonList:array<int,array>}
     */
    public function pack(AuthUser $user, string $idOrNumber, array $dto): array
    {
        $foId = $this->support->findFo($idOrNumber)->id;
        $txId = $this->inventory->newTxId();

        return DB::transaction(function () use ($user, $foId, $dto, $txId) {
            [$so, $fo] = $this->support->lockOrder($foId);
            if ($fo->status !== 'picked') {
                throw AppError::rule('PACK_STATE', "التعبئة تتطلب اكتمال التجهيز أولًا ({$fo->status})", "Packing requires a fully picked order ({$fo->status})");
            }
            $lines = FoLine::where('fo_id', $fo->id)->orderBy('line_no')->get();
            if ((int) $lines->sum('picked_qty') <= 0) {
                throw AppError::rule('NOTHING_PICKED', "لا كميات مجهّزة في {$fo->number} — لا تُعبَّأ شحنة فارغة", 'Nothing was picked — an empty order is not packed');
            }
            $cartons = self::cartonCount($dto['cartons'] ?? 1);
            $weights = FulfillmentSupport::cartonWeights($dto, $cartons);
            $weightKg = $weights !== null ? round(array_sum($weights), 3) : (isset($dto['weightKg']) ? (float) $dto['weightKg'] : $fo->weight_kg);
            $volumeM3 = isset($dto['volumeM3']) ? (float) $dto['volumeM3'] : null;
            $pack = $this->inventory->specialBin($fo->warehouse_id, 'PACK');
            $out = $this->inventory->specialBin($fo->warehouse_id, 'STG-OUT');
            foreach ($lines as $l) {
                if ($l->picked_qty <= 0) {
                    continue;
                }
                // move the picked quantity PACK → STG-OUT per batch (batches come from the pick tasks)
                foreach (PickTask::where('fo_line_id', $l->id)->where('picked_qty', '>', 0)->orderBy('seq')->get() as $t) {
                    $this->inventory->post($user, $txId, [
                        'type' => 'pack', 'productId' => $l->product_id, 'batchId' => $t->batch_id, 'qty' => $t->picked_qty,
                        'from' => ['warehouseId' => $fo->warehouse_id, 'binId' => $pack->id], 'to' => ['warehouseId' => $fo->warehouse_id, 'binId' => $out->id],
                        'reference' => ['type' => 'FulfillmentOrder', 'id' => $fo->id, 'number' => $fo->number], 'note' => 'تعبئة → Outbound Staging',
                    ]);
                }
                $l->update(['packed_qty' => $l->picked_qty]);
            }
            $pkg = Package::create([
                'number' => $this->numbering->next('PKG'), 'fo_id' => $fo->id, 'cartons' => $cartons, 'weight_kg' => $weightKg, 'volume_m3' => $volumeM3,
                'label_ref' => "LBL-{$fo->number}", 'packed_by_id' => $user->id, 'packed_by' => $user->username, 'status' => 'packed',
            ]);
            $cartonList = $this->support->issueCartons($fo, $pkg->id, $cartons, $weightKg, $weights);
            $fo->update(['status' => 'packed', 'cartons' => $cartons, 'weight_kg' => $weightKg, 'cbm' => $volumeM3 ?? $fo->cbm, 'packed_at' => now(), 'staged_at' => now()]);
            StagingEntry::create(['direction' => 'out', 'bin_id' => $out->id, 'reference_type' => 'FulfillmentOrder', 'reference_id' => $fo->id, 'reference_number' => $fo->number, 'qty' => (int) $lines->sum('picked_qty')]);
            if ($so) {
                $so->update(['status' => 'packed']);
                $this->support->emit($so->id, 'order.packed', ['fulfilmentOrder' => $fo->number, 'package' => $pkg->number, 'cartons' => $cartons]);
            }
            if ($fo->trip_id) {
                $this->support->recomputeTrip($fo->trip_id); // an order that joined a trip while picked now has its real weight
            }
            $this->audit->status($user, 'FulfillmentOrder', $fo->id, $fo->number, 'picked', 'packed', $pkg->number, $txId);
            $this->notify->activity($user, 'FulfillmentOrder', $fo->id, $fo->number, "اكتملت تعبئة {$fo->number} — {$pkg->number} · {$cartons} كرتون — جاهز للتحميل", "Packed {$fo->number} — {$cartons} cartons", ['disp']);
            $this->notify->event('OrderPacked', ['fo' => $fo->number, 'so' => $fo->so_id]);

            return ['package' => $pkg->number, 'status' => 'packed', 'cartons' => $cartons, 'cartonList' => $cartonList];
        });
    }

    /**
     * Unpack a packed order that is not loaded: STG-OUT → PACK per batch, package and cartons void, staging entry
     * cleared, fulfilment order and sales order back to `picked` (from there: pack again, or put the goods back).
     *
     * @return array{ok:bool, fo:string, status:string, package:?string, message:string}
     */
    public function unpack(AuthUser $user, string $idOrNumber, string $reason): array
    {
        $foId = $this->support->findFo($idOrNumber)->id;
        $reason = trim($reason);
        if ($reason === '') {
            throw AppError::validation('REASON_REQUIRED', 'اكتب سبب فكّ التعبئة', 'A reason is required');
        }
        $txId = $this->inventory->newTxId();

        return DB::transaction(function () use ($user, $foId, $reason, $txId) {
            [$so, $fo] = $this->support->lockOrder($foId);
            if ($fo->status !== 'packed' || $fo->loaded) {
                throw AppError::rule('UNPACK_STATE', "فكّ التعبئة متاح لطلب معبّأ وغير محمَّل فقط ({$fo->status})".($fo->status === 'loaded' ? ' — أنزِله من المركبة أولًا' : ''), "Only a packed, not loaded order can be unpacked ({$fo->status})");
            }
            Sm::assert('FO_TRANSITIONS', 'packed', 'picked', 'FO_TRANSITION');
            $pack = $this->inventory->specialBin($fo->warehouse_id, 'PACK');
            $out = $this->inventory->specialBin($fo->warehouse_id, 'STG-OUT');
            $moved = 0;
            foreach (FoLine::where('fo_id', $fo->id)->orderBy('line_no')->get() as $l) {
                foreach (PickTask::where('fo_line_id', $l->id)->where('picked_qty', '>', 0)->orderBy('seq')->get() as $t) {
                    $this->inventory->post($user, $txId, [
                        'type' => 'move', 'productId' => $l->product_id, 'batchId' => $t->batch_id, 'qty' => $t->picked_qty,
                        'from' => ['warehouseId' => $fo->warehouse_id, 'binId' => $out->id], 'to' => ['warehouseId' => $fo->warehouse_id, 'binId' => $pack->id],
                        'reference' => ['type' => 'FulfillmentOrder', 'id' => $fo->id, 'number' => $fo->number], 'note' => "فكّ التعبئة STG-OUT → PACK — {$reason}",
                    ]);
                    $moved += $t->picked_qty;
                }
                $l->update(['packed_qty' => 0]);
            }
            $pkg = $this->livePackage($fo->id);
            $pkg?->update(['status' => 'void', 'voided_at' => now(), 'voided_by' => $user->username, 'void_reason' => $reason]);
            $voided = $this->support->voidCartons($fo->id);
            StagingEntry::where('reference_type', 'FulfillmentOrder')->where('reference_id', $fo->id)->where('status', 'waiting')->update(['status' => 'cleared', 'cleared_at' => now()]);
            $fo->update(['status' => 'picked', 'packed_at' => null, 'staged_at' => null]);
            if ($so) {
                $so->update(['status' => 'picked']);
                $this->support->emit($so->id, 'order.unpacked', ['fulfilmentOrder' => $fo->number, 'reason' => $reason]);
            }
            $this->audit->status($user, 'FulfillmentOrder', $fo->id, $fo->number, 'packed', 'picked', "فكّ التعبئة — {$reason}", $txId);
            $this->audit->log($user, ['action' => 'PACK.UNPACK', 'entityType' => 'FulfillmentOrder', 'entityId' => $fo->id, 'entityNumber' => $fo->number,
                'oldValue' => ['package' => $pkg?->number, 'cartons' => $voided, 'weightKg' => $pkg?->weight_kg], 'newValue' => ['reason' => $reason, 'movedQty' => $moved], 'transactionId' => $txId]);
            $this->notify->activity($user, 'FulfillmentOrder', $fo->id, $fo->number, "فُكّت تعبئة {$fo->number}".($pkg ? " (أُبطل {$pkg->number} وملصقات {$voided} كرتون)" : '')." — {$reason}", "Unpacked {$fo->number} — {$reason}", ['wm', 'disp']);

            return ['ok' => true, 'fo' => $fo->number, 'status' => 'picked', 'package' => $pkg?->number,
                'message' => "فُكّت تعبئة {$fo->number} — عادت الكميات إلى محطة التعبئة وأُبطلت الملصقات السابقة"];
        });
    }

    /**
     * Correct a packed order before it is loaded: cartons, weight, volume, carton weights. The package, the fulfilment
     * order and the carton rows follow (cartons are re-issued — old labels void — when their number changes), and the
     * trip the order is on gets its totals recomputed.
     *
     * @param  array{cartons?:int|string|null, weightKg?:float|int|string|null, volumeM3?:float|int|string|null, cartonWeights?:array<int,float|int|string>|null}  $dto
     */
    public function editPackage(AuthUser $user, string $idOrNumber, array $dto): array
    {
        $foId = $this->support->findFo($idOrNumber)->id;
        if (! array_filter([$dto['cartons'] ?? null, $dto['weightKg'] ?? null, $dto['volumeM3'] ?? null, $dto['cartonWeights'] ?? null], fn ($v) => $v !== null && $v !== [])) {
            throw AppError::validation('NO_CHANGES', 'لا تعديل مطلوب — أدخل الكراتين أو الوزن أو الحجم', 'Nothing to change');
        }

        return DB::transaction(function () use ($user, $foId, $dto) {
            [, $fo] = $this->support->lockOrder($foId);
            if ($fo->status !== 'packed' || $fo->loaded) {
                throw AppError::rule('PACKAGE_STATE', "تعديل الطرد متاح لطلب معبّأ وغير محمَّل فقط ({$fo->status})".($fo->status === 'loaded' ? ' — أنزِله من المركبة أولًا' : ''), "Only a packed, not loaded order can be edited ({$fo->status})");
            }
            $pkg = $this->livePackage($fo->id) ?? throw AppError::notFound('PACKAGE_NOT_FOUND', "لا طرد قائم لـ {$fo->number}", 'No live package for this order');
            $old = ['cartons' => $pkg->cartons, 'weightKg' => $pkg->weight_kg, 'volumeM3' => $pkg->volume_m3];
            $cartons = isset($dto['cartons']) ? self::cartonCount($dto['cartons']) : $pkg->cartons;
            $weights = FulfillmentSupport::cartonWeights($dto, $cartons);
            $weightKg = $weights !== null ? round(array_sum($weights), 3) : (isset($dto['weightKg']) ? (float) $dto['weightKg'] : $pkg->weight_kg);
            $volumeM3 = isset($dto['volumeM3']) ? (float) $dto['volumeM3'] : $pkg->volume_m3;

            $pkg->update(['cartons' => $cartons, 'weight_kg' => $weightKg, 'volume_m3' => $volumeM3]);
            $fo->update(['cartons' => $cartons, 'weight_kg' => $weightKg, 'cbm' => $volumeM3 ?? $fo->cbm]);
            $live = Carton::where('fo_id', $fo->id)->where('status', '!=', 'void')->orderBy('seq')->get();
            $reissued = $live->count() !== $cartons;
            if ($reissued) {
                $this->support->voidCartons($fo->id);
                $this->support->issueCartons($fo, $pkg->id, $cartons, $weightKg, $weights);
            } elseif ($weights !== null || $weightKg !== $old['weightKg']) {
                $even = $weightKg !== null ? round($weightKg / $cartons, 3) : null;
                foreach ($live as $i => $c) {
                    $c->update(['weight_kg' => $weights !== null ? round($weights[$i], 3) : $even]);
                }
            }
            $trip = null;
            $warning = null;
            if ($fo->trip_id && ($t = Trip::find($fo->trip_id)) && in_array($t->status, FulfillmentSupport::LOADABLE, true)) {
                $totals = $this->support->recomputeTrip($t->id);
                $trip = ['number' => $t->number] + $totals;
                $v = $t->vehicle_id ? Vehicle::find($t->vehicle_id) : null;
                if ($v && (($v->max_kg > 0 && $totals['kg'] > $v->max_kg) || ($v->max_cbm > 0 && $totals['cbm'] > $v->max_cbm))) {
                    $warning = "تنبيه: حمولة الرحلة {$t->number} صارت تتجاوز سعة المركبة {$v->code} — سيُرفض التحميل عند التجاوز";
                }
            }
            $new = ['cartons' => $cartons, 'weightKg' => $weightKg, 'volumeM3' => $volumeM3];
            $this->audit->log($user, ['action' => 'PACK.EDIT', 'entityType' => 'FulfillmentOrder', 'entityId' => $fo->id, 'entityNumber' => $fo->number,
                'oldValue' => $old, 'newValue' => $new + ['package' => $pkg->number, 'cartonsReissued' => $reissued, 'trip' => $trip['number'] ?? null]]);
            $this->notify->activity($user, 'FulfillmentOrder', $fo->id, $fo->number,
                "عُدّل طرد {$fo->number} ({$pkg->number}): {$old['cartons']} ← {$cartons} كرتون".($reissued ? ' — أُعيد إصدار ملصقات الكراتين' : ''), "Package of {$fo->number} edited", ['disp']);

            return ['ok' => true, 'fo' => $fo->number, 'package' => $pkg->number] + $new + [
                'cartonsReissued' => $reissued, 'trip' => $trip, 'warning' => $warning,
                'cartonList' => Carton::where('fo_id', $fo->id)->where('status', '!=', 'void')->orderBy('seq')->get()
                    ->map(fn (Carton $c) => ['code' => $c->code, 'seq' => $c->seq, 'weightKg' => $c->weight_kg, 'status' => $c->status])->all(),
                'message' => "حُدّث طرد {$fo->number}".($reissued ? ' — أُعيد إصدار الكراتين: اطبع الملصقات الجديدة وأتلف القديمة' : '').($warning ? " · {$warning}" : ''),
            ];
        });
    }

    private function livePackage(string $foId): ?Package
    {
        return Package::where('fo_id', $foId)->where('status', '!=', 'void')->orderByDesc('packed_at')->orderByDesc('id')->first();
    }

    private static function cartonCount(mixed $value): int
    {
        $n = (int) $value;
        if ($n < 1 || $n > self::MAX_CARTONS) {
            throw AppError::validation('BAD_CARTONS', 'عدد الكراتين يجب أن يكون بين 1 و '.self::MAX_CARTONS, 'Cartons must be between 1 and '.self::MAX_CARTONS);
        }

        return $n;
    }
}
