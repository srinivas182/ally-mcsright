<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\CommissionService;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\Models\Plan;
use Modules\Compensation\Ranks\Ranks;
use Modules\Compensation\RevenueShare\Sponsorships;
use Modules\Ledger\Money\Money;

/*
| CM1: ranks (production + recruits) open revenue-share levels and point to a better plan; the network tree shows
| who brought in whom, and only money managers can change it (with a reason).
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olivia']);
    $this->leader = User::factory()->create(['first_name' => 'Lee']);
    $this->recruit = User::factory()->create(['first_name' => 'Rae']);
    $this->other = User::factory()->create(['first_name' => 'Otto']);
    app(Sponsorships::class)->set($this->recruit, $this->leader, CarbonImmutable::parse('2026-01-01'));
    $plans = app(ManagePlans::class);
    $this->standard = $plans->create(PlanType::Commission, 'Standard 70/30', ['split' => ['mode' => 'percentage', 'agent_percent' => '70'],
        'revenue_share' => ['basis' => 'net_profit', 'levels' => ['60', '40'], 'unlock' => ['open_levels' => 0]]]);
    $plans->publish($this->standard->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($this->standard->fresh());
    $this->better = $plans->create(PlanType::Commission, 'Leader 85/15', ['split' => ['mode' => 'percentage', 'agent_percent' => '85']]);
    $plans->publish($this->better->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
});

function rankDeal(User $agent, int $gci, string $date): Calculation
{
    $svc = app(CommissionService::class);

    return $svc->finalise($svc->calculate(new DealSideInput(Money::of($gci), 40_000_000, CarbonImmutable::parse($date), [new AgentShare($agent->id)]), 'R'.uniqid()));
}

it('ranks agents by production and recruits, and says what the next rank needs', function (): void {
    $ranks = app(Ranks::class);
    $ranks->save([
        ['name' => 'Associate', 'unlock_levels' => 0],
        ['name' => 'Producer', 'min_closings' => 2, 'unlock_levels' => 1],
        ['name' => 'Leader', 'min_closings' => 2, 'min_recruits' => 1, 'unlock_levels' => 2, 'plan' => $this->better->ulid],
    ], $this->owner);

    expect($ranks->progress($this->leader->id, now()->toImmutable()))->toMatchArray(['closings' => 0, 'recruits' => 0])
        ->and($ranks->progress($this->leader->id, now()->toImmutable())['rank']['name'])->toBe('Associate')
        ->and($ranks->progress($this->leader->id, now()->toImmutable())['needs'])->toBe(['2 more closed deals']);

    rankDeal($this->leader, 1_000_000, '2026-03-01');
    rankDeal($this->leader, 1_000_000, '2026-04-01');
    $ranks->forget();
    expect($ranks->progress($this->leader->id, now()->toImmutable())['rank']['name'])->toBe('Producer')
        ->and($ranks->progress($this->leader->id, now()->toImmutable())['needs'])->toBe(['1 more recruit who closed a deal']);

    rankDeal($this->recruit, 500_000, '2026-05-01'); // the recruit closes: Lee becomes a Leader
    $ranks->forget();
    expect($ranks->progress($this->leader->id, now()->toImmutable())['rank']['name'])->toBe('Leader')
        ->and($ranks->unlockedLevels($this->leader->id, now()->toImmutable()))->toBe(2);
});

it('opens revenue-share levels by rank on top of the plan', function (): void {
    // The plan opens no levels by itself: without ranks Lee earns nothing from Rae's deal.
    $before = rankDeal($this->recruit, 1_000_000, '2026-05-10');
    expect(DB::table('rs_entries')->where('calculation_id', $before->id)->where('kind', 'share')->where('recipient_user_id', $this->leader->id)->exists())->toBeFalse();

    app(Ranks::class)->save([['name' => 'Associate', 'unlock_levels' => 1]], $this->owner);
    app(Ranks::class)->forget();
    $after = rankDeal($this->recruit, 1_000_000, '2026-05-20');
    expect(DB::table('rs_entries')->where('calculation_id', $after->id)->where('kind', 'share')->where('recipient_user_id', $this->leader->id)->value('level'))->toBe(1);
});

it('refuses a rank that asks less than the one below it, and keeps the plan change to the broker', function (): void {
    expect(fn () => app(Ranks::class)->save([['name' => 'A', 'min_closings' => 5], ['name' => 'B', 'min_closings' => 2]], $this->owner))
        ->toThrow(ValidationException::class);

    $this->actingAs($this->owner)->post('/compensation/ranks', ['ranks' => [
        ['name' => 'Associate', 'min_closings' => 0, 'plan' => $this->better->ulid],
    ]])->assertRedirect();

    // Reaching a rank never moves anyone by itself: the page lists them as ready to move up.
    $this->actingAs($this->owner)->get('/compensation/ranks')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('compensation/ranks')
        ->where('ranks.0.name', 'Associate')->where('ranks.0.plan', $this->better->ulid)
        ->where('agents', fn ($agents) => collect($agents)->firstWhere('name', $this->leader->name)['move_to']['name'] === 'Leader 85/15'));
    expect(Plan::query()->where('type', PlanType::Commission)->count())->toBe(2);

    $this->actingAs($this->leader)->post('/compensation/ranks', ['ranks' => []])->assertForbidden();
    $this->actingAs($this->leader)->get('/compensation/ranks')->assertForbidden();
});

it('shows the whole network tree, and lets money managers move someone with a reason', function (): void {
    $this->actingAs($this->owner)->get('/compensation/network')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('compensation/network')
        ->where('tree', fn ($tree) => collect($tree)->firstWhere('name', $this->leader->name)['children'][0]['name'] === $this->recruit->name)
        ->where('totals.sponsored', 1)->where('canChange', true));

    $this->withSession(['auth.password_confirmed_at' => time()]);
    $this->actingAs($this->owner)->post("/compensation/network/{$this->recruit->ulid}/sponsor", ['sponsor' => $this->other->ulid, 'reason' => 'short'])
        ->assertSessionHasErrors('reason');
    $this->actingAs($this->owner)->post("/compensation/network/{$this->recruit->ulid}/sponsor", ['sponsor' => $this->other->ulid, 'reason' => 'Agreed at the March team meeting'])
        ->assertRedirect();
    expect(app(Sponsorships::class)->sponsorOf($this->recruit->id, CarbonImmutable::today()))->toBe($this->other->id);

    // A cycle is refused: Otto cannot sit under his own recruit.
    $this->actingAs($this->owner)->post("/compensation/network/{$this->other->ulid}/sponsor", ['sponsor' => $this->recruit->ulid, 'reason' => 'Trying to make a loop here'])
        ->assertSessionHasErrors('sponsor');

    $this->actingAs($this->leader)->post("/compensation/network/{$this->recruit->ulid}/sponsor", ['sponsor' => null, 'reason' => 'I would like to move them'])->assertForbidden();
});

it('no longer offers onboarding plans and archives the old ones', function (): void {
    $this->actingAs($this->owner)->post('/compensation/plans', ['name' => 'Joining', 'type' => 'onboarding', 'rules' => ['fees' => []]])->assertSessionHasErrors('type');
    $this->actingAs($this->owner)->get('/compensation/plans')->assertInertia(fn (Assert $page) => $page
        ->where('plans', fn ($plans) => collect($plans)->every(fn ($p) => $p['type'] === 'commission')));
});
