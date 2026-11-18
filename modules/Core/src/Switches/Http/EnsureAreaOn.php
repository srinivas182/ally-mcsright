<?php

declare(strict_types=1);

namespace Modules\Core\Switches\Http;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Switches\Areas;
use Modules\Core\Switches\FeatureSwitches;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages in a switched-off area answer "not found" (FL1, specs/core/16): the area does not exist for this brokerage.
 * Pages in a seat-limited area answer "forbidden" with a plain reason for people without a seat.
 */
final class EnsureAreaOn
{
    public function __construct(private readonly Areas $areas, private readonly FeatureSwitches $switches) {}

    public function handle(Request $request, Closure $next): Response
    {
        $area = $this->areas->forPath('/'.$request->path());
        if ($area === null) {
            return $next($request);
        }
        $user = $request->user();
        if (! $this->switches->on($area)) {
            abort_if(! $user instanceof User || ! $user->isKind(UserKind::HqStaff), 404);
        }
        if ($user instanceof User && ! $this->switches->allows($user, $area)) {
            abort(403, 'You need a seat for '.($this->areas->get($area)['label'] ?? 'this').'. Ask your broker to give you one.');
        }

        return $next($request);
    }
}
