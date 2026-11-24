<?php

declare(strict_types=1);

namespace Modules\Billing\Signup;

use Modules\Billing\PaymentSettings;
use Modules\Roster\Brokerage\BrokerageLicences;
use Modules\Roster\Verification\ServiceVerifier;
use Throwable;

/**
 * LV1/LV2: the licences given at sign-up, checked against the states' public records (BrokerageLicences::assess):
 * - active: confirmed; boards in that state can be chosen. A different last name is let through and marked for HQ.
 * - expired or inactive: let through with a plain message; no boards in that state; the website starts as a landing
 *   page and shows no licence for that state.
 * - suspended or revoked: stopped.
 * - not found: stopped, unless they tick "My licence is new" (HQ checks it by hand; no boards until it is confirmed).
 * - a state without records here: let through, boards allowed, marked for HQ to check by hand.
 * - "test" passes while payments are in test mode.
 */
final class LicenceCheck
{
    public function __construct(private readonly PaymentSettings $payments) {}

    /**
     * @return array{state: string, number: string, status: string, method: string, review: bool, error: string|null, notice: string|null, expires_on: string|null, checked_at: string}
     */
    public function check(string $state, string $stateName, string $number, string $ownerName, bool $isNew = false): array
    {
        $state = strtoupper($state);
        $number = mb_substr(trim($number), 0, 40);
        $found = BrokerageLicences::assess($state, $stateName, $number, $ownerName, $isNew, $this->payments->summary()['mode'] === 'test');
        $review = in_array($found['status'], ['unchecked', 'new'], true);
        if ($found['status'] === 'active') {
            try {
                $last = (string) collect(preg_split('/\s+/', trim($ownerName)) ?: [])->last();
                $onFile = app(ServiceVerifier::class)->lookup($state, $number, $last)['last_name'];
                $review = $onFile !== null && $last !== '' && ! str_contains(mb_strtolower($onFile), mb_strtolower($last)) && ! str_contains(mb_strtolower($last), mb_strtolower($onFile));
            } catch (Throwable) {
                $review = false;
            }
        }

        return ['state' => $state, 'number' => $number, 'status' => $found['status'], 'method' => match ($found['status']) {
            'test' => 'test', 'unchecked', 'new' => 'none', default => 'state_data'
        },
            'review' => $review, 'error' => $found['blocking'] ? $found['message'] : null, 'notice' => $found['blocking'] ? null : $found['message'],
            'expires_on' => $found['expires_on'], 'checked_at' => now()->toIso8601String()];
    }

    /** Whether boards in the licence's state can be chosen. */
    public static function allowsBoards(array $licence): bool
    {
        return in_array((string) ($licence['status'] ?? ''), ['active', 'test', 'unchecked'], true);
    }
}
