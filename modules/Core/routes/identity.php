<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Identity\Http\Controllers\AgentHomeController;
use Modules\Core\Identity\Http\Controllers\InvitationController;
use Modules\Core\Identity\Http\Controllers\SignInSecurityController;
use Modules\Core\Identity\Http\Controllers\UserController;
use Modules\Core\Identity\Http\Middleware\EnsureTenantHost;

Route::middleware(['web', EnsureTenantHost::class, 'guest'])->group(function (): void {
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{token}', [InvitationController::class, 'accept'])
        ->middleware('throttle:10,1')->name('invitations.accept');
});

Route::middleware(['web', EnsureTenantHost::class, 'signed', 'throttle:10,1'])
    ->get('security/secure-account/{user}', [SignInSecurityController::class, 'secureAccount'])
    ->name('security.secure-account');

Route::middleware(['web', EnsureTenantHost::class, 'auth'])->group(function (): void {
    Route::delete('settings/sessions/{id}', [SignInSecurityController::class, 'endSession'])->name('security.sessions.end');
    Route::post('settings/sessions/sign-out-others', [SignInSecurityController::class, 'signOutOthers'])->name('security.sessions.others');
    Route::delete('settings/trusted-devices/{id}', [SignInSecurityController::class, 'revokeTrustedDevice'])->name('security.trusted.revoke');
    Route::post('security/passkey-prompt/dismiss', [SignInSecurityController::class, 'dismissPasskeyPrompt'])->name('security.passkey-prompt.dismiss');
    Route::post('users/{user}/security-reset', [UserController::class, 'resetSecurity'])->name('users.security-reset');
    Route::post('users/{user}/sign-out-everywhere', [UserController::class, 'signOutEverywhere'])->name('users.sign-out-everywhere');

    Route::get('agent', AgentHomeController::class)->name('agent.home');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users/invitations', [UserController::class, 'invite'])->name('users.invite');
    Route::post('users/invitations/{invitation}/resend', [UserController::class, 'resend'])->name('users.invitations.resend');
    Route::post('users/invitations/{invitation}/revoke', [UserController::class, 'revoke'])->name('users.invitations.revoke');
    Route::post('users/{user}/suspend', [UserController::class, 'suspend'])->name('users.suspend');
    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
});
