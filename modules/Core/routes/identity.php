<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Modules\Core\Access\AccessController;
use Modules\Core\Audit\AuditController;
use Modules\Core\Brief\BriefController;
use Modules\Core\CustomFields\CustomFieldsController;
use Modules\Core\Identity\EmailChange;
use Modules\Core\Identity\Http\Controllers\ImpersonationController;
use Modules\Core\Identity\Http\Controllers\InvitationController;
use Modules\Core\Identity\Http\Controllers\SignInSecurityController;
use Modules\Core\Identity\Http\Controllers\SingleSignOnController;
use Modules\Core\Identity\Http\Controllers\UserController;
use Modules\Core\Identity\Http\Middleware\EnsureTenantHost;
use Modules\Core\Identity\Security\SingleSignOn;
use Modules\Core\Notices\NoticesController;
use Modules\Core\Safeguards\Http\DataExportController;
use Modules\Core\Simple\SimpleController;
use Modules\Core\Undo\UndoController;
use Modules\Core\Workflows\WorkflowsController;

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

    Route::get('agent', [BriefController::class, 'agent'])->name('agent.home');
    // Simple mode and undo (SM1).
    Route::get('simple', [SimpleController::class, 'home'])->name('simple.home');
    Route::post('settings/view', [SimpleController::class, 'set'])->name('simple.set');
    Route::post('undo/{undo}', UndoController::class)->where('undo', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('throttle:30,1')->name('undo');
    Route::post('brief/email', [BriefController::class, 'email'])->name('brief.email');

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

// Roles, offices and teams (specs/core/03).
Route::middleware(['web', EnsureTenantHost::class, 'auth'])->group(function (): void {
    Route::get('settings/roles', [AccessController::class, 'roles'])->name('roles.index');
    Route::post('settings/roles/preview', [AccessController::class, 'preview'])->name('roles.preview');
    Route::post('settings/roles/preview-as', [AccessController::class, 'previewAs'])->name('roles.preview-as');
    Route::post('settings/roles/templates/{key}', [AccessController::class, 'addTemplate'])->where('key', '[a-z_]+')->name('roles.templates');
    // The notification centre and custom fields (specs/core/10, N1).
    Route::get('notifications', [NoticesController::class, 'index'])->name('notices.index');
    Route::get('notifications/latest', [NoticesController::class, 'latest'])->name('notices.latest');
    Route::post('notifications/read/{notice?}', [NoticesController::class, 'read'])->name('notices.read');
    Route::post('notifications/{notice}/snooze', [NoticesController::class, 'snooze'])->name('notices.snooze');
    Route::post('notifications/preferences', [NoticesController::class, 'preference'])->name('notices.preference');
    Route::post('notifications/settings', [NoticesController::class, 'settings'])->name('notices.settings');
    Route::get('settings/custom-fields', [CustomFieldsController::class, 'index'])->name('custom-fields.index');
    Route::post('settings/custom-fields/{entity}', [CustomFieldsController::class, 'save'])->whereIn('entity', ['contact', 'deal', 'agent'])->name('custom-fields.store');
    Route::put('settings/custom-fields/{entity}/{field}', [CustomFieldsController::class, 'save'])->whereIn('entity', ['contact', 'deal', 'agent'])->name('custom-fields.update');
    Route::post('settings/custom-fields/{entity}/{field}/archive', [CustomFieldsController::class, 'archive'])->whereIn('entity', ['contact', 'deal', 'agent'])->name('custom-fields.archive');
    // Automations (specs/core/06, W1).
    Route::get('automations', [WorkflowsController::class, 'index'])->name('automations.index');
    Route::get('automations/new', [WorkflowsController::class, 'edit'])->name('automations.create');
    Route::get('automations/approvals', [WorkflowsController::class, 'approvals'])->name('automations.approvals');
    Route::post('automations/approvals/{approval}', [WorkflowsController::class, 'decide'])->name('automations.decide');
    Route::post('automations/dry-run', [WorkflowsController::class, 'dryRun'])->middleware('throttle:30,1')->name('automations.dry-run');
    Route::post('automations/draft', [WorkflowsController::class, 'draft'])->middleware('throttle:20,1')->name('automations.draft');
    Route::post('automations/templates/{key}', [WorkflowsController::class, 'template'])->where('key', '[a-z_]+')->name('automations.template');
    Route::post('automations/runs/{run}/retry', [WorkflowsController::class, 'retry'])->name('automations.retry');
    Route::post('automations/runs/{run}/cancel', [WorkflowsController::class, 'cancel'])->name('automations.cancel');
    Route::post('automations', [WorkflowsController::class, 'save'])->name('automations.store');
    Route::get('automations/{workflow}', [WorkflowsController::class, 'edit'])->name('automations.edit');
    Route::put('automations/{workflow}', [WorkflowsController::class, 'save'])->name('automations.update');
    Route::post('automations/{workflow}/switch', [WorkflowsController::class, 'switch'])->name('automations.switch');
    Route::delete('automations/{workflow}', [WorkflowsController::class, 'destroy'])->name('automations.destroy');
    Route::get('automations/{workflow}/runs', [WorkflowsController::class, 'runs'])->name('automations.runs');
    Route::get('settings/access-review', [AccessController::class, 'review'])->name('access-review.index');
    Route::post('settings/access-review/start', [AccessController::class, 'reviewStart'])->name('access-review.start');
    Route::post('settings/access-review/items/{item}', [AccessController::class, 'reviewDecide'])->whereNumber('item')->name('access-review.decide');
    Route::post('settings/access-review/complete', [AccessController::class, 'reviewComplete'])->middleware('password.confirm')->name('access-review.complete');
    Route::get('settings/access-review/export', [AccessController::class, 'reviewExport'])->middleware('password.confirm')->name('access-review.export');
    Route::post('settings/roles', [AccessController::class, 'save'])->name('roles.store');
    Route::put('settings/roles/{role}', [AccessController::class, 'save'])->name('roles.update');
    Route::post('users/{user}/roles', [AccessController::class, 'assign'])->name('roles.assign');
    Route::delete('users/{user}/roles/{role}', [AccessController::class, 'revoke'])->name('roles.revoke');
    Route::get('settings/organisation', [AccessController::class, 'organisation'])->name('organisation.index');
    Route::post('settings/offices', [AccessController::class, 'saveOffice'])->name('offices.store');
    Route::post('settings/teams', [AccessController::class, 'saveTeam'])->name('teams.store');
    Route::post('settings/membership', [AccessController::class, 'membership'])->name('organisation.membership');
});

// Settings → Your data (DS1, specs/core/12): the owner's full export of the brokerage.
Route::middleware(['web', EnsureTenantHost::class, 'auth', 'password.confirm'])->prefix('settings/data-export')->name('settings.data-export.')->group(function (): void {
    Route::get('/', [DataExportController::class, 'show'])->name('show');
    Route::post('/', [DataExportController::class, 'request'])->middleware('throttle:3,1')->name('request');
    Route::get('{export}', [DataExportController::class, 'download'])->where('export', '[0-9A-HJKMNP-TV-Z]{26}')->name('download');
    Route::post('{export}/password', [DataExportController::class, 'password'])->where('export', '[0-9A-HJKMNP-TV-Z]{26}')->name('password');
});
