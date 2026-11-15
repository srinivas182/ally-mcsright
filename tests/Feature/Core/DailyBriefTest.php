<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Core\Brief\DailyBriefMail;
use Modules\Core\Brief\DailyBriefs;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Task;
use Modules\Transactions\Models\Deal;

/* Sprint DB1: the daily brief across the whole business — one ranked list for agents and brokers, from every
   module's own rules, on the home page and in a 7am email. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC')); // Monday, 10:00 in Austin
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
});

function briefSetup(object $t): void
{
    $contact = app(ManageContacts::class)->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@x.test'], $t->ann);
    Lead::query()->create(['contact_id' => $contact->id, 'source' => 'website', 'channel' => 'website', 'intent' => 'buy', 'price_max' => 500000, 'status' => 'assigned',
        'assigned_user_id' => $t->ann->id, 'assigned_at' => now()->subHours(2), 'new_contact' => true]);
    Task::query()->create(['assigned_to' => $t->ann->id, 'type' => 'call', 'title' => 'Call Bob', 'priority' => 'normal', 'due_at' => now()->subDay(), 'created_by' => $t->ann->id]);
    $t->actingAs($t->ann)->post('/deals', ['type' => 'listing', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '480000']);
    $deal = Deal::query()->latest('id')->firstOrFail();
    $deal->update(['status' => 'under_contract', 'gci_cents' => 1_440_000]);
    DB::table('tx_dates')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'label' => 'Option period ends', 'due_on' => '2026-06-02', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('tx_findings')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'code' => 'docs.missing', 'severity' => 'blocker', 'fingerprint' => str_repeat('a', 40),
        'message' => 'The seller\'s disclosure is not signed.', 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    DB::table('roster_credentials')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $t->tenant->id, 'user_id' => $t->ann->id, 'kind' => 'licence', 'state_code' => 'TX',
        'number' => '123', 'status' => 'current', 'expires_on' => '2026-06-05', 'created_at' => now(), 'updated_at' => now()]);
    auth()->logout();
}

it('gives the agent one ranked list: what is due now first, the most commission at stake first', function (): void {
    briefSetup($this);
    $this->actingAs($this->ann)->get('/agent')->assertInertia(fn (Assert $p) => $p->component('agent/home')->where('briefEmail', true)
        ->where('brief.0.title', 'Fix 1 problem on 12 Oak St, Austin')->where('brief.0.why', 'The seller\'s disclosure is not signed.')->where('brief.0.stakes_cents', 1_440_000)
        ->where('brief.1.title', 'Option period ends: 12 Oak St, Austin')->where('brief.1.url', fn (string $u) => str_starts_with($u, '/deals/'))
        ->where('brief.2.title', 'Answer Jane Doe, a new lead')->where('brief.2.stakes_cents', 1_250_000)->where('brief.2.why', fn (string $w) => str_starts_with($w, 'Waiting 2 h'))
        ->where('brief.3.title', '1 overdue task')
        ->where('brief.4.title', 'Your TX licence expires Jun 5'));
});

it('gives brokers the brokerage view: slow agents, payouts to approve, credits running low', function (): void {
    briefSetup($this);
    DB::table('pay_runs')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'status' => 'draft', 'pay_on' => '2026-06-03', 'total_cents' => 845_000,
        'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    $titles = collect(app(DailyBriefs::class)->for($this->owner))->pluck('title')->all();
    expect($titles)->toContain('Approve the payout run of $8,450.00')->toContain('Ann Agent hasn\'t answered 1 lead')->toContain('Fix 1 problem on 12 Oak St, Austin')
        ->toContain('Ann Agent\'s TX licence expires Jun 5')->not->toContain('1 overdue task');
    $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->component('dashboard')->where('firstName', 'Olive')->has('brief', count($titles)));
});

it('keeps the brief when a source fails', function (): void {
    briefSetup($this);
    app(DailyBriefs::class)->add('broken', fn (User $u): array => throw new RuntimeException('down'));
    expect(app(DailyBriefs::class)->for($this->ann))->toHaveCount(5);
});

it('emails the brief at 7am on weekdays, once, unless turned off', function (): void {
    briefSetup($this);
    $this->travelTo(CarbonImmutable::parse('2026-06-02 11:00', 'UTC')); // 6am in Austin
    expect(app(DailyBriefs::class)->sendDue())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-06-02 12:05', 'UTC')); // 7:05am
    expect(app(DailyBriefs::class)->sendDue())->toBe(2)->and(app(DailyBriefs::class)->sendDue())->toBe(0); // Ann and Olive, once
    Notification::assertSentTo($this->ann, DailyBriefMail::class, function (DailyBriefMail $n) {
        $mail = $n->toMail($this->ann);

        return $n->home === '/agent' && str_contains((string) $mail->subject, 'need you today') && count($n->items) === 5;
    });

    $this->actingAs($this->ann)->post('/brief/email', ['on' => false])->assertRedirect();
    $this->travelTo(CarbonImmutable::parse('2026-06-03 12:05', 'UTC'));
    expect(app(DailyBriefs::class)->sendDue())->toBe(1); // Olive only
    $this->travelTo(CarbonImmutable::parse('2026-06-06 12:05', 'UTC')); // Saturday
    expect(app(DailyBriefs::class)->sendDue())->toBe(0);
});
