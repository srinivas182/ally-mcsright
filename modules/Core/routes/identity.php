<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditController;
use Modules\Core\Identity\EmailChange;
use Modules\Core\Identity\Http\Controllers\AgentHomeController;
use Modules\Core\Identity\Http\Controllers\ImpersonationController;
use Modules\Core\Identity\Http\Controllers\InvitationController;
use Modules\Core\Identity\Http\Controllers\SignInSecurityController;
use Modules\Core\Identity\Http\Controllers\SingleSignOnController;
use Modules\Core\Identity\Http\Controllers\UserController;
use Modules\Core\Identity\Http\Middleware\EnsureTenantHost;
use Modules\Core\Identity\Security\SingleSignOn;

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

// Sign in with Google / Microsoft (specs/core/02, 4A-2).
Route::middleware(['web', EnsureTenantHost::class])->group(function (): void {
    Route::get('auth/{provider}/start', [SingleSignOnController::class, 'start'])->whereIn('provider', SingleSignOn::PROVIDERS)->middleware('throttle:20,1')->name('sso.start');
    Route::get('auth/complete', [SingleSignOnController::class, 'complete'])->middleware('throttle:20,1')->name('sso.complete');
});
Route::middleware(['web', EnsureTenantHost::class, 'auth'])
    ->delete('settings/identities/{provider}', [SingleSignOnController::class, 'unlink'])->whereIn('provider', SingleSignOn::PROVIDERS)->name('sso.unlink');

Route::domain((string) config('core.sso_host'))->middleware(['web', 'throttle:30,1'])->group(function (): void {
    Route::get('oauth/{provider}/redirect', [SingleSignOnController::class, 'toProvider'])->whereIn('provider', SingleSignOn::PROVIDERS)->name('sso.provider');
    Route::get('oauth/{provider}/callback', [SingleSignOnController::class, 'callback'])->whereIn('provider', SingleSignOn::PROVIDERS)->name('sso.callback');
});

// Confirm a new sign-in email (signed link sent to the new address).
Route::middleware(['web', EnsureTenantHost::class, 'auth', 'signed'])->get('settings/email/confirm/{token}', function (Request $request, string $token) {
    $user = $request->user();
    abort_unless($user instanceof User, 403);
    try {
        app(EmailChange::class)->confirm($user, $token);
        Inertia\Inertia::flash('toast', ['type' => 'success', 'message' => 'Your sign-in email is now '.$user->email.'.']);
    } catch (ValidationException $e) {
        return redirect('/settings/profile')->withErrors($e->errors());
    }

    return redirect('/settings/profile');
})->name('email-change.confirm');

// "Log in as" for support (specs/core/02, 4A-3).
Route::middleware(['web', EnsureTenantHost::class, 'auth'])->group(function (): void {
    Route::post('users/{user}/impersonate', [ImpersonationController::class, 'start'])->middleware('throttle:10,1')->name('impersonate.start');
    Route::post('impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');
});
Route::middleware(['web', EnsureTenantHost::class, 'throttle:10,1'])->get('impersonate/accept', [ImpersonationController::class, 'accept'])->name('impersonate.accept');

// Audit log for owners (specs/core/04).
Route::middleware(['web', EnsureTenantHost::class, 'auth'])->get('audit', [AuditController::class, 'index'])->name('audit.index');
Route::middleware(['web', EnsureTenantHost::class, 'auth'])->group(function (): void {
    Route::post('audit/retention', [AuditController::class, 'retention'])->name('audit.retention');
    Route::post('audit/holds', [AuditController::class, 'placeHold'])->name('audit.holds.store');
    Route::post('audit/holds/{hold}/release', [AuditController::class, 'releaseHold'])->name('audit.holds.release');
    Route::get('contacts/{contact}/evidence-pack', [AuditController::class, 'evidencePack'])->middleware('throttle:10,1')->name('audit.evidence-pack');
});
// Audit streaming: owners only, after re-confirming their password (sudo mode).
Route::middleware(['web', EnsureTenantHost::class, 'auth', 'password.confirm'])->group(function (): void {
    Route::post('audit/streams', [AuditController::class, 'addStream'])->name('audit.streams.store');
    Route::delete('audit/streams/{stream}', [AuditController::class, 'removeStream'])->name('audit.streams.destroy');
    Route::post('audit/streams/{stream}/resume', [AuditController::class, 'resumeStream'])->name('audit.streams.resume');
});
Route::middleware(['web', EnsureTenantHost::class, 'auth', 'throttle:10,1'])
    ->post('audit/streams/{stream}/test', [AuditController::class, 'testStream'])->name('audit.streams.test');
