<?php

declare(strict_types=1);

namespace Modules\Sites\Ai;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\CrmAccess;
use Modules\Listings\Search\MarketStats;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePost;
use Modules\Sites\SiteSetup;

/**
 * Monthly market updates (specs/websites/10): on the 1st, a draft post per chosen area from that month's MLS figures,
 * then an email to owners to review and publish. Nothing is published automatically. One draft per area per month.
 */
final class MarketUpdates
{
    public const MAX_PLACES = 10;

    public function __construct(private readonly SiteSetup $sites, private readonly ContentStudio $studio, private readonly MarketStats $stats) {}

    /**
     * @return array{enabled: bool, places: list<string>}
     */
    public static function settings(?Site $site): array
    {
        $s = $site?->settings['market_updates'] ?? [];
        $places = [];
        foreach ($s['places'] ?? [] as $p) {
            $places[] = (string) $p;
        }

        return ['enabled' => (bool) ($s['enabled'] ?? false), 'places' => $places];
    }

    /**
     * @param  list<string>  $places
     */
    public function save(bool $enabled, array $places, User $by): void
    {
        if (! CrmAccess::manager($by)) {
            throw ValidationException::withMessages(['places' => 'Only owners and staff can set up market updates.']);
        }
        $site = $this->sites->brokerageSite() ?? throw ValidationException::withMessages(['places' => 'Create the website first.']);
        $valid = array_column($this->stats->places($site->tenant_id, 60), 'slug');
        $places = array_slice(array_values(array_unique(array_intersect($places, $valid))), 0, self::MAX_PLACES);
        if ($enabled && $places === []) {
            throw ValidationException::withMessages(['places' => 'Choose at least one area with market figures.']);
        }
        $site->forceFill(['settings' => ['market_updates' => ['enabled' => $enabled, 'places' => $places]] + ($site->settings ?? [])])->save();
        app(AuditLogger::class)->record('sites.market_updates_saved', $site, metadata: ['enabled' => $enabled, 'places' => $places]);
    }

    /**
     * Writes this month's drafts for the current brokerage; returns how many were written.
     */
    public function run(?CarbonImmutable $month = null): int
    {
        $month ??= CarbonImmutable::now()->startOfMonth();
        $site = $this->sites->brokerageSite();
        $settings = self::settings($site);
        if ($site === null || $site->status !== 'live' || ! $settings['enabled']) {
            return 0;
        }
        $written = [];
        foreach ($settings['places'] as $place) {
            $m = $this->stats->forPlace($site->tenant_id, $place);
            if ($m === null) {
                continue;
            }
            $label = $m['name'].' housing market update, '.$month->format('F Y');
            if (SitePost::query()->where('site_id', $site->id)->where('slug', Str::slug($label))->exists()) {
                continue; // already drafted this month
            }
            try {
                $r = $this->studio->writePost($site, ['topic' => $label, 'area' => $place, 'audience' => 'home buyers and sellers in '.$m['name'], 'tone' => 'expert',
                    'length' => 'medium', 'category' => 'Market updates', 'keywords' => $m['name'].' real estate market, '.$m['name'].' home prices'], null, ContentStudio::MARKET);
                $r['post']->forceFill(['slug' => Str::slug($label), 'category' => 'Market updates'])->save();
                $written[] = $r['post'];
            } catch (\Throwable $e) {
                report($e);
            }
        }
        if ($written !== []) {
            $owners = User::query()->whereIn('kind', [UserKind::Owner, UserKind::Staff])->get()->filter(fn (User $u): bool => $u->isActive() && CrmAccess::manager($u));
            Notification::send($owners, new DraftsReady(array_map(fn (SitePost $p): array => ['title' => $p->title, 'ulid' => $p->ulid], $written)));
        }

        return count($written);
    }
}
