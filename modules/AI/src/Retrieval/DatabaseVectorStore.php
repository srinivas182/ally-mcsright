<?php

declare(strict_types=1);

namespace Modules\AI\Retrieval;

use Modules\AI\Models\VectorDocument;

/**
 * Exact cosine-similarity search in PHP over the tenant's rows. Correct and fully tested; fine for
 * development and small tenants. Production uses OpenSearch k-NN behind the same interface.
 *
 * Tenant isolation comes from VectorDocument's tenant scope; the access scope is applied here and
 * cannot be widened by callers.
 */
final class DatabaseVectorStore implements VectorStore
{
    public function search(array $vector, string $modelId, AccessScope $scope, int $limit, ?string $sourceType = null): array
    {
        $queryNorm = $this->norm($vector);
        if ($queryNorm === 0.0) {
            return [];
        }

        $matches = [];

        VectorDocument::query()
            ->where('model_id', $modelId)
            ->where('dimensions', count($vector))
            ->when($sourceType !== null, fn ($q) => $q->where('source_type', $sourceType))
            ->when($scope->level === 'own', fn ($q) => $q->where('owner_user_id', $scope->userId))
            ->when(! $scope->includeSensitive, fn ($q) => $q->where('sensitive', false))
            ->orderBy('id')
            ->chunk(500, function ($documents) use ($vector, $queryNorm, $scope, &$matches): void {
                foreach ($documents as $document) {
                    /** @var VectorDocument $document */
                    if (! $scope->allows($document->owner_user_id, $document->team_ids, $document->office_id, $document->sensitive)) {
                        continue;
                    }

                    $candidate = $document->vector();
                    $norm = $this->norm($candidate);
                    if ($norm === 0.0) {
                        continue;
                    }

                    $dot = 0.0;
                    foreach ($vector as $i => $value) {
                        $dot += $value * ($candidate[$i] ?? 0.0);
                    }

                    $matches[] = new VectorMatch($document, $dot / ($queryNorm * $norm));
                }
            });

        usort($matches, fn (VectorMatch $a, VectorMatch $b): int => $b->score <=> $a->score);

        return array_slice($matches, 0, $limit);
    }

    /**
     * @param  list<float>  $vector
     */
    private function norm(array $vector): float
    {
        $sum = 0.0;
        foreach ($vector as $value) {
            $sum += $value * $value;
        }

        return sqrt($sum);
    }
}
