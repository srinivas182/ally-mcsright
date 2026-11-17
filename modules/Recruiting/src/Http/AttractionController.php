<?php

declare(strict_types=1);

namespace Modules\Recruiting\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Recruiting\Campaigns;
use Modules\Recruiting\Careers;
use Modules\Recruiting\Models\Campaign;
use Modules\Recruiting\Models\Enrollment;
use Modules\Recruiting\Models\Recruit;
use Modules\Sites\Models\Site;

/** Recruiting attraction (RC2, specs/grow/17): campaigns, the careers page settings, agents' own words, unsubscribe. */
final class AttractionController
{
    public function campaigns(Request $request, Campaigns $campaigns): Response
    {
        self::recruiter($request);
        $campaigns->seedTemplates();
        $counts = Enrollment::query()->selectRaw('campaign_id, status, count(*) as n')->groupBy('campaign_id', 'status')->get()
            ->groupBy('campaign_id')->map(fn ($rows): array => $rows->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all());

        return Inertia::render('recruiting/campaigns', [
            'campaigns' => Campaign::query()->orderBy('name')->get()->map(fn (Campaign $c): array => ['ulid' => $c->ulid, 'name' => $c->name, 'steps' => $c->steps,
                'status' => $c->status, 'active' => $counts->get($c->id)['active'] ?? 0, 'finished' => $counts->get($c->id)['finished'] ?? 0])->all(),
        ]);
    }

    public function saveCampaign(Request $request, Campaigns $campaigns, ?Campaign $campaign = null): RedirectResponse
    {
        $campaigns->save($campaign, $request->only(['name', 'steps']), self::recruiter($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Campaign saved.']);

        return back();
    }

    public function campaignStatus(Request $request, Campaign $campaign, Campaigns $campaigns): RedirectResponse
    {
        self::recruiter($request);
        $campaigns->setStatus($campaign, $request->boolean('active'));

        return back();
    }

    public function start(Request $request, Recruit $recruit, Campaigns $campaigns): RedirectResponse
    {
        $user = self::recruiter($request);
        $campaign = Campaign::query()->where('ulid', (string) $request->input('campaign'))->firstOrFail();
        $campaigns->start($recruit, $campaign, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Started \"{$campaign->name}\"."]);

        return back();
    }

    public function stop(Request $request, Recruit $recruit, Campaign $campaign, Campaigns $campaigns): RedirectResponse
    {
        $user = self::recruiter($request);
        $e = Enrollment::query()->where('recruit_id', $recruit->id)->where('campaign_id', $campaign->id)->firstOrFail();
        $campaigns->stop($e, "Stopped by {$user->first_name}.");

        return back();
    }

    public function careers(Request $request, Careers $careers): Response
    {
        $user = self::recruiter($request);

        return Inertia::render('recruiting/careers', [
            'careers' => $careers->settings(), 'url' => $careers->publicUrl(), 'stories' => $careers->stories(),
            'canEdit' => $user->isKind(UserKind::Owner), 'siteLive' => Site::query()->where('kind', 'brokerage')->where('status', 'live')->exists(),
        ]);
    }

    public function saveCareers(Request $request, Careers $careers): RedirectResponse
    {
        abort_unless(self::recruiter($request)->isKind(UserKind::Owner), 403);
        $careers->save($request->only(['live', 'headline', 'intro', 'reasons', 'calculator']));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Careers page saved.']);

        return back();
    }

    /** Any agent: their own words for the careers page, shown only while they agree. */
    public function myStory(Request $request, Careers $careers): Response
    {
        $user = self::agent($request);
        $story = $careers->story($user);

        return Inertia::render('recruiting/my-story', [
            'story' => $story !== null ? ['quote' => $story->quote, 'show_deals' => $story->show_deals, 'since' => $story->consented_at->toDateString()] : null,
            'live' => $careers->live(), 'url' => $careers->publicUrl(),
        ]);
    }

    public function saveStory(Request $request, Careers $careers): RedirectResponse
    {
        $d = $request->validate(['quote' => ['required', 'string', 'max:600'], 'consent' => ['accepted'], 'show_deals' => ['boolean']]);
        $careers->saveStory(self::agent($request), (string) $d['quote'], (bool) ($d['show_deals'] ?? false));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Thank you. Your words are on the careers page.']);

        return back();
    }

    public function removeStory(Request $request, Careers $careers): RedirectResponse
    {
        $careers->removeStory(self::agent($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Taken off the careers page.']);

        return back();
    }

    /** From a recruiting email: signed; GET asks, POST (and mail apps' one-click) does it. */
    public function unsubscribe(Request $request, string $recruit, Campaigns $campaigns, TenantContext $tenants): HttpResponse
    {
        abort_unless($request->hasValidSignature(false), 403);
        $r = Recruit::query()->where('ulid', $recruit)->firstOrFail();
        if ($request->isMethod('post')) {
            $campaigns->unsubscribe($r);
        }

        return response()->view('recruiting::unsubscribe', ['done' => $request->isMethod('post') || $r->email_opt_out_at !== null, 'brokerage' => $tenants->current()->name])
            ->withHeaders(['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    private static function recruiter(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can('recruiting.manage'), 403);

        return $user;
    }

    private static function agent(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->isKind(UserKind::Agent) || $user->isKind(UserKind::Owner)), 403);

        return $user;
    }
}
