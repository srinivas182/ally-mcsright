<?php

declare(strict_types=1);

namespace Modules\Marketing\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Identity\Directory;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * "Log in" on rightally.app. LOGIN1: people type their email; RightAlly finds the brokerage (or brokerages) where that
 * email signs in and takes them to its sign-in page with the email filled in, so all that is left is their password
 * or passkey. Brokerages with their own back-office domain are sent there. The brokerage's address still works, for
 * anyone who prefers it. Nothing is remembered and no session is started. HQ staff are never listed.
 */
final class SignInController
{
    public function show(Request $request): Response
    {
        return $this->page($request, '', null);
    }

    public function go(Request $request): Response|RedirectResponse
    {
        $email = mb_strtolower(trim((string) $request->query('email', '')));
        if ($email !== '' || ! $request->filled('address')) {
            return $this->byEmail($request, $email);
        }
        $typed = mb_strtolower(trim((string) $request->query('address', '')));
        // Accept "acme", "acme.rightally.app" or a pasted "https://acme.rightally.app/…".
        $slug = Host::slugFromTyped($typed); // also "acme-office.rightally.app" (DM1)
        $tenant = preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug) === 1
            ? Tenant::query()->where('slug', $slug)->where('status', 'active')->where('type', '!=', 'hq')->first()
            : null;
        if ($tenant === null) {
            return $this->page($request, $typed, 'We could not find a brokerage at that address. Check the spelling, or ask your broker for it.', 422, 'address');
        }

        return redirect()->away(Directory::loginUrl($tenant));
    }

    private function byEmail(Request $request, string $email): Response|RedirectResponse
    {
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190) {
            return $this->page($request, $email, 'Type the email you use for RightAlly.', 422);
        }
        $brokerages = Directory::brokeragesFor($email);
        if (count($brokerages) === 1) {
            return redirect()->away(Directory::loginUrl($brokerages[0], $email));
        }
        if ($brokerages === []) {
            return $this->page($request, $email, 'We could not find a RightAlly account with that email. Check the spelling, try the email your invitation came to, or ask your broker to invite you.', 422);
        }

        return $this->page($request, $email, null, 200, 'email', array_map(fn (Tenant $t): array => ['name' => (string) $t->name, 'url' => Directory::loginUrl($t, $email)], $brokerages));
    }

    /** @param list<array{name: string, url: string}> $choices */
    private function page(Request $request, string $typed, ?string $error, int $status = 200, string $mode = 'email', array $choices = []): Response
    {
        return response()->view('marketing::signin', [
            'typed' => $typed,
            'error' => $error,
            'mode' => $request->query('by') === 'address' ? 'address' : $mode,
            'choices' => $choices,
            'domain' => (string) config('core.platform_domain'),
            'home' => '/',
            'hq' => 'https://'.config('core.hq_host').'/login',
        ], $status)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }
}
