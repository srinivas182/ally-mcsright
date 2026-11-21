<?php

declare(strict_types=1);

namespace Modules\Listings\Feed;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Modules\Core\Services\OutsideServices;
use Modules\Listings\Models\MlsSource;
use RuntimeException;
use Throwable;

/**
 * IDX3: Repliers. Listings are read from Repliers' listings search (oldest change first, a page at a time) and turned
 * into RESO records, so they go through the same sync, MLS display rules, duplicate check and search as every other
 * feed. The feed's "originating system" holds the Repliers board ID; RightAlly's Repliers key comes from
 * HQ › Outside services unless the feed has its own.
 */
final class RepliersFeed implements ResoFeed
{
    /** Where Repliers serves listing photos (the listing gives a path). */
    public const CDN = 'https://cdn.repliers.io/';

    public const PER_PAGE = 100;

    public function __construct(private readonly Http $http) {}

    public function changes(MlsSource $source, ?string $since, ?string $next, int $top = 200): FeedPage
    {
        if ($next !== null) {
            return $this->page($this->get($source, $next), $next);
        }
        $query = $this->base($source) + ['status' => ['A', 'U'], 'sortBy' => 'updatedOnAsc', 'resultsPerPage' => min(self::PER_PAGE, max(1, $top)), 'pageNum' => 1];
        if ($since !== null) {
            $query['minUpdatedOn'] = CarbonImmutable::parse($since)->utc()->format('Y-m-d\TH:i:s\Z');
        }

        $url = $this->url($source, $query);

        return $this->page($this->get($source, $url), $url);
    }

    public function activeKeys(MlsSource $source): array
    {
        $keys = [];
        $url = $this->url($source, $this->base($source) + ['status' => ['A'], 'fields' => 'mlsNumber', 'resultsPerPage' => self::PER_PAGE, 'pageNum' => 1]);
        for ($i = 0; $url !== null && $i < 3000; $i++) {
            $response = $this->get($source, $url);
            foreach ((array) $response->json('listings', []) as $l) {
                if (is_array($l) && is_scalar($l['mlsNumber'] ?? null)) {
                    $keys[] = (string) $l['mlsNumber'];
                }
            }
            $url = $this->nextUrl($response, $url);
        }

        return $keys;
    }

    public function test(MlsSource $source): ?string
    {
        try {
            $this->changes($source, null, null, 1);

            return null;
        } catch (FeedThrottled) {
            return 'Repliers is limiting requests right now; the connection itself works.';
        } catch (Throwable $e) {
            return mb_substr($e->getMessage(), 0, 200);
        }
    }

    /**
     * A Repliers listing as a RESO record (Data Dictionary names the sync already understands).
     *
     * @param  array<string, mixed>  $l
     * @return array<string, mixed>
     */
    public static function toReso(array $l): array
    {
        $a = is_array($l['address'] ?? null) ? $l['address'] : [];
        $d = is_array($l['details'] ?? null) ? $l['details'] : [];
        $m = is_array($l['map'] ?? null) ? $l['map'] : [];
        $agent = is_array($l['agents'][0] ?? null) ? $l['agents'][0] : [];
        $office = is_array($l['office'] ?? null) ? $l['office'] : [];
        $num = fn (mixed $v): ?float => is_numeric($v) ? (float) $v : (is_string($v) && preg_match('/^\s*(\d+(?:\.\d+)?)/', $v, $x) === 1 ? (float) $x[1] : null);
        $street = trim(implode(' ', array_filter([(string) ($a['streetDirectionPrefix'] ?? ''), (string) ($a['streetName'] ?? ''), (string) ($a['streetSuffix'] ?? ''),
            (string) ($a['streetDirection'] ?? '')], fn (string $p): bool => $p !== '')));
        $images = array_values(array_filter((array) ($l['images'] ?? []), 'is_string'));
        $class = strtolower((string) ($l['class'] ?? ''));
        $lease = strtolower((string) ($l['type'] ?? '')) === 'lease';

        return [
            'ListingKey' => (string) ($l['mlsNumber'] ?? ''),
            'ListingId' => (string) ($l['mlsNumber'] ?? ''),
            'StandardStatus' => self::status($l),
            'MlsStatus' => is_string($l['lastStatus'] ?? null) ? $l['lastStatus'] : null,
            'PropertyType' => $lease ? ($class === 'commercial' ? 'Commercial Lease' : 'Residential Lease') : ($class === 'commercial' ? 'Commercial Sale' : 'Residential'),
            'PropertySubType' => $d['propertyType'] ?? ($class === 'condo' ? 'Condominium' : null),
            'BedroomsTotal' => $num($d['numBedrooms'] ?? null),
            'BathroomsFull' => $num($d['numBathrooms'] ?? null),
            'BathroomsHalf' => $num($d['numBathroomsHalf'] ?? null),
            'LivingArea' => $num($d['sqft'] ?? null),
            'YearBuilt' => $num($d['yearBuilt'] ?? null),
            'StreetNumber' => $a['streetNumber'] ?? null,
            'StreetName' => $street !== '' ? $street : null,
            'UnitNumber' => $a['unitNumber'] ?? null,
            'City' => $a['city'] ?? null,
            'StateOrProvince' => $a['state'] ?? null,
            'PostalCode' => $a['zip'] ?? null,
            'CountyOrParish' => $a['area'] ?? null,
            'SubdivisionName' => $a['neighborhood'] ?? null,
            'Latitude' => $num($m['latitude'] ?? null),
            'Longitude' => $num($m['longitude'] ?? null),
            'PublicRemarks' => $d['description'] ?? null,
            'ListPrice' => $num($l['listPrice'] ?? null),
            'OriginalListPrice' => $num($l['originalPrice'] ?? null),
            'ClosePrice' => $num($l['soldPrice'] ?? null),
            'CloseDate' => $l['soldDate'] ?? null,
            'ListingContractDate' => $l['listDate'] ?? null,
            'ModificationTimestamp' => $l['updatedOn'] ?? null,
            'DaysOnMarket' => $num($l['daysOnMarket'] ?? null),
            'ListAgentMlsId' => isset($agent['agentId']) ? (string) $agent['agentId'] : null,
            'ListAgentFullName' => $agent['name'] ?? null,
            'ListOfficeName' => $office['brokerageName'] ?? null,
            'VirtualTourURLUnbranded' => $d['virtualTourUrl'] ?? null,
            'PhotosCount' => is_numeric($l['photoCount'] ?? null) ? (int) $l['photoCount'] : count($images),
            'Media' => array_map(fn (string $path, int $i): array => ['MediaKey' => mb_substr($path, 0, 100), 'MediaURL' => self::CDN.ltrim($path, '/'),
                'MediaCategory' => 'Photo', 'Order' => $i], $images, array_keys($images)),
            'RepliersBoardId' => $l['boardId'] ?? null,
            // IDX10: home facts (Repliers' wording varies by board: anything other than none/no counts as yes).
            'PoolPrivateYN' => self::yes($d['swimmingPool'] ?? null),
            'WaterfrontYN' => self::yes($d['waterfront'] ?? null),
            'GarageSpaces' => $num($d['numGarageSpaces'] ?? null),
        ];
    }

    /** "Inground" → true, "None" or "No" → false, missing → null. */
    private static function yes(mixed $v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (! is_string($v) || trim($v) === '') {
            return null;
        }

        return ! in_array(strtolower(trim($v)), ['none', 'no', 'n', 'false', '0'], true);
    }

    /**
     * Repliers' status (A on the market, U off it) and last status, as a RESO standard status.
     *
     * @param  array<string, mixed>  $l
     */
    public static function status(array $l): string
    {
        if (is_string($l['standardStatus'] ?? null) && $l['standardStatus'] !== '') {
            return $l['standardStatus'];
        }
        $last = strtolower((string) ($l['lastStatus'] ?? ''));
        if (($l['status'] ?? 'A') === 'A') {
            return in_array($last, ['sc', 'sce'], true) ? 'Active Under Contract' : 'Active';
        }

        return match ($last) {
            'sld', 'lsd' => 'Closed',
            'exp' => 'Expired',
            'ter', 'lc' => 'Canceled',
            'sus' => 'Hold',
            'dft' => 'Incomplete',
            default => 'Withdrawn',
        };
    }

    /** @return array<string, mixed> */
    private function base(MlsSource $source): array
    {
        $board = trim((string) $source->originating_system);

        return $board !== '' ? ['boardId' => $board] : [];
    }

    /** @param array<string, mixed> $query */
    private function url(MlsSource $source, array $query): string
    {
        // Repliers takes repeated names for lists (status=A&status=U).
        $parts = [];
        foreach ($query as $k => $v) {
            foreach ((array) $v as $one) {
                $parts[] = rawurlencode($k).'='.rawurlencode((string) $one);
            }
        }

        return rtrim($source->base_url, '/').'/listings?'.implode('&', $parts);
    }

    private function page(Response $response, string $url): FeedPage
    {
        $records = [];
        foreach ((array) $response->json('listings', []) as $l) {
            if (is_array($l) && is_scalar($l['mlsNumber'] ?? null)) {
                $records[] = self::toReso($l);
            }
        }

        return new FeedPage($records, $this->nextUrl($response, $url));
    }

    private function nextUrl(Response $response, string $current): ?string
    {
        $page = (int) $response->json('page', 1);
        $pages = (int) $response->json('numPages', 1);
        if ($page >= $pages || $current === '') {
            return null;
        }

        return (string) preg_replace('/([?&])pageNum=\d+/', '${1}pageNum='.($page + 1), $current);
    }

    private function get(MlsSource $source, string $url): Response
    {
        if (parse_url($url, PHP_URL_HOST) !== parse_url($source->base_url, PHP_URL_HOST)) {
            throw new RuntimeException('Repliers returned a next page on another host.'); // never send the key elsewhere
        }
        $response = $this->http->acceptJson()->timeout(60)->withHeaders(['REPLIERS-API-KEY' => $this->key($source)])->get($url);
        if ($response->status() === 429) {
            throw new FeedThrottled((int) ($response->header('Retry-After') ?: 60));
        }
        if ($response->failed()) {
            throw new RuntimeException('Repliers answered '.$response->status().'.');
        }

        return $response;
    }

    private function key(MlsSource $source): string
    {
        $own = trim((string) (($source->credentials ?? [])['token'] ?? ''));
        if ($own !== '') {
            return $own;
        }
        $key = trim((string) (app(OutsideServices::class)->active('idx_repliers')['keys']['api_key'] ?? ''));
        if ($key === '') {
            throw new RuntimeException('Switch on MLS listings: Repliers in HQ › Outside services first.');
        }

        return $key;
    }
}
