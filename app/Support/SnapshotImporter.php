<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Data migration, step 2: loads a snapshot produced by tools/snapshot-reference-db.mjs into this database.
 * Used by the demo seeder and by `php artisan scm:import-snapshot` for moving live data from the old system.
 *
 * Ids are kept as they are (the old system's 25-char cuids fit the 26-char id columns), so every reference
 * between rows survives untouched. Column names map camelCase -> snake_case; datetimes are stored in UTC.
 */
final class SnapshotImporter
{
    /**
     * @param  ?string  $resetPasswordTo  when set, every user gets this password (demo data); null keeps the
     *                                    imported hashes (live migration — bcrypt hashes stay valid).
     * @return array<string,int> rows imported per table
     */
    public function import(string $file, ?string $resetPasswordTo = null, bool $truncate = true): array
    {
        if (! is_file($file)) {
            throw new RuntimeException("snapshot not found: {$file}");
        }
        $snapshot = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $hash = $resetPasswordTo !== null ? Hash::make($resetPasswordTo) : null;
        $counts = [];

        foreach (array_keys($snapshot['tables']) as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("snapshot table `{$table}` does not exist here — schemas are out of sync");
            }
        }

        // Tables arrive in no particular order, so the foreign keys are checked at the end, not per row:
        // MySQL switches the checks off for the session; PostgreSQL defers them (the keys are DEFERRABLE) to the commit
        // of the surrounding transaction — which also makes the whole import all-or-nothing there.
        $load = function () use ($snapshot, $truncate, $hash, &$counts) {
            Schema::disableForeignKeyConstraints();
            try {
                if ($truncate) {
                    // Everything is emptied BEFORE the first insert: PostgreSQL's TRUNCATE cascades to referencing
                    // tables, so emptying table by table would wipe rows that were just imported.
                    foreach ([...array_keys($snapshot['tables']), 'refresh_tokens', 'idempotency_keys'] as $table) {
                        DB::table($table)->truncate();
                    }
                }
                foreach ($snapshot['tables'] as $table => $spec) {
                    $rows = [];
                    foreach ($spec['rows'] as $row) {
                        $out = [];
                        foreach ($row as $field => $value) {
                            $out[Str::snake($field)] = self::convert($value, $spec['columns'][$field] ?? 'String');
                        }
                        if ($table === 'users' && $hash !== null) {
                            $out['password_hash'] = $hash;
                        }
                        $rows[] = $out;
                    }
                    foreach (array_chunk($rows, 200) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                    $counts[$table] = count($rows);
                }
            } finally {
                if (DB::getDriverName() !== 'pgsql') {
                    Schema::enableForeignKeyConstraints();
                }
            }
        };
        DB::getDriverName() === 'pgsql' ? DB::transaction($load) : $load();

        return $counts;
    }

    private static function convert(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'DateTime' => Carbon::parse($value)->utc()->format('Y-m-d H:i:s.v'),
            'Json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'Boolean' => $value ? 1 : 0,
            default => $value,
        };
    }
}
