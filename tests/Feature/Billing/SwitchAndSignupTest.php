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
    $this->getJson(RA.'/start/address?slug=oak-realty')->assertJson(['ok' => true, 'host' => 'oak-realty.rightally.app']);
    $this->getJson(RA.'/start/address?slug=www')->assertJson(['ok' => false]);

    $this->post(RA.'/start', ['brokerage' => 'Oak', 'slug' => 'oak', 'owner_name' => 'Dana', 'owner_email' => 'dana@oak.test', 'package' => $this->package->ulid, 'interval' => 'month'])
        ->assertSessionHasErrors('terms');
    $signup = startSignup();
    expect($this->gateway->checkouts[0])->toMatchArray(['email' => 'dana@oak.test', 'unitCents' => 29_900, 'interval' => 'month'])
        ->and($this->gateway->checkouts[0]['metadata'])->toMatchArray(['kind' => 'signup', 'signup' => $signup]);
    expect(Tenant::query()->where('slug', 'oak-realty')->exists())->toBeFalse(); // nothing before payment
    $this->get(RA.'/start/welcome/'.$signup)->assertOk()->assertSee('Setting up Oak Realty')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
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
    $this->get(RA.'/start/welcome/'.$signup)->assertSee('Oak Realty is live')->assertSee('https://oak-realty.rightally.app');

    signupEvent('evt_3', 'invoice.payment_failed', $signup, ['subscription' => 'sub_1']);
    expect(control()->table('billing_tenant_subscriptions')->where('tenant_id', $tenant->id)->value('status'))->toBe('grace');
});

it('tells HQ when a paid signup cannot be created automatically', function (): void {
    $signup = startSignup(['slug' => 'pine-homes']);
    $this->onTenantHost('pine-homes'); // someone took the address in the meantime
    signupEvent('evt_9', 'checkout.session.completed', $signup);
    expect(control()->table('signups')->where('ulid', $signup)->value('status'))->toBe('failed');
    Notification::assertSentTo($this->staff, SignupProblemMail::class);
    $this->get(RA.'/start/welcome/'.$signup)->assertSee('we\'ll finish your setup', false);
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
        'state' => 'TX', 'agents' => 12], $over))->assertRedirect('/start?step=2#signup');
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
    $this->get(RA.'/start?step=3')->assertOk()->assertSee('Choose your website design')->assertSee('value="aurum"', false)->assertSee('/start/designs/estate', false);
    $this->post(RA.'/start/step/design', ['template' => 'nope'])->assertSessionHasErrors('template');
    $this->post(RA.'/start/step/design', ['template' => 'estate'])->assertRedirect('/start?step=4#signup');
    $this->get(RA.'/start?step=4')->assertOk()->assertSee('Review and pay')->assertSee('Estate')->assertSee('$2,990 a year')->assertSee('oak-realty.rightally.app');

    $this->post(RA.'/start', [])->assertSessionHasErrors('terms');
    $this->post(RA.'/start', ['terms' => '1'])->assertRedirect('https://checkout.example/session/1');
    expect(control()->table('signups')->sole())->status->toBe('pending')->template->toBe('estate')
        ->and($this->gateway->checkouts[0])->toMatchArray(['email' => 'dana@oak.test', 'unitCents' => 299_000, 'interval' => 'year']);

    // Going back to change something keeps the rest.
    $this->get(RA.'/start?step=1')->assertOk()->assertSee('value="oak-realty"', false);
});

it('puts the new website live in the chosen design the moment they pay (SU2)', function (): void {
    signupStep1();
    $this->post(RA.'/start/step/package', ['package' => $this->package->ulid, 'interval' => 'month']);
    $this->post(RA.'/start/step/design', ['template' => 'ember']);
    $this->post(RA.'/start', ['terms' => '1']);
    $signup = (string) control()->table('signups')->value('ulid');
    signupEvent('evt_paid', 'checkout.session.completed', $signup);

    $tenant = Tenant::query()->where('slug', 'oak-realty')->sole();
    $site = app(TenantContext::class)->runAs($tenant, fn () => \Modules\Sites\Models\Site::query()->where('kind', 'brokerage')->sole());
    expect($site)->template->toBe('ember')->status->toBe('live')
        ->and($site->info['name'])->toBe('Oak Realty');
});

it('shows each design with made-up content for choosing, never indexed (SU2)', function (): void {
    $this->get(RA.'/start/designs/aurum?name=Oak+Realty&area=Austin')->assertOk()->assertSee('Oak Realty')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get(RA.'/start/designs/nope')->assertNotFound();
});
