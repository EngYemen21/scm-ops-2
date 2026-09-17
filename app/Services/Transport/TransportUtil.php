<?php

namespace App\Services\Transport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/** Small shared helpers for the transport module (dates, formatting, labels, local state tables, response shaping). */
final class TransportUtil
{
    /** Trip statuses during which the vehicle/driver are physically committed. */
    public const ACTIVE_TRIP_STATES = ['loading', 'ready', 'dispatched', 'onroute', 'partial', 'completed', 'returning'];

    /** Trip statuses in which assignment / stop order / cancellation may still change (before loading). */
    public const EDITABLE_TRIP_STATES = ['draft', 'planned', 'vassigned', 'dassigned'];

    /** Trip statuses that no longer hold a fulfillment order. */
    public const ENDED_TRIP_STATES = ['closed', 'cancelled', 'failed'];

    /** Stop statuses that count as "processed" for trip closing. */
    public const PROCESSED_STOP_STATES = ['delivered', 'partial', 'failed', 'rejected', 'skipped'];

    public const TEMP_RANK = ['dry' => 0, 'chill' => 1, 'reefer' => 2];

    public const VEHICLE_TYPE_LABEL = ['dry' => ['جافة', 'Dry box'], 'chill' => ['مبردة +4°', 'Chiller +4°'], 'reefer' => ['مبردة −18°/+4°', 'Reefer −18°/+4°']];

    public const SHIFT_LABEL = ['am' => ['صباحية 06–14', 'AM'], 'pm' => ['مسائية 14–22', 'PM'], 'flex' => ['مرنة', 'Flex']];

    public const DRIVER_STATE_LABEL = ['available' => 'متاح', 'onroute' => 'في الطريق', 'off' => 'خارج الوردية', 'blocked' => 'موقوف', 'inactive' => 'غير نشط'];

    public const MAINT_KIND_LABEL = [
        'preventive' => ['وقائية', 'Preventive'], 'corrective' => ['تصحيحية', 'Corrective'], 'emergency' => ['طارئة', 'Emergency'], 'tire' => ['إطارات', 'Tires'], 'oil' => ['زيت', 'Oil'],
        'brake' => ['فرامل', 'Brakes'], 'engine' => ['محرك', 'Engine'], 'elec' => ['كهرباء', 'Electrical'], 'ac' => ['تكييف / تبريد', 'AC / Refrigeration'], 'body' => ['هيكل', 'Body'],
    ];

    public const OPREQ_TYPE_LABEL = ['fuel' => 'وقود', 'maint' => 'صيانة', 'tire' => 'إطار', 'vehicle' => 'مشكلة مركبة', 'toll' => 'رسوم طريق', 'parking' => 'مواقف', 'emergency' => 'طارئ', 'other' => 'أخرى'];

    public const OPREQ_STATE_LABEL = ['submitted' => 'مُرسل', 'review' => 'قيد المراجعة', 'approved' => 'معتمد', 'rejected' => 'مرفوض', 'processed' => 'تمت المعالجة', 'closed' => 'مغلق'];

    /** Ops-request workflow: submitted → review → approved → processed → closed (local to this module, like the reference). */
    public const OPREQ_TRANSITIONS = [
        'submitted' => ['review', 'approved', 'rejected'], 'review' => ['approved', 'rejected'], 'approved' => ['processed', 'rejected', 'closed'],
        'rejected' => ['closed'], 'processed' => ['closed'], 'closed' => [],
    ];

    /** [attribute (snake_case), Arabic label, English label] */
    public const VEHICLE_DOCS = [['reg_expiry', 'الاستمارة', 'Registration'], ['insurance_expiry', 'التأمين', 'Insurance'], ['inspection_expiry', 'الفحص الدوري', 'Inspection'], ['op_card_expiry', 'كرت التشغيل', 'Operating card']];

    public const DRIVER_DOCS = [['license_expiry', 'الرخصة', 'License'], ['iqama_expiry', 'الإقامة / الهوية', 'Iqama / ID'], ['medical_expiry', 'الشهادة الصحية', 'Medical certificate']];

    /** Midnight UTC of "today" (dates are stored as UTC midnight). */
    public static function today(): Carbon
    {
        return Carbon::now('UTC')->startOfDay();
    }

    /** Accepts 'YYYY-MM-DD', 'YYYY/MM/DD' or ISO; returns UTC midnight (or the given HH:MM) — null when unparseable. */
    public static function parseDate(mixed $s): ?Carbon
    {
        if ($s instanceof Carbon) {
            return $s;
        }
        if (! is_string($s) || trim($s) === '') {
            return null;
        }
        if (preg_match('/^(\d{4})[-\/](\d{2})[-\/](\d{2})(?:[ T](\d{2}):(\d{2}))?/', trim($s), $m)) {
            if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }

            return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), 0, 'UTC');
        }
        try {
            return Carbon::parse($s)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** Days from today until $d: negative when already expired; null when unknown. */
    public static function daysTo(mixed $d): ?int
    {
        $date = self::parseDate($d);
        if (! $date) {
            return null;
        }

        return (int) ceil(($date->getTimestamp() - self::today()->getTimestamp()) / 86400);
    }

    public static function fmtDate(?Carbon $d): string
    {
        return $d ? $d->copy()->utc()->format('Y/m/d') : '—';
    }

    public static function fmt0(int|float $n): string
    {
        return number_format(self::round($n), 0, '.', ',');
    }

    public static function hhmm(): string
    {
        return now()->format('H:i');
    }

    /** JavaScript Math.round (half towards +∞), so scores and KPIs match the reference exactly. */
    public static function round(int|float $n): int
    {
        return (int) floor($n + 0.5);
    }

    public static function round1(int|float $n): float
    {
        return self::round($n * 10) / 10;
    }

    /** Number → text the way JavaScript prints it inside a template string (1500, 0.5, 12.3). */
    public static function num(int|float|null $n): string
    {
        if ($n === null) {
            return '—';
        }

        return (string) (floor($n) == $n ? (int) $n : $n);
    }

    /**
     * @param  array<int, array{0:string,1:string,2:string}>  $docs
     * @return array<int, array{key:string,labelAr:string,labelEn:string,date:?Carbon,days:?int,expired:bool}>
     */
    public static function docInfos(Model $entity, array $docs): array
    {
        $out = [];
        foreach ($docs as [$attribute, $labelAr, $labelEn]) {
            $date = $entity->getAttribute($attribute);
            $days = self::daysTo($date);
            $out[] = ['key' => Str::camel($attribute), 'labelAr' => $labelAr, 'labelEn' => $labelEn, 'date' => $date, 'days' => $days, 'expired' => $days !== null && $days < 0];
        }

        return $out;
    }

    /** Smallest `days` of the documents (null when none has a date). */
    public static function minDays(array $docs): ?int
    {
        $days = array_values(array_filter(array_column($docs, 'days'), fn ($d) => $d !== null));

        return $days ? min($days) : null;
    }

    public static function vehicleLabel(string $state, string $lang = 'ar'): string
    {
        return config("scm.VEHICLE_LABELS.{$state}.{$lang}") ?: $state;
    }

    /** `{a: 1}` stays an object in JSON even when empty (`{}` instead of `[]`). */
    public static function map(array $map): object
    {
        return (object) $map;
    }

    /**
     * Trims nested nodes of a serialised (camelCase) array to the fixed key lists the reference returns through
     * Prisma `select`. Paths are dot separated, `*` walks a list; list parents before children.
     *
     * @param  array<string, string[]>  $spec
     */
    public static function shape(array $data, array $spec): array
    {
        foreach ($spec as $path => $keys) {
            $data = self::walk($data, explode('.', $path), $keys);
        }

        return $data;
    }

    private static function walk(mixed $node, array $segments, array $keys): mixed
    {
        if (! is_array($node)) {
            return $node;
        }
        if ($segments === []) {
            $out = [];
            foreach ($keys as $key) {
                $out[$key] = $node[$key] ?? null;
            }

            return $out;
        }
        $segment = array_shift($segments);
        if ($segment === '*') {
            return array_map(fn ($child) => self::walk($child, $segments, $keys), $node);
        }
        if (array_key_exists($segment, $node)) {
            $node[$segment] = self::walk($node[$segment], $segments, $keys);
        }

        return $node;
    }
}
