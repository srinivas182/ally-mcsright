<?php

declare(strict_types=1);

namespace Modules\Connect\Webhooks;

/**
 * Each delivery is signed so the receiver can check it came from RightAlly and wasn't changed or replayed:
 * header X-RightAlly-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>" with the endpoint's secret>.
 */
final class Signature
{
    public static function header(string $secret, string $body, int $time): string
    {
        return 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$body, $secret);
    }

    public static function verify(string $secret, string $body, string $header, int $now, int $tolerance = 300): bool
    {
        if (preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $m) !== 1 || abs($now - (int) $m[1]) > $tolerance) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $m[1].'.'.$body, $secret), $m[2]);
    }
}
