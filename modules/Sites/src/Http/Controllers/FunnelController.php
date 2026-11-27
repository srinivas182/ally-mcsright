<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Security\BotProtection;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Search\ListingSearch;
use Modules\Listings\Search\MarketStats;
use Modules\Sites\AgentSites;
use Modules\Sites\Content\DemoPhotos;
use Modules\Sites\Content\StarterSite;
use Modules\Sites\Funnels\Funnels;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SiteFunnel;
use Modules\Sites\Rendering\Chrome;
use Modules\Sites\Rendering\Seo;
use Throwable;

/**
 * A funnel's public page at /go/{slug} (specs/grow/03): value first (homes, or a price range), then a short ask with
 * the texting consent box. Ad landing pages, so not indexed by search engines.
 */
final class FunnelController
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function show(Request $request, string $slug): Response
    {
        $site = Site::query()->where('kind', 'brokerage')->first();
        if ($site === null || $site->status !== 'live') {
            return response()->view('sites::missing', ['site' => $site], 404);
        }
        $funnel = SiteFunnel::query()->where('site_id', $site->id)->where('slug', $slug)->first();
        if ($funnel === null || $funnel->status !== 'live') {
            return response()->view('sites::funnel-gone', ['site' => $site, 'current' => '/go/'.$slug, 'chrome' => Chrome::for($site, $this->tenants->id(), []),
                'seo' => Seo::build($site, $request->getSchemeAndHttpHost(), '/go/'.$slug, 'Homes for sale | '.$site->displayName(), 'Search homes for sale.', null, false, [])], 410);
        }
        if (! self::bot($request) && $request->query('r') === null) {
            $funnel->increment('visits');
        }
        $result = null;
        if (is_string($request->query('r'))) {
            try {
                $r = json_decode(Crypt::decryptString($request->query('r')), true);
                $result = is_array($r) && (int) ($r['e'] ?? 0) > time() && ($r['f'] ?? null) === $funnel->ulid ? $r : null;
            } catch (Throwable) {
                $result = null;
            }
        }
        $homes = [];
        $stats = null;
        if (! Funnels::isValue($funnel->kind)) {
            $found = app(ListingSearch::class)->search($this->tenants->id(), Funnels::filters($funnel, is_int($result['budget'] ?? null) ? $result['budget'] : null));
            $homes = $found['results'];
            $stats = ['total' => $found['total']];
        }
        if ($funnel->kind === 'moving') {
            try {
                $stats = ($stats ?? []) + ['market' => app(MarketStats::class)->forPlace($this->tenants->id(), self::place((string) $funnel->area))];
            } catch (Throwable) {
                // market facts are a bonus
            }
        }

        // AG3: the page's photo, and the agent who shares it (their own funnel), shown by name with a call button.
        $kind = Funnels::KINDS[$funnel->kind];
        $photo = DemoPhotos::url($kind['photo'] !== null ? DemoPhotos::pick($kind['photo'], (int) $funnel->id) : StarterSite::cityPhoto((array) $site->info), 'hero');
        $agent = null;
        if ($funnel->owner_user_id !== null && ($owner = User::query()->find($funnel->owner_user_id)) instanceof User && $owner->isActive()) {
            $agentSite = app(AgentSites::class)->forUser($owner);
            $agent = $agentSite !== null && $agentSite->status === 'live' ? AgentSites::profile($agentSite) + ['slug' => (string) $agentSite->slug]
                : ['name' => $owner->name, 'first_name' => (string) $owner->first_name, 'phone' => (string) ($owner->phone ?? ''), 'email' => (string) $owner->email, 'photo' => null, 'slug' => null];
        }

        return response()->view('sites::funnel', [
            'site' => $site, 'current' => '/go/'.$slug, 'funnel' => $funnel, 'homes' => $homes, 'stats' => $stats, 'result' => $result,
            'kind' => $kind, 'photo' => $photo, 'agent' => $agent, 'value' => Funnels::isValue($funnel->kind),
            'rememberAgent' => $agent['slug'] ?? null, // AG3: the visitor stays with the agent who shared the page (AG2)
            'seo' => Seo::build($site, $request->getSchemeAndHttpHost(), '/go/'.$slug, $funnel->headline.' | '.$site->displayName(), (string) $funnel->intro, null, false, []),
            'chrome' => Chrome::for($site, $this->tenants->id(), array_column($homes, 'disclaimer')),
            ...SiteController::form($request),
        ])->header('X-Robots-Tag', 'noindex, follow')->header('Cache-Control', 'no-store');
    }

    public function submit(Request $request, string $slug, Funnels $funnels): RedirectResponse
    {
        $site = Site::query()->where('kind', 'brokerage')->where('status', 'live')->first();
        $back = $request->getSchemeAndHttpHost().'/go/'.$slug;
        $funnel = $site !== null ? SiteFunnel::query()->where('site_id', $site->id)->where('slug', $slug)->where('status', 'live')->first() : null;
        if ($site === null || $funnel === null || trim((string) $request->input('website', '')) !== '' || ! app(BotProtection::class)->passes($request, $this->tenants->id())) {
            return redirect($back.'?sent=1', 303); // honeypot, or gone
        }
        try {
            $shown = (int) Crypt::decryptString((string) $request->input('t', ''));
        } catch (Throwable) {
            $shown = PHP_INT_MAX;
        }
        if (time() - $shown < 3 || ! RateLimiter::attempt('site-funnel:'.$request->ip(), 10, fn (): bool => true)) {
            return redirect($back.'?sent=1', 303); // too fast for a person, or too many
        }
        try {
            $done = $funnels->capture($site, $funnel, $request->only(['name', 'email', 'phone', 'sms_consent', 'address', 'zip', 'beds', 'sqft', 'budget', 'timeline', 'preapproved',
                'move_date', 'financing', 'first_referrer', 'landing_url', 'vid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid']), $request);
        } catch (ValidationException $e) {
            return redirect($back.'?error='.urlencode((string) (collect($e->errors())->flatten()->first() ?? 'Please check the form.')).'#ask', 303);
        }
        $e = $done['estimate'];
        $budget = is_numeric(preg_replace('/[^0-9.]/', '', (string) $request->input('budget', ''))) ? (int) preg_replace('/[^0-9]/', '', explode('.', (string) $request->input('budget'))[0]) : null;
        $result = ['f' => $funnel->ulid, 'e' => time() + 86400, 'low' => $e['low'] ?? null, 'mid' => $e['mid'] ?? null, 'high' => $e['high'] ?? null, 'comps' => $e['comps'] ?? 0,
            'budget' => $budget !== null && $budget >= 1000 ? $budget : null, 'address' => mb_substr(trim(strip_tags((string) $request->input('address', ''))), 0, 150)];

        return redirect($back.'?r='.urlencode(Crypt::encryptString((string) json_encode($result))).'#ask', 303);
    }

    /** "39211" or "Jackson, MS" as the market page's place ("39211", "jackson-ms"); anything else finds nothing. */
    private static function place(string $area): string
    {
        if (preg_match('/\b(\d{5})\b/', $area, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^(.+),\s*([A-Za-z]{2})$/', trim($area), $m) === 1) {
            return Str::slug($m[1]).'-'.strtolower($m[2]);
        }

        return '-';
    }

    private static function bot(Request $request): bool
    {
        return preg_match('/bot|crawl|spider|preview|facebookexternalhit|slurp/i', (string) $request->userAgent()) === 1;
    }
}
