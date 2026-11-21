<?php

declare(strict_types=1);

namespace Modules\Sites\Content;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FairHousing;
use Modules\Listings\Idx\DemoListings;
use Modules\Listings\Search\MarketStats;
use stdClass;

/**
 * IDX9: automatic area pages. Every city, ZIP code and neighbourhood with enough homes for sale gets its own page
 * (/market/...) with live figures, the newest homes and questions answered, and is listed in the sitemap and llms.txt.
 * The brokerage can add its own words to any area (unique local content ranks better) or switch a page off.
 *
 * While the website shows sample listings only, area pages are shown but kept out of search engines, so sample
 * figures are never indexed as real ones.
 */
final class AreaPages
{
    public const INTRO_MAX = 2000;

    public function __construct(private readonly MarketStats $stats, private readonly TenantContext $tenants) {}

    /**
     * Areas to publish (not switched off), busiest first, each with the brokerage's intro if it wrote one.
     *
     * @return list<array{kind: string, slug: string, name: string, count: int, intro: string|null}>
     */
    public function published(int $limit = 60): array
    {
        $own = $this->own();
        $out = [];
        foreach ($this->stats->places($this->tenants->id(), $limit) as $place) {
            $row = $own[$place['slug']] ?? null;
            if ($row !== null && (bool) $row->hidden) {
                continue;
            }
            $out[] = $place + ['intro' => $row?->intro !== null && $row->intro !== '' ? (string) $row->intro : null];
        }

        return $out;
    }

    /**
     * Every area, for the brokerage's settings page, including switched-off ones.
     *
     * @return list<array{kind: string, slug: string, name: string, count: int, intro: string|null, hidden: bool}>
     */
    public function all(): array
    {
        $own = $this->own();

        return array_map(fn (array $p): array => $p + ['intro' => isset($own[$p['slug']]) ? $own[$p['slug']]->intro : null, 'hidden' => (bool) ($own[$p['slug']]->hidden ?? false)],
            $this->stats->places($this->tenants->id(), 200));
    }

    public function hidden(string $slug): bool
    {
        return (bool) ($this->own()[$slug]->hidden ?? false);
    }

    public function intro(string $slug): ?string
    {
        $intro = $this->own()[$slug]->intro ?? null;

        return is_string($intro) && $intro !== '' ? $intro : null;
    }

    /** True while the website shows sample (demo) listings only: area pages then stay out of search engines. */
    public function sample(): bool
    {
        return class_exists(DemoListings::class) && app(DemoListings::class)->sourcesFor($this->tenants->id()) !== [];
    }

    /** @param array<string, mixed> $input */
    public function save(string $slug, array $input, User $by): void
    {
        $data = Validator::make($input, ['intro' => ['nullable', 'string', 'max:'.self::INTRO_MAX], 'hidden' => ['required', 'boolean']],
            ['intro.max' => 'Keep it under '.self::INTRO_MAX.' characters.'])->validate();
        abort_unless(preg_match('/^[a-z0-9-]{2,140}$/', $slug) === 1, 404);
        $intro = trim(strip_tags((string) ($data['intro'] ?? '')));
        // Public words about a neighborhood: the same Fair Housing check as ads and review replies.
        if ($intro !== '' && class_exists(FairHousing::class) && ($flags = FairHousing::screen($intro, 'websites', 'blocked', 'Area page')) !== []) {
            throw ValidationException::withMessages(['intro' => 'Fair Housing: please remove "'.implode('", "', $flags).'".']);
        }
        DB::connection(Connections::cell())->table('site_areas')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'slug' => $slug],
            ['intro' => $intro !== '' ? $intro : null, 'hidden' => (bool) $data['hidden'], 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('sites.area.updated', metadata: ['area' => $slug, 'hidden' => (bool) $data['hidden']]);
    }

    /** @return array<string, stdClass> */
    private function own(): array
    {
        return DB::connection(Connections::cell())->table('site_areas')->where('tenant_id', $this->tenants->id())->get()->keyBy('slug')->all();
    }
}
