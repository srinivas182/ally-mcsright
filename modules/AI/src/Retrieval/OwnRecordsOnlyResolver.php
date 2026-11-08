<?php

declare(strict_types=1);

namespace Modules\AI\Retrieval;

use Illuminate\Contracts\Auth\Factory as Auth;

/**
 * The most restrictive scope until roles exist: a signed-in user retrieves only records they
 * own, never sensitive records; anyone else retrieves nothing.
 */
final class OwnRecordsOnlyResolver implements AccessScopeResolver
{
    public function __construct(private readonly Auth $auth) {}

    public function forFeature(string $featureKey): ?AccessScope
    {
        $id = $this->auth->guard()->id();

        return is_numeric($id) ? new AccessScope((int) $id, 'own') : null;
    }
}
