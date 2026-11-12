<?php

declare(strict_types=1);

namespace Modules\Compensation;

use App\Models\User;
use Modules\Compensation\Models\Calculation;

/**
 * Field-level hiding (specs/core/03, addition 3): commission amounts are shown only to people with
 * "See commission amounts and earnings", and each agent always sees their own line. Everyone else
 * sees who is on the deal and the plan, with amounts hidden.
 */
final class CalculationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(Calculation $calculation, User $viewer): array
    {
        $all = $viewer->can('finance.amounts.view');
        $result = $calculation->result;
        $agents = [];
        foreach ((array) ($result['agents'] ?? []) as $row) {
            /** @var array<string, mixed> $row */
            $own = (int) $row['user_id'] === $viewer->id;
            $agents[] = $all || $own ? $row + ['hidden' => false] : [
                'user_id' => $row['user_id'], 'plan' => $row['plan'], 'capped' => $row['capped'] ?? false, 'excluded' => $row['excluded'] ?? false, 'hidden' => true,
            ];
        }

        return [
            'ulid' => $calculation->ulid, 'deal_ref' => $calculation->deal_ref, 'status' => $calculation->status, 'version' => $calculation->version,
            'gci_cents' => $all ? $result['gci_cents'] ?? null : null,
            'agents' => $agents,
            'steps' => $all ? $calculation->steps : null,
            'amounts_hidden' => ! $all,
        ];
    }
}
