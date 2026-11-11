<?php

declare(strict_types=1);

namespace Modules\Compensation\Calculator;

use Carbon\CarbonImmutable;
use Modules\Compensation\Enums\DealClass;
use Modules\Compensation\Enums\Side;
use Modules\Ledger\Money\Money;

final readonly class DealSideInput
{
    /**
     * @param  list<AgentShare>  $agents  shares must add up to 100
     */
    public function __construct(
        public Money $gci,
        public int $salePriceCents,
        public CarbonImmutable $closingDate,
        public array $agents,
        public Side $side = Side::Listing,
        public DealClass $dealClass = DealClass::Sale,
        public ?string $leadSource = null,
        public ?string $outsideReferralPercent = null,
    ) {}
}
