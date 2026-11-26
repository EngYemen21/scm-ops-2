<?php

namespace App\Workspace\Support;

use App\Models\AuditLog;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Sql;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/** Small things the three tools of the workspace share: how a value leaves as JSON, searching, refusing. */
final class Ws
{
    /** The priority of a ticket or a task: 0 normal · 1 medium · 2 high · 3 urgent. */
    public const PRIORITIES = [0 => ['عادية', 'Normal'], 1 => ['متوسطة', 'Medium'], 2 => ['عالية', 'High'], 3 => ['عاجلة', 'Urgent']];

    /** @return array<int, array{key:int, ar:string, en:string}> */
    public static function priorities(): array
    {
        return array_map(fn (int $k) => ['key' => $k, 'ar' => self::PRIORITIES[$k][0], 'en' => self::PRIORITIES[$k][1]], array_keys(self::PRIORITIES));
    }

    public static function day(?DateTimeInterface $d): ?string
    {
        return $d?->format('Y-m-d');
    }

    public static function at(?DateTimeInterface $d): ?string
    {
        return $d instanceof Carbon ? $d->toJSON() : $d?->format(DATE_ATOM);
    }

    /** A text as people typed it: trimmed, null when nothing is left. */
    public static function text(mixed $v, ?int $max = null): ?string
    {
        $s = is_string($v) || is_int($v) || is_float($v) ? trim((string) $v) : '';

        return $s === '' ? null : ($max ? mb_substr($s, 0, $max) : $s);
    }

    /**
     * "contains", without regard to letter case on both engines, over several columns.
     *
     * @param  string[]  $columns
     * @param  ?callable(Builder, string): void  $more  further alternatives of the same search
     */
    public static function search(Builder $query, array $columns, ?string $term, ?callable $more = null): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }
        $like = '%'.addcslashes($term, '\\%_').'%';
        $op = Sql::like();
        $query->where(function (Builder $w) use ($columns, $like, $op, $more, $term) {
            foreach ($columns as $column) {
                $w->orWhere($column, $op, $like);
            }
            if ($more) {
                $more($w, $term);
            }
        });
    }

    /**
     * Refuses something the caller may not do to THIS record (he may open the section, the record is not his to see or
     * to change): a 403 written to the audit trail like every refusal of the API. Call it BEFORE the transaction of
     * the work it refuses, so that the audit line is not rolled back with it.
     */
    public static function deny(AuthUser $user, string $code, string $ar, string $en): never
    {
        $request = request();
        AuditLog::create([
            'user_id' => $user->id, 'username' => $user->username, 'action' => 'SECURITY.UNAUTHORIZED', 'entity_type' => 'endpoint',
            'entity_id' => mb_substr($request->method().' /'.$request->path(), 0, 250), 'old_value' => implode(',', $user->roles),
            'new_value' => 'denied: '.$code, 'request_id' => $user->requestId,
        ]);
        Log::warning("403 {$user->username} {$request->method()} /{$request->path()} {$code}");
        throw AppError::forbidden($code, $ar, $en);
    }

    /**
     * A state machine refuses a move it does not list.
     *
     * @param  array<string, string[]>  $table  from → the states it may become
     */
    public static function transition(array $table, ?string $from, string $to, string $code): void
    {
        if (! in_array($to, $table[$from ?? ''] ?? [], true)) {
            throw AppError::rule($code, "انتقال غير مسموح: {$from} ← {$to}", "Invalid transition: {$from} -> {$to}", ['from' => $from, 'to' => $to]);
        }
    }

    /** The values of `$new` that differ from what the row holds, with the old ones: [old, new]. Days and moments compare by value. */
    public static function diff(Model $row, array $new): array
    {
        $old = $changed = [];
        foreach ($new as $column => $value) {
            $current = $row->getAttribute($column);
            if (! self::same($current, $value)) {
                $old[$column] = $current instanceof DateTimeInterface ? $current->format('Y-m-d H:i:s') : $current;
                $changed[$column] = $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
            }
        }

        return [$old, $changed];
    }

    public static function same(mixed $current, mixed $value): bool
    {
        if ($current instanceof DateTimeInterface || $value instanceof DateTimeInterface) {
            if ($current === null || $value === null) {
                return false;
            }
            // a day (`Y-m-d`) against a date column, otherwise two moments to the second
            if (is_string($value) && strlen($value) === 10) {
                return Carbon::parse($current)->toDateString() === $value;
            }

            return Carbon::parse($current)->getTimestamp() === Carbon::parse($value)->getTimestamp();
        }
        if (is_bool($current) || is_bool($value)) {
            return $current !== null && $value !== null && (bool) $current === (bool) $value;
        }
        if (is_array($current) || is_array($value)) {
            [$a, $b] = [(array) ($current ?: []), (array) ($value ?: [])];
            ksort($a);
            ksort($b);

            return $a == $b;
        }
        if ((is_int($value) || is_float($value) || is_int($current) || is_float($current)) && is_numeric($current) && is_numeric($value)) {
            return abs((float) $current - (float) $value) < 0.005;
        }

        return (string) ($current ?? '') === (string) ($value ?? '');
    }
}
