<?php

declare(strict_types=1);

namespace Modules\Listings\Feed;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsSource;
use RuntimeException;

/**
 * RESO Web API (OData) client for MLS Grid, Trestle, Bridge and direct RESO servers. Credentials are either a
 * bearer token or OAuth2 client credentials (token cached until shortly before it expires). Paging follows
 *
 * @odata.nextLink; changes are ordered by ModificationTimestamp so a cursor never skips records.
 */
final class ResoWebApi implements ResoFeed
{
    public function __construct(private readonly Http $http) {}

    public function changes(MlsSource $source, ?string $since, ?string $next, int $top = 200): FeedPage
    {
        if ($next !== null) {
            return $this->page($this->get($source, $next));
        }
        $filter = 'ModificationTimestamp gt '.($since ?? '1970-01-01T00:00:00Z');
        if ($source->provider === 'mls_grid' && $source->originating_system !== null) {
            $filter = "OriginatingSystemName eq '".str_replace("'", "''", $source->originating_system)."' and ".$filter;
        }
        $query = ['$filter' => $filter, '$orderby' => 'ModificationTimestamp asc', '$top' => (string) $top];
        if ($source->provider === 'mls_grid') {
            $query['$expand'] = 'Media';
        }

        return $this->page($this->get($source, rtrim($source->base_url, '/').'/Property?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)));
    }

    public function activeKeys(MlsSource $source): array
    {
        $statuses = implode(' or ', array_map(fn (string $s): string => "StandardStatus eq '".$s."'", Listing::ON_MARKET));
        $url = rtrim($source->base_url, '/').'/Property?'.http_build_query(['$select' => 'ListingKey', '$filter' => "({$statuses})", '$top' => '1000'], '', '&', PHP_QUERY_RFC3986);
        $keys = [];
        for ($i = 0; $url !== null && $i < 2000; $i++) {
            $page = $this->page($this->get($source, $url));
            foreach ($page->records as $r) {
                if (isset($r['ListingKey']) && is_string($r['ListingKey'])) {
                    $keys[] = $r['ListingKey'];
                }
            }
            $url = $page->next;
        }

        return $keys;
    }

    public function test(MlsSource $source): ?string
    {
        try {
            $this->changes($source, null, null, 1);

            return null;
        } catch (FeedThrottled) {
            return 'The MLS is limiting requests right now; the connection itself works.';
        } catch (\Throwable $e) {
            return mb_substr($e->getMessage(), 0, 200);
        }
    }

    private function get(MlsSource $source, string $url): Response
    {
        $base = parse_url($source->base_url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_HOST) !== $base) {
            throw new RuntimeException('The MLS returned a next page on another host.'); // never send credentials elsewhere
        }
        $response = $this->client($source)->get($url);
        if ($response->status() === 429) {
            throw new FeedThrottled((int) ($response->header('Retry-After') ?: 60));
        }
        if ($response->failed()) {
            throw new RuntimeException('The MLS answered '.$response->status().'.');
        }

        return $response;
    }

    private function page(Response $response): FeedPage
    {
        $json = (array) $response->json();
        $records = array_values(array_filter((array) ($json['value'] ?? []), 'is_array'));
        $next = isset($json['@odata.nextLink']) && is_string($json['@odata.nextLink']) ? $json['@odata.nextLink'] : null;

        return new FeedPage($records, $next);
    }

    private function client(MlsSource $source): PendingRequest
    {
        return $this->http->acceptJson()->timeout(60)->withToken($this->token($source));
    }

    private function token(MlsSource $source): string
    {
        $c = $source->credentials ?? [];
        if (($c['token'] ?? '') !== '') {
            return (string) $c['token'];
        }
        if (($c['client_id'] ?? '') === '' || ($c['token_url'] ?? '') === '') {
            throw new RuntimeException('Add the MLS credentials first.');
        }

        return Cache::remember('listings:token:'.$source->id.':'.hash('sha256', (string) $c['client_id']), 3000, function () use ($c): string {
            $r = $this->http->asForm()->timeout(30)->post((string) $c['token_url'], ['grant_type' => 'client_credentials', 'client_id' => $c['client_id'],
                'client_secret' => $c['client_secret'] ?? '', 'scope' => $c['scope'] ?? 'api']);
            if ($r->failed() || ! is_string($r->json('access_token'))) {
                throw new RuntimeException('The MLS refused the credentials ('.$r->status().').');
            }

            return (string) $r->json('access_token');
        });
    }
}
