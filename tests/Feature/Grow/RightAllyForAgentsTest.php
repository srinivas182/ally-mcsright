<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Gateway\FakeGateway;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\GoLive\GoLive;
use Modules\Billing\Models\Package;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Identity\Actions\ManageUserStatus;
use Modules\Core\Identity\Models\Invitation;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Portability\Portability;
use Modules\Crm\Portability\TakeYourPeopleMail;
use Modules\Insights\Planner;

const AG = 'http://app.rightally.io';

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->gateway = new FakeGateway;
    app()->instance(PaymentGateway::class, $this->gateway);
    $this->brokeragePackage = Package::query()->create(['name' => 'Brokerage', 'audience' => 'brokerage', 'monthly_cents' => 29_900, 'yearly_cents' => 299_000, 'ailly_conversations' => 500, 'features' => [], 'active' => true]);
    $this->soloPackage = Package::query()->create(['name' => 'Solo Agent', 'audience' => 'agent', 'monthly_cents' => 4_900, 'yearly_cents' => 49_000, 'ailly_conversations' => 100, 'features' => [], 'active' => true]);
});

/** @return int the new contact's id */
function agentContact(int $tenantId, ?int $owner, string $origin, string $first, array $extra = []): int
{
    return (int) DB::connection(Connections::cell())->table('crm_contacts')->insertGetId(['ulid' => (string) str()->ulid(), 'tenant_id' => $tenantId, 'first_name' => $first,
        'last_name' => 'Doe', 'emails' => '[]', 'phones' => '[]', 'origin' => $origin, 'source' => 'other', 'lifecycle' => 'lead', 'owner_user_id' => $owner,
        'created_at' => now(), 'updated_at' => now()] + $extra);
}

/** @return list<string> */
function menuTitles(User $user): array
{
    return collect(app(NavigationRegistry::class)->for($user->refresh()))->flatMap(fn ($g) => array_column($g['items'], 'title'))->all();
}

it('sells solo plans on their own page, and keeps them out of brokerage prices and savings', function (): void {
    $this->get(AG.'/agents')->assertOk()->assertSee('RightAlly for Agents')->assertSee('$49')->assertDontSee('$299')->assertSee('"@type":"Offer"', false)
        ->assertSee('<meta name="robots" content="index, follow">', false);
    $this->get(AG.'/start')->assertSee('$299')->assertDontSee('Solo Agent');
    // A brokerage can't buy a solo plan, and a solo agent can't buy a brokerage package.
    $this->post(AG.'/start', ['brokerage' => 'Oak', 'slug' => 'oak', 'owner_name' => 'Dana', 'owner_email' => 'd@oak.test', 'package' => $this->soloPackage->ulid, 'interval' => 'month', 'terms' => '1'])
        ->assertSessionHasErrors('package');
    $this->post(AG.'/agents', ['name' => 'Jane Smith', 'slug' => 'jane-smith', 'email' => 'jane@example.test', 'package' => $this->brokeragePackage->ulid, 'interval' => 'month', 'terms' => '1'])
        ->assertSessionHasErrors('package');
});

it('starts a solo agent in minutes: pay, get their own RightAlly in simple view, and a plan without brokerage steps', function (): void {
    $this->post(AG.'/agents', ['name' => 'Jane Smith', 'slug' => 'jane-smith', 'email' => 'jane@example.test', 'state' => 'TX', 'package' => $this->soloPackage->ulid,
        'interval' => 'month', 'terms' => '1'])->assertRedirect('https://checkout.example/session/1');
    $signup = DB::connection(Connections::control())->table('signups')->sole();
    expect($signup->type)->toBe('solo')->and($signup->brokerage)->toBe('Jane Smith');
    $event = ['id' => 'evt_s1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['subscription' => 'sub_s', 'customer' => 'cus_s',
        'metadata' => ['kind' => 'signup', 'signup' => $signup->ulid, 'package_id' => (string) $this->soloPackage->id, 'interval' => 'month', 'price_cents' => '4900']]]];
    $this->call('POST', AG.'/billing/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'fake-signature', 'CONTENT_TYPE' => 'application/json'], (string) json_encode($event))->assertOk();

    $tenant = Tenant::query()->where('slug', 'jane-smith')->sole();
    expect($tenant->type)->toBe(TenantType::Solo);
    $tenants = app(TenantContext::class);
    $tenants->runAs($tenant, function (): void {
        expect(Invitation::query()->where('email', 'jane@example.test')->exists())->toBeTrue();
        $plan = app(GoLive::class)->plan();
        expect(array_column($plan['steps'], 'key'))->toBe(GoLive::SOLO_STEPS);
    });

    // Simple view until she chooses otherwise.
    $this->hostTenant = $tenant;
    $tenants->set($tenant);
    $jane = User::factory()->owner()->create(['first_name' => 'Jane']);
    $this->actingAs($jane)->get('http://jane-smith.rightally.app/dashboard')->assertRedirect('/simple');
    expect(menuTitles($jane)[0])->toBe('Home');
    $this->actingAs($jane)->get('http://jane-smith.rightally.app/simple')->assertInertia(fn (Assert $page) => $page->where('simple.mode', 'simple'));
    $this->actingAs($jane)->post('http://jane-smith.rightally.app/settings/view', ['mode' => 'full']);
    $this->actingAs($jane)->get('http://jane-smith.rightally.app/dashboard')->assertOk();
});

it('leaves brokerage people to choose their view', function (): void {
    $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('simple.mode', null));
});

it('turns a closings goal into conversations a day, on Today and in the daily brief', function (): void {
    $tenant = $this->onTenantHost('acme');
    $agent = User::factory()->create(['first_name' => 'Pat']);
    $this->actingAs($agent)->get('/today')->assertInertia(fn (Assert $page) => $page->component('crm/today')->where('goal', null));

    $this->actingAs($agent)->post('/insights/plan', ['closings' => 24])->assertRedirect();
    $daily = app(Planner::class)->daily($agent);
    // 24 closings ÷ 60% ÷ 50% ÷ 10% = 800 conversations; 800 ÷ 48 weeks = 17 a week; ÷ 5 = 4 a day.
    expect($daily)->toBe(['closings' => 24, 'per_day' => 4, 'done_today' => 0]);
    $this->actingAs($agent)->get('/today')->assertInertia(fn (Assert $page) => $page->where('goal.per_day', 4)->where('goal.closings', 24));
    expect(collect(app(DailyBriefs::class)->for($agent))->firstWhere('key', 'goal-today')['title'])->toBe('4 more conversations today keeps your goal on track');

    // A solo agent without a goal is asked to set one; a brokerage agent is not.
    $other = User::factory()->create();
    expect(collect(app(DailyBriefs::class)->for($other))->firstWhere('key', 'goal-set'))->toBeNull();
    expect($tenant)->not->toBeNull();
});

it('lets agents download their own people any time, and nobody else\'s', function (): void {
    $tenant = $this->onTenantHost('acme');
    $agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee']);
    $mine = agentContact($tenant->id, $agent->id, 'personal', 'Maria', ['primary_email' => 'maria@example.test', 'city' => 'Austin']);
    agentContact($tenant->id, $agent->id, 'personal', '=HYPERLINK("x")');
    agentContact($tenant->id, $agent->id, 'company', 'Website lead');
    agentContact($tenant->id, null, 'personal', 'Someone else');
    DB::connection(Connections::cell())->table('crm_activities')->insert(['ulid' => (string) str()->ulid(), 'tenant_id' => $tenant->id, 'contact_id' => $mine, 'type' => 'note',
        'body' => 'Wants a pool', 'occurred_at' => '2026-05-01 10:00:00', 'created_at' => now(), 'updated_at' => now()]);

    $r = $this->actingAs($agent)->get('/my-people/download')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    $csv = $r->getContent();
    expect($csv)->toStartWith(implode(',', Portability::COLUMNS))->toContain('Maria,Doe,,maria@example.test')->toContain('2026-05-01: Wants a pool')
        ->toContain("'=HYPERLINK")->not->toContain('Website lead')->not->toContain('Someone else');
    expect((string) $r->headers->get('Content-Disposition'))->toContain('my-people-pat-lee-2026-06-01.csv');
});

it('emails an agent who leaves a private link to take their own people', function (): void {
    $tenant = $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    $agent = User::factory()->create(['first_name' => 'Pat', 'email' => 'pat@example.test']);
    agentContact($tenant->id, $agent->id, 'personal', 'Maria');
    $quiet = User::factory()->create();

    app(ManageUserStatus::class)->deactivate($agent, $owner);
    app(ManageUserStatus::class)->deactivate($quiet, $owner); // nobody of their own: no email
    $url = null;
    Notification::assertSentOnDemand(TakeYourPeopleMail::class, function (TakeYourPeopleMail $m, array $c, object $n) use (&$url): bool {
        $url = $m->url;

        return $n->routes['mail'] === 'pat@example.test' && $m->people === 1;
    });
    Notification::assertSentOnDemandTimes(TakeYourPeopleMail::class, 1);
    $token = (string) str((string) $url)->afterLast('/');
    expect($url)->toStartWith('https://acme.rightally.app/take-my-people/');

    $this->get('/take-my-people/'.$token)->assertOk()->assertSee('Download my people')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect(DB::connection(Connections::cell())->table('crm_portability_links')->value('downloads'))->toBe(0); // opening the page uses nothing
    for ($i = 0; $i < 5; $i++) {
        $this->post('/take-my-people/'.$token)->assertOk();
    }
    $this->post('/take-my-people/'.$token)->assertNotFound();
    $this->get('/take-my-people/'.$token)->assertSee('This link has expired');
});

it('expires the leaving link after 30 days', function (): void {
    $tenant = $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    $agent = User::factory()->create(['email' => 'pat@example.test']);
    agentContact($tenant->id, $agent->id, 'personal', 'Maria');
    app(ManageUserStatus::class)->deactivate($agent, $owner);
    $url = '';
    Notification::assertSentOnDemand(TakeYourPeopleMail::class, function (TakeYourPeopleMail $m) use (&$url): bool {
        $url = $m->url;

        return true;
    });
    $this->travel(31)->days();
    $this->post('/take-my-people/'.str($url)->afterLast('/'))->assertNotFound();
});
