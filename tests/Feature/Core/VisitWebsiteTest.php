<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Sites\SiteSetup;

/*
| WS1: "Visit website" in the back office's top bar opens the brokerage's live website, never the back office.
*/

beforeEach(fn () => $this->onTenantHost('acme'));

it('gives the back office the live website\'s address for "Visit website"', function (): void {
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('tenant.website', null)); // no website yet

    app(SiteSetup::class)->launch('modern', ['name' => 'Acme Realty'], 'draft');
    $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('tenant.website', null)); // not live yet

    app(SiteSetup::class)->publish(app(SiteSetup::class)->brokerageSite(), true, $owner);
    $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('tenant.website', 'https://acme.rightally.site'));
    $this->actingAs(User::factory()->create())->get('/agent')->assertInertia(fn (Assert $p) => $p->where('tenant.website', 'https://acme.rightally.site'));
});
