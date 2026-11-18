<?php

declare(strict_types=1);

namespace Modules\Listings\Search\Engines;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Amazon OpenSearch: fuzzy text, geo distance and filters. */
final class OpenSearchEngine implements SearchEngine
{
    public function __construct(private readonly string $host, private readonly string $user, private readonly string $password, private readonly string $index = 'listings') {}

    public function prepare(): void
    {
        if ($this->http()->head($this->host.'/'.$this->index)->successful()) {
            return;
        }
        $keyword = ['type' => 'keyword'];
        $this->ok($this->http()->put($this->host.'/'.$this->index, ['mappings' => ['properties' => ['source' => ['type' => 'integer'], 'dup_source' => ['type' => 'integer'], 'status' => $keyword, 'type' => $keyword,
            'price' => ['type' => 'long'], 'beds' => ['type' => 'integer'], 'baths' => ['type' => 'integer'], 'sqft' => ['type' => 'integer'], 'location' => ['type' => 'geo_point'],
            'text' => ['type' => 'text'], 'city' => ['type' => 'text', 'fields' => ['raw' => $keyword]], 'zip' => $keyword, 'state' => $keyword, 'agent' => $keyword,
            'listed' => ['type' => 'long'], 'changed' => ['type' => 'long'], 'created' => ['type' => 'long'], 'show_address' => ['type' => 'boolean']]]]), 'create the index');
    }

    public function upsert(array $docs): void
    {
        if ($docs === []) {
            return;
        }
        $lines = [];
        foreach ($docs as $d) {
            if (isset($d['lat'], $d['lng'])) {
                $d['location'] = ['lat' => $d['lat'], 'lon' => $d['lng']];
            }
            unset($d['lat'], $d['lng']);
            $lines[] = (string) json_encode(['index' => ['_index' => $this->index, '_id' => (string) $d['id']]]);
            $lines[] = (string) json_encode($d);
        }
        $this->ok($this->http()->withBody(implode("\n", $lines)."\n", 'application/x-ndjson')->post($this->host.'/_bulk'), 'add listings');
    }

    public function delete(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $lines = array_map(fn (int $id): string => (string) json_encode(['delete' => ['_index' => $this->index, '_id' => (string) $id]]), $ids);
        $this->http()->withBody(implode("\n", $lines)."\n", 'application/x-ndjson')->post($this->host.'/_bulk');
    }

    public function search(array $query): array
    {
        $filter = [['terms' => ['source' => array_map('intval', $query['sources'])]], ['terms' => ['status' => $query['statuses']]]];
        if ($query['types'] !== []) {
            $filter[] = ['terms' => ['type' => $query['types']]];
        }
        foreach (['min_price' => ['price', 'gte'], 'max_price' => ['price', 'lte'], 'beds' => ['beds', 'gte'], 'baths' => ['baths', 'gte'], 'min_sqft' => ['sqft', 'gte'], 'since' => ['changed', 'gte']] as $k => [$field, $op]) {
            if ($query[$k] !== null) {
                $filter[] = ['range' => [$field => [$op => (int) $query[$k]]]];
            }
        }
        if ($query['bounds'] !== null) {
            [$s, $w, $n, $e] = $query['bounds'];
            $filter[] = ['geo_bounding_box' => ['location' => ['top_left' => ['lat' => $n, 'lon' => $w], 'bottom_right' => ['lat' => $s, 'lon' => $e]]]];
            $filter[] = ['term' => ['show_address' => true]];
        }
        if ($query['near'] !== null) {
            [$lat, $lng, $miles] = $query['near'];
            $filter[] = ['geo_distance' => ['distance' => $miles.'mi', 'location' => ['lat' => $lat, 'lon' => $lng]]];
            $filter[] = ['term' => ['show_address' => true]];
        }
        if ($query['agent'] !== null) {
            $filter[] = ['term' => ['agent' => (string) $query['agent']]];
        }
        if ($query['states'] !== []) {
            $filter[] = ['terms' => ['state' => $query['states']]];
        }
        $must = $query['q'] !== '' ? [['multi_match' => ['query' => $query['q'], 'fields' => ['text', 'city', 'zip'], 'fuzziness' => 'AUTO']]] : [['match_all' => (object) []]];
        $sort = match ($query['sort']) {
            'price_asc' => [['price' => 'asc']], 'price_desc' => [['price' => 'desc']], 'sqft_desc' => [['sqft' => 'desc']], 'reduced' => [['changed' => 'desc']],
            'distance' => $query['near'] !== null ? [['_geo_distance' => ['location' => ['lat' => $query['near'][0], 'lon' => $query['near'][1]], 'order' => 'asc', 'unit' => 'mi']]] : [['listed' => 'desc']],
            default => [['listed' => 'desc']],
        };
        $notCopies = [['terms' => ['dup_source' => array_map('intval', $query['sources'])]]];
        $r = $this->http()->post($this->host.'/'.$this->index.'/_search', ['query' => ['bool' => ['must' => $must, 'filter' => $filter, 'must_not' => $notCopies]], 'sort' => $sort,
            'from' => ($query['page'] - 1) * $query['per_page'], 'size' => $query['per_page'], '_source' => false, 'track_total_hits' => true]);
        $this->ok($r, 'search');

        return ['ids' => array_values(array_map(fn ($h): int => (int) ($h['_id'] ?? 0), (array) $r->json('hits.hits', []))), 'total' => (int) $r->json('hits.total.value', 0)];
    }

    public function count(): int
    {
        $r = $this->http()->get($this->host.'/'.$this->index.'/_count');

        return $r->successful() ? (int) $r->json('count', 0) : 0;
    }

    private function http(): PendingRequest
    {
        return Http::timeout(5)->acceptJson()->withBasicAuth($this->user, $this->password);
    }

    private function ok(Response $r, string $what): void
    {
        if ($r->failed()) {
            throw new RuntimeException("The search server could not {$what} ({$r->status()}).");
        }
    }
}
