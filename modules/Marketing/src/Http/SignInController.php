<?php

declare(strict_types=1);

namespace Modules\Marketing\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * BR1: "Log in" on rightally.app. Each brokerage signs in at its own address ({brokerage}.rightally.app), so this
 * page asks for that address and sends the person there. Nothing is remembered and no session is started.
 */
final class SignInController
{
    public function show(Request $request): Response
    {
        return $this->page($request, '', null);
    }

    public function go(Request $request): Response|RedirectResponse
    {
        $typed = mb_strtolower(trim((string) $request->query('address', '')));
        // Accept "acme", "acme.rightally.app" or a pasted "https://acme.rightally.app/…".
        $slug = (string) preg_replace(['#^https?://#', '#/.*$#', '#\.'.preg_quote((string) config('core.platform_domain'), '#').'$#'], '', $typed);

        $tenant = preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug) === 1
            ? Tenant::query()->where('slug', $slug)->where('status', 'active')->where('type', '!=', 'hq')->first()
            : null;
        if ($tenant === null) {
            return $this->page($request, $typed, $typed === ''
                ? 'Type your brokerage\'s address first.'
                : 'We could not find a brokerage at that address. Check the spelling, or ask your broker for it.', 422);
        }

        return redirect()->away('https://'.Host::platformHostFor($tenant->slug).'/login');
    }

    private function page(Request $request, string $typed, ?string $error, int $status = 200): Response
    {
        return response()->view('marketing::signin', [
            'typed' => $typed,
            'error' => $error,
            'domain' => (string) config('core.platform_domain'),
            'home' => '/',
            'hq' => 'https://'.config('core.hq_host').'/login',
        ], $status)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }
}
