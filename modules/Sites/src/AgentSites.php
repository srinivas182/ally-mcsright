<?php

declare(strict_types=1);

namespace Modules\Sites;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Agents\AgentCredentials;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Sites\Content\MediaLibrary;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;

/**
 * Agent websites (specs/websites/09): each agent gets /agents/{name} on the brokerage's address (and, if the broker
 * allows, their own domain), built from their profile and roster credentials, with their own listings. The broker's
 * brand kit decides what agents may change; the brokerage's name, licence and compliance notices are always shown.
 */
final class AgentSites
{
    public const DEFAULT_RULES = ['enabled' => true, 'template' => false, 'colours' => false, 'sections' => true, 'domain' => false, 'referrals' => true, 'approval' => false];

    public function __construct(private readonly SiteSetup $sites) {}

    /**
     * The brand kit rules for agents (from the brokerage website's settings).
     *
     * @return array{enabled: bool, template: bool, colours: bool, sections: bool, domain: bool, referrals: bool, approval: bool}
     */
    public static function rules(): array
    {
        $saved = (array) (Site::query()->where('kind', 'brokerage')->first(['id', 'settings'])?->settings['agents'] ?? []);
        $out = self::DEFAULT_RULES;
        foreach ($out as $k => $v) {
            $out[$k] = array_key_exists($k, $saved) ? (bool) $saved[$k] : $v;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $in
     */
    public function saveRules(array $in, User $by): void
    {
        if (! SiteAccess::manager($by)) {
            throw ValidationException::withMessages(['agents' => 'Only owners and staff can set the brand kit.']);
        }
        $site = $this->sites->brokerageSite() ?? throw ValidationException::withMessages(['agents' => 'Create the brokerage website first.']);
        $rules = [];
        foreach (self::DEFAULT_RULES as $k => $v) {
            $rules[$k] = (bool) ($in[$k] ?? $v);
        }
        $site->forceFill(['settings' => ['agents' => $rules] + ($site->settings ?? [])])->save();
        app(AuditLogger::class)->record('sites.agent_rules', $site, metadata: $rules, severity: 'notice');
    }

    public function forUser(User $user): ?Site
    {
        return Site::query()->where('kind', 'agent')->where('user_id', $user->id)->first();
    }

    /** Creates the agent's website from their profile (a draft until they publish it). */
    public function provision(User $agent): Site
    {
        $brokerage = $this->sites->brokerageSite() ?? throw ValidationException::withMessages(['site' => 'Your brokerage has not set up its website yet.']);
        if (! self::rules()['enabled']) {
            throw ValidationException::withMessages(['site' => 'Your brokerage has not turned on agent websites.']);
        }
        if ($agent->kind === UserKind::HqStaff) {
            throw ValidationException::withMessages(['site' => 'Only brokerage people can have a website.']);
        }
        if (($existing = $this->forUser($agent)) !== null) {
            return $existing;
        }
        $base = Str::slug($agent->name) ?: 'agent';
        $slug = $base;
        for ($n = 2; Site::query()->where('kind', 'agent')->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }
        $first = (string) $agent->first_name;
        $site = Site::query()->create(['kind' => 'agent', 'user_id' => $agent->id, 'slug' => $slug, 'template' => $brokerage->template, 'brand' => $brokerage->brand,
            'info' => ['name' => $agent->name, 'phone' => (string) ($agent->phone ?? ''), 'email' => (string) $agent->email, 'tagline' => '', 'bio' => '', 'areas' => '',
                'languages' => 'English', 'photo' => null], 'seo' => [], 'status' => 'draft']);
        SitePage::query()->create(['site_id' => $site->id, 'path' => '/', 'title' => $agent->name, 'position' => 0, 'status' => 'published', 'published_at' => CarbonImmutable::now(),
            'updated_by' => $agent->id, 'sections' => Sections::clean([
                ['type' => 'agent', 'id' => 'profile', 'props' => ['home_value' => true, 'search' => true]], // AG7
                ['type' => 'listings', 'id' => 'mine', 'props' => ['title' => $first.'\'s listings', 'mine' => true, 'limit' => 6]],
                ['type' => 'text', 'id' => 'about', 'props' => ['title' => 'About '.$first, 'body' => '']],
                ['type' => 'testimonials', 'id' => 'reviews', 'props' => ['title' => 'What clients say', 'items' => []]],
                ['type' => 'contact', 'id' => 'contact', 'props' => ['title' => 'Talk to '.$first, 'body' => 'Send a message and '.$first.' will get back to you shortly.']],
            ])]);
        app(AuditLogger::class)->record('sites.agent_site_created', $site, metadata: ['user' => $agent->id]);

        return $site;
    }

    /**
     * @param  array<string, mixed>  $in
     */
    public function saveProfile(Site $site, array $in, User $by): Site
    {
        self::guardOwner($site, $by);
        $t = static fn (string $k, int $max): string => mb_substr(trim(strip_tags((string) (is_scalar($in[$k] ?? null) ? $in[$k] : ''))), 0, $max);
        $email = $t('email', 150);
        $photo = is_string($in['photo'] ?? null) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $in['photo']) === 1 ? $in['photo'] : null;
        $info = ['name' => $t('name', 120) ?: (string) ($site->info['name'] ?? ''), 'tagline' => $t('tagline', 160), 'bio' => $t('bio', 4000), 'phone' => $t('phone', 30),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '', 'areas' => $t('areas', 200), 'languages' => $t('languages', 120), 'photo' => $photo];
        $changes = ['info' => $info];
        $rules = self::rules();
        if ($rules['template'] && isset($in['template']) && array_key_exists((string) $in['template'], (array) config('sites.templates'))) {
            $changes['template'] = (string) $in['template'];
        }
        if ($rules['colours'] && isset($in['brand']) && is_array($in['brand'])) {
            $hex = static fn ($v, string $d): string => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1 ? strtolower($v) : $d;
            $changes['brand'] = ['primary' => $hex($in['brand']['primary'] ?? null, (string) ($site->brand['primary'] ?? '#1d4ed8')),
                'accent' => $hex($in['brand']['accent'] ?? null, (string) ($site->brand['accent'] ?? '#0f172a'))] + $site->brand;
        }
        $site->fill($changes)->save();
        app(AuditLogger::class)->record('sites.agent_profile_saved', $site);

        return $site;
    }

    public function publish(Site $site, bool $live, User $by): void
    {
        self::guardOwner($site, $by);
        // AW1: when the broker reviews agent websites first, an agent's "publish" asks for approval instead.
        if ($live && self::rules()['approval'] && ! SiteAccess::manager($by)) {
            $site->forceFill(['status' => 'review'])->save();
            app(AuditLogger::class)->record('sites.agent_site_review_requested', $site, severity: 'notice');

            return;
        }
        $site->forceFill(['status' => $live ? 'live' : 'draft', 'published_at' => $live ? ($site->published_at ?? CarbonImmutable::now()) : $site->published_at])->save();
        app(AuditLogger::class)->record($live ? 'sites.agent_site_published' : 'sites.agent_site_unpublished', $site, severity: 'notice');
    }

    /** An agent's own domain (when the brand kit allows), serving their website once DNS is verified. */
    public function addDomain(Site $site, string $host, User $by): TenantDomain
    {
        self::guardOwner($site, $by);
        if (! self::rules()['domain'] && ! SiteAccess::manager($by)) {
            throw ValidationException::withMessages(['host' => 'Your brokerage has not allowed agents to use their own domain.']);
        }
        $domain = $this->sites->addDomain($host, $by, allowAgent: true);
        $domain->forceFill(['site_ulid' => $site->ulid])->save();

        return $domain;
    }

    /**
     * What visitors see about the agent (profile, licence, MLS ids, photo).
     *
     * @return array{name: string, first_name: string, tagline: string, bio: string, phone: string, email: string, areas: list<string>, languages: list<string>, photo: string|null, licence: array{state: string|null, number: string}|null, mls_ids: list<string>}
     */
    public static function profile(Site $site): array
    {
        $info = $site->info;
        $credentials = app(AgentCredentials::class);
        $userId = (int) $site->user_id;
        $list = static fn (string $v): array => array_values(array_filter(array_map('trim', explode(',', $v)), fn (string $x): bool => $x !== ''));
        $name = (string) ($info['name'] ?? 'Agent');

        return ['name' => $name, 'first_name' => (string) strtok($name, ' '), 'tagline' => (string) ($info['tagline'] ?? ''), 'bio' => (string) ($info['bio'] ?? ''),
            'phone' => (string) ($info['phone'] ?? ''), 'email' => (string) ($info['email'] ?? ''), 'areas' => $list((string) ($info['areas'] ?? '')),
            'languages' => $list((string) ($info['languages'] ?? '')), 'photo' => app(MediaLibrary::class)->url(is_string($info['photo'] ?? null) ? $info['photo'] : null, 'card'),
            'licence' => $userId > 0 ? $credentials->licence($userId) : null, 'mls_ids' => $userId > 0 ? $credentials->mlsIds($userId) : []];
    }

    private static function guardOwner(Site $site, User $by): void
    {
        if ($site->kind !== 'agent' || ($site->user_id !== $by->id && ! SiteAccess::manager($by))) {
            throw ValidationException::withMessages(['site' => 'You can only change your own website.']);
        }
    }
}
