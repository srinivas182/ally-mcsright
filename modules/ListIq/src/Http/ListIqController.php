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
use Modules\ListIq\Descriptions;
use Modules\ListIq\Models\Photo;
use Modules\ListIq\Models\Prep;
use Modules\ListIq\Photos;
use Modules\ListIq\Preps;
use Modules\ListIq\Shoot;
use Modules\ListIq\Staging\ReplicateStager;
use Modules\ListIq\Staging\VirtualStager;
use Modules\Transactions\Models\Deal;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        ]);
    }

    public function update(Request $request, Prep $prep, Preps $preps): RedirectResponse
    {
        abort_unless(Preps::canSee(self::user($request), $prep), 403);
        $preps->update($prep, $request->only(['facts', 'notes', 'showing', 'manual', 'remarks_limit', 'description', 'description_es']));

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
