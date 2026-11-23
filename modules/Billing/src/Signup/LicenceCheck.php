<?php

declare(strict_types=1);

namespace Modules\Billing\Signup;

use Modules\Billing\PaymentSettings;
use Modules\Roster\Verification\ServiceVerifier;
use Throwable;

/**
 * LV1: checks the real estate licence given at sign-up against the state's own public licence records, which RightAlly
 * downloads every day (roster:licence-sync). Only an active licence can buy.
 *
 * - Found and active: confirmed. A different last name is let through but marked for HQ to look at (married names).
 * - Not found, expired, suspended or inactive: stopped, with a plain message.
 * - A state whose records RightAlly does not have yet: let through and marked for HQ to check by hand.
 * - While payments are in test mode, the number "test" passes, so HQ can try sign-up end to end.
 */
final class LicenceCheck
{
    public function __construct(private readonly PaymentSettings $payments) {}

    /**
     * @return array{state: string, number: string, status: string, method: string, review: bool, error: string|null, checked_at: string}
     */
    public function check(string $state, string $stateName, string $number, string $ownerName): array
    {
        $state = strtoupper($state);
        $number = mb_substr(trim($number), 0, 40);
        $out = fn (string $status, string $method, bool $review, ?string $error = null): array => ['state' => $state, 'number' => $number, 'status' => $status,
            'method' => $method, 'review' => $review, 'error' => $error, 'checked_at' => now()->toIso8601String()];
        if (mb_strtolower($number) === 'test' && $this->payments->summary()['mode'] === 'test') {
            return $out('test', 'test', false);
        }
        if (preg_match('/^[A-Za-z0-9 .\-]{3,40}$/', $number) !== 1) {
            return $out('invalid', 'none', false, "Type your {$stateName} licence number as it appears on your licence.");
        }
        try {
            $last = (string) collect(preg_split('/\s+/', trim($ownerName)) ?: [])->last();
            $found = app(ServiceVerifier::class)->lookup($state, $number, $last);
        } catch (Throwable $e) {
            report($e);

            return $out('unchecked', 'state_data', true);
        }

        return match ($found['status']) {
            'active' => $out('active', 'state_data', $found['last_name'] !== null && $last !== ''
                && ! str_contains(mb_strtolower($found['last_name']), mb_strtolower($last)) && ! str_contains(mb_strtolower($last), mb_strtolower($found['last_name']))),
            'unknown' => $out('unchecked', 'none', true),
            'not_found' => $out('not_found', 'state_data', false, "We could not find {$stateName} licence {$number} in the state's records. Check the number on your licence, or contact us if it was issued in the last few days."),
            default => $out((string) $found['status'], 'state_data', false, "{$stateName} licence {$number} is not active in the state's records (it shows as {$found['status']}). An active licence is needed to sign up."),
        };
    }
}
