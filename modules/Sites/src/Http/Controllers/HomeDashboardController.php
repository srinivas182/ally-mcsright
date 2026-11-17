<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Task;
use Modules\Listings\Search\ListingSearch;
use Modules\Sites\Models\HomeReport;
use Modules\Sites\Models\Site;
use Modules\Sites\Visitors\Valuation;

/**
 * The homeowner dashboard (MK2, specs/crm/21): from the monthly home-value email, a private page with today's range,
 * how it has moved month by month, an equity estimate the homeowner works out in their own browser (the balance they
 * type is never sent or stored), homes for sale nearby, and one button to ask their agent for a full market analysis.
 */
final class HomeDashboardController
{
    private const PRIVATE = ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer'];

    public function show(Request $request, string $report, TenantContext $tenants, Valuation $valuation, ListingSearch $search): Response|RedirectResponse
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(['asked'], false), 403);
        $row = HomeReport::query()->where('ulid', $report)->firstOrFail();
        if ($request->isMethod('post')) {
            $this->ask($row);
            $uri = (string) $request->getRequestUri();

            return redirect($request->getSchemeAndHttpHost().$uri.(str_contains($uri, 'asked=1') ? '' : '&asked=1'), 303);
        }
        if ($row->dashboard_viewed_at === null) {
            $contact = Contact::query()->whereKey($row->contact_id)->first();
            if ($contact instanceof Contact) {
                app(Timeline::class)->system($contact, 'Opened their home dashboard for '.$row->address.'.', $row->user_id);
            }
        }
        $row->forceFill(['dashboard_viewed_at' => CarbonImmutable::now()])->save();
        $estimate = $valuation->estimate($tenants->id(), $row->zip, $row->beds, $row->sqft);
        $agent = User::query()->whereKey($row->user_id)->first();
        $site = Site::query()->where('kind', 'brokerage')->first();
        $nearby = $search->search($tenants->id(), ['q' => $row->zip, 'sort' => 'newest', 'per_page' => 4])['results'];

        return response()->view('sites::home-dashboard', ['report' => $row, 'estimate' => $estimate, 'history' => $row->history ?? [], 'nearby' => $nearby,
            'agent' => $agent, 'brokerage' => $tenants->current()->name, 'logo' => $site?->brand['logo_url'] ?? null, 'asked' => $request->query('asked') === '1',
            'action' => $request->getRequestUri()])->withHeaders(self::PRIVATE);
    }

    /** "Ask for a full market analysis": a high-priority task for their agent, and a note on their timeline (at most twice a day). */
    private function ask(HomeReport $row): void
    {
        if (RateLimiter::attempt('home-dashboard-ask:'.$row->id, 2, fn (): bool => true, 86400)) {
            Task::query()->create(['contact_id' => $row->contact_id, 'assigned_to' => $row->user_id, 'type' => 'call', 'title' => 'Homeowner asked for a market analysis: '.$row->address,
                'notes' => 'Asked from their home dashboard. Prepare a CMA with recent sales and call them.', 'priority' => 'high', 'due_at' => CarbonImmutable::now()->addHours(4)]);
            $contact = Contact::query()->whereKey($row->contact_id)->first();
            if ($contact instanceof Contact) {
                app(Timeline::class)->system($contact, 'Asked for a full market analysis of '.$row->address.' from their home dashboard.', $row->user_id);
            }
            app(AuditLogger::class)->record('sites.home_dashboard.cma_requested', $row, label: $row->address);
        }
    }
}
