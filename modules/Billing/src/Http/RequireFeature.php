<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Billing\Entitlements;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard: `feature:crm.ai`. Without the feature, agents are shown the plans that include it.
 */
final class RequireFeature
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $this->entitlements->allows($user, $feature)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Your plan does not include this. Choose a plan to unlock it.', 'feature' => $feature], 402);
        }
        Inertia::flash('toast', ['type' => 'info', 'message' => 'Your plan does not include this yet. Choose a plan to unlock it.']);

        return redirect('/plans?feature='.urlencode($feature));
    }
}
