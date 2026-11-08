<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Identity\Http\Controllers\AgentHomeController;
use Modules\Core\Identity\Http\Controllers\InvitationController;
use Modules\Core\Identity\Http\Controllers\UserController;
use Modules\Core\Identity\Http\Middleware\EnsureTenantHost;

Route::middleware(['web', EnsureTenantHost::class, 'guest'])->group(function (): void {
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{token}', [InvitationController::class, 'accept'])
        ->middleware('throttle:10,1')->name('invitations.accept');
});

Route::middleware(['web', EnsureTenantHost::class, 'auth'])->group(function (): void {
    Route::get('agent', AgentHomeController::class)->name('agent.home');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users/invitations', [UserController::class, 'invite'])->name('users.invite');
    Route::post('users/invitations/{invitation}/resend', [UserController::class, 'resend'])->name('users.invitations.resend');
    Route::post('users/invitations/{invitation}/revoke', [UserController::class, 'revoke'])->name('users.invitations.revoke');
    Route::post('users/{user}/suspend', [UserController::class, 'suspend'])->name('users.suspend');
    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
});
