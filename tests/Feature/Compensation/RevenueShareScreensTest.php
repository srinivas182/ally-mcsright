<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\CommissionService;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\RevenueShare\Sponsorships;
use Modules\Ledger\Money\Money;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    foreach (['a', 'b', 'c', 'quiet'] as $n) {
        $this->{$n} = User::factory()->create(['first_name' => ucfirst($n), 'last_login_at' => now()]);
    }
    $sponsors = app(Sponsorships::class);
    $sponsors->set($this->a, $this->b, CarbonImmutable::parse('2026-01-01'));
    $sponsors->set($this->b, $this->c, CarbonImmutable::parse('2026-01-01'));
    $sponsors->set($this->quiet, $this->c, CarbonImmutable::parse('2026-01-01'));
    $this->quiet->forceFill(['last_login_at' => now()->subMonths(2)])->save();

    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, 'Rev share plan', ['split' => ['mode' => 'percentage', 'agent_percent' => '80'],
        'revenue_share' => ['basis' => 'net_profit', 'levels' => ['60', '30', '10'], 'unlock' => ['open_levels' => 2, 'thresholds' => ['3' => 2]]]]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $svc = app(CommissionService::class);
    foreach (['2026-05-10', '2026-06-01'] as $date) {
        $svc->finalise($svc->calculate(new DealSideInput(Money::of(1_000_000), 40_000_000, CarbonImmutable::parse($date), [new AgentShare($this->a->id)]), 'D'.$date));
    }
});

it('shows an agent their wallet, every payment explained, what they missed and why, and their network', function (): void {
    $this->actingAs($this->c)->get('/revenue-share')->assertInertia(fn (Assert $page) => $page
        ->component('compensation/revenue-share')
        ->where('wallet_cents', 2 * 60_000)->where('year_cents', 2 * 60_000)
        ->where('statement.0.explained', '$600.00 from A '.$this->a->last_name."'s closing on 1 Jun 2026 (level 2)")
        ->where('network.0.name', 'B '.$this->b->last_name)->where('network.0.qualifying', false)->where('network.0.children.0.name', 'A '.$this->a->last_name)
        ->where('retention', fn ($r) => collect($r)->pluck('name')->contains('Quiet '.$this->quiet->last_name)));

    // A level-3 sponsor gets nothing until level 3 is unlocked: shown honestly, with coaching.
    $d = User::factory()->create(['first_name' => 'D']);
    app(Sponsorships::class)->set($this->c, $d, CarbonImmutable::parse('2026-01-01'));
    $svc = app(CommissionService::class);
    $svc->finalise($svc->calculate(new DealSideInput(Money::of(1_000_000), 40_000_000, CarbonImmutable::parse('2026-06-10'), [new AgentShare($this->a->id)]), 'D3'));
    $this->actingAs($d)->get('/revenue-share')->assertInertia(fn (Assert $page) => $page
        ->where('missed.0.why', 'This level was not unlocked yet')->where('missed.0.amount_cents', 20_000)
        ->where('coaching.next_level', 3)->where('coaching.needed', 2));
});

it('gives the brokerage its review queue, settings, income disclosure and simulator', function (): void {
    $this->actingAs($this->owner)->get('/compensation/revenue-share')->assertInertia(fn (Assert $page) => $page
        ->component('compensation/revenue-share-admin')->where('budget.paid_cents', 2 * 200_000) // includes the root account's level-3 share
        ->where('disclosure.agents', 4)->where('disclosure.earning_nothing', 2)->where('disclosure.top_cents', 2 * 120_000)->where('disclosure.median_cents', 0));

    $this->actingAs($this->owner)->post('/compensation/revenue-share/settings', ['budget_percent' => 40, 'root' => $this->owner->ulid])->assertSessionHasNoErrors();
    expect((string) DB::table('rs_settings')->value('budget_percent'))->toStartWith('40');

    $this->actingAs($this->owner)->postJson('/compensation/revenue-share/simulate', ['rules' => ['split' => ['mode' => 'percentage', 'agent_percent' => '80'],
        'revenue_share' => ['basis' => 'net_profit', 'levels' => ['100']]]])->assertOk()
        ->assertJsonPath('deals', 2)->assertJsonPath('paid_cents', 400_000)->assertJsonPath('share_of_company', '100.0');
    $this->actingAs($this->owner)->postJson('/compensation/revenue-share/simulate', ['rules' => ['split' => ['mode' => 'percentage', 'agent_percent' => '80'],
        'revenue_share' => ['basis' => 'net_profit', 'levels' => ['50']]]])->assertStatus(422);

    $this->actingAs($this->a)->get('/compensation/revenue-share')->assertForbidden();
});

it('adds Revenue share to agents\' and brokers\' menus', function (): void {
    $menu = fn (User $u) => collect($this->actingAs($u)->get('/revenue-share')->viewData('page')['props']['navigation'])->flatMap(fn ($g) => collect($g['items'])->pluck('href'))->all();

    expect($menu($this->a))->toContain('/revenue-share')->not->toContain('/compensation/revenue-share')
        ->and($menu($this->owner))->toContain('/compensation/revenue-share');
});
