<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;

/** Daily: "Your AI Assistant" conversations are kept 30 days, then deleted (specs/know/03). */
#[CrossTenantAccess('Delete assistant conversations older than 30 days for every brokerage')]
final class PruneAssistant extends Command
{
    protected $signature = 'ai:assistant-prune';

    protected $description = 'Delete Your AI Assistant conversations older than 30 days';

    public function handle(): int
    {
        $n = DB::connection(Connections::cell())->table('ai_assistant_turns')->where('created_at', '<', now()->subDays(30))->delete();
        $this->info("{$n} turn(s) deleted.");

        return self::SUCCESS;
    }
}
