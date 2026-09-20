<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL only — a no-op on MySQL. It runs between the schema and the foreign-key migration (the file name sorts
 * there), so both sides of every foreign key already have the same type when the keys are created.
 *
 * The application was written against MySQL with the utf8mb4 *_ci collations and relies on two of its habits:
 *
 *  1. Text compares case-insensitively: `WHERE code = 'dmm'` finds DMM, a scanned "po-2026-00012" finds the PO, unique
 *     keys reject "ABC" next to "abc". PostgreSQL's CITEXT type gives exactly that, for =, LIKE, IN, ORDER BY and
 *     unique indexes. It also replaces CHAR(n), which PostgreSQL returns padded with spaces (imported 25-character
 *     ids would come back with a trailing space).
 *  2. ROUND(x, n) accepts a float. PostgreSQL only rounds NUMERIC to a scale, so an overload is added.
 *
 * Laravel's own tables (cache, jobs, migrations) keep their types: cache keys are case-sensitive.
 */
return new class extends Migration
{
    private const FRAMEWORK_TABLES = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        DB::statement('CREATE OR REPLACE FUNCTION round(double precision, integer) RETURNS numeric
            LANGUAGE sql IMMUTABLE AS $$ SELECT round($1::numeric, $2) $$');

        $columns = DB::select("SELECT table_name, column_name FROM information_schema.columns
            WHERE table_schema = current_schema() AND data_type IN ('character', 'character varying', 'text')
            ORDER BY table_name, ordinal_position");
        $byTable = [];
        foreach ($columns as $c) {
            if (! in_array($c->table_name, self::FRAMEWORK_TABLES, true)) {
                $byTable[$c->table_name][] = sprintf('ALTER COLUMN "%s" TYPE citext', $c->column_name);
            }
        }
        foreach ($byTable as $table => $changes) {
            DB::statement(sprintf('ALTER TABLE "%s" %s', $table, implode(', ', $changes)));
        }
    }

    public function down(): void
    {
        // the columns go with their tables
    }
};
