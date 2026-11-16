<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Transactions\DealAccess;
use Modules\Transactions\DealIq\Adapt;
use Modules\Transactions\DealIq\DealAssistant;
use Modules\Transactions\DealIq\Intake;
use Modules\Transactions\DealIq\UploadRequests;
use Modules\Transactions\Deals;
use Modules\Transactions\Documents;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;
use Modules\Transactions\Timeline\Timeline;

/** Deal-IQ: start a deal any way, set it up, talk to it, and ask the other side for documents (specs/ai/02, DQ1–DQ2). */
final class DealIqController
{
    public function intakeFile(Request $request, Intake $intake): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['file' => ['required', 'file', 'max:25600'], 'agent' => ['nullable', 'string', 'size:26']]);
        $deal = $intake->fromFile($data['file'], self::agentFor($user, $data['agent'] ?? null), $user);

        return redirect('/deals/'.$deal->ulid.'/setup');
    }

    public function intakeText(Request $request, Intake $intake): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['text' => ['required', 'string', 'max:1000'], 'agent' => ['nullable', 'string', 'size:26']]);
        $deal = $intake->fromText($data['text'], self::agentFor($user, $data['agent'] ?? null), $user);

        return redirect('/deals/'.$deal->ulid.'/setup');
    }

    public function setup(Request $request, Deal $deal, Intake $intake): Response
    {
        self::authorise($request, $deal);

        return Inertia::render('deals/setup', ['deal' => ['ulid' => $deal->ulid], 'proposal' => $intake->proposal($deal), 'types' => Deals::TYPES,
            'propertyTypes' => Adapt::PROPERTY_TYPES, 'financing' => Adapt::FINANCING]);
    }

    public function confirm(Request $request, Deal $deal, Intake $intake): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $data = $request->validate(['type' => ['required', Rule::in(Deals::TYPES)], 'street' => ['required', 'string', 'max:160'], 'city' => ['nullable', 'string', 'max:80'],
            'state_code' => ['required', 'string', 'size:2'], 'postal_code' => ['nullable', 'string', 'max:10'], 'price' => ['nullable', 'numeric', 'min:0'],
            'contract_date' => ['nullable', 'date_format:Y-m-d'], 'closing_date' => ['nullable', 'date_format:Y-m-d'], 'property_type' => ['nullable', 'string'], 'financing' => ['nullable', 'string'],
            'parties' => ['nullable', 'array', 'max:12'], 'parties.*.role' => ['required', 'string'], 'parties.*.name' => ['required', 'string', 'max:160'], 'parties.*.email' => ['nullable', 'email']]);
        $done = $intake->confirm($deal, $data, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Deal set up: '.count($done['checklist']).' checklist items added for this home and loan, '.$done['dates'].' dates on the timeline.']);

        return redirect('/deals/'.$deal->ulid);
    }

    public function property(Request $request, Deal $deal, Adapt $adapt): RedirectResponse
    {
        self::authorise($request, $deal);
        $data = $request->validate(['property_type' => ['nullable', Rule::in(array_keys(Adapt::PROPERTY_TYPES))], 'financing' => ['nullable', Rule::in(array_keys(Adapt::FINANCING))]]);
        $deal->update($data);
        $added = $adapt->apply($deal);
        Inertia::flash('toast', ['type' => 'success', 'message' => $added === [] ? 'Saved.' : 'Saved. Added to the checklist: '.implode(', ', $added).'.']);

        return back();
    }

    public function timeline(Request $request, Deal $deal, Timeline $timeline): RedirectResponse
    {
        self::authorise($request, $deal);
        $changes = $timeline->rebuild($deal);
        Inertia::flash('toast', ['type' => 'success', 'message' => $changes === [] ? 'The timeline is up to date.' : count($changes).' dates updated.']);

        return back();
    }

    public function ask(Request $request, Deal $deal, DealAssistant $assistant): JsonResponse
    {
        $user = self::authorise($request, $deal);
        $data = $request->validate(['text' => ['required', 'string', 'max:1000']]);

        return response()->json($assistant->propose($deal, $data['text'], $user));
    }

    public function doIt(Request $request, Deal $deal, string $turn, DealAssistant $assistant): JsonResponse
    {
        $user = self::authorise($request, $deal);
        $data = $request->validate(['chosen' => ['present', 'array'], 'chosen.*' => ['integer', 'min:0', 'max:7']]);

        return response()->json(['results' => $assistant->confirm($deal, $turn, array_values(array_map('intval', $data['chosen'])), $user)]);
    }

    public function dismiss(Request $request, Deal $deal, string $turn, DealAssistant $assistant): JsonResponse
    {
        $assistant->dismiss($deal, $turn, self::authorise($request, $deal));

        return response()->json(['ok' => true]);
    }

    public function requestUpload(Request $request, Deal $deal, UploadRequests $requests): RedirectResponse
    {
        $user = self::authorise($request, $deal);
        $data = $request->validate(['what' => ['required', 'string', 'max:160'], 'role' => ['required', Rule::in(DealAssistant::ROLES)], 'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email'], 'note' => ['nullable', 'string', 'max:500'], 'item' => ['nullable', 'string', 'size:26']]);
        $item = isset($data['item']) ? Item::query()->where('ulid', $data['item'])->where('deal_id', $deal->id)->first() : null;
        $requests->send($deal, $item, $data['what'], $data['role'], $data['name'], $data['email'], $data['note'] ?? null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Upload link sent to '.$data['name'].'.']);

        return back();
    }

    /** The public upload page for the other side: no account, one document, one use. */
    public function uploadPage(Request $request, string $token, UploadRequests $requests): Response
    {
        $row = $requests->find($token);
        $deal = $row !== null ? Deal::query()->whereKey((int) $row->deal_id)->first() : null;

        return Inertia::render('public/deal-upload', ['valid' => $row !== null, 'what' => $row?->what, 'name' => $row?->name, 'street' => $deal?->street,
            'agent' => $row !== null ? User::query()->whereKey((int) $row->requested_by)->first()?->name : null, 'mimes' => Documents::MIMES,
            'uploaded' => $request->boolean('done')]);
    }

    public function uploadReceive(Request $request, string $token, UploadRequests $requests): RedirectResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:25600']]);
        $requests->receive($token, $data['file']);

        return redirect('/deal-upload/'.$token.'?done=1');
    }

    private static function agentFor(User $user, ?string $agentUlid): User
    {
        return $agentUlid !== null && DealAccess::reviewer($user) ? User::query()->where('ulid', $agentUlid)->firstOrFail() : User::query()->findOrFail(DealAccess::actingFor($user));
    }

    private static function authorise(Request $request, Deal $deal): User
    {
        $user = self::user($request);
        abort_unless(DealAccess::canSee($user, $deal), 403);

        return $user;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
