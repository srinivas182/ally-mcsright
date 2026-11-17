<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Ledger\Http\Controllers\AccountController;
use Modules\Ledger\Http\Controllers\AccountingController;
use Modules\Ledger\Http\Controllers\BooksController;
use Modules\Ledger\Http\Controllers\JournalController;
use Modules\Ledger\Http\Controllers\PeriodController;

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
        Route::post('bank/import', [AccountingController::class, 'importBank'])->middleware('throttle:20,1')->name('bank.import');
        Route::post('bank/lines/{line}/add', [AccountingController::class, 'addToBooks'])->whereNumber('line')->name('bank.add');
        Route::post('bank/lines/{line}/match', [AccountingController::class, 'matchBank'])->whereNumber('line')->name('bank.match');
        Route::post('bank/approve', [AccountingController::class, 'approveBank'])->name('bank.approve');

        Route::get('periods', [PeriodController::class, 'index'])->name('periods.index');
        Route::post('periods/{period}/close', [PeriodController::class, 'close'])->name('periods.close');
    });
