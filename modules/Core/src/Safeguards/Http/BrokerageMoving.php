<?php

declare(strict_types=1);

namespace Modules\Core\Safeguards\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Safeguards\DatabaseMoves;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/** During a move's final copy (a few minutes) pages still open, but saving waits (DS2). */
final class BrokerageMoving
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->tenants->idOrNull();
        if ($id !== null && ! $request->isMethodSafe() && Cache::has(DatabaseMoves::readOnlyKey($id))) {
            return response('Your brokerage\'s data is being moved to its own database. Saving is paused for a few minutes; please try again shortly.', 503,
                ['Retry-After' => '120']);
        }

        return $next($request);
    }
}
