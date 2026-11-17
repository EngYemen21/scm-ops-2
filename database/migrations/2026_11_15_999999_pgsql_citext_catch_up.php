<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL only — a no-op on MySQL. Re-applies the CITEXT conversion of 2026_09_17_000000_pgsql_compatibility to
 * every text / char column added by the operational-hardening migrations of 2026-11-15 (this file sorts after all of
 * them on purpose: a migration of that day that adds text columns needs nothing else). Already-converted columns
 * report data_type USER-DEFINED and are skipped. `stored_files.content` (base64 file bodies) stays plain text.
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
        $columns = DB::select("SELECT table_name, column_name FROM information_schema.columns
            WHERE table_schema = current_schema() AND data_type IN ('character', 'character varying', 'text')
            ORDER BY table_name, ordinal_position");
        $byTable = [];
        foreach ($columns as $c) {
            if (! in_array($c->table_name, self::FRAMEWORK_TABLES, true) && ! ($c->table_name === 'stored_files' && $c->column_name === 'content')) {
                // CHAR(n) → citext keeps the padding it already stored; trim it on the way
                $byTable[$c->table_name][] = sprintf('ALTER COLUMN "%1$s" TYPE citext USING rtrim("%1$s")::citext', $c->column_name);
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
