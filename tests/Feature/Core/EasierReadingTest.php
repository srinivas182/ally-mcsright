<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Sites\SiteSetup;

/*
| AX1 (specs/core/13): each person can make RightAlly easier to read, applied before the page draws; every
| brokerage website has an accessibility statement.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
});

it('saves each person\'s easier-reading choices and shares them with every page', function (): void {
    $this->actingAs($this->owner)->get('/settings/appearance')
        ->assertInertia(fn (Assert $page) => $page->where('reading', ['text' => 'standard', 'contrast' => false, 'motion' => false, 'links' => false]));

    $this->actingAs($this->owner)->post('/settings/reading', ['text' => 'huge'])->assertSessionHasErrors('text');
    $this->actingAs($this->owner)->post('/settings/reading', ['text' => 'largest'])->assertRedirect();
    $this->actingAs($this->owner)->post('/settings/reading', ['contrast' => true, 'links' => true])->assertRedirect();

    expect($this->owner->fresh()?->ui_reading)->toEqual(['text' => 'largest', 'contrast' => true, 'motion' => false, 'links' => true]);
    $this->actingAs($this->owner)->get('/settings/appearance')
        ->assertInertia(fn (Assert $page) => $page->where('reading.text', 'largest')->where('reading.contrast', true))
        ->assertSee('data-text="largest"', false)->assertSee('data-contrast="high"', false)->assertSee('data-links="underline"', false)
        ->assertDontSee('data-motion=', false);

    // Someone else's choices are their own.
    $other = User::factory()->create();
    $this->actingAs($other)->get('/settings/appearance')->assertInertia(fn (Assert $page) => $page->where('reading.text', 'standard'))->assertDontSee('data-text=', false);
});

it('gives every brokerage website an accessibility statement, linked from every page and in the sitemap', function (): void {
    $web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $site = $sites->create('modern', ['name' => 'Acme Realty', 'email' => 'help@acme.test'], $this->owner);
    $sites->publish($site, true, $this->owner);

    $this->get($web.'/accessibility')->assertOk()
        ->assertSee('<title>Accessibility', false)->assertSee('<link rel="canonical" href="'.$web.'/accessibility"', false)
        ->assertSee('WCAG')->assertSee('2.2')->assertSee('Skip to content')->assertSee('two business days');
    $this->get($web.'/')->assertOk()->assertSee('<a href="/accessibility">Accessibility</a>', false);
    $this->get($web.'/sitemap.xml')->assertOk()->assertSee($web.'/accessibility', false);
});
