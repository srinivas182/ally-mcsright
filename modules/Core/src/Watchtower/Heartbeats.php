<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower;

use DateTimeZone;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Str;
use Throwable;

/**
 * Scheduled tasks report in (OB1): when each started and finished, and whether it worked, so a task that fails or
 * stops running is noticed. Watchtower's own every-minute check is the scheduler's pulse (/up/scheduler).
 */
final readonly class Heartbeats
{
    public const PULSE = 'watchtower:check';

    public function __construct(private Recorder $recorder) {}

    /** "crm:score" — the artisan command, without the PHP binary path. */
    public static function task(Event $event): string
    {
        $command = (string) $event->command;
        $task = $command !== '' ? trim(Str::after($command, "'artisan' ")) : (string) ($event->description ?: 'closure');

        return mb_substr($task, 0, 120);
    }

    public function starting(ScheduledTaskStarting $e): void
    {
        $this->beat($e->task, ['last_started_at' => now(), 'last_status' => 'running']);
    }

    public function finished(ScheduledTaskFinished $e): void
    {
        $failed = $e->task->exitCode !== null && $e->task->exitCode !== 0;
        $this->beat($e->task, ['last_finished_at' => now(), 'last_status' => $failed ? 'failed' : 'ok', 'last_runtime_ms' => (int) round($e->runtime * 1000)]);
        if ($failed) {
            $task = self::task($e->task);
            $this->recorder->record(new Signal('scheduler', 'failed:'.$task, "Scheduled task \"{$task}\" failed (exit code {$e->task->exitCode})", severity: 'high',
                location: "command {$task}", surface: 'console'));
        }
    }

    public function failed(ScheduledTaskFailed $e): void
    {
        $this->beat($e->task, ['last_finished_at' => now(), 'last_status' => 'failed']);
        $this->recorder->record(Signal::fromThrowable($e->exception, 'scheduler', 'command '.self::task($e->task), 'console', severity: 'high'));
    }

    /** When the scheduler last ran Watchtower's every-minute check, if ever. */
    public static function pulse(): ?string
    {
        try {
            $at = Settings::db()->table('watchtower_heartbeats')->where('task', self::PULSE)->value('last_started_at');

            return is_string($at) ? $at : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $values */
    private function beat(Event $event, array $values): void
    {
        try {
            Settings::db()->table('watchtower_heartbeats')->updateOrInsert(['task' => self::task($event)], [
                ...$values, 'expression' => mb_substr($event->expression, 0, 60),
                'timezone' => $event->timezone instanceof DateTimeZone ? $event->timezone->getName() : (is_string($event->timezone) ? $event->timezone : null),
            ]);
        } catch (Throwable) {
            // never let watching a task break the task
        }
    }
}
