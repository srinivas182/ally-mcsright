<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Hq\MessagingController;
use Modules\Core\Http\Controllers\Hq\TenantController;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Identity\Http\Controllers\ImpersonationController;

/*
| HQ console (served only on the HQ host; see EnsureHqConsole).
*/
Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.tenants.manage'])
    ->prefix('hq')
    ->name('hq.')
    ->group(function (): void {
        Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::get('tenants/create', [TenantController::class, 'create'])->name('tenants.create');
        Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
        Route::post('tenants/{tenant}/impersonate', [ImpersonationController::class, 'hq'])->middleware('throttle:10,1')->name('tenants.impersonate');
        Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
        Route::post('tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
        Route::post('tenants/{tenant}/reactivate', [TenantController::class, 'reactivate'])->name('tenants.reactivate');
        Route::post('tenants/{tenant}/close', [TenantController::class, 'close'])->name('tenants.close');
        Route::post('tenants/{tenant}/slug', [TenantController::class, 'changeSlug'])->name('tenants.slug');
        Route::get('messaging', [MessagingController::class, 'index'])->name('messaging');
        Route::post('messaging', [MessagingController::class, 'save'])->middleware('password.confirm')->name('messaging.save');
        Route::post('messaging/tenants/{tenant}', [MessagingController::class, 'tenant'])->name('messaging.tenant');
    });
