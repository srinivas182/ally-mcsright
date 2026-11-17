<?php

declare(strict_types=1);

namespace Modules\ListIq\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\Studio\Enhance\PhotoEnhancer;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Consent;
use Modules\ListIq\Descriptions;
use Modules\ListIq\Marketing;
use Modules\ListIq\Models\OpenHouse;
use Modules\ListIq\Models\Photo;
use Modules\ListIq\Models\Prep;
use Modules\ListIq\Models\Showing;
use Modules\ListIq\Photos;
use Modules\ListIq\Preps;
use Modules\ListIq\Shoot;
use Modules\ListIq\Showings;
use Modules\ListIq\Staging\ReplicateStager;
use Modules\ListIq\Staging\VirtualStager;
use Modules\Transactions\Models\Deal;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** List-IQ (LI1, specs/listings/02): get a listing ready. */
final class ListIqController
{
    public function index(Request $request, Preps $preps): Response
    {
        $user = self::user($request);
        $q = Prep::query()->when(! $user->isKind(UserKind::Owner), fn ($w) => $w->where('owner_user_id', $user->id))->orderByDesc('updated_at')->limit(50)->get();

        return Inertia::render('listiq/index', [
            'listings' => $q->map(function (Prep $p) use ($preps): array {
                $list = $preps->checklist($p);

                return ['ulid' => $p->ulid, 'street' => $p->street, 'city' => $p->city, 'status' => $p->status, 'updated' => $p->updated_at->toDateString(),
                    'done' => count(array_filter($list, fn (array $i): bool => $i['done'])), 'total' => count($list)];
            })->all(),
            'deals' => Deal::query()->where('agent_user_id', $user->id)->where('type', 'listing')->whereNotIn('status', ['closed', 'cancelled'])->orderByDesc('id')->limit(20)
                ->get(['ulid', 'street', 'city'])->map(fn (Deal $d): array => ['ulid' => $d->ulid, 'label' => trim($d->street.', '.$d->city, ', ')])->all(),
        ]);
    }

    public function store(Request $request, Preps $preps): RedirectResponse
    {
        $prep = $preps->create(self::user($request), $request->only(['deal', 'street', 'city', 'state', 'zip', 'beds', 'baths', 'sqft', 'year_built', 'price']));

        return to_route('listiq.show', $prep);
    }

    public function show(Request $request, Prep $prep, Preps $preps): Response
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);
        $photos = Photo::query()->where('listing_id', $prep->id)->orderBy('position')->get();

        return Inertia::render('listiq/show', [
            'listing' => ['ulid' => $prep->ulid, 'street' => $prep->street, 'city' => $prep->city, 'state' => $prep->state, 'zip' => $prep->zip, 'facts' => $prep->facts, 'notes' => $prep->notes,
                'showing' => $prep->showing, 'manual' => (object) ($prep->manual ?? []), 'descriptions' => $prep->descriptions ?? [], 'description' => $prep->description,
                'description_es' => $prep->description_es, 'remarks_limit' => $prep->remarks_limit, 'status' => $prep->status,
                'flags' => $prep->description !== null ? Descriptions::check($prep->description) : []],
            'checklist' => $preps->checklist($prep),
            'rooms' => collect(Shoot::ROOMS)->map(fn (array $r, string $k): array => ['key' => $k, 'label' => $r[0], 'tip' => $r[1], 'suggested' => $r[2],
                'count' => $photos->where('room', $k)->where('kind', 'original')->count()])->values()->all(),
            'tips' => Shoot::TIPS,
            'photos' => $photos->map(fn (Photo $p): array => ['ulid' => $p->ulid, 'kind' => $p->kind, 'room' => $p->room, 'room_label' => Shoot::label($p->room), 'caption' => $p->caption,
                'use' => $p->use, 'status' => $p->status, 'error' => $p->error, 'url' => Photos::url($p->path), 'style' => $p->style])->all(),
            'styles' => array_keys(ReplicateStager::STYLES),
            'can' => ['stage' => app(VirtualStager::class)->ready(), 'enhance' => app(PhotoEnhancer::class)->ready()],
            'marketing' => ['in_mls' => $prep->in_mls, 'page_url' => app(Marketing::class)->pageUrl($prep), 'codes' => app(Marketing::class)->codes($prep),
                'kinds' => Marketing::KINDS, 'activity' => app(Marketing::class)->activity($prep), 'floor_plan' => Photos::url($prep->floor_plan_path),
                'open_houses' => OpenHouse::query()->where('listing_id', $prep->id)->orderByDesc('starts_at')->get()->map(fn (OpenHouse $o): array => ['ulid' => $o->ulid,
                    'starts' => $o->starts_at->setTimezone(app(TenantContext::class)->current()->timezone ?: 'America/Chicago')->format('D M j, g:i A'),
                    'ended' => $o->ended_at !== null, 'visitors' => count((array) $o->visitors)])->all()],
            'showings' => ['mode' => $prep->showing_mode, 'access' => $prep->access, 'seller_note' => $prep->seller_note, 'summary' => app(Showings::class)->summary($prep),
                'list' => Showing::query()->where('listing_id', $prep->id)->orderByDesc('starts_at')->limit(30)->get()->map(fn (Showing $s): array => ['ulid' => $s->ulid,
                    'when' => app(Showings::class)->when($s), 'name' => $s->name, 'brokerage' => $s->brokerage, 'status' => $s->status, 'feedback' => $s->feedback,
                    'upcoming' => $s->starts_at->isFuture()])->all(),
                'portal' => $prep->deal_id !== null],
        ]);
    }

    public function decide(Request $request, Prep $prep, string $showing, Showings $showings): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Preps::canSee($user, $prep), 403);
        $s = Showing::query()->where('listing_id', $prep->id)->where('ulid', $showing)->firstOrFail();
        $request->boolean('approve') ? $showings->approve($s, $user) : $showings->decline($s, $user, $request->input('reason') !== null ? (string) $request->input('reason') : null);

        return back();
    }

    public function mls(Request $request, Prep $prep): RedirectResponse
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);
        $prep->in_mls = $request->boolean('on');
        $prep->save();

        return back();
    }

    public function addCode(Request $request, Prep $prep, Marketing $marketing): RedirectResponse
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);
        $marketing->addCode($prep, (string) $request->input('kind'), $request->input('label') !== null ? (string) $request->input('label') : null);

        return back();
    }

    public function qr(Request $request, Prep $prep, string $code, Marketing $marketing): HttpResponse
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);
        $row = collect($marketing->codes($prep))->firstWhere('code', $code);
        abort_if($row === null || $row['url'] === null, 404);

        return response($marketing->qrPng((string) $row['url']), 200, ['Content-Type' => 'image/png', 'Content-Disposition' => 'attachment; filename="qr-'.$code.'.png"']);
    }

    public function floorPlan(Request $request, Prep $prep, Marketing $marketing): RedirectResponse
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);
        $request->validate(['floor_plan' => ['required', 'file', 'max:20480']]);
        $marketing->floorPlan($prep, $request->file('floor_plan'));

        return back();
    }

    public function flyer(Request $request, Prep $prep, Marketing $marketing): HttpResponse
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);

        return response($marketing->flyer($prep), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="flyer-'.str($prep->street)->slug().'.pdf"']);
    }

    public function openHouse(Request $request, Prep $prep, Marketing $marketing): RedirectResponse
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);
        $d = $request->validate(['date' => ['required', 'date'], 'from' => ['required', 'date_format:H:i'], 'to' => ['required', 'date_format:H:i']]);
        $marketing->openHouse($prep, $d['date'].' '.$d['from'], $d['date'].' '.$d['to']);

        return back();
    }

    public function kiosk(Request $request, Prep $prep, string $openHouse): Response
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);
        $oh = OpenHouse::query()->where('listing_id', $prep->id)->where('ulid', $openHouse)->firstOrFail();

        return Inertia::render('listiq/kiosk', ['listing' => ['ulid' => $prep->ulid, 'street' => $prep->street], 'openHouse' => ['ulid' => $oh->ulid, 'ended' => $oh->ended_at !== null,
            'visitors' => count((array) $oh->visitors)], 'consent' => Consent::DEFAULT_SMS_WORDING]);
    }

    public function signIn(Request $request, Prep $prep, string $openHouse, Marketing $marketing): RedirectResponse
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);
        $oh = OpenHouse::query()->where('listing_id', $prep->id)->where('ulid', $openHouse)->firstOrFail();
        $marketing->signIn($oh, $prep, $request->only(['name', 'email', 'phone', 'agent', 'sms_consent']));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Thank you for visiting!']);

        return back();
    }

    public function endOpenHouse(Request $request, Prep $prep, string $openHouse, Marketing $marketing): RedirectResponse
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);
        $marketing->end(OpenHouse::query()->where('listing_id', $prep->id)->where('ulid', $openHouse)->firstOrFail(), $prep);

        return to_route('listiq.show', $prep);
    }

    public function update(Request $request, Prep $prep, Preps $preps): RedirectResponse
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);
        $preps->update($prep, $request->only(['facts', 'notes', 'showing', 'manual', 'remarks_limit', 'description', 'description_es', 'showing_mode', 'access', 'seller_note']));

        return back();
    }

    public function upload(Request $request, Prep $prep, Photos $photos): RedirectResponse
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);
        $request->validate(['room' => ['required', 'string'], 'photos' => ['required', 'array', 'max:'.Photos::MAX_UPLOAD], 'photos.*' => ['file', 'image', 'max:25600']]);
        $n = $photos->upload($prep, (string) $request->input('room'), array_values((array) $request->file('photos')));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$n} photo".($n === 1 ? '' : 's').' added.']);

        return back();
    }

    public function photo(Request $request, Prep $prep, string $photo, Photos $photos): RedirectResponse
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);
        $p = Photo::query()->where('listing_id', $prep->id)->where('ulid', $photo)->firstOrFail();
        $photos->edit($p, $request->has('caption') ? (string) $request->input('caption') : null, $request->has('use') ? $request->boolean('use') : null);

        return back();
    }

    public function reorder(Request $request, Prep $prep, Photos $photos): RedirectResponse
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);
        $photos->reorder($prep, array_values(array_map('strval', (array) $request->input('order', []))));

        return back();
    }

    public function enhance(Request $request, Prep $prep, string $photo, Photos $photos): RedirectResponse
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);
        $photos->enhance(Photo::query()->where('listing_id', $prep->id)->where('ulid', $photo)->firstOrFail());

        return back();
    }

    public function stage(Request $request, Prep $prep, string $photo, Photos $photos): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Preps::canSee($user, $prep), 403);
        $photos->stage(Photo::query()->where('listing_id', $prep->id)->where('ulid', $photo)->firstOrFail(), (string) $request->input('style', 'modern'), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Staging started. It takes about a minute.']);

        return back();
    }

    public function write(Request $request, Prep $prep, Descriptions $descriptions): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Preps::canSee($user, $prep), 403);
        $descriptions->write($prep, $user);

        return back();
    }

    public function translate(Request $request, Prep $prep, Descriptions $descriptions): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Preps::canSee($user, $prep), 403);
        $descriptions->translate($prep, $user);

        return back();
    }

    public function package(Request $request, Prep $prep, Preps $preps): BinaryFileResponse
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);

        return response()->download($preps->package($prep), 'listing-'.str($prep->street)->slug().'.zip')->deleteFileAfterSend();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->isKind(UserKind::Agent) || $user->isKind(UserKind::Owner)), 403);

        return $user;
    }
}
