<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Credits\CreditLedger;
use Modules\Core\Identity\Models\Invitation;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Package;
use Modules\Movewise\Models\SeatRequest;
use Modules\Movewise\Models\State;
use Modules\Movewise\Program\Agents;
use Modules\Roster\Verification\FakeVerifier;
use Modules\Roster\Verification\LicenceVerifier;

/* Movewise MW3: the agent and broker program — applying with a licence check, the profile card and HQ review, the
   brokerage's confirmation, program terms, HQ pricing per state, packages (areas, price range, lead types, caps,
   hours, payment) and the free Movewise Agent seat. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    config(['core.hq_host' => 'app.rightally.io', 'movewise.host' => 'movewisehomes.com']);
    $this->artisan('tenants:bootstrap-hq');
    $this->hqTenant = Tenant::query()->where('type', 'hq')->sole();
    $this->staff = app(TenantContext::class)->runAs($this->hqTenant, fn () => User::factory()->hqStaff()->create());
    app()->instance(LicenceVerifier::class, $this->verifier = new FakeVerifier([
        'TX:0654321' => ['status' => 'active', 'expires_on' => '2027-03-31', 'last_name' => 'Agent'],
        'TX:0999999' => ['status' => 'active', 'expires_on' => '2027-03-31', 'last_name' => 'Someone Else'],
        'TX:0700000' => ['status' => 'active', 'expires_on' => '2027-03-31', 'last_name' => 'Seat'],
    ]));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive', 'last_name' => 'Owner']);
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    DB::connection(Connections::control())->table('credit_settings')->insert(['credit_value_microcents' => 1_000_000, 'enforce' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(CreditLedger::class)->savePolicy(false, null, $this->owner);
    DB::connection(Connections::cell())->table('studio_brand_kits')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $this->ann->id, 'headshot_path' => 'studio/ann.png',
        'created_at' => now(), 'updated_at' => now()]);
    State::query()->whereKey('TX')->update(['on' => true]);
    $this->hq = 'http://app.rightally.io';
});

function mwApply(object $t, array $extra = []): Agent
{
    $t->actingAs($t->ann)->post('/movewise/apply', $extra + ['state' => 'TX', 'licence_number' => '0654321', 'display_name' => 'Ann Agent', 'years' => 7, 'languages' => ['en', 'es'],
        'specialties' => ['first_time', 'relocation', 'nonsense'], 'bio' => 'I help first-time buyers in Houston find the right home, explain every step plainly and answer the same day.'])
        ->assertSessionHasNoErrors();

    return Agent::query()->where('user_id', $t->ann->id)->sole();
}

function mwHqPrice(object $t): void
{
    $t->actingAs($t->staff)->withSession(['auth.password_confirmed_at' => time()])->post($t->hq.'/hq/movewise/program/states/TX/pricing', ['models' => ['connection', 'share'],
        'connection_buyer' => 300, 'connection_seller' => 450, 'appointment' => 0, 'share_pct' => 25])->assertSessionHasNoErrors();
}

it('applies an agent with an automatic licence check, a Fair-Housing-safe card, the broker\'s confirmation, HQ review and signed terms', function (): void {
    $this->actingAs($this->ann)->post('/movewise/apply', ['state' => 'TX', 'licence_number' => '0999999'])->assertSessionHasNoErrors();
    expect(Agent::query()->sole()->licence_status)->toBe('mismatch'); // that number is someone else's
    $this->actingAs($this->ann)->post('/movewise/apply', ['state' => 'TX', 'licence_number' => '0654321', 'bio' => 'Perfect for young couples with no kids looking for a starter home in Houston, call me today.'])
        ->assertSessionHasErrors('bio');
    $agent = mwApply($this);
    expect($agent->licence_status)->toBe('active')->and($agent->licence_expires_on->toDateString())->toBe('2027-03-31')
        ->and($agent->brokerage_name)->toBe($this->tenant->name)->and($agent->profile['specialties'])->toBe(['first_time', 'relocation'])
        ->and($agent->profile['photo'])->toEndWith('studio/ann.png')->and($agent->profile['phone'])->toBeNull()->and($agent->status)->toBe('applying');
    expect(app(Agents::class)->missing($agent))->toBe(['Your broker confirms you in RightAlly', 'HQ checks the state\'s public discipline record',
        'Finish and send your profile card', 'Sign the Movewise program terms']);

    $this->actingAs($this->ann)->post('/movewise/agents/'.$agent->ulid.'/confirm')->assertForbidden(); // only the owner confirms
    $this->actingAs($this->owner)->post('/movewise/agents/'.$agent->ulid.'/confirm', ['yes' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->ann)->post('/movewise/agents/'.$agent->ulid.'/submit')->assertSessionHasNoErrors();
    $this->actingAs($this->ann)->post('/movewise/agents/'.$agent->ulid.'/terms', ['agree' => false])->assertSessionHasErrors('agree');
    $this->actingAs($this->ann)->post('/movewise/agents/'.$agent->ulid.'/terms', ['agree' => true])->assertSessionHasNoErrors();

    $this->actingAs($this->staff)->get($this->hq.'/hq/movewise/program')->assertInertia(fn (Assert $p) => $p->component('hq/movewise-program')
        ->where('agents.0.profile_status', 'submitted')->where('agents.0.account', $this->tenant->name));
    $this->actingAs($this->staff)->post($this->hq.'/hq/movewise/program/agents/'.$agent->ulid.'/review', ['approve' => false])->assertSessionHasErrors('note');
    $this->actingAs($this->staff)->post($this->hq.'/hq/movewise/program/agents/'.$agent->ulid.'/review', ['approve' => true, 'discipline_clear' => true])->assertSessionHasNoErrors();
    expect($agent->refresh()->status)->toBe('active');

    // Changing the card sends it back for review, and the agent pauses until it is approved again.
    mwApply($this, ['bio' => 'I help first-time buyers and relocating families across Houston, explain every step plainly and reply within the hour.']);
    expect($agent->refresh()->profile_status)->toBe('submitted')->and($agent->status)->toBe('paused')->and($agent->pause_reason)->toBe('checks');
    $this->actingAs($this->staff)->post($this->hq.'/hq/movewise/program/agents/'.$agent->ulid.'/review', ['approve' => true])->assertSessionHasNoErrors();
    expect($agent->refresh()->status)->toBe('active');
});

it('lets HQ price each state, and never offers an unpriced model', function (): void {
    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])->post($this->hq.'/hq/movewise/program/states/TX/pricing', ['models' => ['connection'], 'share_pct' => 60])
        ->assertSessionHasErrors('share_pct');
    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])->post($this->hq.'/hq/movewise/program/states/TX/pricing', ['models' => ['connection', 'appointment'],
        'connection_buyer' => 300, 'connection_seller' => 0, 'appointment' => 0])->assertSessionHasNoErrors();
    $this->actingAs($this->staff)->get($this->hq.'/hq/movewise/program')->assertInertia(fn (Assert $p) => $p->where('states.3.code', 'TX')->where('states.3.offered', []));
    mwHqPrice($this);
    $this->actingAs($this->staff)->get($this->hq.'/hq/movewise/program')->assertInertia(fn (Assert $p) => $p->where('states.3.offered', ['connection', 'share']));
});

it('sets up an agent package and lets it go live only when the agent is live and the wallet can pay', function (): void {
    mwHqPrice($this);
    $agent = mwApply($this);
    $pkg = fn (array $over = []): array => $over + ['state' => 'TX', 'model' => 'connection', 'lead_types' => ['buyer'], 'languages' => ['en', 'es'], 'zips' => '77001, 77002 abc',
        'cities' => 'Houston, houston, Katy', 'price_min' => 200000, 'price_max' => 600000, 'monthly_cap' => 5, 'daily_cap' => 9,
        'days' => ['1' => ['08:00', '19:00'], '6' => ['09:00', '13:00']], 'tz' => 'America/Chicago'];
    $this->actingAs($this->ann)->post('/movewise/packages', $pkg(['model' => 'appointment']))->assertSessionHasErrors('model');
    $this->actingAs($this->ann)->post('/movewise/packages', $pkg(['monthly_cap' => 20]))->assertSessionHasErrors('monthly_cap'); // new agents start small
    $this->actingAs($this->ann)->post('/movewise/packages', $pkg(['days' => ['1' => ['19:00', '08:00']]]))->assertSessionHasErrors('days');
    $this->actingAs($this->ann)->post('/movewise/packages', $pkg(['zips' => '', 'cities' => '']))->assertSessionHasErrors('areas');
    $this->actingAs($this->ann)->post('/movewise/packages', $pkg())->assertSessionHasNoErrors();
    $p = Package::query()->sole();
    expect($p->areas)->toEqual(['zips' => ['77001', '77002'], 'cities' => ['Houston', 'Katy']])->and($p->daily_cap)->toBe(5)->and($p->payer)->toBe('agent')
        ->and($p->payer_user_id)->toBe($this->ann->id)->and($p->hours['days'])->toEqual(['1' => ['08:00', '19:00'], '6' => ['09:00', '13:00']]);

    $this->actingAs($this->ann)->post('/movewise/packages/'.$p->ulid.'/activate')->assertSessionHasErrors('package');
    $this->actingAs($this->ann)->get('/movewise')->assertInertia(fn (Assert $page) => $page->component('movewise/program')
        ->where('packages.0.missing', ['You\'re not live in the program yet', 'At least 300 credits in the agent\'s wallet']));

    app(Agents::class)->confirmBrokerage($agent, $this->owner, true);
    $agent->forceFill(['discipline_checked_at' => now(), 'profile_status' => 'approved', 'terms_version' => Agents::TERMS_VERSION, 'terms_signed_at' => now()])->save();
    app(Agents::class)->refresh($agent);
    app(CreditLedger::class)->add($this->ann->id, 300, 'adjust', 'Test credits', $this->owner);
    $this->actingAs($this->ann)->post('/movewise/packages/'.$p->ulid.'/activate')->assertSessionHasNoErrors();
    expect($p->refresh()->status)->toBe('active');

    // A lapsed licence at the monthly re-check pauses the agent and the package.
    $this->verifier->records['TX:0654321']['status'] = 'expired';
    $this->travel(31)->days();
    $this->artisan('movewise:recheck-licences')->assertSuccessful();
    expect($agent->refresh()->status)->toBe('paused')->and($agent->licence_status)->toBe('inactive')->and($p->refresh()->status)->toBe('paused');
});

it('lets the owner buy a broker package for confirmed agents, paid from the brokerage pool', function (): void {
    mwHqPrice($this);
    $agent = mwApply($this);
    $in = ['broker' => true, 'state' => 'TX', 'model' => 'share', 'lead_types' => ['buyer', 'seller'], 'languages' => ['en'], 'cities' => 'Houston', 'monthly_cap' => 30,
        'days' => ['1' => ['08:00', '18:00']], 'agents' => [$agent->ulid]];
    $this->actingAs($this->ann)->post('/movewise/packages', $in)->assertForbidden();
    $this->actingAs($this->owner)->post('/movewise/packages', $in)->assertSessionHasErrors('agents'); // not confirmed yet
    app(Agents::class)->confirmBrokerage($agent, $this->owner, true);
    $this->actingAs($this->owner)->post('/movewise/packages', $in)->assertSessionHasErrors('monthly_cap'); // one new agent: 5 at most
    $this->actingAs($this->owner)->post('/movewise/packages', ['monthly_cap' => 5] + $in)->assertSessionHasNoErrors();
    $p = Package::query()->sole();
    expect($p->payer)->toBe('brokerage')->and($p->agent_id)->toBeNull()->and($p->agentIds())->toBe([$agent->id]);
    $this->actingAs($this->ann)->get('/movewise')->assertInertia(fn (Assert $page) => $page->has('packages', 0)); // agents don't see the brokerage's packages
});

it('gives agents outside RightAlly a free Movewise Agent seat after HQ approves', function (): void {
    $mw = 'https://movewisehomes.com';
    $this->get($mw.'/agents')->assertOk()->assertSee('Ask for a seat')->assertSee('"@type":"FAQPage"', false);
    $form = ['first_name' => 'Sam', 'last_name' => 'Seat', 'email' => 'Sam@Seat.test', 'state' => 'TX', 'licence_number' => '0700000', 'brokerage_name' => 'Gulf Realty', 'agree' => '1'];
    $this->post($mw.'/agents/join', $form)->assertForbidden(); // must come from Movewise's own page
    $this->post($mw.'/agents/join', ['agree' => ''] + $form, ['Origin' => $mw])->assertStatus(422)->assertSee('Tick to agree');
    $this->post($mw.'/agents/join', $form, ['Origin' => $mw])->assertRedirect('/agents?sent=1');
    $req = SeatRequest::query()->sole();
    expect($req->email)->toBe('sam@seat.test')->and($req->licence_status)->toBe('active');

    $this->actingAs($this->staff)->post($this->hq.'/hq/movewise/program/seats/'.$req->ulid, ['approve' => true])->assertSessionHasNoErrors();
    $seat = Tenant::query()->findOrFail($req->refresh()->tenant_id);
    expect($seat->type->value)->toBe('solo')->and((bool) $seat->getAttribute('billing_exception'))->toBeTrue()
        ->and(app(TenantContext::class)->runAs($seat, fn () => Invitation::query()->where('email', 'sam@seat.test')->exists()))->toBeTrue();

    // Once they accept and sign in, their application starts from the request, marked as a seat.
    $sam = app(TenantContext::class)->runAs($seat, fn () => User::factory()->owner()->create(['first_name' => 'Sam', 'last_name' => 'Seat', 'email' => 'sam@seat.test']));
    app(TenantContext::class)->set($seat);
    $agent = app(Agents::class)->apply($sam, ['state' => 'TX', 'licence_number' => '0700000']);
    expect($agent->seat)->toBeTrue()->and($agent->brokerage_name)->toBe('Gulf Realty')->and($agent->brokerage_confirmed_at)->toBeNull()
        ->and(app(Agents::class)->missing($agent))->toContain('Your brokerage confirmed (HQ contacts them)');
});
