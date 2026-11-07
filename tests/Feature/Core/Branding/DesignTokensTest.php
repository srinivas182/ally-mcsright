<?php

declare(strict_types=1);

use Modules\Core\Branding\BrandColourSource;
use Modules\Core\Branding\TenantTheme;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

it('has an up-to-date generated tokens.css', function (): void {
    $this->artisan('design:tokens --check')->assertSuccessful();
});

it('generates light and dark variables for every semantic token', function (): void {
    $css = (string) file_get_contents(resource_path('css/tokens.css'));

    foreach (['background', 'foreground', 'primary', 'primary-foreground', 'muted-foreground', 'border', 'ring', 'brand-600', 'neutral-950'] as $token) {
        expect(substr_count($css, "--{$token}:"))->toBe(2, "--{$token} should be defined for light and dark");
    }
});

it('applies no tenant accent until a brand colour is set', function (): void {
    expect(app(TenantTheme::class)->css())->toBeNull();

    app(TenantContext::class)->set(Tenant::factory()->create());

    expect(app(TenantTheme::class)->css())->toBeNull();
});

it('injects the tenant accent into the page when a brand colour is set', function (): void {
    app()->bind(BrandColourSource::class, fn () => new class implements BrandColourSource
    {
        public function forCurrentTenant(): ?string
        {
            return '#B91C1C';
        }
    });
    app(TenantContext::class)->set(Tenant::factory()->create());

    $css = app(TenantTheme::class)->css();

    expect($css)->toContain(':root {')
        ->and($css)->toContain('--primary: oklch(')
        ->and($css)->not->toContain('--background');
});
