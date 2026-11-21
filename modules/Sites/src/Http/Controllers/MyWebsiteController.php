<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Idx\Subscriptions;
use Modules\Sites\AgentSites;
use Modules\Sites\Ailly\AillySettings;
use Modules\Sites\Content\MediaLibrary;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\SiteSetup;

/** Settings → My website: an agent's own website, within the brokerage's brand kit (specs/websites/09). */
final class MyWebsiteController
{
    public function __construct(private readonly AgentSites $agents, private readonly SiteSetup $sites) {}

    /**
     * IDX8: agents inherit their brokerage's MLS boards: the listings on their website come from the same boards, with
     * the same notices. Shown read-only, so an agent knows where the homes come from and never has to sign up again.
     *
     * @return list<array{name: string, status: string, status_label: string}>
     */
    private static function boards(): array
    {
        if (! class_exists(Subscriptions::class)) {
            return [];
        }
        try {
            $all = app(Subscriptions::class)->forTenant(app(TenantContext::class)->id());
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_map(fn (array $b): array => ['name' => (string) $b['board'], 'status' => (string) $b['status'], 'status_label' => (string) $b['status_label']],
            array_filter($all, fn (array $b): bool => $b['status'] !== 'cancelled')));
    }

    public function index(Request $request, MediaLibrary $media): Response
    {
        $user = self::user($request);
        $site = $this->agents->forUser($user);
        $rules = AgentSites::rules();
        $brokerage = $this->sites->brokerageSite();
        $host = $this->sites->liveHost();

        return Inertia::render('settings/my-website', [
            'mls_boards' => self::boards(),
            'available' => $brokerage !== null && $rules['enabled'],
            'brokerage_live' => $brokerage?->status === 'live',
            'rules' => $rules,
            'site' => $site === null ? null : [
                'ulid' => $site->ulid, 'status' => $site->status, 'template' => $site->template, 'brand' => $site->brand, 'info' => $site->info,
                'url' => $host !== null ? 'https://'.$host.'/agents/'.$site->slug : null,
                'referral' => $host !== null && $site->status === 'live' && $rules['referrals'] ? 'https://'.$host.'/r/'.$site->slug : null,
                'pages' => SitePage::query()->where('site_id', $site->id)->orderBy('position')->get()->map(fn (SitePage $p): array => ['ulid' => $p->ulid, 'path' => $p->path, 'title' => $p->title,
                    'has_draft' => $p->draft_sections !== null])->values(),
                'domains' => TenantDomain::query()->where('site_ulid', $site->ulid)->get()->map(fn (TenantDomain $d): array => ['host' => $d->host,
                    'verified' => $d->verification_status->value === 'verified'])->values(),
                'credentials' => ['licence' => AgentSites::profile($site)['licence'], 'mls_ids' => AgentSites::profile($site)['mls_ids']],
                'ailly' => ['name' => (string) (($site->settings ?? [])['ailly']['name'] ?? ''), 'photo' => ($site->settings ?? [])['ailly']['photo'] ?? null,
                    'default_name' => AillySettings::for($brokerage)['name'], 'enabled' => AillySettings::for($brokerage)['enabled']],
            ],
            'templates' => collect((array) config('sites.templates'))->map(fn (array $t, string $k): array => ['key' => $k, 'name' => $t['name']])->values(),
            'media' => $site !== null ? PageEditorController::mediaList($site, $media) : [],
            'cname_target' => (string) config('sites.cname_target'),
        ]);
    }

    /** A QR code for the agent's referral link (AW1), for cards, signs and flyers. */
    public function qr(Request $request): HttpResponse
    {
        $site = self::mine($this->agents, self::user($request));
        $host = $this->sites->liveHost();
        abort_unless($host !== null && $site->status === 'live' && AgentSites::rules()['referrals'], 404);
        $png = (new Writer(new GDLibRenderer(600, 2)))->writeString('https://'.$host.'/r/'.$site->slug);

        return response($png, 200, ['Content-Type' => 'image/png', 'Content-Disposition' => 'inline; filename="my-website-qr.png"', 'Cache-Control' => 'private, max-age=300']);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->agents->provision(self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your website is ready. Add your photo and story, then publish it.']);

        return back();
    }

    public function update(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:120'], 'tagline' => ['nullable', 'string', 'max:160'], 'bio' => ['nullable', 'string', 'max:4000'],
            'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:150'], 'areas' => ['nullable', 'string', 'max:200'], 'languages' => ['nullable', 'string', 'max:120'],
            'photo' => ['nullable', 'string', 'size:26'], 'template' => ['nullable', 'string'], 'brand' => ['nullable', 'array'],
            'brand.primary' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'brand.accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'ailly_name' => ['nullable', 'string', 'max:30'], 'ailly_photo' => ['nullable', 'string', 'size:26']]);
        $site = self::mine($this->agents, $user);
        $this->agents->saveProfile($site, Arr::except($data, ['ailly_name', 'ailly_photo']), $user);
        if ($request->has('ailly_name') || $request->has('ailly_photo')) {
            AillySettings::saveAgentPersona($site->fresh() ?? $site, $data['ailly_name'] ?? null, $data['ailly_photo'] ?? null, $user);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved.']);

        return back();
    }

    public function publish(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $live = (bool) $request->validate(['live' => ['required', 'boolean']])['live'];
        $this->agents->publish(self::mine($this->agents, $user), $live, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $live ? 'Your website is live.' : 'Your website is offline.']);

        return back();
    }

    public function domain(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $this->agents->addDomain(self::mine($this->agents, $user), (string) $request->validate(['host' => ['required', 'string', 'max:253']])['host'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Domain added. Add the DNS record shown; it goes live once verified.']);

        return back();
    }

    private static function mine(AgentSites $agents, User $user): Site
    {
        return $agents->forUser($user) ?? abort(404);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
