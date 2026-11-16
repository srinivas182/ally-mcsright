<?php

declare(strict_types=1);

namespace Modules\Transactions\DealIq;

use App\Models\User;
use Modules\Transactions\Models\Deal;

/**
 * Contract and addendum drafting (specs/ai/02, §3), provided by the Forms module: an instruction such as "a 3-day
 * extension of the inspection period" becomes a draft forms packet on the brokerage's own licensed form, filled from
 * the deal, for the agent to check and send for e-signature.
 */
interface FormDrafter
{
    /**
     * @return array{packet: string, form: string, filled: array<string, string>, flags: list<string>}|null null when no form fits
     */
    public function draft(Deal $deal, string $instruction, User $by): ?array;
}
