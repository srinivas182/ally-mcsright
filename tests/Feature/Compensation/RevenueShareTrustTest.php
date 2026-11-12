<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\CommissionService;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\RevenueShare\Attribution;
use Modules\Compensation\RevenueShare\Members;
use Modules\Compensation\RevenueShare\RevenueShareEngine;
use Modules\Compensation\RevenueShare\Sponsorships;
use Modules\Core\Identity\Actions\AcceptInvitation;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Identity\Events\UserDeactivated;
use Modules\Core\Identity\Models\Invitation;
use Modules\Ledger\Money\Money;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Root']);
    foreach (['a', 'b', 'c'] as $n) {
        $this->{$n} = User::factory()->create(['first_name' => strtoupper($n), 'phone' => '+1 555 010 '.ord($n)]);
    }
    $this->sponsors = app(Sponsorships::class);
    $this->sponsors->set($this->a, $this->b, CarbonImmutable::parse('2026-01-01'));
    $this->sponsors->set($this->b, $this->c, CarbonImmutable::parse('2026-01-01'));
});

function trustPlan(array $extra = []): void
{
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, 'RS '.uniqid(), ['split' => ['mode' => 'percentage', 'agent_percent' => '80'],
        'revenue_share' => ['basis' => 'net_profit', 'levels' => ['60', '40']] + $extra]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
}

function trustDeal(User $agent, int $gci = 1_000_000, string $date = '2026-06-10'): Calculation
{
    $svc = app(CommissionService::class);

    return $svc->finalise($svc->calculate(new DealSideInput(Money::of($gci), 40_000_000, CarbonImmutable::parse($date), [new AgentShare($agent->id)]), 'D'.uniqid()));
}

function kinds(Calculation $c): array
{
    return DB::table('rs_entries')->where('calculation_id', $c->id)->whereNull('reversal_of')->orderBy('level')->orderBy('id')
        ->get(['level', 'kind', 'recipient_user_id', 'reason', 'amount_cents'])->map(fn ($r) => [(int) $r->level, $r->kind, $r->recipient_user_id !== null ? (int) $r->recipient_user_id : null, $r->reason, (int) $r->amount_cents])->all();
}

function joinedOn(User $u, string $date): void
{
    DB::table('rs_members')->updateOrInsert(['tenant_id' => test()->tenant->id, 'user_id' => $u->id], ['joined_on' => $date, 'created_at' => now(), 'updated_at' => now()]);
}

it('keeps paying vested agents after they leave, and only them', function (): void {
    joinedOn($this->b, '2022-01-01');
    joinedOn($this->c, '2025-01-01');
    foreach ([$this->b, $this->c] as $u) {
        $u->forceFill(['status' => UserStatus::Deactivated])->save();
        UserDeactivated::dispatch($this->tenant->id, $u->id, $this->owner->id);
    }

    trustPlan();
    expect(kinds(trustDeal($this->a)))->toBe([[1, 'missed', null, 'ineligible_upline', 120_000], [2, 'missed', null, 'ineligible_upline', 80_000]]); // parity: no vesting rule

    trustPlan(['vesting' => ['years' => 3]]);
    expect(kinds(trustDeal($this->a)))->toBe([[1, 'share', $this->b->id, null, 120_000], [2, 'missed', null, 'ineligible_upline', 80_000]]);
});

it('pays the beneficiary of a vested agent who has died', function (): void {
    trustPlan(['vesting' => ['years' => 3]]);
    joinedOn($this->b, '2020-01-01');
    $spouse = User::factory()->staff()->create(['first_name' => 'Spouse']);
    $this->actingAs($this->b)->post('/revenue-share/beneficiary', ['beneficiary' => $spouse->ulid])->assertSessionHasNoErrors();

    expect(fn () => app(Members::class)->markDeceased($this->b, CarbonImmutable::parse('2026-05-01'), $this->owner, 'short'))->toThrow(ValidationException::class);
    $this->actingAs($this->owner)->post("/revenue-share/members/{$this->b->ulid}/deceased", ['on' => '2026-05-01', 'note' => 'Death certificate received by the office manager'])->assertSessionHasNoErrors();

    expect(kinds(trustDeal($this->a))[0])->toBe([1, 'share', $spouse->id, null, 120_000]);
});

it('sets the sponsor chosen in the invitation when it is accepted', function (): void {
    $this->actingAs($this->owner)->post('/users/invitations', ['email' => 'new@acme.test', 'first_name' => 'New', 'last_name' => 'Agent', 'kind' => 'agent', 'sponsor' => $this->b->ulid])->assertSessionHasNoErrors();
    $user = app(AcceptInvitation::class)->handle(Invitation::query()->where('email', 'new@acme.test')->sole(), 'New', 'Agent', 'a-Long-password-123!');

    expect($this->sponsors->sponsorOf($user->id, CarbonImmutable::now()))->toBe($this->b->id)
        ->and(DB::table('rs_members')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('settles sponsorship disputes with the brokerage, changing the sponsor only from the chosen date', function (): void {
    trustPlan();
    $claimant = User::factory()->create(['first_name' => 'Claimant']);
    expect(fn () => app(Attribution::class)->claim($this->a, $claimant, 'I did it'))->toThrow(ValidationException::class);
    $this->actingAs($claimant)->post('/revenue-share/disputes', ['agent' => $this->a->ulid, 'evidence' => 'I introduced A at the March open house and ran their interview.'])->assertSessionHasNoErrors();
    expect(fn () => app(Attribution::class)->claim($this->a, $this->c, 'Another claim with enough characters'))->toThrow(ValidationException::class, 'already an open');

    $dispute = (string) DB::table('rs_disputes')->value('ulid');
    $this->actingAs($this->a)->post("/revenue-share/disputes/{$dispute}/decide", ['upheld' => true, 'effective_from' => '2026-07-01', 'note' => 'Open-house sign-in sheet confirms it'])->assertForbidden();
    $this->actingAs($this->owner)->post("/revenue-share/disputes/{$dispute}/decide", ['upheld' => true, 'effective_from' => '2026-07-01', 'note' => 'Open-house sign-in sheet confirms it'])->assertSessionHasNoErrors();

    expect(kinds(trustDeal($this->a, 1_000_000, '2026-06-20'))[0][2])->toBe($this->b->id)
        ->and(kinds(trustDeal($this->a, 1_000_000, '2026-07-02'))[0][2])->toBe($claimant->id);
});

it('holds suspicious payments until the brokerage releases or rejects them', function (): void {
    trustPlan();
    $this->b->forceFill(['phone' => $this->a->phone])->save();
    $held = trustDeal($this->a);
    expect(kinds($held)[0])->toBe([1, 'held', $this->b->id, 'same_phone', 120_000])
        ->and(app(RevenueShareEngine::class)->wallet($this->b)->cents)->toBe(0);

    $hold = (int) DB::table('rs_holds')->value('id');
    $this->actingAs($this->owner)->post("/revenue-share/holds/{$hold}/release", ['note' => 'Married couple, separate businesses'])->assertSessionHasNoErrors();
    expect(app(RevenueShareEngine::class)->wallet($this->b)->cents)->toBe(120_000);

    $second = trustDeal($this->a);
    $this->actingAs($this->owner)->post('/revenue-share/holds/'.DB::table('rs_holds')->where('status', 'held')->value('id').'/reject', ['note' => 'Same person on two accounts'])->assertSessionHasNoErrors();
    expect(kinds($second)[0])->toBe([1, 'missed', null, 'rejected', 120_000]);
});

it('holds tiny deals, shared devices and recent sponsor changes, and cancels holds when a deal is reversed', function (): void {
    trustPlan();
    expect(kinds(trustDeal($this->a, 40_000))[0][3])->toBe('tiny_deal');

    DB::table('known_devices')->insert([
        ['tenant_id' => $this->tenant->id, 'user_id' => $this->a->id, 'fingerprint' => str_repeat('f', 64), 'label' => 'Chrome on Mac', 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['tenant_id' => $this->tenant->id, 'user_id' => $this->b->id, 'fingerprint' => str_repeat('f', 64), 'label' => 'Chrome on Mac', 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);
    $deal = trustDeal($this->a);
    expect(kinds($deal)[0][3])->toBe('shared_device');
    app(CommissionService::class)->reverse($deal, 'Deal unwound after closing');
    expect(DB::table('rs_holds')->where('entry_id', DB::table('rs_entries')->where('calculation_id', $deal->id)->where('kind', 'held')->value('id'))->value('status'))->toBe('rejected');

    $d = User::factory()->create();
    $this->sponsors->set($d, $this->c, CarbonImmutable::parse('2026-01-01'));
    $this->sponsors->set($d, $this->a, CarbonImmutable::parse('2026-06-05'));
    expect(kinds(trustDeal($d))[0][3])->toBe('recent_sponsor_change');
});

it('pays the sponsor a milestone bonus once, on the recruit\'s first closing', function (): void {
    trustPlan(['milestone' => ['first_closing_cents' => 50_000]]);

    $first = trustDeal($this->a);
    trustDeal($this->a);
    expect(collect(kinds($first))->firstWhere(1, 'bonus'))->toBe([1, 'bonus', $this->b->id, 'first_closing', 50_000])
        ->and(DB::table('rs_entries')->where('kind', 'bonus')->count())->toBe(1)
        ->and(app(RevenueShareEngine::class)->wallet($this->b)->cents)->toBe(120_000 * 2 + 50_000);
});
