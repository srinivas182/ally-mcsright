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
use Modules\Crm\CrmAccess;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;

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
        if ($this->brokerageSite() !== null) {
            throw ValidationException::withMessages(['site' => 'Your brokerage already has a website.']);
        }
        if (! array_key_exists($template, (array) config('sites.templates'))) {
            throw ValidationException::withMessages(['template' => 'Choose a design.']);
        }
        $tenant = $this->tenants->current();
        $info = self::cleanInfo($info + ['name' => $tenant->name]);
        $site = Site::query()->create(['kind' => 'brokerage', 'template' => $template, 'info' => $info, 'status' => 'draft',
            'brand' => ['primary' => $template === 'aurum' ? '#c9a45c' : '#1d4ed8', 'accent' => $template === 'aurum' ? '#c9a45c' : '#0f172a'],
            'seo' => ['title_suffix' => $info['name'], 'description' => (string) ($info['tagline'] ?? '')]]);
        $position = 0;
        foreach (Sections::starterPages($info) as $path => $sections) {
            SitePage::query()->create(['site_id' => $site->id, 'path' => $path, 'title' => match ($path) {
                '/' => 'Home', '/about' => 'About', default => 'Contact'
            },
                'meta_description' => $path === '/' ? mb_substr((string) ($info['tagline'] ?? "Homes for sale with {$info['name']}."), 0, 300) : null,
                'sections' => $sections, 'position' => $position++, 'status' => 'published', 'published_at' => CarbonImmutable::now(), 'updated_by' => $by->id]);
        }
        $host = Str::slug($tenant->slug).'.'.config('sites.platform_domain');
        TenantDomain::query()->firstOrCreate(['host' => $host], ['tenant_id' => $tenant->id, 'kind' => DomainKind::Platform, 'is_primary' => false,
            'verification_status' => VerificationStatus::Verified, 'ssl_status' => SslStatus::Active, 'verified_at' => now(), 'purpose' => 'website']);
        app(AuditLogger::class)->record('sites.created', $site, metadata: ['template' => $template], severity: 'notice');

        return $site;
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
                'logo_url' => $url($b['logo_url'] ?? null), 'hero_image' => $url($b['hero_image'] ?? null)];
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
        $host = $this->liveHost();
        if ($host === null) {
            return null;
        }
        $path = '/_preview/'.$page->ulid;
        $query = 'expires='.CarbonImmutable::now()->addMinutes($minutes)->getTimestamp();
        $signature = hash_hmac('sha256', $path.'?'.$query, (string) config('app.key'));

        return 'https://'.$host.$path.'?'.$query.'&signature='.$signature;
    }

    /** Adds a custom website domain; it serves the site once DNS is verified (Cloudflare for SaaS, specs/core/05). */
    public function addDomain(string $host, User $by): TenantDomain
    {
        self::guard($by);
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
            'state' => strtoupper($t('state', 2)), 'zip' => $t('zip', 10), 'licence' => $t('licence', 60), 'social' => $social];
    }

    private static function guard(User $by): void
    {
        if (! CrmAccess::manager($by)) {
            throw ValidationException::withMessages(['site' => 'Only owners and staff can manage the website.']);
        }
    }
}
