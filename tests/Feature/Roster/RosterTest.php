<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Roster\Compliance;
use Modules\Roster\CredentialExpiring;
use Modules\Roster\ExpiryReminders;
use Modules\Roster\LicenceGuard;
use Modules\Roster\Roster;
use Modules\Transactions\Models\Deal;

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->staff = User::factory()->staff()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat']);
    $this->other = User::factory()->create(['first_name' => 'Quinn']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->roster = app(Roster::class);
});

function rosterDeal(): Deal
{
    test()->actingAs(test()->agent)->post('/deals', ['type' => 'listing', 'street' => uniqid().' Cedar', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000']);

    return Deal::query()->latest('id')->firstOrFail();
}

function contract(Deal $deal): TestResponse
{
    return test()->actingAs(test()->agent)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '500000', 'gci' => '15000',
        'contract_date' => '2026-06-01', 'closing_date' => '2026-07-01']);
}

it('keeps licences, E&O and memberships, replacing old records but keeping them on file', function (): void {
    expect(fn () => $this->roster->add($this->agent, 'licence', ['state_code' => 'TX'], $this->owner))->toThrow(ValidationException::class, 'state, the licence number')
        ->and(fn () => $this->roster->add($this->agent, 'licence', ['state_code' => 'TX', 'number' => '1', 'expires_on' => '2027-01-01'], $this->agent))->toThrow(ValidationException::class);

    $this->roster->add($this->agent, 'licence', ['state_code' => 'tx', 'number' => '0612345', 'expires_on' => '2026-08-31'], $this->owner);
    $this->roster->add($this->agent, 'licence', ['state_code' => 'TX', 'number' => '0612345', 'expires_on' => '2028-08-31'], $this->staff);
    $this->roster->add($this->agent, 'licence', ['state_code' => 'OK', 'number' => '17890', 'expires_on' => '2027-03-31'], $this->owner);
    $this->roster->add($this->agent, 'eo', ['issuer' => 'RISC', 'coverage_cents' => 100_000_000, 'expires_on' => '2027-01-01'], $this->owner);
    $this->roster->add($this->agent, 'association', ['issuer' => 'Austin Board of REALTORS', 'number' => 'NRDS 123'], $this->owner);

    $records = $this->roster->forUser($this->agent);
    expect($records['credentials'])->toHaveCount(4)->and($records['history'])->toHaveCount(1)
        ->and(collect($records['credentials'])->firstWhere('state_code', 'TX')['expires_on'])->toBe('2028-08-31');
    $this->actingAs($this->agent)->post("/roster/{$this->agent->ulid}/credentials", ['kind' => 'mls', 'issuer' => 'ACTRIS'])->assertForbidden();
});

it('stops a deal going under contract without an active licence in its state and E&O once the guard is on', function (): void {
    contract(rosterDeal())->assertSessionHasNoErrors(); // off by default for a new brokerage

    expect(fn () => $this->roster->saveSettings(true, true, $this->staff))->toThrow(ValidationException::class);
    $this->roster->saveSettings(true, true, $this->owner);
    $deal = rosterDeal();
    contract($deal)->assertSessionHasErrors(['status' => "{$this->agent->name} has no active TX licence on file. Add or renew it in the roster (or an owner can allow this deal with a reason)."]);

    $this->roster->add($this->agent, 'licence', ['state_code' => 'OK', 'number' => '1', 'expires_on' => '2027-01-01'], $this->owner);
    contract($deal)->assertSessionHasErrors('status');
    $this->roster->add($this->agent, 'licence', ['state_code' => 'TX', 'number' => '2', 'expires_on' => '2026-05-31'], $this->owner);
    contract($deal)->assertSessionHasErrors('status'); // expired
    $this->roster->add($this->agent, 'licence', ['state_code' => 'TX', 'number' => '2', 'expires_on' => '2028-05-31'], $this->owner);
    contract($deal)->assertSessionHasErrors(['status' => "{$this->agent->name}'s E&O insurance is missing or expired. Add the renewal in the roster (or an owner can allow this deal with a reason)."]);

    $this->roster->saveSettings(true, false, $this->owner);
    contract($deal)->assertSessionHasNoErrors();
});

it('lets only an owner allow one deal past the guard, with a reason', function (): void {
    $this->roster->saveSettings(true, true, $this->owner);
    $deal = rosterDeal();
    $guard = app(LicenceGuard::class);
    expect(fn () => $guard->override($deal, 'Renewal approved by the state', $this->staff))->toThrow(ValidationException::class)
        ->and(fn () => $guard->override($deal, 'ok', $this->owner))->toThrow(ValidationException::class);

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post("/roster/deals/{$deal->ulid}/override", ['reason' => 'Renewal approved; state site not updated yet'])
        ->assertSessionHasNoErrors();
    contract($deal)->assertSessionHasNoErrors();
    contract(rosterDeal())->assertSessionHasErrors('status');
});

it('records manual verification and keeps agent documents private to the agent and the brokerage', function (): void {
    $licence = $this->roster->add($this->agent, 'licence', ['state_code' => 'TX', 'number' => '0612345', 'expires_on' => '2028-01-01'], $this->owner);
    $shot = $this->roster->upload($this->agent, 'verification', UploadedFile::fake()->createWithContent('trec.pdf', "%PDF-1.4\n%%EOF"), $this->owner);
    expect(fn () => $this->roster->verify($licence, 'checked', null, $this->owner))->toThrow(ValidationException::class);
    $this->actingAs($this->owner)->post("/roster/credentials/{$licence}/verify", ['note' => 'Looked up on the TREC site: active until 2028', 'evidence' => $shot])->assertSessionHasNoErrors();
    expect($this->roster->forUser($this->agent)['credentials'][0])->toMatchArray(['verified' => true, 'verification_note' => 'Looked up on the TREC site: active until 2028']);

    expect(fn () => $this->roster->upload($this->agent, 'agreement', UploadedFile::fake()->createWithContent('a.exe', 'MZ'), $this->owner))->toThrow(ValidationException::class);
    $this->actingAs($this->agent)->get("/roster/documents/{$shot}")->assertOk();
    $this->actingAs($this->other)->get("/roster/documents/{$shot}")->assertForbidden();
    $this->actingAs($this->agent)->get('/roster/me')->assertInertia(fn (Assert $page) => $page->component('roster/agent')->where('canManage', false));
    $this->actingAs($this->agent)->get('/roster')->assertForbidden();
});

it('shows what is expired, expiring, missing or unverified on the compliance dashboard', function (): void {
    $this->roster->add($this->agent, 'licence', ['state_code' => 'TX', 'number' => '1', 'expires_on' => '2026-06-20'], $this->owner); // 19 days
    $this->roster->add($this->agent, 'eo', ['issuer' => 'RISC', 'expires_on' => '2026-05-15'], $this->owner); // expired
    $this->roster->add($this->other, 'licence', ['state_code' => 'TX', 'number' => '2', 'expires_on' => '2026-08-15'], $this->owner); // 75 days
    $this->roster->upload($this->other, 'agreement', UploadedFile::fake()->createWithContent('ica.pdf', "%PDF-1.4\n%%EOF"), $this->owner);

    $d = app(Compliance::class)->dashboard();
    expect($d['counts'])->toMatchArray(['expired' => 1, 'within_30' => 1, 'within_90' => 1, 'missing' => 2, 'unverified' => 2])
        ->and(collect($d['agents'])->firstWhere('name', $this->other->name)['issues'])->toContain(['level' => 'missing', 'text' => 'Missing: E&O, W-9']);
    $this->actingAs($this->staff)->get('/roster')->assertInertia(fn (Assert $page) => $page->component('roster/index')->where('settings.guard_licence', false));
});

it('reminds the agent and owners at 60, 30 and 7 days and on expiry, once each, starting again after renewal', function (): void {
    Notification::fake();
    $this->roster->add($this->agent, 'licence', ['state_code' => 'TX', 'number' => '1', 'expires_on' => '2026-07-31'], $this->owner);
    $reminders = app(ExpiryReminders::class);
    foreach (['2026-06-01', '2026-06-02', '2026-07-01', '2026-07-25', '2026-07-31', '2026-08-02'] as $day) {
        $reminders->run(CarbonImmutable::parse($day));
    }
    Notification::assertSentToTimes($this->agent, CredentialExpiring::class, 4);
    Notification::assertSentToTimes($this->owner, CredentialExpiring::class, 4);

    $this->roster->add($this->agent, 'licence', ['state_code' => 'TX', 'number' => '1', 'expires_on' => '2026-09-30'], $this->owner);
    $reminders->run(CarbonImmutable::parse('2026-08-02'));
    Notification::assertSentToTimes($this->agent, CredentialExpiring::class, 5);
    $this->artisan('roster:reminders')->assertSuccessful();
});
