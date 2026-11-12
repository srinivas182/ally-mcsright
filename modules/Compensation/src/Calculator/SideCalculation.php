<?php

declare(strict_types=1);

namespace Modules\Compensation\Calculator;

use Modules\Ledger\Money\Money;

final readonly class SideCalculation
{
    /**
     * @param  list<AgentCalculation>  $agents
     * @param  list<string>  $steps
     */
    public function __construct(
        public Money $gci,
        public Money $outsideReferral,
        public array $agents,
        public array $steps,
    ) {}

    /**
     * Every cent of the GCI, by where it went. Always equals the GCI.
     */
    public function accountedFor(): Money
    {
        $total = $this->outsideReferral;
        foreach ($this->agents as $agent) {
            $total = $total->add($agent->preSplitTotal())->add($agent->companyDollar)->add($agent->postSplitTotal())->add($agent->agentNet);
            if ($agent->postCapFee !== null) {
                $total = $total->add($agent->postCapFee);
            }
            if ($agent->teamSplit !== null) {
                $total = $total->add($agent->teamSplit);
            }
            if ($agent->creditUsed !== null) {
                // Credit is company money moved to the agent: it is already inside agent net.
                $total = $total->subtract($agent->creditUsed);
            }
        }

        return $total;
    }

    /**
     * @return list<string>
     */
    public function explanation(): array
    {
        $lines = $this->steps;
        foreach ($this->agents as $agent) {
            array_push($lines, ...$agent->steps);
        }

        return $lines;
    }
}
