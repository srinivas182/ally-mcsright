<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Compensation\Exceptions\InvalidRules;
use Modules\Compensation\JoinChoices;
use Modules\Compensation\Models\Plan;
use Modules\Compensation\RevenueShare\RevenueShareEngine;
use Modules\Compensation\Rules\CommissionRules;
use Modules\Compensation\StarterPlans;
use Modules\Core\Tenancy\Actions\ProvisionTenant;
use Modules\Core\Tenancy\Data\ProvisionTenantData;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Models\Cell;
use Modules\Core\Tenancy\TenantContext;

/* AG5: a new brokerage starts with Revenue share (the default) and Flat fee, published and offered when agents join;
   a brokerage that has no plans can add them with one button; revenue share can share part of the basis (a pool). */

it('gives a new brokerage the two starter plans, offered when agents join', function (): void {
    Cell::factory()->create(['name' => 'cell-a']);
    $tenant = app(ProvisionTenant::class)->handle(new ProvisionTenantData(type: TenantType::Brokerage, name: 'Jones Homes Realty', slug: 'jones-homes',
        ownerEmail: 'owner@joneshomes.com', ownerName: 'Pat Jones', idempotencyKey: 'signup-ag5', stateCode: 'TX'));

    app(TenantContext::class)->runAs($tenant, function (): void {
        $plans = Plan::query()->orderBy('name')->get();
        expect($plans->pluck('name')->all())->toBe([StarterPlans::FLAT, StarterPlans::SHARE])
            ->and($plans->firstWhere('name', StarterPlans::SHARE)->is_default)->toBeTrue()
            ->and($plans->every(fn (Plan $p): bool => $p->offered_on_join && $p->latestPublished() !== null))->toBeTrue()
            ->and(collect(app(JoinChoices::class)->offered())->firstWhere('name', StarterPlans::SHARE)['lines'])->toBe([
                'You keep 90% of each commission; the brokerage keeps 10%.',
                'The brokerage\'s share stops at $16,000 a year (your cap). Your year starts on the day you join.',
                'After you cap, you pay $500 a deal for the rest of that year.', 'Earn revenue share on the agents you bring in, 5 levels deep.']);
    });
});

it('adds the starter plans from the plans page only when there are none', function (): void {
    $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->get('/compensation/plans')->assertOk();
    $this->post('/compensation/plans/starter')->assertRedirect();
    expect(Plan::query()->count())->toBe(2);
    $this->post('/compensation/plans/starter')->assertRedirect();
    expect(Plan::query()->count())->toBe(2);

    $agent = User::factory()->create();
    $this->actingAs($agent)->post('/compensation/plans/starter')->assertForbidden();
});

it('pays revenue share from a pool of part of the basis, split by the levels', function (): void {
    $rules = CommissionRules::fromArray(StarterPlans::shareRules());
    expect($rules->revenueShare->poolPercent)->toBe('50')->and($rules->revenueShare->levels)->toBe(['40', '20', '20', '10', '10'])
        // $10,000 company dollar: a $5,000 pool.
        ->and(app(RevenueShareEngine::class)->distributable(1_000_000, $rules->revenueShare))->toBe(500_000);

    $parse = fn (array $rs) => CommissionRules::fromArray(['split' => ['mode' => 'percentage', 'agent_percent' => '90'], 'revenue_share' => $rs]);
    expect(fn () => $parse(['basis' => 'company_dollar', 'pool_percent' => '150', 'levels' => ['100']]))->toThrow(InvalidRules::class)
        ->and(fn () => $parse(['basis' => 'gci', 'pool_percent' => '50', 'levels' => ['10']]))->toThrow(InvalidRules::class)
        ->and($parse(['basis' => 'net_profit', 'levels' => ['100']])->revenueShare->poolPercent)->toBe('100');
});
