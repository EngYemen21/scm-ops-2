<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The index of the Odoo archive (docs/odoo/WAVE2.md §3). The copy of Odoo (`odoo_records`) keeps every record as Odoo
 * returned it, in JSON; this table says, with typed columns, what each of those records IS for a person — its kind,
 * number, title, partner, date, state, amount, what it hangs on — so that every list of the archive is one range of
 * an index, on MySQL and on PostgreSQL alike. It is derived data: `php artisan scm:odoo-archive-index --fresh`
 * rebuilds it from the copy at any time, and nothing here is ever written back to `odoo_*` or to Odoo.
 *
 * Why the columns are what they are:
 *  - `live` (the record still exists in Odoo) leads every index after the kind, so a list and its count never look at
 *    the rows of what was deleted in Odoo — which the archive keeps, and lists apart.
 *  - `doc_date`, `number`, `title` and `amount` are NOT NULL: the two engines order NULLs differently, and a sorted
 *    list must be the same on both.
 *  - `amount` closes the filter indexes, so a list's count and total are read from the index alone.
 *  - the text columns of the list index are kept short by the writer (the search text 360 characters and 1,000
 *    bytes at most, a dimension 240 bytes): an index entry stays far below what PostgreSQL accepts for one.
 *
 * Each table is created only where it does not exist: on the development database they were created by hand, ahead
 * of this migration, to measure the index on the real copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('odoo_docs')) {
            Schema::create('odoo_docs', function (Blueprint $t) {
                $t->id();
                $t->string('kind', 64);                              // key of the kind in config/odoo_archive.php
                $t->string('model', 120);                            // the Odoo model …
                $t->bigInteger('odoo_id');                           // … and the id of the record in it
                $t->boolean('live')->default(true);                  // false: the record no longer exists in Odoo
                $t->boolean('active')->default(true);                // Odoo's own archive flag (true where the model has none)
                $t->boolean('private')->default(false);              // people's file, or hangs on one: needs odoo.private
                $t->string('number', 120)->default('');              // document number / code
                $t->string('title', 255)->default('');               // what a person calls it
                $t->bigInteger('partner_id')->nullable();
                $t->string('partner_name', 255)->nullable();
                $t->bigInteger('product_id')->nullable();            // product.product
                $t->string('parent_model', 120)->nullable();         // what it hangs on: the order of a line, the document
                $t->bigInteger('parent_id')->nullable();             // of a message or an attachment, the source of a document
                $t->integer('seq')->default(0);                      // position among the children of the parent
                $t->dateTime('doc_date');                            // the kind's own date (UTC), else the creation date
                $t->string('state', 64)->nullable();
                $t->decimal('amount', 20, 4)->default(0);            // Odoo's own figure
                $t->decimal('residual', 20, 4)->nullable();          // what is still open of it (invoices, bills)
                $t->string('currency', 12)->nullable();
                $t->bigInteger('user_id')->nullable();               // responsible user
                $t->string('user_name', 190)->nullable();
                $t->string('dim1', 120)->nullable();                 // two free dimensions, named by the kind
                $t->string('dim2', 120)->nullable();
                $t->string('search', 360)->nullable();               // lower-cased, letters folded: what the search box matches
                $t->integer('rec_version')->default(0);              // odoo_records.version the row was made from
                $t->dateTime('indexed_at', 3);

                $t->unique(['model', 'odoo_id'], 'odoo_docs_record_uq');
                // THE LIST: a kind, newest first. It carries everything a list is filtered by — and the search text — so
                // that a search, or several filters at once, are answered by reading this index alone (a "contains"
                // search has to look at every document of the kind; in the index that is a fraction of reading the rows)
                $t->index(['kind', 'live', 'doc_date', 'odoo_id', 'amount', 'currency', 'state', 'dim1', 'dim2', 'search'], 'odoo_docs_list_ix');
                // one filter, newest first: straight to the documents of one state / one value of a dimension
                $t->index(['kind', 'live', 'state', 'doc_date', 'odoo_id', 'amount'], 'odoo_docs_state_ix');
                $t->index(['kind', 'live', 'dim1', 'doc_date', 'odoo_id', 'amount'], 'odoo_docs_dim1_ix');
                $t->index(['kind', 'live', 'dim2', 'doc_date', 'odoo_id', 'amount'], 'odoo_docs_dim2_ix');
                // the other orders a list offers (a list by title is small enough to be sorted when it is asked for)
                $t->index(['kind', 'live', 'number', 'odoo_id'], 'odoo_docs_number_ix');
                $t->index(['kind', 'live', 'amount', 'odoo_id'], 'odoo_docs_amount_ix');
                // everything of one partner / one product (their pages, and the partner / product filter of a list)
                $t->index(['partner_id', 'live', 'kind', 'doc_date', 'odoo_id', 'amount'], 'odoo_docs_partner_ix');
                $t->index(['product_id', 'live', 'kind', 'doc_date', 'odoo_id', 'amount'], 'odoo_docs_product_ix');
                // the lines, messages, attachments and follow-up documents of one document, in their order
                $t->index(['parent_model', 'parent_id', 'kind', 'seq', 'odoo_id'], 'odoo_docs_parent_ix');
            });
        }

        // how far the index of each model got: the build can be stopped at any moment and goes on from here
        if (! Schema::hasTable('odoo_docs_state')) {
            Schema::create('odoo_docs_state', function (Blueprint $t) {
                $t->string('model', 120)->primary();
                $t->bigInteger('cursor_id')->default(0);             // first build: records up to this Odoo id are indexed
                $t->boolean('done')->default(false);                 // the first build of the model is complete
                $t->dateTime('mark', 3)->nullable();                 // changes of the copy up to this moment are in the index
                $t->string('defs', 40)->nullable();                  // hash of the definitions the rows were made with
                $t->bigInteger('rows')->default(0);
                $t->dateTime('built_at', 3)->nullable();
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_docs_state');
        Schema::dropIfExists('odoo_docs');
    }
};
