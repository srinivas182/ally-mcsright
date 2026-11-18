<?php

declare(strict_types=1);

namespace Modules\Core\Launch;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * Sprint 63 (launch readiness): is this server set up for real customers? Each check says what it found and, when
 * it fails, what to change, in plain words. Run with `php artisan launch:check` on every production server.
 */
final class LaunchReadiness
{
    /** Minutes within which the scheduler must have run (it writes a heartbeat every minute). */
    public const SCHEDULER_MINUTES = 5;

    public const HEARTBEAT = 'core:scheduler-heartbeat';

    /**
     * @return list<array{check: string, ok: bool, detail: string}>
     */
    public function checks(): array
    {
        $env = (string) config('app.env');
        $url = (string) config('app.url');
        $checks = [
            $this->check('Running as production', $env === 'production', "APP_ENV is \"{$env}\".", 'Set APP_ENV=production.'),
            $this->check('Error details hidden', config('app.debug') !== true, 'APP_DEBUG is '.(config('app.debug') === true ? 'on' : 'off').'.', 'Set APP_DEBUG=false: error pages would show code and settings.'),
            $this->check('Encryption key set', is_string(config('app.key')) && strlen((string) config('app.key')) >= 32, 'APP_KEY is set.', 'Run php artisan key:generate once, and keep the key safe.'),
            $this->check('HTTPS address', str_starts_with($url, 'https://'), "APP_URL is {$url}.", 'Set APP_URL to the https:// address.'),
            $this->check('Secure session cookie', config('session.secure') === true, 'Session cookies are '.(config('session.secure') === true ? '' : 'not ').'https-only.', 'Set SESSION_SECURE_COOKIE=true.'),
            $this->check('Shared sessions', in_array(config('session.driver'), ['redis', 'database'], true), 'Sessions: '.config('session.driver').'.',
                'Set SESSION_DRIVER=redis, so every server sees the same sign-ins.'),
            $this->check('Shared cache', in_array(config('cache.default'), ['redis', 'database', 'memcached', 'dynamodb'], true), 'Cache: '.config('cache.default').'.',
                'Set CACHE_STORE=redis: rate limits and kept counts must be shared by every server.'),
            $this->check('Background work on a queue', ! in_array(config('queue.default'), ['sync', 'null'], true), 'Queue: '.config('queue.default').'.',
                'Set QUEUE_CONNECTION=redis (or sqs) and run queue workers; "sync" makes visitors wait for emails and AI.'),
            $this->check('Real email', ! in_array(config('mail.default'), ['log', 'array'], true), 'Mail: '.config('mail.default').'.',
                'Set MAIL_MAILER to the sending service (Postmark, SES…); "log" sends nothing.'),
            $this->check('Files on shared storage', ! in_array(config('filesystems.default'), ['local', 'public'], true) && config('listings.media_disk') !== 'public',
                'Files: '.config('filesystems.default').'; listing photos: '.config('listings.media_disk', 'public').'.',
                'Use S3 (FILESYSTEM_DISK=s3, LISTINGS_MEDIA_DISK=s3) with the CDN in front, so every server sees the same files.'),
            $this->check('Brokerage database (cell) set', Connections::cell() !== null, 'Cell connection: '.(Connections::cell() ?? 'none').'.',
                'Set CORE_CELL_CONNECTION: brokerage data must not share the control database.'),
        ];
        $default = (string) config('database.default');
        foreach (['control' => Connections::control() ?? $default, 'cell' => Connections::cell() ?? $default, 'listings' => Connections::listings() ?? $default] as $name => $connection) {
            try {
                DB::connection($connection)->select('select 1');
                $checks[] = $this->check("Database \"{$name}\" answers", true, "Connected ({$connection}).", '');
            } catch (Throwable $e) {
                $checks[] = $this->check("Database \"{$name}\" answers", false, 'Could not connect.', "Check the {$name} database settings ({$connection}).");
            }
        }
        $pending = $this->pendingMigrations();
        $checks[] = $this->check('Database up to date', $pending === 0, match (true) {
            $pending === 0 => 'No changes waiting.', $pending < 0 => 'Could not read the database.', default => "{$pending} database changes waiting.",
        }, $pending < 0 ? 'Fix the database connection first.' : 'Run php artisan migrate --force.');

        try {
            $hq = Tenant::query()->where('type', 'hq')->first();
            [$staff, $twoStep] = $hq === null ? [0, 0] : app(TenantContext::class)->runAs($hq, fn (): array => [
                User::query()->where('kind', UserKind::HqStaff)->where('status', UserStatus::Active)->count(),
                User::query()->where('kind', UserKind::HqStaff)->where('status', UserStatus::Active)->whereNotNull('two_factor_confirmed_at')->count(),
            ]);
        } catch (Throwable) {
            [$hq, $staff, $twoStep] = [null, 0, 0]; // the database checks above say why
        }
        $checks[] = $this->check('HQ ready', $hq !== null && $staff > 0 && $twoStep === $staff, $hq === null ? 'No HQ yet.' : "{$twoStep} of {$staff} HQ staff use two-step sign-in.",
            $hq === null ? 'Run php artisan tenants:bootstrap-hq.' : 'Every HQ staff member must turn on two-step sign-in.');

        try {
            $beat = Cache::get(self::HEARTBEAT);
        } catch (Throwable) {
            $beat = null; // the cache store is not reachable
        }
        $fresh = is_string($beat) && CarbonImmutable::parse($beat)->gt(now()->subMinutes(self::SCHEDULER_MINUTES));
        $checks[] = $this->check('Scheduler running', $fresh, is_string($beat) ? 'Last ran '.CarbonImmutable::parse($beat)->diffForHumans().'.' : 'Never ran.',
            'Run "php artisan schedule:run" every minute (cron) on one server, or "schedule:work".');

        return $checks;
    }

    /** @param  list<array{check: string, ok: bool, detail: string}>  $checks */
    public static function ready(array $checks): bool
    {
        return collect($checks)->every(fn (array $c): bool => $c['ok']);
    }

    /** @return array{check: string, ok: bool, detail: string} */
    private function check(string $name, bool $ok, string $found, string $fix): array
    {
        return ['check' => $name, 'ok' => $ok, 'detail' => $ok || $fix === '' ? $found : $found.' '.$fix];
    }

    private function pendingMigrations(): int
    {
        try {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
            $ran = $migrator->getRepository()->getRan();

            return count(array_diff(array_keys($files), $ran));
        } catch (Throwable) {
            return -1;
        }
    }
}
