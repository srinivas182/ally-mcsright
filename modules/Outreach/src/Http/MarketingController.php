<?php

declare(strict_types=1);

namespace Modules\Outreach\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Branding\EmailSignature;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Contact;
use Modules\Crm\SmartLists;
use Modules\Outreach\ContentPack;
use Modules\Outreach\HandwrittenCards;
use Modules\Outreach\MarketReports;
use Modules\Sites\Funnels\HomeReports;
use Modules\Sites\Models\HomeReport;

/** MK2 (specs/crm/21): email signature, monthly content pack, homeowners (home-value reports for past clients) and handwritten cards. */
final class MarketingController
{
    public function signature(Request $request, EmailSignature $signature): Response
    {
        $user = self::user($request);

        return Inertia::render('outreach/signature', ['text' => $signature->text($user), 'html' => $signature->html($user)]);
    }

    public function content(Request $request, ContentPack $pack): Response
    {
        $user = self::user($request);
        $places = $pack->places();
        $place = is_string($request->query('place')) ? $request->query('place') : ($places[0]['slug'] ?? null);

        return Inertia::render('outreach/content', ['places' => $places, 'place' => $place, 'pack' => $place !== null ? $pack->build($user, $place) : null]);
    }

    public function homeowners(Request $request, HomeReports $reports): Response
    {
        $user = self::user($request);
        $all = self::manager($user);
        $rows = HomeReport::query()->when(! $all, fn ($q) => $q->where('user_id', $user->id))->orderByDesc('id')->limit(300)->get();
        $names = Contact::query()->whereIn('id', $rows->pluck('contact_id')->all() ?: [0])->get()->keyBy('id');

        return Inertia::render('outreach/homeowners', [
            'reports' => $rows->map(fn (HomeReport $r): array => ['contact' => ($c = $names->get($r->contact_id)) instanceof Contact ? trim($c->first_name.' '.$c->last_name) : '—',
                'address' => $r->address, 'last_mid' => $r->last_mid, 'sent' => $r->sent_count, 'next_on' => $r->next_on->toDateString(),
                'opened' => $r->dashboard_viewed_at?->toDateString(), 'stopped' => $r->unsubscribed_at !== null])->values(),
            'waiting' => count($reports->pastClientsWithout($all ? null : $user->id)),
        ]);
    }

    public function startHomeowners(Request $request, HomeReports $reports): RedirectResponse
    {
        $user = self::user($request);
        $started = 0;
        foreach ($reports->pastClientsWithout(self::manager($user) ? null : $user->id) as $c) {
            if ($reports->start($c, null, (string) $c->address_line1, (string) $c->zip, null, null, null, $c->owner_user_id ?? $user->id) !== null) {
                $started++;
            }
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $started === 0 ? 'Everyone is already getting it.' : "Started for {$started} past ".($started === 1 ? 'client' : 'clients').'. The first email goes out in a month.']);

        return back();
    }

    public function cards(Request $request, HandwrittenCards $cards, SmartLists $lists, ContactVisibility $visibility): Response
    {
        $user = self::user($request);
        $suggest = [];
        foreach (['birthdays-week' => 'birthday', 'anniversaries-week' => 'home_anniversary'] as $key => $occasion) {
            $filters = $lists->filtersFor($key, $user);
            foreach ($filters !== null ? $lists->apply($visibility->query($user), $filters)->limit(20)->get() : [] as $c) {
                $suggest[] = ['ulid' => $c->ulid, 'name' => trim($c->first_name.' '.$c->last_name), 'occasion' => $occasion, 'has_address' => ($c->address_line1 ?? '') !== '' && ($c->zip ?? '') !== ''];
            }
        }
        $contact = is_string($request->query('contact')) ? Contact::query()->where('ulid', $request->query('contact'))->first() : null;

        return Inertia::render('outreach/cards', ['requests' => $cards->forUser($user), 'occasions' => HandwrittenCards::OCCASIONS, 'max' => HandwrittenCards::MAX_CHARS,
            'suggest' => $suggest, 'contact' => $contact !== null && $visibility->canSee($user, $contact) ? ['ulid' => $contact->ulid, 'name' => trim($contact->first_name.' '.$contact->last_name)] : null]);
    }

    public function requestCard(Request $request, HandwrittenCards $cards, ContactVisibility $visibility): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['contact' => ['required', 'string', 'size:26'], 'occasion' => ['required', 'string'], 'message' => ['required', 'string', 'max:400']]);
        $contact = Contact::query()->where('ulid', $data['contact'])->firstOrFail();
        abort_unless($visibility->canSee($user, $contact), 403);
        $cards->request($user, $contact, $data['occasion'], $data['message']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Card on its way to the writer. It is usually mailed within 3 business days.']);

        return back();
    }

    /** MK3 (D09): monthly market report emails by area. */
    public function marketReports(Request $request, MarketReports $reports, ContentPack $pack): Response
    {
        $user = self::user($request);

        return Inertia::render('outreach/market-reports', ['places' => $pack->places(), 'audiences' => $reports->audiences($user),
            'reports' => $reports->summary(self::manager($user) ? null : $user->id)]);
    }

    public function startMarketReports(Request $request, MarketReports $reports): RedirectResponse
    {
        $data = $request->validate(['place' => ['required', 'string', 'max:80'], 'audience' => ['required', 'string', 'max:40']]);
        $n = $reports->start(self::user($request), (string) $data['audience'], (string) $data['place']);
        Inertia::flash('toast', ['type' => 'success', 'message' => $n === 0 ? 'Nobody new to add: everyone in that list already gets a market report or has no email.'
            : "Started for {$n} ".($n === 1 ? 'person' : 'people').'. The first report goes out tomorrow morning.']);

        return back();
    }

    /** The one-click stop from the email (GET shows a button; POST is what mail apps send for one-click). */
    public function stopMarketReport(Request $request, MarketReports $reports, string $report): HttpResponse
    {
        abort_unless($request->hasValidSignature(false), 403);
        if ($request->isMethod('post')) {
            $reports->stop($report);
        }

        return response()->view('outreach::market-report-stop', ['action' => $request->getRequestUri(), 'done' => $request->isMethod('post')])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private static function manager(User $user): bool
    {
        return $user->isKind(UserKind::Owner) || $user->isKind(UserKind::Staff);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
