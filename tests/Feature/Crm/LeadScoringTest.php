<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Leads\LeadAlert;
use Modules\Crm\Leads\LeadReports;
use Modules\Crm\Leads\LeadRouting;
use Modules\Crm\Leads\LeadScoring;
use Modules\Crm\Leads\LeadSources;
use Modules\Crm\Models\AiThread;
use Modules\Crm\Models\Lead;
use Modules\Crm\TodaysTen;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/* Sprint 16B-2: lead scores with reasons, hot-lead alerts, return on spend to commission, speed scorecard. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $this->bob = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Agent']);
    $this->key = (string) app(LeadSources::class)->create(['name' => 'Zapier', 'kind' => 'api', 'source' => 'zillow'], $this->owner)['key'];
    app(LeadRouting::class)->saveRule(['name' => 'Both', 'method' => 'round_robin', 'agent_ids' => [$this->ann->id, $this->bob->id]], $this->owner);
    Notification::fake();
});

function lead(object $t, array $in): Lead
{
    $t->postJson('/api/leads', $in + ['name' => 'Lee '.Str::random(4), 'email' => Str::random(6).'@x.test'], ['Authorization' => 'Bearer '.$t->key])->assertSuccessful();

    return Lead::query()->latest('id')->firstOrFail();
}

it('scores every lead with its reasons, and alerts the agent the moment a lead turns hot', function (): void {
    $cold = lead($this, ['name' => 'Cold Case']);
    expect($cold->score_label)->toBe('cold')->and($cold->score)->toBe(10)->and($cold->score_reasons)->toBe([]);

    $jane = lead($this, ['name' => 'Jane Doe', 'phone' => '5125550199', 'intent' => 'sell', 'property' => '12 Oak St', 'price' => 450000, 'message' => 'Thinking of selling this spring, what is it worth?']);
    expect($jane->score)->toBe(50)->and($jane->score_label)->toBe('warm')
        ->and($jane->score_reasons)->toBe(['Wants to sell', 'Asked about 12 Oak St', 'Gave a price ($450,000)', 'Wrote a message', 'Gave email and phone']);
    Notification::assertNotSentTo([$this->ann, $this->bob], LeadAlert::class, fn (LeadAlert $n): bool => str_starts_with($n->subject, 'Hot lead'));

    // The AI learns they are pre-approved and moving next month; they reply asking to see it.
    AiThread::query()->create(['lead_id' => $jane->id, 'contact_id' => $jane->contact_id, 'status' => 'handed_off', 'qualification' => ['preapproved' => 'yes, $450k', 'timeline' => 'next month']]);
    $agent = User::query()->findOrFail($jane->assigned_user_id);
    app(Inbox::class)->store($jane->contact()->firstOrFail(), 'email', 'Can we see it this weekend?', 'Re', null, true);

    $jane->refresh();
    expect($jane->score)->toBe(100)->and($jane->score_label)->toBe('hot')->and($jane->hot_alerted_at)->not->toBeNull()
        ->and($jane->score_reasons)->toContain('Pre-approved ($450k)')->toContain('Moving soon (next month)')->toContain('Asked to see a home')->toContain('Replied');
    $hot = fn (): int => Notification::sent($agent, LeadAlert::class)->filter(fn (LeadAlert $n): bool => str_starts_with($n->subject, 'Hot lead'))->count();
    Notification::assertSentTo($agent, LeadAlert::class, fn (LeadAlert $n): bool => $n->subject === 'Hot lead: Jane Doe (100)');
    expect($hot())->toBe(1);

    // Rescoring does not alert again.
    app(LeadScoring::class)->rescoreAll();
    expect($hot())->toBe(1);

    // Hot leads lead Today's 10.
    $top = app(TodaysTen::class)->for($agent)[0];
    expect($top['reason'])->toStartWith('Hot lead (100): ');
});

it('marks leads who already have an agent cold and old silent leads down', function (): void {
    $l = lead($this, ['name' => 'Has Agent', 'intent' => 'buy', 'property' => '1 Elm']);
    AiThread::query()->create(['lead_id' => $l->id, 'contact_id' => $l->contact_id, 'status' => 'handed_off', 'qualification' => ['has_agent' => 'yes, working with Pat']]);
    expect(app(LeadScoring::class)->score($l->refresh())->score_label)->toBe('cold')->and($l->score_reasons[0])->toBe('Already has an agent');

    $old = lead($this, ['name' => 'Old Silent', 'intent' => 'buy', 'property' => '2 Elm', 'message' => 'Please send me more information on this home.']);
    $this->travel(31)->days();
    expect(app(LeadScoring::class)->score($old->refresh())->score_reasons)->toContain('No reply in 30 days');
});

it('reports return on spend by source and channel, down to commission and company dollar', function (): void {
    $won = lead($this, ['name' => 'Won Deal', 'utm_source' => 'chatgpt.com']);
    lead($this, ['name' => 'Other One']);
    lead($this, ['name' => 'Third One', 'source' => 'facebook']);
    $db = DB::connection();
    $period = $db->table('comp_cap_periods')->insertGetId(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'agent_user_id' => $this->ann->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);
    $db->table('comp_cap_entries')->insert(['tenant_id' => $this->tenant->id, 'cap_period_id' => $period, 'calculation_id' => 991, 'company_dollar_cents' => 300000, 'post_cap_fee_cents' => 0, 'reason' => 'deal', 'created_at' => now()]);
    $this->travel(20)->days();
    $db->table('tx_deals')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'type' => 'buyer', 'status' => 'closed', 'agent_user_id' => $this->ann->id,
        'contact_id' => $won->contact_id, 'street' => '9 Elm', 'state_code' => 'TX', 'price_cents' => 50000000, 'gci_cents' => 1500000, 'closed_on' => '2026-07-05', 'calculation_id' => 991,
        'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->owner)->post('/leads/report/spend', ['month' => '2026-06', 'key' => 'zillow', 'amount' => '1000'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/leads/report/spend', ['month' => '2026-06', 'key' => 'channel:ai_search', 'amount' => '500'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/leads/report/spend', ['month' => '2026-06', 'key' => 'nope', 'amount' => '5'])->assertSessionHasErrors('key');
    $this->actingAs($this->ann)->post('/leads/report/spend', ['month' => '2026-06', 'key' => 'zillow', 'amount' => '5'])->assertSessionHasErrors('spend');

    $r = app(LeadReports::class)->returns(CarbonImmutable::parse('2026-06-01'), CarbonImmutable::now());
    $zillow = collect($r['sources'])->firstWhere('key', 'zillow');
    expect($zillow)->toMatchArray(['leads' => 2, 'closed' => 1, 'gci_cents' => 1500000, 'company_dollar_cents' => 300000, 'spend_cents' => 100000,
        'cost_per_lead_cents' => 50000, 'cost_per_closing_cents' => 100000, 'return' => 3.0])
        ->and(collect($r['channels'])->firstWhere('key', 'ai_search'))->toMatchArray(['leads' => 1, 'closed' => 1, 'company_dollar_cents' => 300000, 'spend_cents' => 50000, 'return' => 6.0])
        ->and($r['totals'])->toMatchArray(['leads' => 3, 'closed' => 1, 'spend_cents' => 150000, 'return' => 2.0]);

    $this->actingAs($this->owner)->get('/leads/report?days=90')->assertOk()->assertInertia(fn (Assert $page) => $page->component('crm/leads/report')->where('returns.totals.closed', 1));
    $this->actingAs($this->ann)->get('/leads/report')->assertForbidden();
});

it('scores agents on speed against the brokerage target and flags slow agents on the Risk page', function (): void {
    $this->actingAs($this->owner)->post('/leads/report/targets', ['target_minutes' => 5, 'target_percent' => 80])->assertSessionHasNoErrors();
    for ($i = 0; $i < 6; $i++) {
        $l = lead($this, ['name' => "Lead {$i}"]);
        $agent = User::query()->findOrFail($l->assigned_user_id);
        $this->travel($agent->id === $this->ann->id ? 2 : 30)->minutes();
        app(Timeline::class)->log($l->contact()->firstOrFail(), ['type' => 'call', 'outcome' => 'connected'], $agent);
    }
    for ($i = 0; $i < 4; $i++) { // Bob gets two more slow ones so he has 5+
        $l = lead($this, ['name' => "More {$i}"]);
        $agent = User::query()->findOrFail($l->assigned_user_id);
        $this->travel($agent->id === $this->ann->id ? 1 : 45)->minutes();
        app(Timeline::class)->log($l->contact()->firstOrFail(), ['type' => 'call', 'outcome' => 'connected'], $agent);
    }

    $card = app(LeadReports::class)->scorecard(CarbonImmutable::now()->subDays(30), CarbonImmutable::now());
    $byName = collect($card['agents'])->keyBy('name');
    expect($byName['Ann Agent'])->toMatchArray(['leads' => 5, 'contacted' => 5, 'within_target' => 100])
        ->and($byName['Bob Agent'])->toMatchArray(['leads' => 5, 'contacted' => 5, 'within_target' => 0]);

    $this->actingAs($this->owner)->get('/deals/risk')->assertInertia(fn (Assert $page) => $page
        ->where('risks', fn ($r) => collect($r)->contains(fn ($x) => $x['title'] === 'Bob Agent reached 0% of leads within 5 minutes in the last 30 days (target 80%).' && $x['level'] === 'high')
            && ! collect($r)->contains(fn ($x) => str_starts_with($x['title'], 'Ann Agent'))));
    $this->actingAs($this->owner)->post('/leads/report/targets', ['target_minutes' => 0, 'target_percent' => 80])->assertSessionHasErrors('targets');
});

it('shows scores on the leads page and sends the report page what it needs', function (): void {
    lead($this, ['name' => 'Jane Doe', 'intent' => 'sell']);
    $this->actingAs($this->owner)->get('/leads')->assertInertia(fn (Assert $page) => $page->where('leads.0.score_label', 'cold')->where('leads.0.score', 25)->where('leads.0.score_reasons.0', 'Wants to sell'));
    assertPageContract($this->actingAs($this->owner)->get('/leads/report'), 'crm/leads/report');
});
