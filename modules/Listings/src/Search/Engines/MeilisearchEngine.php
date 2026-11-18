<?php

declare(strict_types=1);

namespace Modules\Listings\Search\Engines;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Meilisearch: typo-tolerant text, geo radius and filters. */
final class MeilisearchEngine implements SearchEngine
{
    public function __construct(private readonly string $host, private readonly string $key, private readonly string $index = 'listings') {}

    public function prepare(): void
    {
        if (! $this->http()->get($this->host.'/indexes/'.$this->index)->successful()) {
            $this->ok($this->http()->post($this->host.'/indexes', ['uid' => $this->index, 'primaryKey' => 'id']), 'create the index');
        }
        $this->ok($this->http()->patch($this->host.'/indexes/'.$this->index.'/settings', ['searchableAttributes' => ['text', 'city', 'zip'],
            'filterableAttributes' => ['source', 'dup_source', 'status', 'type', 'price', 'beds', 'baths', 'sqft', 'agent', 'state', 'changed', 'show_address', '_geo'],
            'sortableAttributes' => ['price', 'sqft', 'listed', 'changed', '_geo']]), 'set up the index');
    }

    public function upsert(array $docs): void
    {
        if ($docs === []) {
            return;
        }
        $this->ok($this->http()->post($this->host.'/indexes/'.$this->index.'/documents', array_map(function (array $d): array {
            if (isset($d['lat'], $d['lng'])) {
                $d['_geo'] = ['lat' => $d['lat'], 'lng' => $d['lng']];
            }
            unset($d['lat'], $d['lng']);

            return $d;
        }, $docs)), 'add listings');
    }

    public function delete(array $ids): void
    {
        if ($ids !== []) {
            $this->http()->post($this->host.'/indexes/'.$this->index.'/documents/delete-batch', $ids);
        }
    }

    public function search(array $query): array
    {
        $q = fn (string $s): string => "'".str_replace(['\\', "'"], ['', "\\'"], $s)."'";
        $sources = implode(', ', array_map('intval', $query['sources']));
        $f = ['source IN ['.$sources.']', 'dup_source NOT IN ['.$sources.']', 'status IN ['.implode(', ', array_map($q, $query['statuses'])).']'];
        if ($query['types'] !== []) {
            $f[] = 'type IN ['.implode(', ', array_map($q, $query['types'])).']';
        }
        foreach (['min_price' => 'price >= ', 'max_price' => 'price <= ', 'beds' => 'beds >= ', 'baths' => 'baths >= ', 'min_sqft' => 'sqft >= ', 'since' => 'changed >= '] as $k => $op) {
            if ($query[$k] !== null) {
                $f[] = $op.(int) $query[$k];
            }
        }
        if ($query['bounds'] !== null) {
            [$s, $w, $n, $e] = $query['bounds'];
            $f[] = "_geoBoundingBox([{$n}, {$e}], [{$s}, {$w}])";
            $f[] = 'show_address = true';
        }
        if ($query['near'] !== null) {
            [$lat, $lng, $miles] = $query['near'];
            $f[] = "_geoRadius({$lat}, {$lng}, ".(int) round($miles * 1609.344).')';
            $f[] = 'show_address = true';
        }
        if ($query['agent'] !== null) {
            $f[] = 'agent = '.$q((string) $query['agent']);
        }
        if ($query['states'] !== []) {
            $f[] = 'state IN ['.implode(', ', array_map($q, $query['states'])).']';
        }
        $sort = match ($query['sort']) {
            'price_asc' => ['price:asc'], 'price_desc' => ['price:desc'], 'sqft_desc' => ['sqft:desc'], 'reduced' => ['changed:desc'],
            'distance' => $query['near'] !== null ? ['_geoPoint('.$query['near'][0].', '.$query['near'][1].'):asc'] : ['listed:desc'], default => ['listed:desc'],
        };
        $r = $this->http()->post($this->host.'/indexes/'.$this->index.'/search', ['q' => $query['q'], 'filter' => implode(' AND ', $f), 'sort' => $sort,
            'page' => $query['page'], 'hitsPerPage' => $query['per_page'], 'attributesToRetrieve' => ['id']]);
        $this->ok($r, 'search');

        return ['ids' => array_values(array_map(fn ($h): int => (int) ($h['id'] ?? 0), (array) $r->json('hits', []))), 'total' => (int) $r->json('totalHits', 0)];
    }

    public function count(): int
    {
        $r = $this->http()->get($this->host.'/indexes/'.$this->index.'/stats');

        return $r->successful() ? (int) $r->json('numberOfDocuments', 0) : 0;
    }

    private function http(): PendingRequest
    {
        return Http::timeout(5)->acceptJson()->withToken($this->key);
    }

    private function ok(Response $r, string $what): void
    {
        if ($r->failed()) {
            throw new RuntimeException("The search server could not {$what} ({$r->status()}).");
        }
    }
}
