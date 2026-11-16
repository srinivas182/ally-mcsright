<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\DealIq\UploadReceivedMail;
use Modules\Transactions\DealIq\UploadRequests;
use Modules\Transactions\Documents;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Portal\Concierge;
use Modules\Transactions\Portal\Copy;
use Modules\Transactions\Portal\PortalAccess;
use Modules\Transactions\Portal\PortalFeedbackMail;
use Modules\Transactions\Portal\PortalView;
use Modules\Transactions\Portal\SignIn;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The deal portal for clients and the other side (specs/ai/02, §§6, 22). No account and no password: a sign-in link
 * puts the access into this browser's session; every page checks it is still allowed. Pages are never indexed.
 */
final class PortalController
{
    public function signIn(Request $request): Response
    {
        $locale = Copy::locale((string) $request->query('lang', 'en'));
        app(SignIn::class)->fromDevice($request);
        $mine = $this->mine($request);
        if (count($mine) === 1) {
            return redirect('/my-deal/'.$mine[0]->ulid);
        }

        return $this->render($request, 'public/my-deal', ['t' => Copy::all($locale), 'locales' => Copy::LOCALES, 'locale' => $locale,
            'deals' => array_map(fn (\stdClass $a): array => ['ulid' => (string) $a->ulid, 'street' => (string) (Deal::query()->whereKey((int) $a->deal_id)->value('street') ?? '')], $mine),
            'sent' => (bool) $request->session()->get('portal_sent', false), 'expired' => $request->boolean('expired'),
            'locked' => $request->session()->get('portal_error') === 'code_locked']);
    }

    public function sendLink(Request $request, PortalAccess $portal): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'lang' => ['nullable', 'string', 'size:2']]);
        $portal->requestLink($data['email']);

        return redirect('/my-deal?lang='.Copy::locale($data['lang'] ?? 'en'))->with('portal_sent', true);
    }

    /**
     * The emailed link opens a page that asks for the invited address; opening it changes nothing, so email
     * security scanners cannot use it up. A remembered device for the same person goes straight in.
     */
    public function enter(Request $request, string $token, SignIn $signIn): Response
    {
        $access = $signIn->pending($token);
        if ($access === null) {
            return redirect('/my-deal?expired=1');
        }
        $signIn->fromDevice($request);
        if ($signIn->allowed($request, $access)) {
            return redirect('/my-deal/'.$access->ulid);
        }
        $locale = Copy::locale((string) $access->locale);

        return $this->render($request, 'public/my-deal-verify', ['t' => Copy::all($locale), 'locale' => $locale, 'token' => $token,
            'street' => (string) (Deal::query()->whereKey((int) $access->deal_id)->value('street') ?? ''),
            'sms' => $signIn->canText($access) ? '•••'.substr((string) $access->phone, -4) : null,
            'sentTo' => $request->session()->get('portal_code_sent'), 'error' => $request->session()->get('portal_error')]);
    }

    public function sendCode(Request $request, string $token, SignIn $signIn): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'max:254'], 'via' => ['nullable', 'in:email,sms']]);
        try {
            $where = $signIn->sendCode($token, $data['email'], $data['via'] ?? 'email');
        } catch (ValidationException $e) {
            return $this->failed($token, $e);
        }

        $request->session()->put('portal_code_sent_last', $where);

        return redirect('/my-deal/in/'.$token)->with('portal_code_sent', $where);
    }

    public function verify(Request $request, string $token, SignIn $signIn): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12'], 'remember' => ['boolean']]);
        try {
            $result = $signIn->verify($request, $token, $data['code'], (bool) ($data['remember'] ?? false));
        } catch (ValidationException $e) {
            return $this->failed($token, $e, true);
        }
        $request->session()->regenerate();
        $response = redirect('/my-deal/'.$result['access']->ulid);

        return $result['cookie'] !== null ? $response->withCookie($result['cookie']) : $response;
    }

    /** "This was not me" from the new sign-in email: a page with a button, so a scanner opening the link does nothing. */
    public function notMe(Request $request, string $token, SignIn $signIn): Response
    {
        $access = $signIn->reported($token);
        $locale = Copy::locale((string) ($access->locale ?? 'en'));

        return $this->render($request, 'public/my-deal-not-me', ['t' => Copy::all($locale), 'locale' => $locale, 'valid' => $access !== null,
            'done' => (bool) $request->session()->get('portal_not_me', false)]);
    }

    public function confirmNotMe(Request $request, string $token, SignIn $signIn): RedirectResponse
    {
        abort_unless($signIn->notMe($token), 404);
        $request->session()->forget(SignIn::SESSION);

        return redirect('/my-deal/not-me/'.$token)->with('portal_not_me', true)->withCookie(cookie()->forget(SignIn::COOKIE, '/my-deal'));
    }

    public function show(Request $request, string $access): Response
    {
        [$a, $deal] = $this->access($request, $access);
        DB::connection(Connections::cell())->table('tx_portal_access')->where('id', $a->id)->update(['last_seen_at' => now()]);

        return $this->render($request, 'public/my-deal-show', PortalView::page($deal, $a) + ['uploaded' => (bool) $request->session()->get('portal_uploaded', false),
            'saved' => (bool) $request->session()->get('portal_saved', false), 'thanks' => (bool) $request->session()->get('portal_thanks', false), 'mimes' => Documents::MIMES]);
    }

    public function ask(Request $request, string $access, Concierge $concierge): JsonResponse
    {
        [$a, $deal] = $this->access($request, $access);
        $data = $request->validate(['question' => ['required', 'string', 'max:1000']]);

        return response()->json($concierge->ask($deal, $a, $data['question']));
    }

    public function askAgent(Request $request, string $access, string $question, Concierge $concierge): JsonResponse
    {
        [$a, $deal] = $this->access($request, $access);
        $concierge->toAgent($deal, $a, $question);

        return response()->json(['ok' => true]);
    }

    public function upload(Request $request, string $access, UploadRequests $requests, Documents $documents): RedirectResponse
    {
        [$a, $deal] = $this->access($request, $access);
        $data = $request->validate(['file' => ['required', 'file', 'max:25600'], 'request' => ['nullable', 'string', 'size:26']]);
        if (isset($data['request'])) {
            $requests->receiveInPortal($data['request'], (string) $a->email, $data['file']);
        } else {
            abort_unless(PortalAccess::isClient((string) $a->role), 403);
            $agent = User::query()->findOrFail($deal->agent_user_id);
            $doc = $documents->upload($deal, null, $data['file'], 'portal', $agent);
            app(AuditLogger::class)->record('transactions.portal.uploaded', $deal, metadata: ['from' => $a->name, 'filename' => $doc->filename], label: $deal->street);
            $agent->notify(new UploadReceivedMail((string) $a->name, $doc->filename, $deal->street, '/deals/'.$deal->ulid));
        }

        return redirect('/my-deal/'.$a->ulid)->with('portal_uploaded', true);
    }

    public function document(Request $request, string $access, string $document): StreamedResponse
    {
        [$a, $deal] = $this->access($request, $access);
        abort_unless(PortalAccess::isClient((string) $a->role), 404);
        $doc = Document::query()->where('ulid', $document)->where('deal_id', $deal->id)->whereNotNull('shared_at')->firstOrFail();
        app(AuditLogger::class)->record('transactions.portal.document_viewed', $deal, metadata: ['who' => AuditLogger::mask((string) $a->email), 'filename' => $doc->filename], label: $deal->street);

        return Storage::disk('local')->download($doc->path, $doc->filename, ['X-Robots-Tag' => 'noindex', 'Cache-Control' => 'private, no-store']);
    }

    public function preferences(Request $request, string $access, PortalAccess $portal): RedirectResponse
    {
        [$a] = $this->access($request, $access);
        $data = $request->validate(['locale' => ['required', 'in:'.implode(',', array_keys(Copy::LOCALES))], 'phone' => ['nullable', 'string', 'max:20'], 'texts' => ['boolean']]);
        $portal->preferences($a, $data['locale'], $data['phone'] ?? null, (bool) ($data['texts'] ?? false));

        return redirect('/my-deal/'.$a->ulid)->with('portal_saved', true);
    }

    public function feedback(Request $request, string $access): RedirectResponse
    {
        [$a, $deal] = $this->access($request, $access);
        abort_unless(PortalAccess::isClient((string) $a->role) && $deal->status === 'closed', 404);
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1000'], 'may_quote' => ['boolean']]);
        $db = DB::connection(Connections::cell());
        if (! $db->table('tx_portal_feedback')->where('access_id', $a->id)->exists()) {
            $db->table('tx_portal_feedback')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'access_id' => $a->id, 'rating' => $data['rating'],
                'comment' => $data['comment'] ?? null, 'may_quote' => (bool) ($data['may_quote'] ?? false), 'created_at' => now(), 'updated_at' => now()]);
            User::query()->whereKey($deal->agent_user_id)->first()?->notify(new PortalFeedbackMail((string) $a->name, $deal->street, (int) $data['rating'],
                $data['comment'] ?? null, (bool) ($data['may_quote'] ?? false), '/deals/'.$deal->ulid));
        }

        return redirect('/my-deal/'.$a->ulid)->with('portal_thanks', true);
    }

    public function signOut(Request $request, SignIn $signIn): RedirectResponse
    {
        $signIn->signOut($request);

        return redirect('/my-deal')->withCookie(cookie()->forget(SignIn::COOKIE, '/my-deal'));
    }

    /**
     * The access for this page, only when this browser signed in with it and it is still allowed.
     *
     * @return array{0: \stdClass, 1: Deal}
     */
    private function access(Request $request, string $ulid): array
    {
        $signIn = app(SignIn::class);
        $a = app(PortalAccess::class)->find($ulid);
        if ($a !== null && ! $signIn->allowed($request, $a)) {
            $signIn->fromDevice($request);
        }
        if ($a === null || (int) $a->tenant_id !== app(TenantContext::class)->id() || ! $signIn->allowed($request, $a)) {
            throw new HttpResponseException($request->expectsJson() ? response()->json(['message' => 'Sign in again.'], 403) : redirect('/my-deal'));
        }
        $deal = Deal::query()->whereKey((int) $a->deal_id)->firstOrFail();

        return [$a, $deal];
    }

    /**
     * @return list<\stdClass>
     */
    private function mine(Request $request): array
    {
        $ids = app(SignIn::class)->signedIn($request);

        return $ids === [] ? [] : array_values(DB::connection(Connections::cell())->table('tx_portal_access')->whereIn('id', $ids)->whereNull('revoked_at')->get()->all());
    }

    /** Back to the sign-in page with the reason (the page words it in the client's language). */
    private function failed(string $token, ValidationException $e, bool $codeStep = false): RedirectResponse
    {
        $reason = (string) collect($e->errors())->flatten()->first();
        if ($reason === 'expired' || $reason === 'code_locked') {
            return redirect('/my-deal?expired=1')->with('portal_error', $reason);
        }
        $back = redirect('/my-deal/in/'.$token)->with('portal_error', $reason);

        return $codeStep ? $back->with('portal_code_sent', session('portal_code_sent_last')) : $back;
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function render(Request $request, string $page, array $props): Response
    {
        $response = Inertia::render($page, $props)->toResponse($request);
        $response->headers->add(['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store']);

        return $response;
    }
}
