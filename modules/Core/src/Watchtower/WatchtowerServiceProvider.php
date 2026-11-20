<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Core\Watchtower\Http\TimeRequests;
use Throwable;

/**
 * Watchtower (OB1, specs/core/20): watches the whole of RightAlly for HQ — server and browser errors, failed jobs,
 * scheduled tasks that fail or stop, a backed-up queue, the databases, cache and storage, and slow pages — groups them
 * into problems, has the AI find the root cause, and emails HQ a fix request ready to paste. An outside uptime checker
 * covers the one thing it cannot: RightAlly being down. Problems can also go to Sentry, switched on in Outside services.
 */
final class WatchtowerServiceProvider extends ServiceProvider
{
    /**
     * Every reported server error (web, background jobs, commands) becomes, or counts towards, a problem. Called from
     * bootstrap/app.php (withExceptions), so it runs for every report and never stops the normal log.
     */
    public static function report(Throwable $e): void
    {
        try {
            $app = app();
            $console = $app->runningInConsole() && ! $app->runningUnitTests();
            $request = $console ? null : request();
            $worker = str_starts_with((string) ($_SERVER['argv'][1] ?? ''), 'queue:');
            $app->make(Recorder::class)->record(Signal::fromThrowable($e, 'exception', $request !== null ? Where::location($request) : null,
                $request !== null ? Where::surface($request) : ($worker ? 'queue' : 'console')));
        } catch (Throwable) {
            // never let recording an error cause another
        }
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/watchtower.php');
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../../prompts');
        $this->app->make(NavigationRegistry::class)->add(new NavItem('HQ', 'Watchtower', '/hq/watchtower', 'radar', 11, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff]));
        if ($this->app->runningInConsole()) {
            $this->commands([Console\CheckCommand::class, Console\ReportCommand::class, Console\SweepCommand::class]);
        }

        // A background job that has used up its retries.
        Event::listen(JobFailed::class, function (JobFailed $e): void {
            $job = mb_substr($e->job->resolveName(), 0, 200);
            $this->app->make(Recorder::class)->record(Signal::fromThrowable($e->exception, 'job', "job {$job}", 'queue', ['attempts' => $e->job->attempts()], 'high'));
        });

        Event::listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $e) => $this->app->make(Heartbeats::class)->starting($e));
        Event::listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $e) => $this->app->make(Heartbeats::class)->finished($e));
        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $e) => $this->app->make(Heartbeats::class)->failed($e));

        // Response times, sampled, for slow pages.
        $this->app->make(Router::class)->pushMiddlewareToGroup('web', TimeRequests::class);
    }
}
