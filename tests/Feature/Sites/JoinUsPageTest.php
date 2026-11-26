<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Sites\Models\SitePage;
use Modules\Sites\Sections;
use Modules\Sites\SiteSetup;

/* AG8: every brokerage website has a "Join us" page for agents: why join, how they can be paid (the plans offered
   under Commissions › Plans, live), how joining works, and a button that opens joining online. With no agent's
   link, a new agent joins with the brokerage itself. The page is edited in the website builder like any other. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $this->site = $sites->create('aurum', ['phone' => '(512) 555-0100'], $this->owner);
    $sites->publish($this->site, true, $this->owner);
});

it('adds Join us to the menu of a new brokerage website, just before Contact', function (): void {
    $menu = SitePage::query()->where('site_id', $this->site->id)->where('in_menu', true)->orderBy('position')->pluck('path')->all();

    expect($menu)->toContain('/join-us')
        ->and(array_search('/join-us', $menu, true))->toBe(array_search('/contact', $menu, true) - 1);
    $this->get($this->web.'/')->assertOk()->assertSee('href="/join-us"', false)->assertSee('Join us');
});

it('shows why agents join, the plans they can pick, and buttons that open joining', function (): void {
    $plans = app(ManagePlans::class);
    $share = $plans->create(PlanType::Commission, 'Revenue share', ['split' => ['mode' => 'percentage', 'agent_percent' => '90'],
        'cap' => ['amount_cents' => 1_600_000, 'anchor' => 'anniversary'], 'post_cap' => ['fee' => ['calc' => 'flat', 'amount' => 50_000]]]);
    $plans->publish($share->versions()->sole(), CarbonImmutable::parse('2026-01-01'));

    // Not offered yet: the page still works, without plan cards.
    $this->get($this->web.'/join-us')->assertOk()->assertSee('Grow your real estate business with')->assertSee('href="/join"', false)
        ->assertDontSee('How you can be paid')->assertSee('Questions agents ask');

    $plans->offerOnJoin($share->fresh(), true);
    $this->get($this->web.'/join-us')->assertOk()->assertSee('How you can be paid')->assertSee('Revenue share')
        ->assertSee('You keep 90% of each commission; the brokerage keeps 10%.')->assertSee('$16,000 a year (your cap)', false);
});

it('opens joining with no sponsor from /join on the website', function (): void {
    $this->get($this->web.'/join')->assertRedirect()->assertHeader('Cache-Control', 'no-store, private');
    expect($this->get($this->web.'/join')->headers->get('Location'))->toEndWith('/join');
});

it('keeps the section safe and editable in the builder', function (): void {
    $clean = Sections::clean([['type' => 'recruit', 'id' => 'join', 'props' => ['headline' => '<b>Join</b> us', 'link' => 'javascript:alert(1)',
        'points' => [['text' => 'One'], 'bad', ['text' => str_repeat('x', 300)]], 'plans' => false]]])[0];

    expect($clean['props']['headline'])->toBe('Join us')->and($clean['props']['link'])->toBe('/contact')
        ->and($clean['props']['points'])->toHaveCount(2)->and(mb_strlen($clean['props']['points'][1]['text']))->toBe(120)
        ->and($clean['props']['plans'])->toBeFalse()
        ->and(Sections::blank('recruit')['props']['link'])->toBe('/join');
});
