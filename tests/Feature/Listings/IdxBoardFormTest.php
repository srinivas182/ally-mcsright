<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Enums\SslStatus;
use Modules\Core\Tenancy\Enums\VerificationStatus;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Idx\BoardForm;
use Modules\Listings\Idx\Coverage;
use Modules\Listings\Idx\Subscriptions;

/*
| IDX12: the board's own agreement form, filled in. Its fillable fields are found and matched to the brokerage's details,
| HQ checks the match, and the brokerage reviews the form filled in, completes the rest, previews it and signs.
*/

beforeEach(function (): void {
    Mail::fake();
    Notification::fake();
    Storage::fake('local');
    Http::fake(['acme.rightally.site*' => Http::response('<html><body>Homes for sale</body></html>')]);
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->acme = Tenant::factory()->withPlatformDomain()->create(['slug' => 'acme', 'name' => 'Acme Realty']);
    TenantDomain::query()->create(['tenant_id' => $this->acme->id, 'host' => 'acme.rightally.site', 'kind' => DomainKind::Platform, 'is_primary' => false,
        'verification_status' => VerificationStatus::Verified, 'ssl_status' => SslStatus::Active, 'purpose' => 'website']);
    $this->owner = app(TenantContext::class)->runAs($this->acme, fn () => User::factory()->owner()->create(['email' => 'owner@acme.test', 'first_name' => 'Pat', 'last_name' => 'Owner']));
    $this->db = DB::connection(Connections::control());
    $this->db->table('idx_boards')->where('code', 'HAR')->update(['visibility' => 'public', 'monthly_fee_cents' => 5_000, 'send_to' => 'onboarding@repliers.test']);
    $this->har = (string) $this->db->table('idx_boards')->where('code', 'HAR')->value('ulid');
    app(Coverage::class)->saveAgreementPdf($this->har, (string) file_get_contents(base_path('tests/Fixtures/forms/idx-agreement-fillable.pdf')), $this->staff);
});

function ownFormFields(object $t): array
{
    return collect(BoardForm::fields($t->db->table('idx_boards')->where('code', 'HAR')->first()))->keyBy('key')->all();
}

it('finds the fields in the board\'s own form and matches them to what RightAlly knows', function (): void {
    $f = ownFormFields($this);
    expect($f['firm_name']['source'])->toBe('brokerage_name')->and($f['office_id']['source'])->toBe('office_id')
        ->and($f['participant_mls_id']['source'])->toBe('member_id')->and($f['license_number']['source'])->toBe('license_number')
        ->and($f['designated_broker_name']['source'])->toBe('managing_broker_name')->and($f['broker_email']['source'])->toBe('managing_broker_email')
        ->and($f['website_url']['source'])->toBe('website')->and($f['office_phone']['source'])->toBe('fill')->and($f['office_phone']['required'])->toBeTrue()
        ->and($f['broker_signature']['type'])->toBe('signature')->and($f['broker_signature']['role'])->toBe('managing_broker');

    // Not in use until HQ has checked the match.
    expect(BoardForm::inUse($this->db->table('idx_boards')->where('code', 'HAR')->first()))->toBeFalse();
    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/idx')->assertInertia(fn (Assert $page) => $page
        ->where('boards', fn ($boards) => count(collect($boards)->firstWhere('code', 'HAR')['form_fields']) === 11));
});

it('lets HQ check the match, then the brokerage reviews the form filled in, completes it, previews it and signs', function (): void {
    $rows = array_map(fn (array $f): array => ['key' => $f['key'], 'source' => $f['source'], 'role' => $f['key'] === 'date_signed' ? 'managing_broker' : $f['role'],
        'label' => $f['key'] === 'vendor_contact' ? 'Technology contact' : $f['label'], 'required' => $f['key'] !== 'vendor_contact'], array_values(ownFormFields($this)));
    $rows = array_map(fn (array $r): array => $r['key'] === 'vendor_contact' ? ['source' => 'vendor'] + $r : $r, $rows);
    $this->actingAs($this->staff)->put('http://app.rightally.io/hq/idx/boards/'.$this->har.'/fields', ['fields' => $rows])->assertSessionHasNoErrors();
    expect(ownFormFields($this)['vendor_contact']['label'])->toBe('Technology contact');

    $subs = app(Subscriptions::class);
    $ulid = $subs->add($this->acme->id, $this->har, $this->owner);
    $subs->submit($this->acme->id, $ulid, ['member_id' => 'M123', 'office_id' => 'ACME1', 'license_number' => 'TX-0654321', 'managing_broker_name' => 'Dana Brooks',
        'managing_broker_email' => 'dana@acme.test'], $this->owner);
    expect($this->db->table('idx_subscriptions')->where('ulid', $ulid)->value('status'))->toBe('review');
    expect($this->db->table('idx_subscriptions')->where('ulid', $ulid)->value('packet_ulid'))->toBeNull(); // nothing to sign yet

    $this->actingAs($this->owner)->get('http://acme.rightally.app/settings/mls')->assertInertia(fn (Assert $page) => $page
        ->where('boards.0.form', fn ($form) => collect($form)->firstWhere('key', 'firm_name')['value'] === 'Acme Realty'
            && collect($form)->firstWhere('key', 'participant_mls_id')['value'] === 'M123' && collect($form)->firstWhere('key', 'office_phone')['value'] === ''
            && collect($form)->firstWhere('key', 'office_phone')['filled_by_us'] === false));

    $confirmed = ['auth.password_confirmed_at' => time()];
    $values = ['firm_name' => 'Acme Realty LLC', 'office_id' => 'ACME1', 'participant_mls_id' => 'M123', 'license_number' => 'TX-0654321', 'designated_broker_name' => 'Dana Brooks',
        'broker_email' => 'dana@acme.test', 'website_url' => 'https://acme.rightally.site', 'office_phone' => '', 'vendor_contact' => 'RightAlly'];
    $this->actingAs($this->owner)->withSession($confirmed)->put('http://acme.rightally.app/settings/mls/boards/'.$ulid.'/review', ['values' => $values, 'send' => true])
        ->assertSessionHasErrors('values');
    $this->actingAs($this->owner)->withSession($confirmed)->put('http://acme.rightally.app/settings/mls/boards/'.$ulid.'/review', ['values' => array_merge($values, ['office_phone' => '(713) 555-0100']), 'send' => false])
        ->assertSessionHasNoErrors();
    $preview = $this->actingAs($this->owner)->get('http://acme.rightally.app/settings/mls/boards/'.$ulid.'/preview.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with((string) $preview->getContent(), '%PDF-'))->toBeTrue();

    $this->actingAs($this->owner)->withSession($confirmed)->put('http://acme.rightally.app/settings/mls/boards/'.$ulid.'/review', ['values' => array_merge($values, ['office_phone' => '(713) 555-0100']), 'send' => true])
        ->assertSessionHasNoErrors();
    $sub = $this->db->table('idx_subscriptions')->where('ulid', $ulid)->first();
    expect($sub->status)->toBe('awaiting_signature')->and($sub->packet_ulid)->not->toBeNull();

    // The packet is the board's own form, with the reviewed values and the board's signature line for the managing broker.
    $cell = DB::connection(Connections::cell());
    $packet = $cell->table('form_packets')->where('ulid', $sub->packet_ulid)->first();
    $form = $cell->table('form_packet_forms')->where('packet_id', $packet->id)->first();
    $template = $cell->table('form_templates')->where('id', $form->template_id)->first();
    $fields = collect(json_decode((string) $template->fields, true))->keyBy('key');
    expect(json_decode((string) $form->values, true)['firm_name'])->toBe('Acme Realty LLC')
        ->and(json_decode((string) $form->values, true)['office_phone'])->toBe('(713) 555-0100')
        ->and($fields['broker_signature']['role'])->toBe('managing_broker')->and($fields->has('sign_brokerage'))->toBeTrue(); // the owner signs on our page

    // Another brokerage cannot preview it.
    $other = Tenant::factory()->withPlatformDomain()->create(['slug' => 'other']);
    $stranger = app(TenantContext::class)->runAs($other, fn () => User::factory()->owner()->create());
    $this->actingAs($stranger)->get('http://other.rightally.app/settings/mls/boards/'.$ulid.'/preview.pdf')->assertNotFound();
});

it('finds the fields again when the board\'s form changes, so HQ checks the new version', function (): void {
    $rows = array_map(fn (array $f): array => ['key' => $f['key'], 'source' => $f['source'] ?? 'skip', 'role' => $f['role']], array_values(ownFormFields($this)));
    $this->actingAs($this->staff)->put('http://app.rightally.io/hq/idx/boards/'.$this->har.'/fields', ['fields' => $rows])->assertSessionHasNoErrors();
    expect(BoardForm::inUse($this->db->table('idx_boards')->where('code', 'HAR')->first()))->toBeTrue();

    app(Coverage::class)->saveAgreementPdf($this->har, (string) file_get_contents(base_path('tests/Fixtures/forms/idx-agreement-fillable.pdf')), $this->staff);
    expect(BoardForm::inUse($this->db->table('idx_boards')->where('code', 'HAR')->first()))->toBeFalse(); // waiting for HQ again

    // A signature line with no signer cannot be saved.
    $bad = array_map(fn (array $r): array => $r['key'] === 'broker_signature' ? ['role' => ''] + $r : $r, $rows);
    $this->actingAs($this->staff)->put('http://app.rightally.io/hq/idx/boards/'.$this->har.'/fields', ['fields' => $bad])->assertSessionHasErrors('fields');
});
