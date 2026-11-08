<?php

declare(strict_types=1);

namespace Modules\AI\Retrieval;

/**
 * Resolves the acting user's retrieval scope for an AI feature. Replaced by the roles and
 * permissions engine in Sprints 5-6 (specs/core/03).
 */
interface AccessScopeResolver
{
    /**
     * Null means the user may retrieve nothing.
     */
    public function forFeature(string $featureKey): ?AccessScope;
}
