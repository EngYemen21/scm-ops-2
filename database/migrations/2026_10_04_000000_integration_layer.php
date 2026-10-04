<?php

use App\Integration\IntegrationBootstrap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B2B Integration Layer (docs/integration/ARCHITECTURE.md). Its own `int_*` tables — the layer never shares a table
 * with another system — plus the event envelope on the existing transactional outbox (`integration_events`) and the
 * source reference that makes an order received from another system impossible to create twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        // the existing outbox becomes the event store: envelope fields (id stays the event id)
        Schema::table('integration_events', function (Blueprint $table) {
            $table->string('source', 40)->default('ops');
            $table->string('subject', 120)->nullable();       // entity the event is about (e.g. the Sales order id)
            $table->bigInteger('sequence')->nullable();        // monotonic per (source, subject)
            $table->string('correlation_id', 120)->nullable(); // business journey id
            $table->string('causation_id', 40)->nullable();
            $table->integer('schema_version')->default(1);
            $table->index(['correlation_id'], 'integration_events_corr_ix');
            $table->index(['subject'], 'integration_events_subject_ix');
        });

        // one delivery per (event, subscriber): retries with back-off, dead letter, response timing
        Schema::create('int_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('event_id');
            $table->string('subscriber', 40);
            $table->string('status', 20)->default('pending'); // pending | sent | failed | dead
            $table->integer('attempts')->default(0);
            $table->dateTime('next_attempt_at', 3)->nullable();
            $table->integer('last_status')->nullable();        // HTTP status of the last attempt
            $table->text('last_error')->nullable();
            $table->integer('response_ms')->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('sent_at', 3)->nullable();
            $table->unique(['event_id', 'subscriber'], 'int_deliveries_event_sub_uq');
            $table->index(['status', 'next_attempt_at'], 'int_deliveries_due_ix');
        });

        // per-(source, subject) event counter for the events we emit
        Schema::create('int_sequences', function (Blueprint $table) {
            $table->string('source', 40);
            $table->string('subject', 120);
            $table->bigInteger('last')->default(0);
            $table->primary(['source', 'subject']);
        });

        // every event received from another system, deduplicated by its id
        Schema::create('int_inbox', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('event_id', 64)->unique();
            $table->string('source', 40);
            $table->string('type', 80);
            $table->string('subject', 120);
            $table->bigInteger('sequence')->nullable();
            $table->string('correlation_id', 120)->nullable();
            $table->string('causation_id', 64)->nullable();
            $table->integer('schema_version')->default(1);
            $table->dateTime('event_time', 3)->nullable();
            $table->json('data');
            // received | processed | stale | blocked | failed | dead | rejected
            $table->string('status', 20)->default('received');
            $table->integer('attempts')->default(0);
            $table->dateTime('next_attempt_at', 3)->nullable();
            $table->string('last_code', 60)->nullable();
            $table->text('last_error')->nullable();
            $table->json('result')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->dateTime('received_at', 3)->useCurrent();
            $table->dateTime('processed_at', 3)->nullable();
            $table->index(['status', 'next_attempt_at'], 'int_inbox_due_ix');
            $table->index(['correlation_id'], 'int_inbox_corr_ix');
            $table->index(['source', 'subject'], 'int_inbox_subject_ix');
        });

        // the last applied event per (source, subject): out-of-order events older than this are skipped
        Schema::create('int_subject_state', function (Blueprint $table) {
            $table->string('source', 40);
            $table->string('subject', 120);
            $table->bigInteger('last_sequence')->default(0);
            $table->string('last_event_id', 64)->nullable();
            $table->string('last_type', 80)->nullable();
            $table->dateTime('updated_at', 3)->nullable();
            $table->primary(['source', 'subject']);
        });

        // identity links between systems: (system, entity, their id) ↔ our id — unique both ways
        Schema::create('int_external_refs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('system', 40);
            $table->string('entity', 40);          // customer | site | product | order
            $table->string('external_id', 120);
            $table->string('internal_id', 40);
            $table->string('internal_code', 120)->nullable();
            $table->json('meta')->nullable();
            $table->string('created_by', 120)->nullable();
            $table->timestamps(3);
            $table->unique(['system', 'entity', 'external_id'], 'int_refs_external_uq');
            $table->unique(['system', 'entity', 'internal_id'], 'int_refs_internal_uq');
        });

        // integration exceptions: mapping gaps, rejected / dead events, reconciliation mismatches — for humans
        Schema::create('int_exceptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 60);
            $table->string('severity', 10)->default('warn'); // info | warn | crit
            $table->string('system', 40)->nullable();
            $table->string('entity', 40)->nullable();
            $table->string('entity_ref', 120)->nullable();
            $table->string('correlation_id', 120)->nullable();
            $table->string('event_id', 64)->nullable();
            $table->text('message');
            $table->json('details')->nullable();
            $table->string('status', 20)->default('open');   // open | resolved | ignored
            $table->integer('occurrences')->default(1);
            $table->dateTime('first_at', 3)->useCurrent();
            $table->dateTime('last_at', 3)->useCurrent();
            $table->string('resolved_by', 120)->nullable();
            $table->dateTime('resolved_at', 3)->nullable();
            $table->text('resolution')->nullable();
            $table->index(['status', 'code'], 'int_exceptions_open_ix');
            $table->index(['correlation_id'], 'int_exceptions_corr_ix');
        });

        // the latest copy of another system's master record (e.g. a Sales product) — read-only, for mapping and display
        Schema::create('int_mirror', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('system', 40);
            $table->string('entity', 40);
            $table->string('external_id', 120);
            $table->string('label', 255)->nullable();
            $table->json('data');
            $table->string('last_event_id', 64)->nullable();
            $table->timestamps(3);
            $table->unique(['system', 'entity', 'external_id'], 'int_mirror_uq');
        });

        // delivery sites of a customer (a Sales customer has several branches; OPS verifies the coordinates)
        Schema::create('customer_sites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('customer_id');
            $table->string('external_key', 160)->nullable(); // the branch key in the source system
            $table->string('name', 200);
            $table->string('city', 120)->nullable();
            $table->text('address')->nullable();
            $table->double('lat')->nullable();
            $table->double('lng')->nullable();
            $table->boolean('coords_verified')->default(false); // set by OPS (map), never by the source
            $table->string('window', 40)->nullable();
            $table->string('contact', 120)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps(3);
            $table->unique(['customer_id', 'external_key'], 'customer_sites_ext_uq');
        });

        // an order received from another system carries its origin: never created twice (unique), always traceable
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('source_system', 40)->nullable();
            $table->string('external_ref', 120)->nullable();
            $table->ulid('site_id')->nullable();                // delivery site (customer_sites)
            $table->json('commercial')->nullable();             // the source's commercial snapshot: totals, VAT, rep, notes
            $table->string('fulfil_status', 30)->nullable();    // integration view: full | partial | backordered
            $table->unique(['source_system', 'external_ref'], 'sales_orders_source_ref_uq');
        });
        // the commercial identity Sales owns (CR / VAT were accepted by the API but had nowhere to go)
        Schema::table('customers', function (Blueprint $table) {
            $table->string('cr', 40)->nullable();
            $table->string('vat_no', 40)->nullable();
            $table->string('source_system', 40)->nullable();
        });

        // existing databases only: on a fresh one the seeder does it after importing users (a service user created here
        // would make the seeder believe the database is already seeded and skip it)
        if (DB::table('users')->exists()) {
            IntegrationBootstrap::ensure();
        }
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['cr', 'vat_no', 'source_system']));
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->dropUnique('sales_orders_source_ref_uq');
            $t->dropColumn(['source_system', 'external_ref', 'site_id', 'commercial', 'fulfil_status']);
        });
        foreach (['customer_sites', 'int_mirror', 'int_exceptions', 'int_external_refs', 'int_subject_state', 'int_inbox', 'int_sequences', 'int_deliveries'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('integration_events', function (Blueprint $t) {
            $t->dropIndex('integration_events_corr_ix');
            $t->dropIndex('integration_events_subject_ix');
            $t->dropColumn(['source', 'subject', 'sequence', 'correlation_id', 'causation_id', 'schema_version']);
        });
    }
};
