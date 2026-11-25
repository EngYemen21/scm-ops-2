<?php

use App\Banking\Http\BankingController;
use App\Banking\Http\MatchingController;
use App\Banking\Http\StatementsController;
use Illuminate\Support\Facades\Route;

/*
 * «البنوك والمطابقة» — /api/banking (docs/odoo/WAVE4.md, Q4). The bank accounts themselves are finance's
 * (/api/finance/banks); this section holds their statements and reconciles them with the ledger.
 *
 *   page.banking          reads everything of the section
 *   bankstatement.manage  enters statements: create, type and import lines, the column mappings, void; links the
 *                         source system's journals to OPS bank accounts (making a bank account also needs bank.manage)
 *   bank.reconcile        reconciles: confirm and undo matches, the write-off rules, lock and reopen a statement
 *
 * Account numbers and IBANs leave in full only for a holder of bankstatement.manage / bank.reconcile / bank.manage.
 * A record that came from Odoo is read-only while the section follows Odoo (422 BANKING_OWNED_BY_SOURCE); a
 * reconciled statement is immutable until reopened (422 BANK_STATEMENT_LOCKED).
 */
Route::prefix('banking')->group(function () {
    Route::middleware('perm:page.banking')->group(function () {
        Route::get('lookups', [BankingController::class, 'lookups']);
        Route::get('accounts', [BankingController::class, 'accounts']);
        Route::get('reconciliation', [BankingController::class, 'reconciliation']);
        Route::get('journals', [BankingController::class, 'journals']);
        Route::get('payment-terms', [MatchingController::class, 'terms']);
        // statements and their lines
        Route::get('statements', [StatementsController::class, 'index']);
        Route::get('statements/{id}', [StatementsController::class, 'show']);
        Route::get('lines', [StatementsController::class, 'lines']);
        Route::get('lines/{id}', [StatementsController::class, 'line']);
        Route::get('profiles', [StatementsController::class, 'profiles']);
        // the books' side, the proposals, the matches, the rules
        Route::get('ledger-items', [MatchingController::class, 'ledgerItems']);
        Route::get('lines/{id}/suggestions', [MatchingController::class, 'suggestions']);
        Route::get('matches', [MatchingController::class, 'index']);
        Route::get('matches/{id}', [MatchingController::class, 'show']);
        Route::get('rules', [MatchingController::class, 'rules']);
        Route::get('rules/{id}', [MatchingController::class, 'rule']);
    });

    Route::middleware('perm:bankstatement.manage')->group(function () {
        Route::post('statements', [StatementsController::class, 'store']);
        Route::patch('statements/{id}', [StatementsController::class, 'update']);
        Route::post('statements/{id}/void', [StatementsController::class, 'void']);
        Route::post('statements/{id}/lines', [StatementsController::class, 'storeLines']);
        Route::patch('lines/{id}', [StatementsController::class, 'updateLine']);
        Route::delete('lines/{id}', [StatementsController::class, 'destroyLine']);
        // a bank's file: how it would be read, then the import (safe to repeat)
        Route::post('import/preview', [StatementsController::class, 'preview']);
        Route::post('statements/{id}/import', [StatementsController::class, 'import']);
        Route::post('profiles', [StatementsController::class, 'storeProfile']);
        Route::patch('profiles/{id}', [StatementsController::class, 'updateProfile']);
        // which OPS bank account a journal of the source system is
        Route::post('journals/{id}/link', [BankingController::class, 'linkJournal']);
        Route::post('journals/{id}/unlink', [BankingController::class, 'unlinkJournal']);
        Route::post('journals/{id}/adopt', [BankingController::class, 'adoptJournal']);
    });

    Route::middleware('perm:bank.reconcile')->group(function () {
        Route::post('matches', [MatchingController::class, 'store']);
        Route::post('matches/{id}/undo', [MatchingController::class, 'undo']);
        Route::post('statements/{id}/auto-match', [StatementsController::class, 'autoMatch']);
        Route::post('statements/{id}/reconcile', [StatementsController::class, 'reconcile']);
        Route::post('statements/{id}/reopen', [StatementsController::class, 'reopen']);
        Route::post('rules', [MatchingController::class, 'storeRule']);
        Route::patch('rules/{id}', [MatchingController::class, 'updateRule']);
    });
});
