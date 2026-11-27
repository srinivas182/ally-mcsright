<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Enums\SslStatus;
use Modules\Core\Tenancy\Enums\VerificationStatus;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Sites\Domains\DnsLookup;
use Modules\Sites\SiteSetup;

/* DM2: a brokerage connects its own web address. It is registered with Cloudflare for SaaS (HQ's choice under
   Outside services), checked every few minutes, and the website moves there once it points to us and https is
   ready; until then (and if it stops working) the website stays on its free address. */

final class FakeDns extends DnsLookup
{
    /** @var array<string, string> */
    public array $cnames = [];

    public function cname(string $host): ?string
    {
        return $this->cnames[$host] ?? null;
    }

    public function addresses(string $host): array
    {
        return [];
    }
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $sites = app(SiteSetup::class);
    $this->site = $sites->create('aurum', ['phone' => '(512) 555-0100'], $this->owner);
    $sites->publish($this->site, true, $this->owner);
    app()->instance(DnsLookup::class, $this->dns = new FakeDns);
    DB::connection(Connections::control())->table('outside_services')->insert(['tenant_id' => 0, 'service' => 'custom_domains', 'provider' => 'cloudflare_saas', 'mode' => 'test',
        'test_keys' => Crypt::encryptString('{"zone_id":"zone-1","api_token":"cf-token"}'), 'live_keys' => null, 'test_contacts' => '', 'created_at' => now(), 'updated_at' => now()]);
    $this->cfStatus = 'pending';
    Http::fake([
        'api.cloudflare.com/client/v4/zones/zone-1/custom_hostnames' => Http::response(['success' => true, 'result' => ['id' => 'ch-1', 'status' => 'pending', 'ssl' => ['status' => 'initializing']]]),
        'api.cloudflare.com/client/v4/zones/zone-1/custom_hostnames/ch-1' => fn ($request) => $request->method() === 'DELETE' ? Http::response(['success' => true])
            : Http::response(['success' => true, 'result' => ['id' => 'ch-1', 'status' => $this->cfStatus, 'ssl' => ['status' => $this->cfStatus === 'active' ? 'active' : 'pending_validation']]]),
    ]);
});

it('registers the domain with Cloudflare and keeps the website on its free address until it is connected', function (): void {
    $this->actingAs($this->owner)->post('/settings/website/domains', ['host' => 'www.acmehomes.com'])->assertSessionHasNoErrors();
    $domain = TenantDomain::query()->where('host', 'www.acmehomes.com')->sole();

    expect($domain->provider_hostname_id)->toBe('ch-1')->and($domain->verification_status)->toBe(VerificationStatus::Pending)
        ->and($domain->check_message)->toContain('CNAME record named www with the value '.config('sites.cname_target'))
        ->and(app(SiteSetup::class)->liveHost())->toBe('acme.rightally.site');
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['hostname'] === 'www.acmehomes.com' && $r->hasHeader('Authorization', 'Bearer cf-token'));
    $this->get('http://acme.rightally.site/')->assertOk();
});

it('connects the domain once DNS points to us and https is ready, and the free address sends visitors there', function (): void {
    $this->actingAs($this->owner)->post('/settings/website/domains', ['host' => 'www.acmehomes.com']);
    $this->dns->cnames['www.acmehomes.com'] = (string) config('sites.cname_target');
    $this->travel(6)->minutes();
    $this->artisan('sites:check-domains')->assertSuccessful();
    expect(TenantDomain::query()->where('host', 'www.acmehomes.com')->sole()->check_message)->toContain('Cloudflare is confirming');

    $this->cfStatus = 'active';
    $this->travel(6)->minutes();
    $this->artisan('sites:check-domains')->assertSuccessful();
    $domain = TenantDomain::query()->where('host', 'www.acmehomes.com')->sole();

    expect($domain->verification_status)->toBe(VerificationStatus::Verified)->and($domain->ssl_status)->toBe(SslStatus::Active)
        ->and($domain->check_message)->toBeNull()->and(app(SiteSetup::class)->liveHost())->toBe('www.acmehomes.com');
    $this->get('http://acme.rightally.site/buy?x=1')->assertStatus(301)->assertRedirect('https://www.acmehomes.com/buy?x=1');
    $this->actingAs($this->owner)->get('/settings/website')->assertInertia(fn (Assert $p) => $p->where('live_host', 'www.acmehomes.com')
        ->where('hosts.1.verified', true));
});

it('falls back to the free address if the domain stops pointing to us, and removes a domain', function (): void {
    $this->actingAs($this->owner)->post('/settings/website/domains', ['host' => 'www.acmehomes.com']);
    $this->dns->cnames['www.acmehomes.com'] = (string) config('sites.cname_target');
    $this->cfStatus = 'active';
    $domain = TenantDomain::query()->where('host', 'www.acmehomes.com')->sole();
    $this->post("/settings/website/domains/{$domain->ulid}/check")->assertRedirect();
    expect(app(SiteSetup::class)->liveHost())->toBe('www.acmehomes.com');

    $this->cfStatus = 'moved';
    unset($this->dns->cnames['www.acmehomes.com']);
    $this->post("/settings/website/domains/{$domain->ulid}/check")->assertRedirect();
    expect($domain->fresh()->verification_status)->toBe(VerificationStatus::Failed)->and(app(SiteSetup::class)->liveHost())->toBe('acme.rightally.site');
    $this->get('http://acme.rightally.site/')->assertOk();

    $this->withSession(['auth.password_confirmed_at' => time()])->delete("/settings/website/domains/{$domain->ulid}")->assertRedirect();
    expect(TenantDomain::query()->where('host', 'www.acmehomes.com')->exists())->toBeFalse();
    Http::assertSent(fn ($r) => $r->method() === 'DELETE');
});

it('only checks DNS when HQ uses its own server, and lets only owners and staff change domains', function (): void {
    DB::connection(Connections::control())->table('outside_services')->where('service', 'custom_domains')->update(['provider' => 'dns_only']);
    $this->dns->cnames['www.acmehomes.com'] = (string) config('sites.cname_target');
    $this->actingAs($this->owner)->post('/settings/website/domains', ['host' => 'www.acmehomes.com']);
    expect(TenantDomain::query()->where('host', 'www.acmehomes.com')->sole()->verification_status)->toBe(VerificationStatus::Verified);
    Http::assertNothingSent();

    $agent = User::factory()->create();
    $this->actingAs($agent)->post('/settings/website/domains/'.TenantDomain::query()->where('host', 'www.acmehomes.com')->value('ulid').'/check')->assertForbidden();
});
