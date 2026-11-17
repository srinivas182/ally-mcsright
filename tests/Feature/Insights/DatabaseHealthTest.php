<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Brief\DailyBriefs;
use Modules\Crm\Health\DatabaseHealth;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\ContactScore;
use Modules\Insights\DatabaseReportMail;
use Modules\Insights\DatabaseValue;
use Modules\Transactions\Models\Deal;

/* DH1: database health with one-click fixes, commission forecast, database value and proof. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $this->bob = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Agent']);
});

/** @param array<string, mixed> $extra */
function dbHealthContact(User $owner, string $lifecycle, ?string $email, ?string $phone, string $source = 'manual', array $extra = []): Contact
{
    return Contact::query()->create(['first_name' => 'P'.str()->random(4), 'emails' => $email ? [['address' => $email, 'primary' => true]] : [],
        'phones' => $phone ? [['e164' => $phone, 'primary' => true]] : [], 'origin' => 'personal', 'source' => $source, 'lifecycle' => $lifecycle,
        'owner_user_id' => $owner->id, ...$extra]);
}

function dbHealthDeal(User $agent, string $status, int $gciDollars, ?string $closedOn, ?string $closing = null, ?int $contactId = null): Deal
{
    return Deal::query()->create(['type' => 'buyer', 'status' => $status, 'agent_user_id' => $agent->id, 'street' => str()->random(6).' Oak', 'state_code' => 'TX',
        'price_cents' => 40_000_000, 'gci_cents' => $gciDollars * 100, 'closed_on' => $closedOn, 'closing_date' => $closing ?? $closedOn, 'contact_id' => $contactId]);
}

it('scores the database, counts each problem, and links to the people behind it', function (): void {
    $lead = dbHealthContact($this->ann, 'lead', 'pat@example.com', null);
    dbHealthContact($this->ann, 'sphere', 'pat@example.com', null); // duplicate email
    $past = dbHealthContact($this->ann, 'past_client', null, null); // no way to reach, no closing date
    $active = dbHealthContact($this->ann, 'active', null, '+15125550101');
    dbHealthContact($this->bob, 'lead', 'bob-lead@example.com', null);
    $this->travel(40)->days();
    $active->forceFill(['last_activity_at' => now(), 'next_task_at' => now()->addDay()])->saveQuietly();

    $h = app(DatabaseHealth::class)->for($this->ann);
    expect(collect($h['checks'])->pluck('count', 'key')->all())->toEqual(['duplicates' => 1, 'unreachable' => 1, 'forgotten' => 3, 'no_next_step' => 1, 'missing_close_date' => 1])
        ->and($h['total'])->toBe(4)->and($h['to_fix'])->toBe(5)
        ->and($h['score'])->toBe(61); // 100 × (1 − (¼ + ¼ + 2·¾ + 2·¼ + ¼) ÷ 7)
    expect(app(DatabaseHealth::class)->for(null)['total'])->toBe(5);

    // Each fix opens the contacts behind it.
    $this->actingAs($this->ann)->get('/contacts?list=no-way-to-reach')->assertInertia(fn (Assert $page) => $page->where('contacts.data', fn ($rows): bool => collect($rows)->pluck('ulid')->all() === [$past->ulid]));
    $this->actingAs($this->ann)->get('/contacts?list=no-next-step')->assertInertia(fn (Assert $page) => $page->where('contacts.data', fn ($rows): bool => collect($rows)->pluck('ulid')->all() === [$lead->ulid]));
    $this->actingAs($this->ann)->get('/contacts?list=past-no-date')->assertInertia(fn (Assert $page) => $page->where('contacts.data', fn ($rows): bool => collect($rows)->pluck('ulid')->all() === [$past->ulid]));

    $item = collect(app(DailyBriefs::class)->for($this->ann))->firstWhere('key', 'database-health');
    expect($item['title'])->toBe('Your database is 61% healthy — fix 5 things')->and($item['url'])->toBe('/insights/database');
});

it('forecasts commission as a range with its reasons, from deals under contract, active buyers and likely sellers', function (): void {
    foreach (['2026-03-01', '2026-05-01', '2026-07-01'] as $d) {
        dbHealthDeal($this->ann, 'closed', 10_000, $d);
    }
    dbHealthDeal($this->ann, 'under_contract', 12_000, null, '2026-10-05'); // within 30 days
    dbHealthDeal($this->ann, 'under_contract', 8_000, null, '2026-12-01'); // within 90 days
    dbHealthContact($this->ann, 'active', 'a@example.com', null);
    $seller = dbHealthContact($this->ann, 'past_client', 'b@example.com', null);
    ContactScore::query()->create(['contact_id' => $seller->id, 'buyer' => 10, 'seller' => 80, 'timeframe' => '90d', 'reasons' => [], 'scored_at' => now()]);

    $f = app(DatabaseValue::class)->forecast($this->ann);
    // 30 days: $12k × 85% + 1 active × 10% × ⅓ × $10k + 1 likely × 2% × ⅓ × $10k = $10,200 + $333 + $67
    expect($f['average_gci_cents'])->toBe(1_000_000)
        ->and($f['buckets'][0])->toEqual(['days' => 30, 'low_cents' => 1_060_000, 'high_cents' => 1_200_000 + 100_000 + 26_667])
        ->and($f['buckets'][2])->toEqual(['days' => 90, 'low_cents' => 1_700_000 + 100_000 + 20_000, 'high_cents' => 2_000_000 + 300_000 + 80_000])
        ->and($f['reasons'][0])->toBe('2 deals under contract ($20,000 commission); 85% or more usually close.')
        ->and($f['reasons'])->toContain('1 likely seller in your database; 2–8% may list in 90 days.');
});

it('values the database from its own history when it has enough, and shows where the business came from', function (): void {
    $people = collect(range(1, 10))->map(fn (int $i): Contact => dbHealthContact($this->ann, $i <= 5 ? 'past_client' : 'sphere', "p{$i}@example.com", null, $i === 10 ? 'referral' : 'sphere'));
    $zillow = dbHealthContact($this->ann, 'lead', 'z@example.com', null, 'zillow');
    expect(app(DatabaseValue::class)->value($this->ann))->toMatchArray(['people' => 10, 'from_history' => false, 'rate' => 0.08, 'deals_a_year' => 0.8]);

    foreach (['2026-01-10', '2026-04-10', '2026-08-10', '2025-11-10'] as $i => $d) {
        dbHealthDeal($this->ann, 'closed', 9_000, $d, null, $people[$i]->id);
    }
    dbHealthDeal($this->ann, 'closed', 6_000, '2026-06-10', null, $zillow->id);
    $v = app(DatabaseValue::class)->value($this->ann);
    expect($v)->toMatchArray(['people' => 10, 'from_history' => true, 'rate' => 0.2, 'deals_a_year' => 2.0]) // 4 deals in 2 years ÷ 10 people
        ->and($v['basis'])->toContain('4 deals from past clients');

    $proof = collect(app(DatabaseValue::class)->proof($this->ann))->keyBy('group');
    expect($proof['Your database (past clients, sphere, referrals)'])->toMatchArray(['deals' => 4, 'gci_cents' => 3_600_000])
        ->and($proof['Paid portals and ads'])->toMatchArray(['deals' => 1, 'gci_cents' => 600_000]);
});

it('shows the page to agents and the broker, with each agent for the broker, and emails it on the 1st', function (): void {
    dbHealthContact($this->ann, 'lead', 'x@example.com', null);
    $this->actingAs($this->ann)->get('/insights/database')->assertInertia(fn (Assert $page) => $page->component('insights/database')->where('broker', false)->where('agents', [])
        ->where('report.health.total', 1));
    $this->actingAs($this->owner)->get('/insights/database')->assertInertia(fn (Assert $page) => $page->where('broker', true)->has('agents', 2)->where('agents.0.name', 'Ann Agent'));
    $this->actingAs(User::factory()->staff()->create())->get('/insights/database')->assertForbidden();

    Notification::fake();
    expect(app(DatabaseValue::class)->sendDue(CarbonImmutable::parse('2026-10-02')))->toBe(0)
        ->and(app(DatabaseValue::class)->sendDue(CarbonImmutable::parse('2026-10-01')))->toBe(2); // the owner and Ann; Bob has nobody yet
    Notification::assertSentTo($this->ann, DatabaseReportMail::class);
    Notification::assertNotSentTo($this->bob, DatabaseReportMail::class);
});
