<?php

declare(strict_types=1);

namespace Modules\Connect;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Connect\Models\Connection;
use Modules\Connect\Notifications\ConnectionNeedsAttention;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Identity\Enums\UserKind;

/**
 * How a connection is doing (specs/connect/01): a success clears errors; failures are counted, and after three in a
 * row the connection shows an error and its owner hears about it (at most once a day). "Needs reconnect" (a revoked
 * or expired sign-in) is shown and alerted at once.
 */
final class Health
{
    public const ALERT_AFTER = 3;

    public function succeeded(Connection $c): void
    {
        $c->forceFill(['failures' => 0, 'last_error' => null, 'status' => in_array($c->status, ['error', 'needs_reconnect'], true) ? 'connected' : $c->status])->save();
    }

    public function failed(Connection $c, string $error, bool $reconnect = false): void
    {
        $failures = $c->failures + 1;
        $status = $reconnect ? 'needs_reconnect' : ($failures >= self::ALERT_AFTER ? 'error' : $c->status);
        $c->forceFill(['failures' => $failures, 'last_error' => mb_substr($error, 0, 500), 'status' => $status])->save();
        if (($reconnect || $failures >= self::ALERT_AFTER) && ($c->alerted_at === null || $c->alerted_at->lt(CarbonImmutable::now()->subDay()))) {
            $c->forceFill(['alerted_at' => now()])->save();
            $app = app(AppCatalog::class)->find($c->app_key);
            foreach ($this->people($c) as $user) {
                $user->notify(new ConnectionNeedsAttention($app !== null ? $app->name : $c->app_key, $c->label, $error, $reconnect, url('/apps/'.$c->app_key)));
            }
        }
    }

    /** @return list<User> the connection's owner, or the brokerage's owners for brokerage connections */
    private function people(Connection $c): array
    {
        if ($c->owner_user_id !== null) {
            $u = User::query()->find($c->owner_user_id);

            return $u instanceof User && $u->isActive() ? [$u] : [];
        }

        return array_values(User::query()->where('kind', UserKind::Owner)->get()->filter(fn (User $u): bool => $u->isActive())->all());
    }
}
