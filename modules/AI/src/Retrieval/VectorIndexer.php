<?php

declare(strict_types=1);

namespace Modules\AI\Retrieval;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\AI\AiGateway;
use Modules\AI\Exceptions\ContextNotPermitted;
use Modules\AI\Models\VectorDocument;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Indexes a tenant record's text for retrieval: chunk → embed through the gateway (personal data
 * redacted, credits charged) → store with access metadata. Re-indexing replaces old chunks.
 */
final class VectorIndexer
{
    public const FEATURE = 'retrieval.embed';

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly TenantContext $tenants,
        private readonly Chunker $chunker = new Chunker,
    ) {}

    /**
     * @return int number of chunks stored
     */
    public function index(Model $source, string $text, IndexAccess $access): int
    {
        [$type, $id] = $this->identify($source);
        $chunks = $this->chunker->split($text);

        $this->remove($source);

        if ($chunks === []) {
            return 0;
        }

        $result = $this->gateway->embed(self::FEATURE, $chunks, 'index:'.$type.':'.$id.':'.Str::ulid());

        foreach ($chunks as $i => $chunk) {
            VectorDocument::query()->create([
                'source_type' => $type,
                'source_id' => $id,
                'chunk_index' => $i,
                'owner_user_id' => $access->ownerUserId,
                'team_ids' => $access->teamIds,
                'office_id' => $access->officeId,
                'sensitive' => $access->sensitive,
                'model_id' => $result->modelId,
                'dimensions' => $result->dimensions,
                'embedding' => VectorDocument::pack($result->vectors[$i]),
                'content' => $chunk,
            ]);
        }

        return count($chunks);
    }

    public function remove(Model $source): void
    {
        [$type, $id] = $this->identify($source);

        VectorDocument::query()->where('source_type', $type)->where('source_id', $id)->delete();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function identify(Model $source): array
    {
        if (! in_array(BelongsToTenant::class, class_uses_recursive($source), true)
            || (int) $source->getAttribute('tenant_id') !== $this->tenants->id()) {
            throw new ContextNotPermitted('Only the current tenant\'s own records can be indexed.');
        }

        return [class_basename($source), (string) ($source->getAttribute('ulid') ?? $source->getKey())];
    }
}
