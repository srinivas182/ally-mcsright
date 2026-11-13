<?php

declare(strict_types=1);

namespace Modules\Forms;

use Illuminate\Validation\ValidationException;

/**
 * Wire-fraud protection (specs/forms/01, addition 4): forms never carry wiring instructions. Text that looks
 * like bank routing or account details is refused, wherever it is typed.
 */
final class Guard
{
    public static function noWiringInstructions(string $text, string $field): void
    {
        $lower = mb_strtolower($text);
        $mentionsBank = preg_match('/\b(routing|aba|swift|iban|account (number|no\.?|#)|acct\.? ?(no|#)|wire (to|instructions)|beneficiary bank)\b/', $lower) === 1;
        $hasRoutingLike = preg_match('/\b\d{9}\b/', $text) === 1;
        $hasAccountLike = preg_match('/\b\d{8,17}\b/', $text) === 1;
        if ($mentionsBank && ($hasRoutingLike || $hasAccountLike)) {
            throw ValidationException::withMessages([$field => 'Forms never carry wiring instructions. Remove the bank details; payment instructions are confirmed by phone, never in documents.']);
        }
    }
}
