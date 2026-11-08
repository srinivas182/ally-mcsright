<?php

declare(strict_types=1);

namespace Modules\AI\Retrieval;

use Illuminate\Support\Str;
use Modules\AI\AiGateway;
use Modules\AI\Data\RetrievedRecord;

/**
 * Finds the passages most relevant to a question among records the acting user may see
 * (ADR 0007, specs/ai/01). The tenant comes from the tenant scope and the access scope from the
 * resolver; callers cannot pass or widen either.
 */
final class ScopedRetriever
{
    public function __construct(
        private readonly AiGateway $gateway,
        private readonly VectorStore $store,
        private readonly AccessScopeResolver $scopes,
    ) {}

    /**
     * @return list<RetrievedRecord>
     */
    public function retrieve(string $featureKey, string $query, int $limit = 5, ?string $sourceType = null): array
    {
        $scope = $this->scopes->forFeature($featureKey);

        if ($scope === null || trim($query) === '') {
            return [];
        }

        $embedding = $this->gateway->embed(VectorIndexer::FEATURE, [$query], 'retrieve:'.Str::ulid(), $scope->userId);

        $matches = $this->store->search($embedding->vectors[0], $embedding->modelId, $scope, max(1, min($limit, 20)), $sourceType);

        return array_map(fn (VectorMatch $match): RetrievedRecord => RetrievedRecord::fromIndexedChunk($match->document), $matches);
    }
}
