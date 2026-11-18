<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Crm\Consent;
use Modules\Crm\Consent\ConsentAskMail;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;

/*
| CN1 (specs/grow/18): one-tap written texting consent by email. The tap is recorded as written consent with proof
| (wording naming the brokerage, time, IP, browser, the email it went to), so automated texts become allowed.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->tenant->forceFill(['name' => 'Acme Realty'])->save();
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $this->actingAs($this->agent)->post('/contacts', ['first_name' => 'Nia', 'last_name' => 'Lopez', 'email' => 'nia@example.test', 'phone' => '512-555-0100', 'source' => 'open_house']);
    $this->nia = Contact::query()->where('primary_email', 'nia@example.test')->sole();
});

function consentLink(): string
{
    $link = null;
    Notification::assertSentOnDemand(ConsentAskMail::class, function (ConsentAskMail $m) use (&$link): bool {
        $link = $m->link;

        return true;
    });

    return (string) $link;
}

it('emails a one-tap request and records a yes as written consent with proof', function (): void {
    $this->actingAs($this->agent)->get('/contacts/'.$this->nia->ulid)->assertInertia(fn (Assert $p) => $p->where('contact.consent.ask_blocker', null));
    $this->actingAs($this->agent)->post('/contacts/'.$this->nia->ulid.'/texting-consent')->assertSessionHasNoErrors();
    $link = consentLink();
    expect($link)->toStartWith('http://acme.rightally.app/consent/');

    auth()->guard('web')->logout();
    $this->get($link)->assertOk()->assertSee('May Acme Realty text you?')->assertSee('ending 0100')->assertSee('from Acme Realty and its agents')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->withHeader('User-Agent', 'Phone browser')->post($link, ['answer' => 'yes'])->assertOk()->assertSee('may now text you');

    $nia = $this->nia->fresh();
    expect($nia->sms_opt_in)->toBeTrue()->and($nia->sms_consent_method)->toBe('web_form')->and(app(Consent::class)->canAutoText($nia))->toBeTrue();
    $event = ConsentEvent::query()->where('contact_id', $nia->id)->where('channel', 'sms')->latest('id')->sole();
    expect($event->source)->toBe('email_link')->and($event->consent_text)->toContain('Acme Realty')->and($event->user_agent)->toBe('Phone browser')
        ->and(json_decode((string) $event->evidence, true)['sent_to'])->toBe('nia@example.test');

    // Answering again changes nothing; the contact page now says they agreed.
    $this->post($link, ['answer' => 'no'])->assertOk()->assertSee('may now text you');
    $this->actingAs($this->agent)->get('/contacts/'.$this->nia->ulid)->assertInertia(fn (Assert $p) => $p->where('contact.consent.status', 'yes')
        ->where('contact.consent.ask_blocker', 'They already gave written consent.'));
});

it('records a no without changing anything, and expires links after 30 days', function (): void {
    $this->actingAs($this->agent)->post('/contacts/'.$this->nia->ulid.'/texting-consent');
    $link = consentLink();
    $this->post($link, ['answer' => 'no'])->assertOk()->assertSee('will not text you');
    expect($this->nia->fresh()->sms_opt_in)->toBeFalse()
        ->and(DB::connection(Connections::cell())->table('crm_consent_requests')->value('answer'))->toBe('no');

    $this->travel(31)->days();
    $this->actingAs($this->agent)->post('/contacts/'.$this->nia->ulid.'/texting-consent')->assertSessionHasNoErrors(); // may ask again after 30 days
    $this->travel(31)->days();
    $this->get('/consent/'.str_repeat('a', 48))->assertStatus(410)->assertSee('expired');
});

it('only asks people who can be asked, and limits how often', function (): void {
    $this->actingAs($this->agent)->post('/contacts/'.$this->nia->ulid.'/texting-consent')->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->post('/contacts/'.$this->nia->ulid.'/texting-consent')->assertSessionHasErrors('consent'); // asked already

    $this->actingAs($this->agent)->post('/contacts', ['first_name' => 'Pat', 'email' => 'pat@example.test', 'source' => 'open_house']);
    $pat = Contact::query()->where('primary_email', 'pat@example.test')->sole();
    $this->actingAs($this->agent)->post('/contacts/'.$pat->ulid.'/texting-consent')->assertSessionHasErrors('consent'); // no phone

    $this->actingAs($this->agent)->post('/contacts', ['first_name' => 'Sam', 'email' => 'sam@example.test', 'phone' => '512-555-0101', 'source' => 'open_house']);
    $sam = Contact::query()->where('primary_email', 'sam@example.test')->sole();
    app(Consent::class)->setEmail($sam, false, 'agent');
    $this->actingAs($this->agent)->post('/contacts/'.$sam->ulid.'/texting-consent')->assertSessionHasErrors('consent'); // unsubscribed

    $other = User::factory()->create();
    $this->actingAs($other)->post('/contacts', ['first_name' => 'Kim', 'email' => 'kim@example.test', 'phone' => '512-555-0102', 'source' => 'open_house']);
    $this->actingAs($this->agent)->get('/contacts/texting-consent')->assertInertia(fn (Assert $p) => $p->component('crm/texting-consent')->where('eligible', 0));
    $this->actingAs($other)->post('/contacts/texting-consent')->assertSessionHasNoErrors();
    Notification::assertSentOnDemandTimes(ConsentAskMail::class, 2);
});
