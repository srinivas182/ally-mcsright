<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\CommissionService;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Recruiters\Recruiters;
use Modules\Compensation\RevenueShare\Sponsorships;
use Modules\Ledger\Money\Money;
use Modules\Transactions\Deals;

/*
| CM4b: recruiters (added only by the brokerage, no licence, no deals) are paid a flat bonus on a recruit's first
| closing; revenue share on levels 1–2 only after the owner switches it on and confirms their state allows it.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->recruiter = User::factory()->create(['first_name' => 'Rita', 'email' => 'rita@example.test']);
    $this->agent = User::factory()->create(['first_name' => 'Pat']);
    app(Recruiters::class)->add('rita@example.test', 'Rita', 'Recruiter', null, $this->owner);
    app(Sponsorships::class)->set($this->agent, $this->recruiter, CarbonImmutable::parse('2026-01-01'));
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, 'RS', ['split' => ['mode' => 'percentage', 'agent_percent' => '80'], 'revenue_share' => ['basis' => 'net_profit', 'levels' => ['100']]]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
});

function recruitClose(User $agent): int
{
    $svc = app(CommissionService::class);

    return $svc->finalise($svc->calculate(new DealSideInput(Money::of(1_000_000), 40_000_000, CarbonImmutable::parse('2026-05-01'), [new AgentShare($agent->id)]), 'R'.uniqid()))->id;
}

it('pays a recruiter the flat bonus on a recruit\'s first closing, and no revenue share by default', function (): void {
    $first = recruitClose($this->agent);
    recruitClose($this->agent);

    expect(DB::table('rs_entries')->where('recipient_user_id', $this->recruiter->id)->where('kind', 'bonus')->pluck('amount_cents')->all())->toBe([Recruiters::DEFAULT_BONUS_CENTS])
        ->and(DB::table('rs_entries')->where('recipient_user_id', $this->recruiter->id)->where('kind', 'share')->exists())->toBeFalse()
        ->and(DB::table('rs_entries')->where('calculation_id', $first)->where('missed_user_id', $this->recruiter->id)->value('reason'))->toBe('recruiter_limit');
});

it('pays level 1 revenue share only after the owner confirms their state allows it', function (): void {
    expect(fn () => app(Recruiters::class)->saveSettings(50_000, 1, false, $this->owner))->toThrow(ValidationException::class);
    app(Recruiters::class)->saveSettings(25_000, 1, true, $this->owner);

    recruitClose($this->agent);
    expect((int) DB::table('rs_entries')->where('recipient_user_id', $this->recruiter->id)->where('kind', 'share')->sum('amount_cents'))->toBe(200_000)
        ->and((int) DB::table('rs_entries')->where('recipient_user_id', $this->recruiter->id)->where('kind', 'bonus')->value('amount_cents'))->toBe(25_000);
});

it('never lets a recruiter be the agent on a deal under contract', function (): void {
    $deal = app(Deals::class)->create(['type' => 'buyer', 'street' => '1 Main St', 'state_code' => 'TX'], $this->recruiter, $this->owner);

    $this->actingAs($this->owner)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '400000', 'gci' => '12000',
        'contract_date' => '2026-06-01', 'closing_date' => '2026-07-01'])->assertSessionHasErrors('status');
});

it('lists recruiters for the brokerage, and only owners can add them or change their pay', function (): void {
    $this->actingAs($this->owner)->get('/compensation/recruiters')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('compensation/recruiters')->where('recruiters.0.email', 'rita@example.test')->where('recruiters.0.recruits', 1)
        ->where('settings.share_levels', 0));
    $this->actingAs($this->agent)->post('/compensation/recruiters', ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.test'])->assertForbidden();
    $this->actingAs($this->agent)->withSession(['auth.password_confirmed_at' => time()])->post('/compensation/recruiters/settings', ['bonus' => 1, 'share_levels' => 2, 'confirmed' => true])->assertForbidden();
});
