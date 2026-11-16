<?php

declare(strict_types=1);

namespace Modules\Core\Connect;

/**
 * A module that can take an app webhook part no brokerage connection owns (tagged `connect.unrouted`). Used for
 * RightAlly's own accounts acting for a brokerage, such as lead forms on RightAlly Boost ads (specs/ads/04): the
 * handler finds the brokerage itself and must hand the part only to it.
 */
interface UnroutedWebhook
{
    /**
     * @param  array<string, mixed>  $payload  one routed part's payload
     * @return bool whether it was taken
     */
    public function take(string $app, array $payload): bool;
}
