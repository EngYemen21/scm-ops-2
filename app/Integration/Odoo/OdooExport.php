<?php

namespace App\Integration\Odoo;

use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Support\AppError;
use Illuminate\Support\Facades\DB;

/**
 * The copy as files a person can put in a safe: one gzip file of JSON lines per Odoo model and a manifest that lists
 * every file with its number of records and its checksum. It needs neither Odoo nor OPS to be read (one record per
 * line, exactly as Odoo returned it), and `import()` loads it back into the mirror tables of any OPS installation.
 *
 *   <dir>/manifest.json                 { exportedAt, source, models: [{ model, label, records, file, sha256, fields }] }
 *   <dir>/<model>.jsonl.gz              {"id":…, "display_name":…, "write_date":…, "active":…, "deleted_at":…, "data":{…}}
 */
class OdooExport
{
    public function __construct(private readonly OdooClient $odoo) {}

    /**
     * @param  ?string[]  $only  restrict to these models
     * @return array the manifest
     */
    public function export(string $dir, ?array $only = null, ?callable $progress = null): array
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw AppError::rule('ODOO_EXPORT_DIR', "تعذّر إنشاء المجلد {$dir}", "The folder {$dir} could not be created");
        }
        $models = [];
        $total = 0;
        foreach (OdooModel::where('mirrored_count', '>', 0)->when($only, fn ($q) => $q->whereIn('model', $only))->orderBy('priority')->orderBy('model')->get() as $m) {
            $file = $m->model.'.jsonl.gz';
            $gz = gzopen($dir.DIRECTORY_SEPARATOR.$file, 'wb6');
            $hash = hash_init('sha256');
            $n = 0;
            $last = 0;
            do {
                $rows = DB::table('odoo_records')->where('model', $m->model)->where('odoo_id', '>', $last)->orderBy('odoo_id')->limit(2000)
                    ->get(['odoo_id', 'display_name', 'write_date', 'active', 'deleted_at', 'data']);
                foreach ($rows as $r) {
                    $line = json_encode(['id' => (int) $r->odoo_id, 'display_name' => $r->display_name, 'write_date' => $r->write_date, 'active' => $r->active === null ? null : (bool) $r->active,
                        'deleted_at' => $r->deleted_at, 'data' => json_decode((string) $r->data, false) ?? new \stdClass],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION)."\n";
                    gzwrite($gz, $line);
                    hash_update($hash, $line);
                    $last = (int) $r->odoo_id;
                    $n++;
                }
            } while ($rows->count() >= 2000);
            gzclose($gz);
            $models[] = ['model' => $m->model, 'label' => $m->label, 'records' => $n, 'file' => $file, 'sha256' => hash_final($hash), 'fields' => $m->fields, 'hasActive' => (bool) $m->has_active];
            $total += $n;
            if ($progress) {
                $progress($m->model, $n);
            }
        }
        $manifest = ['format' => 'ops-odoo-archive/1', 'exportedAt' => now()->toJSON(), 'source' => array_intersect_key($this->odoo->describe(), ['host' => 1, 'db' => 1]),
            'records' => $total, 'models' => $models];
        file_put_contents($dir.DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $manifest;
    }

    /**
     * Checks every file of an archive against its manifest (number of records, checksum) without loading anything.
     *
     * @return array{ok:bool, models:int, records:int, bad:string[]}
     */
    public function check(string $dir): array
    {
        $manifest = $this->manifest($dir);
        $bad = [];
        $records = 0;
        foreach ($manifest['models'] as $m) {
            [$n, $sha] = $this->scan($dir.DIRECTORY_SEPARATOR.$m['file']);
            $records += $n;
            if ($n !== (int) $m['records'] || $sha !== $m['sha256']) {
                $bad[] = $m['model'];
            }
        }

        return ['ok' => ! $bad, 'models' => count($manifest['models']), 'records' => $records, 'bad' => $bad];
    }

    /**
     * Loads an archive into the mirror tables — for an installation that has to stand without Odoo. Every file is
     * checked against the manifest first; nothing is loaded from an archive that fails the check. The models are
     * registered as `paused` ("restored from an archive"): no run tries to synchronise them until a person resumes
     * them, so a restore never depends on Odoo being there.
     *
     * @return array{models:int, records:int}
     */
    public function import(string $dir, bool $force = false): array
    {
        $manifest = $this->manifest($dir);
        $check = $this->check($dir);
        if (! $check['ok']) {
            throw AppError::rule('ODOO_ARCHIVE_DAMAGED', 'الأرشيف لا يطابق بيانه: '.implode('، ', array_slice($check['bad'], 0, 5)), 'The archive does not match its manifest: '.implode(', ', array_slice($check['bad'], 0, 5)));
        }
        if (! $force && OdooRecord::query()->exists()) {
            throw AppError::conflict('ODOO_MIRROR_NOT_EMPTY', 'النسخة في هذه القاعدة ليست فارغة — الاستعادة فوقها تحتاج --force', 'The mirror of this database is not empty — restoring over it needs --force');
        }
        $records = 0;
        foreach ($manifest['models'] as $m) {
            $now = now()->format('Y-m-d H:i:s.v');
            $gz = gzopen($dir.DIRECTORY_SEPARATOR.$m['file'], 'rb');
            $batch = [];
            $n = 0;
            $flush = function () use (&$batch) {
                if ($batch) {
                    DB::table('odoo_records')->upsert($batch, ['model', 'odoo_id'], ['display_name', 'write_date', 'active', 'data', 'hash', 'changed_at', 'deleted_at']);
                    $batch = [];
                }
            };
            while (($line = gzgets($gz)) !== false) {
                $r = json_decode($line, true);
                if (! is_array($r) || ! isset($r['id'])) {
                    continue;
                }
                $data = (array) ($r['data'] ?? []);
                ksort($data);
                $json = json_encode($data ?: new \stdClass, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
                $batch[] = ['model' => $m['model'], 'odoo_id' => (int) $r['id'], 'display_name' => $r['display_name'] ?? null, 'write_date' => $r['write_date'] ?? null,
                    'active' => $r['active'] ?? null, 'data' => $json, 'hash' => sha1($json), 'version' => 1, 'first_seen_at' => $now, 'changed_at' => $now, 'deleted_at' => $r['deleted_at'] ?? null];
                $n++;
                if (count($batch) >= 200) {
                    $flush();
                }
            }
            gzclose($gz);
            $flush();
            $live = OdooRecord::where('model', $m['model'])->whereNull('deleted_at')->count();
            OdooModel::updateOrCreate(['model' => $m['model']], ['label' => $m['label'] ?? null, 'status' => 'paused', 'reason' => 'restored from an archive', 'fields' => $m['fields'] ?? null,
                'has_write_date' => true, 'has_active' => (bool) ($m['hasActive'] ?? false), 'full_state' => 'done', 'mirrored_count' => $live, 'remote_count' => $live,
                'last_changed_at' => $now, 'page_size' => (int) config('odoo.page_size', 500)]);
            $records += $n;
        }

        return ['models' => count($manifest['models']), 'records' => $records];
    }

    private function manifest(string $dir): array
    {
        $manifest = json_decode((string) @file_get_contents($dir.DIRECTORY_SEPARATOR.'manifest.json'), true);
        if (! is_array($manifest) || ($manifest['format'] ?? null) !== 'ops-odoo-archive/1' || ! is_array($manifest['models'] ?? null)) {
            throw AppError::rule('ODOO_ARCHIVE_UNREADABLE', "لا يوجد أرشيف صالح في {$dir}", "No valid archive in {$dir}");
        }

        return $manifest;
    }

    /** @return array{0:int, 1:string} lines and sha256 of the uncompressed content */
    private function scan(string $file): array
    {
        $gz = @gzopen($file, 'rb');
        if (! $gz) {
            return [-1, ''];
        }
        $hash = hash_init('sha256');
        $n = 0;
        while (($line = gzgets($gz)) !== false) {
            hash_update($hash, $line);
            $n++;
        }
        gzclose($gz);

        return [$n, hash_final($hash)];
    }
}
