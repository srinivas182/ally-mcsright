<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Models\Site;

/* E2E1 (specs/core/15): the brokerage the nightly journeys use; safe to run again, never in production. */

it('makes the e2e brokerage with a two-step owner, an agent and a live website, and can run again', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->artisan('e2e:seed', ['--password' => 'e2e-password-123'])->assertSuccessful();
    $this->artisan('e2e:seed', ['--password' => 'e2e-password-123'])->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'e2e')->sole();
    app(TenantContext::class)->runAs($tenant, function (): void {
        $owner = User::query()->where('email', 'e2e-owner@example.test')->sole();
        expect($owner->two_factor_confirmed_at)->not->toBeNull()->and($owner->email_verified_at)->not->toBeNull()
            ->and(User::query()->where('email', 'e2e-agent@example.test')->count())->toBe(1)
            ->and(Site::query()->where('kind', 'brokerage')->sole()->status)->toBe('live');
    });
});

it('refuses production and a short password', function (): void {
    $this->artisan('e2e:seed', ['--password' => 'short'])->assertFailed();
    app()->detectEnvironment(fn (): string => 'production');
    $this->artisan('e2e:seed', ['--password' => 'e2e-password-123'])->assertFailed();
    expect(Tenant::query()->where('slug', 'e2e')->exists())->toBeFalse();
});
