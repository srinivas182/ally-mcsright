<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Actions\AcceptInvitation;
use Modules\Core\Identity\Actions\ManageInvitations;
use Modules\Core\Identity\Auth\LandingPage;
use Modules\Core\Tenancy\TenantContext;

/**
 * Accepting an invitation on the tenant's own address (the token only resolves there).
 */
final class InvitationController
{
    public function show(string $token, ManageInvitations $invitations, TenantContext $tenants): Response
    {
        $invitation = $invitations->findOpen($token);

        if ($invitation === null) {
            return Inertia::render('auth/invitation-invalid', ['tenantName' => $tenants->current()->name]);
        }

        return Inertia::render('auth/accept-invitation', [
            'token' => $token,
            'email' => $invitation->email,
            'firstName' => $invitation->first_name,
            'lastName' => $invitation->last_name,
            'tenantName' => $tenants->current()->name,
            'kind' => $invitation->kind->label(),
        ]);
    }

    public function accept(Request $request, string $token, ManageInvitations $invitations, AcceptInvitation $accept): RedirectResponse
    {
        $invitation = $invitations->findOpen($token);

        if ($invitation === null) {
            return redirect('/invitations/'.$token);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $accept->handle($invitation, $data['first_name'], $data['last_name'], $data['password']);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect(LandingPage::for($user));
    }
}
