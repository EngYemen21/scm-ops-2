<?php

namespace App\Integration\Odoo;

use App\Integration\Odoo\Models\OdooFile;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The content of Odoo's attachments, kept by OPS: invoices and bills as they were issued or received, product
 * pictures, receipts, signed delivery notes. The mirror holds the LIST of attachments (`ir.attachment`, without
 * content); this reads each file from Odoo once, checks it against the checksum Odoo states, and stores it on a disk
 * of OPS (config odoo.files). Resumable: a file already looked at is never read again. Read-only towards Odoo.
 *
 * Left out, each with its reason on record: links (an attachment that is a URL, not a file), Odoo's own machinery
 * (web assets, module icons) and files larger than `max_mb`.
 */
class OdooFiles
{
    public function __construct(private readonly OdooClient $odoo) {}

    /** The mirror holds the list of attachments. */
    public function ready(): bool
    {
        return OdooModel::where('model', 'ir.attachment')->where('status', 'active')->where('full_state', 'done')->exists();
    }

    /**
     * @return array{ran:bool, reason?:string, stored?:int, bytes?:int, skipped?:int, failed?:int, left?:int}
     */
    public function run(int $seconds = 50): array
    {
        if (! $this->odoo->enabled()) {
            return ['ran' => false, 'reason' => 'disabled'];
        }
        if (! $this->ready()) {
            return ['ran' => false, 'reason' => 'the list of attachments is not copied yet'];
        }
        $lock = Cache::lock('odoo:files', $seconds + 300);
        if (! $lock->get()) {
            return ['ran' => false, 'reason' => 'another run is in progress'];
        }
        $until = microtime(true) + max(3, $seconds);
        $out = ['ran' => true, 'stored' => 0, 'bytes' => 0, 'skipped' => 0, 'failed' => 0];
        $record = function (int $id, array $row) use (&$out) {
            OdooFile::create($row + ['attachment_id' => $id, 'stored_at' => now()]);
            $out[$row['status'] === 'stored' ? 'stored' : ($row['status'] === 'failed' ? 'failed' : 'skipped')]++;
            $out['bytes'] += $row['status'] === 'stored' ? $row['size'] : 0;
        };
        try {
            do {
                $batch = $this->pending()->limit(100)->get(['odoo_records.odoo_id', 'odoo_records.data']);
                // small files are asked for together (one answer of Odoo carries up to about 8 MB of them); what is not
                // read at all (a link, Odoo's machinery, a file that is too large) is recorded without asking
                $group = [];
                $weight = 0;
                $flush = function () use (&$group, &$weight, $record) {
                    if ($group) {
                        foreach ($this->fetch($group) as $id => $row) {
                            $record($id, $row);
                        }
                        $group = [];
                        $weight = 0;
                    }
                };
                foreach ($batch as $a) {
                    $id = (int) $a->odoo_id;
                    $d = (array) json_decode((string) $a->data, true);
                    $row = $this->describe($id, $d);
                    if ($row['status'] === 'skipped') {
                        $record($id, $row);

                        continue;
                    }
                    $size = (int) ($d['file_size'] ?? 0);
                    if ($group && ($weight + $size > 8 * 1048576 || count($group) >= 25)) {
                        $flush();
                    }
                    $group[$id] = $row;
                    $weight += $size;
                    if (microtime(true) >= $until) {
                        $flush();

                        break 2;
                    }
                }
                $flush();
            } while ($batch->count() >= 100 && microtime(true) < $until);
        } catch (OdooError $e) {
            // Odoo refused the key or does not answer: nothing was recorded for the file in hand, the next run reads it
            $out['error'] = $e->getMessage();
        } finally {
            $lock->release();
        }

        return $out + ['left' => $this->pending()->count()];
    }

    /** Attachments of the copy whose content was not looked at yet, oldest first. */
    private function pending()
    {
        return DB::table('odoo_records')->leftJoin('odoo_files', 'odoo_files.attachment_id', '=', 'odoo_records.odoo_id')
            ->where('odoo_records.model', 'ir.attachment')->whereNull('odoo_records.deleted_at')->whereNull('odoo_files.id')->orderBy('odoo_records.odoo_id');
    }

    /**
     * What the copy knows about one attachment, and whether its content is to be read: status `skipped` with the reason
     * when it is not (a link, Odoo's machinery, too large), otherwise `pending`.
     *
     * @return array the odoo_files row (without the id and the time)
     */
    private function describe(int $id, array $d): array
    {
        $resModel = is_string($d['res_model'] ?? null) ? $d['res_model'] : null;
        $row = ['res_model' => $resModel, 'res_id' => is_numeric($d['res_id'] ?? null) ? (int) $d['res_id'] : null, 'name' => mb_substr((string) ($d['name'] ?? "attachment-{$id}"), 0, 255),
            'mimetype' => isset($d['mimetype']) ? mb_substr((string) $d['mimetype'], 0, 150) : null, 'size' => 0, 'checksum' => isset($d['checksum']) ? (string) $d['checksum'] : null,
            'status' => 'skipped', 'note' => null, 'disk' => null, 'path' => null];
        if (($d['type'] ?? 'binary') !== 'binary') {
            return ['note' => 'a link, not a file'] + $row;
        }
        if ($resModel === null && empty($d['res_field']) && preg_match('#^/web/|\.assets_|/static/#', (string) ($d['url'] ?? $d['name'] ?? ''))) {
            return ['note' => 'a web asset of Odoo'] + $row;
        }
        if (in_array($resModel, (array) config('odoo.files.skip_models'), true)) {
            return ['note' => 'Odoo machinery, not a document of the company'] + $row;
        }
        $max = (int) config('odoo.files.max_mb', 100);
        if ((int) ($d['file_size'] ?? 0) > $max * 1048576) {
            return ['note' => "larger than {$max} MB", 'size' => (int) $d['file_size']] + $row;
        }

        return ['status' => 'pending'] + $row;
    }

    /**
     * Reads the content of a few attachments in one answer of Odoo, checks each against the checksum Odoo states and
     * stores it. @param  array<int, array>  $rows  attachment id → its described row
     *
     * @return array<int, array> attachment id → the row to record (stored or failed)
     */
    private function fetch(array $rows): array
    {
        // the ids in the question make Odoo return the files that belong to a field too (a product picture)
        $got = [];
        foreach ($this->odoo->searchRead('ir.attachment', [['id', 'in', array_keys($rows)]], ['datas'], count($rows), 'id asc', 0, 240) as $r) {
            $got[(int) $r['id']] = $r['datas'] ?? false;
        }
        $disk = (string) config('odoo.files.disk', 'local');
        $folder = trim((string) config('odoo.files.folder', 'odoo-files'), '/');
        $out = [];
        foreach ($rows as $id => $row) {
            $known = array_key_exists($id, $got);
            $bytes = is_string($got[$id] ?? null) ? base64_decode($got[$id], true) : false;
            unset($got[$id]); // the content of a large file is not kept in memory longer than needed
            if ($bytes === false || $bytes === '') {
                // nothing OPS could keep, and nothing a later try would change: the attachment was deleted in Odoo after
                // it was listed, or Odoo itself holds no content for it. Said with its reason — not a failure of the archive.
                $out[$id] = ['status' => 'skipped', 'note' => $known ? 'Odoo holds no content for it' : 'no longer in Odoo'] + $row;

                continue;
            }
            if ($row['checksum'] && sha1($bytes) !== $row['checksum']) {
                $out[$id] = ['status' => 'failed', 'note' => 'the content does not match the checksum Odoo states'] + $row;

                continue;
            }
            $path = $folder.'/'.str_pad((string) ($id % 1000), 3, '0', STR_PAD_LEFT).'/'.$id;
            $out[$id] = Storage::disk($disk)->put($path, $bytes)
                ? ['status' => 'stored', 'size' => strlen($bytes), 'checksum' => sha1($bytes), 'disk' => $disk, 'path' => $path] + $row
                : ['status' => 'failed', 'note' => 'the disk refused the file'] + $row;
        }

        return $out;
    }

    /** Failed files are looked at again on the next runs. @return int how many */
    public function retryFailed(): int
    {
        return OdooFile::where('status', 'failed')->delete();
    }

    /** What people see: how much of the attachments' content OPS keeps. */
    public function status(): array
    {
        $by = OdooFile::query()->selectRaw('status, COUNT(*) AS n, COALESCE(SUM(size), 0) AS bytes')->groupBy('status')->get()->keyBy('status');
        $listed = $this->ready() ? OdooRecord::where('model', 'ir.attachment')->whereNull('deleted_at')->count() : 0;
        $seen = (int) $by->sum('n');

        return ['ready' => $this->ready(), 'listed' => $listed, 'stored' => (int) ($by['stored']->n ?? 0), 'storedBytes' => (int) ($by['stored']->bytes ?? 0),
            'skipped' => (int) ($by['skipped']->n ?? 0), 'failed' => (int) ($by['failed']->n ?? 0), 'left' => max(0, $listed - $seen), 'disk' => (string) config('odoo.files.disk', 'local')];
    }

    /** One archived file, for the person asking. A file that hangs on people's records needs `odoo.private`. */
    public function download(int $attachmentId): StreamedResponse
    {
        $user = AuthUser::current();
        if (! $user->can('page.odoo') && ! $user->can('integration.view')) {
            throw AppError::forbidden('FORBIDDEN', 'مرفوض — عرض مرفقات أودو يحتاج صلاحية أرشيف أودو أو برج التكامل', 'Forbidden — reading Odoo attachments needs the Odoo archive or the integration tower');
        }
        $f = OdooFile::where('attachment_id', $attachmentId)->where('status', 'stored')->first()
            ?? throw AppError::notFound('ODOO_FILE_NOT_ARCHIVED', 'هذا المرفق لم يُؤرشف بعد في نظام العمليات', 'This attachment is not archived in OPS yet');
        if ($f->res_model && OdooSync::isRestricted($f->res_model) && ! $user->can('odoo.private')) {
            throw AppError::forbidden('ODOO_PRIVATE', 'هذا المرفق من ملفات الأشخاص — عرضه يحتاج صلاحية odoo.private', 'This attachment belongs to people\'s files — reading it needs odoo.private');
        }
        if (! Storage::disk($f->disk)->exists($f->path)) {
            throw AppError::notFound('ODOO_FILE_MISSING', 'ملف المرفق غير موجود على وسيط التخزين', 'The attachment file is missing from the storage disk');
        }

        return Storage::disk($f->disk)->download($f->path, $f->name ?: "attachment-{$attachmentId}", array_filter(['Content-Type' => $f->mimetype]));
    }
}
