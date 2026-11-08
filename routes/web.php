<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

// Component workbench for design review (local, or staging with CORE_DESIGN_GALLERY=true).
if (app()->environment('local') || config('core.design_gallery') === true) {
    Route::inertia('dev/design', 'dev/design')->name('dev.design');
}
