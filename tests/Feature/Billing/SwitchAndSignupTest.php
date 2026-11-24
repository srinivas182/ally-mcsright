<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Gateway\FakeGateway;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\GoLive\GoLive;
use Modules\Billing\GoLive\GoLiveWeekMail;
use Modules\Billing\HealthCheck\HealthCopy;
use Modules\Billing\HealthCheck\HealthReportMail;
use Modules\Billing\Models\Package;
use Modules\Billing\Signup\SignupProblemMail;
use Modules\Billing\Signup\SignupReminderMail;
use Modules\Core\Identity\Models\Invitation;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Roster\Brokerage\BrokerageLicences;
use Modules\Roster\Verification\ServiceVerifier;
use Modules\Sites\Content\DemoSite;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;

const RA = 'http://app.rightally.io';

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->hq = Tenant::query()->where('type', 'hq')->sole();
    $this->staff = app(TenantContext::class)->runAs($this->hq, fn () => User::factory()->hqStaff()->create());
    $this->gateway = new FakeGateway;
    app()->instance(PaymentGateway::class, $this->gateway);
    $this->package = Package::query()->create(['name' => 'Brokerage', 'monthly_cents' => 29_900, 'yearly_cents' => 299_000, 'ailly_conversations' => 500, 'features' => [], 'active' => true]);
});

function control(): Connection
{
    return DB::connection(Connections::control());
}

function signupEvent(string $id, string $type, string $signup, array $object = []): void
{
    $event = ['id' => $id, 'type' => $type, 'data' => ['object' => $object + ['subscription' => 'sub_1', 'customer' => 'cus_1',
        'metadata' => ['kind' => 'signup', 'signup' => $signup, 'package_id' => (string) test()->package->id, 'interval' => 'month', 'price_cents' => '29900']]]];
    test()->call('POST', RA.'/billing/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'fake-signature', 'CONTENT_TYPE' => 'application/json'], (string) json_encode($event))->assertOk();
}

function startSignup(array $over = []): string
{
    test()->post(RA.'/start', array_replace(['brokerage' => 'Oak Realty', 'slug' => 'oak-realty', 'owner_name' => 'Dana Smith', 'owner_email' => 'dana@oak.test', 'state' => 'TX',
        'agents' => 12, 'package' => test()->package->ulid, 'interval' => 'month', 'terms' => '1'], $over))->assertRedirect('https://checkout.example/session/'.count(test()->gateway->checkouts));

    return (string) control()->table('signups')->orderByDesc('id')->value('ulid');
}

it('has every Health Check word in English and Spanish, with a Spanish page search engines can find', function (): void {
    expect(HealthCopy::missing())->toBe([]);
    $this->get(RA.'/health-check/es')->assertOk()->assertSee('<html lang="es">', false)
        ->assertSee('<link rel="canonical" href="https://app.rightally.io/health-check/es">', false)
        ->assertSee('hreflang="en" href="https://app.rightally.io/health-check"', false)
        ->assertSee('Chequeo gratuito de su correduría')->assertSee('"@type":"FAQPage"', false);

    $this->post(RA.'/health-check', ['lang' => 'es', 'brokerage' => 'Roble Realty', 'contact_name' => 'Ana', 'email' => 'ana@roble.test', 'agents' => 5,
        'answers' => ['lead_reply' => 'minutes', 'file_check' => 'rarely', 'payout_days' => 'same_day', 'past_clients' => 'yes'], 'who_pays' => 'agents',
        'tools' => [['key' => 'skyslope', 'cost' => '200']]])->assertRedirect();
    Notification::assertSentOnDemand(HealthReportMail::class, fn (HealthReportMail $m): bool => $m->lang === 'es');
    $row = control()->table('health_checks')->sole();
    expect($row->lang)->toBe('es')->and((string) $row->scores)->toContain('Usted dijo: En menos de 5 minutos.');
});

it('wears the same header and footer as rightally.app on the sign-up and Health Check pages (SITE2)', function (): void {
    config(['marketing.hosts' => ['rightally.app']]);
    foreach (['/start', '/agents', '/health-check'] as $path) {
        $html = (string) $this->get(RA.$path)->assertOk()->getContent();
        expect($html)->toContain('<header class="top">')->toContain('<details class="lang">')->toContain('href="https://rightally.app/"')
            ->toContain('<footer class="site">')->toContain('href="https://rightally.app/privacy"')->toContain('href="https://rightally.app/login"')
            ->not->toContain('Right<span>Ally</span>');
    }
});

it('shows public prices with offer markup, checks the address, and sends the broker to payment', function (): void {
    $this->get(RA.'/start')->assertOk()->assertSee('$299')->assertSee('"@type":"Offer"', false)->assertSee('<meta name="robots" content="index, follow">', false);
    $this->getJson(RA.'/start/address?slug=oak-realty')->assertJson(['ok' => true, 'host' => 'oak-realty.rightally.site']); // DM1: the website address
    $this->getJson(RA.'/start/address?slug=www')->assertJson(['ok' => false]);

    $this->post(RA.'/start', ['brokerage' => 'Oak', 'slug' => 'oak', 'owner_name' => 'Dana', 'owner_email' => 'dana@oak.test', 'package' => $this->package->ulid, 'interval' => 'month'])
        ->assertSessionHasErrors('terms');
    $signup = startSignup();
    expect($this->gateway->checkouts[0])->toMatchArray(['email' => 'dana@oak.test', 'unitCents' => 29_900, 'interval' => 'month'])
        ->and($this->gateway->checkouts[0]['metadata'])->toMatchArray(['kind' => 'signup', 'signup' => $signup]);
    expect(Tenant::query()->where('slug', 'oak-realty')->exists())->toBeFalse(); // nothing before payment
    $this->get(RA.'/start/welcome/'.$signup)->assertOk()->assertSee('We are setting up Oak Realty')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    // While the payment is pending the address is held.
    $this->getJson(RA.'/start/address?slug=oak-realty')->assertJson(['ok' => false]);
});

it('creates the brokerage when the payment comes through, once, and its later payments keep the subscription', function (): void {
    $signup = startSignup();
    signupEvent('evt_1', 'checkout.session.completed', $signup);
    signupEvent('evt_1', 'checkout.session.completed', $signup); // the same event again
    signupEvent('evt_2', 'checkout.session.completed', $signup); // a second event for the same signup

    $tenant = Tenant::query()->where('slug', 'oak-realty')->sole();
    expect($tenant->name)->toBe('Oak Realty')->and((int) $tenant->getAttribute('billing_package_id'))->toBe($this->package->id)
        ->and(control()->table('signups')->where('ulid', $signup)->value('status'))->toBe('live')
        ->and(control()->table('billing_tenant_subscriptions')->where('tenant_id', $tenant->id)->value('status'))->toBe('active')
        ->and(control()->table('go_live_plans')->where('tenant_id', $tenant->id)->exists())->toBeTrue();
    app(TenantContext::class)->runAs($tenant, fn () => expect(Invitation::query()->where('email', 'dana@oak.test')->exists())->toBeTrue());
    $this->get(RA.'/start/welcome/'.$signup)->assertSee('Oak Realty is live')->assertSee('Welcome to the RightAlly family')->assertSee('Sunil')->assertSee('https://oak-realty.rightally.site')->assertSee('https://oak-realty.rightally.app/login', false);

    signupEvent('evt_3', 'invoice.payment_failed', $signup, ['subscription' => 'sub_1']);
    expect(control()->table('billing_tenant_subscriptions')->where('tenant_id', $tenant->id)->value('status'))->toBe('grace');
});

it('tells HQ when a paid signup cannot be created automatically', function (): void {
    $signup = startSignup(['slug' => 'pine-homes']);
    $this->onTenantHost('pine-homes'); // someone took the address in the meantime
    signupEvent('evt_9', 'checkout.session.completed', $signup);
    expect(control()->table('signups')->where('ulid', $signup)->value('status'))->toBe('failed');
    Notification::assertSentTo($this->staff, SignupProblemMail::class);
    $this->get(RA.'/start/welcome/'.$signup)->assertSee('Your payment is safe', false);
});

it('prefills signup from a Health Check report', function (): void {
    $r = $this->post(RA.'/health-check', ['brokerage' => 'Elm Street Realty', 'contact_name' => 'Lee Park', 'email' => 'lee@elm.test', 'agents' => 8, 'state' => 'FL',
        'answers' => ['lead_reply' => 'day', 'file_check' => 'rarely', 'payout_days' => 'week', 'past_clients' => 'no'], 'who_pays' => 'brokerage'])->assertRedirect();
    $token = (string) str((string) $r->headers->get('Location'))->afterLast('/');
    $this->get(RA.'/health-check/r/'.$token)->assertSee('/start?check='.$token, false);
    $this->get(RA.'/start?check='.$token)->assertSee('value="Elm Street Realty"', false)->assertSee('value="elm-street-realty"', false)->assertSee('value="lee@elm.test"', false);
});

it('shows the brokerage its switch centre, the checks after the move and its go-live plan', function (): void {
    $tenant = $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create(['last_login_at' => now()]);
    $agent = User::factory()->create();
    app(GoLive::class)->start($tenant);
    DB::connection(Connections::cell())->table('crm_contacts')->insert(['ulid' => (string) str()->ulid(), 'tenant_id' => $tenant->id, 'first_name' => 'Maria', 'emails' => '[]', 'phones' => '[]',
        'origin' => 'company', 'source' => 'other', 'lifecycle' => 'lead', 'sms_opt_out_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($agent)->get('/switch')->assertForbidden();
    $this->actingAs($owner)->get('/switch')->assertOk()->assertInertia(fn (Assert $page) => $page->component('switch/index')
        ->where('overview.people.total', 1)
        ->where('overview.checks', fn ($checks): bool => collect($checks)->firstWhere('key', 'owners')['ok'] === false
            && collect($checks)->firstWhere('key', 'routing')['ok'] === false
            && str_contains(collect($checks)->firstWhere('key', 'opt_outs')['detail'], '1 people'))
        ->where('overview.people.apps', fn ($apps): bool => collect($apps)->pluck('key')->contains('follow_up_boss'))
        ->where('plan.steps', fn ($steps): bool => collect($steps)->firstWhere('key', 'signed_in')['done'] === true
            && collect($steps)->firstWhere('key', 'people')['done'] === true && collect($steps)->firstWhere('key', 'agents_invited')['done'] === true
            && collect($steps)->firstWhere('key', 'first_close')['done'] === false));

    $this->travel(10)->days();
    $plan = app(GoLive::class)->plan();
    expect($plan['day'])->toBe(11)->and(collect($plan['steps'])->firstWhere('key', 'routing')['late'])->toBeTrue();
});

it('gives HQ a go-live board with late steps and coaches, and emails owners weekly', function (): void {
    $tenant = $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    app(GoLive::class)->start($tenant);
    $this->travel(8)->days();

    $this->actingAs($this->staff)->get(RA.'/hq/go-live')->assertOk()->assertInertia(fn (Assert $page) => $page->component('hq/go-live')
        ->where('plans.0.name', $tenant->name)->where('plans.0.day', 9)->where('plans.0.late', fn ($late): bool => collect($late)->contains('Bring your people')));
    $this->actingAs($this->staff)->post(RA."/hq/go-live/{$tenant->ulid}/coach", ['name' => 'Sam Rivera', 'email' => 'sam@rightally.test'])->assertRedirect();
    expect(control()->table('go_live_plans')->where('tenant_id', $tenant->id)->value('coach_name'))->toBe('Sam Rivera');

    $this->artisan('billing:go-live-weekly')->assertSuccessful();
    $this->artisan('billing:go-live-weekly')->assertSuccessful(); // not twice in a week
    Notification::assertSentToTimes($owner, GoLiveWeekMail::class, 1);
    Notification::assertSentTo($owner, GoLiveWeekMail::class, fn (GoLiveWeekMail $m): bool => $m->coach === 'Sam Rivera' && $m->url === 'https://acme.rightally.app/switch');
});

it('checks the address while typing, suggests a free one, and keeps it for the same person starting again (SU1)', function (): void {
    startSignup();
    $this->getJson(RA.'/start/address?slug=oak-realty')->assertJson(['ok' => false, 'suggestion' => 'oak-realty-2']);
    $this->getJson(RA.'/start/address?slug=oak-realty&email=dana@oak.test')->assertJson(['ok' => true]); // their own unpaid signup

    // Starting again (for example after cancelling the payment) replaces the unpaid signup and keeps the address.
    startSignup();
    expect(control()->table('signups')->orderBy('id')->pluck('status')->all())->toBe(['replaced', 'pending']);
});

it('offers only the states RightAlly works in, as a list, and packages as cards with a yearly price (SU1)', function (): void {
    control()->table('idx_states')->update(['enabled' => false]);
    control()->table('idx_states')->where('code', 'TX')->update(['enabled' => true]);
    $html = (string) $this->get(RA.'/start')->assertOk()->getContent();
    expect($html)->toContain('<select name="state"')->toContain('<option value="TX"')->not->toContain('<option value="CA"');
    signupStep1();
    $html = (string) $this->get(RA.'/start?step=2')->assertOk()->getContent();
    expect($html)->toContain('class="plan"')->toContain('data-year="299000"')->toContain('id="pay-today"')->toContain('Save up to');
});

it('reminds people who did not pay, an hour and a day later, with a link that fills the form back in (SU1)', function (): void {
    $signup = startSignup();
    $this->artisan('signups:remind')->expectsOutputToContain('0 reminder(s) sent');

    $this->travel(61)->minutes();
    $this->artisan('signups:remind')->expectsOutputToContain('1 reminder(s) sent');
    $this->artisan('signups:remind')->expectsOutputToContain('0 reminder(s) sent'); // not twice
    $mail = null;
    Notification::assertSentOnDemand(SignupReminderMail::class, function (SignupReminderMail $m) use (&$mail): bool {
        $mail = $m;

        return true;
    });
    $this->flushSession();
    $this->get($mail->resumeUrl)->assertRedirect('/start?step=4&back=1'); // back at the step they stopped
    $this->get(RA.'/start?step=1&back=1')->assertOk()->assertSee('Welcome back')->assertSee('value="Oak Realty"', false)->assertSee('value="oak-realty"', false);
    $this->flushSession();
    $this->get(RA.'/start?resume='.$signup)->assertOk()->assertDontSee('value="Oak Realty"', false); // only with the signed link

    $this->travel(1)->days();
    $this->artisan('signups:remind')->expectsOutputToContain('1 reminder(s) sent');
    $this->travel(2)->days();
    $this->artisan('signups:remind')->expectsOutputToContain('0 reminder(s) sent'); // two at most

    // "Stop these emails" works, and HQ can still send one by hand.
    $this->get($mail->stopUrl)->assertOk()->assertSee('won');
    expect((bool) control()->table('signups')->where('ulid', $signup)->value('no_reminders'))->toBeTrue();
});

it('sends no reminder once the person has paid (SU1)', function (): void {
    $signup = startSignup();
    signupEvent('evt_paid', 'checkout.session.completed', $signup);
    $this->travel(2)->hours();
    $this->artisan('signups:remind')->expectsOutputToContain('0 reminder(s) sent');
});

it('lists sign-ups for HQ with where each stopped, and sends a reminder on request (SU1)', function (): void {
    $signup = startSignup();
    $this->actingAs($this->staff)->get(RA.'/hq/signups')->assertOk()->assertInertia(fn (Assert $p) => $p->component('hq/signups')
        ->where('status', 'unfinished')->where('counts.unfinished', 1)->where('signups.0.brokerage', 'Oak Realty')->where('signups.0.price_cents', 29_900)
        ->where('signups.0.stopped', 'At the payment page')
        ->where('signups.0.resume_url', fn ($u): bool => str_contains((string) $u, 'resume='.$signup)));
    $this->actingAs($this->staff)->post(RA.'/hq/signups/'.$signup.'/remind')->assertRedirect();
    Notification::assertSentOnDemandTimes(SignupReminderMail::class, 1);

    $this->actingAs($this->staff)->get(RA.'/hq/dashboard')->assertInertia(fn (Assert $p) => $p->where('attention', fn ($a): bool => collect($a)->pluck('label')->doesntContain('Sign-ups not finished (not paid)')));
    $this->travel(2)->hours();
    Cache::flush();
    $this->actingAs($this->staff)->get(RA.'/hq/dashboard')->assertInertia(fn (Assert $p) => $p->where('attention', fn ($a): bool => collect($a)->pluck('label')->contains('Sign-ups not finished (not paid)')));
});

function signupStep1(array $over = []): void
{
    test()->post(RA.'/start/step/about', array_replace(['brokerage' => 'Oak Realty', 'slug' => 'oak-realty', 'owner_name' => 'Dana Smith', 'owner_email' => 'dana@oak.test',
        'state' => 'TX', 'agents' => 12, 'phone' => '(713) 555-0142', 'licence_states' => ['TX'], 'licence_numbers' => ['TX' => 'test']], $over))->assertRedirect('/start?step=2#signup');
}

it('signs up in four saved steps, and HQ sees where someone stopped (SU2)', function (): void {
    // Later steps are not reachable before step 1.
    $this->get(RA.'/start?step=3')->assertOk()->assertSee('name="brokerage"', false)->assertDontSee('name="template"', false);
    signupStep1();
    $signup = control()->table('signups')->sole();
    expect($signup)->status->toBe('draft')->step->toBe(2)->package_id->toBeNull();
    $this->actingAs($this->staff)->get(RA.'/hq/signups')->assertInertia(fn (Assert $p) => $p->where('signups.0.stopped', 'Stopped at step 2: Package'));
    auth()->logout();

    $this->post(RA.'/start/step/package', ['package' => $this->package->ulid, 'interval' => 'year'])->assertRedirect('/start?step=3#signup');
    // LP1: no MLS board, so the landing page designs.
    $this->get(RA.'/start?step=3')->assertOk()->assertSee('Choose your website design')->assertSee('value="signature"', false)->assertDontSee('value="aurum"', false)
        ->assertSee('/start/designs/legacy', false)->assertSee('professional landing page');
    $this->post(RA.'/start/step/design', ['template' => 'nope'])->assertSessionHasErrors('template');
    $this->post(RA.'/start/step/design', ['template' => 'estate'])->assertSessionHasErrors('template'); // a full website needs a board
    $this->post(RA.'/start/step/design', ['template' => 'legacy'])->assertRedirect('/start?step=4#signup');
    $this->get(RA.'/start?step=4')->assertOk()->assertSee('Review and pay')->assertSee('Landing page: Legacy')->assertSee('Texas test')->assertSee('$2,990 a year')->assertSee('oak-realty.rightally.site');

    $this->post(RA.'/start', [])->assertSessionHasErrors('terms');
    $this->post(RA.'/start', ['terms' => '1'])->assertRedirect('https://checkout.example/session/1');
    expect(control()->table('signups')->sole())->status->toBe('pending')->template->toBe('legacy')
        ->and($this->gateway->checkouts[0])->toMatchArray(['email' => 'dana@oak.test', 'unitCents' => 299_000, 'interval' => 'year']);

    // Going back to change something keeps the rest.
    $this->get(RA.'/start?step=1')->assertOk()->assertSee('value="oak-realty"', false);
});

it('puts the new website live in the chosen design the moment they pay (SU2)', function (): void {
    signupStep1();
    $this->post(RA.'/start/step/package', ['package' => $this->package->ulid, 'interval' => 'month']);
    $this->post(RA.'/start/step/design', ['template' => 'signature']);
    $this->post(RA.'/start', ['terms' => '1']);
    $signup = (string) control()->table('signups')->value('ulid');
    signupEvent('evt_paid', 'checkout.session.completed', $signup);

    $tenant = Tenant::query()->where('slug', 'oak-realty')->sole();
    $site = app(TenantContext::class)->runAs($tenant, fn () => Site::query()->where('kind', 'brokerage')->sole());
    expect($site)->template->toBe('signature')->status->toBe('live')
        ->and($site->info['name'])->toBe('Oak Realty')->and($site->info['phone'])->toBe('(713) 555-0142') // FT1: in the header and footer
        // LP1: a landing page: Home, About and Contact, no home search.
        ->and(app(TenantContext::class)->runAs($tenant, fn () => SitePage::query()->where('site_id', $site->id)->orderBy('position')->pluck('path')->all()))->toBe(['/', '/about', '/contact']);
});

it('shows each design with made-up content for choosing, never indexed (SU2)', function (): void {
    $this->get(RA.'/start/designs/aurum?name=Oak+Realty&area=Austin')->assertOk()->assertSee('Oak Realty')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get(RA.'/start/designs/nope')->assertNotFound();
});

it('needs an active licence checked in the state\'s records, and offers boards under each licence state (LV1, LP1)', function (): void {
    control()->table('idx_states')->whereIn('code', ['TX', 'FL'])->update(['enabled' => true]);
    control()->table('idx_boards')->update(['visibility' => 'private']);
    control()->table('idx_boards')->where('code', 'HAR')->update(['visibility' => 'public', 'monthly_fee_cents' => 12_900, 'region' => 'Greater Houston']);
    $har = (string) control()->table('idx_boards')->where('code', 'HAR')->value('ulid');
    ServiceVerifier::load('TX', "License Number,Last Name,Full Name,License Status,Expiration Date\n0654321,Smith,Dana Smith,Active,2028-01-31\n0777777,Jones,Pat Jones,Expired,2025-01-31\n0888888,Ray,Sam Ray,Suspended,2028-01-31\n");

    $page = (string) $this->get(RA.'/start')->assertOk()->getContent();
    expect($page)->toContain('Your real estate licence')->toContain('name="licence_states[]" data-idx-state value="TX"')
        ->toContain('name="licence_states[]" data-idx-state value="FL"') // licensed in Florida: offered, with a landing page until a board is there
        ->toContain('name="licence_numbers[TX]"')->toContain('connect to an MLS board in Florida yet');

    $base = ['brokerage' => 'Oak Realty', 'slug' => 'oak-realty', 'owner_name' => 'Dana Smith', 'owner_email' => 'dana@oak.test', 'state' => 'TX', 'phone' => '(713) 555-0142'];
    $this->post(RA.'/start/step/about', $base)->assertSessionHasErrors('licence_states');
    $this->post(RA.'/start/step/about', $base + ['licence_states' => ['TX']])->assertSessionHasErrors(['licence_numbers.TX' => 'Type your Texas licence number.']);
    $this->post(RA.'/start/step/about', $base + ['licence_states' => ['TX'], 'licence_numbers' => ['TX' => '0123999']])->assertSessionHasErrors('licence_numbers.TX');
    // LV2: suspended is stopped; not found can go ahead as a new licence; expired goes ahead without boards there.
    $this->post(RA.'/start/step/about', $base + ['licence_states' => ['TX'], 'licence_numbers' => ['TX' => '888888']])
        ->assertSessionHasErrors(['licence_numbers.TX' => 'The state\'s records show Texas licence 888888 as suspended, so we can\'t accept it. If this is wrong, please contact us.']);
    $this->post(RA.'/start/step/about', $base + ['licence_states' => ['TX'], 'licence_numbers' => ['TX' => '0123999'], 'licence_new' => ['TX' => '1'], 'boards' => [$har]])
        ->assertSessionHasNoErrors()->assertRedirect('/start?step=2#signup');
    expect(json_decode((string) control()->table('signups')->value('idx_boards') ?: '[]', true))->toBe([]) // no board until the new licence shows as active
        ->and(json_decode((string) control()->table('signups')->value('licences'), true)[0]['status'])->toBe('new');
    $this->post(RA.'/start/step/about', $base + ['licence_states' => ['TX'], 'licence_numbers' => ['TX' => '777777'], 'boards' => [$har]])->assertSessionHasNoErrors();
    $this->get(RA.'/start?step=2')->assertSee('Your Texas licence shows as expired')->assertSee('We left out an MLS board')->assertSee('coming soon');
    expect(json_decode((string) control()->table('signups')->value('idx_boards') ?: '[]', true))->toBe([]);
    // Florida's records are not loaded here: let through, marked for HQ to check.
    signupStep1(['licence_states' => ['TX', 'FL'], 'licence_numbers' => ['TX' => '0654321', 'FL' => 'SL3456789'], 'boards' => [$har, $har]]);
    $row = control()->table('signups')->sole();
    expect(json_decode((string) $row->idx_boards, true))->toBe([$har])
        ->and(collect(json_decode((string) $row->licences, true))->pluck('status', 'state')->all())->toBe(['TX' => 'active', 'FL' => 'unchecked']);

    // With a board: the full website designs, talking about where the board is.
    $this->post(RA.'/start/step/package', ['package' => $this->package->ulid, 'interval' => 'month']);
    $this->get(RA.'/start?step=3')->assertOk()->assertSee('value="aurum"', false)->assertDontSee('value="signature"', false)
        ->assertSee('Find your place in Greater Houston');
    $this->actingAs($this->staff)->get(RA.'/hq/signups')->assertInertia(fn (Assert $p) => $p->where('signups.0.licences.0.status', 'Confirmed active')
        ->where('signups.0.licences.1.status', 'Check by hand')->where('signups.0.licences.1.review', true));
});

it('opens design previews on the demo website in the chosen board\'s state, and keeps its address for it (TP4)', function (): void {
    app(DemoSite::class)->install();
    control()->table('idx_states')->where('code', 'TX')->update(['enabled' => true]);
    control()->table('idx_boards')->where('code', 'HAR')->update(['visibility' => 'public', 'monthly_fee_cents' => 12_900, 'region' => 'Greater Houston']);
    $har = (string) control()->table('idx_boards')->where('code', 'HAR')->value('ulid');
    signupStep1(['brokerage' => 'Oak Realty', 'state' => 'FL', 'licence_states' => ['TX'], 'boards' => [$har]]);
    $this->post(RA.'/start/step/package', ['package' => $this->package->ulid, 'interval' => 'month']);
    $this->get(RA.'/start?step=3')->assertOk()->assertSee('https://demo.rightally.site/?design=aurum&amp;name=Oak+Realty&amp;area=Greater+Houston&amp;state=TX', false);

    $this->getJson(RA.'/start/address?slug=demo')->assertJson(['ok' => false, 'message' => 'This address is reserved. Please choose another.']);
});

it('sends the owner\'s sign-in email again from the welcome page and from HQ, and shows progress while setting up (QW1)', function (): void {
    $signup = startSignup();
    $this->get(RA.'/start/welcome/'.$signup)->assertSee('Confirming your payment')->assertSee('Emailing you a link to set your password');
    signupEvent('evt_1', 'checkout.session.completed', $signup);
    $tenant = Tenant::query()->where('slug', 'oak-realty')->sole();
    $hash = fn (): string => (string) app(TenantContext::class)->runAs($tenant, fn () => Invitation::query()->where('email', 'dana@oak.test')->value('token_hash'));
    $first = $hash();

    $this->get(RA.'/start/welcome/'.$signup)->assertSee('Send it again');
    $this->post(RA.'/start/welcome/'.$signup.'/resend')->assertRedirect(RA.'/start/welcome/'.$signup);
    expect($hash())->not->toBe($first);
    $this->get(RA.'/start/welcome/'.$signup)->assertSee('we have sent it again', false);

    $second = $hash();
    $this->actingAs($this->staff)->get(RA.'/hq/signups?status=live')->assertInertia(fn (Assert $p) => $p->where('signups.0.can_resend', true));
    $this->actingAs($this->staff)->post(RA.'/hq/signups/'.$signup.'/resend')->assertRedirect();
    expect($hash())->not->toBe($second);
});

it('lets HQ correct the owner\'s email and sends the sign-in email to the new address (EM1)', function (): void {
    $signup = startSignup();
    $as = fn () => $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()]);
    // Before payment: only the sign-up changes.
    $as()->post(RA.'/hq/signups/'.$signup.'/email', ['email' => 'Dana@Oak-Realty.test'])->assertRedirect();
    expect(control()->table('signups')->where('ulid', $signup)->value('owner_email'))->toBe('dana@oak-realty.test');

    signupEvent('evt_1', 'checkout.session.completed', $signup);
    $tenant = Tenant::query()->where('slug', 'oak-realty')->sole();
    $as()->post(RA.'/hq/signups/'.$signup.'/email', ['email' => 'not-an-email'])->assertSessionHasErrors('email');
    $as()->post(RA.'/hq/signups/'.$signup.'/email', ['email' => 'owner@oakrealty.test'])->assertRedirect();
    app(TenantContext::class)->runAs($tenant, function (): void {
        expect(Invitation::query()->where('email', 'owner@oakrealty.test')->whereNull('revoked_at')->exists())->toBeTrue()
            ->and(Invitation::query()->where('email', 'dana@oak-realty.test')->value('revoked_at'))->not->toBeNull();
    });
    expect(control()->table('signups')->where('ulid', $signup)->value('owner_email'))->toBe('owner@oakrealty.test');

    // It needs the HQ person's password.
    $this->flushSession();
    $this->actingAs($this->staff)->post(RA.'/hq/signups/'.$signup.'/email', ['email' => 'x@oak.test'])->assertRedirect();
    expect(control()->table('signups')->where('ulid', $signup)->value('owner_email'))->toBe('owner@oakrealty.test');
});

it('keeps the website "coming soon" after payment when no licence is active, and saves the licences to the brokerage (LV2)', function (): void {
    ServiceVerifier::load('TX', "License Number,Last Name,License Status,Expiration Date\n0777777,Jones,Expired,2025-01-31\n");
    signupStep1(['licence_numbers' => ['TX' => '0777777']]);
    $this->post(RA.'/start/step/package', ['package' => $this->package->ulid, 'interval' => 'month']);
    $this->post(RA.'/start/step/design', ['template' => 'signature']);
    $this->post(RA.'/start', ['terms' => '1']);
    signupEvent('evt_lv2', 'checkout.session.completed', (string) control()->table('signups')->value('ulid'));
    $tenant = Tenant::query()->where('slug', 'oak-realty')->sole();
    app(TenantContext::class)->runAs($tenant, function (): void {
        expect(Site::query()->where('kind', 'brokerage')->value('status'))->toBe('draft')
            ->and(app(BrokerageLicences::class)->all()[0])->toMatchArray(['state' => 'TX', 'status' => 'expired', 'good' => false]);
    });
});

it('keeps earlier attempts of someone who later paid out of "Started again", labelled under All (SU5)', function (): void {
    control()->table('signups')->insert([
        ['ulid' => (string) Str::ulid(), 'type' => 'brokerage', 'brokerage' => 'Old try', 'slug' => 'old-try', 'owner_name' => 'Dana', 'owner_email' => 'Dana@oak.test', 'status' => 'replaced', 'created_at' => now(), 'updated_at' => now()],
        ['ulid' => (string) Str::ulid(), 'type' => 'brokerage', 'brokerage' => 'Other try', 'slug' => 'other-try', 'owner_name' => 'Pat', 'owner_email' => 'pat@x.test', 'status' => 'replaced', 'created_at' => now(), 'updated_at' => now()],
        ['ulid' => (string) Str::ulid(), 'type' => 'brokerage', 'brokerage' => 'Oak', 'slug' => 'oak-paid', 'owner_name' => 'Dana', 'owner_email' => 'dana@oak.test', 'status' => 'live', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->actingAs($this->staff)->get(RA.'/hq/signups?status=replaced')->assertInertia(fn (Assert $p) => $p->has('signups', 1)
        ->where('signups.0.email', 'pat@x.test')->where('counts.replaced', 1));
    $this->actingAs($this->staff)->get(RA.'/hq/signups?status=all')->assertInertia(fn (Assert $p) => $p->where('statuses.replaced_paid', 'Replaced by a paid sign-up')
        ->where('signups', fn ($rows): bool => collect($rows)->firstWhere('brokerage', 'Old try')['status'] === 'replaced_paid'));
});
