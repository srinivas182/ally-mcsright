<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Audit\AuditRetention;
use Modules\Core\Audit\FlushAuditOutbox;
use Modules\Core\Audit\LegalHold;
use Modules\Core\Audit\SecurityDigest;
use Modules\Core\Audit\WeeklySecurityDigestMail;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\ManageContacts;

beforeEach(function (): void {
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Maria', 'last_name' => 'Ruiz']);
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee']);
    $this->kim = User::factory()->create(['first_name' => 'Kim', 'last_name' => 'Ng']);
    $this->contacts = app(ManageContacts::class);
});

it('writes the activity in plain English, groups repeated actions, and filters to important ones', function (): void {
    $sam = $this->contacts->create(['first_name' => 'Sam', 'last_name' => 'Ortiz', 'email' => 's@x.test'], $this->owner);
    $this->contacts->assignOwner($sam, $this->agent, $this->owner);
    $this->actingAs($this->owner);
    $this->contacts->assignOwner($sam->fresh(), $this->kim, $this->owner);
    foreach (['a', 'b', 'c'] as $n) {
        $c = $this->contacts->create(['first_name' => 'Ann'.$n, 'email' => "{$n}@x.test"], $this->owner);
        $this->get("/contacts/{$c->ulid}");
    }
    $this->post("/users/{$this->agent->ulid}/impersonate", ['reason' => 'Checking a missing commission']);
    $this->post('/impersonate/stop');

    $this->get('/audit')->assertInertia(fn (Assert $page) => $page
        ->where('view', 'activity')
        ->where('rows', fn ($rows) => collect($rows)->pluck('sentence')->contains('Maria Ruiz reassigned Sam Ortiz from Pat Lee to Kim Ng')
            && collect($rows)->pluck('sentence')->contains('Maria Ruiz viewed 3 contacts')));

    $this->get('/audit?important=1')->assertInertia(fn (Assert $page) => $page
        ->where('rows', fn ($rows) => collect($rows)->pluck('sentence')->contains(fn ($s) => str_starts_with($s, 'Maria Ruiz started viewing as Pat Lee: Checking a missing commission'))
            && ! collect($rows)->pluck('sentence')->contains('Maria Ruiz viewed 3 contacts')));
});

it('removes events after the retention period, keeping the rest verifiable, unless on legal hold', function (): void {
    $old = $this->contacts->create(['first_name' => 'Old', 'email' => 'o@x.test'], $this->owner);
    $this->travel(8)->years();
    $this->contacts->create(['first_name' => 'New', 'email' => 'n@x.test'], $this->owner);

    // A hold on the old contact protects that contact's history (older unrelated events may go).
    $this->actingAs($this->owner)->post('/audit/holds', ['reason' => 'Smith v. Acme Realty, case 24-1187', 'contact' => $old->ulid])->assertSessionHasNoErrors();
    app(AuditRetention::class)->purge();
    (new FlushAuditOutbox)->handle();
    expect(DB::table('audit_events')->where('subject_label', 'Old')->count())->toBeGreaterThan(0);
    $this->artisan('audit:verify', ['tenant' => 'acme'])->assertSuccessful();

    $hold = LegalHold::query()->sole();
    $this->post("/audit/holds/{$hold->ulid}/release");
    $removed = app(AuditRetention::class)->purge();
    (new FlushAuditOutbox)->handle();

    expect($removed)->toBeGreaterThan(0)
        ->and(DB::table('audit_events')->where('subject_label', 'Old')->where('action', 'crm.contact.created')->count())->toBe(0)
        ->and(DB::table('audit_events')->where('action', 'core.audit.retention_purged')->exists())->toBeTrue();
    $this->artisan('audit:verify', ['tenant' => 'acme'])->expectsOutputToContain('earlier events removed by retention')->assertSuccessful();

    $this->post('/audit/retention', ['years' => 5])->assertSessionHasErrors('years');
    $this->post('/audit/retention', ['years' => 10])->assertSessionHasNoErrors();
    expect((int) $this->tenant->fresh()?->audit_retention_years)->toBe(10);
    $this->actingAs($this->agent)->post('/audit/retention', ['years' => 10])->assertForbidden();
});

it('pauses all deletion under a brokerage-wide legal hold', function (): void {
    $this->contacts->create(['first_name' => 'Old', 'email' => 'o@x.test'], $this->owner);
    $this->travel(8)->years();
    $this->actingAs($this->owner)->post('/audit/holds', ['reason' => 'State audit of all brokerage records'])->assertSessionHasNoErrors();

    expect(app(AuditRetention::class)->purge())->toBe(0);
});

it('emails owners a weekly digest with payout and bank changes always first', function (): void {
    Notification::fake();
    app(AuditLogger::class)->record('core.auth.new_device', $this->agent, metadata: ['device' => 'Chrome on Windows'], severity: 'notice');
    app(AuditLogger::class)->record('finance.payout_details.changed', $this->agent, severity: 'critical');
    (new FlushAuditOutbox)->handle();

    $digest = app(SecurityDigest::class)->build(now()->subWeek()->toImmutable());
    expect($digest['sections'][0]['title'])->toBe('Payout and bank-detail changes')
        ->and($digest['sections'][0]['lines'][0])->toContain('Pat Lee')
        ->and(collect($digest['sections'])->pluck('title'))->toContain('New-device sign-ins')
        ->and($digest['quiet'])->toBeFalse();

    $this->artisan('audit:maintain', ['task' => 'weekly-digest'])->assertSuccessful();
    Notification::assertSentTo($this->owner, WeeklySecurityDigestMail::class);
    Notification::assertNotSentTo($this->agent, WeeklySecurityDigestMail::class);

    $this->travel(2)->weeks();
    $quiet = app(SecurityDigest::class)->build(now()->subWeek()->toImmutable());
    expect($quiet['quiet'])->toBeTrue()->and($quiet['sections'][0]['lines'][0])->toBe('No payout or bank-detail changes this week.');
});

it('gives owners an evidence pack PDF with the integrity check, and records the download', function (): void {
    $sam = $this->contacts->create(['first_name' => 'Sam', 'email' => 's@x.test'], $this->owner);

    $response = $this->actingAs($this->owner)->get("/contacts/{$sam->ulid}/evidence-pack")->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and(DB::table('audit_events')->where('action', 'crm.contact.evidence_pack')->exists())->toBeTrue();

    $this->actingAs($this->agent)->get("/contacts/{$sam->ulid}/evidence-pack")->assertForbidden();
    $hidden = app(TenantContext::class)->runAs($this->tenant, fn () => $this->contacts->create(['first_name' => 'Hid', 'email' => 'h@x.test'], $this->agent));
    $this->actingAs($this->owner)->get("/contacts/{$hidden->ulid}/evidence-pack")->assertNotFound();
});
