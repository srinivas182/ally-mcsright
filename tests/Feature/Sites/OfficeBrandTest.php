<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Branding\ColorMath;
use Modules\Core\Branding\Oklch;
use Modules\Core\Branding\ThemeBuilder;
use Modules\Sites\Models\Site;
use Modules\Sites\SiteSetup;

/*
| BR2: a brokerage's back office in its own brand, from its website settings: its colours across the whole back office
| (or buttons and highlights only, or RightAlly's), its logo in the menu, and its logo, name and website photo on the
| sign-in page. Text stays readable whatever the colour.
*/

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('public');
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Dana']);
    $this->site = app(SiteSetup::class)->create('estate', ['area' => 'Austin'], $this->owner);
    $this->setBrand = function (array $brand): void {
        $this->actingAs($this->owner)->patch('/settings/website', ['brand' => $brand + ['primary' => '#7a4b1e', 'accent' => '#0f172a']])->assertRedirect();
    };
});

it('shows the whole back office in the brokerage\'s colours by default, with its logo in the menu', function (): void {
    ($this->setBrand)(['logo_url' => 'https://cdn.acme.test/logo.png']);
    $html = (string) $this->actingAs($this->owner)->get('/dashboard')->assertOk()->getContent();
    expect($html)->toMatch('/html:root:root\s*\{[^}]*--sidebar:/')->toMatch('/--primary:/');
    $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('tenant.logo', 'https://cdn.acme.test/logo.png')
        ->where('tenant.branded', true)->where('tenant.photo', fn ($v): bool => is_string($v) && $v !== ''));
});

it('can keep the colour to buttons and highlights, or use RightAlly\'s own look', function (): void {
    ($this->setBrand)(['office' => 'accent']);
    expect(Site::query()->whereKey($this->site->id)->value('brand')['office'])->toBe('accent');
    $html = (string) $this->actingAs($this->owner)->get('/dashboard')->getContent();
    expect($html)->toContain('--primary:')->not->toMatch('/--sidebar:\s*oklch/');

    ($this->setBrand)(['office' => 'off']);
    $html = (string) $this->actingAs($this->owner)->get('/dashboard')->getContent();
    expect($html)->not->toContain('--sidebar-primary:');
    $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('tenant.branded', false)->where('tenant.logo', null));
});

it('puts the brokerage\'s name, photo and colours on its sign-in page', function (): void {
    ($this->setBrand)([]);
    auth()->logout();
    $this->get('/login')->assertOk()->assertInertia(fn (Assert $p) => $p->where('tenant.name', $this->tenant->name)->where('tenant.branded', true)
        ->where('tenant.photo', fn ($v): bool => is_string($v) && $v !== ''));
});

it('keeps menu and button text readable even with a very light brand colour', function (): void {
    $theme = app(ThemeBuilder::class)->backOfficeFull('#f4e3a1'); // pale gold
    $parse = function (string $css): Oklch {
        preg_match('/oklch\(([\d.]+)%?\s+([\d.]+)\s+([\d.]+)/', $css, $m);

        return new Oklch((float) $m[1] > 1 ? (float) $m[1] / 100 : (float) $m[1], (float) $m[2], (float) $m[3]);
    };
    foreach ([['primary', 'primary-foreground'], ['sidebar', 'sidebar-foreground'], ['sidebar-primary', 'sidebar-primary-foreground']] as [$bg, $fg]) {
        expect(ColorMath::contrast($parse($theme->light[$bg]), $parse($theme->light[$fg])))->toBeGreaterThanOrEqual(4.5);
    }
});
