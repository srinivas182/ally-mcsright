<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Modules\AI\Evals\EvalRunner;
use Modules\AI\Evals\EvalSet;
use Modules\AI\Evals\PromptLock;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * AI test sets (AQ1, specs/ai/08). --offline checks the sets and example answers with no model calls; --lock
 * refreshes the prompt lock after a prompt changes; otherwise runs every case live in HQ within the budget.
 */
final class RunEvals extends Command
{
    protected $signature = 'ai:eval {--prompt=* : Only these prompt keys} {--offline : No model calls} {--lock : Refresh the prompt lock} {--budget-cents= : Maximum spend in cents}';

    protected $description = 'Run the AI test sets';

    public function handle(EvalRunner $runner, PromptRegistry $prompts): int
    {
        if ($this->option('lock')) {
            PromptLock::write($prompts);
            $this->info('Prompt lock refreshed.');

            return self::SUCCESS;
        }
        /** @var list<string> $only */
        $only = (array) $this->option('prompt');
        if ($this->option('offline')) {
            $problems = [];
            foreach (EvalSet::all() as $set) {
                if ($only === [] || in_array($set->prompt, $only, true)) {
                    $problems = array_merge($problems, $runner->offline($set));
                }
            }
            foreach ($problems as $p) {
                $this->error($p);
            }
            $this->info(count(EvalSet::all()).' test set(s) checked, '.count($problems).' problem(s).');

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }
        $hq = Tenant::query()->where('type', TenantType::Hq)->first();
        if ($hq === null) {
            $this->error('RightAlly HQ tenant not found.');

            return self::FAILURE;
        }
        $cents = $this->option('budget-cents') !== null ? (int) $this->option('budget-cents') : (int) config('ai.eval_budget_cents', 300);
        $run = $runner->live($hq, $cents * 1_000_000, $only === [] ? null : $only);
        $this->line("Eval run {$run['ulid']}: {$run['status']}, {$run['passed']} of {$run['cases']} cases passed, cost $".number_format($run['cost_microcents'] / 100_000_000, 2));

        return $run['status'] === 'failures' ? self::FAILURE : self::SUCCESS;
    }
}
