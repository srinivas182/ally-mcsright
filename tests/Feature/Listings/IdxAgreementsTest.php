<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Enums\SslStatus;
use Modules\Core\Tenancy\Enums\VerificationStatus;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\TenantContext;
use Modules\Forms\Events\PacketSealed;
use Modules\Forms\SignatureRequested;
use Modules\Listings\Idx\Agreements;
use Modules\Listings\Idx\Mail\IdxAgreementMail;
use Modules\Listings\Idx\Mail\IdxUpdateMail;
use Modules\Listings\Idx\Subscriptions;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Models\RulesProfile;

/*
| IDX6: each board's IDX agreement is filled, signed in order (brokerage, then managing broker), kept, checked against
| the brokerage's website, and emailed to the board's addresses with CC and BCC. Live websites are checked nightly.
*/

beforeEach(function (): void {
    Mail::fake();
    Notification::fake();
    Storage::fake('local');
    config(['core.hq_host' => 'app.rightally.io', 'core.watchtower.emails' => 'alerts@rightally.test']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->acme = Tenant::factory()->withPlatformDomain()->create(['slug' => 'acme', 'name' => 'Acme Realty']);
    TenantDomain::query()->create(['tenant_id' => $this->acme->id, 'host' => 'acme.rightally.site', 'kind' => DomainKind::Platform, 'is_primary' => false,
        'verification_status' => VerificationStatus::Verified, 'ssl_status' => SslStatus::Active, 'purpose' => 'website']);
    $this->owner = app(TenantContext::class)->runAs($this->acme, fn () => User::factory()->owner()->create(['email' => 'owner@acme.test', 'first_name' => 'Pat', 'last_name' => 'Owner']));
    $this->db = DB::connection(Connections::control());
    $this->db->table('idx_boards')->where('code', 'HAR')->update(['visibility' => 'public', 'monthly_fee_cents' => 5_000, 'send_to' => 'onboarding@repliers.test',
        'send_cc' => 'idx@har.test', 'send_bcc' => 'hq@rightally.test', 'agreement_text' => '{brokerage} asks {board} for an IDX feed for {website}.']);
    $this->har = (string) $this->db->table('idx_boards')->where('code', 'HAR')->value('ulid');
    $this->subs = app(Subscriptions::class);
});

function submitAcme(object $t): string
{
    $ulid = $t->subs->add($t->acme->id, $t->har, $t->owner);
    $t->subs->submit($t->acme->id, $ulid, ['member_id' => 'M123', 'license_number' => 'TX-1', 'managing_broker_name' => 'Dana Brooks', 'managing_broker_email' => 'dana@acme.test'], $t->owner);

    return $ulid;
}

it('fills the board\'s agreement and sends it for signing: the brokerage first, then the managing broker', function (): void {
    $ulid = submitAcme($this);
    $sub = $this->db->table('idx_subscriptions')->where('ulid', $ulid)->first();
    expect($sub->status)->toBe('awaiting_signature')->and($sub->packet_ulid)->not->toBeNull()->and($sub->website)->toBe('https://acme.rightally.site');

    $signers = app(TenantContext::class)->runAs($this->acme, fn () => DB::connection(Connections::cell())->table('form_signers')
        ->join('form_packets', 'form_packets.id', '=', 'form_signers.packet_id')->where('form_packets.ulid', $sub->packet_ulid)
        ->orderBy('sign_order')->get(['form_signers.role', 'form_signers.email', 'form_signers.sign_order', 'form_signers.status'])->all());
    expect($signers)->toHaveCount(2)
        ->and($signers[0])->role->toBe('brokerage')->email->toBe('owner@acme.test')->status->toBe('sent')
        ->and($signers[1])->role->toBe('managing_broker')->email->toBe('dana@acme.test')->status->toBe('waiting');
    Notification::assertSentOnDemand(SignatureRequested::class, fn ($n, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'owner@acme.test' && $n->agent === 'RightAlly');
    expect($this->subs->forTenant($this->acme->id)[0]['agreement'])->toBe('Out for signature');
});

it('keeps the signed copy, checks the website, and emails it to the board with CC and BCC', function (): void {
    Http::fake(['acme.rightally.site*' => Http::response('<html><body>Homes for sale</body></html>')]);
    $ulid = submitAcme($this);
    $sub = $this->db->table('idx_subscriptions')->where('ulid', $ulid)->first();
    Storage::disk('local')->put('forms/sealed.pdf', '%PDF-1.4 sealed');

    PacketSealed::dispatch($this->acme->id, (string) $sub->packet_ulid, 'forms/sealed.pdf');

    $sub = $this->db->table('idx_subscriptions')->where('ulid', $ulid)->first();
    expect($sub->status)->toBe('sent')->and($sub->sent_to_mls_at)->not->toBeNull()
        ->and(Storage::disk('local')->get((string) $sub->signed_path))->toBe('%PDF-1.4 sealed');
    Mail::assertQueued(IdxAgreementMail::class, fn (IdxAgreementMail $m): bool => $m->hasTo('onboarding@repliers.test') && $m->hasCc('idx@har.test')
        && $m->hasBcc('hq@rightally.test') && $m->facts['MLS member ID'] === 'M123' && $m->facts['Brokerage'] === 'Acme Realty');

    $this->actingAs($this->owner)->get('http://acme.rightally.app/settings/mls/boards/'.$ulid.'/agreement.pdf')->assertOk();
    $other = Tenant::factory()->withPlatformDomain()->create(['slug' => 'other']);
    $stranger = app(TenantContext::class)->runAs($other, fn () => User::factory()->owner()->create());
    $this->actingAs($stranger)->get('http://other.rightally.app/settings/mls/boards/'.$ulid.'/agreement.pdf')->assertNotFound();
});

it('holds a signed agreement when the website does not open, and HQ can send it anyway', function (): void {
    Http::fake(['acme.rightally.site*' => Http::response('<form><input type="password" name="p"></form>')]);
    $ulid = submitAcme($this);
    $sub = $this->db->table('idx_subscriptions')->where('ulid', $ulid)->first();
    Storage::disk('local')->put('forms/sealed.pdf', '%PDF-1.4 sealed');
    PacketSealed::dispatch($this->acme->id, (string) $sub->packet_ulid, 'forms/sealed.pdf');

    $sub = $this->db->table('idx_subscriptions')->where('ulid', $ulid)->first();
    expect($sub->status)->toBe('needs_fixing')->and($sub->client_note)->toContain('asks for a password');
    Mail::assertNotQueued(IdxAgreementMail::class);

    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])->post('http://app.rightally.io/hq/idx/subscriptions/'.$ulid.'/send')->assertRedirect();
    expect($this->db->table('idx_subscriptions')->where('ulid', $ulid)->value('status'))->toBe('sent');
    Mail::assertQueued(IdxAgreementMail::class);
});

it('checks live websites every night for the MLS notice, and tells HQ', function (): void {
    $rules = RulesProfile::query()->create(['name' => 'HAR', 'program' => 'idx', 'attribution_template' => 'Listing courtesy of {ListOfficeName}',
        'disclaimer' => 'Information is provided by HAR and deemed reliable but not guaranteed. Updated {LastUpdated}.', 'sold_allowed' => false, 'sold_delay_days' => 0,
        'freshness_minutes' => 60, 'index_off_market' => false]);
    $feed = MlsSource::query()->create(['key' => 'har', 'name' => 'HAR', 'states' => ['TX'], 'provider' => 'repliers', 'base_url' => 'https://api.repliers.io', 'status' => 'active',
        'rules_profile_id' => $rules->id]);
    $ulid = submitAcme($this);
    $this->subs->move($ulid, 'sent', null, $this->staff);
    $this->subs->goLive($ulid, $feed->id, null, $this->staff);

    Http::fakeSequence('acme.rightally.site/homes')
        ->push('<footer><p>Information is provided by HAR and deemed reliable but not guaranteed. Updated 11/21</p></footer>')
        ->push('<footer>nothing here</footer>');
    expect(app(Agreements::class)->complianceWatch())->toBe(['checked' => 1, 'problems' => 0]);

    expect(app(Agreements::class)->complianceWatch())->toBe(['checked' => 1, 'problems' => 1])
        ->and($this->db->table('idx_subscriptions')->where('ulid', $ulid)->value('compliance_note'))->toBe('The MLS notice is missing from the home search page.');
    Mail::assertQueued(IdxUpdateMail::class, fn (IdxUpdateMail $m): bool => $m->hasTo('alerts@rightally.test') && str_contains($m->heading, 'MLS compliance check'));
});

it('only accepts email addresses for where the agreement goes', function (): void {
    $board = (array) $this->db->table('idx_boards')->where('code', 'HAR')->first();
    $this->actingAs($this->staff)->put('http://app.rightally.io/hq/idx/boards/'.$this->har, ['code' => 'HAR', 'name' => $board['name'], 'state' => 'TX', 'other_states' => [],
        'region' => null, 'visibility' => 'public', 'approval_days_min' => 3, 'approval_days_max' => 7, 'main_connector' => 'repliers', 'main_cost_cents' => null,
        'backup_connector' => null, 'backup_cost_cents' => null, 'connector_code' => null, 'monthly_fee_cents' => 5000, 'setup_fee_cents' => 0, 'rules_profile_id' => null,
        'checked' => true, 'notes' => null, 'send_to' => 'not an email', 'send_cc' => '', 'send_bcc' => ''])->assertSessionHasErrors('send_to');
});
