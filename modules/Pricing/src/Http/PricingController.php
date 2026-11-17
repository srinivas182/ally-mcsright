<?php

declare(strict_types=1);

namespace Modules\Pricing\Http;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Contact;
use Modules\Listings\Media\Photos;
use Modules\Pricing\Cmas;
use Modules\Pricing\Comps;
use Modules\Pricing\Defaults;
use Modules\Pricing\Models\Cma;
use Modules\Pricing\Models\Sheet;
use Modules\Pricing\Sheets;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Pricing for agents (CM1, specs/listings/01): CMAs, the seller net sheet and buyer cost estimates. */
final class PricingController
{
    public function index(Request $request, Defaults $defaults, Comps $comps): Response
    {
        $user = self::user($request);
        $mine = fn ($q) => $user->isKind(UserKind::Owner) ? $q : $q->where('owner_user_id', $user->id);
        $contact = $request->filled('contact') ? Contact::query()->where('ulid', (string) $request->query('contact'))->first() : null;

        return Inertia::render('pricing/index', [
            'cmas' => $mine(Cma::query())->orderByDesc('updated_at')->limit(50)->get()->map(fn (Cma $c): array => ['ulid' => $c->ulid, 'title' => $c->title,
                'likely_cents' => (int) ($c->result['likely_cents'] ?? 0), 'views' => $c->views, 'shared' => $c->share_hash !== null, 'updated' => $c->updated_at->toDateString()])->all(),
            'sheets' => $mine(Sheet::query())->orderByDesc('updated_at')->limit(50)->get()->map(fn (Sheet $s): array => ['ulid' => $s->ulid, 'title' => $s->title,
                'monthly_cents' => (int) ($s->result['monthly_total_cents'] ?? 0), 'updated' => $s->updated_at->toDateString()])->all(),
            'sold' => $comps->sources(app(TenantContext::class)->id())['sold'],
            'prefill' => $contact !== null ? ['contact' => $contact->ulid, 'name' => $contact->name, 'street' => (string) $contact->address_line1, 'city' => (string) $contact->city,
                'state' => (string) $contact->state, 'zip' => (string) $contact->zip] : ($request->filled('listing') ? ['listing' => (string) $request->query('listing')] : null),
            'defaults' => $defaults->values(), 'broker' => $user->isKind(UserKind::Owner),
        ]);
    }

    public function store(Request $request, Cmas $cmas): RedirectResponse
    {
        $cma = $cmas->create(self::user($request), $request->only(['listing', 'street', 'city', 'state', 'zip', 'beds', 'baths_full', 'baths_half', 'sqft', 'year_built', 'lot_sqft', 'pool', 'garage', 'contact', 'lang']));

        return to_route('pricing.cma', $cma);
    }

    public function show(Request $request, Cma $cma): Response
    {
        $user = self::user($request);
        abort_unless(Cmas::canSee($user, $cma), 403);
        $r = (array) $cma->result;
        $r['comps'] = array_map(fn (array $c): array => [...$c, 'photo' => Photos::url($c['photo'] ?? null)], (array) ($r['comps'] ?? []));

        return Inertia::render('pricing/cma', [
            'cma' => ['ulid' => $cma->ulid, 'title' => $cma->title, 'subject' => $cma->subject, 'lang' => $cma->lang, 'summary' => $cma->summary, 'net' => $cma->net,
                'shared_at' => $cma->shared_at?->toDateString(), 'share_expires' => $cma->share_expires_at?->toDateString(), 'views' => $cma->views,
                'last_viewed' => $cma->last_viewed_at?->toDateTimeString(), 'contact' => $cma->contact_id !== null ? Contact::query()->find($cma->contact_id)?->only(['ulid', 'first_name', 'last_name']) : null],
            'result' => $r,
            'shareUrl' => session('share_url'),
        ]);
    }

    public function update(Request $request, Cma $cma, Cmas $cmas): RedirectResponse
    {
        abort_unless(Cmas::canSee(self::user($request), $cma), 403);
        $cmas->update($cma, $request->only(['on', 'overrides', 'subject', 'net', 'lang', 'title']));

        return back();
    }

    public function search(Request $request, Cma $cma, Cmas $cmas): JsonResponse
    {
        abort_unless(Cmas::canSee(self::user($request), $cma), 403);

        return response()->json(['homes' => $cmas->search($cma, (string) $request->query('q', ''))]);
    }

    public function addComp(Request $request, Cma $cma, Cmas $cmas): RedirectResponse
    {
        abort_unless(Cmas::canSee(self::user($request), $cma), 403);
        $cmas->addComp($cma, (string) $request->validate(['listing' => ['required', 'string', 'size:26']])['listing']);

        return back();
    }

    public function summary(Request $request, Cma $cma, Cmas $cmas): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Cmas::canSee($user, $cma), 403);
        $cmas->writeSummary($cma, $user);

        return back();
    }

    public function share(Request $request, Cma $cma, Cmas $cmas): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Cmas::canSee($user, $cma), 403);

        return back()->with('share_url', $cmas->share($cma, $user));
    }

    public function pdf(Request $request, Cma $cma): HttpResponse
    {
        abort_unless(Cmas::canSee(self::user($request), $cma), 403);

        return Pdf::loadView('pricing::cma', ShareController::cmaView($cma, true))->download('cma-'.str($cma->title)->slug().'.pdf');
    }

    public function buyer(Request $request, Defaults $defaults): Response
    {
        $user = self::user($request);
        $sheet = $request->filled('sheet') ? Sheet::query()->where('ulid', (string) $request->query('sheet'))->first() : null;
        abort_if($sheet !== null && $sheet->owner_user_id !== $user->id && ! $user->isKind(UserKind::Owner), 403);
        $contact = $request->filled('contact') ? Contact::query()->where('ulid', (string) $request->query('contact'))->first() : null;

        return Inertia::render('pricing/buyer', [
            'defaults' => $defaults->values(), 'loans' => array_keys((array) config('pricing.loans')),
            'sheet' => $sheet !== null ? ['ulid' => $sheet->ulid, 'title' => $sheet->title, 'inputs' => $sheet->inputs, 'result' => $sheet->result, 'views' => $sheet->views] : null,
            'contact' => $contact?->only(['ulid', 'first_name', 'last_name']), 'shareUrl' => session('share_url'),
        ]);
    }

    public function calculate(Request $request, Sheets $sheets): JsonResponse
    {
        self::user($request);

        return response()->json($sheets->work($request->all()));
    }

    public function saveBuyer(Request $request, Sheets $sheets): RedirectResponse
    {
        $user = self::user($request);
        $existing = $request->filled('sheet') ? Sheet::query()->where('ulid', (string) $request->input('sheet'))->where('owner_user_id', $user->id)->first() : null;
        $sheet = $sheets->save($user, $request->all(), $existing);

        return redirect('/pricing/buyer?sheet='.$sheet->ulid);
    }

    public function shareBuyer(Request $request, Sheet $sheet, Sheets $sheets): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($sheet->owner_user_id === $user->id || $user->isKind(UserKind::Owner), 403);

        return redirect('/pricing/buyer?sheet='.$sheet->ulid)->with('share_url', $sheets->share($sheet));
    }

    public function buyerPdf(Request $request, Sheet $sheet): HttpResponse
    {
        $user = self::user($request);
        abort_unless($sheet->owner_user_id === $user->id || $user->isKind(UserKind::Owner), 403);

        return Pdf::loadView('pricing::estimate', ShareController::sheetView($sheet, true))->download('buyer-estimate.pdf');
    }

    public function saveDefaults(Request $request, Defaults $defaults): RedirectResponse
    {
        abort_unless(self::user($request)->isKind(UserKind::Owner), 403);
        $defaults->save($request->all());

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->isKind(UserKind::Agent) || $user->isKind(UserKind::Owner)), 403);

        return $user;
    }
}
