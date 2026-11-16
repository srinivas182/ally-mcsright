<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Offers\Compare;
use Modules\Transactions\Offers\OfferReader;
use Modules\Transactions\Offers\Offers;
use Symfony\Component\HttpFoundation\Response;

/**
 * Buyers' agents send and revise offers here (specs/ai/02, §16): no account, the listing's link is the key, and a
 * private link (sent by email) revises their own offer while offers are being taken. Never indexed.
 */
final class PublicOfferController
{
    public function show(Request $request, string $token, Offers $offers): Response
    {
        $deal = $offers->dealForLink($token);

        return $this->page($request, $deal, null, $request->boolean('sent'));
    }

    public function store(Request $request, string $token, Offers $offers, OfferReader $reader): RedirectResponse
    {
        $deal = $offers->dealForLink($token) ?? abort(404);
        $request->validate(['file' => ['nullable', 'file', 'max:25600'], 'agent_name' => ['required', 'string'], 'agent_email' => ['required', 'email'], 'price' => ['required', 'numeric', 'min:1']]);
        $result = $offers->submit($deal, OffersController::terms($request), $request->file('file'), 'link');
        if ($result['offer']->file_path !== null) {
            $reader->read($result['offer'], null);
        }

        return redirect('/offer/'.$token.'?sent=1');
    }

    public function revisePage(Request $request, string $token, Offers $offers): Response
    {
        $offer = $offers->forUpdate($token);
        $deal = $offer !== null ? Deal::query()->whereKey((int) $offer->deal_id)->first() : null;

        return $this->page($request, $deal, $offer, $request->boolean('sent'));
    }

    public function revise(Request $request, string $token, Offers $offers): RedirectResponse
    {
        $offer = $offers->forUpdate($token) ?? abort(404);
        $request->validate(['file' => ['nullable', 'file', 'max:25600'], 'price' => ['required', 'numeric', 'min:1']]);
        $offers->revise($offer, OffersController::terms($request), $request->file('file'));

        return redirect('/offer/revise/'.$token.'?sent=1');
    }

    private function page(Request $request, ?Deal $deal, ?\stdClass $offer, bool $sent): Response
    {
        $response = Inertia::render('public/offer', [
            'valid' => $deal !== null,
            'sent' => $sent,
            'listing' => $deal !== null ? ['street' => $deal->street, 'city' => $deal->city, 'state' => $deal->state_code, 'brokerage' => app(TenantContext::class)->current()->name] : null,
            'deadline' => $deal !== null ? app(Offers::class)->openDeadline($deal->id)?->toIso8601String() : null,
            'offer' => $offer !== null ? array_intersect_key((array) $offer, array_flip([...Offers::TERMS, 'label'])) + ['contingencies' => (array) json_decode((string) ($offer->contingencies ?? '[]'), true)] : null,
            'options' => ['financing' => Compare::FINANCING, 'proof' => Compare::PROOF, 'contingencies' => Compare::CONTINGENCIES],
        ])->toResponse($request);
        $response->headers->add(['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store']);

        return $response;
    }
}
