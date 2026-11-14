<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Search\ListingSearch;
use Modules\Sites\Models\Site;
use Modules\Sites\Rendering\Chrome;
use Modules\Sites\Rendering\Seo;
use Modules\Sites\Visitors\SiteLeads;
use Modules\Sites\Visitors\Valuation;

/**
 * "What's my home worth?" (specs/websites/07): a price range from comparable homes for sale, shown once the owner
 * leaves their details, so every request is a seller lead with the estimate attached for the agent.
 */
final class ValuationController
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function show(Request $request): Response
    {
        $site = Site::query()->where('kind', 'brokerage')->first();
        if ($site === null || $site->status !== 'live') {
            return response()->view('sites::missing', ['site' => $site], 404);
        }
        $result = null;
        if (is_string($request->query('r'))) {
            try {
                $r = json_decode(Crypt::decryptString($request->query('r')), true);
                $result = is_array($r) && (int) ($r['e'] ?? 0) > time() ? $r : null;
            } catch (\Throwable) {
                $result = null;
            }
        }
        $comps = [];
        if ($result !== null) {
            foreach (array_slice((array) ($result['ulids'] ?? []), 0, 3) as $u) {
                $home = is_string($u) ? app(ListingSearch::class)->find($this->tenants->id(), $u) : null;
                if ($home !== null) {
                    $comps[] = $home + ['reduced' => false];
                }
            }
        }
        $name = $site->displayName();

        return response()->view('sites::valuation', [
            'site' => $site, 'current' => '/home-value', 'result' => $result, 'comps' => $comps,
            'seo' => Seo::build($site, $request->getSchemeAndHttpHost(), '/home-value', 'What is my home worth? Free home value | '.(string) ($site->seo['title_suffix'] ?? $name),
                'Get a free price range for your home from comparable homes for sale nearby, confirmed by a local agent from '.$name.'.', null, $result === null, [['Home value', '/home-value']],
                [['q' => 'How is my home value worked out?', 'a' => 'We compare your home with similar homes for sale in your ZIP code by size and bedrooms, then a local agent checks recent sales and your home\'s condition to confirm the price.'],
                    ['q' => 'Is the home value free?', 'a' => 'Yes. There is no cost and no obligation to sell.']]),
            'chrome' => Chrome::for($site, $this->tenants->id(), array_column($comps, 'disclaimer')),
            ...SiteController::form($request),
        ])->header('Cache-Control', $result === null && $request->query('error') === null ? 'public, max-age=60, s-maxage=300' : 'no-store');
    }

    public function submit(Request $request, Valuation $valuation, SiteLeads $leads): RedirectResponse
    {
        $site = Site::query()->where('kind', 'brokerage')->where('status', 'live')->first();
        $back = $request->getSchemeAndHttpHost().'/home-value';
        if ($site === null || trim((string) $request->input('website', '')) !== '') {
            return redirect($back.'?sent=1', 303);
        }
        try {
            $shown = (int) Crypt::decryptString((string) $request->input('t', ''));
        } catch (\Throwable) {
            $shown = PHP_INT_MAX;
        }
        if (time() - $shown < 3 || ! RateLimiter::attempt('site-valuation:'.$request->ip(), 10, fn (): bool => true)) {
            return redirect($back.'?sent=1', 303);
        }
        $address = mb_substr(trim(strip_tags((string) $request->input('address', ''))), 0, 150);
        $zip = preg_match('/^\d{5}$/', trim((string) $request->input('zip', ''))) === 1 ? trim((string) $request->input('zip')) : null;
        if ($address === '' || $zip === null) {
            return redirect($back.'?error='.urlencode('Enter your street address and 5-digit ZIP code.').'#value', 303);
        }
        $int = static fn (string $k, int $min, int $max): ?int => is_numeric($request->input($k)) && (int) $request->input($k) >= $min ? min($max, (int) $request->input($k)) : null;
        $beds = $int('beds', 0, 20);
        $baths = $int('baths', 0, 20);
        $sqft = $int('sqft', 300, 50000);
        $estimate = $valuation->estimate($this->tenants->id(), $zip, $beds, $sqft);
        $facts = trim(implode(', ', array_filter([$beds !== null ? $beds.' bd' : null, $baths !== null ? $baths.' ba' : null, $sqft !== null ? number_format($sqft).' sq ft' : null])));
        $message = 'Home value request for '.$address.' '.$zip.($facts !== '' ? ' ('.$facts.')' : '').'. '
            .($estimate !== null ? 'Website estimate: $'.number_format($estimate['low']).' to $'.number_format($estimate['high']).' from '.$estimate['comps'].' homes for sale nearby.'
                : 'Not enough similar homes for sale to estimate; please prepare a price range.')
            .(trim((string) $request->input('message', '')) !== '' ? "\n".mb_substr(trim((string) $request->input('message')), 0, 1000) : '');
        try {
            $leads->capture($site, SiteLeads::source('Home valuation', 'What is my home worth?'), [
                'name' => (string) $request->input('name', ''), 'email' => (string) $request->input('email', ''), 'phone' => (string) $request->input('phone', ''),
                'intent' => 'sell', 'property' => $address.' '.$zip, 'zip' => $zip, 'price' => $estimate['mid'] ?? null, 'message' => $message,
                'sms_consent' => $request->boolean('sms_consent'), 'referrer' => mb_substr((string) $request->input('first_referrer', ''), 0, 500),
                'landing_url' => mb_substr((string) $request->input('landing_url', ''), 0, 500),
            ], $request, 'valuation', null, ['address' => $address.' '.$zip, 'mid' => $estimate['mid'] ?? null]);
        } catch (ValidationException $e) {
            return redirect($back.'?error='.urlencode((string) (collect($e->errors())->flatten()->first() ?? 'Please check the form.')).'#value', 303);
        }
        $result = ['address' => $address, 'zip' => $zip, 'e' => time() + 86400, 'low' => $estimate['low'] ?? null, 'mid' => $estimate['mid'] ?? null,
            'high' => $estimate['high'] ?? null, 'comps' => $estimate['comps'] ?? 0, 'ulids' => $estimate['comp_ulids'] ?? []];

        return redirect($back.'?r='.urlencode(Crypt::encryptString((string) json_encode($result))).'#value', 303);
    }
}
