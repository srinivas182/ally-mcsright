<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Pricing\Http\PricingController;
use Modules\Pricing\Http\ShareController;

// Pricing (specs/listings/01).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('pricing')->name('pricing.')->group(function (): void {
    Route::get('/', [PricingController::class, 'index'])->name('index');
    Route::post('cma', [PricingController::class, 'store'])->middleware('throttle:30,1')->name('cma.store');
    Route::get('cma/{cma}', [PricingController::class, 'show'])->name('cma');
    Route::patch('cma/{cma}', [PricingController::class, 'update'])->name('cma.update');
    Route::get('cma/{cma}/search', [PricingController::class, 'search'])->middleware('throttle:60,1')->name('cma.search');
    Route::post('cma/{cma}/comps', [PricingController::class, 'addComp'])->name('cma.comps');
    Route::post('cma/{cma}/summary', [PricingController::class, 'summary'])->middleware(['throttle:10,1', 'feature:crm.ai'])->name('cma.summary');
    Route::post('cma/{cma}/share', [PricingController::class, 'share'])->name('cma.share');
    Route::get('cma/{cma}/pdf', [PricingController::class, 'pdf'])->middleware('throttle:20,1')->name('cma.pdf');
    Route::get('buyer', [PricingController::class, 'buyer'])->name('buyer');
    Route::post('buyer/calculate', [PricingController::class, 'calculate'])->middleware('throttle:120,1')->name('buyer.calculate');
    Route::post('buyer', [PricingController::class, 'saveBuyer'])->name('buyer.save');
    Route::post('buyer/{sheet}/share', [PricingController::class, 'shareBuyer'])->name('buyer.share');
    Route::get('buyer/{sheet}/pdf', [PricingController::class, 'buyerPdf'])->middleware('throttle:20,1')->name('buyer.pdf');
    Route::post('defaults', [PricingController::class, 'saveDefaults'])->name('defaults');
});

// The private links sent to sellers and buyers (no sign-in; signed by a random token).
Route::middleware(['tenant', EnsureTenantWorkspace::class, 'throttle:60,1,pricing-share'])->group(function (): void {
    Route::get('cma/{token}', [ShareController::class, 'cma'])->where('token', '[A-Za-z0-9]{40}')->name('pricing.share.cma');
    Route::get('estimate/{token}', [ShareController::class, 'estimate'])->where('token', '[A-Za-z0-9]{40}')->name('pricing.share.estimate');
});
