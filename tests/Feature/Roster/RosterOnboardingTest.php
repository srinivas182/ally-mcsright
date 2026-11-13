<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Payouts\AgentBilling;
use Modules\Payouts\BankAccounts;
use Modules\Payouts\W9s;
use Modules\Roster\Agreements;
use Modules\Roster\Flows;
use Modules\Roster\Roster;
use Modules\Roster\UpdateDecided;
use Modules\Roster\UpdateRequests;

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->staff = User::factory()->staff()->create();
    $this->agent = User::factory()->create();
    $this->agreements = app(Agreements::class);
    $this->flows = app(Flows::class);
});

function agreementText(string $extra = ''): string
{
    return 'The agent is an independent contractor and not an employee of the brokerage. Commission is paid per the agent\'s plan.'.$extra;
}

it('lets agents e-sign the current agreement, recording the exact text, and asks again after a new version', function (): void {
    expect(fn () => $this->agreements->publish('agreement', 'ICA', agreementText(), $this->staff))->toThrow(ValidationException::class);
    $this->agreements->publish('agreement', 'Independent contractor agreement', agreementText(), $this->owner);

    $this->actingAs($this->agent)->get('/roster/me')->assertInertia(fn (Assert $page) => $page->has('toSign', 1));
    $this->actingAs($this->agent)->post('/roster/agreements/agreement/sign', ['typed_name' => 'Someone Else', 'agree' => true])->assertSessionHasErrors('sign');
    $this->actingAs($this->agent)->post('/roster/agreements/agreement/sign', ['typed_name' => $this->agent->name, 'agree' => true])->assertSessionHasNoErrors();

    $sig = DB::table('roster_signatures')->first();
    expect($sig->sha256)->toBe(hash('sha256', agreementText()))->and($sig->ip)->toBe('127.0.0.1')
        ->and(DB::table('roster_documents')->where('id', $sig->document_id)->value('kind'))->toBe('agreement')
        ->and($this->agreements->signed($this->agent, 'agreement'))->toBeTrue();

    $this->agreements->publish('agreement', 'Independent contractor agreement', agreementText(' Updated for 2027.'), $this->owner);
    expect($this->agreements->signed($this->agent, 'agreement'))->toBeFalse();
});

it('runs onboarding: agent tasks tick themselves, brokerage tasks are marked, and it completes at 100%', function (): void {
    $this->agreements->publish('agreement', 'ICA', agreementText(), $this->owner);
    $this->agreements->publish('policy', 'Policy manual', agreementText(' Policies.'), $this->owner);
    $this->flows->start($this->agent, 'onboarding', $this->staff);
    expect(fn () => $this->flows->start($this->agent, 'onboarding', $this->staff))->toThrow(ValidationException::class, 'already');

    $this->agreements->sign($this->agent, 'agreement', $this->agent->name, true, null, null);
    $this->agreements->sign($this->agent, 'policy', $this->agent->name, true, null, null);
    app(W9s::class)->submit(['legal_name' => $this->agent->name, 'tax_class' => 'individual', 'tin_type' => 'ssn', 'tin' => '123456789', 'address' => '1 Main St',
        'signature_name' => $this->agent->name, 'certify' => true], $this->agent);
    app(BankAccounts::class)->set($this->agent, $this->agent->name, '021000021', '111122223333', 'checking', $this->agent);
    $roster = app(Roster::class);
    $roster->add($this->agent, 'licence', ['state_code' => 'TX', 'number' => '1', 'expires_on' => '2028-01-01'], $this->staff);
    $roster->add($this->agent, 'eo', ['issuer' => 'RISC', 'expires_on' => '2027-01-01'], $this->staff);

    $view = $this->flows->view($this->agent, 'onboarding');
    expect(collect($view['tasks'])->where('who', 'agent')->every(fn ($t) => $t['done']))->toBeTrue()->and($view['progress'])->toBe(60)
        ->and(fn () => $this->flows->complete($this->agent, 'onboarding', $this->staff))->toThrow(ValidationException::class, 'Finish every task')
        ->and(fn () => $this->flows->mark($view['tasks'][6]['id'], true, $this->agent))->toThrow(ValidationException::class);

    foreach (array_slice($view['tasks'], 6) as $t) {
        $this->actingAs($this->staff)->post("/roster/tasks/{$t['id']}", ['done' => true])->assertSessionHasNoErrors();
    }
    $this->flows->complete($this->agent, 'onboarding', $this->staff);
    expect($this->flows->view($this->agent, 'onboarding'))->toMatchArray(['status' => 'complete', 'progress' => 100]);
});

it('runs offboarding: balances must be settled, and an owner completing it deactivates the account', function (): void {
    expect(fn () => $this->flows->start($this->agent, 'offboarding', $this->staff))->toThrow(ValidationException::class, 'leaving date');
    $this->flows->start($this->agent, 'offboarding', $this->staff, CarbonImmutable::parse('2026-06-30'), 'Moving to another brokerage');
    app(AgentBilling::class)->charge($this->agent, 'Final desk fee', 5_000, $this->owner);

    $view = $this->flows->view($this->agent, 'offboarding');
    expect(collect($view['tasks'])->firstWhere('code', 'settled')['done'])->toBeFalse();
    foreach ($view['tasks'] as $t) {
        if (! $t['automatic']) {
            $this->flows->mark($t['id'], true, $this->staff);
        }
    }
    expect(fn () => $this->flows->complete($this->agent, 'offboarding', $this->owner))->toThrow(ValidationException::class, 'Finish every task');

    app(AgentBilling::class)->paidOnline((string) DB::table('bill_invoices')->value('ulid'), 5_000, 'pi_final');
    // Money received online sits in processor clearing; the agent owes nothing now.
    expect(collect($this->flows->view($this->agent, 'offboarding')['tasks'])->firstWhere('code', 'settled')['done'])->toBeTrue()
        ->and(fn () => $this->flows->complete($this->agent, 'offboarding', $this->staff))->toThrow(ValidationException::class, 'owner');
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post("/roster/{$this->agent->ulid}/flows/offboarding/complete")->assertSessionHasNoErrors();
    expect($this->agent->fresh()->status)->toBe(UserStatus::Deactivated);
});

it('lets agents send renewals that take effect only when the brokerage approves', function (): void {
    Notification::fake();
    $file = fn () => UploadedFile::fake()->createWithContent('licence.pdf', "%PDF-1.4\n%%EOF");
    $this->actingAs($this->agent)->post('/roster/me/requests', ['kind' => 'licence', 'state_code' => 'TX', 'number' => '0612345', 'expires_on' => '2028-08-31', 'file' => $file()])->assertSessionHasNoErrors();
    expect(app(Roster::class)->forUser($this->agent)['credentials'])->toBe([]);

    $pending = app(UpdateRequests::class)->pending();
    $this->actingAs($this->agent)->post("/roster/requests/{$pending[0]['ulid']}/decide", ['approve' => true])->assertForbidden();
    $this->actingAs($this->staff)->post("/roster/requests/{$pending[0]['ulid']}/decide", ['approve' => true])->assertSessionHasNoErrors();
    expect(app(Roster::class)->forUser($this->agent)['credentials'][0])->toMatchArray(['state_code' => 'TX', 'expires_on' => '2028-08-31']);
    Notification::assertSentTo($this->agent, UpdateDecided::class, fn (UpdateDecided $n): bool => $n->approved);

    app(UpdateRequests::class)->submit($this->agent, 'eo', ['issuer' => 'RISC', 'expires_on' => '2025-01-01'], $file());
    $eo = app(UpdateRequests::class)->pending()[0]['ulid'];
    expect(fn () => app(UpdateRequests::class)->decide($eo, false, null, $this->staff))->toThrow(ValidationException::class);
    app(UpdateRequests::class)->decide($eo, false, 'That certificate expired in 2025', $this->staff);
    Notification::assertSentTo($this->agent, UpdateDecided::class, fn (UpdateDecided $n): bool => ! $n->approved && $n->note === 'That certificate expired in 2025');
});
