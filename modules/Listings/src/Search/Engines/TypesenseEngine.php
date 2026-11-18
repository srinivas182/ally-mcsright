<?php

declare(strict_types=1);

namespace Modules\Listings\Search\Engines;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Typesense (recommended; run on our own servers). Typo-tolerant text, geo radius and filters. */
final class TypesenseEngine implements SearchEngine
{
    public function __construct(private readonly string $host, private readonly string $key, private readonly string $index = 'listings') {}

    public function prepare(): void
    {
        if ($this->http()->get($this->host.'/collections/'.$this->index)->successful()) {
            return;
        }
        $opt = fn (string $name, string $type, bool $facet = false): array => ['name' => $name, 'type' => $type, 'optional' => true, 'facet' => $facet];
        $this->ok($this->http()->post($this->host.'/collections', ['name' => $this->index, 'default_sorting_field' => 'listed', 'fields' => [
            ['name' => 'source', 'type' => 'int32', 'facet' => true], ['name' => 'dup_source', 'type' => 'int32'], ['name' => 'status', 'type' => 'string', 'facet' => true], $opt('type', 'string', true),
            $opt('price', 'int64'), $opt('beds', 'int32'), $opt('baths', 'int32'), $opt('sqft', 'int32'), $opt('location', 'geopoint'),
            ['name' => 'text', 'type' => 'string'], $opt('city', 'string', true), $opt('zip', 'string', true), $opt('state', 'string', true), $opt('agent', 'string'),
            ['name' => 'listed', 'type' => 'int64'], $opt('changed', 'int64'), ['name' => 'created', 'type' => 'int64'], ['name' => 'show_address', 'type' => 'bool'],
        ]]), 'create the index');
    }

    public function upsert(array $docs): void
    {
        if ($docs === []) {
            return;
        }
        $lines = implode("\n", array_map(function (array $d): string {
            $d['id'] = (string) $d['id'];
            if (isset($d['lat'], $d['lng'])) {
                $d['location'] = [$d['lat'], $d['lng']];
            }
            unset($d['lat'], $d['lng']);

            return (string) json_encode(array_filter($d, fn ($v): bool => $v !== null));
        }, $docs));
        $this->ok($this->http()->withBody($lines, 'text/plain')->post($this->host.'/collections/'.$this->index.'/documents/import?action=upsert'), 'add listings');
    }

    public function delete(array $ids): void
    {
        if ($ids !== []) {
            $this->http()->delete($this->host.'/collections/'.$this->index.'/documents?filter_by='.rawurlencode('id:['.implode(',', $ids).']'));
        }
    }

    public function search(array $query): array
    {
        $sources = implode(',', array_map('intval', $query['sources']));
        $f = ['source:['.$sources.']', 'dup_source:!=['.$sources.']', 'status:['.implode(',', array_map(fn ($s): string => '`'.$s.'`', $query['statuses'])).']'];
        if ($query['types'] !== []) {
            $f[] = 'type:['.implode(',', array_map(fn ($s): string => '`'.str_replace('`', '', $s).'`', $query['types'])).']';
        }
        foreach (['min_price' => 'price:>=', 'max_price' => 'price:<=', 'beds' => 'beds:>=', 'baths' => 'baths:>=', 'min_sqft' => 'sqft:>=', 'since' => 'changed:>='] as $k => $op) {
            if ($query[$k] !== null) {
                $f[] = $op.(int) $query[$k];
            }
        }
        if ($query['bounds'] !== null) {
            [$s, $w, $n, $e] = $query['bounds'];
            $f[] = "location:({$n}, {$w}, {$n}, {$e}, {$s}, {$e}, {$s}, {$w})";
            $f[] = 'show_address:true';
        }
        if ($query['near'] !== null) {
            [$lat, $lng, $miles] = $query['near'];
            $f[] = "location:({$lat}, {$lng}, {$miles} mi)";
            $f[] = 'show_address:true';
        }
        if ($query['agent'] !== null) {
            $f[] = 'agent:=`'.str_replace('`', '', (string) $query['agent']).'`';
        }
        if ($query['states'] !== []) {
            $f[] = 'state:['.implode(',', $query['states']).']';
        }
        $sort = match ($query['sort']) {
            'price_asc' => 'price:asc', 'price_desc' => 'price:desc', 'sqft_desc' => 'sqft:desc', 'reduced' => 'changed:desc',
            'distance' => $query['near'] !== null ? 'location('.$query['near'][0].', '.$query['near'][1].'):asc' : 'listed:desc', default => 'listed:desc',
        };
        $r = $this->http()->get($this->host.'/collections/'.$this->index.'/documents/search', ['q' => $query['q'] !== '' ? $query['q'] : '*', 'query_by' => 'text,city,zip',
            'filter_by' => implode(' && ', $f), 'sort_by' => $sort, 'page' => $query['page'], 'per_page' => $query['per_page'], 'include_fields' => 'id', 'num_typos' => 2]);
        $this->ok($r, 'search');

        return ['ids' => array_values(array_map(fn ($h): int => (int) ($h['document']['id'] ?? 0), (array) $r->json('hits', []))), 'total' => (int) $r->json('found', 0)];
    }

    public function count(): int
    {
        $r = $this->http()->get($this->host.'/collections/'.$this->index);

        return $r->successful() ? (int) $r->json('num_documents', 0) : 0;
    }

    private function http(): PendingRequest
    {
        return Http::timeout(5)->acceptJson()->withHeaders(['X-TYPESENSE-API-KEY' => $this->key]);
    }

    private function ok(Response $r, string $what): void
    {
        if ($r->failed()) {
            throw new RuntimeException("The search server could not {$what} ({$r->status()}).");
        }
    }
}
