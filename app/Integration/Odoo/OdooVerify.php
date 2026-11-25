<?php

namespace App\Integration\Odoo;

use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Models\OdooVerification;
use App\Support\AppError;

/**
 * Is the copy what Odoo holds? A backup that nobody checks is a hope, not a backup.
 *
 * For every copied model the ids Odoo holds are compared with the ids of the copy, and a sample of records is read
 * again from Odoo and compared value by value with what the copy holds:
 *   missing    in Odoo and not in the copy (records created since the last run are `new`: the next run brings those);
 *   extra      in the copy and no longer in Odoo (deleted there since the last deletion check — the check marks them);
 *   differing  sampled records whose values differ although Odoo has NOT written them since the copy read them. Odoo
 *              can recompute a stored value without stamping the record; the mirror follows the stamps, so only this
 *              comparison can see it. With `heal` such a model is read again in place.
 * The label Odoo prints beside a linked record's id is left out of the comparison: it changes when the other record is
 * renamed, the link does not. Read-only towards Odoo, like everything else here.
 */
class OdooVerify
{
    public function __construct(private readonly OdooClient $odoo) {}

    /**
     * @param  ?string[]  $only  restrict the check to these models
     * @return array the stored verification (see odoo_verifications) with `detail.models`
     */
    public function run(string $trigger = 'manual', ?array $only = null, ?int $sample = null, bool $heal = false): array
    {
        if (! $this->odoo->enabled()) {
            throw AppError::rule('ODOO_DISABLED', 'ربط أودو غير مفعّل على هذا الخادم', 'The Odoo connection is not enabled on this server');
        }
        $sample ??= (int) config('odoo.verify_sample', 25);
        $v = OdooVerification::create(['trigger' => $trigger, 'started_at' => now()]);
        $sum = ['models' => 0, 'records_odoo' => 0, 'records_copy' => 0, 'missing' => 0, 'extra' => 0, 'sampled' => 0, 'differing' => 0];
        $off = [];
        $unread = 0;
        foreach (OdooModel::where('status', 'active')->where('full_state', 'done')->when($only, fn ($q) => $q->whereIn('model', $only))->orderBy('priority')->orderBy('model')->get() as $m) {
            try {
                $row = $this->model($m, $sample);
            } catch (OdooError $e) {
                if ($e->kind === 'auth') {
                    $v->update(['finished_at' => now(), 'detail' => ['error' => $e->getMessage()]]);
                    throw $e;
                }
                $off[$m->model] = ['error' => mb_substr($e->getMessage(), 0, 200)];
                $unread++;

                continue;
            }
            $sum['models']++;
            $sum['records_odoo'] += $row['odoo'];
            $sum['records_copy'] += $row['copy'];
            foreach (['missing', 'extra', 'sampled', 'differing'] as $k) {
                $sum[$k] += $row[$k];
            }
            if ($row['missing'] || $row['extra'] || $row['differing']) {
                $off[$m->model] = $row;
                if ($heal && ($row['missing'] || $row['differing'])) {
                    // read every record again in place: values Odoo changed without stamping, and ids the runs never saw
                    $m->update(['full_state' => 'pending', 'full_cursor_id' => 0, 'full_started_at' => null, 'full_finished_at' => null]);
                    $off[$m->model]['healing'] = true;
                }
            }
        }
        // models still being copied (a first copy, or a re-read): the copy is not complete while one of them is open
        $open = $only ? 0 : OdooModel::whereIn('status', ['new', 'active'])->where('full_state', '!=', 'done')->count();
        $held = $only ? 0 : OdooModel::whereIn('status', ['paused', 'error'])->count();
        $v->update($sum + ['finished_at' => now(), 'complete' => $sum['missing'] === 0 && $sum['differing'] === 0 && $unread === 0 && $open === 0,
            'detail' => array_filter(['models' => array_slice($off, 0, 80, true), 'stillCopying' => $open, 'onHold' => $held, 'unreadable' => $unread])]);

        return $v->refresh()->toArray();
    }

    /**
     * The ids themselves are compared, page by page — equal counts can hide one record missing and another one too
     * many. A record with an id beyond the copy's newest was created since the last run: the next run brings it, it is
     * counted as `new`, not as missing.
     *
     * @return array{odoo:int, copy:int, missing:int, extra:int, new:int, sampled:int, differing:int, written:int, fields?:array<string,int>}
     *                                                                                                                                        `fields`: the names of the fields that differ and in how many sampled records
     */
    private function model(OdooModel $m, int $sample): array
    {
        $size = (int) config('odoo.deletion_page', 5000);
        $newest = (int) OdooRecord::where('model', $m->model)->max('odoo_id');
        $top = $m->ranged ? max($newest, max([0, ...$this->odoo->search($m->model, [['id', '>', 0]], 50, 'id desc')])) : null;
        $odoo = $copy = $missing = $extra = $new = 0;
        $from = 0;
        do {
            if ($m->ranged) {
                // a model Odoo trims after limiting: fixed id ranges, as the mirror itself walks it
                $to = $from + $size;
                $remote = $this->odoo->search($m->model, [['id', '>', $from], ['id', '<=', $to]], $size);
                $more = $to < $top;
            } else {
                $remote = $this->odoo->search($m->model, [['id', '>', $from]], $size);
                $more = count($remote) >= $size;
                $to = $more ? (int) end($remote) : null;
            }
            $local = OdooRecord::where('model', $m->model)->whereNull('deleted_at')->where('odoo_id', '>', $from)->when($to, fn ($q) => $q->where('odoo_id', '<=', $to))
                ->pluck('odoo_id')->map(fn ($id) => (int) $id)->all();
            $inCopy = array_flip($local);
            $inOdoo = array_flip($remote);
            foreach ($remote as $id) {
                if (! isset($inCopy[$id])) {
                    $id > $newest ? $new++ : $missing++;
                }
            }
            foreach ($local as $id) {
                $extra += isset($inOdoo[$id]) ? 0 : 1;
            }
            $odoo += count($remote);
            $copy += count($local);
            $from = (int) $to;
        } while ($more);
        $differing = $written = 0;
        $fields = []; // field → in how many sampled records it differs (names only: never a value)
        $ids = $sample > 0 ? OdooRecord::where('model', $m->model)->whereNull('deleted_at')->inRandomOrder()->limit($sample)->pluck('odoo_id')->map(fn ($id) => (int) $id)->all() : [];
        if ($ids) {
            $types = (array) $m->fields;
            $stored = OdooRecord::where('model', $m->model)->whereIn('odoo_id', $ids)->get(['odoo_id', 'data', 'write_date'])->keyBy('odoo_id');
            foreach ($this->odoo->searchRead($m->model, [['id', 'in', $ids]], array_keys($types), count($ids), 'id asc', 0, 90) as $rec) {
                $was = $stored[(int) $rec['id']] ?? null;
                if (! $was) {
                    continue;
                }
                if (($rec['write_date'] ?? null) !== $was->write_date) {
                    $written++; // written in Odoo since the copy read it: the next run's business, not a difference

                    continue;
                }
                $now = self::bare(OdooSync::keep($rec, $types));
                $then = self::bare((array) $was->data);
                $off = [];
                foreach (array_keys($now + $then) as $k) {
                    if (array_key_exists($k, $now) !== array_key_exists($k, $then) || ! self::same($now[$k] ?? null, $then[$k] ?? null)) {
                        $off[] = $k;
                    }
                }
                if ($off) {
                    $differing++;
                    foreach ($off as $k) {
                        $fields[$k] = ($fields[$k] ?? 0) + 1;
                    }
                }
            }
        }
        arsort($fields);

        return ['odoo' => $odoo, 'copy' => $copy, 'missing' => $missing, 'extra' => $extra, 'new' => $new, 'sampled' => count($ids), 'differing' => $differing, 'written' => $written]
            + ($fields ? ['fields' => array_slice($fields, 0, 12, true)] : []);
    }

    /**
     * Is it the same value? Two things that are not a difference are told apart from one that is:
     *  - the last bit of a decimal. A database that keeps JSON numbers as binary doubles (MySQL) gives 29601.92 back
     *    for Odoo's 29601.920000000002: the same amount, written with fewer digits. Measured on the real copy: every
     *    one of the first 43 "differences" was this. Numbers are equal within a billionth of their size.
     *  - the order of a list of linked ids, which Odoo sorts by the linked records' own order.
     */
    private static function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            if (array_is_list($a) && array_is_list($b) && $a === array_filter($a, 'is_int') && $b === array_filter($b, 'is_int')) {
                sort($a);
                sort($b);

                return $a === $b;
            }
            foreach ($a as $k => $v) {
                if (! array_key_exists($k, $b) || ! self::same($v, $b[$k])) {
                    return false;
                }
            }

            return true;
        }
        if ((is_float($a) || is_float($b)) && is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) <= 1e-9 * max(1.0, abs((float) $a), abs((float) $b));
        }

        return $a == $b;
    }

    /** A record's values without the labels Odoo prints beside the ids of linked records. */
    private static function bare(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v) && count($v) === 2 && array_is_list($v) && is_int($v[0]) && is_string($v[1])) {
                $data[$k] = $v[0];
            }
        }

        return $data;
    }
}
