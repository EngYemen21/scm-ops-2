<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL only — a no-op on MySQL. Re-applies the CITEXT conversion of 2026_09_17_000000_pgsql_compatibility to
 * every text / char column added by the integration layer (int_* tables, sales_orders.source_system / external_ref, customers.cr / vat_no). Without it a
 * CHAR(26) id column returns an imported 25-character id padded with a space, so `$driver->phone_trip_id === $trip->id`
 * is false on PostgreSQL only (found by the PostgreSQL test run).
 *
 * Already-converted columns report data_type USER-DEFINED and are skipped, so this is safe to copy into any later
 * migration that adds text columns: run it last.
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
            if (! in_array($c->table_name, self::FRAMEWORK_TABLES, true)) {
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
