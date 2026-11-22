<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Enums\SslStatus;
use Modules\Core\Tenancy\Enums\VerificationStatus;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\TenantContext;

/*
| BR1: "Log in" on RightAlly's website asks for the brokerage's own address and sends the person to its log in page.
*/

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io', 'core.platform_domain' => 'rightally.app']);
});

it('shows a branded log in page that asks for an email, or the brokerage address', function (): void {
    $this->get('http://rightally.io/login')->assertOk()
        ->assertSee('Log in to RightAlly')->assertSee('Your email')->assertSee('https://app.rightally.io/login', false)
        ->assertHeader('X-Robots-Tag', 'noindex');
    $this->get('http://rightally.io/login?by=address')->assertOk()->assertSee('.rightally.app');
    $this->get('http://rightally.io/sign-in')->assertRedirect('/login');
});

it('sends people to their brokerage log in page, however they type the address', function (string $typed): void {
    Tenant::factory()->withPlatformDomain()->create(['slug' => 'acme', 'status' => 'active']);

    $this->get('http://rightally.io/login/go?address='.urlencode($typed))->assertRedirect('https://acme.rightally.app/login');
})->with(['acme', ' ACME ', 'acme.rightally.app', 'https://acme.rightally.app/dashboard']);

it('says plainly when no brokerage has that address, and never sends anyone to HQ', function (): void {
    Tenant::factory()->withPlatformDomain()->create(['slug' => 'gone', 'status' => 'suspended']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();

    foreach (['nobody', 'gone', $hq->slug, '../evil', ''] as $typed) {
        $this->get('http://rightally.io/login/go?address='.urlencode($typed))->assertStatus(422)->assertSee('role="alert"', false);
    }
});

it('shows a branded page when a page is missing', function (): void {
    $this->get('http://rightally.io/no-such-page')->assertNotFound()->assertSee('We could not find that page')->assertSee('href="/login"', false);
});

it('finds the brokerage from the email and opens its log in page with the email filled in (LOGIN1)', function (): void {
    $acme = Tenant::factory()->withPlatformDomain()->create(['slug' => 'acme', 'name' => 'Acme Realty', 'status' => 'active']);
    app(TenantContext::class)->runAs($acme, fn () => User::factory()->create(['email' => 'pat@example.com']));

    $this->get('http://rightally.io/login/go?email='.urlencode(' Pat@Example.com '))->assertRedirect('https://acme.rightally.app/login?email=pat%40example.com');
    $this->get('http://rightally.io/login/go?email=nobody%40example.com')->assertStatus(422)->assertSee('could not find a RightAlly account');
    $this->get('http://rightally.io/login/go?email=not-an-email')->assertStatus(422);

    // The brokerage's own log in page has it filled in.
    $this->get('http://acme.rightally.app/login?email=pat%40example.com')->assertInertia(fn (Assert $page) => $page->where('email', 'pat@example.com'));
});

it('sends a brokerage with its own verified domain there, and lists every brokerage an email belongs to (LOGIN1)', function (): void {
    $acme = Tenant::factory()->withPlatformDomain()->create(['slug' => 'acme', 'name' => 'Acme Realty', 'status' => 'active']);
    $oak = Tenant::factory()->withPlatformDomain()->create(['slug' => 'oak', 'name' => 'Oak Homes', 'status' => 'active']);
    TenantDomain::query()->where('tenant_id', $oak->id)->update(['is_primary' => false]);
    TenantDomain::query()->create(['tenant_id' => $oak->id, 'host' => 'office.oakhomes.com', 'kind' => DomainKind::Custom, 'is_primary' => true,
        'verification_status' => VerificationStatus::Verified, 'ssl_status' => SslStatus::Active, 'purpose' => 'app']);
    foreach ([$acme, $oak] as $t) {
        app(TenantContext::class)->runAs($t, fn () => User::factory()->create(['email' => 'sam@example.com']));
    }

    $this->get('http://rightally.io/login/go?email=sam%40example.com')->assertOk()
        ->assertSee('Continue to Acme Realty')->assertSee('https://acme.rightally.app/login?email=sam%40example.com', false)
        ->assertSee('Continue to Oak Homes')->assertSee('https://office.oakhomes.com/login?email=sam%40example.com', false);
});

it('never lists HQ, and on HQ\'s log in page shows a brokerage person where to go (LOGIN1)', function (): void {
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create(['email' => 'staff@rightally.test']));
    $this->get('http://rightally.io/login/go?email=staff%40rightally.test')->assertStatus(422);

    $acme = Tenant::factory()->withPlatformDomain()->create(['slug' => 'acme', 'name' => 'Acme Realty', 'status' => 'active']);
    app(TenantContext::class)->runAs($acme, fn () => User::factory()->create(['email' => 'pat@example.com']));
    $this->from('http://app.rightally.io/login')->post('http://app.rightally.io/login', ['email' => 'pat@example.com', 'password' => 'wrong'])->assertRedirect();
    $this->get('http://app.rightally.io/login')->assertInertia(fn (Assert $page) => $page
        ->where('elsewhere.brokerages.0.name', 'Acme Realty')->where('elsewhere.brokerages.0.url', 'https://acme.rightally.app/login?email=pat%40example.com'));
});
