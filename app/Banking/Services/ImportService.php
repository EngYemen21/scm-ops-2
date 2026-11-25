<?php

namespace App\Banking\Services;

use App\Banking\Models\ImportProfile;
use App\Banking\Models\ImportRun;
use App\Banking\Models\Statement;
use App\Banking\Models\StatementLine;
use App\Banking\Support\Fingerprint;
use App\Banking\Support\Money;
use App\Banking\Support\Shape;
use App\Banking\Support\Sheet\Cells;
use App\Banking\Support\Sheet\ColumnMap;
use App\Banking\Support\Sheet\SheetFile;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importing a statement file (CSV or Excel) into an open statement, with a column mapping kept per bank account.
 *
 * `preview` reads the file and proposes how to read it (the row of titles, the mapping, the order of dates, the decimal
 * mark) and says what an import would find; `run` imports — or, with `dryRun`, only reports.
 *
 * SAFE TO REPEAT. Every line gets a fingerprint (`Fingerprint`): a line the bank account already holds is refused as a
 * duplicate, so the same file — or a second export that overlaps the first — never doubles anything. The report names
 * every row of the file: taken (with its line number), duplicate (and where it already is), or refused and why.
 * Nothing of the file is written to a log; the stored record of an import holds counts and row numbers only.
 */
class ImportService
{
    public function __construct(private readonly StatementsService $statements, private readonly Banks $banks, private readonly AuditService $audit) {}

    // ───────────────────────────── preview ─────────────────────────────

    /**
     * @param  array{fileName:string, base64:string}  $file
     * @param  array{bankAccount?:?string, profile?:?string, options?:array, mapping?:array}  $dto
     */
    public function preview(array $file, array $dto): array
    {
        $profile = $this->profileFor($dto['profile'] ?? null, $dto['bankAccount'] ?? null);
        $plan = $this->plan($file, $dto, $profile, strict: false);
        $check = null;
        if ($plan['map'] !== null) {
            $records = ColumnMap::records($plan['table']['rows'], $plan['headerRow'], $plan['map'], $plan['options']);
            $valid = array_values(array_filter($records, fn (array $r) => $r['code'] === null));
            $valid = self::chronological($valid, $plan['options']['order']);
            $codes = array_count_values(array_filter(array_column($records, 'code')));
            ksort($codes);
            $dates = array_column($valid, 'date');
            $withBalance = array_values(array_filter($valid, fn (array $r) => $r['balance'] !== null));
            $check = [
                'rows' => count($records), 'readable' => count($valid), 'refused' => count($records) - count($valid),
                'refusedBy' => array_map(fn (string $code, int $n) => ['code' => $code, 'count' => $n] + ColumnMap::reason($code), array_keys($codes), $codes),
                'dateFrom' => $dates ? min($dates) : null, 'dateTo' => $dates ? max($dates) : null,
                'in' => Money::str(array_sum(array_map(fn (array $r) => max(0, $r['cents']), $valid))), 'out' => Money::str(array_sum(array_map(fn (array $r) => min(0, $r['cents']), $valid))),
                'net' => Money::str(array_sum(array_column($valid, 'cents'))),
                // what the bank's own running balance says the statement opens and closes with, when the file carries one
                'opening' => $withBalance ? Money::str($withBalance[0]['balance'] - $withBalance[0]['cents']) : null,
                'closing' => $withBalance ? Money::str(end($withBalance)['balance']) : null,
                'balanceBreaks' => self::balanceBreaks($valid),
            ];
        }

        return [
            'file' => array_diff_key($plan['table'], ['rows' => 1]) + ['rows' => count($plan['table']['rows'])],
            'headerRow' => $plan['headerRow'], 'columns' => $plan['columns'],
            'sample' => array_values(array_slice($plan['table']['rows'], $plan['headerRow'], 12)),
            'top' => array_values(array_slice($plan['table']['rows'], 0, min(12, max(3, $plan['headerRow'] + 1)))),
            'options' => $plan['options'] + ['dateOrderGuessed' => $plan['dateOrderGuessed']], 'mapping' => (object) $plan['mapping'],
            'suggested' => ['mapping' => (object) $plan['suggested']['mapping'], 'amountMode' => $plan['suggested']['amountMode']],
            'profile' => $profile ? Shape::profile($profile) : null, 'mappingComplete' => $plan['map'] !== null, 'mappingProblem' => $plan['problem'], 'check' => $check,
        ];
    }

    // ───────────────────────────── import ─────────────────────────────

    /**
     * @param  array{fileName:string, base64:string}  $file
     * @param  array{profile?:?string, options?:array, mapping?:array, saveAs?:?string, makeDefault?:bool}  $dto
     */
    public function run(AuthUser $user, string $statementIdOrNumber, array $file, array $dto, bool $dryRun): array
    {
        return DB::transaction(function () use ($user, $statementIdOrNumber, $file, $dto, $dryRun) {
            $s = $this->statements->find($statementIdOrNumber, lock: true);
            $this->statements->assertEditable($s);
            $profile = $this->profileFor($dto['profile'] ?? null, isset($dto['mapping']) ? null : $s->bank_account_id);
            $plan = $this->plan($file, $dto, $profile, strict: true);
            $records = ColumnMap::records($plan['table']['rows'], $plan['headerRow'], $plan['map'], $plan['options']);
            if (! $records) {
                throw AppError::validation('BANK_IMPORT_NO_ROWS', 'الملف لا يحوي سطور بيانات بعد صف العناوين', 'The file holds no data rows after its header row');
            }
            $from = $s->date_from->toDateString();
            $to = $s->date_to->toDateString();
            foreach ($records as $i => $r) {
                if ($r['code'] === null && ($r['date'] < $from || $r['date'] > $to)) {
                    $records[$i]['code'] = 'ROW_DATE_OUTSIDE';
                }
            }
            $valid = self::chronological(array_values(array_filter($records, fn (array $r) => $r['code'] === null)), $plan['options']['order']);

            // ── fingerprints: the n-th occurrence of an identical line inside the file is its own line
            $seen = [];
            foreach ($valid as $i => $r) {
                $identity = ColumnMap::identity($r['date'], $r['cents'], $r['reference'], $r['description']);
                $seen[$identity] = ($seen[$identity] ?? 0) + 1;
                $valid[$i]['fingerprint'] = Fingerprint::of($s->bank_account_id, $r['date'], $r['cents'], $r['reference'], $r['description'], $seen[$identity]);
            }
            $existing = $this->existing(array_column($valid, 'fingerprint'));

            $report = [];
            foreach ($records as $r) {
                if ($r['code'] !== null) {
                    $report[$r['row']] = self::reportRow($r, 'refused', $r['code']);
                }
            }
            $run = $dryRun ? null : ImportRun::create(['statement_id' => $s->id, 'bank_account_id' => $s->bank_account_id, 'profile_id' => $profile?->id, 'file_name' => $plan['table']['fileName'],
                'file_hash' => $plan['table']['hash'], 'format' => $plan['table']['format'], 'rows_total' => count($records), 'created_by' => $user->username]);
            $no = (int) StatementLine::where('statement_id', $s->id)->max('line_no');
            $taken = $duplicates = 0;
            $total = 0;
            $now = now()->format('Y-m-d H:i:s.v');
            foreach ($valid as $r) {
                $held = $existing[$r['fingerprint']] ?? null;
                if ($held === null && ! $dryRun) {
                    // the unique fingerprint decides, also against an import running at this very moment
                    $inserted = DB::table('bank_statement_lines')->insertOrIgnore([
                        'id' => (string) Str::ulid(), 'statement_id' => $s->id, 'bank_account_id' => $s->bank_account_id, 'line_no' => $no + 1, 'kind' => StatementLine::KIND_LINE, 'txn_date' => $r['date'],
                        'value_date' => $r['valueDate'], 'amount' => Money::str($r['cents']), 'balance_after' => $r['balance'] === null ? null : Money::str($r['balance']), 'reference' => $r['reference'],
                        'description' => $r['description'], 'partner_name' => $r['partnerName'], 'partner_account' => $r['partnerAccount'], 'txn_type' => $r['type'], 'fingerprint' => $r['fingerprint'],
                        'import_id' => $run->id, 'created_by' => $user->username, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    if ($inserted === 0) {
                        $held = $this->existing([$r['fingerprint']])[$r['fingerprint']] ?? ['statement' => null, 'lineNo' => null];
                    }
                }
                if ($held !== null) {
                    $duplicates++;
                    $report[$r['row']] = self::reportRow($r, 'duplicate', 'ROW_DUPLICATE') + ['existing' => $held];

                    continue;
                }
                $no++;
                $taken++;
                $total += $r['cents'];
                $report[$r['row']] = self::reportRow($r, 'taken', null) + ['lineNo' => $no];
            }
            ksort($report);
            $refused = count($records) - count($valid);
            $saved = null;
            if (! $dryRun) {
                // kept: counts, and for what was not taken the row number and the reason — never a figure or a text of the file
                $run->update(['rows_taken' => $taken, 'rows_duplicate' => $duplicates, 'rows_refused' => $refused,
                    'report' => array_slice(array_values(array_map(fn (array $x) => ['row' => $x['row'], 'status' => $x['status'], 'code' => $x['code']], array_filter($report, fn (array $x) => $x['status'] !== 'taken'))), 0, 3000)]);
                $this->audit->log($user, ['action' => 'BANK.IMPORT', 'entityType' => 'BankStatement', 'entityId' => $s->id, 'entityNumber' => $s->number, 'newValue' => [
                    'file' => $plan['table']['fileName'], 'sha256' => substr($plan['table']['hash'], 0, 16), 'rows' => count($records), 'taken' => $taken, 'duplicates' => $duplicates, 'refused' => $refused, 'total' => Money::str($total)]]);
                if (isset($dto['saveAs']) && trim((string) $dto['saveAs']) !== '') {
                    // the mapping that just worked is kept under that name for this bank account (a mapping of that name is replaced)
                    $name = mb_substr(trim((string) $dto['saveAs']), 0, 120);
                    $saved = $this->saveProfile($user, ['bankAccount' => $s->bank_account_id, 'name' => $name, 'options' => $plan['options'], 'mapping' => $plan['mapping'], 'isDefault' => (bool) ($dto['makeDefault'] ?? true)],
                        ImportProfile::where('bank_account_id', $s->bank_account_id)->where('name', $name)->where('active', true)->value('id'));
                }
            }

            return [
                'dryRun' => $dryRun, 'import' => $run ? Shape::import($run->refresh()) : null, 'total' => count($records), 'taken' => $taken, 'duplicates' => $duplicates, 'refused' => $refused,
                'takenTotal' => Money::str($total), 'balanceBreaks' => self::balanceBreaks($valid), 'lines' => array_values($report), 'profile' => $saved,
                'statement' => $this->statements->detail($user, $s->refresh()),
            ];
        });
    }

    private static function reportRow(array $r, string $status, ?string $code): array
    {
        return ['row' => $r['row'], 'status' => $status, 'code' => $code, 'reason' => $code ? ColumnMap::reason($code) : null, 'date' => $r['date'],
            'amount' => $r['cents'] === null ? null : Money::str($r['cents']), 'reference' => $r['reference']];
    }

    /** @return array<string, array{statement:?string, lineNo:?int}> the fingerprints a line already holds, and where */
    private function existing(array $fingerprints): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($fingerprints)), 500) as $chunk) {
            foreach (StatementLine::query()->toBase()->join('bank_statements as st', 'st.id', '=', 'bank_statement_lines.statement_id')->whereIn('bank_statement_lines.fingerprint', $chunk)
                ->get(['bank_statement_lines.fingerprint', 'bank_statement_lines.line_no', 'st.number']) as $row) {
                $out[$row->fingerprint] = ['statement' => $row->number, 'lineNo' => (int) $row->line_no];
            }
        }

        return $out;
    }

    /** Oldest first: a bank that lists the newest movement first is turned round, so line numbers follow time. */
    private static function chronological(array $valid, string $order): array
    {
        if ($order === 'desc' || ($order === 'auto' && count($valid) > 1 && $valid[0]['date'] > end($valid)['date'])) {
            return array_reverse($valid);
        }

        return $valid;
    }

    /** How many times the bank's running balance does not follow from the line before it (0 = the file is whole and in order). */
    private static function balanceBreaks(array $valid): int
    {
        $breaks = 0;
        $previous = null;
        foreach ($valid as $r) {
            if ($r['balance'] === null) {
                $previous = null;

                continue;
            }
            if ($previous !== null && $previous + $r['cents'] !== $r['balance']) {
                $breaks++;
            }
            $previous = $r['balance'];
        }

        return $breaks;
    }

    /**
     * How the file is to be read: the table, the header row, the columns, and the options and the mapping in force —
     * what the request says, else what the profile says, else what the file itself suggests.
     *
     * @return array{table:array, headerRow:int, columns:array, options:array, mapping:array, suggested:array, map:?array, problem:?array, dateOrderGuessed:bool}
     */
    private function plan(array $file, array $dto, ?ImportProfile $profile, bool $strict): array
    {
        $given = ColumnMap::options((array) ($dto['options'] ?? []));
        $kept = ColumnMap::options((array) ($profile?->options ?? []));
        $table = SheetFile::read($file, ['sheet' => $given['sheet'] ?? $kept['sheet'], 'delimiter' => $given['delimiter'] ?? $kept['delimiter'], 'encoding' => $given['encoding'] ?? $kept['encoding']]);
        if (! array_filter($table['rows'])) {
            throw AppError::validation('BANK_IMPORT_EMPTY', 'الملف لا يحوي أي سطر', 'The file holds no row at all');
        }
        $detected = ColumnMap::detectHeaderRow($table['rows']);
        $headerRow = $given['headerRow'] ?? $kept['headerRow'] ?? $detected;
        if ($given['headerRow'] === null && $kept['headerRow'] !== null && $detected > 0 && $detected !== $headerRow) {
            // the kept row of titles is the bank's usual layout; an export whose titles stand elsewhere (without the
            // title line above them, say) is read where its titles are — when the mapping is found there and not here
            $wanted = isset($dto['mapping']) && is_array($dto['mapping']) && $dto['mapping'] ? self::cleanMapping($dto['mapping']) : ($profile?->mapping ?: null);
            $mode = $given['amountMode'] ?? $kept['amountMode'] ?? 'signed';
            if ($wanted && ! self::fits($wanted, ColumnMap::columns($table['rows'], $headerRow), $mode) && self::fits($wanted, ColumnMap::columns($table['rows'], $detected), $mode)) {
                $headerRow = $detected;
            }
        }
        $columns = ColumnMap::columns($table['rows'], $headerRow);
        $suggested = ColumnMap::suggest($columns);
        $mapping = isset($dto['mapping']) && is_array($dto['mapping']) && $dto['mapping'] ? self::cleanMapping($dto['mapping']) : (($profile?->mapping ?: null) ?? $suggested['mapping']);
        $mode = $given['amountMode'] ?? $kept['amountMode'] ?? $suggested['amountMode'] ?? 'signed';
        $map = null;
        $problem = null;
        try {
            $map = ColumnMap::resolve($mapping, $columns, $mode);
        } catch (AppError $e) {
            if ($strict) {
                throw $e;
            }
            $problem = ['code' => $e->errorCode, 'ar' => $e->messageAr, 'en' => $e->messageEn];
            if ($profile && ! isset($dto['mapping'])) {
                // the bank changed its file: the kept mapping no longer fits — fall back to what the titles suggest
                $mapping = $suggested['mapping'];
                $mode = $given['amountMode'] ?? $suggested['amountMode'] ?? 'signed';
                try {
                    $map = ColumnMap::resolve($mapping, $columns, $mode);
                } catch (AppError) {
                    $map = null;
                }
            }
        }
        $data = array_slice($table['rows'], $headerRow, 400);
        $column = fn (string $field) => $map !== null && isset($map[$field]) && is_int($map[$field]) ? array_map(fn (array $row) => $row[$map[$field]] ?? null, $data) : [];
        $detected = Cells::dateOrder($column('date'));
        $order = $given['dateOrder'] ?? $kept['dateOrder'] ?? $detected ?? 'dmy';
        $decimal = $given['decimal'] ?? $kept['decimal'] ?? Cells::decimalMark(array_merge($column('amount'), $column('debit'), $column('credit')));

        return [
            'table' => $table, 'headerRow' => $headerRow, 'columns' => $columns, 'suggested' => $suggested, 'mapping' => $mapping, 'map' => $map, 'problem' => $problem,
            'options' => ['sheet' => $table['sheet'], 'delimiter' => $table['delimiter'], 'encoding' => $table['encoding'], 'headerRow' => $headerRow, 'dateOrder' => $order, 'decimal' => $decimal, 'amountMode' => $mode,
                'invert' => $given['invert'] || (! array_key_exists('invert', (array) ($dto['options'] ?? [])) && $kept['invert']), 'order' => $given['order'] !== 'auto' ? $given['order'] : $kept['order']],
            'dateOrderGuessed' => ($given['dateOrder'] ?? $kept['dateOrder'] ?? $detected) === null,
        ];
    }

    /** Does the mapping find its columns among these? */
    private static function fits(array $mapping, array $columns, string $mode): bool
    {
        try {
            ColumnMap::resolve($mapping, $columns, $mode);

            return true;
        } catch (AppError) {
            return false;
        }
    }

    /** A mapping as the client sends it: known fields only, a column name (or `#N`) each; `description` may be a list. */
    private static function cleanMapping(array $mapping): array
    {
        $out = [];
        foreach (ColumnMap::FIELDS as $field) {
            $value = $mapping[$field] ?? null;
            if (is_array($value)) {
                $value = array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? mb_substr(trim((string) $v), 0, 120) : '', $value), fn (string $v) => $v !== ''));
                $value = $field === 'description' ? $value : ($value[0] ?? null);
            } elseif (is_scalar($value)) {
                $value = mb_substr(trim((string) $value), 0, 120);
            } else {
                $value = null;
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $out[$field] = $value;
            }
        }

        return $out;
    }

    // ───────────────────────────── saved mappings ─────────────────────────────

    /** The profile named, else the default one of the bank account, else none. */
    private function profileFor(?string $profileId, ?string $bank): ?ImportProfile
    {
        if ($profileId) {
            return ImportProfile::whereKey($profileId)->first() ?? throw AppError::notFound('BANK_PROFILE_NOT_FOUND', 'ربط الأعمدة المحفوظ غير موجود', 'Saved column mapping not found');
        }
        if (! $bank) {
            return null;
        }
        $bankId = $this->banks->find($bank)->id;

        return ImportProfile::where('bank_account_id', $bankId)->where('active', true)->orderByDesc('is_default')->orderByDesc('updated_at')->orderBy('id')->first();
    }

    /** @return array<int, array<string, mixed>> */
    public function profiles(?string $bank, bool $withInactive = false): array
    {
        $q = ImportProfile::with('bankAccount:id,code,name_ar,name_en,kind')->when(! $withInactive, fn ($w) => $w->where('active', true));
        if ($bank) {
            $id = $this->banks->find($bank)->id;
            $q->where(fn ($w) => $w->where('bank_account_id', $id)->orWhereNull('bank_account_id'));
        }

        return $q->orderByDesc('is_default')->orderBy('name')->orderBy('id')->get()->map(fn (ImportProfile $p) => Shape::profile($p))->all();
    }

    /**
     * @param  array{bankAccount?:?string, name?:string, options?:array, mapping?:array, isDefault?:bool, active?:bool}  $dto
     */
    public function saveProfile(AuthUser $user, array $dto, ?string $id = null): array
    {
        return DB::transaction(function () use ($user, $dto, $id) {
            $p = $id ? (ImportProfile::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('BANK_PROFILE_NOT_FOUND', 'ربط الأعمدة المحفوظ غير موجود', 'Saved column mapping not found')) : new ImportProfile(['active' => true]);
            $bankId = array_key_exists('bankAccount', $dto) ? ($dto['bankAccount'] ? $this->banks->find((string) $dto['bankAccount'])->id : null) : $p->bank_account_id;
            $name = array_key_exists('name', $dto) ? trim((string) $dto['name']) : (string) $p->name;
            if ($name === '') {
                throw AppError::validation('BANK_PROFILE_NAME', 'اسم ربط الأعمدة إلزامي', 'The mapping needs a name', [['path' => 'name', 'message' => 'هذا الحقل إلزامي']]);
            }
            if (ImportProfile::where('name', $name)->where('active', true)->where('bank_account_id', $bankId)->when($p->exists, fn ($w) => $w->where('id', '!=', $p->id))->exists()) {
                throw AppError::conflict('BANK_PROFILE_EXISTS', "يوجد ربط أعمدة بالاسم «{$name}» لهذا الحساب", "A mapping named “{$name}” already exists for this bank account");
            }
            $options = array_key_exists('options', $dto) ? ColumnMap::options((array) $dto['options']) : ColumnMap::options((array) ($p->options ?? []));
            $mapping = array_key_exists('mapping', $dto) ? self::cleanMapping((array) $dto['mapping']) : (array) ($p->mapping ?? []);
            $mode = $options['amountMode'] ?? 'signed';
            $need = ['date'] + [1 => $mode === 'debit_credit' ? 'debit' : 'amount', 2 => $mode === 'debit_credit' ? 'credit' : ($mode === 'amount_type' ? 'type' : 'amount')];
            foreach (array_unique($need) as $field) {
                if (! isset($mapping[$field])) {
                    throw AppError::validation('BANK_IMPORT_MAPPING', "ربط الأعمدة ناقص: حدّد عمود «{$field}»", "The column mapping is incomplete: choose the “{$field}” column", [['path' => "mapping.{$field}", 'message' => 'هذا العمود إلزامي']]);
                }
            }
            $default = array_key_exists('isDefault', $dto) ? (bool) $dto['isDefault'] : (bool) $p->is_default;
            $active = array_key_exists('active', $dto) ? (bool) $dto['active'] : (bool) ($p->active ?? true);
            if ($default && $active) {
                ImportProfile::where('bank_account_id', $bankId)->when($p->exists, fn ($w) => $w->where('id', '!=', $p->id))->update(['is_default' => false]);   // one default per bank account
            }
            $p->fill(['bank_account_id' => $bankId, 'name' => mb_substr($name, 0, 120), 'options' => $options, 'mapping' => $mapping, 'is_default' => $default && $active, 'active' => $active, 'updated_by' => $user->username]
                + ($p->exists ? [] : ['created_by' => $user->username]));
            $created = ! $p->exists;
            $p->save();
            $this->audit->log($user, ['action' => $created ? 'BANK.PROFILE.CREATE' : 'BANK.PROFILE.UPDATE', 'entityType' => 'BankImportProfile', 'entityId' => $p->id, 'entityNumber' => $p->name,
                'newValue' => ['bank' => $bankId ? $this->banks->find($bankId)->code : null, 'amountMode' => $mode, 'fields' => array_keys($mapping), 'default' => $p->is_default, 'active' => $p->active]]);

            return Shape::profile($p->refresh()->load('bankAccount:id,code,name_ar,name_en,kind'));
        });
    }

    /** The statements of a bank account that could take a file of this period — for the screen that starts from the file. */
    public function openStatements(string $bank): array
    {
        return Statement::where('bank_account_id', $this->banks->find($bank)->id)->where('status', 'open')->orderByDesc('date_to')->limit(24)->get(['id', 'number', 'name', 'date_from', 'date_to'])
            ->map(fn (Statement $s) => ['id' => $s->id, 'number' => $s->number, 'name' => $s->name, 'dateFrom' => $s->date_from->toDateString(), 'dateTo' => $s->date_to->toDateString()])->all();
    }
}
