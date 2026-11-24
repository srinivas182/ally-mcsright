<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Enums\DomainKind;
use Modules\Core\Tenancy\Enums\SslStatus;
use Modules\Core\Tenancy\Enums\VerificationStatus;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\TenantContext;
use Modules\Forms\SignatureRequested;
use Modules\Listings\Idx\Mail\IdxUpdateMail;
use Modules\Listings\Idx\Subscriptions;

/*
| IDX13: the brokerage sees who the MLS agreement waits on, signs it from its back office or gets the email again;
| HQ can resend it (or send it, when the board's address was added later) and is told when the MLS is slow.
*/

beforeEach(function (): void {
    Mail::fake();
    Notification::fake();
    Storage::fake('local');
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create(['email' => 'staff@rightally.test']));
    $this->acme = Tenant::factory()->withPlatformDomain()->create(['slug' => 'acme', 'name' => 'Acme Realty']);
    giveLicence($this->acme);
    TenantDomain::query()->create(['tenant_id' => $this->acme->id, 'host' => 'acme.rightally.site', 'kind' => DomainKind::Platform, 'is_primary' => false,
        'verification_status' => VerificationStatus::Verified, 'ssl_status' => SslStatus::Active, 'purpose' => 'website']);
    $this->owner = app(TenantContext::class)->runAs($this->acme, fn () => User::factory()->owner()->create(['email' => 'owner@acme.test', 'first_name' => 'Pat', 'last_name' => 'Owner']));
    $this->db = DB::connection(Connections::control());
    $this->db->table('idx_boards')->where('code', 'HAR')->update(['visibility' => 'public', 'monthly_fee_cents' => 5_000, 'send_to' => 'onboarding@repliers.test',
        'agreement_text' => '{brokerage} asks {board} for an IDX feed for {website}.', 'approval_days_max' => 14]);
    $this->har = (string) $this->db->table('idx_boards')->where('code', 'HAR')->value('ulid');
    $this->subs = app(Subscriptions::class);
    $this->ulid = $this->subs->add($this->acme->id, $this->har, $this->owner);
});

function idx13Submit(object $t): void
{
    $t->subs->submit($t->acme->id, $t->ulid, ['member_id' => 'M123', 'license_number' => 'TX-1', 'managing_broker_name' => 'Dana Brooks', 'managing_broker_email' => 'dana@acme.test'], $t->owner);
}

it('shows the owner that the agreement waits on them, and lets them sign from the back office', function (): void {
    idx13Submit($this);
    $this->actingAs($this->owner);
    $board = app(TenantContext::class)->runAs($this->acme, fn () => $this->subs->forTenant($this->acme->id)[0]);
    expect($board['signing'])->names->toBe(['Pat Owner'])->emails->toBe(['owner@acme.test'])->mine->toBeTrue()
        ->and($board['by_hand'])->toBeFalse();

    $url = app(TenantContext::class)->runAs($this->acme, fn () => $this->subs->signNow($this->acme->id, $this->ulid, $this->owner));
    expect($url)->toContain('/sign/');
});

it('emails the signing link again; the earlier link stops working', function (): void {
    idx13Submit($this);
    Notification::fake();
    $before = app(TenantContext::class)->runAs($this->acme, fn () => DB::connection(Connections::cell())->table('form_signers')->where('email', 'owner@acme.test')->value('token_hash'));

    expect($this->subs->resendSigning($this->ulid, $this->acme->id, $this->owner))->toBe(1);
    Notification::assertSentOnDemand(SignatureRequested::class, fn ($n, $c, $notifiable): bool => $notifiable->routes['mail'] === 'owner@acme.test');
    $after = app(TenantContext::class)->runAs($this->acme, fn () => DB::connection(Connections::cell())->table('form_signers')->where('email', 'owner@acme.test')->value('token_hash'));
    expect($after)->not->toBe($before);
});

it('does not let someone sign whose turn it is not', function (): void {
    idx13Submit($this);
    $other = app(TenantContext::class)->runAs($this->acme, fn () => User::factory()->owner()->create(['email' => 'other@acme.test']));

    app(TenantContext::class)->runAs($this->acme, fn () => $this->subs->signNow($this->acme->id, $this->ulid, $other));
})->throws(ValidationException::class);

it('sends the agreement when the board\'s address was added after the brokerage sent its details', function (): void {
    $this->db->table('idx_boards')->where('code', 'HAR')->update(['send_to' => null]);
    idx13Submit($this);
    expect($this->db->table('idx_subscriptions')->where('ulid', $this->ulid)->value('packet_ulid'))->toBeNull()
        ->and($this->subs->queue()[0]['by_hand'])->toBeTrue();

    $this->db->table('idx_boards')->where('code', 'HAR')->update(['send_to' => 'onboarding@repliers.test']);
    $this->subs->resendSigning($this->ulid, null, $this->staff);
    expect($this->db->table('idx_subscriptions')->where('ulid', $this->ulid)->value('packet_ulid'))->not->toBeNull();
    Notification::assertSentOnDemand(SignatureRequested::class);
});

it('lets HQ resend through its own page', function (): void {
    idx13Submit($this);
    $this->actingAs($this->staff)->withServerVariables(['HTTP_HOST' => 'app.rightally.io'])
        ->post('https://app.rightally.io/hq/idx/subscriptions/'.$this->ulid.'/resend')->assertRedirect();
    Notification::assertSentOnDemand(SignatureRequested::class);
});

it('tells HQ once a week about boards the MLS has had longer than usual', function (): void {
    idx13Submit($this);
    $this->db->table('idx_subscriptions')->where('ulid', $this->ulid)->update(['status' => 'mls_review', 'status_at' => now()->subWeeks(5), 'sent_to_mls_at' => now()->subWeeks(5)]);
    expect($this->subs->queue()[0]['overdue'])->toBeTrue();

    $this->artisan('idx:remind')->assertSuccessful();
    Mail::assertQueued(IdxUpdateMail::class, fn (IdxUpdateMail $m): bool => $m->hasTo('staff@rightally.test'));
    Mail::fake();
    $this->artisan('idx:remind')->assertSuccessful();
    Mail::assertNotQueued(IdxUpdateMail::class);
});

it('does not flag a board that is within its usual time', function (): void {
    idx13Submit($this);
    $this->db->table('idx_subscriptions')->where('ulid', $this->ulid)->update(['status' => 'sent', 'status_at' => now()->subDays(3), 'sent_to_mls_at' => now()->subDays(3)]);
    expect($this->subs->overdue())->toBe([]);
});
