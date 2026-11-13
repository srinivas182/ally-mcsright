<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Roster\CeIncomplete;
use Modules\Roster\ContinuingEducation;
use Modules\Roster\LicenceProblem;
use Modules\Roster\Roster;
use Modules\Roster\Verification;
use Modules\Roster\Verification\FakeVerifier;
use Modules\Roster\Verification\LicenceVerifier;
use Modules\Transactions\Models\Deal;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->staff = User::factory()->staff()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee']);
    $this->other = User::factory()->create();
    $this->roster = app(Roster::class);
    $this->ce = app(ContinuingEducation::class);
    $this->licence = $this->roster->add($this->agent, 'licence', ['state_code' => 'TX', 'number' => '0612345', 'expires_on' => '2026-12-31'], $this->owner);
});

it('tracks CE hours and required topics in the cycle ending at the renewal date', function (): void {
    expect(fn () => $this->ce->saveRule('TX', '18', 24, [], $this->agent))->toThrow(ValidationException::class);
    $this->actingAs($this->staff)->post('/roster/ce/rules', ['state' => 'tx', 'hours' => '18', 'cycle_months' => 24, 'topics' => [['topic' => 'Ethics', 'hours' => '3'], ['topic' => '', 'hours' => '']]])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->agent)->post('/roster/me/ce', ['state' => 'TX', 'title' => 'Contracts update', 'hours' => '12', 'completed_on' => '2026-03-10'])->assertSessionHasNoErrors();
    $this->ce->log($this->agent, 'TX', 'Old course', null, null, '6', CarbonImmutable::parse('2024-06-01'), null, $this->agent); // before the cycle
    $this->ce->log($this->agent, 'TX', 'Ethics in practice', 'Champions', 'Ethics', '3', CarbonImmutable::parse('2026-05-01'), null, $this->agent);
    expect(fn () => $this->ce->log($this->agent, 'TX', 'Future', null, null, '3', CarbonImmutable::parse('2026-10-01'), null, $this->agent))->toThrow(ValidationException::class)
        ->and(fn () => $this->ce->log($this->other, 'TX', 'For someone else', null, null, '3', CarbonImmutable::parse('2026-05-01'), null, $this->agent))->toThrow(ValidationException::class);

    $p = $this->ce->progress($this->agent)[0];
    expect($p)->toMatchArray(['state' => 'TX', 'renewal' => '2026-12-31', 'cycle_start' => '2024-12-31', 'required' => '18.0', 'done' => '15.0', 'complete' => false])
        ->and($p['topics'][0])->toBe(['topic' => 'Ethics', 'required' => '3.0', 'done' => '3.0']);

    $this->ce->log($this->agent, 'TX', 'Fair housing', null, null, '3', CarbonImmutable::parse('2026-08-01'), null, $this->agent);
    expect($this->ce->progress($this->agent)[0]['complete'])->toBeTrue();
    $old = DB::table('roster_ce_courses')->where('title', 'Old course')->value('ulid');
    expect(fn () => $this->ce->remove($old, $this->agent))->toThrow(ValidationException::class);
    $this->actingAs($this->agent)->get('/roster/me')->assertInertia(fn (Assert $page) => $page->where('ce.0.complete', true)->where('canVerify', false));
});

it('reminds agents with unfinished CE at 90 and 60 days before renewal, and not once complete', function (): void {
    Notification::fake();
    $this->ce->saveRule('TX', '18', 24, [], $this->owner);
    $this->ce->remind(CarbonImmutable::parse('2026-10-02'));
    $this->ce->remind(CarbonImmutable::parse('2026-10-03'));
    $this->ce->remind(CarbonImmutable::parse('2026-11-01'));
    Notification::assertSentToTimes($this->agent, CeIncomplete::class, 2);

    $this->ce->log($this->agent, 'TX', 'Everything', null, null, '18', CarbonImmutable::parse('2026-09-01'), null, $this->agent);
    $this->ce->remind(CarbonImmutable::parse('2026-12-01'));
    Notification::assertSentToTimes($this->agent, CeIncomplete::class, 2);
});

it('checks licences against state records once switched on, and blocks deals for a licence the state shows as suspended', function (): void {
    expect(app(Verification::class)->available())->toBeFalse()->and(app(Verification::class)->recheckDue())->toBe(0)
        ->and(fn () => app(Verification::class)->check($this->licence))->toThrow(ValidationException::class, 'not switched on');

    Notification::fake();
    $fake = new FakeVerifier(['TX:0612345' => ['status' => 'active', 'expires_on' => '2027-01-31', 'last_name' => 'Lee']]);
    app()->instance(LicenceVerifier::class, $fake);
    app()->forgetScopedInstances();
    $this->actingAs($this->owner)->post("/roster/credentials/{$this->licence}/check")->assertSessionHasNoErrors();
    expect($this->roster->forUser($this->agent)['credentials'][0])->toMatchArray(['verified' => true, 'expires_on' => '2027-01-31', 'check_result' => 'active']);

    $fake->records['TX:0612345']['status'] = 'suspended';
    expect(app(Verification::class)->recheckDue())->toBe(0); // checked today; due again in 30 days
    $this->travel(31)->days();
    expect(app(Verification::class)->recheckDue())->toBe(1);
    Notification::assertSentTo($this->owner, LicenceProblem::class);
    expect($this->roster->forUser($this->agent)['credentials'][0])->toMatchArray(['verified' => false, 'check_result' => 'inactive']);

    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->roster->saveSettings(true, false, $this->owner);
    $this->actingAs($this->agent)->post('/deals', ['type' => 'listing', 'street' => '9 Oak', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000']);
    $deal = Deal::query()->latest('id')->firstOrFail();
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '500000', 'gci' => '15000', 'contract_date' => '2026-10-02', 'closing_date' => '2026-11-01'])
        ->assertSessionHasErrors('status');

    $fake->records['TX:0612345'] = ['status' => 'active', 'expires_on' => '2027-01-31', 'last_name' => 'Smith'];
    expect(app(Verification::class)->check($this->licence))->toBe('mismatch');
});

it('exports the roster for staff and lets everyone find colleagues in the directory', function (): void {
    $this->roster->add($this->agent, 'eo', ['issuer' => 'RISC', 'expires_on' => '2027-01-01'], $this->owner);
    $this->actingAs($this->agent)->get('/roster/export.csv')->assertForbidden();
    $csv = (string) $this->actingAs($this->staff)->get('/roster/export.csv')->assertOk()->getContent();
    expect($csv)->toContain('"Pat Lee"')->toContain('TX 0612345 (exp 2026-12-31)')->toContain('"RISC",2027-01-01');

    $this->actingAs($this->agent)->get('/directory?q=pat')->assertInertia(fn (Assert $page) => $page->component('roster/directory')
        ->has('people', 1)->where('people.0.licences', ['TX 0612345']));
});
