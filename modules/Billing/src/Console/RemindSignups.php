<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Signup\SignupReminders;

/** SU1: reminds people who started signing up but did not pay (about an hour, then a day, later). */
final class RemindSignups extends Command
{
    protected $signature = 'signups:remind';

    protected $description = 'Remind people who started signing up but did not finish paying';

    public function handle(SignupReminders $reminders): int
    {
        $sent = 0;
        foreach ($reminders->due() as $signup) {
            $sent += $reminders->send($signup) ? 1 : 0;
        }
        $this->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
