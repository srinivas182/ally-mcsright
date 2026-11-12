<?php

declare(strict_types=1);

namespace Modules\Compensation\Calculator;

use Modules\Ledger\Money\Money;

final readonly class AgentCalculation
{
    /**
     * @param  list<DeductionLine>  $preSplit
     * @param  list<DeductionLine>  $postSplit
     * @param  list<string>  $steps
     * @param  list<DeductionLine>  $brokerageFees
     */
    public function __construct(
        public int $userId,
        public string $planName,
        public ?int $planVersionId,
        public Money $share,
        public array $preSplit,
        public Money $splitBase,
        public string $splitSource,
        public Money $companyDollar,
        public Money $agentGross,
        public array $postSplit,
        public Money $agentNet,
        public array $steps,
        public ?Money $postCapFee = null,
        public ?int $capAmountCents = null,
        public int $capBeforeCents = 0,
        public int $capAfterCents = 0,
        public bool $capped = false,
        public array $brokerageFees = [],   // list<DeductionLine> paid from company dollar
        public ?Money $creditUsed = null,    // credit that paid the agent's fees (from company dollar)
        public ?int $teamLeaderUserId = null,
        public ?Money $teamSplit = null,
        public int $countedFeesCents = 0,    // fees that count toward the cap
        public bool $excluded = false,
    ) {}

    public function preSplitTotal(): Money
    {
        return array_reduce($this->preSplit, fn (Money $sum, DeductionLine $d): Money => $sum->add($d->amount), Money::zero($this->share->currency));
    }

    public function postSplitTotal(): Money
    {
        return array_reduce($this->postSplit, fn (Money $sum, DeductionLine $d): Money => $sum->add($d->amount), Money::zero($this->share->currency));
    }
}
