<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Modules\Billing\BrokeragePayments;
use Modules\Core\Identity\Enums\UserKind;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the brokerage's fee is unpaid past its grace period, owners and staff can look but not change
 * anything (except billing, to fix it). Agents who pay for themselves are not affected.
 */
final class BrokerageReadOnly
{
    private const ALLOWED = ['settings/billing', 'logout', 'user/', 'two-factor', 'impersonate/stop'];

    public function __construct(private readonly BrokeragePayments $payments) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($request->isMethodSafe() || ! $user instanceof User || ! $user->isKind(UserKind::Owner, UserKind::Staff) || ! $this->payments->readOnly()) {
            return $next($request);
        }
        foreach (self::ALLOWED as $prefix) {
            if (str_starts_with($request->path(), $prefix)) {
                return $next($request);
            }
        }

        return redirect('/settings/billing')->withErrors(['billing' => 'Your RightAlly subscription is unpaid, so changes are paused. Update the card to continue.']);
    }
}
