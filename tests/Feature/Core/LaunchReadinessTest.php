<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Modules\Core\Launch\LaunchReadiness;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/*
| Sprint 63 (launch readiness): `php artisan launch:check` says, in plain words, whether a server is set up for
| real customers, and what to change when it is not.
*/

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(),
        fn () => User::factory()->hqStaff()->create(['two_factor_confirmed_at' => now(), 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')]));
});

function lrProduction(): void
{
    config(['app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://app.rightally.io', 'session.secure' => true, 'session.driver' => 'redis',
        'cache.default' => 'database', 'queue.default' => 'redis', 'mail.default' => 'postmark', 'filesystems.default' => 's3', 'listings.media_disk' => 's3',
        'core.connections.cell' => config('database.default')]);
    Cache::put(LaunchReadiness::HEARTBEAT, now()->toIso8601String(), 3600);
}

it('passes a server set up for customers', function (): void {
    lrProduction();
    $checks = app(LaunchReadiness::class)->checks();

    expect(collect($checks)->reject(fn (array $c): bool => $c['ok'])->pluck('check')->all())->toBe([])
        ->and(LaunchReadiness::ready($checks))->toBeTrue();
    lrProduction(); // the HQ check switched brokerage, which puts the cell setting back to this test server's own
    $this->artisan('launch:check')->expectsOutputToContain('Ready for customers.')->assertSuccessful();
});

it('names each setting that is not ready, and what to change', function (): void {
    app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create(['two_factor_confirmed_at' => null, 'two_factor_secret' => null])); // no two-step
    lrProduction();
    config(['app.debug' => true, 'queue.default' => 'sync', 'mail.default' => 'log', 'filesystems.default' => 'local', 'session.secure' => false]);
    Cache::put(LaunchReadiness::HEARTBEAT, now()->subMinutes(20)->toIso8601String(), 3600);

    $failed = collect(app(LaunchReadiness::class)->checks())->reject(fn (array $c): bool => $c['ok'])->keyBy('check');
    expect($failed->keys()->all())->toBe(['Error details hidden', 'Secure session cookie', 'Background work on a queue', 'Real email', 'Files on shared storage', 'HQ ready', 'Scheduler running'])
        ->and($failed['Background work on a queue']['detail'])->toContain('Set QUEUE_CONNECTION=redis')
        ->and($failed['HQ ready']['detail'])->toBe('1 of 2 HQ staff use two-step sign-in. Every HQ staff member must turn on two-step sign-in.');
    lrProduction();
    $this->artisan('launch:check')->expectsOutputToContain('Not ready')->assertFailed(); // the scheduler is still late
});

it('trusts the load balancer, so each visitor keeps their own address and https is seen', function (): void {
    $this->onTenantHost('acme');
    $seen = null;
    Route::get('/_probe', function (Request $r) use (&$seen) {
        $seen = [$r->ip(), $r->isSecure()];

        return 'ok';
    })->middleware('web');
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.3.7'])->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])->get('/_probe');
    expect($seen)->toBe(['203.0.113.9', true]);
});

it('does not believe forwarded addresses from outside the private network', function (): void {
    $this->onTenantHost('acme');
    $seen = null;
    Route::get('/_probe', function (Request $r) use (&$seen) {
        $seen = [$r->ip(), $r->isSecure()];

        return 'ok';
    })->middleware('web');
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])->get('/_probe');
    expect($seen)->toBe(['198.51.100.20', false]);
});
