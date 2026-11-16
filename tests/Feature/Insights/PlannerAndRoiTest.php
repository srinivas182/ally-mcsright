<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Models\Lead;
use Modules\Insights\Planner;
use Modules\Insights\RoiMail;
use Modules\Transactions\Models\Deal;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann']);
});

it('works back from an income goal to weekly activity', function (): void {
    $plan = Planner::plan(10000000, true, ['average_price_cents' => 40000000, 'commission_rate' => 0.025, 'net_share' => 0.8, 'closing_rate' => 0.5, 'agreement_rate' => 0.5, 'appointment_rate' => 0.1]);
    // $100k take-home at 80% → $125k GCI; $10k per closing → 13 closings; 26 agreements; 52 appointments; 520 conversations.
    expect($plan)->toMatchArray(['gci_cents' => 12500000, 'closings' => 13, 'agreements' => 26, 'appointments' => 52, 'conversations' => 520])
        ->and($plan['weekly'])->toBe(['closings' => 0.3, 'agreements' => 0.5, 'appointments' => 2, 'conversations' => 11]);
});

it('uses the agent\'s own history, saves the plan and the goal, and tracks this week', function (): void {
    foreach (['2026-02-01', '2026-05-01', '2026-08-01'] as $i => $date) {
        Deal::query()->create(['type' => 'buyer', 'status' => 'closed', 'agent_user_id' => $this->ann->id, 'street' => "{$i} Oak", 'state_code' => 'TX',
            'price_cents' => 50000000, 'gci_cents' => 1500000, 'closed_on' => $date, 'closing_date' => $date]);
    }
    $contact = app(ManageContacts::class)->create(['first_name' => 'Bo', 'email' => 'bo@x.test'], $this->ann);
    app(Timeline::class)->log($contact, ['type' => 'call', 'outcome' => 'connected', 'body' => 'Talked'], $this->ann);
    app(Timeline::class)->log($contact, ['type' => 'meeting', 'body' => 'Listing appointment'], $this->ann);

    $this->actingAs($this->ann)->get('/insights/plan')->assertInertia(fn (Assert $page) => $page
        ->component('insights/plan')->where('plan', null)->where('rates.average_price_cents', 50000000)->where('rates.commission_rate', 0.03)
        ->where('rates.from_history', fn ($h) => collect($h)->contains('average price and commission'))
        ->where('week.conversations', 2)->where('week.appointments', 1)->where('ytd.closings', 3));

    $this->actingAs($this->ann)->post('/insights/plan', ['income' => 150000, 'take_home' => false, 'average_price' => 500000, 'commission_rate' => 3,
        'closing_rate' => 60, 'agreement_rate' => 50, 'appointment_rate' => 10])->assertSessionHasNoErrors();
    $this->actingAs($this->ann)->get('/insights/plan')->assertInertia(fn (Assert $page) => $page
        ->where('plan.gci_cents', 15000000)->where('plan.closings', 10)->where('plan.agreements', 17)->where('inputs.take_home', false));
    expect(DB::table('insights_goals')->where('user_id', $this->ann->id)->value('sides'))->toBe(10);
    $this->actingAs($this->ann)->get('/insights/me')->assertInertia(fn (Assert $page) => $page->where('goal.gci_cents', 15000000));
});

it('reports the month with RightAlly to owners on the 1st', function (): void {
    Notification::fake();
    $lead = Lead::query()->create(['source' => 'website', 'channel' => 'web', 'status' => 'new', 'response_seconds' => 120, 'responded_at' => now()]);
    Lead::query()->whereKey($lead->id)->update(['created_at' => '2026-10-10 12:00:00']);
    Deal::query()->create(['type' => 'buyer', 'status' => 'closed', 'agent_user_id' => $this->ann->id, 'street' => '1 Elm', 'state_code' => 'TX',
        'price_cents' => 40000000, 'gci_cents' => 1200000, 'closed_on' => '2026-10-20', 'closing_date' => '2026-10-20']);

    $this->actingAs($this->owner)->get('/insights/roi?month=2026-10')->assertInertia(fn (Assert $page) => $page
        ->component('insights/roi')->where('label', 'October 2026')->where('leads.count', 1)->where('leads.median_minutes', 2)
        ->where('closings.sides', 1)->where('closings.gci_cents', 1200000));
    $this->actingAs($this->ann)->get('/insights/roi')->assertForbidden();

    $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00:00'));
    $this->artisan('insights:daily')->assertSuccessful();
    Notification::assertSentTo($this->owner, RoiMail::class, fn (RoiMail $m) => $m->report['label'] === 'November 2026');
});
