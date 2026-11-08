<?php

declare(strict_types=1);

namespace Modules\AI\Retrieval;

/**
 * Nearest-neighbour search over the current tenant's indexed chunks, restricted to the given
 * access scope. Implementations: DatabaseVectorStore (now), OpenSearch k-NN (with AWS).
 */
interface VectorStore
{
    /**
     * @param  list<float>  $vector
     * @return list<VectorMatch> best first
     */
    public function search(array $vector, string $modelId, AccessScope $scope, int $limit, ?string $sourceType = null): array;
}
