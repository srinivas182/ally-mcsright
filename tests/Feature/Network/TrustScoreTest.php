<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Network\TrustScore;

/*
| AR2 (specs/network/02): the Referral Trust Score, from what an agent did with referrals: paying, answering,
| updating, closing and ratings. Shown after 3 answered referrals; disputes count until HQ closes them.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->hq = Tenant::query()->where('type', 'hq')->sole();
    $this->staff = app(TenantContext::class)->runAs($this->hq, fn () => User::factory()->hqStaff()->create());
    $this->pine = $this->onTenantHost('pine');
    $pineOwner = User::factory()->owner()->create();
    $this->rosa = User::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Diaz']);
    $this->actingAs($pineOwner)->post('/settings/network', ['enabled' => true, 'default_fee_percent' => '25']);
    $this->actingAs($this->rosa)->post('/network/profile', ['areas' => ['Denver, CO'], 'sides' => 'both', 'state_code' => 'CO']);
    $this->acme = $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $this->actingAs($owner)->post('/settings/network', ['enabled' => true, 'default_fee_percent' => '25']);
    $this->actingAs($this->ann)->post('/network/profile', ['areas' => ['Austin, TX'], 'sides' => 'both', 'state_code' => 'TX']);
    $this->rosaProfile = DB::connection(Connections::control())->table('network_profiles')->where('tenant_id', $this->pine->id)->sole();
});

function trustSend(string $name): string
{
    test()->useTenantHost(test()->acme);
    test()->actingAs(test()->ann)->post('/network/referrals', ['to' => test()->rosaProfile->ulid, 'side' => 'buyer', 'area' => 'Denver', 'first_name' => $name,
        'email' => strtolower($name).'@example.test', 'client_agreed' => true])->assertSessionHasNoErrors();

    return (string) DB::connection(Connections::control())->table('network_referrals')->orderByDesc('id')->value('ulid');
}

it('weighs paying, answering, updates, closing and ratings, and leaves out parts with nothing to measure', function (): void {
    $now = CarbonImmutable::parse('2026-09-01');
    $row = fn (array $over): object => (object) ($over + ['status' => 'closed', 'created_at' => '2026-06-01 10:00', 'responded_at' => '2026-06-01 12:00', 'last_update_at' => '2026-07-01',
        'closed_on' => '2026-07-01', 'fee_paid_at' => '2026-07-10', 'disputed_at' => null, 'dispute_closed_at' => null, 'rating' => null]);
    // Three closed, all paid on time, answered fast: 100.
    expect(TrustScore::compute(collect([$row([]), $row([]), $row([])]), $now)['score'])->toBe(100);
    // Only two answered: no score yet.
    expect(TrustScore::compute(collect([$row([]), $row([])]), $now)['score'])->toBeNull();
    // One fee unpaid 60 days after closing pulls "pays" to 67; an open dispute counts as unpaid too.
    $out = TrustScore::compute(collect([$row([]), $row([]), $row(['fee_paid_at' => null])]), $now);
    expect($out['parts']['pays']['score'])->toBe(67)->and($out['score'])->toBe(87);
    $disputed = TrustScore::compute(collect([$row([]), $row([]), $row(['disputed_at' => '2026-08-20'])]), $now);
    expect($disputed['parts']['pays']['score'])->toBe(67);
    // A slow answer and a referral never answered count against "answers quickly".
    $slow = TrustScore::compute(collect([$row(['responded_at' => '2026-06-03 10:00']), $row([]), $row(['status' => 'expired', 'responded_at' => null, 'closed_on' => null, 'fee_paid_at' => null])]), $now);
    expect($slow['parts']['answers']['detail'])->toBe('Answered 1 of 3 referrals within 24 hours.')->and($slow['count'])->toBe(2);
    expect(array_keys(TrustScore::compute(collect([$row(['status' => 'declined', 'closed_on' => null, 'fee_paid_at' => null])]), $now)['parts']))->toBe(['answers']);
});

it('updates the score as referrals go, lets the referring agent rate and report an unpaid fee, and HQ settle it', function (): void {
    $ulids = [trustSend('Sam'), trustSend('Kim'), trustSend('Lee')];
    $this->useTenantHost($this->pine);
    foreach ($ulids as $u) {
        $this->actingAs($this->rosa)->post("/network/referrals/{$u}/accept", ['agree' => true]);
        $this->actingAs($this->rosa)->post("/network/referrals/{$u}/update", ['status' => 'under_contract']);
        $this->actingAs($this->rosa)->post("/network/referrals/{$u}/update", ['status' => 'closed', 'fee_due' => '3000', 'closed_on' => '2026-09-01']);
    }
    $profile = fn () => DB::connection(Connections::control())->table('network_profiles')->where('id', $this->rosaProfile->id)->sole();
    expect($profile()->trust_score)->toBe(100)->and((int) $profile()->trust_count)->toBe(3);

    $this->useTenantHost($this->acme);
    $this->actingAs($this->ann)->post("/network/referrals/{$ulids[0]}/dispute", ['note' => 'Nothing received yet'])->assertSessionHasErrors('dispute'); // too early
    $this->actingAs($this->ann)->post("/network/referrals/{$ulids[0]}/fee", ['step' => 'paid']);
    $this->actingAs($this->ann)->post("/network/referrals/{$ulids[1]}/fee", ['step' => 'paid']);
    $this->actingAs($this->ann)->post("/network/referrals/{$ulids[0]}/rate", ['rating' => 5, 'note' => 'Great updates'])->assertRedirect();
    $this->travel(50)->days();
    $this->actingAs($this->ann)->post("/network/referrals/{$ulids[2]}/dispute", ['note' => 'Nothing received after 50 days'])->assertRedirect();
    expect($profile()->trust_score)->toBe(88); // one fee in three unpaid; everything else full marks

    $this->useTenantHost($this->pine);
    $this->actingAs($this->rosa)->post("/network/referrals/{$ulids[2]}/dispute-reply", ['note' => 'Sent by wire on Oct 20'])->assertRedirect();
    $this->actingAs($this->rosa)->get('/network?tab=profile')->assertInertia(fn (Assert $page) => $page->where('profile.trust.score', 88)
        ->where('profile.tips', fn ($tips): bool => collect($tips)->contains(fn (string $t): bool => str_contains($t, 'referral fees within 45 days'))));

    auth()->logout();
    $id = (int) DB::connection(Connections::control())->table('network_referrals')->where('ulid', $ulids[2])->value('id');
    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/network')->assertOk()->assertInertia(fn (Assert $page) => $page->component('hq/network')
        ->where('disputes.0.open', true)->where('disputes.0.reply', 'Sent by wire on Oct 20')->where('totals.closed', 3)->missing('disputes.0.client'));
    $this->actingAs($this->staff)->post("http://app.rightally.io/hq/network/disputes/{$id}/close")->assertRedirect();
    $this->artisan('network:trust')->assertSuccessful();
    expect($profile()->trust_score)->toBeLessThan(100); // paid late (after 45 days) still isn't on time
});
