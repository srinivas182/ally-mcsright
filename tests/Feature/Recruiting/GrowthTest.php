<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Identity\Events\UserActivated;
use Modules\Core\Identity\Models\Invitation;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Recruiting\Models\MlsAgent;
use Modules\Recruiting\Models\Recruit;
use Modules\Recruiting\Pipeline;
use Modules\Recruiting\Referrals;
use Modules\Recruiting\Results;
use Modules\Recruiting\Retention;
use Modules\Transactions\Models\Deal;
use Tests\Fixtures\FakeAiProvider;

/* RC3: agents refer recruits (with a note they write themselves), inviting recruits in, keeping agents, and results. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    Mail::fake();
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive', 'last_name' => 'Owner']);
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
});

function growthDeal(User $agent, string $closed, int $gci = 10_000): void
{
    Deal::query()->create(['type' => 'buyer', 'status' => 'closed', 'agent_user_id' => $agent->id, 'street' => str()->random(6).' Elm', 'state_code' => 'TX',
        'price_cents' => 40_000_000, 'gci_cents' => $gci * 100, 'closed_on' => $closed, 'closing_date' => $closed])
        ->forceFill(['created_at' => CarbonImmutable::parse($closed)->subDays(45)])->save(); // opened about six weeks before closing
}

it('lets agents refer people they know, and see only how far it has gone', function (): void {
    $this->actingAs($this->ann)->get('/recruiting/refer')->assertInertia(fn (Assert $p) => $p->component('recruiting/refer')->where('suggestions', [])->where('database', false));
    $this->actingAs($this->ann)->post('/recruiting/refer', ['name' => 'Ben Broker', 'email' => 'Ben@X.test', 'note' => 'Top listing agent, unhappy with his split.'])->assertSessionHasNoErrors();
    $ben = Recruit::query()->sole();
    expect($ben->only(['source', 'sponsor_user_id', 'owner_user_id', 'email']))->toBe(['source' => 'referral', 'sponsor_user_id' => $this->ann->id, 'owner_user_id' => null, 'email' => 'ben@x.test'])
        ->and(collect(app(Pipeline::class)->alerts())->pluck('message')->all())->toBe(['Ann Agent referred Ben Broker.']);
    $this->actingAs($this->ann)->post('/recruiting/refer', ['name' => 'Ben again', 'email' => 'ben@x.test'])->assertSessionHasErrors('email');
    $this->actingAs($this->ann)->get("/recruiting/{$ben->ulid}")->assertForbidden(); // the pipeline stays with recruiters

    app(Pipeline::class)->move($ben, 'meeting', $this->owner);
    expect(app(Referrals::class)->mine($this->ann))->toBe([['name' => 'Ben Broker', 'stage' => 'Meeting set', 'since' => '2026-09-01']]);

    // With the broker's say-so, agents see producing agents to say who they know.
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://x.test', 'credentials' => ['token' => 't'], 'status' => 'active']);
    $auth = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($auth, ['agent_production'], null, null, User::factory()->hqStaff()->make());
    $kim = MlsAgent::query()->create(['mls_source_id' => $source->id, 'agent_mls_id' => 'KIM', 'name' => 'Kim Lister', 'office_name' => 'KW Realty', 'sides' => 9, 'sides_before' => 4, 'refreshed_at' => now()]);
    $this->actingAs($this->ann)->post('/recruiting/refer/database', ['on' => true])->assertForbidden();
    $this->actingAs($this->owner)->post('/recruiting/refer/database', ['on' => true]);
    expect(app(Referrals::class)->suggestions())->toBe([['id' => $kim->id, 'name' => 'Kim Lister', 'office' => 'KW Realty', 'sides' => 9, 'reason' => 'Growing: 9 closings, up from 4']]);
    $this->actingAs($this->ann)->post('/recruiting/refer', ['mls_agent' => $kim->id])->assertSessionHasNoErrors();
    expect(Recruit::query()->where('mls_agent_id', $kim->id)->sole()->sponsor_user_id)->toBe($this->ann->id)->and(app(Referrals::class)->suggestions())->toBe([]);
});

it('drafts a personal note for the agent to send themselves, with no money talk or links', function (): void {
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => Referrals::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.recruit_note']);
    $ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $ai);

    $ai->respond(json_encode(['message' => 'Hi Ben, it has been great working here. Fancy a coffee next week? https://example.test']));
    $this->actingAs($this->ann)->postJson('/recruiting/refer/draft', ['name' => 'Ben Broker'])->assertOk()
        ->assertJsonPath('message', 'Hi Ben, it has been great working here. Fancy a coffee next week? ');
    expect($ai->requests[0]->userMessage)->toContain('Ben Broker')->toContain('Ann Agent');

    $ai->respond(json_encode(['message' => 'You would keep 90% here and earn $40,000 more!']));
    $note = $this->actingAs($this->ann)->postJson('/recruiting/refer/draft', ['name' => 'Ben Broker'])->assertOk()->json('message');
    expect($note)->not->toContain('$')->not->toContain('%')->toContain('Happy to show you what you\'d keep here, with your own numbers.');
});

it('invites a recruit into RightAlly with their sponsor, and marks them joined when they accept', function (): void {
    $r = app(Pipeline::class)->add(['name' => 'Ben Broker'], $this->owner);
    $r->fill(['sponsor_user_id' => $this->ann->id])->save();
    app(Pipeline::class)->move($r, 'meeting', $this->owner);
    $this->actingAs($this->owner)->post("/recruiting/{$r->ulid}/invite")->assertSessionHasErrors('invite'); // no email yet
    $r->fill(['email' => 'ben@x.test'])->save();
    $this->actingAs($this->owner)->get("/recruiting/{$r->ulid}")->assertInertia(fn (Assert $p) => $p->where('joining.can_invite', true)->where('joining.sponsor', 'Ann Agent'));
    $this->actingAs($this->owner)->post("/recruiting/{$r->ulid}/invite")->assertSessionHasNoErrors();
    $invitation = Invitation::query()->sole();
    expect($invitation->sponsor_user_id)->toBe($this->ann->id)->and($r->fresh()?->stage)->toBe('offer');

    $ben = User::factory()->create(['email' => 'ben@x.test', 'first_name' => 'Ben']);
    event(new UserActivated($this->tenant->id, $ben->id, null));
    expect($r->fresh()?->only(['stage', 'user_id']))->toBe(['stage' => 'joined', 'user_id' => $ben->id])->and($r->fresh()?->joined_on?->toDateString())->toBe('2026-09-01');

    // Results: the referral source produced a hire who has closed $12,000 since; $3,000 spent.
    $this->travel(30)->days();
    growthDeal($ben, '2026-09-20', 12_000);
    growthDeal($ben, '2026-08-01', 99_000); // before joining: not counted
    $this->actingAs($this->owner)->post('/recruiting/results/spend', ['year' => 2026, 'source' => 'manual', 'amount' => 3000]);
    $row = collect(app(Results::class)->forYear(2026)['rows'])->firstWhere('source', 'manual');
    expect($row)->toMatchArray(['added' => 1, 'met' => 1, 'joined' => 1, 'gci_cents' => 1_200_000, 'spend_cents' => 300_000, 'cost_per_hire_cents' => 300_000]);
    $this->actingAs($this->owner)->get('/recruiting/results')->assertInertia(fn (Assert $p) => $p->component('recruiting/results')->where('year', 2026)->has('rows', 4));
});

it('shows the broker their own agents worth a check-in, with reasons, and quiets them for 60 days after a call', function (): void {
    $this->ann->forceFill(['created_at' => '2023-09-20', 'last_active_at' => '2026-07-01'])->save();
    foreach (['2025-01-10', '2025-03-10', '2025-05-10', '2025-07-10'] as $d) {
        growthDeal($this->ann, $d);
    }
    growthDeal($this->ann, '2026-02-10');
    $steady = User::factory()->create(['first_name' => 'Sue', 'created_at' => '2024-01-10', 'last_active_at' => now()]);

    $risks = app(Retention::class)->risks();
    expect($risks)->toHaveCount(1)->and($risks[0]['name'])->toBe('Ann Agent')->and($risks[0]['score'])->toBe(95)
        ->and($risks[0]['reasons'])->toBe(['Closed 1 in the last 12 months, down from 4 the year before.', 'Last signed in Jul 1, 2026.',
            '3 years here on Sep 20, 2026: a natural time to talk about their plan.', 'No new deal in the last 90 days.']);
    expect(collect(app(DailyBriefs::class)->for($this->owner))->firstWhere('key', 'recruiting-retention')['title'])->toBe('1 agent worth a check-in')
        ->and(collect(app(DailyBriefs::class)->for($this->ann))->firstWhere('key', 'recruiting-retention'))->toBeNull();

    $this->actingAs($this->ann)->get('/recruiting/retention')->assertForbidden();
    $this->actingAs($this->owner)->post("/recruiting/retention/{$this->ann->ulid}", ['note' => 'Coffee; wants more listing help.'])->assertRedirect();
    expect(app(Retention::class)->risks())->toBe([])->and($steady->id)->toBeInt();
    $this->travel(61)->days();
    expect(app(Retention::class)->risks())->toHaveCount(1);
});
