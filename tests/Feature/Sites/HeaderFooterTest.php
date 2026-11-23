<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Modules\Sites\SiteSetup;

/*
| FT1: every website's header has the menu in the middle and the phone number (or "Contact us") on the right; the
| footer has the brokerage, its pages, resources and contact details, then the legal notices; the website assistant
| is on unless the brokerage turns it off.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
});

it('shows the phone in the header, a four-column footer and the assistant', function (): void {
    app(SiteSetup::class)->create('mesa', ['name' => 'Acme Realty', 'area' => 'Austin', 'phone' => '(512) 555-0100', 'email' => 'hi@acme.test', 'licence' => 'TX 0654321'], $this->owner)->update(['status' => 'live']);
    $html = (string) $this->get('http://acme.rightally.site/')->assertOk()->getContent();
    expect($html)->toContain('class="header-actions"')->toContain('href="tel:5125550100"')->toContain('(512) 555-0100')
        ->toContain('class="wrap footer-top"')->toContain('>Explore<')->toContain('>Resources<')->toContain('>Contact<')
        ->toContain('Licence TX 0654321')->toContain('Talk to an agent')->toContain('class="wrap footer-bottom"')
        ->toContain('data-ailly'); // the assistant, on by default
});

it('offers "Contact us" in the header until there is a phone number', function (): void {
    app(SiteSetup::class)->create('modern', ['name' => 'Acme Realty', 'area' => 'Austin'], $this->owner)->update(['status' => 'live']);
    expect((string) $this->get('http://acme.rightally.site/')->getContent())->toContain('<a class="btn btn-ghost header-call" href="/contact">Contact us</a>');
});
