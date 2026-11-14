<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Transactions\Http\ComplianceController;
use Modules\Transactions\Http\DealController;
use Modules\Transactions\Http\DocumentReadingController;
use Modules\Transactions\Http\MoneyDeskController;

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth', 'feature:backoffice.minimal'])->prefix('deals')->name('deals.')->group(function (): void {
    Route::get('/', [DealController::class, 'index'])->name('index');
    Route::get('review', [DealController::class, 'queue'])->name('review');
    Route::get('compliance', [ComplianceController::class, 'settings'])->name('compliance');
    Route::post('compliance/checks/{code}', [ComplianceController::class, 'saveCheck'])->where('code', '[a-z_.]+')->name('compliance.check');
    Route::post('compliance/rules', [ComplianceController::class, 'draftRule'])->middleware('throttle:10,1,compliance-rule')->name('compliance.rules.draft');
    Route::post('compliance/rules/{rule}/confirm', [ComplianceController::class, 'confirmRule'])->name('compliance.rules.confirm');
    Route::post('compliance/rules/{rule}/off', [ComplianceController::class, 'deactivateRule'])->name('compliance.rules.off');
    Route::post('/', [DealController::class, 'store'])->name('store');
    Route::get('{deal}', [DealController::class, 'show'])->name('show');
    Route::post('{deal}/status', [DealController::class, 'status'])->name('status');
    Route::post('{deal}/dates', [DealController::class, 'addDate'])->name('dates.store');
    Route::post('{deal}/facts', [DocumentReadingController::class, 'apply'])->name('facts.apply');
    Route::post('{deal}/compliance/scan', [ComplianceController::class, 'scan'])->middleware('throttle:20,1,compliance-scan')->name('compliance.scan');
    Route::post('{deal}/compliance/findings/{finding}/dismiss', [ComplianceController::class, 'dismiss'])->whereNumber('finding')->name('compliance.dismiss');
    Route::post('{deal}/compliance/submit', [ComplianceController::class, 'submit'])->name('compliance.submit');
    Route::post('{deal}/compliance/rules/{rule}/approve', [ComplianceController::class, 'approveRule'])->name('compliance.rule.approve');
    Route::post('{deal}/dates/{date}/toggle', [DealController::class, 'toggleDate'])->whereNumber('date')->name('dates.toggle');
    Route::delete('{deal}/dates/{date}', [DealController::class, 'removeDate'])->whereNumber('date')->name('dates.destroy');
    Route::post('{deal}/documents', [DealController::class, 'upload'])->middleware('throttle:60,1')->name('upload');
    Route::get('documents/{document}', [DealController::class, 'download'])->name('download');
    Route::get('documents/{document}/evidence', [ComplianceController::class, 'evidence'])->name('document.evidence');
    Route::get('documents/{document}/safe', [DocumentReadingController::class, 'safe'])->name('document.safe');
    Route::post('documents/{document}/ask', [DocumentReadingController::class, 'ask'])->middleware('throttle:20,1,doc-ask')->name('document.ask');
    Route::post('items/{item}/review', [DealController::class, 'review'])->name('item.review');
    Route::post('review/bulk', [MoneyDeskController::class, 'bulkApprove'])->name('review.bulk');
    Route::post('{deal}/deposits', [MoneyDeskController::class, 'expectDeposit'])->name('deposits.expect');
    Route::post('deposits/{deposit}/receive', [MoneyDeskController::class, 'receiveDeposit'])->name('deposits.receive');
    Route::post('deposits/{deposit}/release', [MoneyDeskController::class, 'releaseDeposit'])->name('deposits.release');
    Route::post('{deal}/referrals', [MoneyDeskController::class, 'referral'])->name('referrals.store');
    Route::post('{deal}/cdas', [MoneyDeskController::class, 'generateCda'])->name('cdas.generate');
    Route::post('cdas/{cda}/approve', [MoneyDeskController::class, 'approveCda'])->name('cdas.approve');
    Route::post('cdas/{cda}/send', [MoneyDeskController::class, 'sendCda'])->middleware('throttle:10,1')->name('cdas.send');
    Route::get('{deal}/export', [MoneyDeskController::class, 'export'])->middleware('throttle:10,1')->name('export');
});

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('settings/transactions')->name('transactions.settings')->group(function (): void {
    Route::get('/', [MoneyDeskController::class, 'settings']);
    Route::post('titles', [MoneyDeskController::class, 'addTitle'])->name('.titles');
    Route::post('titles/{title}/verify', [MoneyDeskController::class, 'verifyTitle'])->name('.titles.verify');
    Route::middleware('password.confirm')->group(function (): void {
        Route::post('wire/propose', [MoneyDeskController::class, 'proposeWire'])->name('.wire.propose');
        Route::post('wire/confirm', [MoneyDeskController::class, 'confirmWire'])->name('.wire.confirm');
        Route::post('agents-direct', [MoneyDeskController::class, 'agentsDirect'])->name('.agents-direct');
    });
});

// The title officer's CDA: a secret link on the brokerage's host, no sign-in.
Route::middleware(['web', EnsureTenantWorkspace::class, 'throttle:30,1'])->group(function (): void {
    Route::get('cda/{token}', [MoneyDeskController::class, 'viewCda'])->where('token', '[A-Za-z0-9]{48}')->name('cda.view');
    Route::post('cda/{token}/acknowledge', [MoneyDeskController::class, 'acknowledgeCda'])->where('token', '[A-Za-z0-9]{48}')->name('cda.acknowledge');
});
