<?php

declare(strict_types=1);

namespace Modules\Movewise\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Connections;
use Modules\Listings\Search\ListingSearch;
use Modules\Movewise\Alerts;
use Modules\Movewise\Http\Guard;
use Modules\Movewise\I18n;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Connection;
use Modules\Movewise\Models\Person;
use Modules\Movewise\Movewise;
use Modules\Movewise\MovewiseAilly;
use Modules\Movewise\People\Accounts;
use Modules\Movewise\Tools\MovePlanner;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * My Movewise (specs/movewise/02): signing in with an email code, saved homes and searches, alerts, the move planner,
 * downloading and deleting your data, and AIlly. Requests that change anything must come from Movewise's own pages
 * (same-origin check; the session cookie is SameSite=Lax and HttpOnly). Signed-in pages are never cached.
 */
final class AccountController
{
    public function __construct(private readonly Accounts $accounts, private readonly Movewise $movewise) {}

    public function start(Request $request): JsonResponse
    {
        $this->guard($request, 'auth:'.$request->ip(), 10);
        $this->accounts->start((string) $request->input('email', ''), I18n::locale());

        return response()->json(['ok' => true]);
    }

    public function verify(Request $request): JsonResponse
    {
        $this->guard($request, 'verify:'.$request->ip(), 20);
        $r = $this->accounts->verify((string) $request->input('email', ''), (string) $request->input('code', ''), I18n::locale());

        return response()->json(['ok' => true])->withCookie($r['cookie']);
    }

    public function me(Request $request): JsonResponse
    {
        $p = $this->accounts->current($request);

        return response()->json($p === null ? ['signed_in' => false, 'saved' => []] : ['signed_in' => true, 'email' => $p->email,
            'saved' => DB::connection(Connections::control())->table('movewise_saved_homes')->where('person_id', $p->id)->pluck('listing_ulid')->all()])->header('Cache-Control', 'no-store');
    }

    public function saveHome(Request $request): JsonResponse
    {
        $p = $this->person($request);
        $ulid = (string) $request->input('listing', '');
        abort_unless(preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $ulid) === 1, 422);
        $db = DB::connection(Connections::control())->table('movewise_saved_homes');
        $request->boolean('saved', true)
            ? $db->insertOrIgnore(['person_id' => $p->id, 'listing_ulid' => $ulid, 'created_at' => CarbonImmutable::now()])
            : $db->where('person_id', $p->id)->where('listing_ulid', $ulid)->delete();

        return response()->json(['ok' => true]);
    }

    public function saveSearch(Request $request): JsonResponse
    {
        $p = $this->person($request);
        $f = array_filter((array) $request->input('filters', []), fn ($v): bool => is_scalar($v) && $v !== '');
        $filters = array_intersect_key($f, array_flip(['q', 'min_price', 'max_price', 'beds', 'baths', 'state']));
        if (isset($filters['state'])) {
            $filters['states'] = [strtoupper((string) $filters['state'])];
            unset($filters['state']);
        }
        $db = DB::connection(Connections::control())->table('movewise_saved_searches');
        if ($db->where('person_id', $p->id)->count() >= 20) {
            throw ValidationException::withMessages(['search' => 'You can keep up to 20 saved searches.']);
        }
        $name = mb_substr(trim((string) ($request->input('name') ?: ($filters['q'] ?? 'Homes for sale'))), 0, 120);
        $db->insert(['ulid' => (string) Str::ulid(), 'person_id' => $p->id, 'name' => $name, 'filters' => json_encode($filters), 'frequency' => 'daily',
            'last_sent_at' => CarbonImmutable::now(), 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);

        return response()->json(['ok' => true]);
    }

    public function account(Request $request, SiteController $site): BaseResponse
    {
        $p = $this->accounts->current($request);
        $chrome = $site->chrome();
        if ($p === null) {
            return response()->view('movewise::signin', ['site' => $chrome, 'seo' => $site->seo($chrome, '/account', I18n::t('account.title').' | Movewise Homes', I18n::t('signin.lead'), false)])
                ->header('Cache-Control', 'no-store');
        }
        $db = DB::connection(Connections::control());
        $hq = $this->movewise->feedTenant();
        $homes = [];
        foreach ($db->table('movewise_saved_homes')->where('person_id', $p->id)->orderByDesc('created_at')->limit(60)->pluck('listing_ulid') as $u) {
            $h = $hq !== null ? app(ListingSearch::class)->find($hq->id, (string) $u) : null;
            if ($h !== null) {
                $label = ListingSearch::label($h);
                $homes[] = $h + ['label' => $label, 'href' => I18n::url('/homes/'.$h['ulid'].'/'.Str::slug(mb_substr($label, 0, 120)))];
            }
        }
        $plan = (array) ($p->plan ?? []);
        $agents = [];
        foreach (Connection::query()->where('person_id', $p->id)->whereIn('status', ['offered', 'accepted', 'expired', 'declined', 'stopped'])->where('offered_at', '>=', now()->subDays(60))
            ->orderByDesc('id')->limit(5)->get() as $c) {
            $a = Agent::query()->find($c->agent_id);
            $agents[] = ['ulid' => $c->ulid, 'status' => $c->status, 'name' => (string) ($a->profile['display_name'] ?? ''), 'by' => $c->accept_by->setTimezone('America/Chicago')->format('g:i a T'),
                'show' => DB::connection(Connections::control())->table('movewise_shows')->where('id', $c->show_id)->value('ulid'), 'waited' => $c->refund_reason === 'waited',
                'paused_until' => $c->paused_until?->isFuture() ? $c->paused_until->toFormattedDateString() : null, 'relay' => $c->handed_over_at === null ? $c->relay_number : null,
                'ask_met' => $c->met_agent_at !== null && $c->met_person_at === null, 'can_rate' => $c->accepted_at !== null && ($c->accepted_at->lte(now()->subDays(7)) || $c->stage === 'closed'),
                'rating' => $c->rating];
        }

        return response()->view('movewise::account', ['site' => $chrome, 'person' => $p, 'homes' => $homes,
            'searches' => $db->table('movewise_saved_searches')->where('person_id', $p->id)->orderByDesc('id')->get()->all(),
            'plan' => MovePlanner::plan(is_string($plan['move_date'] ?? null) ? $plan['move_date'] : null, array_values(array_map('strval', (array) ($plan['done'] ?? [])))),
            'move_date' => $plan['move_date'] ?? null, 'agents' => $agents,
            'seo' => $site->seo($chrome, '/account', I18n::t('account.title').' | Movewise Homes', I18n::t('account.title'), false)])->header('Cache-Control', 'no-store');
    }

    public function frequency(Request $request, string $search): RedirectResponse
    {
        $p = $this->person($request);
        $freq = in_array($request->input('frequency'), ['daily', 'weekly', 'off'], true) ? (string) $request->input('frequency') : 'daily';
        DB::connection(Connections::control())->table('movewise_saved_searches')->where('ulid', $search)->where('person_id', $p->id)
            ->update($request->boolean('delete') ? ['frequency' => 'off', 'updated_at' => now()] : ['frequency' => $freq, 'updated_at' => now()]);
        if ($request->boolean('delete')) {
            DB::connection(Connections::control())->table('movewise_saved_searches')->where('ulid', $search)->where('person_id', $p->id)->delete();
        }

        return redirect(I18n::url('/account'));
    }

    public function plan(Request $request): RedirectResponse
    {
        $p = $this->person($request);
        $date = (string) $request->input('move_date', '');
        $keys = array_merge(...array_map('array_keys', array_values(MovePlanner::TASKS)));
        $p->forceFill(['plan' => ['move_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null,
            'done' => array_values(array_intersect($keys, array_map('strval', (array) $request->input('done', []))))]])->save();

        return redirect(I18n::url('/account').'#plan');
    }

    public function export(Request $request): Response
    {
        $p = $this->person($request, false);

        return response((string) json_encode($this->accounts->export($p), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 200, [
            'Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="my-movewise.json"', 'Cache-Control' => 'no-store']);
    }

    public function delete(Request $request): RedirectResponse
    {
        $p = $this->person($request);
        abort_unless($request->boolean('confirm'), 422);
        $cookie = $this->accounts->signOut($request);
        $this->accounts->delete($p);

        return redirect(I18n::url('/'))->withCookie($cookie);
    }

    public function signOut(Request $request): RedirectResponse
    {
        $this->person($request);

        return redirect(I18n::url('/'))->withCookie($this->accounts->signOut($request));
    }

    /** "Stop these emails" from an alert: a link (GET shows the page) or one-click unsubscribe (POST, RFC 8058). */
    public function stopAlerts(Request $request, string $search, string $sig, SiteController $site): BaseResponse
    {
        abort_unless(hash_equals(Alerts::sign($search), $sig), 404);
        DB::connection(Connections::control())->table('movewise_saved_searches')->where('ulid', $search)->update(['frequency' => 'off', 'updated_at' => now()]);
        if ($request->isMethod('POST')) {
            return response('', 204);
        }
        $chrome = $site->chrome();

        return response()->view('movewise::alerts-off', ['site' => $chrome, 'seo' => $site->seo($chrome, '/account', 'Alerts stopped | Movewise Homes', 'Alerts stopped.', false)])
            ->header('Cache-Control', 'no-store');
    }

    public function ailly(Request $request, MovewiseAilly $ailly): JsonResponse
    {
        $this->guard($request, 'ailly:'.$request->ip(), 20);
        $r = $ailly->reply(is_string($request->input('chat')) ? strtoupper((string) $request->input('chat')) : null, (string) $request->input('message', ''),
            is_string($request->input('listing')) && preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', (string) $request->input('listing')) === 1 ? (string) $request->input('listing') : null,
            $this->accounts->current($request), I18n::locale());

        return response()->json($r)->header('Cache-Control', 'no-store');
    }

    private function person(Request $request, bool $write = true): Person
    {
        if ($write) {
            $this->guard($request, 'me:'.$request->ip(), 120);
        }

        return $this->accounts->current($request) ?? abort(401);
    }

    private function guard(Request $request, string $key, int $perMinute): void
    {
        Guard::check($request, $key, $perMinute);
    }
}
