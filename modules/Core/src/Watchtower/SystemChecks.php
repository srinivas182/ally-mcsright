<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Support\Connections;
use Throwable;

/**
 * Every minute (OB1): is each part of RightAlly working? Each database (control, brokerage cells, listings, audit),
 * the cache, file storage, the queue (not backed up), and every scheduled task (ran when it should have). A failure
 * opens a problem; a recovery closes it and says so.
 */
final readonly class SystemChecks
{
    /** The oldest waiting job may be this old before the queue counts as backed up. */
    public const QUEUE_MAX_AGE_SECONDS = 600;

    public const QUEUE_MAX_SIZE = 5000;

    /** A scheduled task this late counts as missed (allows for slow servers and one-server hand-offs). */
    public const SCHEDULE_GRACE_MINUTES = 10;

    public function __construct(private Recorder $recorder, private Alerts $alerts) {}

    /** @return array<string, array{ok: bool, message: string|null}> */
    public function run(): array
    {
        $results = [];
        foreach ($this->checks() as $name => $check) {
            $start = hrtime(true);
            try {
                $message = $check();
            } catch (Throwable $e) {
                $message = Mask::text($e->getMessage(), 200) ?: 'Failed';
            }
            $ok = $message === null;
            $this->store($name, $ok, $message, (int) round((hrtime(true) - $start) / 1e6));
            $results[$name] = ['ok' => $ok, 'message' => $message];
            if ($ok) {
                $cleared = $this->recorder->clear('check', $name);
                if ($cleared !== null) {
                    $this->alerts->recovered($cleared);
                }
            } else {
                $this->recorder->record(new Signal('check', $name, self::label($name).': '.$message, severity: str_starts_with($name, 'queue') ? 'high' : 'critical',
                    message: $message, location: 'system check '.$name, surface: 'system', ongoing: true));
            }
        }
        $this->missedTasks();

        return $results;
    }

    /** @return array<string, callable(): ?string> null = fine, otherwise what is wrong */
    private function checks(): array
    {
        $checks = [];
        $seen = [];
        foreach (['control' => Connections::control(), 'cell' => Connections::cell(), 'listings' => Connections::listings(), 'audit' => Connections::audit()] as $role => $connection) {
            $name = $connection ?? (string) config('database.default');
            if (isset($seen[$name])) {
                continue; // the same database under another role
            }
            $seen[$name] = true;
            $checks['database:'.$role] = function () use ($connection): ?string {
                DB::connection($connection)->select('select 1');

                return null;
            };
        }
        $checks['cache'] = function (): ?string {
            $key = 'watchtower:probe:'.Str::random(8);
            Cache::put($key, 'ok', 30);
            $back = Cache::get($key);
            Cache::forget($key);

            return $back === 'ok' ? null : 'The cache does not keep what is written';
        };
        $checks['storage'] = function (): ?string {
            $disk = Storage::disk((string) config('filesystems.default'));
            $path = 'watchtower/probe-'.Str::random(8).'.txt';
            $disk->put($path, 'ok');
            $back = $disk->get($path);
            $disk->delete($path);

            return $back === 'ok' ? null : 'Files cannot be written and read back';
        };
        $connection = (string) config('queue.default');
        if (! in_array($connection, ['sync', 'null'], true)) {
            $checks['queue'] = fn (): ?string => $this->backlog();
        }

        return $checks;
    }

    private function backlog(): ?string
    {
        $q = app(QueueFactory::class)->connection();
        $size = $q->size();
        $oldest = $q->creationTimeOfOldestPendingJob();
        $age = is_int($oldest) ? now()->getTimestamp() - $oldest : 0;
        if ($age > self::QUEUE_MAX_AGE_SECONDS || $size > self::QUEUE_MAX_SIZE) {
            return "{$size} jobs waiting".($age > 0 ? ', the oldest for '.intdiv($age, 60).' minutes' : '').'. Are the queue workers running?';
        }

        return null;
    }

    /** Scheduled tasks that have run before but missed their latest time. */
    private function missedTasks(): void
    {
        $beats = Settings::db()->table('watchtower_heartbeats')->get()->keyBy('task');
        foreach (app(Schedule::class)->events() as $event) {
            $task = Heartbeats::task($event);
            $beat = $beats[$task] ?? null;
            if ($beat === null || $beat->last_started_at === null) {
                continue; // never seen yet (a new task, or a fresh install): nothing to compare
            }
            try {
                $tz = $event->timezone instanceof DateTimeZone ? $event->timezone->getName() : (string) ($event->timezone ?: config('app.timezone'));
                $due = CarbonImmutable::instance((new CronExpression($event->expression))->getPreviousRunDate(now($tz)->toDateTimeImmutable(), 0, true));
            } catch (Throwable) {
                continue;
            }
            $last = CarbonImmutable::parse((string) $beat->last_started_at, (string) config('app.timezone'));
            $group = 'missed:'.$task;
            if ($last->lessThan($due->subMinute()) && now()->greaterThan($due->addMinutes(self::SCHEDULE_GRACE_MINUTES))) {
                $this->recorder->record(new Signal('scheduler', $group, "Scheduled task \"{$task}\" did not run at ".$due->setTimezone('America/Chicago')->format('g:i A').' Central',
                    severity: 'high', message: 'Last ran '.$last->setTimezone('America/Chicago')->format('M j g:i A').' Central. Is the scheduler running on one server?',
                    location: "command {$task}", surface: 'console', ongoing: true));
            } elseif (! $last->lessThan($due->subMinute())) {
                $cleared = $this->recorder->clear('scheduler', $group);
                if ($cleared !== null) {
                    $this->alerts->recovered($cleared);
                }
            }
        }
    }

    private function store(string $name, bool $ok, ?string $message, int $ms): void
    {
        $db = Settings::db();
        $was = $db->table('watchtower_checks')->where('name', $name)->first();
        $db->table('watchtower_checks')->updateOrInsert(['name' => $name], [
            'ok' => $ok, 'message' => $message === null ? null : mb_substr($message, 0, 255), 'duration_ms' => $ms, 'checked_at' => now(),
            'failing_since' => $ok ? null : ($was !== null && ! $was->ok ? $was->failing_since : now()),
        ]);
    }

    public static function label(string $name): string
    {
        return match (true) {
            $name === 'cache' => 'Cache',
            $name === 'storage' => 'File storage',
            $name === 'queue' => 'Background jobs backed up',
            str_starts_with($name, 'database:') => ucfirst(substr($name, 9)).' database',
            default => Str::headline($name),
        };
    }

    /**
     * For the HQ page: the latest result of every check.
     *
     * @return list<array{name: string, label: string, ok: bool, message: string|null, checked_at: string, failing_since: string|null}>
     */
    public static function latest(): array
    {
        return array_values(Settings::db()->table('watchtower_checks')->orderBy('name')->get()->map(fn (object $c): array => ['name' => (string) $c->name, 'label' => self::label((string) $c->name),
            'ok' => (bool) $c->ok, 'message' => $c->message === null ? null : (string) $c->message, 'checked_at' => (string) $c->checked_at,
            'failing_since' => $c->failing_since === null ? null : (string) $c->failing_since])->all());
    }
}
