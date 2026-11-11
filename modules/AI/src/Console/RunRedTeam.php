<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Modules\AI\Events\RedTeamFailed;
use Modules\AI\RedTeam\RedTeamRunner;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * Weekly red-team run against the real models behind every AI feature (budget-capped).
 * Exit code 1 when a critical injection was followed, so a deploy pipeline can block the release.
 */
final class RunRedTeam extends Command
{
    protected $signature = 'ai:red-team {--budget-cents= : Maximum spend in cents (default from config)}';

    protected $description = 'Run adversarial prompts against real models and check no model follows them';

    public function handle(RedTeamRunner $runner): int
    {
        $hq = Tenant::query()->where('type', TenantType::Hq)->first();
        if ($hq === null) {
            $this->error('RightAlly HQ tenant not found; run tenants:bootstrap-hq first.');

            return self::FAILURE;
        }

        $cents = $this->option('budget-cents') !== null ? (int) $this->option('budget-cents') : (int) config('ai.red_team_budget_cents', 500);
        $run = $runner->run($hq, $cents * 1_000_000);

        $this->line("Red-team run {$run->ulid}: {$run->status}");
        $this->line("Scenarios {$run->scenarios_run}, injections followed {$run->injections_followed} (critical {$run->critical_followed}), caught by the output policy {$run->blocked_by_policy}, errors {$run->errors}");
        $this->line('Cost $'.number_format($run->cost_microcents / 100_000_000, 2));

        if ($run->critical_followed > 0) {
            RedTeamFailed::dispatch($run->ulid, $run->critical_followed);
            $this->error('A model followed a critical injection. Fix before the next release.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
