<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Connect\OAuth\Providers;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/* Sprint C1b: HQ → Connect setup — return addresses to copy, steps to follow, and RightAlly's sign-in apps. */

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io', 'services.google.client_id' => '', 'services.google.client_secret' => '', 'services.microsoft.client_id' => '']);
    $this->onTenantHost('acme');
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->hq = 'http://app.rightally.io';
});

it('shows the return addresses to add and whether each sign-in app is ready', function (): void {
    $this->actingAs($this->staff)->get($this->hq.'/hq/connect')->assertInertia(fn (Assert $p) => $p->component('hq/connect')
        ->where('return_addresses.connect', 'https://auth.rightally.app/connect/oauth/callback')
        ->where('return_addresses.google_sign_in', 'https://auth.rightally.app/oauth/google/callback')
        ->where('providers.google.set', false));
});

it('saves the Google app (secret encrypted and never shown) and switches one-click on', function (): void {
    $save = fn (array $in) => $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])->post($this->hq.'/hq/connect/google', $in);
    $save(['client_id' => 'not-a-google-id', 'client_secret' => 'GOCSPX-abcdefghijklmnop'])->assertSessionHasErrors('client_id');
    $save(['client_id' => '1234567890-abcdef.apps.googleusercontent.com', 'client_secret' => 'short'])->assertSessionHasErrors('client_secret');
    $save(['client_id' => '1234567890-abcdef.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-abcdefghijklmnop'])->assertSessionHasNoErrors();

    expect(DB::connection(Connections::control())->table('hq_oauth_clients')->value('client_secret'))->not->toContain('GOCSPX')
        ->and(Providers::configured('google'))->toBeTrue()
        ->and(config('services.google.client_secret'))->toBe('GOCSPX-abcdefghijklmnop');
    $page = $this->actingAs($this->staff)->get($this->hq.'/hq/connect');
    $page->assertInertia(fn (Assert $p) => $p->where('providers.google.set', true)->where('providers.google.from', 'hq'));
    expect((string) $page->getContent())->not->toContain('GOCSPX');

    // Changing only the ID keeps the saved secret.
    $save(['client_id' => '999-xyz.apps.googleusercontent.com', 'client_secret' => ''])->assertSessionHasNoErrors();
    expect(config('services.google.client_secret'))->toBe('GOCSPX-abcdefghijklmnop')->and(config('services.google.client_id'))->toBe('999-xyz.apps.googleusercontent.com');
});

it('checks the Microsoft client ID shape and keeps the secret expiry', function (): void {
    $save = fn (array $in) => $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])->post($this->hq.'/hq/connect/microsoft', $in);
    $save(['client_id' => 'my app', 'client_secret' => 'abcdefghijklmnopqrstu'])->assertSessionHasErrors('client_id');
    $save(['client_id' => '1a2b3c4d-1111-2222-3333-444455556666', 'client_secret' => 'abc~defghijklmnopqrstu', 'secret_expires_on' => '2027-05-01'])->assertSessionHasNoErrors();
    $this->actingAs($this->staff)->get($this->hq.'/hq/connect')->assertInertia(fn (Assert $p) => $p->where('providers.microsoft.secret_expires_on', '2027-05-01'));
});

it('asks for the password before saving, and is for HQ only', function (): void {
    $this->actingAs($this->staff)->post($this->hq.'/hq/connect/google', ['client_id' => '1-a.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-abcdefghijklmnop'])->assertRedirect();
    expect(DB::connection(Connections::control())->table('hq_oauth_clients')->count())->toBe(0);
});

it('gives HQ a ready-written request to send to Follow Up Boss, with the return address filled in', function (): void {
    $this->actingAs($this->staff)->get($this->hq.'/hq/connect')->assertInertia(fn (Assert $p) => $p
        ->where('apps', function ($apps): bool {
            $fub = collect($apps)->firstWhere('key', 'follow_up_boss');

            return $fub['request']['to'] === 'api@followupboss.com'
                && str_contains($fub['request']['body'], 'https://auth.rightally.app/connect/oauth/callback')
                && ! str_contains($fub['request']['body'], '{return_address}')
                && collect($apps)->firstWhere('key', 'sierra')['request'] === null;
        }));
});
