<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Builder\AddOns;
use Modules\Billing\Entitlements;
use Modules\Billing\Gateway\FakeGateway;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\Models\Package;
use Modules\Billing\Usage\UsageMeter;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/*
| PB2: extras — more texts, minutes or map views, or one extra module — priced at cost + 70% (ending in 9), bought by
| the owner, on straight away, billed monthly; a nudge when the next package up is cheaper; HQ sees who costs more
| than they pay.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-10 15:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    app()->instance(PaymentGateway::class, $this->gateway = new FakeGateway);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->package = Package::query()->create(['name' => 'Starter', 'audience' => 'brokerage', 'monthly_cents' => 10_000, 'yearly_cents' => 0, 'ailly_conversations' => 0,
        'features' => ['backoffice.basic'], 'active' => true]);
    $this->package->forceFill(['included_text_segments' => 100, 'modules' => ['crm']])->save();
    Package::query()->create(['name' => 'Growth', 'audience' => 'brokerage', 'monthly_cents' => 12_900, 'yearly_cents' => 0, 'ailly_conversations' => 0, 'features' => [], 'active' => true]);
    $this->tenant->forceFill(['billing_package_id' => $this->package->id])->save();
    DB::connection(Connections::control())->table('billing_tenant_subscriptions')->insert(['tenant_id' => $this->tenant->id, 'package_id' => $this->package->id,
        'interval' => 'month', 'status' => 'active', 'price_cents' => 10_000, 'customer_ref' => 'cus_1', 'created_at' => now(), 'updated_at' => now()]);
});

function hqAddOn(object $t, array $data): void
{
    test()->actingAs($t->staff)->post('http://app.rightally.io/hq/packages/addons', $data)->assertSessionHasNoErrors();
}

it('prices an extra at cost + 70% ending in 9, and needs a reason below cost + 40%', function (): void {
    // 1,000 texts × 1.33¢ = $13.30 → about $13.30 × 1.7 plus card fees → $29.
    expect(app(AddOns::class)->costCents('allowance', 'texts', 1000, 'brokerage'))->toBe(1330)
        ->and(app(AddOns::class)->suggestedCents(1330))->toBe(2900);
    hqAddOn($this, ['kind' => 'allowance', 'key' => 'texts', 'amount' => 1000, 'name' => '1,000 more texts', 'audience' => 'both']);
    expect((int) DB::connection(Connections::control())->table('billing_addons')->value('price_cents'))->toBe(2900);

    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/packages/addons', ['kind' => 'allowance', 'key' => 'texts', 'amount' => 1000, 'name' => 'Cheap', 'audience' => 'both', 'price' => 10])
        ->assertSessionHasErrors('price');
});

it('lets the owner add an extra that works straight away, bills it monthly, and stops it at month end', function (): void {
    hqAddOn($this, ['kind' => 'allowance', 'key' => 'texts', 'amount' => 1000, 'name' => '1,000 more texts', 'audience' => 'both']);
    hqAddOn($this, ['kind' => 'module', 'key' => 'crm_ai', 'name' => 'AI for contacts', 'audience' => 'brokerage']);
    $texts = (string) DB::connection(Connections::control())->table('billing_addons')->where('key', 'texts')->value('ulid');
    $ai = (string) DB::connection(Connections::control())->table('billing_addons')->where('key', 'crm_ai')->value('ulid');

    expect(app(UsageMeter::class)->allowance('texts'))->toBe(100)->and(app(Entitlements::class)->allows($this->owner, 'crm.ai'))->toBeFalse();
    $agent = User::factory()->create();
    $this->actingAs($agent)->post("/settings/billing/extras/{$texts}")->assertSessionHasErrors('addon');
    $this->actingAs($this->owner)->post("/settings/billing/extras/{$texts}")->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post("/settings/billing/extras/{$ai}")->assertSessionHasNoErrors();
    app(Entitlements::class)->forget();
    $meter = app(UsageMeter::class);
    $meter->forget();
    expect($meter->allowance('texts'))->toBe(1100)->and(app(Entitlements::class)->allows($this->owner->fresh(), 'crm.ai'))->toBeTrue();

    // Package $100 + extras ($29 + AI) ≥ Growth $129: told the upgrade is cheaper.
    $this->actingAs($this->owner)->get('/settings/billing')->assertInertia(fn (Assert $p) => $p->where('extras.upgrade.name', 'Growth')
        ->where('extras.offers', fn ($o): bool => collect($o)->every(fn ($x): bool => $x['have'] === true)));

    $this->artisan('billing:bill-addons')->assertSuccessful();
    $this->artisan('billing:bill-addons')->assertSuccessful(); // once a month
    expect($this->gateway->invoiceItems)->toHaveCount(2);

    $this->actingAs($this->owner)->post("/settings/billing/extras/{$texts}/stop")->assertSessionHasNoErrors();
    $meter->forget();
    expect($meter->allowance('texts'))->toBe(1100); // still on this month
    $this->travelTo(CarbonImmutable::parse('2026-07-02 10:00', 'UTC'));
    $meter->forget();
    expect($meter->allowance('texts'))->toBe(100);
});

it('shows HQ every customer\'s money this month, flagging anyone who costs more than they pay', function (): void {
    DB::connection(Connections::cell())->table('usage_meters')->insert(['tenant_id' => $this->tenant->id, 'period' => '2026-06', 'meter' => 'texts', 'used' => 900_000, 'over' => 0,
        'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/packages')->assertInertia(fn (Assert $p) => $p
        ->where('tenants', fn ($rows): bool => ($row = collect($rows)->firstWhere('tenant', $this->tenant->name)) !== null && $row['paid_cents'] === 10_000 && $row['losing'] === true));
});
