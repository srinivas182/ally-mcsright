<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Enums\SslStatus;
use Modules\Core\Tenancy\Enums\VerificationStatus;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\TenantContext;
use Modules\Forms\Events\PacketSealed;
use Modules\Forms\SignatureRequested;
use Modules\Listings\Idx\AgreementCheck;
use Modules\Listings\Idx\Agreements;
use Modules\Listings\Idx\AgreementWatch;
use Modules\Listings\Idx\Mail\IdxAgreementMail;
use Modules\Listings\Idx\Mail\IdxUpdateMail;
use Modules\Listings\Idx\Subscriptions;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Models\RulesProfile;
use Tests\Fixtures\FakeAiProvider;

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
    giveLicence($this->acme); // LV2: boards need an active licence in their state
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

function signAll(object $t, string $ulid): void
{
    $packet = (string) $t->db->table('idx_subscriptions')->where('ulid', $ulid)->value('packet_ulid');
    $cell = DB::connection(Connections::cell());
    $cell->table('form_signers')->where('packet_id', $cell->table('form_packets')->where('ulid', $packet)->value('id'))->update(['status' => 'signed']);
    Storage::disk('local')->put('forms/sealed.pdf', '%PDF-1.4 sealed');
    PacketSealed::dispatch($t->acme->id, $packet, 'forms/sealed.pdf');
}

it('keeps the signed copy and holds it for RightAlly\'s check; HQ sends it to the board with CC and BCC (IDX11)', function (): void {
    Http::fake(['acme.rightally.site*' => Http::response('<html><body>Homes for sale</body></html>')]);
    $ulid = submitAcme($this);
    signAll($this, $ulid);

    $sub = $this->db->table('idx_subscriptions')->where('ulid', $ulid)->first();
    expect($sub->status)->toBe('hq_check')->and($sub->sent_to_mls_at)->toBeNull()->and($sub->check_result)->toBe('clear')->and($sub->check_source)->toBe('rules')
        ->and((int) $sub->agreement_version)->toBe(1)->and(Storage::disk('local')->get((string) $sub->signed_path))->toBe('%PDF-1.4 sealed');
    Mail::assertNotQueued(IdxAgreementMail::class); // nothing goes to the MLS before HQ looks

    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/idx')->assertInertia(fn (Assert $page) => $page
        ->where('queue', fn ($queue) => collect($queue)->firstWhere('ulid', $ulid)['check']['result'] === 'clear'));
    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])->post('http://app.rightally.io/hq/idx/subscriptions/'.$ulid.'/send')->assertRedirect();
    expect($this->db->table('idx_subscriptions')->where('ulid', $ulid)->value('status'))->toBe('sent');
    Mail::assertQueued(IdxAgreementMail::class, fn (IdxAgreementMail $m): bool => $m->hasTo('onboarding@repliers.test') && $m->hasCc('idx@har.test')
        && $m->hasBcc('hq@rightally.test') && $m->facts['MLS member ID'] === 'M123' && $m->facts['Brokerage'] === 'Acme Realty');

    $this->actingAs($this->owner)->get('http://acme.rightally.app/settings/mls/boards/'.$ulid.'/agreement.pdf')->assertOk();
    $other = Tenant::factory()->withPlatformDomain()->create(['slug' => 'other']);
    $stranger = app(TenantContext::class)->runAs($other, fn () => User::factory()->owner()->create());
    $this->actingAs($stranger)->get('http://other.rightally.app/settings/mls/boards/'.$ulid.'/agreement.pdf')->assertNotFound();
});

it('lists what to fix, writes the note, and lets HQ send it back with its own words or send it anyway (IDX11)', function (): void {
    Http::fake(['acme.rightally.site*' => Http::response('<form><input type="password" name="p"></form>')]);
    $ulid = submitAcme($this);
    $this->db->table('idx_subscriptions')->where('ulid', $ulid)->update(['license_number' => '']); // changed after it was sent for signing
    signAll($this, $ulid);

    $sub = $this->db->table('idx_subscriptions')->where('ulid', $ulid)->first();
    $issues = array_column((array) json_decode((string) $sub->check_issues, true), 'text');
    expect($sub->check_result)->toBe('issues')->and($issues)->toContain('The real estate license number is missing.')
        ->and(implode(' ', $issues))->toContain('asks for a password')->and((string) $sub->check_note)->toContain('please fix')->toContain('license number');
    Mail::assertNotQueued(IdxAgreementMail::class);

    // Send it back with the note plus HQ's own words.
    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/idx/subscriptions/'.$ulid.'/move', ['to' => 'needs_fixing', 'note' => $sub->check_note."\nHQ: call us if you need help."])
        ->assertSessionHasNoErrors();
    $sub = $this->db->table('idx_subscriptions')->where('ulid', $ulid)->first();
    expect($sub->status)->toBe('needs_fixing')->and($sub->client_note)->toContain('license number')->toContain('call us if you need help');
    Mail::assertQueued(IdxUpdateMail::class, fn (IdxUpdateMail $m): bool => $m->hasTo('owner@acme.test') && str_contains(implode(' ', $m->lines), 'call us if you need help'));

    // HQ can still send it as it is.
    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])->post('http://app.rightally.io/hq/idx/subscriptions/'.$ulid.'/send')->assertRedirect();
    expect($this->db->table('idx_subscriptions')->where('ulid', $ulid)->value('status'))->toBe('sent');
});

it('lets AI add what the rules missed and write the note; the rules still stand when AI is down (IDX11)', function (): void {
    Http::fake(['acme.rightally.site*' => Http::response('<html><body>Homes for sale</body></html>')]);
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => AgreementCheck::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.idx_agreement_check']);
    $ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $ai);
    $ai->respond('{"clear": false, "issues": ["HAR asks for your office ID; add it."], "note": "Thank you for signing. Please add your office ID in Settings › MLS and sign again."}');
    $ulid = submitAcme($this);
    signAll($this, $ulid);

    $sub = $this->db->table('idx_subscriptions')->where('ulid', $ulid)->first();
    expect($sub->check_result)->toBe('issues')->and($sub->check_source)->toBe('ai')->and((string) $sub->check_issues)->toContain('office ID')
        ->and((string) $sub->check_note)->toContain('Please add your office ID');
    expect($ai->requests)->toHaveCount(1); // the details went to the model once, as data

    // AI down: the check still runs on the rules.
    $ai->fail(new RuntimeException('provider down'));
    $ai->fail(new RuntimeException('provider down'));
    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/idx/subscriptions/'.$ulid.'/recheck')->assertRedirect();
    $sub = $this->db->table('idx_subscriptions')->where('ulid', $ulid)->first();
    expect($sub->check_result)->toBe('clear')->and($sub->check_source)->toBe('rules');
});

it('watches each board\'s own form, keeps a new version aside for HQ, and asks to confirm forms it cannot download (IDX11)', function (): void {
    $this->db->table('idx_boards')->where('code', 'HAR')->update(['agreement_url' => 'https://har.test/idx-agreement.pdf']);
    Http::fakeSequence('har.test/*')->push('%PDF-1.4 version one')->push('%PDF-1.4 version one')->push('%PDF-1.4 version two')->push('<html>Sign in</html>');

    expect(app(AgreementWatch::class)->run()['changed'])->toBe(0); // first download becomes the form
    $board = $this->db->table('idx_boards')->where('code', 'HAR')->first();
    expect(Storage::disk('local')->get((string) $board->agreement_path))->toBe('%PDF-1.4 version one');
    expect(app(AgreementWatch::class)->run()['changed'])->toBe(0);
    expect(app(AgreementWatch::class)->run()['changed'])->toBe(1);
    Mail::assertQueued(IdxUpdateMail::class, fn (IdxUpdateMail $m): bool => $m->hasTo('alerts@rightally.test') && str_contains(implode(' ', $m->lines), 'published a new version'));

    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/idx/boards/'.$this->har.'/form', ['action' => 'use_new'])->assertSessionHasNoErrors();
    $board = $this->db->table('idx_boards')->where('code', 'HAR')->first();
    expect(Storage::disk('local')->get((string) $board->agreement_path))->toBe('%PDF-1.4 version two')->and((int) $board->agreement_version)->toBe(2)
        ->and($board->agreement_pending_path)->toBeNull();

    app(AgreementWatch::class)->run(); // now a sign-in page
    $board = $this->db->table('idx_boards')->where('code', 'HAR')->first();
    expect((string) $board->agreement_watch_note)->toContain('sign-in');
    $this->travel(91)->days();
    expect(AgreementWatch::due($this->db->table('idx_boards')->where('code', 'HAR')->first()))->toBeTrue();
    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/idx/boards/'.$this->har.'/form', ['action' => 'confirm'])->assertSessionHasNoErrors();
    expect(AgreementWatch::due($this->db->table('idx_boards')->where('code', 'HAR')->first()))->toBeFalse();
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
