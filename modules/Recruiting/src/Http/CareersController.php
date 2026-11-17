<?php

declare(strict_types=1);

namespace Modules\Recruiting\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Core\Tenancy\TenantContext;
use Modules\Recruiting\Careers;
use Modules\Recruiting\EarnHere;
use Modules\Recruiting\Models\Recruit;
use Modules\Sites\Http\Controllers\SiteController;
use Modules\Sites\Models\Site;
use Modules\Sites\Rendering\Chrome;
use Modules\Sites\Rendering\Seo;

/** /careers on the brokerage's website (RC2, specs/grow/17): public, cacheable, no session. */
final class CareersController
{
    public function show(Request $request, Careers $careers, EarnHere $earn): Response
    {
        $site = Site::query()->where('kind', 'brokerage')->first();
        if ($site === null || ! $careers->live()) {
            return response()->view('sites::missing', ['site' => $site], 404)->header('Cache-Control', 'public, max-age=60');
        }
        $c = $careers->settings();
        $name = $site->displayName();
        $result = null;
        $asked = $c['calculator'] && $request->filled(['deals', 'price']);
        if ($asked) {
            $deals = max(1, min(100, (int) $request->query('deals')));
            $price = max(10_000, min(50_000_000, (int) preg_replace('/\D/', '', (string) $request->query('price'))));
            $split = $request->filled('split') ? max(0, min(100, (float) $request->query('split'))) : null;
            $cap = $request->filled('cap') ? max(0, min(1_000_000, (int) preg_replace('/\D/', '', (string) $request->query('cap')))) : null;
            $visitor = new Recruit(['name' => 'You', 'current_split' => $split !== null ? (string) $split : null, 'current_cap_cents' => $cap !== null ? $cap * 100 : null]);
            $result = $earn->compare($visitor, ['deals' => $deals, 'price_cents' => $price * 100]) + ['input' => compact('deals', 'price', 'split', 'cap')];
        }
        $i = $site->info;
        $faqs = [
            ['q' => "How much would I keep at {$name}?", 'a' => $c['calculator'] ? 'Enter your deals a year and average price above: we run them through our real commission plan, the same one that pays our agents, and compare it with your plan today.' : 'Ask us: we will work it out from your own last 12 months.'],
            ['q' => "How do I join {$name}?", 'a' => 'Send the form on this page. A broker calls you within one business day to talk it through, with no obligation.'],
            ['q' => 'Do I need my own real estate licence?', 'a' => 'Yes, an active real estate licence in '.(($i['state'] ?? '') !== '' ? $i['state'] : 'our state').', or one in progress.'],
        ];
        $extra = [];
        if (($i['city'] ?? '') !== '' && ($i['state'] ?? '') !== '') {
            $root = rtrim($request->getSchemeAndHttpHost(), '/');
            $extra[] = array_filter(['@type' => 'JobPosting', 'title' => 'Real estate agent', 'description' => '<p>'.e($c['intro']).'</p><ul>'.implode('', array_map(fn (string $r): string => '<li>'.e($r).'</li>', $c['reasons'])).'</ul>',
                'datePosted' => now()->startOfMonth()->toDateString(), 'validThrough' => now()->startOfMonth()->addMonths(2)->toDateString(), 'employmentType' => 'CONTRACTOR', 'directApply' => true,
                'hiringOrganization' => ['@id' => $root.'/#organization'], 'jobLocation' => ['@type' => 'Place', 'address' => array_filter(['@type' => 'PostalAddress',
                    'streetAddress' => ($i['street'] ?? '') ?: null, 'addressLocality' => $i['city'], 'addressRegion' => $i['state'], 'postalCode' => ($i['zip'] ?? '') ?: null, 'addressCountry' => 'US'])]]);
        }

        return response()->view('recruiting::careers', [
            'site' => $site, 'current' => '/careers', 'careers' => $c, 'stories' => $careers->stories(), 'result' => $result, 'faqs' => $faqs,
            'textConsent' => Careers::textConsentWording($name),
            'seo' => Seo::build($site, $request->getSchemeAndHttpHost(), '/careers', "Careers at {$name}: join our real estate team", mb_substr($c['intro'], 0, 160), null, ! $asked,
                [['Careers', '/careers']], $faqs, $extra),
            'chrome' => Chrome::for($site, app(TenantContext::class)->id()),
            ...SiteController::form($request),
        ])->header('Cache-Control', $asked || $request->query('error') !== null || $request->query('sent') !== null ? 'no-store' : 'public, max-age=60, s-maxage=300');
    }

    public function apply(Request $request, Careers $careers): RedirectResponse
    {
        $back = $request->getSchemeAndHttpHost().'/careers';
        if (! $careers->live() || trim((string) $request->input('website', '')) !== '') {
            return redirect($back.'?sent=1#apply', 303);
        }
        try {
            $shown = (int) Crypt::decryptString((string) $request->input('t', ''));
        } catch (\Throwable) {
            $shown = PHP_INT_MAX;
        }
        if (time() - $shown < 3 || ! RateLimiter::attempt('careers-apply:'.$request->ip(), 5, fn (): bool => true, 3600)) {
            return redirect($back.'?sent=1#apply', 303);
        }
        $name = mb_substr(trim(strip_tags((string) $request->input('name', ''))), 0, 120);
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $digits = (string) preg_replace('/\D/', '', (string) $request->input('phone', ''));
        $phone = strlen($digits) === 10 ? '+1'.$digits : (strlen($digits) === 11 && $digits[0] === '1' ? '+'.$digits : null);
        if ($name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190) {
            return redirect($back.'?error='.urlencode('Enter your name and a working email address.').'#apply', 303);
        }
        $clean = fn (string $k, int $max): ?string => ($v = mb_substr(trim(strip_tags((string) $request->input($k, ''))), 0, $max)) !== '' ? $v : null;
        $careers->apply(['name' => $name, 'email' => $email, 'phone' => $phone, 'licence' => $clean('licence', 40), 'brokerage' => $clean('brokerage', 120),
            'deals' => is_numeric($request->input('deals')) ? max(0, min(500, (int) $request->input('deals'))) : null, 'message' => $clean('message', 1000),
            'text_consent' => $request->boolean('text_consent')]);

        return redirect($back.'?sent=1#apply', 303);
    }
}
