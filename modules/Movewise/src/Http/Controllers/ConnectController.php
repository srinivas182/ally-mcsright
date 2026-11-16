<?php

declare(strict_types=1);

namespace Modules\Movewise\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Connections as ControlDb;
use Modules\Listings\Search\ListingSearch;
use Modules\Movewise\Http\Guard;
use Modules\Movewise\I18n;
use Modules\Movewise\Matching\Connections;
use Modules\Movewise\Matching\Matcher;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Connection;
use Modules\Movewise\Models\Person;
use Modules\Movewise\Models\State;
use Modules\Movewise\Movewise;
use Modules\Movewise\People\Accounts;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Talk to an agent" (specs/movewise/00 §4.3, 04): the person tells us about their move, sees three agents, verifies
 * their mobile, and chooses one agent and one way to be contacted. Signed-in only; never cached; works without
 * JavaScript. Errors are shown on the page (no session), with a 422.
 */
final class ConnectController
{
    public function __construct(private readonly Accounts $accounts, private readonly Connections $connections, private readonly SiteController $site) {}

    public function start(Request $request): Response
    {
        $p = $this->accounts->current($request);
        if ($p === null) {
            return app(AccountController::class)->account($request, $this->site);
        }
        $listing = is_string($request->query('listing')) ? (string) $request->query('listing') : null;

        return $this->about($p, $listing, $request->query('type') === 'sell' ? 'seller' : 'buyer', []);
    }

    public function saveAbout(Request $request, Matcher $matcher, Movewise $movewise): Response|RedirectResponse
    {
        Guard::check($request, 'connect:'.$request->ip(), 20);
        $p = $this->person($request);
        $listing = is_string($request->input('listing')) && $request->input('listing') !== '' ? (string) $request->input('listing') : null;
        try {
            $this->connections->about($p, $request->all());
            if ($p->has_agent) {
                return $this->view('movewise::connect.has-agent', []);
            }
            $context = $this->context($p, $listing, $movewise);
        } catch (ValidationException $e) {
            return $this->about($p, $listing, (string) $request->input('lead_type', 'buyer'), array_map(fn (array $m): string => (string) $m[0], $e->errors()), 422);
        }
        $open = $this->connections->open($p, $context['lead_type']);
        if ($open !== null) {
            return redirect(I18n::url('/account').'#agent', 303);
        }
        $tried = Connection::query()->where('person_id', $p->id)->where('lead_type', $context['lead_type'])->whereIn('status', ['expired', 'declined'])
            ->where('offered_at', '>=', now()->subDays(30))->pluck('agent_id')->map(fn ($i): int => (int) $i)->all();
        $show = $matcher->three($p, $context, array_values($tried));

        return redirect(I18n::url('/connect/'.$show['ulid']), 303);
    }

    public function show(Request $request, string $show): Response
    {
        return $this->cards($this->person($request), $show, []);
    }

    public function phone(Request $request, string $show): Response
    {
        Guard::check($request, 'phone:'.$request->ip(), 10);
        $p = $this->person($request);
        try {
            $request->filled('code') ? $this->connections->phoneVerify($p, (string) $request->input('code')) : $this->connections->phoneStart($p, (string) $request->input('phone', ''));
        } catch (ValidationException $e) {
            return $this->cards($p, $show, array_map(fn (array $m): string => (string) $m[0], $e->errors()), 422);
        }

        return $this->cards($p->refresh(), $show, [], 200, ! $request->filled('code'));
    }

    public function choose(Request $request, string $show): Response|RedirectResponse
    {
        Guard::check($request, 'choose:'.$request->ip(), 10);
        $p = $this->person($request);
        try {
            $this->connections->choose($p, $show, (string) $request->input('agent', ''), (string) $request->input('method', ''), is_string($request->input('slot')) ? (string) $request->input('slot') : null,
                $request->ip());
        } catch (ValidationException $e) {
            return $this->cards($p, $show, array_map(fn (array $m): string => (string) $m[0], $e->errors()), 422);
        }

        return redirect(I18n::url('/account').'#agent', 303);
    }

    public function wait(Request $request, string $connection): RedirectResponse
    {
        Guard::check($request, 'wait:'.$request->ip(), 10);
        $p = $this->person($request);
        $this->connections->wait(Connection::query()->where('ulid', $connection)->where('person_id', $p->id)->firstOrFail(), $p);

        return redirect(I18n::url('/account').'#agent', 303);
    }

    /** "Didn't ask for this?" from the email: no sign-in, a signed link; GET asks, POST ends it. */
    public function withdraw(Request $request, string $connection, string $sig): Response
    {
        abort_unless(Connections::checkSigned('/connect/withdraw/'.$connection, $sig), 404);
        $c = Connection::query()->where('ulid', $connection)->firstOrFail();
        if ($request->isMethod('POST')) {
            Guard::check($request, 'withdraw:'.$request->ip(), 10);
            $this->connections->withdraw($c, 'The person says they never asked to be contacted');
        }

        return $this->view('movewise::connect.withdraw', ['done' => $request->isMethod('POST') || $c->status === 'withdrawn']);
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function about(Person $p, ?string $listing, string $leadType, array $errors, int $status = 200): Response
    {
        $about = (array) ($p->about ?? []);

        return $this->view('movewise::connect.about', ['person' => $p, 'about' => $about + ['lead_type' => $leadType], 'listing' => $listing, 'errors_' => $errors,
            'open' => $this->connections->open($p), 'states' => config('movewise.launch_states')], $status);
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function cards(Person $p, string $showUlid, array $errors, int $status = 200, bool $codeSent = false): Response
    {
        $show = DB::connection(ControlDb::control())->table('movewise_shows')->where('ulid', $showUlid)->where('person_id', $p->id)->first() ?? abort(404);
        $context = (array) json_decode((string) $show->context, true);
        $cards = [];
        $tried = Connection::query()->where('show_id', $show->id)->whereIn('status', ['expired', 'declined', 'withdrawn'])->pluck('agent_id')->map(fn ($i): int => (int) $i)->all();
        foreach ((array) json_decode((string) $show->agents, true) as $pick) {
            $a = Agent::query()->find((int) ($pick['agent'] ?? 0));
            if ($a === null || in_array($a->id, $tried, true) || $a->status !== 'active') {
                continue;
            }
            $cards[] = ['ulid' => $a->ulid, 'profile' => $a->profile, 'brokerage' => $a->brokerage_name, 'licence' => $a->licence_number, 'state' => $a->state,
                'lookup' => self::licenceLookup($a->state), 'slots' => $p->phone_verified_at !== null ? $this->connections->slots($a) : []];
        }

        return $this->view('movewise::connect.choose', ['person' => $p, 'show' => $showUlid, 'context' => $context, 'cards' => $cards, 'errors_' => $errors, 'code_sent' => $codeSent,
            'open' => $this->connections->open($p, (string) ($context['lead_type'] ?? 'buyer'))], $status);
    }

    /**
     * @return array{state: string, zip: string|null, city: string|null, price: int|null, lead_type: string, language: string, listing: string|null}
     */
    private function context(Person $p, ?string $listing, Movewise $movewise): array
    {
        $about = (array) ($p->about ?? []);
        $on = $movewise->onStates();
        if ($about['lead_type'] === 'seller') {
            $state = strtoupper((string) ($about['home_state'] ?? ''));
            if (! in_array($state, $on, true)) {
                throw ValidationException::withMessages(['home_state' => 'Movewise doesn\'t have agents in that state yet.']);
            }

            return ['state' => $state, 'zip' => $about['home_zip'] ?? null, 'city' => $about['home_city'] ?? null, 'price' => $about['home_price'] ?? null,
                'lead_type' => 'seller', 'language' => (string) ($about['language'] ?? 'en'), 'listing' => null];
        }
        $hq = $movewise->feedTenant();
        $home = $hq !== null && $listing !== null ? app(ListingSearch::class)->find($hq->id, $listing) : null;
        if ($home === null || ! in_array((string) ($home['state'] ?? ''), $on, true)) {
            throw ValidationException::withMessages(['listing' => 'Open a home you\'d like to see, then tap "Talk to an agent" there.']);
        }

        return ['state' => (string) $home['state'], 'zip' => isset($home['postal_code']) ? substr((string) $home['postal_code'], 0, 5) : null, 'city' => $home['city'] ?? null,
            'price' => isset($home['price']) ? (int) $home['price'] : null, 'lead_type' => 'buyer', 'language' => (string) ($about['language'] ?? 'en'), 'listing' => (string) $home['ulid']];
    }

    /** The state's own licence lookup, so people can check the licence themselves. */
    public static function licenceLookup(string $state): ?string
    {
        return ['TX' => 'https://www.trec.texas.gov/apps/license-holder-search/', 'LA' => 'https://lrec.gov/license-search/', 'MS' => 'https://www.mrec.ms.gov/',
            'AL' => 'https://arec.alabama.gov/arec/'][$state] ?? null;
    }

    private function person(Request $request): Person
    {
        return $this->accounts->current($request) ?? abort(401);
    }

    /** @param  array<string, mixed>  $data */
    private function view(string $view, array $data, int $status = 200): Response
    {
        $chrome = $this->site->chrome();

        return response()->view($view, $data + ['site' => $chrome, 'seo' => $this->site->seo($chrome, '/connect', I18n::t('connect.title').' | Movewise Homes', I18n::t('connect.title'), false)], $status)
            ->header('Cache-Control', 'no-store');
    }
}
