<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Forms\Http\FormsController;
use Modules\Forms\Http\LibraryController;
use Modules\Forms\Http\SigningController;

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('forms')->name('forms.')->group(function (): void {
    Route::get('templates', [FormsController::class, 'templates'])->name('templates');
    Route::post('templates', [FormsController::class, 'upload'])->middleware('throttle:20,1')->name('templates.upload');
    Route::post('templates/{template}/fields', [FormsController::class, 'fields'])->name('templates.fields');
    Route::get('templates/{template}/pages/{page}', [FormsController::class, 'page'])->whereNumber('page')->name('templates.page');
    Route::post('clauses', [FormsController::class, 'clause'])->name('clauses');
    Route::post('identity-settings', [FormsController::class, 'identitySettings'])->name('identity-settings');
    Route::post('clause-setting', [FormsController::class, 'clauseSetting'])->name('clause-setting');
    Route::get('libraries', [LibraryController::class, 'index'])->name('libraries');
    Route::post('libraries', [LibraryController::class, 'connect'])->middleware('throttle:10,1,forms-library')->name('libraries.connect');
    Route::post('libraries/{library}/sync', [LibraryController::class, 'sync'])->middleware('throttle:10,1,forms-library')->name('libraries.sync');
    Route::post('library-forms/{form}/import', [LibraryController::class, 'import'])->whereNumber('form')->name('library-forms.import');
    Route::post('import', [LibraryController::class, 'importFiles'])->middleware('throttle:10,1,forms-import')->name('import');
    Route::post('templates/{template}/risk', [FormsController::class, 'templateRisk'])->name('templates.risk');
    Route::get('deals/{deal}', [FormsController::class, 'forDeal'])->name('deal');
    Route::post('deals/{deal}/packets', [FormsController::class, 'createPacket'])->name('packets.create');
    Route::get('packets/{packet}', [FormsController::class, 'packet'])->name('packets.show');
    Route::post('packets/{packet}/forms/{form}/values', [FormsController::class, 'values'])->whereNumber('form')->name('packets.values');
    Route::post('packets/{packet}/signers', [FormsController::class, 'signers'])->name('packets.signers');
    Route::get('packets/{packet}/forms/{form}/preview', [FormsController::class, 'preview'])->whereNumber('form')->name('packets.preview');
    Route::post('packets/{packet}/send', [FormsController::class, 'send'])->middleware('throttle:10,1')->name('packets.send');
    Route::post('packets/{packet}/void', [FormsController::class, 'void'])->name('packets.void');
    Route::post('packets/{packet}/amend', [FormsController::class, 'amend'])->middleware('throttle:20,1')->name('packets.amend');
    Route::post('packets/{packet}/counter', [FormsController::class, 'counter'])->middleware('throttle:20,1')->name('packets.counter');
    Route::post('packets/{packet}/newest-editions', [FormsController::class, 'newestEditions'])->name('packets.newest-editions');
    Route::post('packets/{packet}/summary', [FormsController::class, 'summary'])->middleware('throttle:10,1,forms-summary')->name('packets.summary');
    Route::post('packets/{packet}/summary/approve', [FormsController::class, 'approveSummary'])->name('packets.summary.approve');
    Route::post('packets/{packet}/dates', [FormsController::class, 'dates'])->name('packets.dates');
    Route::post('packets/{packet}/clauses/approve', [FormsController::class, 'approveClauses'])->name('packets.clauses.approve');
    Route::post('packets/{packet}/owner-override', [FormsController::class, 'ownerOverride'])->name('packets.owner-override');
    Route::post('packets/{packet}/addendum', [FormsController::class, 'addendum'])->name('packets.addendum');
    Route::post('packets/{packet}/in-person/{signer}', [FormsController::class, 'inPerson'])->name('packets.in-person');
    Route::get('packets/{packet}/signed.pdf', [FormsController::class, 'signed'])->name('packets.signed');
});

// Signers (no sign-in; secret link) and public verification of sealed documents, on the brokerage's host.
Route::middleware(['web', EnsureTenantWorkspace::class, 'throttle:60,1'])->group(function (): void {
    Route::prefix('sign/{token}')->where(['token' => '[A-Za-z0-9]{48}'])->name('sign.')->group(function (): void {
        Route::get('/', [SigningController::class, 'show'])->name('show');
        Route::get('document.pdf', [SigningController::class, 'document'])->name('document');
        Route::post('consent', [SigningController::class, 'consent'])->name('consent');
        Route::post('code', [SigningController::class, 'code'])->middleware('throttle:3,10,sign-code')->name('code');
        Route::post('verify', [SigningController::class, 'verify'])->name('verify');
        Route::post('sign', [SigningController::class, 'sign'])->name('sign');
        Route::post('decline', [SigningController::class, 'decline'])->name('decline');
        Route::post('acknowledge', [SigningController::class, 'acknowledge'])->name('acknowledge');
        Route::post('identity', [SigningController::class, 'identity'])->middleware('throttle:5,10,sign-identity')->name('identity');
        Route::get('identity/return', [SigningController::class, 'identityReturn'])->name('identity.return');
    });
    Route::get('verify-document', [SigningController::class, 'verifyPage'])->name('verify-document');
    Route::post('verify-document', [SigningController::class, 'verifyUpload'])->middleware('throttle:20,1')->name('verify-document.check');
});
