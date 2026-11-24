<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavWords;
use Modules\Core\Switches\FeatureSwitches;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/*
| PW1 (specs/core/17): the installable app (manifest, home page, service worker that never stores pages or data),
| universal search that only finds what the person may see, and the whole menu in Spanish.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->tenant->forceFill(['name' => 'Acme Realty'])->save();
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $this->bob = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Broker']);
});

it('serves an installable app manifest named after the brokerage, without signing in', function (): void {
    $this->get('/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('name', 'Acme Realty · RightAlly')->assertJsonPath('short_name', 'Acme Realty')
        ->assertJsonPath('display', 'standalone')->assertJsonPath('start_url', '/app')
        ->assertJsonPath('icons.1.sizes', '512x512')->assertJsonPath('icons.2.purpose', 'maskable');
    foreach (['icons/icon-192.png', 'icons/icon-512.png', 'icons/maskable-512.png', 'offline.html', 'sw.js'] as $file) {
        expect(file_exists(public_path($file)))->toBeTrue();
    }
    // The service worker only keeps built files and the offline page: pages always come from the network.
    $sw = (string) file_get_contents(public_path('sw.js'));
    expect($sw)->toContain("request.mode === 'navigate'")->toContain('fetch(request).catch(() => caches.match(OFFLINE))')
        ->and(substr_count($sw, 'cache.put'))->toBe(1)->and($sw)->toContain('STATIC.test(url.pathname)');

    $this->get('/dashboard')->assertRedirect(); // the page itself still needs signing in
    $this->actingAs($this->ann)->get('/dashboard')->assertSee('rel="manifest"', false)->assertSee('apple-mobile-web-app-capable', false);
});

it('opens the installed app on each person\'s own home page', function (): void {
    $this->get('/app')->assertRedirect('/login');
    $this->actingAs($this->owner)->get('/app')->assertRedirect('/dashboard');
    $this->actingAs($this->ann)->get('/app')->assertRedirect('/agent');
});

it('finds only the contacts, deals and people the person may see', function (): void {
    $this->actingAs($this->ann)->post('/contacts', ['first_name' => 'Nia', 'last_name' => 'Lopez', 'email' => 'nia@example.test', 'source' => 'open_house']);
    $this->actingAs($this->bob)->post('/contacts', ['first_name' => 'Nina', 'last_name' => 'Secret', 'email' => 'nina@example.test', 'source' => 'open_house']);
    $this->actingAs($this->ann)->post('/deals', ['type' => 'listing', 'street' => '12 Nightingale Way', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000']);

    $res = $this->actingAs($this->ann)->getJson('/search?q=ni')->assertOk()->json('groups');
    $groups = collect($res)->keyBy('key');
    expect($groups->get('contacts')['items'])->toHaveCount(1)
        ->and($groups->get('contacts')['items'][0]['title'])->toBe('Nia Lopez')
        ->and($groups->get('deals')['items'][0]['title'])->toBe('12 Nightingale Way')
        ->and($groups->has('people'))->toBeFalse(); // agents cannot list the brokerage's people

    $owner = collect($this->actingAs($this->owner)->getJson('/search?q=ann')->json('groups'))->keyBy('key');
    expect($owner->get('people')['items'][0]['title'])->toBe('Ann Agent');

    // Too short, and wildcard characters are taken literally.
    expect($this->actingAs($this->ann)->getJson('/search?q=n')->json('groups'))->toBe([])
        ->and($this->actingAs($this->ann)->getJson('/search?q=%25%25')->json('groups'))->toBe([]);
    auth()->guard('web')->logout();
    $this->getJson('/search?q=nia')->assertUnauthorized();
});

it('answers in Spanish and drops results from areas that are switched off', function (): void {
    $this->ann->forceFill(['ui_locale' => 'es'])->save();
    $this->actingAs($this->ann)->post('/contacts', ['first_name' => 'Nia', 'email' => 'nia@example.test', 'source' => 'open_house']);
    expect(collect($this->actingAs($this->ann)->getJson('/search?q=nia')->json('groups'))->firstWhere('key', 'contacts')['label'])->toBe('Contactos');

    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    app(TenantContext::class)->set($this->tenant);
    app(FeatureSwitches::class)->setByHq($this->tenant->id, 'network', 'off', null, null, $staff);
    expect(app(FeatureSwitches::class)->allowsPath($this->ann, '/network'))->toBeFalse();
});

it('shows the whole menu in Spanish to people who chose Spanish', function (string $kind): void {
    $user = match ($kind) {
        'owner' => User::factory()->owner()->create(['ui_locale' => 'es', 'ui_mode' => 'full']),
        'staff' => User::factory()->staff()->create(['ui_locale' => 'es', 'ui_mode' => 'full']),
        default => User::factory()->create(['ui_locale' => 'es', 'ui_mode' => 'full']),
    };
    $menu = app(NavigationRegistry::class)->for($user);
    $sameInBoth = ['Ally Studio', 'List-IQ', 'MLS', 'MLS / IDX', 'Movewise', 'Blog'];
    $untranslated = collect($menu)->flatMap(fn ($g) => array_column($g['items'], 'title'))
        ->filter(fn (string $t): bool => ! in_array($t, $sameInBoth, true) && array_key_exists($t, NavWords::ES))->values()->all();
    expect($untranslated)->toBe([])
        ->and(collect($menu)->pluck('label')->intersect(array_keys(NavWords::GROUPS))->diff(['CRM'])->values()->all())->toBe([]); // CRM is CRM in Spanish too

    $english = collect(app(NavigationRegistry::class)->for($user->forceFill(['ui_locale' => 'en'])))->flatMap(fn ($g) => array_column($g['items'], 'title'));
    expect($english->reject(fn (string $t): bool => array_key_exists($t, NavWords::ES))->values()->all())->toBe([]); // every item has a Spanish word
})->with(['owner', 'staff', 'agent']);
