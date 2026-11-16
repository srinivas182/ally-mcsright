<?php

declare(strict_types=1);

namespace Modules\Movewise\Console;

use Illuminate\Console\Command;
use Modules\Movewise\Launch\Readiness;
use Modules\Movewise\Models\State;

/** Prints each state's launch checks (specs/movewise/08). Exits 1 when the asked-for state isn't ready. */
final class LaunchCheck extends Command
{
    protected $signature = 'movewise:launch-check {state? : Two-letter state code; all states when left out}';

    protected $description = 'Show the Movewise launch checks for a state, green or red';

    public function handle(Readiness $readiness): int
    {
        $code = $this->argument('state');
        $states = State::query()->when(is_string($code), fn ($q) => $q->where('code', strtoupper((string) $code)))->orderBy('code')->get();
        $ok = true;
        foreach ($states as $s) {
            $this->line('<options=bold>'.$s->name.'</>'.($s->on ? ' (live)' : ''));
            foreach ($readiness->for($s) as $c) {
                $this->line(($c['ok'] ? '  <fg=green>✓</> ' : '  <fg=red>✗</> ').$c['check'].': '.$c['detail']);
            }
            $ok = $ok && $readiness->ready($s);
        }

        return is_string($code) && ! $ok ? self::FAILURE : self::SUCCESS;
    }
}
