<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Core\Privacy\PrivacyConfirmMail;
use Modules\Core\Privacy\PrivacyRequests;
use Modules\Core\Security\BotProtection;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Models\Site;
use Modules\Sites\Rendering\Chrome;
use Modules\Sites\Rendering\Seo;

/**
 * The website's privacy policy, on every brokerage website at /privacy (specs/websites/15). It says plainly what this
 * website collects and why, matching what RightAlly actually does: forms and accounts, the homes someone views (no
 * cookies, no third-party trackers, Global Privacy Control honoured), texts only with consent, leads never sold.
 * The owner can replace it with their own policy (Website settings). Lead-form ads link here.
 */
final class PrivacyController
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function show(Request $request, ?string $notice = null): Response
    {
        $site = Site::query()->where('kind', 'brokerage')->first();
        if ($site === null || $site->status !== 'live') {
            return response()->view('sites::missing', ['site' => $site], 404);
        }
        $name = $site->displayName();
        $custom = trim((string) ($site->info['privacy'] ?? ''));

        return response()->view('sites::privacy', [
            'site' => $site, 'current' => '/privacy', 'name' => $name, 'info' => $site->info,
            'custom' => $custom !== '' ? array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $custom) ?: []))) : null,
            'updated' => $site->updated_at?->format('F j, Y'),
            'seo' => Seo::build($site, $request->getSchemeAndHttpHost(), '/privacy', 'Privacy policy | '.(string) ($site->seo['title_suffix'] ?? $name),
                'How '.$name.' collects, uses and protects your information on this website.', null, true, [['Privacy policy', '/privacy']], []),
            'chrome' => Chrome::for($site, $this->tenants->id(), []),
            // TR1: the privacy request form.
            'kinds' => PrivacyRequests::KINDS, 'notice' => $notice ?? ($request->query('sent') === '1' ? 'sent' : null),
            'formError' => is_string($request->query('error')) ? mb_substr((string) $request->query('error'), 0, 200) : null,
            'bot' => app(BotProtection::class)->widget($this->tenants->id()),
        ])->header('Cache-Control', $notice !== null || $request->query() !== [] ? 'no-store' : 'public, max-age=60, s-maxage=300');
    }

    /**
     * TR1 (specs/core/18): a privacy request from the website. We email a link to confirm it is really them; nothing
     * reaches the brokerage until they confirm. Honeypot, bot check and 5 an hour per address.
     */
    public function store(Request $request, PrivacyRequests $requests): RedirectResponse
    {
        $back = $request->getSchemeAndHttpHost().'/privacy';
        if (trim((string) $request->input('website', '')) !== '' || ! RateLimiter::attempt('privacy-request:'.$request->ip(), 5, fn (): bool => true, 3600)
            || ! app(BotProtection::class)->passes($request, $this->tenants->id())) {
            return redirect($back.'?sent=1#request', 303);
        }
        try {
            $token = $requests->ask(['kind' => (string) $request->input('kind', ''), 'name' => (string) $request->input('name', ''), 'email' => (string) $request->input('email', ''),
                'phone' => (string) $request->input('phone', ''), 'details' => (string) $request->input('details', '')]);
        } catch (ValidationException $e) {
            return redirect($back.'?error='.rawurlencode((string) collect($e->errors())->flatten()->first()).'#request', 303);
        }
        $site = Site::query()->where('kind', 'brokerage')->first();
        Notification::route('mail', mb_strtolower(trim((string) $request->input('email'))))->notify(new PrivacyConfirmMail(trim((string) $request->input('name')),
            $site?->displayName() ?? (string) $this->tenants->current()->name, $request->getSchemeAndHttpHost().'/privacy/confirm/'.$token));

        return redirect($back.'?sent=1#request', 303);
    }

    public function confirm(Request $request, PrivacyRequests $requests, string $token): Response
    {
        $req = $requests->confirm($token);

        return $this->show($request, $req !== null ? 'confirmed:'.((string) $req->due_on) : 'expired');
    }
}
