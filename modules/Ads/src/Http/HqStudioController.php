<?php

declare(strict_types=1);

namespace Modules\Ads\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ads\Studio\StudioPlans;

/**
 * HQ → Ally Studio (specs/grow/13): each package's allowances and features, its price and what the full allowance
 * costs RightAlly, and each extra item's credits, price, cost and margin. Prices are set under Connect setup (paid
 * apps), credits on the rate card and costs in the cost list.
 */
final class HqStudioController
{
    public function index(StudioPlans $plans): Response
    {
        return Inertia::render('hq/studio', $plans->economics() + ['features' => StudioPlans::FEATURES]);
    }

    public function save(Request $request, string $package, StudioPlans $plans): RedirectResponse
    {
        $data = $request->validate(['posts' => ['required', 'integer', 'min:0', 'max:10000'], 'reels' => ['required', 'integer', 'min:0', 'max:10000'],
            'tours' => ['required', 'integer', 'min:0', 'max:10000'], 'enhance' => ['required', 'integer', 'min:0', 'max:10000'], 'voice' => ['required', 'integer', 'min:0', 'max:10000'],
            'features' => ['array'], 'features.*' => ['string']]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $plans->savePackage($package, $data, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. It applies from each customer\'s next use.']);

        return back();
    }
}
