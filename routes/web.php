<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

// Interim design gallery for designers (local development only; Storybook follows).
if (app()->environment('local')) {
    Route::inertia('dev/design', 'dev/design')->name('dev.design');
}
