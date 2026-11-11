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
