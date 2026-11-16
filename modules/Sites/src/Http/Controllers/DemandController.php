<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Access\Can;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Demand\BuyerDemand;
use Modules\Sites\Models\DemandReport;

/**
 * Listings → Buyer demand (specs/grow/10): run a report for a home before the listing appointment, read it, print
 * it (English or Spanish) for the seller, and tell the agents who hold matching buyers.
 */
final class DemandController
{
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $open = is_string($request->query('report')) ? DemandReport::query()->where('ulid', $request->query('report'))->first() : null;

        return Inertia::render('listings/demand', [
            'reports' => DemandReport::query()->where('user_id', $user->id)->latest('id')->limit(20)->get()
                ->map(fn (DemandReport $r): array => ['ulid' => $r->ulid, 'address' => $r->address, 'zip' => $r->zip, 'buyers' => (int) ($r->result['buyers'] ?? 0),
                    'at' => $r->created_at->toIso8601String()])->values()->all(),
            'report' => $open !== null && self::mine($open, $user) ? self::view($open) : null,
        ]);
    }

    public function store(Request $request, BuyerDemand $demand): RedirectResponse
    {
        $report = $demand->run(self::user($request), $request->only(['address', 'zip', 'price', 'beds', 'baths', 'sqft']));

        return redirect('/listings/demand?report='.$report->ulid);
    }

    public function tell(Request $request, string $report, BuyerDemand $demand): RedirectResponse
    {
        $user = self::user($request);
        $r = DemandReport::query()->where('ulid', $report)->firstOrFail();
        abort_unless(self::mine($r, $user), 403);
        $n = $demand->tellAgents($r, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $n === 0 ? 'No other agents hold matching buyers.' : "Told {$n} ".($n === 1 ? 'agent' : 'agents').'. They\'ll contact you about their buyers.']);

        return back();
    }

    /** The branded report for the seller, ready to print, in English or Spanish. */
    public function print(Request $request, string $report, TenantContext $tenants): HttpResponse
    {
        $user = self::user($request);
        $r = DemandReport::query()->where('ulid', $report)->firstOrFail();
        abort_unless(self::mine($r, $user), 403);
        $lang = $request->query('lang') === 'es' ? 'es' : 'en';

        return response()->view('sites::demand-report', ['r' => $r, 'lang' => $lang, 't' => DemandWords::for($lang), 'brokerage' => $tenants->current()->name,
            'agent' => User::query()->find($r->user_id)])->header('X-Robots-Tag', 'noindex');
    }

    /** @return array<string, mixed> */
    private static function view(DemandReport $r): array
    {
        return ['ulid' => $r->ulid, 'address' => $r->address, 'zip' => $r->zip, 'inputs' => $r->inputs, 'agents_told' => $r->agents_told_at !== null] + $r->result;
    }

    private static function mine(DemandReport $r, User $user): bool
    {
        return $r->user_id === $user->id || Can::manage($user, 'marketing.manage');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true), 403);

        return $user;
    }
}
