<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Ads\ListingAds;
use Modules\Core\Privacy\PrivacyConfirmMail;
use Modules\Core\Support\Connections;
use Modules\Sites\SiteSetup;

/* Sprint W15: every brokerage website has a privacy policy at /privacy — RightAlly's standard one, matching what the
   platform actually does, or the brokerage's own — linked from every page's footer and used by instant-form ads. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $this->site = $sites->create('modern', ['name' => 'Acme Realty'], $this->owner);
    $sites->publish($this->site, true, $this->owner);
});

it('shows a plain standard privacy policy, linked from every page and listed in the sitemap', function (): void {
    $this->get($this->web.'/privacy')->assertOk()
        ->assertSee('<title>Privacy policy', false)->assertSee('<link rel="canonical" href="'.$this->web.'/privacy"', false)
        ->assertDontSee('noindex', false)->assertSee('Acme Realty')->assertSee('never sell')->assertSee('Global Privacy Control')->assertSee('STOP');
    $this->get($this->web.'/')->assertOk()->assertSee('<a href="/privacy">Privacy policy</a>', false);
    $this->get($this->web.'/sitemap.xml')->assertOk()->assertSee($this->web.'/privacy', false);
});

it('uses the brokerage\'s own policy when the owner writes one, escaped as plain paragraphs', function (): void {
    $this->actingAs($this->owner)->patch('/settings/website', ['info' => ['privacy' => "Our own policy.\n\n<script>alert(1)</script>Second part."]])->assertSessionHasNoErrors();
    $this->get($this->web.'/privacy')->assertOk()->assertSee('Our own policy.')->assertSee('Second part.')
        ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('What we collect');
});

it('offers the site\'s policy as the instant-form ad privacy link', function (): void {
    $this->get($this->web.'/privacy')->assertOk();
    expect(app(ListingAds::class)->privacyUrl())->toEndWith('/privacy');
});

it('takes a privacy request from the website and confirms it by email before the brokerage sees it (TR1)', function (): void {
    Notification::fake();
    $this->get($this->web.'/privacy')->assertOk()->assertSee('Make a privacy request')->assertSee('action="/_privacy"', false);
    $this->post($this->web.'/_privacy', ['kind' => 'delete', 'name' => 'Nia Lopez', 'email' => 'nia@example.test', 'website' => ''])
        ->assertRedirect($this->web.'/privacy?sent=1#request');
    $this->post($this->web.'/_privacy', ['kind' => 'delete', 'name' => 'Bot', 'email' => 'bot@example.test', 'website' => 'spam']); // honeypot

    $link = null;
    Notification::assertSentOnDemand(PrivacyConfirmMail::class, function ($m) use (&$link): bool {
        $link = $m->link;

        return true;
    });
    expect(DB::connection(Connections::cell())->table('privacy_requests')->count())->toBe(1)
        ->and($link)->toStartWith($this->web.'/privacy/confirm/');
    $this->get($link)->assertOk()->assertSee('Your request is confirmed')->assertHeader('Cache-Control', 'no-store, private');
    $this->get($link)->assertOk()->assertSee('expired or was already used');
    $this->post($this->web.'/_privacy', ['kind' => 'nope', 'name' => 'Pat', 'email' => 'pat@example.test'])->assertRedirectContains('error=');
});
