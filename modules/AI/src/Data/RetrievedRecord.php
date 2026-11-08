<?php

declare(strict_types=1);

namespace Modules\AI\Data;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Exceptions\ContextNotPermitted;
use Modules\AI\Models\VectorDocument;
use Modules\Core\Tenancy\Concerns\BelongsToTenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * The only form in which data can reach an AI model (ADR 0007, specs/ai/01).
 *
 * - fromModel(): a tenant-owned record that belongs to the CURRENT tenant. Records from another
 *   tenant, or models that are not tenant-scoped, are refused.
 * - shared(): non-private shared content only (public MLS listings, the rule library, help).
 *
 * Callers must load records through tenant-scoped, permission-checked queries; the role and
 * scope check is added to this factory when permissions ship (specs/core/03).
 */
final readonly class RetrievedRecord
{
    /**
     * @param  array<string, string>  $fields
     */
    private function __construct(
        public string $type,
        public string $id,
        public array $fields,
        public bool $shared,
    ) {}

    /**
     * @param  array<string, string|int|null>  $fields  only the fields the AI needs
     */
    public static function fromModel(Model $model, array $fields): self
    {
        if (! in_array(BelongsToTenant::class, class_uses_recursive($model), true)) {
            throw new ContextNotPermitted($model::class.' is not tenant-scoped; use RetrievedRecord::shared() for public content.');
        }

        $context = app(TenantContext::class);

        if (! $context->check() || (int) $model->getAttribute('tenant_id') !== $context->id()) {
            throw new ContextNotPermitted('Record does not belong to the current tenant.');
        }

        $id = $model->getAttribute('ulid') ?? $model->getKey();

        return new self(class_basename($model), (string) $id, self::stringify($fields), false);
    }

    /**
     * A retrieved passage from the current tenant's search index. The type and ID are those of the
     * source record, so answers cite the record the passage came from.
     */
    public static function fromIndexedChunk(VectorDocument $chunk): self
    {
        $context = app(TenantContext::class);

        if (! $context->check() || $chunk->tenant_id !== $context->id()) {
            throw new ContextNotPermitted('Indexed passage does not belong to the current tenant.');
        }

        return new self($chunk->source_type, $chunk->source_id, ['passage' => $chunk->content], false);
    }

    /**
     * Non-private content shared across tenants (e.g. a public MLS listing or a state rule).
     *
     * @param  array<string, string|int|null>  $fields
     */
    public static function shared(string $type, string $id, array $fields): self
    {
        return new self($type, $id, self::stringify($fields), true);
    }

    /**
     * @param  array<string, string|int|null>  $fields
     * @return array<string, string>
     */
    private static function stringify(array $fields): array
    {
        return array_map(fn (string|int|null $value): string => (string) $value, $fields);
    }
}
