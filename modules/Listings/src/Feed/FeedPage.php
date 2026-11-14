<?php

declare(strict_types=1);

namespace Modules\Listings\Feed;

/** One page of RESO Property records and the link to the next page (null: done). */
final readonly class FeedPage
{
    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function __construct(public array $records, public ?string $next) {}
}
