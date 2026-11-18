<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureHqConsole;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Team\Http\ChatController;
use Modules\Team\Http\HelpController;
use Modules\Team\Http\HqSupportController;

// Team chat (CH1, specs/team/01).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('chat')->name('team.chat.')->group(function (): void {
    Route::get('/', [ChatController::class, 'index'])->name('index');
    Route::post('direct', [ChatController::class, 'direct'])->middleware('throttle:30,1')->name('direct');
    Route::post('groups', [ChatController::class, 'group'])->middleware('throttle:20,1')->name('group');
    Route::get('{channel}/messages', [ChatController::class, 'poll'])->middleware('throttle:120,1')->name('poll');
    Route::post('{channel}/messages', [ChatController::class, 'post'])->middleware('throttle:60,1')->name('post');
    Route::post('{channel}/members', [ChatController::class, 'members'])->name('members');
    Route::patch('messages/{message}', [ChatController::class, 'edit'])->whereNumber('message')->name('edit');
    Route::post('messages/{message}/hide', [ChatController::class, 'hide'])->whereNumber('message')->name('hide');
});

// The help centre and support requests.
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('help')->name('team.help.')->group(function (): void {
    Route::get('/', [HelpController::class, 'index'])->name('index');
    Route::post('ask', [HelpController::class, 'ask'])->middleware('throttle:10,1')->name('ask');
    Route::get('page', [HelpController::class, 'page'])->name('page'); // SX5: "Help me"
    Route::post('callback', [HelpController::class, 'callback'])->middleware('throttle:3,60')->name('callback');
    Route::get('{slug}/print', [HelpController::class, 'print'])->where('slug', '[a-z0-9-]{2,80}')->name('print');
    Route::post('requests/{ticket}/close', [HelpController::class, 'close'])->where('ticket', '[0-9A-HJKMNP-TV-Z]{26}')->name('close');
    Route::get('{slug}', [HelpController::class, 'article'])->where('slug', '[a-z0-9-]{2,80}')->name('article');
    Route::post('{slug}/feedback', [HelpController::class, 'feedback'])->where('slug', '[a-z0-9-]{2,80}')->middleware('throttle:30,1')->name('feedback');
});

Route::middleware(['web', EnsureHqConsole::class, 'auth', 'can:hq.tenants.manage'])->prefix('hq/support')->name('hq.support.')->group(function (): void {
    Route::get('/', [HqSupportController::class, 'index'])->name('index');
    Route::get('videos', [HqSupportController::class, 'videos'])->name('videos'); // SX5: 60-second video per page
    Route::post('videos', [HqSupportController::class, 'setVideo'])->name('videos.set');
    Route::post('{ticket}', [HqSupportController::class, 'reply'])->where('ticket', '[0-9A-HJKMNP-TV-Z]{26}')->name('reply');
});
