<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Modules\Core\Tenancy\Actions\ChangeTenantSlug;
use Modules\Core\Tenancy\Actions\TenantLifecycle;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\CrossTenant;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Events\CrossTenantAccessed;
use Modules\Core\Tenancy\Events\TenantClosed;
use Modules\Core\Tenancy\Events\TenantPurgeDue;
use Modules\Core\Tenancy\Events\TenantSlugChanged;
use Modules\Core\Tenancy\Events\TenantSuspended;
use Modules\Core\Tenancy\Exceptions\InvalidTenantTransition;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\Models\LedgerAccount;

beforeEach(fn () => Route::middleware('web')->get('/_probe', fn () => 'ok'));

it('suspends with a reason and reactivates', function (): void {
    Event::fake([TenantSuspended::class]);
    $tenant = Tenant::factory()->withPlatformDomain()->create();
    $lifecycle = app(TenantLifecycle::class);

    $lifecycle->suspend($tenant, 'Unpaid invoices');
    expect($tenant->status)->toBe(TenantStatus::Suspended)->and($tenant->suspended_reason)->toBe('Unpaid invoices');
    $this->get('http://'.$tenant->slug.'.rightally.app/_probe')->assertForbidden();

    $lifecycle->reactivate($tenant);
    expect($tenant->status)->toBe(TenantStatus::Active)->and($tenant->suspended_reason)->toBeNull();
    Event::assertDispatched(TenantSuspended::class);
});

it('runs the closing period, then closes and schedules the purge', function (): void {
    Event::fake([TenantClosed::class, TenantPurgeDue::class]);
    $tenant = Tenant::factory()->create();
    app(TenantLifecycle::class)->close($tenant, 'Brokerage sold');

    $this->artisan('tenants:lifecycle')->assertSuccessful();
    expect($tenant->fresh()?->status)->toBe(TenantStatus::Closing);

    $this->travel(31)->days();
    $this->artisan('tenants:lifecycle')->assertSuccessful();
    $closed = $tenant->fresh();
    expect($closed?->status)->toBe(TenantStatus::Closed)
        ->and($closed?->purge_after?->isAfter(now()->addDays(89)))->toBeTrue();
    Event::assertDispatched(TenantClosed::class);

    $this->travel(91)->days();
    $this->artisan('tenants:lifecycle')->assertSuccessful();
    $this->artisan('tenants:lifecycle')->assertSuccessful();
    Event::assertDispatchedTimes(TenantPurgeDue::class, 1);
});

it('lets a closing tenant be reactivated within the closing period', function (): void {
    $tenant = Tenant::factory()->create();
    app(TenantLifecycle::class)->close($tenant, 'Changed our mind later');

    app(TenantLifecycle::class)->reactivate($tenant);

    expect($tenant->status)->toBe(TenantStatus::Active)->and($tenant->closing_at)->toBeNull();
});

it('rejects invalid transitions', function (Closure $act): void {
    $act(app(TenantLifecycle::class));
})->throws(InvalidTenantTransition::class)->with([
    'suspend HQ' => [fn ($l) => $l->suspend(Tenant::factory()->hq()->create(), 'nope')],
    'close HQ' => [fn ($l) => $l->close(Tenant::factory()->hq()->create(), 'nope')],
    'reactivate active' => [fn ($l) => $l->reactivate(Tenant::factory()->create())],
    'suspend closed' => [fn ($l) => $l->suspend(Tenant::factory()->status(TenantStatus::Closed)->create(), 'x')],
    'blank reason' => [fn ($l) => $l->suspend(Tenant::factory()->create(), '   ')],
]);

it('changes the address and redirects the old one for 90 days', function (): void {
    Event::fake([TenantSlugChanged::class]);
    $tenant = Tenant::factory()->withPlatformDomain()->create(['slug' => 'old-name']);

    app(ChangeTenantSlug::class)->handle($tenant, 'new-name');

    $this->get('http://new-name.rightally.app/_probe')->assertOk();
    $this->get('http://old-name.rightally.app/_probe?tab=1')
        ->assertStatus(301)
        ->assertRedirect('http://new-name.rightally.app/_probe?tab=1');
    Event::assertDispatched(TenantSlugChanged::class);

    $this->travel(91)->days();
    $this->get('http://old-name.rightally.app/_probe')->assertNotFound();
});

it('keeps an old address reserved while it redirects', function (): void {
    $first = Tenant::factory()->withPlatformDomain()->create(['slug' => 'popular']);
    app(ChangeTenantSlug::class)->handle($first, 'renamed');
    $second = Tenant::factory()->withPlatformDomain()->create(['slug' => 'someone-else']);

    expect(fn () => app(ChangeTenantSlug::class)->handle($second, 'popular'))->toThrow(ValidationException::class);

    // The original owner may move back.
    app(ChangeTenantSlug::class)->handle($first, 'popular');
    expect(TenantDomain::query()->where('host', 'popular.rightally.app')->value('tenant_id'))->toBe($first->id);
});

#[CrossTenantAccess('Test: HQ reporting across brokerages')]
final class AllowedHqReport
{
    public function count(): int
    {
        return CrossTenant::query(LedgerAccount::class, $this)->count();
    }
}

final class UnmarkedReport
{
    public function count(): int
    {
        return CrossTenant::query(LedgerAccount::class, $this)->count();
    }
}

it('only allows marked classes to query across tenants, and announces each use', function (): void {
    Event::fake([CrossTenantAccessed::class]);
    $context = app(TenantContext::class);
    foreach (Tenant::factory()->count(2)->create() as $tenant) {
        $context->runAs($tenant, fn () => app(ChartOfAccounts::class)->seed());
    }

    expect((new AllowedHqReport)->count())->toBe(30)
        ->and(fn () => (new UnmarkedReport)->count())->toThrow(LogicException::class);
    Event::assertDispatched(CrossTenantAccessed::class, fn ($e) => $e->callerClass === AllowedHqReport::class);
});
