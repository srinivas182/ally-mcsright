<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Crm\Models\Contact;
use Modules\Transactions\Models\Deal;

/* PS2 (specs/core/14): the load-test filler only fills "loadtest" brokerages, never in production, with undeliverable addresses. */

it('fills a load-test brokerage with made-up agents, contacts and deals', function (): void {
    $this->onTenantHost('loadtest-big');
    $this->artisan('perf:seed', ['slug' => 'loadtest-big', '--agents' => 3, '--contacts' => 7, '--deals' => 6, '--password' => 'a-long-test-password'])->assertSuccessful();

    expect(User::query()->where('email', 'like', '%@loadtest.invalid')->count())->toBe(3)
        ->and(Contact::query()->count())->toBe(7)
        ->and(Contact::query()->where('primary_email', 'not like', '%@loadtest.invalid')->count())->toBe(0)
        ->and(Deal::query()->count())->toBe(6)->and(Deal::query()->where('status', 'closed')->count())->toBe(2);
    // Running it again tops up rather than duplicating agents.
    $this->artisan('perf:seed', ['slug' => 'loadtest-big', '--agents' => 3, '--contacts' => 0, '--deals' => 0, '--password' => 'a-long-test-password'])->assertSuccessful();
    expect(User::query()->where('email', 'like', '%@loadtest.invalid')->count())->toBe(3);
});

it('refuses any other brokerage, a short password, and production', function (): void {
    $this->onTenantHost('acme');
    $this->artisan('perf:seed', ['slug' => 'acme', '--password' => 'a-long-test-password'])->assertFailed();
    $this->onTenantHost('loadtest-x');
    $this->artisan('perf:seed', ['slug' => 'loadtest-x', '--password' => 'short'])->assertFailed();
    app()->detectEnvironment(fn (): string => 'production');
    $this->artisan('perf:seed', ['slug' => 'loadtest-x', '--password' => 'a-long-test-password'])->assertFailed();
    expect(Contact::query()->count())->toBe(0);
});
