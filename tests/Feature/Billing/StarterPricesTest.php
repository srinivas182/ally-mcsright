<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Billing\Catalogue;
use Modules\Billing\Models\AgentPlan;
use Modules\Billing\Models\Package;
use Modules\Billing\Pricing;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/*
| PB3: RightAlly's starter prices — the brokerage package at $499 a month, three agent seats (cheaper through a
| brokerage than on your own), solo agent packages at the public prices, and a 20% brokerage share. Only what is
| missing is added; HQ's own edits are never changed.
*/

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
});

it('adds the starter brokerage package, agent seats, solo packages and the 20% brokerage share', function (): void {
    $this->artisan('billing:starter-prices')->expectsOutputToContain('RightAlly Brokerage package at $499 a month')->assertSuccessful();

    $brokerage = Package::query()->where('audience', 'brokerage')->sole();
    expect($brokerage->name)->toBe('RightAlly Brokerage')->and($brokerage->monthly_cents)->toBe(49_900)->and($brokerage->yearly_cents)->toBe(499_000)
        ->and($brokerage->seats)->toBe(3)->and($brokerage->getAttribute('modules'))->toContain('accounting', 'compensation', 'recruiting', 'blog_writer');

    $plans = AgentPlan::query()->orderBy('sort')->get()->mapWithKeys(fn (AgentPlan $p): array => [$p->key => [$p->floor_cents, $p->list_cents]])->all();
    expect($plans)->toBe(['basic' => [3_800, 5_800], 'pro' => [9_800, 13_800], 'all_in' => [15_800, 19_800]]);
    expect(AgentPlan::query()->where('key', 'all_in')->sole()->features)->toContain('crm.ai', 'crm.intelligence', 'website', 'content.blog')
        ->and(AgentPlan::query()->where('key', 'basic')->sole()->features)->not->toContain('crm.ai');

    $solo = Package::query()->where('audience', 'agent')->orderBy('monthly_cents')->get();
    expect($solo->pluck('monthly_cents')->all())->toBe([5_800, 13_800, 19_800])
        ->and($solo->pluck('name')->all())->toBe(['Solo agent Basic', 'Solo agent Pro', 'Solo agent All-in']);

    expect(app(Catalogue::class)->commissionPercent(1))->toBe('20')->and(app(Catalogue::class)->commissionPercent(500))->toBe('20');
});

it('pays the brokerage 20% of the floor, and keeps any price the brokerage adds on top', function (): void {
    $this->artisan('billing:starter-prices');
    $tenant = $this->onTenantHost('acme');
    app(TenantContext::class)->set($tenant);
    $plan = AgentPlan::query()->where('key', 'all_in')->sole();
    $pricing = app(Pricing::class);

    expect($pricing->priceFor($plan))->toBe(15_800); // through a brokerage, agents pay the floor by default
    expect($pricing->earningsPreview($plan, 15_800))->toMatchArray(['commission_cents' => 3_160, 'markup_cents' => 0, 'rightally_cents' => 12_640]);
    expect($pricing->earningsPreview($plan, 17_800))->toMatchArray(['commission_cents' => 3_160, 'markup_cents' => 2_000, 'total_cents' => 5_160]);
});

it('only adds what is missing and never changes what HQ has edited', function (): void {
    $this->artisan('billing:starter-prices');
    Package::query()->where('name', 'RightAlly Brokerage')->update(['monthly_cents' => 59_900]);
    AgentPlan::query()->where('key', 'pro')->delete();
    app(Catalogue::class)->saveTiers([['min_paying_agents' => 1, 'percent' => 15], ['min_paying_agents' => 50, 'percent' => 25]]);

    $this->artisan('billing:starter-prices')->expectsOutputToContain('Pro agent seat at $98 through a brokerage, $138 on their own')->assertSuccessful();
    expect(Package::query()->where('name', 'RightAlly Brokerage')->value('monthly_cents'))->toBe(59_900)
        ->and(Package::query()->count())->toBe(4)
        ->and(AgentPlan::query()->count())->toBe(3)
        ->and(DB::connection(Connections::control())->table('billing_commission_tiers')->count())->toBe(2);

    $this->artisan('billing:starter-prices')->expectsOutputToContain('Nothing to add')->assertSuccessful();
});
