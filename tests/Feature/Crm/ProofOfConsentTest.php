<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Consent;
use Modules\Crm\Leads\LeadIntake;
use Modules\Crm\Leads\LeadSources;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;

/* Sprint PC1: proof of consent that travels with every contact — where it came from, the exact wording, the page,
   and an evidence fingerprint; automated texts need it; a report for any contact; imports arrive "unknown". */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create();
});

it('keeps the proof when someone agrees on a web form: wording, page, IP, device and a fingerprint', function (): void {
    $form = app(LeadSources::class)->create(['name' => 'Home page form', 'kind' => 'form', 'source' => 'website'], $this->owner)['source'];
    $request = Request::create('https://acme.rightally.site/contact', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Test']);
    app(LeadIntake::class)->receive($form, ['name' => 'Jane Doe', 'phone' => '5125550199', 'sms_consent' => true, 'consent_text' => Consent::DEFAULT_SMS_WORDING,
        'landing_url' => 'https://acme.rightally.site/homes/78704'], $request);

    $e = ConsentEvent::query()->sole();
    expect($e->only(['channel', 'action', 'method', 'source', 'source_ref', 'ip']))->toBe(['channel' => 'sms', 'action' => 'opt_in', 'method' => 'web_form', 'source' => 'web_form',
        'source_ref' => 'https://acme.rightally.site/homes/78704', 'ip' => '203.0.113.9'])
        ->and($e->evidence_sha256)->toBe(hash('sha256', (string) $e->evidence))
        ->and(json_decode((string) $e->evidence, true))->toMatchArray(['wording' => Consent::DEFAULT_SMS_WORDING, 'source' => 'web_form', 'form' => 'Home page form',
            'page' => 'https://acme.rightally.site/homes/78704', 'checked' => true, 'user_agent' => 'Mozilla/5.0 Test']);
    $jane = Contact::query()->sole();
    expect(app(Consent::class)->status($jane))->toBe('yes')->and(app(Consent::class)->canAutoText($jane))->toBeTrue();
});

it('marks an API lead as coming from a connected app', function (): void {
    $api = app(LeadSources::class)->create(['name' => 'Zapier', 'kind' => 'api', 'source' => 'facebook'], $this->owner)['source'];
    app(LeadIntake::class)->receive($api, ['name' => 'Jo Lee', 'phone' => '5125550111', 'sms_consent' => true, 'consent_text' => 'I agree to texts.']);
    expect(ConsentEvent::query()->sole()->only(['source', 'source_ref']))->toBe(['source' => 'connected_app', 'source_ref' => 'Zapier']);
});

it('never texts automatically without proof on file, and imported contacts arrive "unknown"', function (): void {
    $c = app(ManageContacts::class)->create(['first_name' => 'Ava', 'phone' => '6015550123', 'source' => 'import'], $this->agent);
    expect(app(Consent::class)->status($c))->toBe('unknown');
    $c->forceFill(['sms_opt_in' => true, 'sms_consent_method' => 'web_form', 'sms_opt_in_at' => now()])->save(); // set without any record
    expect(app(Consent::class)->canAutoText($c))->toBeFalse()->and(app(Consent::class)->status($c))->toBe('verbal');
    app(Consent::class)->optOutSms($c);
    expect(app(Consent::class)->status($c->fresh()))->toBe('no');
});

it('stops texts, emails and calls when a connected app says so, recorded with the app', function (): void {
    $c = app(ManageContacts::class)->create(['first_name' => 'Bo', 'phone' => '6015550124', 'email' => 'bo@x.test'], $this->agent);
    app(Consent::class)->optInSms($c, 'signed', Consent::DEFAULT_SMS_WORDING, $this->agent);
    app(Consent::class)->fromConnectedApp($c->fresh(), 'Lofty', true, true, true);
    $c->refresh();
    expect(app(Consent::class)->canText($c))->toBeFalse()->and($c->do_not_call)->toBeTrue()->and($c->email_opt_in)->toBeFalse()
        ->and(ConsentEvent::query()->where('source', 'connected_app')->where('source_ref', 'Lofty')->count())->toBe(3);
    app(Consent::class)->fromConnectedApp($c, 'Lofty', true, true, true); // already stopped: nothing new
    expect(ConsentEvent::query()->where('source', 'connected_app')->count())->toBe(3);
});

it('gives brokers a consent report for any contact, printable or as CSV', function (): void {
    $c = app(ManageContacts::class)->create(['first_name' => 'Cy', 'last_name' => 'Doe', 'phone' => '6015550125'], $this->agent);
    app(Consent::class)->optInSms($c, 'signed', Consent::DEFAULT_SMS_WORDING, $this->agent);
    $this->travel(1)->day();
    app(Consent::class)->optOutSms($c->fresh(), 'sms_keyword');

    $this->actingAs($this->owner)->get('/contacts/'.$c->ulid.'/consent-report')->assertOk()->assertHeader('X-Robots-Tag', 'noindex')
        ->assertSee('Consent report')->assertSee('No (opted out)')->assertSee(Consent::DEFAULT_SMS_WORDING)->assertSee('Evidence SHA-256')->assertSee('sms keyword');
    $csv = (string) $this->actingAs($this->owner)->get('/contacts/'.$c->ulid.'/consent-report?format=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8')->getContent();
    expect(substr_count(trim($csv), "\n"))->toBe(2)->and($csv)->toContain('opt_in,signed,agent')->toContain('opt_out,sms_keyword,sms_keyword');
    $this->actingAs($this->agent)->get('/contacts/'.$c->ulid.'/consent-report')->assertForbidden();
    $this->actingAs($this->agent)->get('/contacts/'.$c->ulid)->assertInertia(fn ($p) => $p->where('contact.consent.status', 'no')->where('contact.consent.report', null));
});
