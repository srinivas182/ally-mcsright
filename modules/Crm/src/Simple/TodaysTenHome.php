<?php

declare(strict_types=1);

namespace Modules\Crm\Simple;

use App\Models\User;
use Modules\Billing\Entitlements;
use Modules\Core\Simple\HomePeople;
use Modules\Crm\Inbox\SendGuard;
use Modules\Crm\TodaysTen;

/**
 * SX4: "Today's 10" for the simple home: who to call and why, with big Call and Text buttons.
 *
 * - Call shows only when the person may be called (not on the do-not-call list).
 * - Text shows only when they agreed to texts (TCPA: opt-in only), and only between 8am and 9pm where they are;
 *   outside those hours the button says when it can be used.
 * - Needs the plan that includes Today's 10 (crm.intelligence), like the Today's 10 page.
 */
final class TodaysTenHome implements HomePeople
{
    public function __construct(private readonly TodaysTen $today, private readonly Entitlements $entitlements) {}

    public function for(User $user): array
    {
        if (! $this->entitlements->allows($user, 'crm.intelligence')) {
            return [];
        }

        return array_map(function (array $p): array {
            $phone = is_string($p['phone']) && $p['phone'] !== '' ? $p['phone'] : null;
            $wait = $phone !== null && $p['can_text'] ? SendGuard::quietHours($phone) : null;

            return ['ulid' => (string) $p['ulid'], 'name' => (string) $p['name'], 'reason' => (string) $p['reason'], 'phone' => $phone,
                'call' => $phone !== null && (bool) $p['can_call'], 'text' => $phone !== null && (bool) $p['can_text'] && $wait === null, 'text_wait' => $wait];
        }, $this->today->for($user, 10));
    }
}
