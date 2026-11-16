<?php

declare(strict_types=1);

namespace Modules\Movewise\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Movewise\Models\Partner;
use Modules\Movewise\Models\State;
use Modules\Movewise\Movewise;

/** HQ → Movewise (specs/movewise/01): each state's switch, broker of record, legal checklist and MLS feeds. */
final class HqMovewiseController
{
    public function __construct(private readonly Movewise $movewise) {}

    public function index(): Response
    {
        $tenant = $this->movewise->feedTenant();
        $auths = $tenant !== null ? MlsAuthorization::query()->where('tenant_id', $tenant->id)->get()->keyBy('mls_source_id') : collect();

        return Inertia::render('hq/movewise', [
            'host' => (string) config('movewise.host'),
            'checklist' => Movewise::CHECKLIST,
            'partners' => Partner::query()->orderBy('licence_state')->orderBy('name')->get()->map(fn (Partner $p): array => ['ulid' => $p->ulid, 'name' => $p->name,
                'broker_name' => $p->broker_name, 'licence_number' => $p->licence_number, 'licence_state' => $p->licence_state, 'phone' => $p->phone, 'email' => $p->email,
                'address' => $p->address, 'iabs_url' => $p->iabs_url, 'contract_signed_on' => $p->contract_signed_on?->toDateString(), 'status' => $p->status])->values()->all(),
            'states' => State::query()->with('partner')->orderBy('name')->get()->map(fn (State $s): array => ['code' => $s->code, 'name' => $s->name, 'on' => $s->on,
                'partner' => $s->partner?->ulid, 'checklist' => $s->checklist ?? [], 'missing' => $this->movewise->missing($s),
                'feeds' => MlsSource::query()->get()->filter(fn (MlsSource $m): bool => in_array($s->code, $m->states, true))->map(fn (MlsSource $m): array => ['ulid' => $m->ulid,
                    'name' => $m->name, 'status' => $auths[$m->id]->status ?? null])->values()->all()])->values()->all(),
            'feed_account' => $tenant?->name,
        ]);
    }

    public function savePartner(Request $request, ?string $partner = null): RedirectResponse
    {
        $p = $partner !== null ? Partner::query()->where('ulid', $partner)->firstOrFail() : null;
        $this->movewise->savePartner($p, $request->all(), self::user($request));

        return back();
    }

    public function assign(Request $request, string $state): RedirectResponse
    {
        $p = $request->filled('partner') ? Partner::query()->where('ulid', (string) $request->input('partner'))->firstOrFail() : null;
        $this->movewise->assign(State::query()->findOrFail($state), $p, self::user($request));

        return back();
    }

    public function check(Request $request, string $state): RedirectResponse
    {
        $this->movewise->check(State::query()->findOrFail($state), (string) $request->input('item'), $request->boolean('done'), $request->input('note'), self::user($request));

        return back();
    }

    public function switch(Request $request, string $state): RedirectResponse
    {
        $s = State::query()->findOrFail($state);
        $this->movewise->switchState($s, $request->boolean('on'), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $s->name.($s->on ? ' is live on Movewise.' : ' is off.')]);

        return back();
    }

    public function requestFeed(Request $request, string $state): RedirectResponse
    {
        $source = MlsSource::query()->where('ulid', (string) $request->input('mls'))->firstOrFail();
        $this->movewise->requestFeed(State::query()->findOrFail($state), $source, (string) $request->input('broker_mls_id', ''), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Requested. Approve it under HQ → MLS once the licence is in place.']);

        return back();
    }

    private static function user(Request $request): User
    {
        $u = $request->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }
}
