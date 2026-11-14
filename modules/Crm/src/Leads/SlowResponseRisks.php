<?php

declare(strict_types=1);

namespace Modules\Crm\Leads;

use Carbon\CarbonImmutable;
use Modules\Core\Risk\RiskSource;

/** Broker risk register (protect/01): agents below the brokerage's speed-to-lead target over the last 30 days (5+ leads). */
final class SlowResponseRisks implements RiskSource
{
    public function risks(): array
    {
        $reports = app(LeadReports::class);
        $card = $reports->scorecard(CarbonImmutable::now()->subDays(30), CarbonImmutable::now());
        $t = $card['targets'];
        $out = [];
        foreach ($card['agents'] as $a) {
            if ($a['leads'] >= 5 && $a['within_target'] !== null && $a['within_target'] < $t['target_percent']) {
                $out[] = ['level' => $a['within_target'] < intdiv($t['target_percent'], 2) ? 'high' : 'medium', 'category' => 'Leads',
                    'title' => "{$a['name']} reached {$a['within_target']}% of leads within {$t['target_minutes']} minutes in the last 30 days (target {$t['target_percent']}%).",
                    'deal' => null, 'street' => null, 'owner' => (string) $a['name'], 'due' => null, 'link' => '/leads/report'];
            }
        }

        return $out;
    }
}
