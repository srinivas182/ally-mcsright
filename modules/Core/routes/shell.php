<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Identity\Http\Middleware\EnsureTenantHost;
use Modules\Core\Search\SearchController;
use Modules\Core\Shell\AppHomeController;
use Modules\Core\Shell\AppManifestController;

/*
| PW1 (specs/core/17): the installable app and universal search.
*/
Route::middleware('web')->get('manifest.webmanifest', AppManifestController::class)->name('app.manifest');
Route::middleware('web')->get('app', AppHomeController::class)->name('app.home');
Route::middleware(['web', EnsureTenantHost::class, 'auth', 'throttle:60,1'])->get('search', SearchController::class)->name('search');
