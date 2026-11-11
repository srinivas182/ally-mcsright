<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\Hq\AiConsoleController;
use Modules\Core\Http\Middleware\EnsureHqConsole;

Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.ai.manage'])
    ->prefix('hq/ai')
    ->name('hq.ai.')
    ->group(function (): void {
        Route::get('/', [AiConsoleController::class, 'index'])->name('index');
        Route::put('providers/{provider}', [AiConsoleController::class, 'updateProvider'])->name('providers.update');
        Route::put('routes/{route}', [AiConsoleController::class, 'updateRoute'])->name('routes.update');
        Route::post('routes/{route}/lock', [AiConsoleController::class, 'toggleLock'])->name('routes.lock');
    });
