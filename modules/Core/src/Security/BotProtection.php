<?php

declare(strict_types=1);

namespace Modules\Core\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\OutsideServices;
use Throwable;

/**
 * Bot protection on public forms (TR1, specs/core/18), on top of the honeypot, the timing check and rate limits every
 * form already has. Uses the "Bot protection" outside service (spec 16): HQ's account by default, a brokerage's own on
 * its website. Off: forms work as before. Turnstile, hCaptcha or reCAPTCHA; no picture puzzles for most people.
 *
 * A wrong or missing answer fails the check; if the provider cannot be reached the form still goes through (a lead is
 * never lost because a provider is down) and the outage is logged. Nothing about the person is sent but the answer
 * token and their IP address, which the provider needs.
 */
final class BotProtection
{
    private const FIELDS = ['turnstile' => 'cf-turnstile-response', 'hcaptcha' => 'h-captcha-response', 'recaptcha' => 'g-recaptcha-response'];

    private const VERIFY = [
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'hcaptcha' => 'https://api.hcaptcha.com/siteverify',
        'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
    ];

    private const SCRIPTS = [
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
        'hcaptcha' => 'https://js.hcaptcha.com/1/api.js',
        'recaptcha' => 'https://www.google.com/recaptcha/api.js',
    ];

    private const CLASSES = ['turnstile' => 'cf-turnstile', 'hcaptcha' => 'h-captcha', 'recaptcha' => 'g-recaptcha'];

    public function __construct(private readonly OutsideServices $services) {}

    /**
     * What a form needs to show the check, or null when bot protection is off.
     *
     * @return array{provider: string, site_key: string, script: string, class: string}|null
     */
    public function widget(?int $tenantId = null): ?array
    {
        $active = $this->services->active('bot_protection', $tenantId);
        $key = (string) ($active['keys']['site_key'] ?? '');
        if ($active === null || $key === '' || ! isset(self::SCRIPTS[$active['provider']])) {
            return null;
        }

        return ['provider' => $active['provider'], 'site_key' => $key, 'script' => self::SCRIPTS[$active['provider']], 'class' => self::CLASSES[$active['provider']]];
    }

    public function passes(Request $request, ?int $tenantId = null): bool
    {
        $active = $this->services->active('bot_protection', $tenantId);
        if ($active === null || ! isset(self::VERIFY[$active['provider']])) {
            return true;
        }
        $token = $request->input(self::FIELDS[$active['provider']]);
        if (! is_string($token) || $token === '' || strlen($token) > 4096) {
            return false;
        }
        try {
            $r = Http::timeout(5)->asForm()->post(self::VERIFY[$active['provider']], ['secret' => (string) ($active['keys']['secret_key'] ?? ''),
                'response' => $token, 'remoteip' => (string) $request->ip()]);
        } catch (Throwable $e) {
            Log::warning('Bot protection could not be checked; the form went through', ['provider' => $active['provider'], 'error' => $e->getMessage()]);

            return true;
        }
        if (! $r->successful()) {
            Log::warning('Bot protection answered with an error; the form went through', ['provider' => $active['provider'], 'status' => $r->status()]);

            return true;
        }

        return $r->json('success') === true;
    }
}
