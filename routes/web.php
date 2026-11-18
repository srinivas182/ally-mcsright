<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Brief\BriefController;
use Modules\Core\Identity\Http\Middleware\EnsureTenantHost;

Route::inertia('/', 'welcome')->name('home');

Route::middleware([EnsureTenantHost::class, 'auth', 'verified'])->group(function () {
    Route::get('dashboard', [BriefController::class, 'dashboard'])->name('dashboard');
});

require __DIR__.'/settings.php';

// Component workbench for design review (local, or staging with CORE_DESIGN_GALLERY=true).
if (app()->environment('local') || config('core.design_gallery') === true) {
    Route::inertia('dev/design', 'dev/design')->name('dev.design');
}
