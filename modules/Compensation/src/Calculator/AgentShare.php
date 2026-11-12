<?php

declare(strict_types=1);

namespace Modules\Compensation\Calculator;

/**
 * One agent on a deal side, with their share of the side and their cap-year progress so far
 * (tiered splits, the cap and the post-cap fee cap).
 */
final readonly class AgentShare
{
    /**
     * @param  array<string, int>  $feesPaidCents  fee code => paid this calendar year
     */
    public function __construct(
        public int $userId,
        public string $sharePercent = '100',
        public int $ytdCompanyDollarCents = 0,
        public int $ytdUnits = 0,
        public int $capPaidCents = 0,
        public int $postCapFeesPaidCents = 0,
        public array $feesPaidCents = [],   // fee code => paid this calendar year (yearly fee caps)
        public int $creditCents = 0,        // credit balance that can pay this agent's fees
        public ?int $teamLeaderUserId = null,
        public ?string $teamSplitPercent = null, // team lead's share of the member's side after the brokerage split
    ) {}
}
