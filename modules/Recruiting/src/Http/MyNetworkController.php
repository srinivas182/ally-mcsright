<?php

declare(strict_types=1);

namespace Modules\Recruiting\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Recruiting\MyNetwork;
use Modules\Recruiting\SelfJoin;

/** AG1: My commissions › My network, and the join link's short address on the brokerage's website. */
final class MyNetworkController
{
    public function show(Request $request, MyNetwork $network): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Agent, UserKind::Owner], true), 403);

        $data = $network->for($user);
        unset($data['tree']); // the tree has its own page (AG1b)

        return Inertia::render('recruiting/my-network', ['link' => $network->shareUrl($user, $request->getSchemeAndHttpHost())] + $data + $network->money($user));
    }

    /** AG1b: My commissions › Network tree: who the agent brought in, level by level. */
    public function tree(Request $request, MyNetwork $network): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Agent, UserKind::Owner], true), 403);
        $data = $network->for($user);

        return Inertia::render('recruiting/network-tree', ['tree' => $data['tree'], 'direct' => $data['direct'], 'network' => $data['network']]);
    }

    /** /join/{code} on the website: on to the join page in the back office (same brokerage), keeping the agent's code. */
    public function fromWebsite(SelfJoin $join, ?string $code = null): RedirectResponse
    {
        $host = app(TenantContext::class)->current()->primaryDomain()?->host;
        $known = $code !== null && $join->sponsorFor($code) !== null; // AG8: no agent: they join with the brokerage itself

        return redirect()->away(($host !== null ? 'https://'.$host : '').'/join'.($known ? '/'.$code : ''), 302)
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }
}
