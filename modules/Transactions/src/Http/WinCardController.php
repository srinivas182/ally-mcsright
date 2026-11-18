<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Models\Site;
use Modules\Sites\Rendering\Chrome;
use Modules\Sites\Rendering\Seo;
use Modules\Transactions\DealAccess;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\WinCards;

/** RF1 (specs/transactions/32): make or hide a deal's win card, and its public page on the brokerage website. */
final class WinCardController
{
    public function make(Request $request, Deal $deal, WinCards $cards): RedirectResponse
    {
        $user = self::user($request, $deal);
        $data = $request->validate(['client_agreed' => ['accepted'], 'message' => ['nullable', 'string', 'max:400'], 'show_price' => ['boolean']],
            ['client_agreed.accepted' => 'Tick that your client agreed to sharing this.']);
        $cards->make($deal, $user, true, $data['message'] ?? null, (bool) ($data['show_price'] ?? false));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your win card is live. Share it below.']);

        return back();
    }

    public function hide(Request $request, Deal $deal, WinCards $cards): RedirectResponse
    {
        $cards->hide($deal, self::user($request, $deal));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Win card hidden.']);

        return back();
    }

    public function show(string $card, Request $request, TenantContext $tenants): Response
    {
        $site = Site::query()->where('kind', 'brokerage')->where('status', 'live')->first();
        $row = DB::connection(Connections::cell())->table('tx_win_cards')->where('tenant_id', $tenants->id())->where('ulid', $card)->where('status', 'live')->first();
        $deal = $row !== null ? Deal::query()->find((int) $row->deal_id) : null;
        if ($site === null || $row === null || $deal === null) {
            return response()->view('sites::missing', ['site' => $site], 404);
        }
        DB::connection(Connections::cell())->table('tx_win_cards')->where('id', $row->id)->increment('views');
        $agent = User::query()->find((int) $row->agent_user_id);
        $root = rtrim($request->getSchemeAndHttpHost(), '/');
        $path = '/wins/'.$row->ulid;

        return response()->view('transactions::win', [
            'site' => $site, 'current' => $path, 'card' => $row, 'deal' => $deal, 'agent' => $agent, 'url' => $root.$path,
            'seo' => Seo::build($site, $root, $path, $row->headline.' | '.$site->displayName(), mb_substr((string) $row->message, 0, 160), null, true, [[$row->headline, $path]], [],
                [['@type' => 'Article', 'headline' => $row->headline, 'datePublished' => substr((string) $row->created_at, 0, 10), 'author' => ['@type' => 'Person', 'name' => $agent->name ?? $site->displayName()],
                    'publisher' => ['@type' => 'RealEstateAgent', 'name' => $site->displayName()]]], (string) $row->updated_at, 'article'),
            'chrome' => Chrome::for($site, $tenants->id()),
        ])->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    }

    private static function user(Request $request, Deal $deal): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && DealAccess::canSee($user, $deal) && ($user->id === $deal->agent_user_id || DealAccess::reviewer($user)), 403);

        return $user;
    }
}
