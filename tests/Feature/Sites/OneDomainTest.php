<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Signup\Signups;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\Models\TenantHostRedirect;
use Modules\Core\Tenancy\Slug;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\SiteSetup;

/*
| DM1: one domain. A brokerage's public website is acme.rightally.app and its back office acme-office.rightally.app;
| acme.rightally.app/backoffice (and /login, /admin, /office, old /settings/… links) lead to the back office, on its own
| domain too. Existing brokerages are moved over on deploy.
*/

function oneDomain(): void
{
    config(['core.office_suffix' => '-office', 'sites.platform_domain' => 'rightally.app']);
}

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('public');
});

it('gives back offices the -office address, keeps "-office" names for them, and finds a brokerage from either address', function (): void {
    oneDomain();
    expect(Host::platformHostFor('acme'))->toBe('acme-office.rightally.app')
        ->and(Slug::problem('acme-office'))->toBe('Addresses ending in "-office" are reserved. Please choose another.')
        ->and(Host::slugFromTyped('https://acme-office.rightally.app/settings'))->toBe('acme')
        ->and(Host::slugFromTyped('acme.rightally.app'))->toBe('acme')
        ->and(Signups::websiteHost('acme'))->toBe('acme.rightally.app');
});

it('serves the website at the brokerage\'s name and sends back-office addresses to name-office', function (): void {
    oneDomain();
    $tenant = $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    expect(TenantDomain::query()->where('tenant_id', $tenant->id)->where('purpose', '!=', 'website')->value('host'))->toBe('acme-office.rightally.app');
    $sites = app(SiteSetup::class);
    $site = $sites->create('modern', ['area' => 'Austin'], $owner);
    $sites->publish($site, true, $owner);
    expect(TenantDomain::query()->where('tenant_id', $tenant->id)->where('purpose', 'website')->value('host'))->toBe('acme.rightally.app');
    app(TenantContext::class)->forget();

    $home = (string) $this->get('http://acme.rightally.app/')->assertOk()->getContent();
    expect($home)->toContain('href="/backoffice"')->toContain('Agent login');
    $this->get('http://acme.rightally.app/homes')->assertOk();
    foreach (['backoffice', 'back-office', 'login', 'admin', 'office'] as $alias) {
        $this->get('http://acme.rightally.app/'.$alias)->assertRedirect('https://acme-office.rightally.app/login');
    }
    $this->get('http://acme.rightally.app/settings/website?tab=pages')->assertRedirect('https://acme-office.rightally.app/settings/website?tab=pages');
    $this->get('http://acme.rightally.app/invitations/abc123')->assertRedirect('https://acme-office.rightally.app/invitations/abc123');
    $this->get('http://acme.rightally.app/no-such-page')->assertNotFound(); // the website's own "not found"
    $this->get('http://acme-office.rightally.app/login')->assertOk(); // the back office itself

    // On the brokerage's own domain too.
    TenantDomain::query()->create(['tenant_id' => $tenant->id, 'host' => 'www.joneshomes.com', 'kind' => DomainKind::Custom, 'is_primary' => false,
        'verification_status' => 'verified', 'ssl_status' => 'active', 'purpose' => 'website']);
    $this->get('http://www.joneshomes.com/backoffice')->assertRedirect('https://acme-office.rightally.app/login');
});

it('moves existing brokerages to one domain on deploy, once, keeping old back-office links working', function (): void {
    // Before: back office at acme.rightally.app, website at acme.rightally.site.
    $acme = $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('modern', ['area' => 'Austin'], $owner), true, $owner);
    expect(TenantDomain::query()->where('tenant_id', $acme->id)->where('purpose', 'website')->value('host'))->toBe('acme.rightally.site');
    $oak = Tenant::factory()->withPlatformDomain()->create(['slug' => 'oak']); // no website yet
    app(TenantContext::class)->forget();

    $this->artisan('sites:one-domain')->expectsOutputToContain('One domain is off')->assertSuccessful();

    oneDomain();
    $this->artisan('sites:one-domain')->assertSuccessful();
    $hosts = fn (Tenant $t): array => TenantDomain::query()->where('tenant_id', $t->id)->orderBy('purpose')->pluck('host', 'purpose')->all();
    expect($hosts($acme))->toBe(['app' => 'acme-office.rightally.app', 'website' => 'acme.rightally.app'])
        ->and($hosts($oak))->toBe(['app' => 'oak-office.rightally.app'])
        ->and(TenantHostRedirect::query()->where('host', 'oak.rightally.app')->value('tenant_id'))->toBe($oak->id)
        ->and(TenantHostRedirect::query()->where('host', 'acme.rightally.app')->exists())->toBeFalse();

    // Again: nothing changes.
    $this->artisan('sites:one-domain')->assertSuccessful();
    expect($hosts($acme))->toBe(['app' => 'acme-office.rightally.app', 'website' => 'acme.rightally.app']);

    // Old links: acme's website sends back-office paths on; oak (no website) forwards everything to its back office.
    $this->get('http://acme.rightally.app/settings')->assertRedirect('https://acme-office.rightally.app/settings');
    $this->get('http://oak.rightally.app/contacts')->assertRedirectContains('oak-office.rightally.app');
});
