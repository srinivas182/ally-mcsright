<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Sites\AgentSites;
use Modules\Sites\Models\Site;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * An agent's referral link, /r/{agent} (AW1, specs/websites/18). It remembers the agent for 30 days in a small
 * first-party cookie, so a lead the visitor sends later anywhere on the brokerage's website goes to that agent, then
 * sends them on to ?to=/a-page-on-this-site (only this site; never another address) or the agent's own page.
 */
final class ReferralController
{
    public const COOKIE = 'ra_agent';

    public const DAYS = 30;

    public function go(Request $request, string $slug): RedirectResponse
    {
        $site = Site::query()->where('kind', 'agent')->where('slug', $slug)->where('status', 'live')->first();
        $root = $request->getSchemeAndHttpHost();
        if ($site === null || ! AgentSites::rules()['enabled'] || ! AgentSites::rules()['referrals']) {
            return redirect($root.'/', 302)->header('Cache-Control', 'no-store');
        }
        $to = (string) $request->query('to', '');
        $safe = preg_match('#^/(?!/)[A-Za-z0-9/_\-.?=&%]*$#', $to) === 1 && ! str_contains($to, '\\') ? $to : '/agents/'.$site->slug;

        return redirect($root.$safe, 302)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex')
            ->withCookie(new Cookie(self::COOKIE, $site->ulid, now()->addDays(self::DAYS), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
    }

    /** The agent a visitor arrived through, if their link is still fresh and the agent's website is live. */
    public static function agentFor(Request $request): ?Site
    {
        $ulid = $request->cookies->get(self::COOKIE);
        if (! is_string($ulid) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $ulid) !== 1 || ! AgentSites::rules()['referrals']) {
            return null;
        }

        return Site::query()->where('ulid', $ulid)->where('kind', 'agent')->where('status', 'live')->first();
    }

    /**
     * AG2: remembers the agent whose page this visitor opened (called from the page itself, so the page stays cached
     * for everyone) and answers with the agent the visitor is with, for the "Your agent" bar on every other page.
     * Nothing is remembered without an agent's page or link; the answer is never cached.
     */
    public function me(Request $request): Response
    {
        $remember = (string) $request->query('remember', '');
        $site = null;
        $cookie = null;
        if ($remember !== '' && preg_match('/^[a-z0-9-]{1,80}$/', $remember) === 1 && AgentSites::rules()['enabled'] && AgentSites::rules()['referrals']) {
            $site = Site::query()->where('kind', 'agent')->where('slug', $remember)->where('status', 'live')->first();
            if ($site !== null) {
                $cookie = new Cookie(self::COOKIE, $site->ulid, now()->addDays(self::DAYS), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
            }
        }
        $site ??= self::agentFor($request);
        $owner = $site !== null ? User::query()->find($site->user_id) : null;
        if ($site === null || ! $owner instanceof User || ! $owner->isActive()) {
            return response()->noContent()->header('Cache-Control', 'no-store');
        }
        $p = AgentSites::profile($site);
        $response = response()->json(['name' => $p['name'], 'first_name' => $p['first_name'], 'phone' => $p['phone'], 'email' => $p['email'], 'photo' => $p['photo'],
            'url' => '/agents/'.$site->slug])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');

        return $cookie !== null ? $response->withCookie($cookie) : $response;
    }
}
