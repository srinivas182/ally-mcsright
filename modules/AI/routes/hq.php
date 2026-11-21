<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\Hq\AiConsoleController;
use Modules\AI\Http\Controllers\Hq\AiCostController;
use Modules\AI\Http\Controllers\Hq\AiQualityController;
use Modules\Core\Http\Middleware\EnsureHqConsole;

Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.ai.manage'])
    ->prefix('hq/ai')
    ->name('hq.ai.')
    ->group(function (): void {
        Route::get('/', [AiConsoleController::class, 'index'])->name('index');
        Route::post('providers', [AiConsoleController::class, 'storeProvider'])->name('providers.store');
        Route::post('models', [AiConsoleController::class, 'saveModel'])->name('models.store');
        Route::put('models/{model}', [AiConsoleController::class, 'saveModel'])->name('models.update');
        Route::put('providers/{provider}', [AiConsoleController::class, 'updateProvider'])->name('providers.update');
        // AI1: the provider's API key, saved here (encrypted, never shown again).
        Route::post('providers/{provider}/key', [AiConsoleController::class, 'saveKey'])->middleware(['password.confirm', 'throttle:10,1'])->name('providers.key');
        Route::post('providers/{provider}/key/check', [AiConsoleController::class, 'checkKey'])->middleware('throttle:20,1')->name('providers.key.check');
        Route::delete('providers/{provider}/key', [AiConsoleController::class, 'removeKey'])->middleware('password.confirm')->name('providers.key.remove');
        Route::put('routes/{route}', [AiConsoleController::class, 'updateRoute'])->name('routes.update');
        Route::post('routes/{route}/lock', [AiConsoleController::class, 'toggleLock'])->name('routes.lock');
        // AQ1 (specs/ai/08): AI test sets and scores.
        Route::get('quality', [AiQualityController::class, 'index'])->name('quality');
        Route::post('quality/run', [AiQualityController::class, 'run'])->middleware('throttle:3,10')->name('quality.run');
        // AQ2 (specs/ai/08): AI cost and per-feature cost settings.
        Route::get('cost', [AiCostController::class, 'index'])->name('cost');
        Route::put('cost/routes/{route}', [AiCostController::class, 'updateRoute'])->name('cost.routes');
    });
