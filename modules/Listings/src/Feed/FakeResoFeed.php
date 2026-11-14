<?php

declare(strict_types=1);

namespace Modules\Listings\Feed;

use Modules\Listings\Models\MlsSource;

/** A simulated MLS for tests and demos: records served in ModificationTimestamp order, a page at a time. */
final class FakeResoFeed implements ResoFeed
{
    /** @var list<array<string, mixed>> */
    public array $records = [];

    /** Throw a 429 on the next call. */
    public bool $throttle = false;

    /** Fail after this many pages (to test resuming). */
    public ?int $failAfterPages = null;

    public int $calls = 0;

    public function changes(MlsSource $source, ?string $since, ?string $next, int $top = 200): FeedPage
    {
        $this->calls++;
        if ($this->throttle) {
            $this->throttle = false;
            throw new FeedThrottled(120);
        }
        if ($this->failAfterPages !== null && $this->calls > $this->failAfterPages) {
            $this->failAfterPages = null;
            throw new \RuntimeException('The MLS answered 503.');
        }
        $offset = 0;
        if ($next !== null) {
            parse_str((string) parse_url($next, PHP_URL_QUERY), $q);
            $since = is_string($q['since'] ?? null) ? $q['since'] : '';
            $offset = is_numeric($q['skip'] ?? null) ? (int) $q['skip'] : 0;
        }
        $all = array_values(array_filter($this->records, fn (array $r): bool => $since === null || $since === '' || (string) $r['ModificationTimestamp'] > $since));
        usort($all, fn (array $a, array $b): int => strcmp((string) $a['ModificationTimestamp'], (string) $b['ModificationTimestamp']));
        $page = array_slice($all, $offset, $top);
        $more = $offset + $top < count($all);

        return new FeedPage($page, $more ? 'https://fake.mls/Property?'.http_build_query(['since' => $since ?? '', 'skip' => $offset + $top]) : null);
    }

    public function activeKeys(MlsSource $source): array
    {
        return array_values(array_map(fn (array $r): string => (string) $r['ListingKey'],
            array_filter($this->records, fn (array $r): bool => in_array($r['StandardStatus'] ?? '', ['Active', 'Active Under Contract', 'Coming Soon', 'Pending'], true))));
    }

    public function test(MlsSource $source): ?string
    {
        return null;
    }
}
