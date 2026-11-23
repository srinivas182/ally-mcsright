<?php

declare(strict_types=1);

namespace Modules\Sites;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Enums\SslStatus;
use Modules\Core\Tenancy\Enums\VerificationStatus;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Content\StarterSite;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\Models\SitePost;

/**
 * Creating and managing the brokerage website (specs/websites/05): a starter site with home, about and contact pages
 * from the brokerage's details, a website address ({slug}.{SITES_PLATFORM_DOMAIN}), custom domains, template, brand
 * and publishing. Owners and staff only.
 */
final class SiteSetup
{
    /** @var array<int, string|null> host of each brokerage's live website, per request */
    private array $publicHosts = [];

    public function __construct(private readonly TenantContext $tenants) {}

    /** The live website's host for links (null when the brokerage has no live site). */
    public function publicHost(): ?string
    {
        $tenantId = $this->tenants->id();
        if (! array_key_exists($tenantId, $this->publicHosts)) {
            $this->publicHosts[$tenantId] = $this->brokerageSite()?->status === 'live' ? $this->liveHost() : null;
        }

        return $this->publicHosts[$tenantId];
    }

    public function brokerageSite(): ?Site
    {
        return Site::query()->where('kind', 'brokerage')->first();
    }

    /**
     * @param  array<string, mixed>  $info
     */
    public function create(string $template, array $info, User $by): Site
    {
        self::guard($by);

        return $this->build($template, $info, $by, 'draft');
    }

    /**
     * SU2: the website of a brokerage that just signed up and paid: live straight away at its RightAlly address, in
     * the design chosen while signing up, with starter pages and sample listings until its MLS board is approved.
     *
     * @param  array<string, mixed>  $info
     */
    public function launch(string $template, array $info): Site
    {
        return $this->build(array_key_exists($template, (array) config('sites.templates')) ? $template : 'modern', $info, null, 'live');
    }

    /** @param  array<string, mixed>  $info */
    private function build(string $template, array $info, ?User $by, string $status): Site
    {
        if ($this->brokerageSite() !== null) {
            throw ValidationException::withMessages(['site' => 'Your brokerage already has a website.']);
        }
        if (! array_key_exists($template, (array) config('sites.templates'))) {
            throw ValidationException::withMessages(['template' => 'Choose a design.']);
        }
        $tenant = $this->tenants->current();
        $info = self::cleanInfo($info + ['name' => $tenant->name]);
        $site = Site::query()->create(['kind' => 'brokerage', 'template' => $template, 'info' => $info, 'status' => $status,
            'brand' => (array) config("sites.templates.{$template}.brand", ['primary' => '#1d4ed8', 'accent' => '#0f172a']),
            'seo' => ['title_suffix' => $info['name'], 'description' => (string) ($info['tagline'] ?? '')]]);
        $this->addStarterPages($site, $by);
        $host = Str::slug($tenant->slug).'.'.config('sites.platform_domain');
        TenantDomain::query()->firstOrCreate(['host' => $host], ['tenant_id' => $tenant->id, 'kind' => DomainKind::Platform, 'is_primary' => false,
            'verification_status' => VerificationStatus::Verified, 'ssl_status' => SslStatus::Active, 'verified_at' => now(), 'purpose' => 'website']);
        app(AuditLogger::class)->record('sites.created', $site, metadata: ['template' => $template, 'from' => $by === null ? 'signup' : 'settings'], severity: 'notice');

        return $site;
    }

    /**
     * TP2: adds the starter pages a website doesn't have yet (Home, Buy, Sell, Communities, About, Contact) and, when
     * it has no blog posts, the three starter posts. Pages the brokerage already has are never touched.
     *
     * @return list<string> the paths added
     */
    public function addStarterPages(Site $site, ?User $by = null): array
    {
        $info = (array) $site->info;
        $have = SitePage::query()->where('site_id', $site->id)->pluck('path')->all();
        $position = (int) SitePage::query()->where('site_id', $site->id)->max('position');
        $added = [];
        foreach (StarterSite::pages($info, $site->template) as $i => $page) {
            if (in_array($page['path'], $have, true)) {
                continue;
            }
            SitePage::query()->create(['site_id' => $site->id, 'path' => $page['path'], 'title' => $page['title'], 'in_menu' => $page['in_menu'],
                'meta_description' => $page['meta'], 'sections' => $page['sections'], 'position' => $have === [] ? $i : ++$position, 'status' => 'published',
                'published_at' => CarbonImmutable::now(), 'updated_by' => $by?->id]);
            $added[] = $page['path'];
        }
        if (! SitePost::query()->where('site_id', $site->id)->exists()) {
            foreach (StarterSite::posts($info) as $n => $post) {
                SitePost::query()->create(['site_id' => $site->id, 'slug' => $post['slug'], 'title' => $post['title'], 'excerpt' => $post['excerpt'], 'body' => $post['body'],
                    'category' => $post['category'], 'cover_media' => $post['cover'], 'status' => 'published', 'published_at' => CarbonImmutable::now()->subDays(3 * $n + 1)]);
            }
        }
        if ($added !== [] && $by !== null) {
            app(AuditLogger::class)->record('sites.starter_pages_added', $site, metadata: ['paths' => $added], severity: 'notice');
        }

        return $added;
    }

    /**
     * @param  array<string, mixed>  $in
     */
    public function update(Site $site, array $in, User $by): Site
    {
        self::guard($by);
        $changes = [];
        if (isset($in['template'])) {
            if (! array_key_exists((string) $in['template'], (array) config('sites.templates'))) {
                throw ValidationException::withMessages(['template' => 'Choose a design.']);
            }
            $changes['template'] = (string) $in['template'];
        }
        if (isset($in['info']) && is_array($in['info'])) {
            $changes['info'] = self::cleanInfo($in['info'] + $site->info);
        }
        if (isset($in['brand']) && is_array($in['brand'])) {
            $b = $in['brand'];
            $hex = static fn ($v, string $d): string => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1 ? strtolower($v) : $d;
            $url = static fn ($v): ?string => is_string($v) && preg_match('#^https://[^\s"<>]{1,490}$#', $v) === 1 ? $v : null;
            $changes['brand'] = ['primary' => $hex($b['primary'] ?? null, (string) ($site->brand['primary'] ?? '#1d4ed8')), 'accent' => $hex($b['accent'] ?? null, (string) ($site->brand['accent'] ?? '#0f172a')),
                'logo_url' => $url($b['logo_url'] ?? null), 'hero_image' => $url($b['hero_image'] ?? null),
                // BR2: how far the brand colour goes in the back office.
                'office' => in_array($b['office'] ?? null, ['full', 'accent', 'off'], true) ? $b['office'] : (string) ($site->brand['office'] ?? 'full')];
        }
        if (isset($in['settings']) && is_array($in['settings']) && array_key_exists('signup_after', $in['settings'])) {
            $changes['settings'] = ['signup_after' => max(0, min(20, (int) $in['settings']['signup_after']))] + ($site->settings ?? []);
        }
        if (array_key_exists('ai_crawlers', $in)) {
            $changes['ai_crawlers'] = (bool) $in['ai_crawlers'];
        }
        if (isset($in['seo']) && is_array($in['seo'])) {
            $changes['seo'] = ['title_suffix' => mb_substr(trim(strip_tags((string) ($in['seo']['title_suffix'] ?? ''))), 0, 60) ?: $site->displayName(),
                'description' => mb_substr(trim(strip_tags((string) ($in['seo']['description'] ?? ''))), 0, 300)];
        }
        $site->fill($changes)->save();
        app(AuditLogger::class)->record('sites.updated', $site, metadata: ['fields' => array_keys($changes)]);

        return $site;
    }

    public function publish(Site $site, bool $live, User $by): void
    {
        self::guard($by);
        $this->publicHosts = [];
        $site->forceFill(['status' => $live ? 'live' : 'draft', 'published_at' => $live ? ($site->published_at ?? CarbonImmutable::now()) : $site->published_at])->save();
        app(AuditLogger::class)->record($live ? 'sites.published' : 'sites.unpublished', $site, severity: 'notice');
    }

    /**
     * The site's web addresses: the platform address first, then custom domains with their DNS status.
     *
     * @return list<array{host: string, kind: string, verified: bool}>
     */
    public function hosts(): array
    {
        return array_values(TenantDomain::query()->where('tenant_id', $this->tenants->id())->where('purpose', 'website')->orderByRaw("case kind when 'platform' then 0 else 1 end")->orderBy('id')->get()
            ->map(fn (TenantDomain $d): array => ['host' => $d->host, 'kind' => $d->kind->value, 'verified' => $d->verification_status === VerificationStatus::Verified])->all());
    }

    /** Where the site is visited: the first verified custom domain, else the platform address. */
    public function liveHost(): ?string
    {
        $hosts = $this->hosts();
        foreach ($hosts as $h) {
            if ($h['kind'] !== 'platform' && $h['verified']) {
                return $h['host'];
            }
        }

        return $hosts[0]['host'] ?? null;
    }

    /**
     * A one-hour signed link to preview a page's draft on the website host (checked with a relative signature,
     * so it works on any of the site's domains).
     */
    public function previewUrl(SitePage $page, int $minutes = 60): ?string
    {
        return $this->signedPreview('/_preview/'.$page->ulid, $minutes);
    }

    public function previewPostUrl(SitePost $post, int $minutes = 60): ?string
    {
        return $this->signedPreview('/_preview/post/'.$post->ulid, $minutes);
    }

    private function signedPreview(string $path, int $minutes): ?string
    {
        $host = $this->liveHost();
        if ($host === null) {
            return null;
        }
        $query = 'expires='.CarbonImmutable::now()->addMinutes($minutes)->getTimestamp();
        $signature = hash_hmac('sha256', $path.'?'.$query, (string) config('app.key'));

        return 'https://'.$host.$path.'?'.$query.'&signature='.$signature;
    }

    /** Adds a custom website domain; it serves the site once DNS is verified (Cloudflare for SaaS, specs/core/05). */
    public function addDomain(string $host, User $by, bool $allowAgent = false): TenantDomain
    {
        if (! $allowAgent) {
            self::guard($by);
        }
        $host = strtolower(trim($host));
        $platform = (string) config('core.platform_domain');
        $sites = (string) config('sites.platform_domain');
        if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1 || str_ends_with($host, '.'.$platform) || str_ends_with($host, '.'.$sites)
            || $host === config('core.hq_host')) {
            throw ValidationException::withMessages(['host' => 'Enter your domain, for example www.joneshomes.com.']);
        }
        if (TenantDomain::query()->where('host', $host)->exists()) {
            throw ValidationException::withMessages(['host' => 'That domain is already connected.']);
        }
        $domain = TenantDomain::query()->create(['tenant_id' => $this->tenants->id(), 'host' => $host, 'kind' => DomainKind::Custom, 'is_primary' => false,
            'verification_status' => VerificationStatus::Pending, 'ssl_status' => SslStatus::Pending, 'purpose' => 'website']);
        $this->publicHosts = [];
        app(AuditLogger::class)->record('sites.domain_added', metadata: ['host' => $host], severity: 'notice');

        return $domain;
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private static function cleanInfo(array $in): array
    {
        $t = static fn (string $k, int $max): string => mb_substr(trim(strip_tags((string) (is_scalar($in[$k] ?? null) ? $in[$k] : ''))), 0, $max);
        $social = [];
        foreach (['facebook', 'instagram', 'linkedin', 'youtube', 'x', 'tiktok', 'zillow', 'google'] as $k) {
            $v = $in['social'][$k] ?? null;
            if (is_string($v) && preg_match('#^https://[^\s"<>]{1,290}$#', $v) === 1) {
                $social[$k] = $v;
            }
        }
        $email = $t('email', 150);

        return ['name' => $t('name', 120), 'tagline' => $t('tagline', 200), 'about' => $t('about', 3000), 'area' => $t('area', 120), 'phone' => $t('phone', 30),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '', 'street' => $t('street', 150), 'city' => $t('city', 80),
            'state' => strtoupper($t('state', 2)), 'zip' => $t('zip', 10), 'licence' => $t('licence', 60), 'social' => $social,
            'privacy' => $t('privacy', 20000)]; // the owner's own privacy policy (plain text), replacing the standard one at /privacy
    }

    private static function guard(User $by): void
    {
        if (! SiteAccess::manager($by)) {
            throw ValidationException::withMessages(['site' => 'Only owners and staff can manage the website.']);
        }
    }
}
