<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Models\LedgerJournal;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/*
| Real concurrency on MySQL: separate PHP processes post to the same accounts at the same time.
| Data must be committed for other processes to see it, so this suite migrates instead of
| wrapping tests in a transaction. Skipped on SQLite.
*/

uses(TestCase::class, DatabaseMigrations::class);

it('keeps exact balances when many processes post to the same accounts at once', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL-only: concurrent posting.');
    }

    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->runAs($tenant, fn () => app(ChartOfAccounts::class)->seed());

    $workers = 8;
    $perWorker = 25;
    $processes = [];

    for ($w = 0; $w < $workers; $w++) {
        $processes[] = new Process(['php', __DIR__.'/post-worker.php', (string) $tenant->id, (string) $perWorker, "w{$w}", '123'], base_path(), timeout: 120);
    }
    // Two extra workers race on the SAME posting keys: each journal must exist exactly once.
    $processes[] = new Process(['php', __DIR__.'/post-worker.php', (string) $tenant->id, '10', 'race', '500'], base_path(), timeout: 120);
    $processes[] = new Process(['php', __DIR__.'/post-worker.php', (string) $tenant->id, '10', 'race', '500'], base_path(), timeout: 120);

    foreach ($processes as $process) {
        $process->start();
    }
    foreach ($processes as $process) {
        $process->wait();
        expect($process->getExitCode())->toBe(0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
    }

    $expectedCents = $workers * $perWorker * 123 + 10 * 500;

    app(TenantContext::class)->runAs($tenant, function () use ($expectedCents, $workers, $perWorker): void {
        $chart = app(ChartOfAccounts::class);

        expect(app(LedgerService::class)->balance($chart->account('1000'))->cents)->toBe($expectedCents)
            ->and(app(LedgerService::class)->balance($chart->account('3000'))->cents)->toBe($expectedCents)
            ->and(LedgerJournal::query()->count())->toBe($workers * $perWorker + 10)
            ->and(LedgerJournal::query()->where('posting_key', 'like', 'race-%')->count())->toBe(10);
    });

    expect(Artisan::call('ledger:reconcile'))->toBe(0);
});
