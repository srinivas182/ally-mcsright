<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Core\Identity\Impersonation;
use Modules\Core\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

final class ImpersonationController
{
    public function __construct(private readonly Impersonation $impersonation) {}

    /** Owner: view as one of their agents, assistants or staff. */
    public function start(Request $request, User $user): RedirectResponse
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $this->impersonation->startAsOwner($owner, $user, $data['reason'], $request);

        return redirect('/');
    }

    /** HQ console: hand support over to the brokerage's own host. */
    public function hq(Request $request, Tenant $tenant): Response
    {
        $staff = $request->user();
        abort_unless($staff instanceof User, 403);
        $data = $request->validate(['email' => ['required', 'email'], 'reason' => ['required', 'string', 'max:300']]);

        return Inertia::location($this->impersonation->issueHqTicket($staff, $tenant, $data['email'], $data['reason']));
    }

    /** Brokerage host: redeem HQ support's ticket. */
    public function accept(Request $request): RedirectResponse
    {
        try {
            $this->impersonation->acceptHqTicket((string) $request->query('ticket', ''), $request);
        } catch (ValidationException $e) {
            return redirect('/login')->withErrors($e->errors());
        }

        return redirect('/');
    }

    public function stop(Request $request): Response
    {
        return Inertia::location($this->impersonation->stop($request));
    }
}
