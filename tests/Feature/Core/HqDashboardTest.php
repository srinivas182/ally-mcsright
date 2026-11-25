<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/*
| HQ2: the HQ console opens on a dashboard with the platform's numbers (totals only) and what needs attention.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->hq = Tenant::query()->where('type', 'hq')->sole();
    $this->staff = app(TenantContext::class)->runAs($this->hq, fn () => User::factory()->hqStaff()->create());
});

it('shows money, growth, use and what needs attention, across every brokerage', function (): void {
    $control = DB::connection(Connections::control());
    $a = Tenant::factory()->create(['created_at' => '2026-11-03']);
    $b = Tenant::factory()->create(['created_at' => '2026-10-10']);
    $control->table('billing_tenant_subscriptions')->insert([
        ['tenant_id' => $a->id, 'package_id' => 1, 'interval' => 'month', 'status' => 'active', 'price_cents' => 49_900, 'created_at' => now(), 'updated_at' => now()],
        ['tenant_id' => $b->id, 'package_id' => 1, 'interval' => 'year', 'status' => 'grace', 'price_cents' => 120_000, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $control->table('support_tickets')->insert(['ulid' => (string) str()->ulid(), 'tenant_id' => $a->id, 'user_id' => 1, 'user_name' => 'Ann', 'user_email' => 'ann@example.com',
        'topic' => 'other', 'subject' => 'Help', 'body' => encrypt('x'), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/dashboard')->assertOk()->assertInertia(fn (Assert $p) => $p->component('hq/dashboard')
        ->where('kpis.mrr_cents', 49_900 + 10_000) // a yearly price counts as a twelfth each month
        ->where('kpis.paying_brokerages', 2)
        ->where('kpis.new_brokerages_month', 1)->where('kpis.new_brokerages_last_month', 1)
        ->has('months', 12)->where('months.11.month', '2026-11')->where('months.11.brokerages', 1)
        ->has('ai_days', 30)
        ->where('attention', fn ($items): bool => collect($items)->pluck('label')->all() === ['Brokerage payments overdue', 'Support questions waiting']));
});

it('is where HQ staff land, and only HQ can open it', function (): void {
    $this->actingAs($this->staff)->get('http://app.rightally.io/dashboard')->assertRedirect('/hq/today');
    auth()->logout();

    $brokerage = Tenant::factory()->create();
    $owner = app(TenantContext::class)->runAs($brokerage, fn () => User::factory()->owner()->create());
    expect($this->actingAs($owner)->get('http://app.rightally.io/hq/dashboard')->status())->toBeIn([302, 403, 404]); // never the figures
});
