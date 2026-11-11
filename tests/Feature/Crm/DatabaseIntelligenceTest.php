<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiBatchItem;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Pipelines;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\DatabaseIntelligence;
use Modules\Crm\Models\ContactScore;
use Modules\Crm\Models\Intent;
use Modules\Crm\Models\Stage;
use Modules\Crm\Models\Task;
use Modules\Crm\TodaysTen;
use Tests\Fixtures\FakeAiProvider;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 15:00:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->agent = User::factory()->create();
    $this->other = User::factory()->create();
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => DatabaseIntelligence::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.x']);
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    $this->contacts = app(ManageContacts::class);
    $this->intel = app(DatabaseIntelligence::class);
    $this->pat = $this->contacts->create(['first_name' => 'Pat', 'email' => 'pat@x.test', 'phone' => '6015550100'], $this->agent);
    app(Timeline::class)->log($this->pat, ['type' => 'note', 'body' => 'Renting now; relocating for a hospital job in March, wants to buy in Fondren'], $this->agent);
});

function intentsJson(array $intents): string
{
    return json_encode(['intents' => $intents]);
}

it('reads plans and dates from notes, keeps only valid ones, encrypted, without duplicates', function (): void {
    $this->ai->respond(intentsJson([
        ['kind' => 'relocate', 'summary' => 'Relocating for a hospital job', 'when' => '2027-03', 'confidence' => 'high'],
        ['kind' => 'lottery', 'summary' => 'Wants to win', 'when' => ''],
        ['kind' => 'buy', 'summary' => 'Wants a safe neighborhood for kids', 'when' => ''],
    ]));

    $this->actingAs($this->agent)->post("/contacts/{$this->pat->ulid}/analyse")->assertSessionHasNoErrors();

    $intent = Intent::query()->sole();
    expect($intent->kind)->toBe('relocate')->and($intent->due_on?->toDateString())->toBe('2027-03-01')
        ->and(DB::table('crm_intents')->value('summary'))->not->toContain('hospital');

    $this->ai->respond(intentsJson([['kind' => 'relocate', 'summary' => 'Relocating for a hospital job', 'when' => '2027-03', 'confidence' => 'high']]));
    $this->intel->analyseNow($this->pat, $this->agent);
    expect(Intent::query()->count())->toBe(1)
        ->and(ContactScore::query()->sole()->buyer)->toBeGreaterThanOrEqual(40)
        ->and(ContactScore::query()->sole()->reasons[0])->toContain('Relocating for a hospital job');

    $this->actingAs($this->other)->post("/contacts/{$this->pat->ulid}/analyse")->assertNotFound();
});

it('scores with plain reasons: active buyers, new leads, owners due to move; deals in progress are not prospects', function (): void {
    app(Pipelines::class)->all();
    $active = $this->contacts->create(['first_name' => 'Ava', 'email' => 'ava@x.test'], $this->agent);
    app(Pipelines::class)->move($active, Stage::query()->where('name', 'Showing')->firstOrFail(), $this->agent);
    $owner = $this->contacts->create(['first_name' => 'Ole', 'email' => 'ole@x.test'], $this->agent);
    $owner->forceFill(['lifecycle' => 'past_client', 'closed_on' => '2020-06-01', 'created_at' => now()->subYears(6)])->save();
    $contract = $this->contacts->create(['first_name' => 'Cy', 'email' => 'cy@x.test'], $this->agent);
    Intent::query()->create(['contact_id' => $contract->id, 'kind' => 'buy', 'summary' => 'Buying now', 'confidence' => 'high']);
    $contract->forceFill(['lifecycle' => 'under_contract'])->save();

    $a = $this->intel->score($active->fresh());
    $o = $this->intel->score($owner->fresh());
    $c = $this->intel->score($contract->fresh());

    expect($a->buyer)->toBeGreaterThanOrEqual(25)->and($a->reasons)->toContain('Actively searching')->and($a->timeframe)->toBe('now')
        ->and($o->seller)->toBe(20)->and($o->reasons[0])->toBe('Bought 6 years ago, a common time to move')
        ->and($c->buyer)->toBeLessThanOrEqual(10);
});

it('turns plans coming due into a follow-up task, once', function (): void {
    Intent::query()->create(['contact_id' => $this->pat->id, 'kind' => 'lease_end', 'summary' => 'Lease ends December 5', 'due_on' => '2026-12-05', 'confidence' => 'high']);
    Intent::query()->create(['contact_id' => $this->pat->id, 'kind' => 'buy', 'summary' => 'Maybe buy in 2028', 'due_on' => '2028-01-01']);

    expect($this->intel->createAlerts())->toBe(1)->and($this->intel->createAlerts())->toBe(0)
        ->and(Task::query()->where('contact_id', $this->pat->id)->value('title'))->toBe('Follow up: Lease ends December 5');
});

it('ranks Today\'s 10 by what matters, drops people already spoken to, and stays private', function (): void {
    $lead = $this->contacts->create(['first_name' => 'Nia', 'email' => 'nia@x.test'], $this->agent);
    $overdue = $this->contacts->create(['first_name' => 'Olu', 'email' => 'olu@x.test'], $this->agent);
    app(Tasks::class)->create($overdue, ['title' => 'Send the CMA', 'due_at' => '2026-11-20 10:00'], $this->agent);
    $this->contacts->create(['first_name' => 'Bea', 'email' => 'bea@x.test'], $this->other);
    $this->pat->forceFill(['last_activity_at' => now()->subDays(3)])->save();

    $picks = app(TodaysTen::class)->for($this->agent);
    // Pat (a lead from this week, spoken to 3 days ago) also scores as a likely buyer, ranked third.
    expect(array_column($picks, 'name'))->toBe(['Olu', 'Nia', 'Pat'])
        ->and($picks[0]['reason'])->toBe('Overdue: Send the CMA')
        ->and($picks[1]['reason'])->toBe('New lead, not contacted yet')
        ->and($picks[2]['reason'])->toStartWith('Likely buyer (30)');

    app(Timeline::class)->log($overdue, ['type' => 'call', 'outcome' => 'connected'], $this->agent);
    expect(array_column(app(TodaysTen::class)->for($this->agent), 'name'))->toBe(['Nia', 'Pat']);

    $this->actingAs($this->agent)->get('/today')->assertInertia(fn (Assert $page) => $page->component('crm/today')->where('picks.0.name', 'Nia'));
    $this->actingAs($this->agent)->get('/contacts/work?list=today')->assertInertia(fn (Assert $page) => $page
        ->where('contact.name', 'Nia')->where('reason', 'New lead, not contacted yet'));
    $this->actingAs($this->agent)->get("/contacts/work?list=today&skip={$lead->id}")->assertInertia(fn (Assert $page) => $page->where('contact.name', 'Pat'));
    $this->actingAs($this->agent)->get("/contacts/work?list=today&skip={$lead->id},{$this->pat->id}")->assertInertia(fn (Assert $page) => $page->where('contact', null));
});

it('runs overnight: scores everyone and reads new notes at batch price', function (): void {
    $this->ai->respond(intentsJson([['kind' => 'relocate', 'summary' => 'Relocating in March', 'when' => '2027-03', 'confidence' => 'medium']]));

    $this->artisan('crm:intelligence')->assertSuccessful();

    expect(Intent::query()->where('contact_id', $this->pat->id)->count())->toBe(1)
        ->and($this->pat->fresh()?->getAttribute('analysed_at'))->not->toBeNull()
        ->and(ContactScore::query()->where('contact_id', $this->pat->id)->exists())->toBeTrue()
        ->and(AiBatchItem::query()->whereNotNull('result')->count())->toBe(0);

    $intent = Intent::query()->sole();
    $this->actingAs($this->agent)->post("/intents/{$intent->id}", ['status' => 'dismissed'])->assertSessionHasNoErrors();
    expect($intent->fresh()?->status)->toBe('dismissed');
    $this->actingAs($this->other)->post("/intents/{$intent->id}", ['status' => 'open'])->assertNotFound();
});
