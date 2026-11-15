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
        public ?string $excludedReason = null, // excluded from plan progression and cap (audited)
        // Broker overrides for this one deal (audited, with a reason; specs/connect/12, C5a-3):
        public ?string $agentSplitPercent = null, // the agent's share of the split base, instead of the plan's split
        public bool $waiveFees = false,           // no post-split fees and no post-cap fee on this deal
        public bool $noRevenueShare = false,      // the deal pays no revenue share
        public ?string $overrideReason = null,
    ) {}

    public function overridden(): bool
    {
        return $this->agentSplitPercent !== null || $this->waiveFees || $this->noRevenueShare;
    }

    /**
     * @return array<string, mixed> stored with the calculation so it can be recalculated
     */
    public function toArray(): array
    {
        return [
            'gci_cents' => $this->gci->cents,
            'currency' => $this->gci->currency,
            'sale_price_cents' => $this->salePriceCents,
            'closing_date' => $this->closingDate->toDateString(),
            'agents' => array_map(fn (AgentShare $a): array => ['user_id' => $a->userId, 'share_percent' => $a->sharePercent], $this->agents),
            'side' => $this->side->value,
            'deal_class' => $this->dealClass->value,
            'lead_source' => $this->leadSource,
            'outside_referral_percent' => $this->outsideReferralPercent,
            'excluded_reason' => $this->excludedReason,
            'agent_split_percent' => $this->agentSplitPercent,
            'waive_fees' => $this->waiveFees,
            'no_revenue_share' => $this->noRevenueShare,
            'override_reason' => $this->overrideReason,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array{user_id: int, share_percent: string}> $agents */
        $agents = $data['agents'];

        return new self(
            Money::of((int) $data['gci_cents'], (string) $data['currency']),
            (int) $data['sale_price_cents'],
            CarbonImmutable::parse((string) $data['closing_date']),
            array_map(fn (array $a): AgentShare => new AgentShare((int) $a['user_id'], (string) $a['share_percent']), $agents),
            Side::from((string) $data['side']),
            DealClass::from((string) $data['deal_class']),
            isset($data['lead_source']) ? (string) $data['lead_source'] : null,
            isset($data['outside_referral_percent']) ? (string) $data['outside_referral_percent'] : null,
            excludedReason: isset($data['excluded_reason']) ? (string) $data['excluded_reason'] : null,
            agentSplitPercent: isset($data['agent_split_percent']) ? (string) $data['agent_split_percent'] : null,
            waiveFees: (bool) ($data['waive_fees'] ?? false),
            noRevenueShare: (bool) ($data['no_revenue_share'] ?? false),
            overrideReason: isset($data['override_reason']) ? (string) $data['override_reason'] : null,
        );
    }
}
