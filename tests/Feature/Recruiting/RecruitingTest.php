<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Core\Brief\DailyBriefs;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Recruiting\AgentDatabase;
use Modules\Recruiting\EarnHere;
use Modules\Recruiting\Models\MlsAgent;
use Modules\Recruiting\Models\Recruit;
use Modules\Recruiting\Pipeline;

/* RC1: the MLS agent database, the recruiting pipeline, alerts, and what an agent would earn here. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->agent = User::factory()->create();
    $this->source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $this->n = 0;
});

function recruitingLicence(object $t, array $scopes): void
{
    $auth = MlsAuthorization::query()->create(['tenant_id' => $t->tenant->id, 'mls_source_id' => $t->source->id, 'broker_mls_id' => 'B1', 'office_mls_ids' => ['OURS'],
        'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($auth, $scopes, null, null, User::factory()->hqStaff()->make());
}

/** A closed sale: listing agent (and office), buyer agent, price in dollars, closing date. */
function recruitingSale(object $t, ?string $lister, ?string $office, ?string $buyer, int $price, string $closed, string $status = 'Closed', ?string $contract = null): void
{
    $t->n++;
    Listing::query()->create(['mls_source_id' => $t->source->id, 'listing_key' => 'R'.$t->n, 'standard_status' => $status, 'street_number' => (string) $t->n, 'street_name' => 'Elm St',
        'city' => 'Austin', 'state_or_province' => 'TX', 'postal_code' => '78704', 'list_price_cents' => $price * 100, 'close_price_cents' => $status === 'Closed' ? $price * 100 : null,
        'close_date' => $status === 'Closed' ? $closed : null, 'listing_contract_date' => $contract ?? CarbonImmutable::parse($closed)->subDays(40)->toDateString(),
        'list_agent_mls_id' => $lister, 'list_agent_name' => $lister !== null ? ucfirst(strtolower($lister)).' Lister' : null, 'list_office_mls_id' => $office,
        'list_office_name' => $office !== null ? "{$office} Realty" : null, 'buyer_agent_mls_id' => $buyer, 'buyer_office_mls_id' => $buyer !== null ? 'BUYCO' : null,
        'modification_timestamp' => now()]);
}

function recruitingPlan(): void
{
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
}

it('builds the agent database from licensed MLS data only, leaving out the brokerage\'s own agents', function (): void {
    foreach (['2026-02-10', '2026-05-10', '2026-07-10'] as $d) {
        recruitingSale($this, 'ANN', 'KW', 'BEN', 500_000, $d);
    }
    recruitingSale($this, 'ANN', 'KW', null, 400_000, '2025-03-10'); // the year before
    recruitingSale($this, 'OUR1', 'OURS', null, 300_000, '2026-04-01');
    recruitingSale($this, 'ANN', 'KW', null, 600_000, '2026-08-01', 'Active');

    $db = app(AgentDatabase::class);
    recruitingLicence($this, ['idx', 'back_office']);
    expect($db->refresh())->toBe(0); // a back-office licence isn't for recruiting

    MlsAuthorization::query()->delete();
    recruitingLicence($this, ['idx', 'agent_production']);
    expect($db->refresh())->toBe(3);
    $ann = MlsAgent::query()->where('agent_mls_id', 'ANN')->sole();
    expect($ann->only(['name', 'office_mls_id', 'office_name', 'sides', 'list_sides', 'buyer_sides', 'volume_cents', 'sides_before', 'active_listings', 'ours']))
        ->toEqual(['name' => 'Ann Lister', 'office_mls_id' => 'KW', 'office_name' => 'KW Realty', 'sides' => 3, 'list_sides' => 3, 'buyer_sides' => 0,
            'volume_cents' => 150_000_000, 'sides_before' => 1, 'active_listings' => 1, 'ours' => false])
        ->and($ann->trend())->toBe(200)
        ->and(MlsAgent::query()->where('agent_mls_id', 'BEN')->sole()->displayName())->toBe('Agent BEN')
        ->and(MlsAgent::query()->where('agent_mls_id', 'OUR1')->sole()->ours)->toBeTrue();

    $this->actingAs($this->owner)->get('/recruiting/agents?sort=sides')->assertInertia(fn (Assert $page) => $page->component('recruiting/agents')->where('licensed', true)
        ->where('agents.data', fn ($rows): bool => collect($rows)->pluck('mls_id')->all() === ['ANN', 'BEN'])->where('settings.own_office_ids', ['OURS']));
    $this->actingAs($this->agent)->get('/recruiting/agents')->assertForbidden();
});

it('runs the pipeline: add from the MLS or by hand, stages, notes, next steps and the daily brief', function (): void {
    recruitingLicence($this, ['agent_production']);
    recruitingSale($this, 'ANN', 'KW', null, 500_000, '2026-05-10');
    app(AgentDatabase::class)->refresh();
    $ann = MlsAgent::query()->where('agent_mls_id', 'ANN')->sole();

    $this->actingAs($this->agent)->post("/recruiting/agents/{$ann->id}/recruit")->assertForbidden();
    $this->actingAs($this->owner)->post("/recruiting/agents/{$ann->id}/recruit")->assertRedirect();
    $this->actingAs($this->owner)->post("/recruiting/agents/{$ann->id}/recruit"); // once only
    $r = Recruit::query()->sole();
    expect($r->only(['name', 'brokerage', 'source', 'stage']))->toBe(['name' => 'Ann Lister', 'brokerage' => 'KW Realty', 'source' => 'mls', 'stage' => 'prospect'])
        ->and($r->next_step_on?->toDateString())->toBe('2026-09-02');

    $this->actingAs($this->owner)->post('/recruiting', ['name' => 'Sam Referral', 'phone' => '(512) 555-0100', 'source' => 'referral'])->assertRedirect();
    expect(Recruit::query()->where('name', 'Sam Referral')->sole()->phone)->toBe('+15125550100');

    $this->actingAs($this->owner)->post("/recruiting/{$r->ulid}/stage", ['stage' => 'meeting'])->assertRedirect();
    $this->actingAs($this->owner)->post("/recruiting/{$r->ulid}/note", ['kind' => 'call', 'body' => 'Coffee on Friday', 'next_step' => 'Coffee', 'next_step_on' => '2026-09-04']);
    expect($r->fresh()?->stage)->toBe('meeting')->and($r->fresh()?->next_step)->toBe('Coffee')
        ->and($r->notes()->pluck('body')->all())->toContain('To reach → Meeting set.', 'Coffee on Friday');

    expect(app(Pipeline::class)->dueCount())->toBe(0);
    $this->travel(4)->days();
    $item = collect(app(DailyBriefs::class)->for($this->owner))->firstWhere('key', 'recruiting-due');
    expect($item['title'])->toBe('2 recruits to follow up')->and(collect(app(DailyBriefs::class)->for($this->agent))->firstWhere('key', 'recruiting-due'))->toBeNull();

    $this->actingAs($this->owner)->post("/recruiting/{$r->ulid}/stage", ['stage' => 'not_now', 'reason' => 'Just renewed her plan']);
    expect($r->fresh()?->only(['stage', 'not_now_reason', 'next_step']))->toBe(['stage' => 'not_now', 'not_now_reason' => 'Just renewed her plan', 'next_step' => null]);
    $this->actingAs($this->owner)->get('/recruiting')->assertInertia(fn (Assert $page) => $page->component('recruiting/index')->has('recruits', 2));
});

it('alerts the recruiter when a recruit changes brokerage or their production jumps', function (): void {
    recruitingLicence($this, ['agent_production']);
    recruitingSale($this, 'ANN', 'KW', null, 500_000, '2026-05-10');
    recruitingSale($this, 'BEN', 'EXP', null, 500_000, '2025-05-10');
    app(AgentDatabase::class)->refresh();
    foreach (['ANN', 'BEN'] as $id) {
        app(Pipeline::class)->addFromMls(MlsAgent::query()->where('agent_mls_id', $id)->sole(), $this->owner);
    }

    $this->travel(1)->days();
    recruitingSale($this, 'ANN', 'CB', null, 450_000, '2026-09-30', 'Active', '2026-09-01'); // a new listing with a new office
    foreach (range(1, 4) as $i) {
        recruitingSale($this, 'BEN', 'EXP', null, 300_000, "2026-0{$i}-15");
    }
    app(AgentDatabase::class)->refresh();
    $alerts = app(Pipeline::class)->alerts();
    expect(collect($alerts)->pluck('message')->all())->toBe(['Ben Lister closed 4 sides in the last 12 months, up from 1.', 'Ann Lister moved from KW Realty to CB Realty. A good moment to check in.']);

    $ann = Recruit::query()->where('name', 'Ann Lister')->sole();
    expect($ann->brokerage)->toBe('CB Realty');
    $this->actingAs($this->owner)->get("/recruiting/{$ann->ulid}")->assertOk();
    expect(collect(app(Pipeline::class)->alerts())->pluck('name')->all())->toBe(['Ben Lister']); // seen once opened

    app(AgentDatabase::class)->refresh(); // no second production alert within 90 days
    expect(app(Pipeline::class)->alerts())->toHaveCount(1);
});

it('shows what a recruit would earn here, with the brokerage\'s real plan and every assumption', function (): void {
    recruitingPlan();
    recruitingLicence($this, ['agent_production']);
    foreach (['2026-02-10', '2026-05-10', '2026-07-10'] as $d) {
        recruitingSale($this, 'ANN', 'KW', null, 500_000, $d);
    }
    app(AgentDatabase::class)->refresh();
    $r = app(Pipeline::class)->addFromMls(MlsAgent::query()->where('agent_mls_id', 'ANN')->sole(), $this->owner);

    $e = app(EarnHere::class)->compare($r);
    // $500k × 2.5% = $12,500 a side; here 80% = $10,000; there (assumed 70%) = $8,750.
    expect($e)->toMatchArray(['plan' => '80/20', 'here_cents' => 3_000_000, 'there_cents' => 2_625_000, 'difference_cents' => 375_000, 'problem' => null])
        ->and($e['assumptions'][2])->toBe('Today: a guess of 70% to them (add what they told you), no cap.');

    $this->actingAs($this->owner)->put("/recruiting/{$r->ulid}", ['name' => 'Ann Lister', 'current_split' => 70, 'current_cap' => 5000, 'current_fee' => 100])->assertRedirect();
    $e = app(EarnHere::class)->compare($r->fresh() ?? $r);
    // There: company takes $3,750, then $1,250 (cap of $5,000 reached), then nothing; less $100 a deal.
    expect($e['there_cents'])->toBe(3_750_000 - 500_000 - 30_000)->and($e['assumptions'][2])->toBe('Today: they keep 70%, their brokerage takes at most $5,000 a year, $100 a deal in fees.');

    $sam = app(Pipeline::class)->add(['name' => 'Sam'], $this->owner);
    expect(app(EarnHere::class)->compare($sam)['problem'])->toBe('Enter how many deals they do a year and their average price.');
    $this->actingAs($this->owner)->get("/recruiting/{$sam->ulid}?deals=4&price=400000")->assertInertia(fn (Assert $page) => $page->component('recruiting/show')
        ->where('earn.here_cents', 4 * 800_000)->where('earn.deals', fn ($d): bool => count($d) === 4)->where('production', null));
});
