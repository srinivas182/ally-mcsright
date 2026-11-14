<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers\Leads;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Leads\LeadIntake;
use Modules\Crm\Models\LeadSource;

/**
 * Hosted lead form (server-rendered for speed, accessibility and crawlers; noindex as a utility page) and the
 * endpoint that embedded plain-HTML forms post to (specs/grow/04, websites/04).
 */
final class LeadFormController
{
    public function show(Request $request, string $token, TenantContext $tenants): Response
    {
        $source = self::source($token);
        $referrer = (string) $request->headers->get('referer', '');

        return $this->page($source, $tenants, [
            'first_referrer' => $tenants->current()->domains()->where('host', parse_url($referrer, PHP_URL_HOST) ?: '')->exists() ? '' : $referrer,
            'landing_url' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, string $token, LeadIntake $intake, TenantContext $tenants): RedirectResponse|Response
    {
        $source = self::source($token);
        $thanks = redirect('/l/'.$token.'/thanks', 303);
        if (trim((string) $request->input('website', '')) !== '') {
            return $thanks; // honeypot: a bot filled the hidden field
        }
        if ($request->filled('t')) {
            try {
                $shown = (int) Crypt::decryptString((string) $request->input('t'));
            } catch (\Throwable) {
                $shown = 0;
            }
            if (time() - $shown < 3) {
                return $thanks; // filled faster than a person can
            }
        }
        $referer = (string) $request->headers->get('referer', '');
        $input = [
            'first_name' => (string) $request->input('first_name', ''), 'last_name' => (string) $request->input('last_name', ''),
            'email' => (string) $request->input('email', ''), 'phone' => (string) $request->input('phone', ''),
            'message' => mb_substr((string) $request->input('message', ''), 0, 4000), 'intent' => (string) $request->input('intent', ''),
            'zip' => (string) $request->input('zip', ''), 'property' => mb_substr((string) $request->input('property', ''), 0, 200),
            'referrer' => mb_substr((string) $request->input('first_referrer', ''), 0, 500),
            'landing_url' => mb_substr((string) ($request->input('landing_url') ?: $referer), 0, 500),
            'sms_consent' => $request->boolean('sms_consent'), 'consent_text' => (string) $source->consent_wording,
        ];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid'] as $k) {
            if (is_string($request->input($k))) {
                $input[$k] = mb_substr((string) $request->input($k), 0, 150);
            }
        }
        try {
            $intake->receive($source, $input, $request);
        } catch (ValidationException $e) {
            return $this->page($source, $tenants, $input, $e->errors(), 422);
        }

        return $thanks;
    }

    public function thanks(string $token, TenantContext $tenants): Response
    {
        $source = self::source($token);

        return response()->view('crm::leads.thanks', ['brokerage' => $tenants->current()->name, 'source' => $source])->header('X-Robots-Tag', 'noindex');
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, list<string>>  $errors
     */
    private function page(LeadSource $source, TenantContext $tenants, array $old, array $errors = [], int $status = 200): Response
    {
        $tenant = $tenants->current();
        $url = url('/l/'.$source->token);
        $schema = ['@context' => 'https://schema.org', '@type' => 'RealEstateAgent', 'name' => $tenant->name, 'url' => url('/'),
            'potentialAction' => ['@type' => 'CommunicateAction', 'name' => (string) $source->heading, 'target' => $url]];

        return response()->view('crm::leads.form', ['source' => $source, 'brokerage' => $tenant->name, 'action' => $url, 'canonical' => $url,
            'schema' => json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP), 'old' => $old, 'errors' => $errors,
            't' => Crypt::encryptString((string) time())], $status)->header('X-Robots-Tag', 'noindex, follow');
    }

    private static function source(string $token): LeadSource
    {
        return LeadSource::query()->where('token', $token)->where('kind', 'form')->where('active', true)->firstOrFail();
    }
}
