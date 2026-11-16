<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Credits\CreditLedger;
use Modules\Core\Messaging\FakeSmsSender;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Booking\Availability;
use Modules\Crm\FollowUpPlans;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\FollowUpPlan;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Task;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Movewise\Growth\AdBudget;
use Modules\Movewise\Mail\PersonUpdate;
use Modules\Movewise\Matching\AcceptClock;
use Modules\Movewise\Matching\Connections as Intros;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Connection;
use Modules\Movewise\Models\Package;
use Modules\Movewise\Models\Partner;
use Modules\Movewise\Models\Person;
use Modules\Movewise\Models\State;
use Modules\Movewise\Movewise;
use Modules\Movewise\Notifications\IntroductionClosed;
use Modules\Movewise\Notifications\IntroductionOffered;
use Modules\Movewise\Program\Agents;
use Modules\Sites\Reviews\Reviews;

/* Movewise MW4: "Talk to an agent" — about your move and the readiness score, three agents chosen fairly (eligibility,
   caps, rotation, a place for someone not shown recently), the verified mobile, the consent, the accept timer,
   the charge on accept, the lead in the agent's CRM with the brief, expiry and choosing again, and refunds. */

const MWH = 'https://movewisehomes.com';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-02 15:00', 'UTC')); // Tuesday 10:00 in Houston
    Notification::fake();
    Mail::fake();
    app()->instance(SmsSender::class, $this->sms = new FakeSmsSender);
    config(['core.hq_host' => 'app.rightally.io', 'movewise.host' => 'movewisehomes.com', 'listings.cdn_url' => 'https://cdn.movewise.test']);
    $this->artisan('tenants:bootstrap-hq');
    $this->hqTenant = Tenant::query()->where('type', 'hq')->sole();
    $this->staff = app(TenantContext::class)->runAs($this->hqTenant, fn () => User::factory()->hqStaff()->create());
    app(TenantContext::class)->forget();
    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $source = MlsSource::query()->create(['key' => 'har', 'name' => 'HAR', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $feed->records = [['ListingKey' => 'H1', 'ListingId' => 'MH1', 'StandardStatus' => 'Active', 'PropertyType' => 'Residential', 'ListPrice' => 400000, 'BedroomsTotal' => 3,
        'BathroomsFull' => 2, 'LivingArea' => 1900, 'StreetNumber' => '12', 'StreetName' => 'Oak St', 'City' => 'Houston', 'StateOrProvince' => 'TX', 'PostalCode' => '77001', 'SubdivisionName' => 'Oak Forest',
        'Latitude' => 29.7, 'Longitude' => -95.3, 'ListAgentMlsId' => 'A1', 'ListOfficeName' => 'Bayou Realty', 'ModificationTimestamp' => '2026-05-31T14:30:00Z']];
    app(ListingSync::class)->run($source);
    $partner = Partner::query()->create(['name' => 'Lone Star Partners LLC', 'broker_name' => 'Pat', 'licence_number' => '9001234', 'licence_state' => 'TX', 'contract_signed_on' => '2026-05-01', 'status' => 'active']);
    $auth = app(MlsAccess::class)->request($this->hqTenant->id, $source, 'B-LS1', [], 'Movewise TX', $this->staff);
    app(MlsAccess::class)->approve($auth, ['idx'], null, null, $this->staff);
    State::query()->findOrFail('TX')->forceFill(['partner_id' => $partner->id, 'checklist' => array_map(fn (): array => ['done' => true], Movewise::CHECKLIST),
        'pricing' => ['models' => ['connection'], 'connection' => ['buyer' => 300, 'seller' => 450]]])->save();
    app(Movewise::class)->switchState(State::query()->findOrFail('TX'), true, $this->staff);
    $this->listing = (string) Listing::query()->value('ulid');

    // A brokerage with five live agents and their packages.
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    DB::connection(Connections::control())->table('credit_settings')->insert(['credit_value_microcents' => 1_000_000, 'enforce' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(CreditLedger::class)->savePolicy(false, null, $this->owner);
    $this->agents = [];
    foreach (['Ann', 'Ben', 'Cal', 'Dee', 'Eve'] as $i => $name) {
        $u = User::factory()->create(['first_name' => $name, 'last_name' => 'Agent']);
        app(CreditLedger::class)->add($u->id, 1000, 'adjust', 'Test credits', $this->owner);
        $a = Agent::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $u->id, 'state' => 'TX', 'licence_number' => '06543'.$i, 'licence_status' => 'active',
            'brokerage_name' => 'Acme Realty', 'brokerage_confirmed_at' => now(), 'discipline_checked_at' => now(), 'profile_status' => 'approved', 'terms_version' => Agents::TERMS_VERSION,
            'terms_signed_at' => now(), 'status' => 'active', 'tier' => $name === 'Ann' ? 'preferred' : 'standard',
            'profile' => ['display_name' => $name.' Agent', 'bio' => str_repeat('Helpful and quick. ', 6), 'languages' => $name === 'Dee' ? ['en', 'es'] : ['en'], 'years' => 5 + $i, 'specialties' => [], 'photo' => null]]);
        Package::query()->create(['tenant_id' => $this->tenant->id, 'agent_id' => $a->id, 'payer' => 'agent', 'payer_user_id' => $u->id, 'name' => $name, 'state' => 'TX',
            'areas' => ['zips' => $name === 'Eve' ? ['75001'] : ['77001'], 'cities' => []], 'price_min' => 0, 'price_max' => $name === 'Cal' ? 300000 : 0, 'lead_types' => ['buyer', 'seller'],
            'languages' => $name === 'Dee' ? ['en', 'es'] : ['en'], 'monthly_cap' => 5, 'daily_cap' => null,
            'hours' => ['tz' => 'America/Chicago', 'days' => [1 => ['08:00', '19:00'], 2 => ['08:00', '19:00'], 3 => ['08:00', '19:00'], 4 => ['08:00', '19:00'], 5 => ['08:00', '19:00']]],
            'model' => 'connection', 'status' => 'active', 'created_by' => $u->id]);
        $this->agents[$name] = [$a, $u];
    }
    app(TenantContext::class)->forget();

    $this->person = Person::query()->create(['email' => 'buyer@example.com', 'locale' => 'en', 'email_verified_at' => now()]);
    $this->sid = str_repeat('ab', 32);
    DB::connection(Connections::control())->table('movewise_sessions')->insert(['token_hash' => hash('sha256', $this->sid), 'person_id' => $this->person->id,
        'expires_at' => now()->addDays(90), 'created_at' => now()]);
    $this->from = ['Origin' => MWH];
});

function mwAs(object $t): object
{
    return $t->withUnencryptedCookie('mw_sid', $t->sid);
}

function mwShow(object $t, array $about = []): string
{
    mwAs($t)->post(MWH.'/connect', $about + ['first_name' => 'Bea', 'lead_type' => 'buyer', 'timeline' => 'now', 'preapproved' => 'yes', 'language' => 'en', 'listing' => $t->listing], $t->from)
        ->assertStatus(303);

    return (string) DB::connection(Connections::control())->table('movewise_shows')->orderByDesc('id')->value('ulid');
}

function mwVerify(object $t, string $show): void
{
    mwAs($t)->post(MWH.'/connect/'.$show.'/phone', ['phone' => '(713) 555-0142'], $t->from)->assertOk()->assertSee('We texted you a code');
    preg_match('/code is (\d{6})/', $t->sms->sent[0]['message'], $m);
    mwAs($t)->post(MWH.'/connect/'.$show.'/phone', ['code' => '000000'], $t->from)->assertStatus(422);
    mwAs($t)->post(MWH.'/connect/'.$show.'/phone', ['code' => $m[1]], $t->from)->assertOk()->assertSee('Mobile verified: (***) ***-0142');
}

it('times the accept window from the agent\'s working hours', function (): void {
    $hours = ['tz' => 'America/Chicago', 'days' => [1 => ['08:00', '19:00'], 2 => ['08:00', '19:00']]];
    expect(AcceptClock::deadline($hours, CarbonImmutable::parse('2026-06-01 15:00', 'UTC'))->toDateTimeString())->toBe('2026-06-01 15:05:00') // Monday 10:00 CDT
        ->and(AcceptClock::deadline($hours, CarbonImmutable::parse('2026-06-01 11:00', 'UTC'))->toDateTimeString())->toBe('2026-06-01 13:30:00') // 6:00, before the start
        ->and(AcceptClock::deadline($hours, CarbonImmutable::parse('2026-06-02 02:00', 'UTC'))->toDateTimeString())->toBe('2026-06-02 13:30:00') // Monday 21:00: Tuesday 8:30
        ->and(AcceptClock::deadline($hours, CarbonImmutable::parse('2026-06-03 02:00', 'UTC'))->toDateTimeString())->toBe('2026-06-08 13:30:00'); // Tuesday night: next Monday
});

it('never shows agents to people who already have one, and scores readiness from what they said and did', function (): void {
    $this->get(MWH.'/connect?listing='.$this->listing)->assertOk()->assertSee('Email me a code'); // signed out
    mwAs($this)->get(MWH.'/connect?listing='.$this->listing)->assertOk()->assertSee('When do you hope to move?')->assertHeader('Cache-Control', 'no-store, private');
    mwAs($this)->post(MWH.'/connect', ['first_name' => 'Bea', 'lead_type' => 'buyer', 'timeline' => 'now', 'has_agent' => '1', 'listing' => $this->listing], $this->from)->assertOk()->assertSee('bring your agent along');
    expect(DB::connection(Connections::control())->table('movewise_shows')->count())->toBe(0)->and($this->person->refresh()->readiness)->toBe(30 + 8); // email verified only
});

it('shows three eligible agents, keeps a place for someone not shown lately, and logs why', function (): void {
    $show = mwShow($this);
    $row = DB::connection(Connections::control())->table('movewise_shows')->where('ulid', $show)->sole();
    $ids = array_column(json_decode($row->agents, true), 'agent');
    // Cal's range stops at $300k and Eve covers another ZIP: never eligible. Ann is Preferred: always first.
    expect($ids)->toHaveCount(3)->and($ids[0])->toBe($this->agents['Ann'][0]->id)->and($ids)->not->toContain($this->agents['Cal'][0]->id)->not->toContain($this->agents['Eve'][0]->id)
        ->and(json_decode($row->context, true))->toMatchArray(['state' => 'TX', 'zip' => '77001', 'price' => 400000, 'lead_type' => 'buyer'])
        ->and(json_decode($row->agents, true)[0]['reasons'])->toContain('Covers ZIP 77001');
    mwAs($this)->get(MWH.'/connect/'.$show)->assertOk()->assertSee('Choose one agent')->assertSee('First, your mobile number')->assertDontSee('Connect with');

    // Spanish: only agents who work in Spanish.
    $es = mwShow($this, ['language' => 'es']);
    expect(array_column(json_decode((string) DB::connection(Connections::control())->table('movewise_shows')->where('ulid', $es)->value('agents'), true), 'agent'))
        ->toBe([$this->agents['Dee'][0]->id]);
});

it('takes the consent from a verified mobile, tells only the chosen agent, and charges only when they accept', function (): void {
    $show = mwShow($this);
    mwAs($this)->post(MWH.'/connect/'.$show.'/choose', ['agent' => $this->agents['Ann'][0]->ulid, 'method' => 'text'], $this->from)->assertStatus(422)->assertSee('Verify your mobile number first');
    mwVerify($this, $show);
    mwAs($this)->post(MWH.'/connect/'.$show.'/choose', ['agent' => $this->agents['Ann'][0]->ulid, 'method' => 'text'], ['Origin' => 'https://evil.test'])->assertForbidden();
    mwAs($this)->post(MWH.'/connect/'.$show.'/choose', ['agent' => $this->agents['Ann'][0]->ulid, 'method' => 'text'], $this->from)->assertRedirect('/account#agent');
    $c = Connection::query()->sole();
    expect($c->consent_text)->toContain('I choose Ann Agent of Acme Realty (licence 065430) and agree that they may text me at (***) ***-0142 about buying a home')
        ->and($c->accept_by->toDateTimeString())->toBe('2026-06-02 15:05:00')->and($c->status)->toBe('offered');
    Notification::assertSentTo($this->agents['Ann'][1], IntroductionOffered::class);
    Notification::assertNotSentTo($this->agents['Ben'][1], IntroductionOffered::class);
    mwAs($this)->get(MWH.'/account')->assertSee('Waiting for Ann Agent to accept');
    mwAs($this)->post(MWH.'/connect/'.$show.'/choose', ['agent' => $this->agents['Ben'][0]->ulid, 'method' => 'call'], $this->from)->assertStatus(422); // one agent at a time

    // The agent's brief hides contact details until they accept.
    app(TenantContext::class)->set($this->tenant);
    [, $ann] = $this->agents['Ann'];
    $this->actingAs($ann)->get('/movewise/introductions/'.$c->ulid)->assertInertia(fn (Assert $p) => $p->component('movewise/introduction')->where('brief.phone', null)
        ->where('brief.name', 'Bea')->where('brief.readiness.score', fn ($s): bool => $s >= 50));
    $this->actingAs($this->agents['Ben'][1])->get('/movewise/introductions/'.$c->ulid)->assertNotFound();
    $this->actingAs($ann)->post('/movewise/introductions/'.$c->ulid, ['action' => 'accept'])->assertSessionHasNoErrors();
    expect($c->refresh()->status)->toBe('accepted')->and($c->price_credits)->toBe(300)->and(app(CreditLedger::class)->balanceOf($ann->id))->toBe(700);
    $lead = Lead::query()->findOrFail($c->lead_id);
    expect($lead->contact->primary_phone)->toBe('+17135550142')->and($lead->contact->primary_email)->toBe('buyer@example.com')->and($lead->intent)->toBe('buy')
        ->and($lead->source_detail ?? $lead->contact->source_detail)->toBe('Movewise');
    Mail::assertSent(PersonUpdate::class, fn (PersonUpdate $m): bool => str_contains($m->heading, 'Ann Agent will be in touch') && str_contains($m->links[0][1], '/connect/withdraw/'.$c->ulid.'/'));
    $this->actingAs($ann)->post('/movewise/introductions/'.$c->ulid, ['action' => 'accept'])->assertSessionHasErrors('introduction');
    expect(app(CreditLedger::class)->balanceOf($ann->id))->toBe(700); // never charged twice
    $this->actingAs($ann)->get('/movewise/introductions/'.$c->ulid)->assertInertia(fn (Assert $p) => $p->where('brief.phone', '+17135550142'));

    // "Didn't ask for this?" from the email: ends it and refunds the agent in full.
    app(TenantContext::class)->forget();
    $link = Intros::signedUrl('/connect/withdraw/'.$c->ulid);
    $this->get(str_replace('/'.substr($link, -32), '/'.str_repeat('0', 32), $link))->assertNotFound();
    $this->get($link)->assertOk()->assertSee('End this introduction?');
    $this->post($link, [], $this->from)->assertOk()->assertSee('The agent has been told not to contact you');
    expect($c->refresh()->status)->toBe('withdrawn')->and($c->refund_status)->toBe('refunded')
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => app(CreditLedger::class)->balanceOf($ann->id)))->toBe(1000);
});

it('expires an unanswered introduction without charging, and lets the person wait once or choose again', function (): void {
    $show = mwShow($this);
    mwVerify($this, $show);
    mwAs($this)->post(MWH.'/connect/'.$show.'/choose', ['agent' => $this->agents['Ann'][0]->ulid, 'method' => 'call'], $this->from)->assertRedirect();
    $c = Connection::query()->sole();
    $this->travel(6)->minutes();
    $this->artisan('movewise:expire-offers')->assertSuccessful();
    expect($c->refresh()->status)->toBe('expired');
    Mail::assertSent(PersonUpdate::class, fn (PersonUpdate $m): bool => str_contains($m->heading, 'hasn\'t been able to reply yet'));
    mwAs($this)->get(MWH.'/account')->assertSee('Ann Agent hasn\'t been able to reply yet')->assertSee('Wait for Ann Agent');
    mwAs($this)->get(MWH.'/connect/'.$show)->assertOk()->assertDontSee('Connect with Ann Agent')->assertSee('Connect with'); // the other two

    mwAs($this)->post(MWH.'/account/connections/'.$c->ulid.'/wait', [], $this->from)->assertRedirect('/account#agent');
    expect($c->refresh()->status)->toBe('offered')->and($c->accept_by->gt(now()))->toBeTrue();
    $this->travel(6)->minutes();
    $this->artisan('movewise:expire-offers');
    mwAs($this)->post(MWH.'/account/connections/'.$c->ulid.'/wait', [], $this->from)->assertStatus(422); // only once
    expect(app(TenantContext::class)->runAs($this->tenant, fn () => app(CreditLedger::class)->balanceOf($this->agents['Ann'][1]->id)))->toBe(1000);

    // A new choice never goes back to an agent who didn't answer.
    $again = mwShow($this);
    expect(array_column(json_decode((string) DB::connection(Connections::control())->table('movewise_shows')->where('ulid', $again)->value('agents'), true), 'agent'))
        ->not->toContain($this->agents['Ann'][0]->id);
});

it('books "Pick a time" on the agent\'s real calendar, and handles a wrong-number refund through HQ', function (): void {
    [$ann, $annUser] = $this->agents['Ann'];
    app(TenantContext::class)->runAs($this->tenant, fn () => app(Availability::class)->save($annUser, true, Availability::DEFAULT_HOURS, 30, 2, $annUser));
    $show = mwShow($this);
    mwVerify($this, $show);
    $slot = app(Intros::class)->slots($ann)[0];
    mwAs($this)->post(MWH.'/connect/'.$show.'/choose', ['agent' => $ann->ulid, 'method' => 'time', 'slot' => '2030-01-01T10:00:00+00:00'], $this->from)->assertStatus(422);
    mwAs($this)->post(MWH.'/connect/'.$show.'/choose', ['agent' => $ann->ulid, 'method' => 'time', 'slot' => $slot->toIso8601String()], $this->from)->assertRedirect();
    $c = Connection::query()->sole();
    expect($c->slot_at->eq($slot))->toBeTrue()->and($c->consent_text)->toContain('call me for our appointment on');

    app(TenantContext::class)->set($this->tenant);
    $this->actingAs($annUser)->post('/movewise/introductions/'.$c->ulid, ['action' => 'accept'])->assertSessionHasNoErrors();
    $task = Task::query()->where('contact_id', Lead::query()->findOrFail($c->refresh()->lead_id)->contact_id)->where('assigned_to', $annUser->id)->sole();
    expect($task->due_at->eq($slot))->toBeTrue();
    $this->actingAs($annUser)->post('/movewise/introductions/'.$c->ulid, ['action' => 'report', 'reason' => ''])->assertSessionHasErrors('refund');
    $this->actingAs($annUser)->post('/movewise/introductions/'.$c->ulid, ['action' => 'report', 'reason' => 'The number belongs to someone else'])->assertSessionHasNoErrors();
    expect($c->refresh()->refund_status)->toBe('requested');

    app(TenantContext::class)->forget();
    $hq = 'http://app.rightally.io';
    $this->actingAs($this->staff)->get($hq.'/hq/movewise/program')->assertInertia(fn (Assert $p) => $p->where('refunds.0.reason', 'The number belongs to someone else'));
    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])->post($hq.'/hq/movewise/program/refunds/'.$c->ulid, ['approve' => true])->assertSessionHasNoErrors();
    expect($c->refresh()->refund_status)->toBe('refunded')
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => app(CreditLedger::class)->balanceOf($annUser->id)))->toBe(1000);
});

/* MW5: the bridge — relay numbers, personal messages only, PAUSE / RESUME / CHANGE / STOP, and handing over. */

function mwAccepted(object $t, string $method = 'text', array $accept = []): Connection
{
    DB::connection(Connections::control())->table('movewise_relay_numbers')->insert(['number' => '+17135550900', 'area' => 'Houston', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(MessagingSettings::class)->save('AC'.str_repeat('1', 32), 'twilio-auth-token-0000000', '+15125550111', '+15125550112', true, false, $t->staff);
    app(MessagingSettings::class)->setTenant($t->tenant->id, true, false, '+17135550111');
    $show = mwShow($t);
    mwVerify($t, $show);
    mwAs($t)->post(MWH.'/connect/'.$show.'/choose', ['agent' => $t->agents['Ann'][0]->ulid, 'method' => $method], $t->from)->assertRedirect();
    $c = Connection::query()->orderByDesc('id')->firstOrFail();
    app(TenantContext::class)->set($t->tenant);
    $t->actingAs($t->agents['Ann'][1])->post('/movewise/introductions/'.$c->ulid, ['action' => 'accept'] + $accept)->assertSessionHasNoErrors();

    return $c->refresh();
}

it('bridges texts through a relay number and keeps the contact to personal messages only', function (): void {
    $c = mwAccepted($this);
    [, $ann] = $this->agents['Ann'];
    expect($c->relay_number)->toBe('+17135550900')->and($c->contact_id)->not->toBeNull()
        ->and(app(MessagingSettings::class)->tenantForReply('+17135550142', '+17135550900'))->toBe($this->tenant->id); // replies find Ann's inbox
    $contact = Contact::query()->findOrFail($c->contact_id);
    $this->actingAs($ann);
    app(Inbox::class)->send($contact, 'sms', 'Hi Bea, it\'s Ann. When suits you to see 12 Oak St?', null, $ann);
    expect(end($this->sms->delivered))->toMatchArray(['from' => '+17135550900', 'to' => '+17135550142']);

    $blocked = [];
    expect(app(Inbox::class)->sendAutomated($contact, 'sms', 'Just checking in!', null, 'Acme', $blocked))->toBeNull()
        ->and($blocked)->toContain('A Movewise introduction: personal messages only (no campaigns, plans or automated texts).');
    $plan = FollowUpPlan::query()->create(['name' => 'Drip', 'owner_user_id' => $ann->id, 'shared' => true, 'active' => true]);
    expect(fn () => app(FollowUpPlans::class)->enroll($contact, $plan, $ann))->toThrow(ValidationException::class);
});

it('lets the person pause, resume and stop by text, and change agent from My Movewise', function (): void {
    $c = mwAccepted($this);
    [, $ann] = $this->agents['Ann'];
    $contact = Contact::query()->findOrFail($c->contact_id);
    $inbox = app(Inbox::class);
    expect($inbox->receiveSms('+17135550142', 'pause', 'SM1'))->toContain('paused');
    expect($c->refresh()->paused_until?->isFuture())->toBeTrue();
    $this->actingAs($ann);
    expect(fn () => $inbox->send($contact, 'sms', 'Hi Bea!', null, $ann))->toThrow(ValidationException::class);
    expect($inbox->receiveSms('+17135550142', 'Resume', 'SM2'))->toContain('resumed');
    $inbox->send($contact, 'sms', 'Hi Bea!', null, $ann);
    Notification::assertSentTo($ann, IntroductionClosed::class);

    // Change agent: the introduction ends, Ann can't message them, and three agents are shown again without her.
    app(TenantContext::class)->forget();
    $r = mwAs($this)->post(MWH.'/account/connections/'.$c->ulid.'/change', [], $this->from)->assertStatus(303);
    expect($c->refresh()->status)->toBe('changed');
    $show = DB::connection(Connections::control())->table('movewise_shows')->orderByDesc('id')->first();
    expect($r->headers->get('Location'))->toEndWith('/connect/'.$show->ulid)->and(array_column(json_decode($show->agents, true), 'agent'))->not->toContain($this->agents['Ann'][0]->id);
    app(TenantContext::class)->set($this->tenant);
    expect(fn () => $inbox->send($contact->refresh(), 'sms', 'Still there?', null, $ann))->toThrow(ValidationException::class);
});

it('ends the introduction on STOP, and hands over to the agent\'s own number only when the person says YES', function (): void {
    $c = mwAccepted($this);
    [, $ann] = $this->agents['Ann'];
    $inbox = app(Inbox::class);
    $this->actingAs($ann)->post('/movewise/introductions/'.$c->ulid, ['action' => 'handover'])->assertSessionHasErrors('handover'); // needs an agreement or closing
    $this->actingAs($ann)->post('/movewise/introductions/'.$c->ulid, ['action' => 'handover', 'signed_or_closed' => '1'])->assertSessionHasNoErrors();
    expect(end($this->sms->delivered))->toMatchArray(['from' => '+17135550900', 'to' => '+17135550142'])->and(end($this->sms->delivered)['message'])->toContain('Reply YES');
    expect($inbox->receiveSms('+17135550142', 'YES', 'SM3'))->toContain('Movewise is stepping back');
    $contact = Contact::query()->findOrFail($c->contact_id);
    $inbox->send($contact, 'sms', 'Hi Bea, Ann here on my own number.', null, $ann);
    expect(end($this->sms->delivered)['from'])->toBe('+17135550111');
    $blocked = [];
    $inbox->sendAutomated($contact, 'sms', 'Hello', null, 'Acme', $blocked);
    expect($blocked)->not->toContain('A Movewise introduction: personal messages only (no campaigns, plans or automated texts).');
});

it('stops contact when the person texts STOP', function (): void {
    $c = mwAccepted($this);
    expect(app(Inbox::class)->receiveSms('+17135550142', 'STOP', 'SM9'))->toBeNull();
    expect($c->refresh()->status)->toBe('stopped')->and($c->end_reason)->toBe('Replied STOP')
        ->and(Contact::query()->findOrFail($c->contact_id)->sms_opt_out_at)->not->toBeNull();
});

/* MW6: status flow-back, reminders and pauses, per-appointment and share-at-close billing, referral invoices,
   partner settlements, ratings, the scorecard and tiers, and strikes. */

function mwModel(object $t, string $model): void
{
    State::query()->findOrFail('TX')->forceFill(['pricing' => ['models' => ['connection', 'appointment', 'share'], 'connection' => ['buyer' => 300, 'seller' => 450],
        'appointment' => 200, 'share_pct' => 25]])->save();
    Package::query()->update(['model' => $model]);
}

it('keeps the status flowing back, reminds after 14 days and pauses the package a week later', function (): void {
    $c = mwAccepted($this);
    [, $ann] = $this->agents['Ann'];
    $this->actingAs($ann)->post('/movewise/introductions/'.$c->ulid, ['action' => 'status', 'stage' => 'appointment'])->assertSessionHasNoErrors();
    $this->actingAs($ann)->post('/movewise/introductions/'.$c->ulid, ['action' => 'status', 'stage' => 'contacted'])->assertSessionHasErrors('stage'); // forward only
    $this->travel(15)->days();
    $this->artisan('movewise:outcomes')->assertSuccessful();
    Notification::assertSentTo($ann, IntroductionClosed::class, fn ($n): bool => str_contains($n->subject, 'Update your Movewise client'));
    $this->travel(8)->days();
    $this->artisan('movewise:outcomes');
    expect(Package::query()->findOrFail($c->package_id)->status)->toBe('paused');
});

it('charges per appointment only when both sides say it was held', function (): void {
    mwModel($this, 'appointment');
    $c = mwAccepted($this);
    [, $ann] = $this->agents['Ann'];
    expect($c->price_credits)->toBe(0);
    $this->actingAs($ann)->post('/movewise/introductions/'.$c->ulid, ['action' => 'status', 'stage' => 'met'])->assertSessionHasNoErrors();
    expect($c->refresh()->price_credits)->toBe(0);
    app(TenantContext::class)->forget();
    mwAs($this)->get(MWH.'/account')->assertSee('Did you meet Ann Agent?');
    mwAs($this)->post(MWH.'/account/connections/'.$c->ulid.'/feedback', ['met' => '1'], $this->from)->assertRedirect('/account#agent');
    mwAs($this)->post(MWH.'/account/connections/'.$c->ulid.'/feedback', ['met' => '1'], $this->from);
    expect($c->refresh()->price_credits)->toBe(200)
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => app(CreditLedger::class)->balanceOf($ann->id)))->toBe(800); // once
});

it('signs the referral agreement at accept, invoices at closing, and settles HQ\'s share with the partner', function (): void {
    mwModel($this, 'share');
    Partner::query()->update(['hq_share_pct' => 40]);
    DB::connection(Connections::control())->table('movewise_relay_numbers')->insert(['number' => '+17135550900', 'area' => 'Houston', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $show = mwShow($this);
    mwVerify($this, $show);
    mwAs($this)->post(MWH.'/connect/'.$show.'/choose', ['agent' => $this->agents['Ann'][0]->ulid, 'method' => 'call'], $this->from)->assertRedirect();
    $c = Connection::query()->sole();
    [, $ann] = $this->agents['Ann'];
    app(TenantContext::class)->set($this->tenant);
    $this->actingAs($ann)->post('/movewise/introductions/'.$c->ulid, ['action' => 'accept'])->assertSessionHasErrors('introduction');
    $this->actingAs($ann)->post('/movewise/introductions/'.$c->ulid, ['action' => 'accept', 'agree_referral' => '1'])->assertSessionHasNoErrors();
    expect($c->refresh()->referral_agreement)->toContain('a referral fee of 25% of the gross commission')->and($c->price_credits)->toBe(0)->and($c->share_pct)->toBe(25.0);

    $this->actingAs($ann)->post('/movewise/introductions/'.$c->ulid, ['action' => 'status', 'stage' => 'closed'])->assertSessionHasErrors('close_price');
    $this->actingAs($ann)->post('/movewise/introductions/'.$c->ulid, ['action' => 'status', 'stage' => 'closed', 'close_price' => '400,000', 'commission' => '12000',
        'close_date' => '2026-07-30'])->assertSessionHasNoErrors();
    $inv = DB::connection(Connections::control())->table('movewise_invoices')->sole();
    expect((int) $inv->amount_cents)->toBe(300_000)->and($inv->status)->toBe('issued');
    Notification::assertSentTo($this->owner, IntroductionClosed::class, fn ($n): bool => str_contains($n->subject, $inv->number));

    app(TenantContext::class)->forget();
    $hq = 'http://app.rightally.io';
    $as = fn () => $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()]);
    $as()->post($hq.'/hq/movewise/money/invoices/'.$inv->ulid, ['status' => 'paid'])->assertSessionHasNoErrors();
    $this->artisan('movewise:outcomes --settle='.now()->format('Y-m'))->assertSuccessful();
    $s = DB::connection(Connections::control())->table('movewise_settlements')->sole();
    expect((int) $s->collected_cents)->toBe(300_000)->and((int) $s->hq_cents)->toBe(120_000);
    $as()->get($hq.'/hq/movewise/money')->assertInertia(fn (Assert $p) => $p->component('hq/movewise-money')->where('invoices.0.status', 'paid')
        ->where('settlements.0.hq_cents', 120_000)->where('revenue.0.model', 'share'));
});

it('falls back to the per-connection price when a share-at-close lead goes quiet for 30 days', function (): void {
    mwModel($this, 'share');
    $c = mwAccepted($this, 'text', ['agree_referral' => '1']);
    expect($c->model)->toBe('share')->and($c->price_credits)->toBe(0);
    $this->travel(31)->days();
    $this->artisan('movewise:outcomes')->assertSuccessful();
    expect($c->refresh()->model)->toBe('connection')->and($c->price_credits)->toBe(300)
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => app(CreditLedger::class)->balanceOf($this->agents['Ann'][1]->id)))->toBe(700);
    $this->artisan('movewise:outcomes');
    expect(app(TenantContext::class)->runAs($this->tenant, fn () => app(CreditLedger::class)->balanceOf($this->agents['Ann'][1]->id)))->toBe(700); // once
});

it('rates agents, scores them weekly with the reasons shown, and removes them on the third strike', function (): void {
    [$annAgent, $ann] = $this->agents['Ann'];
    $now = CarbonImmutable::now();
    foreach (range(1, 5) as $i) {
        $p = Person::query()->create(['email' => "p{$i}@x.test", 'locale' => 'en']);
        Connection::query()->create(['person_id' => $p->id, 'show_id' => 1, 'agent_id' => $annAgent->id, 'package_id' => 1, 'tenant_id' => $this->tenant->id, 'lead_type' => 'buyer',
            'method' => 'text', 'consent_text' => 'x', 'consent_at' => $now, 'status' => 'accepted', 'offered_at' => $now->subDays(20), 'accept_by' => $now->subDays(20)->addMinutes(5),
            'accepted_at' => $now->subDays(20)->addMinutes(3), 'model' => 'connection', 'stage' => 'met', 'stage_at' => $now->subDays(2), 'rating' => 5]);
    }
    $this->artisan('movewise:outcomes --scorecards')->assertSuccessful();
    $card = $annAgent->refresh()->scorecard;
    expect($annAgent->tier)->toBe('preferred')->and($card['median_accept_minutes'])->toEqual(3)->and($card['appointments_per_100'])->toEqual(100)
        ->and($card['why'])->toContain('Met: Rating 4.5 or more');
    expect($this->agents['Ben'][0]->refresh()->tier)->toBe('new'); // nothing rated yet

    // Ratings: a week after the introduction.
    $c = mwAccepted($this);
    app(TenantContext::class)->forget();
    mwAs($this)->post(MWH.'/account/connections/'.$c->ulid.'/feedback', ['rating' => 5], $this->from)->assertStatus(422);
    $this->travel(8)->days();
    mwAs($this)->post(MWH.'/account/connections/'.$c->ulid.'/feedback', ['rating' => 4, 'note' => 'Quick and kind'], $this->from)->assertRedirect();
    expect($c->refresh()->rating)->toBe(4);

    $hq = 'http://app.rightally.io';
    foreach (range(1, 3) as $i) {
        $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])->post($hq.'/hq/movewise/program/agents/'.$annAgent->ulid.'/strike', ['reason' => 'Bulk texted a lead'])
            ->assertSessionHasNoErrors();
    }
    expect($annAgent->refresh()->status)->toBe('removed')->and($annAgent->strikes)->toBe(3);
});

/* MW7: city, ZIP and neighbourhood pages with market facts; verified reviews on agent profiles; Movewise's ads stop-loss. */

it('has a page for every city, ZIP code and neighbourhood, with market facts and plain answers', function (): void {
    $page = $this->get(MWH.'/texas/houston')->assertOk()->assertSee('Homes for sale in Houston, Texas')->assertSee('$400,000')->assertSee('"@type":"FAQPage"', false)
        ->assertSee('"@type":"Place"', false)->assertSee('Six-month market report')->assertSee('href="/texas/houston/oak-forest"', false)->assertSee('href="/texas/77001"', false)
        ->assertSee('<link rel="alternate" hreflang="es" href="https://movewisehomes.com/es/texas/houston">', false);
    expect($page->headers->get('Cache-Control'))->toContain('public');
    $this->get(MWH.'/texas/77001')->assertOk()->assertSee('ZIP code 77001');
    $this->get(MWH.'/texas/houston/oak-forest')->assertOk()->assertSee('Oak Forest, Houston');
    $this->get(MWH.'/es/texas/houston')->assertOk()->assertSee('¿Cuántas casas hay en venta en Houston, Texas?');
    $this->get(MWH.'/texas/atlantis')->assertNotFound();
    $this->get(MWH.'/texas')->assertSee('href="/texas/houston"', false);
    $this->get(MWH.'/sitemaps/tx.xml')->assertSee('https://movewisehomes.com/texas/houston')->assertSee('https://movewisehomes.com/texas/77001')->assertSee('https://movewisehomes.com/es/texas/houston');
});

it('publishes verified reviews only with the person\'s say-so, lets the agent reply once, and removes only with a logged reason', function (): void {
    $c = mwAccepted($this);
    [$annAgent, $ann] = $this->agents['Ann'];
    app(Reviews::class)->save('google', $ann->id, null, [['external_id' => 'g1', 'author' => 'Sam G.', 'rating' => 5, 'text' => 'Great on Google',
        'url' => null, 'reviewed_at' => now()->subMonth()]]);
    app(TenantContext::class)->forget();
    $this->travel(8)->days();
    mwAs($this)->post(MWH.'/account/connections/'.$c->ulid.'/feedback', ['rating' => 5, 'note' => 'Ann was great, call her on 713-555-0199 or ann@x.com', 'public' => '1'], $this->from)
        ->assertRedirect();
    expect($c->refresh()->rating_note)->toBe('Ann was great, call her on [removed] or [removed]');
    $profile = $this->get(MWH.'/agent/'.$annAgent->ulid.'/ann-agent')->assertOk()->assertSee('Verified Movewise client')->assertSee('Ann was great')
        ->assertSee('"@type":"AggregateRating"', false)->assertSee('5 out of 5 from 1 verified Movewise clients')
        ->assertSee('Reviews from elsewhere')->assertSee('Great on Google')->assertSee('not part of the Movewise rating');

    app(TenantContext::class)->set($this->tenant);
    $this->actingAs($ann)->post('/movewise/introductions/'.$c->ulid, ['action' => 'reply', 'reply' => 'Thank you, Bea!'])->assertSessionHasNoErrors();
    $this->actingAs($ann)->post('/movewise/introductions/'.$c->ulid, ['action' => 'reply', 'reply' => 'Again'])->assertSessionHasErrors('reply');
    app(TenantContext::class)->forget();
    $this->get(MWH.'/agent/'.$annAgent->ulid)->assertSee('Reply from Ann Agent');

    $hq = 'http://app.rightally.io';
    $as = fn () => $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()]);
    $as()->post($hq.'/hq/movewise/money/reviews/'.$c->ulid.'/remove', ['reason' => 'abuse'])->assertSessionHasErrors('reason');
    $as()->post($hq.'/hq/movewise/money/reviews/'.$c->ulid.'/remove', ['reason' => 'personal_data', 'note' => 'Named a neighbour'])->assertSessionHasNoErrors();
    $this->get(MWH.'/agent/'.$annAgent->ulid)->assertDontSee('Ann was great')->assertSee('No Movewise reviews yet');
});

it('holds the ads top-up when the last week costs more per accepted introduction than the stop-loss', function (): void {
    $hq = 'http://app.rightally.io';
    $as = fn () => $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()]);
    $as()->post($hq.'/hq/movewise/money/ads', ['monthly_budget' => '5000', 'weekly_topup' => '1000', 'target' => '80', 'stop_loss' => '50'])->assertSessionHasErrors('stop_loss');
    $as()->post($hq.'/hq/movewise/money/ads', ['monthly_budget' => '5000', 'weekly_topup' => '1000', 'target' => '80', 'stop_loss' => '150'])->assertSessionHasNoErrors();
    $as()->post($hq.'/hq/movewise/money/ads', ['kind' => 'spend', 'day' => now()->toDateString(), 'channel' => 'meta', 'state' => 'TX', 'spend' => '400'])->assertSessionHasNoErrors();
    $as()->get($hq.'/hq/movewise/money')->assertInertia(fn (Assert $p) => $p->where('ads.status', 'held')->where('ads.summary.spend_7d', 40_000));
    Notification::assertSentTo($this->staff, IntroductionClosed::class, fn ($n): bool => str_contains($n->subject, 'top-up held'));

    mwAccepted($this); // one accepted introduction: $400 each, still above $150
    expect(app(AdBudget::class)->check())->toBe('held');
    foreach (range(1, 2) as $i) {
        Connection::query()->create(['person_id' => $this->person->id, 'show_id' => 1, 'agent_id' => $this->agents['Ben'][0]->id, 'package_id' => 1, 'tenant_id' => $this->tenant->id,
            'lead_type' => 'buyer', 'method' => 'text', 'consent_text' => 'x', 'consent_at' => now(), 'status' => 'accepted', 'offered_at' => now(), 'accept_by' => now(), 'accepted_at' => now(),
            'model' => 'connection']);
    }
    expect(app(AdBudget::class)->check())->toBe('ok'); // $133 each
});

/* MW8: launch hardening — launch order, launch checks, audit packs, calls through the relay, caching. */

it('launches Texas first: another state only after Texas has run for 30 days, with green launch checks', function (): void {
    $la = State::query()->findOrFail('LA');
    expect(app(Movewise::class)->missing($la))->toContain('Texas live for at least 30 days first');
    $this->artisan('movewise:launch-check TX')->expectsOutputToContain('Live agents')->assertFailed(); // no relay numbers or postal address yet
    config(['movewise.postal_address' => '1 Main St, Houston, TX 77001']);
    DB::connection(Connections::control())->table('movewise_relay_numbers')->insert(['number' => '+17135550900', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(MessagingSettings::class)->save('AC'.str_repeat('1', 32), 'twilio-auth-token-0000000', '+15125550111', '+15125550112', true, true, $this->staff);
    $this->artisan('movewise:launch-check TX')->assertSuccessful();
    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/movewise/launch')->assertInertia(fn (Assert $p) => $p->component('hq/movewise-launch')
        ->where('states.3.code', 'TX')->where('states.3.ready', true)->where('order.0', 'TX'));
    $this->travel(31)->days();
    expect(app(Movewise::class)->missing($la->refresh()))->not->toContain('Texas live for at least 30 days first');
});

it('exports the Fair Housing and consent packs, password-confirmed, logged, and without phone numbers', function (): void {
    mwAccepted($this);
    app(TenantContext::class)->forget();
    $hq = 'http://app.rightally.io';
    $this->actingAs($this->staff)->get($hq.'/hq/movewise/audit/consent.csv')->assertRedirect(); // asks for the password
    $fh = $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])->get($hq.'/hq/movewise/audit/fair-housing.csv')->assertOk();
    $csv = $fh->streamedContent();
    expect($csv)->toContain('Ranking uses only the home, area, price, lead type and language')->toContain('Ann Agent')->toContain('Covers ZIP 77001')->toContain('Tier: preferred');
    $consent = $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])->get($hq.'/hq/movewise/audit/consent.csv')->assertOk()->streamedContent();
    expect($consent)->toContain('I choose Ann Agent of Acme Realty')->toContain('+17135550900')->not->toContain('7135550142');
});

it('connects calls through the relay, both ways, keeping quiet hours and pauses', function (): void {
    $c = mwAccepted($this);
    [, $ann] = $this->agents['Ann'];
    $ann->forceFill(['phone' => '+17135550777'])->save();
    app(TenantContext::class)->forget();
    $url = 'http://localhost/webhooks/movewise/voice';
    $call = function (string $from) use ($url) {
        $params = ['From' => $from, 'To' => '+17135550900', 'CallSid' => 'CA1'];
        $sig = base64_encode(hash_hmac('sha1', $url.implode('', array_map(fn ($k) => $k.$params[$k], array_keys(Arr::sortRecursive($params)))), 'twilio-auth-token-0000000', true));

        return $this->call('POST', $url, $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($params));
    };
    $this->post($url, ['From' => '+17135550142', 'To' => '+17135550900'])->assertForbidden(); // unsigned
    $call('+17135550142')->assertOk()->assertSee('<Dial callerId="+17135550900" timeout="25"><Number>+17135550777</Number></Dial>', false);
    $call('+17135550777')->assertOk()->assertSee('<Number>+17135550142</Number>', false);
    $this->travelTo(CarbonImmutable::parse('2026-06-03 04:00', 'UTC')); // 11pm in Houston
    $call('+17135550777')->assertOk()->assertSee('outside 8am to 9pm');
    $c->refresh()->forceFill(['paused_until' => now()->addDays(3)])->save();
    $call('+17135550142')->assertOk()->assertSee('You paused contact');
    $call('+15555550000')->assertOk()->assertSee('isn&apos;t connected', false);
});

it('caches the site header and footer, and drops the cache the moment a state changes', function (): void {
    $this->get(MWH.'/')->assertSee('Lone Star Partners LLC');
    State::query()->findOrFail('TX')->partner?->forceFill(['name' => 'Lone Star Realty Partners'])->save();
    $this->get(MWH.'/')->assertSee('Lone Star Realty Partners');
    app(Movewise::class)->switchState(State::query()->findOrFail('TX'), false, $this->staff);
    $this->get(MWH.'/')->assertSee('noindex', false)->assertDontSee('Lone Star Realty Partners, licence');
});
