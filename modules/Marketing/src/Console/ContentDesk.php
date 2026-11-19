<?php

declare(strict_types=1);

namespace Modules\Marketing\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Marketing\Content\Cadence;
use Modules\Marketing\Content\IndexStats;
use Modules\Marketing\Content\Topics;
use Modules\Marketing\Content\Writer;

/**
 * MK3, every night: fetch each HQ desk's Search Console numbers, move desks up or down a level, then write the day's
 * drafts for the morning review. Brokerage and agent desks (MK4) run in their own command.
 */
final class ContentDesk extends Command
{
    protected $signature = 'content:desk';

    protected $description = 'HQ content desk: index numbers, levels, and the day\'s drafts for review';

    public function handle(IndexStats $stats, Writer $writer): int
    {
        $desks = fn () => DB::connection(Connections::control())->table('content_desks')->where('tenant_id', 0)->where('mode', '!=', 'off')->get();
        foreach ($desks() as $desk) {
            try {
                $stats->refresh($desk);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $changed = Cadence::review();
        $written = 0;
        foreach ($desks() as $desk) {
            try {
                $written += $writer->writeFor($desk, Topics::HQ, 'RightAlly (software for real estate brokerages and agents)', 'real estate broker owners and agents');
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info(count($changed).' level change(s); '.$written.' draft(s) ready for review.');

        return self::SUCCESS;
    }
}
