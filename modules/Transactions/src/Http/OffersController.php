<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Core\Access\Fields;
use Modules\Core\Support\Connections;
use Modules\Transactions\DealAccess;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Offers\Compare;
use Modules\Transactions\Offers\OfferReader;
use Modules\Transactions\Offers\Offers;
use Modules\Transactions\Offers\OfferSummary;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Offers on a listing, the listing agent's side (specs/ai/02, §16, DQ4). */
final class OffersController
{
    public function index(Request $request, Deal $deal, Offers $offers): InertiaResponse
    {
        $user = self::authorise($request, $deal);
        abort_unless(Offers::forListing($deal), 404);
        $all = Offers::all($deal);

        return Inertia::render('deals/offers', [
            'deal' => ['ulid' => $deal->ulid, 'street' => $deal->street, 'list_price_cents' => $deal->price_cents, 'locked' => $deal->locked_at !== null, 'status' => $deal->status],
            'offers' => Compare::table($deal, $all),
            'sheet' => Compare::sheet($deal),
            'canSeeCommission' => Fields::visible($user, 'commission', $deal->agent_user_id),
            'hasLink' => DB::connection(Connections::cell())->table('tx_offer_links')->where('deal_id', $deal->id)->whereNull('revoked_at')->where('expires_at', '>', now())->exists(),
            'link' => $request->session()->get('offerLink'),
            'notices' => Offers::notices($deal),
            'deadline' => $offers->openDeadline($deal->id)?->toIso8601String(),
            'summary' => OfferSummary::latest($deal),
            'packet' => $request->session()->get('packet'),
            'options' => ['financing' => Compare::FINANCING, 'proof' => Compare::PROOF, 'contingencies' => Compare::CONTINGENCIES],
        ]);
    }

    public function link(Request $request, Deal $deal, Offers $offers): RedirectResponse
    {
        $token = $offers->link($deal, self::authorise($request, $deal));

        return back()->with('offerLink', url('/offer/'.$token));
    }

    public function upload(Request $request, Deal $deal, Offers $offers, OfferReader $reader): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $data = $request->validate(['file' => ['required', 'file', 'max:25600']]);
        $result = $offers->submit($deal, [], $data['file'], 'upload', $user);
        $read = $reader->read($result['offer'], $user->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Offer '.$result['offer']->label.' added. '.($read > 0 ? "Deal-IQ read {$read} term(s): check them." : 'Fill in the terms.')]);

        return back();
    }

    public function store(Request $request, Deal $deal, Offers $offers): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $offers->submit($deal, self::terms($request), null, 'manual', $user);

        return back();
    }

    public function update(Request $request, Deal $deal, string $offer, Offers $offers): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $row = $offers->find($deal, $offer);
        $terms = self::terms($request);
        $offers->revise($row, $terms, null, $user);
        if (isset($terms['agent_email'])) { // the listing agent may correct the address
            DB::connection(Connections::cell())->table('tx_offers')->where('id', $row->id)->update(['agent_email' => Offers::clean(['agent_email' => $terms['agent_email']])['agent_email']]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Offer '.$row->label.' saved.']);

        return back();
    }

    public function status(Request $request, Deal $deal, string $offer, Offers $offers): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $offers->status($deal, $offer, (string) $request->validate(['status' => ['required', 'string']])['status'], $user);

        return back();
    }

    public function notify(Request $request, Deal $deal, Offers $offers): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $data = $request->validate(['kind' => ['required', 'in:multiple,highest_best'], 'message' => ['required', 'string', 'max:3000'], 'deadline' => ['nullable', 'date']]);
        $n = $offers->notify($deal, $data['kind'], $data['message'], isset($data['deadline']) ? CarbonImmutable::parse($data['deadline']) : null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Sent to {$n} buyer's agent(s) at the same moment."]);

        return back();
    }

    public function counter(Request $request, Deal $deal, string $offer, Offers $offers): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $data = $request->validate(['price' => ['nullable', 'numeric', 'min:0'], 'closing_date' => ['nullable', 'date_format:Y-m-d'], 'concessions' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500']]);
        $packet = $offers->counter($deal, $offer, ['price_cents' => isset($data['price']) ? (int) round((float) $data['price'] * 100) : null, 'closing_date' => $data['closing_date'] ?? null,
            'concessions_cents' => isset($data['concessions']) ? (int) round((float) $data['concessions'] * 100) : null, 'note' => $data['note'] ?? null], $user);
        Inertia::flash('toast', ['type' => $packet !== null ? 'success' : 'info', 'message' => $packet !== null ? 'Counter-offer drafted on your form. Check it, then send it for signature.'
            : 'Marked as countered. None of your forms fits a counter-offer; draft it under Forms.']);

        return back()->with('packet', $packet);
    }

    public function accept(Request $request, Deal $deal, string $offer, Offers $offers): RedirectResponse
    {
        $offers->accept($deal, $offer, self::authorise($request, $deal));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Accepted. The deal has the offer\'s terms and people, and every other buyer\'s agent was told. Mark it under contract once it is signed.']);

        return redirect('/deals/'.$deal->ulid);
    }

    public function sheet(Request $request, Deal $deal, Offers $offers): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        abort_unless(Fields::visible($user, 'commission', $deal->agent_user_id), 403);
        $d = $request->validate(['listing_percent' => ['required', 'numeric', 'between:0,10'], 'title_percent' => ['required', 'numeric', 'between:0,5'],
            'payoff' => ['nullable', 'numeric', 'min:0'], 'other' => ['nullable', 'numeric', 'min:0']]);
        $offers->saveSheet($deal, ['listing_percent' => (float) $d['listing_percent'], 'title_percent' => (float) $d['title_percent'],
            'payoff_cents' => (int) round((float) ($d['payoff'] ?? 0) * 100), 'other_cents' => (int) round((float) ($d['other'] ?? 0) * 100)], $user);

        return back();
    }

    public function summary(Request $request, Deal $deal, OfferSummary $summary): RedirectResponse
    {
        $summary->write($deal, self::authorise($request, $deal));

        return back();
    }

    public function saveSummary(Request $request, Deal $deal, OfferSummary $summary): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $d = $request->validate(['en' => ['required', 'string', 'max:3000'], 'es' => ['nullable', 'string', 'max:3000'], 'share' => ['boolean']]);
        $summary->save($deal, $d['en'], $d['es'] ?? null, (bool) ($d['share'] ?? false), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => ($d['share'] ?? false) ? 'The sellers can see the comparison in their deal portal.' : 'Saved.']);

        return back();
    }

    public function file(Request $request, Deal $deal, string $offer, Offers $offers): StreamedResponse|Response
    {
        self::authorise($request, $deal);
        $row = $offers->find($deal, $offer);
        $path = $offers->file($row) ?? abort(404);

        return Storage::disk('local')->download($path, (string) ($row->file_name ?? 'offer.pdf'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function terms(Request $request): array
    {
        $d = $request->validate(['agent_name' => ['nullable', 'string', 'max:160'], 'agent_email' => ['nullable', 'email', 'max:254'], 'agent_phone' => ['nullable', 'string', 'max:20'],
            'brokerage' => ['nullable', 'string', 'max:160'], 'buyer_names' => ['nullable', 'string', 'max:300'], 'price' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'financing' => ['nullable', 'string'], 'down_percent' => ['nullable', 'numeric', 'between:0,100'], 'earnest' => ['nullable', 'numeric', 'min:0'], 'option_fee' => ['nullable', 'numeric', 'min:0'],
            'option_days' => ['nullable', 'integer', 'between:0,60'], 'concessions' => ['nullable', 'numeric', 'min:0'], 'buyer_agent' => ['nullable', 'numeric', 'min:0'],
            'closing_date' => ['nullable', 'date_format:Y-m-d'], 'contingencies' => ['nullable', 'array'], 'contingencies.*' => ['string'], 'appraisal_gap' => ['nullable', 'numeric', 'min:0'],
            'escalation_cap' => ['nullable', 'numeric', 'min:0'], 'escalation_step' => ['nullable', 'numeric', 'min:0'], 'proof' => ['nullable', 'string'],
            'leaseback_days' => ['nullable', 'integer', 'between:0,90'], 'expires_at' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $cents = fn (string $k): ?int => isset($d[$k]) && $d[$k] !== '' ? (int) round((float) $d[$k] * 100) : null;
        $terms = ['agent_name' => $d['agent_name'] ?? null, 'agent_email' => $d['agent_email'] ?? null, 'agent_phone' => $d['agent_phone'] ?? null, 'brokerage' => $d['brokerage'] ?? null,
            'buyer_names' => $d['buyer_names'] ?? null, 'price_cents' => $cents('price'), 'financing' => $d['financing'] ?? null, 'down_percent' => $d['down_percent'] ?? null,
            'earnest_cents' => $cents('earnest'), 'option_fee_cents' => $cents('option_fee'), 'option_days' => $d['option_days'] ?? null, 'concessions_cents' => $cents('concessions'),
            'buyer_agent_cents' => $cents('buyer_agent'), 'closing_date' => $d['closing_date'] ?? null, 'contingencies' => $d['contingencies'] ?? [], 'appraisal_gap_cents' => $cents('appraisal_gap'),
            'escalation_cap_cents' => $cents('escalation_cap'), 'escalation_step_cents' => $cents('escalation_step'), 'proof' => $d['proof'] ?? null,
            'leaseback_days' => $d['leaseback_days'] ?? null, 'expires_at' => isset($d['expires_at']) ? CarbonImmutable::parse($d['expires_at']) : null, 'notes' => $d['notes'] ?? null];
        if (($terms['escalation_cap_cents'] === null) !== ($terms['escalation_step_cents'] === null)) {
            throw ValidationException::withMessages(['escalation_cap' => 'An escalation clause needs both the cap and the step.']);
        }

        return $terms;
    }

    private static function authorise(Request $request, Deal $deal): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && DealAccess::canSee($user, $deal), 403);

        return $user;
    }
}
