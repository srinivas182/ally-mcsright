<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Forms\Http\FormsController;

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('forms')->name('forms.')->group(function (): void {
    Route::get('templates', [FormsController::class, 'templates'])->name('templates');
    Route::post('templates', [FormsController::class, 'upload'])->middleware('throttle:20,1')->name('templates.upload');
    Route::post('templates/{template}/fields', [FormsController::class, 'fields'])->name('templates.fields');
    Route::post('clauses', [FormsController::class, 'clause'])->name('clauses');
    Route::get('deals/{deal}', [FormsController::class, 'forDeal'])->name('deal');
    Route::post('deals/{deal}/packets', [FormsController::class, 'createPacket'])->name('packets.create');
    Route::get('packets/{packet}', [FormsController::class, 'packet'])->name('packets.show');
    Route::post('packets/{packet}/forms/{form}/values', [FormsController::class, 'values'])->whereNumber('form')->name('packets.values');
    Route::post('packets/{packet}/signers', [FormsController::class, 'signers'])->name('packets.signers');
    Route::get('packets/{packet}/forms/{form}/preview', [FormsController::class, 'preview'])->whereNumber('form')->name('packets.preview');
});
