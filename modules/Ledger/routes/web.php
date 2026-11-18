<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Ledger\Http\Controllers\AccountController;
use Modules\Ledger\Http\Controllers\AccountingController;
use Modules\Ledger\Http\Controllers\AdjustmentController;
use Modules\Ledger\Http\Controllers\AuditController;
use Modules\Ledger\Http\Controllers\BooksController;
use Modules\Ledger\Http\Controllers\JournalController;
use Modules\Ledger\Http\Controllers\PeriodController;
use Modules\Ledger\Http\Controllers\StatementsController;

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])
    ->prefix('finance')
    ->name('finance.')
    ->group(function (): void {
        Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::post('accounts/{account}/archive', [AccountController::class, 'archive'])->name('accounts.archive');
        Route::get('accounts/{account}/statement', [AccountController::class, 'statement'])->name('accounts.statement');
        Route::get('accounts/{account}/statement.csv', [AccountController::class, 'export'])->name('accounts.export');

        Route::get('journals', [JournalController::class, 'index'])->name('journals.index');
        Route::get('journals/{journal}', [JournalController::class, 'show'])->name('journals.show');
        Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->name('journals.reverse');
        Route::get('adjustments/create', [JournalController::class, 'createAdjustment'])->name('adjustments.create');
        Route::post('adjustments', [JournalController::class, 'storeAdjustment'])->name('adjustments.store');

        Route::get('books', [BooksController::class, 'index'])->name('books.index');
        Route::post('books/{connection}', [BooksController::class, 'save'])->where('connection', '[0-9A-HJKMNP-TV-Z]{26}')->name('books.save');

        // AC1 (specs/finance/20): bills, invoices, bank reconciliation.
        Route::get('bills', [AccountingController::class, 'bills'])->name('bills.index');
        Route::post('bills', [AccountingController::class, 'storeBill'])->middleware('throttle:60,1')->name('bills.store');
        Route::post('bills/{bill}/pay', [AccountingController::class, 'payBill'])->where('bill', '[0-9A-HJKMNP-TV-Z]{26}')->name('bills.pay');
        Route::post('bills/{bill}/void', [AccountingController::class, 'voidBill'])->where('bill', '[0-9A-HJKMNP-TV-Z]{26}')->name('bills.void');
        Route::get('bills/{bill}/file', [AccountingController::class, 'billFile'])->where('bill', '[0-9A-HJKMNP-TV-Z]{26}')->name('bills.file');
        Route::get('invoices', [AccountingController::class, 'invoices'])->name('invoices.index');
        Route::post('invoices', [AccountingController::class, 'storeInvoice'])->middleware('throttle:60,1')->name('invoices.store');
        Route::post('invoices/{invoice}/paid', [AccountingController::class, 'invoicePaid'])->where('invoice', '[0-9A-HJKMNP-TV-Z]{26}')->name('invoices.paid');
        Route::post('invoices/{invoice}/void', [AccountingController::class, 'voidInvoice'])->where('invoice', '[0-9A-HJKMNP-TV-Z]{26}')->name('invoices.void');
        Route::get('invoices/{invoice}.pdf', [AccountingController::class, 'invoicePdf'])->where('invoice', '[0-9A-HJKMNP-TV-Z]{26}')->name('invoices.pdf');
        Route::get('bank', [AccountingController::class, 'bank'])->name('bank.index');
        // BK1: the live, read-only bank feed.
        Route::post('bank/feed/start', [AccountingController::class, 'feedStart'])->middleware('throttle:10,1')->name('bank.feed.start');
        Route::post('bank/feed/finish', [AccountingController::class, 'feedFinish'])->middleware(['throttle:10,1', 'password.confirm'])->name('bank.feed.finish');
        Route::post('bank/feed/account', [AccountingController::class, 'feedAccount'])->name('bank.feed.account');
        Route::post('bank/feed/sync', [AccountingController::class, 'feedSync'])->middleware('throttle:6,1')->name('bank.feed.sync');
        Route::post('bank/feed/disconnect', [AccountingController::class, 'feedDisconnect'])->middleware('password.confirm')->name('bank.feed.disconnect');
        Route::post('bank/from-feed', [AccountingController::class, 'importFromFeed'])->middleware('throttle:20,1')->name('bank.from-feed');
        Route::post('bank/import', [AccountingController::class, 'importBank'])->middleware('throttle:20,1')->name('bank.import');
        Route::post('bank/lines/{line}/add', [AccountingController::class, 'addToBooks'])->whereNumber('line')->name('bank.add');
        Route::post('bank/lines/{line}/match', [AccountingController::class, 'matchBank'])->whereNumber('line')->name('bank.match');
        Route::post('bank/approve', [AccountingController::class, 'approveBank'])->name('bank.approve');

        // AC2 (specs/finance/21): profit and loss, and the balance sheet.
        Route::get('statements', [StatementsController::class, 'index'])->name('statements.index');
        Route::get('statements.pdf', [StatementsController::class, 'pdf'])->middleware('throttle:20,1')->name('statements.pdf');
        Route::get('statements.csv', [StatementsController::class, 'csv'])->middleware('throttle:20,1')->name('statements.csv');

        Route::get('periods', [PeriodController::class, 'index'])->name('periods.index');
        Route::post('periods/{period}/close', [PeriodController::class, 'close'])->name('periods.close');
        // FH1 (specs/finance/22): month-end checklist, statements as closed, and adjustments approved by a second person.
        Route::post('periods/{period}/check', [PeriodController::class, 'check'])->middleware('throttle:20,1')->name('periods.check');
        Route::get('periods/{period}/statements.pdf', [PeriodController::class, 'statements'])->middleware('throttle:20,1')->name('periods.statements');
        Route::get('adjustments', [AdjustmentController::class, 'index'])->name('adjustments.index');
        // FH2 (specs/finance/23): audit pack and outside-auditor access.
        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
        Route::post('audit/packs', [AuditController::class, 'requestPack'])->middleware('throttle:6,1')->name('audit.packs.store');
        Route::get('audit/packs/{pack}', [AuditController::class, 'download'])->where('pack', '[0-9A-HJKMNP-TV-Z]{26}')->middleware(['password.confirm', 'throttle:20,1'])->name('audit.packs.download');
        Route::post('audit/packs/{pack}/password', [AuditController::class, 'password'])->where('pack', '[0-9A-HJKMNP-TV-Z]{26}')->middleware(['password.confirm', 'throttle:20,1'])->name('audit.packs.password');
        Route::post('audit/auditors', [AuditController::class, 'grant'])->middleware('throttle:10,1')->name('audit.auditors.store');
        Route::post('audit/auditors/{access}/revoke', [AuditController::class, 'revoke'])->where('access', '[0-9A-HJKMNP-TV-Z]{26}')->name('audit.auditors.revoke');
        Route::post('adjustments/{adjustment}/decide', [AdjustmentController::class, 'decide'])->where('adjustment', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('throttle:30,1')->name('adjustments.decide');
    });
