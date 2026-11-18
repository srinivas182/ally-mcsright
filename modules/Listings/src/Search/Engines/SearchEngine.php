<?php

declare(strict_types=1);

namespace Modules\Listings\Search\Engines;

/**
 * A listing search server (SR1, specs/websites/02): Typesense, Meilisearch or OpenSearch. It answers with listing
 * ids in order and the total; ListingSearch then loads those listings from the database through the usual MLS
 * licence and display rules, so the search server can never show something the rules would hide.
 *
 * Query keys: q, sources (licensed MLS ids), statuses, types, min_price, max_price (cents), beds, baths, min_sqft,
 * bounds [s, w, n, e], near [lat, lng, miles], agent, states, since (unix time), sort, page, per_page.
 */
interface SearchEngine
{
    /** Creates the index and its fields when missing. */
    public function prepare(): void;

    /** @param  list<array<string, mixed>>  $docs */
    public function upsert(array $docs): void;

    /** @param  list<int>  $ids */
    public function delete(array $ids): void;

    /**
     * @param  array<string, mixed>  $query
     * @return array{ids: list<int>, total: int}
     */
    public function search(array $query): array;

    /** How many listings the index holds. */
    public function count(): int;
}
