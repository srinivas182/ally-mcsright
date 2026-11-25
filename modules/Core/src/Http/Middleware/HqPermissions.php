<?php

declare(strict_types=1);

namespace Modules\Core\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Modules\Core\Access\HqAccess;
use Modules\Core\Access\HqRoles;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * HQ2: in the HQ console, every page checks the HQ staff member's role: looking needs "see", changing needs "change".
 * A page someone may not use is refused (403), not just left out of their menu.
 */
final class HqPermissions
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->kind !== UserKind::HqStaff || ! $this->context->check() || ! $this->context->current()->isHq()) {
            return $next($request);
        }
        if (! HqAccess::rolesOn()) {
            app(HqRoles::class)->ensure(); // the first time HQ is used after this release
        }
        $needed = HqAccess::required($request->path(), $request->method());
        if ($needed !== null && ! HqAccess::allows($user, $needed)) {
            abort(403, 'Your HQ role does not include this. Ask a Super admin.');
        }

        return $next($request);
    }
}
