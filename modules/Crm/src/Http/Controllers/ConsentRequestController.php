<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Consent\ConsentRequests;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Contact;
use stdClass;

/**
 * One-tap texting consent (CN1, specs/grow/18): the agent asks one contact or everyone in their list who can be asked;
 * the contact answers on a plain public page (no sign-in, never indexed, never cached).
 */
final class ConsentRequestController
{
    public function ask(Request $request, ConsentRequests $requests, Contact $contact): RedirectResponse
    {
        $requests->send($contact, $this->user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent. They can agree in one tap from the email.']);

        return back();
    }

    public function page(Request $request, ConsentRequests $requests): Response
    {
        $user = $this->user($request);
        $eligible = $this->eligible($user, $requests);

        return Inertia::render('crm/texting-consent', [
            'eligible' => $eligible->count(),
            'sample' => $eligible->take(10)->map(fn (Contact $c): array => ['ulid' => $c->ulid, 'name' => $c->name])->values(),
            'daily' => ConsentRequests::DAILY,
            'wording' => ConsentRequests::wording((string) app(TenantContext::class)->current()->name),
        ]);
    }

    public function askAll(Request $request, ConsentRequests $requests): RedirectResponse
    {
        $user = $this->user($request);
        $r = $requests->sendMany($this->eligible($user, $requests)->take(ConsentRequests::DAILY), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $r['sent'].' sent.'.($r['skipped'] > 0 ? ' '.$r['skipped'].' skipped (already asked, or today\'s limit).' : '')]);

        return back();
    }

    /** The public page the email opens. */
    public function show(Request $request, ConsentRequests $requests, string $token): HttpResponse
    {
        $req = $requests->find($token);
        $state = $req === null || CarbonImmutable::parse((string) $req->expires_at)->isPast() ? 'expired' : ($req->answer ?? 'ask');

        return $this->view($state, $req, $token);
    }

    public function answer(Request $request, ConsentRequests $requests, string $token): HttpResponse
    {
        $yes = $request->input('answer') === 'yes';
        $state = $requests->answer($token, $yes, $request);

        return $this->view($state, $requests->find($token), $token);
    }

    private function view(string $state, ?stdClass $req, string $token): HttpResponse
    {
        $digits = (string) preg_replace('/\D+/', '', $req !== null ? (string) $req->phone : '');

        return response()->view('crm::consent.ask', [
            'state' => $state, 'brokerage' => (string) app(TenantContext::class)->current()->name, 'wording' => $req !== null ? (string) $req->wording : '',
            'phone' => strlen($digits) >= 4 ? '•••-•••-'.substr($digits, -4) : '', 'token' => $token,
        ], $state === 'expired' ? 410 : 200)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    /** @return Collection<int, Contact> */
    private function eligible(User $user, ConsentRequests $requests): Collection
    {
        return app(ContactVisibility::class)->query($user)->whereNotNull('primary_phone')->whereNotNull('primary_email')->where('sms_opt_in', false)
            ->orderByDesc('last_activity_at')->limit(1000)->get()->filter(fn (Contact $c): bool => $requests->blocker($c) === null)->values();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
