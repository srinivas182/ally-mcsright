<?php

declare(strict_types=1);

use Modules\Core\Tenancy\Models\Tenant;

/*
| BR1: "Log in" on RightAlly's website asks for the brokerage's own address and sends the person to its log in page.
*/

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io', 'core.platform_domain' => 'rightally.app']);
});

it('shows a branded log in page that asks for the brokerage address', function (): void {
    $this->get('http://rightally.io/login')->assertOk()
        ->assertSee('Log in to RightAlly')->assertSee('.rightally.app')->assertSee('https://app.rightally.io/login', false)
        ->assertHeader('X-Robots-Tag', 'noindex');
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
