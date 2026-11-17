<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Billing\Credits\CreditLedger;
use Modules\Learn\Models\Article;
use Modules\Learn\Models\RolePlaySession;
use Modules\Learn\RolePlay;
use Modules\Learn\Scorecard;
use Tests\Fixtures\FakeAiProvider;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    foreach ([RolePlay::TURN => 'ai.roleplay', RolePlay::FEEDBACK => 'ai.roleplay_feedback', RolePlay::COACH => 'ai.coach'] as $f => $c) {
        AiFeatureRoute::query()->create(['feature_key' => $f, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $c]);
    }
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    app(CreditLedger::class)->add(null, 100, 'adjust', 'Test credits', $this->owner);
});

it('plays a made-up client, then scores the agent on the same checklist for everyone', function (): void {
    $this->actingAs($this->ann)->post('/learn/practice', ['kind' => 'practice', 'scenario' => 'listing_commission'])->assertRedirect();
    $s = RolePlaySession::query()->sole();
    expect($s->transcript[0]['who'])->toBe('client');

    $this->ai->respond(json_encode(['reply' => 'Fair, but one percent is a lot of money.']));
    $this->actingAs($this->ann)->post("/learn/practice/{$s->ulid}/reply", ['text' => 'I understand. What matters most to you in this sale?']);
    $this->actingAs($this->ann)->post("/learn/practice/{$s->ulid}/finish")->assertSessionHasErrors('text'); // too short to judge
    $this->ai->respond(json_encode(['reply' => 'Selling fast, honestly.']));
    $this->actingAs($this->ann)->post("/learn/practice/{$s->ulid}/reply", ['text' => 'Then let me show you how we sold three homes on your street in under two weeks.']);
    expect($this->ai->requests[1]->userMessage)->toContain('AGENT: I understand.');

    $this->ai->respond(json_encode(['rubric' => [
        ['item' => 'listened', 'met' => true, 'note' => 'You asked what mattered.'], ['item' => 'questions', 'met' => true],
        ['item' => 'next_step', 'met' => false, 'note' => 'No meeting booked.'], ['item' => 'invented', 'met' => true],
    ], 'went_well' => ['Good question about priorities'], 'try_next' => ['Ask for the listing'], 'better_line' => 'Shall we look at a plan on Thursday?']));
    $this->actingAs($this->ann)->post("/learn/practice/{$s->ulid}/finish");
    $s->refresh();
    expect($s->score)->toBe(40) // 2 of our 5 items; the made-up item is ignored
        ->and(array_column($s->feedback['rubric'], 'item'))->toBe(array_keys(RolePlay::RUBRIC))
        ->and($s->feedback['better_line'])->toBe('Shall we look at a plan on Thursday?');

    $this->actingAs($this->owner)->get("/learn/practice/{$s->ulid}")->assertNotFound(); // practice is private
    $this->actingAs($this->owner)->get('/learn/practice')->assertInertia(fn (Assert $p) => $p->where('team.0.name', 'Ann Agent')->where('team.0.average', 40));
    expect(collect(Scorecard::forUser($this->ann->id))->firstWhere('skill', 'listings')['practice'])->toBe(40);
});

it('gives one daily drill a day and counts the streak', function (): void {
    expect(RolePlay::todays($this->ann))->toBe(RolePlay::todays($this->ann))->toBeIn(array_keys(RolePlay::SCENARIOS));
    foreach ([2, 1, 0] as $daysAgo) {
        RolePlaySession::query()->create(['user_id' => $this->ann->id, 'kind' => 'daily', 'scenario' => 'fsbo', 'turns' => 3, 'score' => 80,
            'finished_at' => CarbonImmutable::now()->subDays($daysAgo)]);
    }
    expect(RolePlay::streak($this->ann))->toBe(3);
    $this->actingAs($this->ann)->get('/learn/practice')->assertInertia(fn (Assert $p) => $p->component('learn/practice')->where('streak', 3)->where('today.done', true));
    $this->travel(2)->days();
    expect(RolePlay::streak($this->ann))->toBe(0);
});

it('rehearses a real appointment from a short description', function (): void {
    $this->actingAs($this->ann)->post('/learn/practice', ['kind' => 'rehearsal', 'brief' => 'short'])->assertSessionHasErrors('brief');
    $this->actingAs($this->ann)->post('/learn/practice', ['kind' => 'rehearsal', 'brief' => 'Listing appointment with a retired couple who think the house is worth more.']);
    $s = RolePlaySession::query()->sole();
    $this->ai->respond(json_encode(['reply' => 'We were thinking 600 thousand.']));
    $this->actingAs($this->ann)->post("/learn/practice/{$s->ulid}/reply", ['text' => 'Tell me about the home and why you are moving.']);
    expect($this->ai->requests[0]->system.$this->ai->requests[0]->userMessage)->toContain('retired couple');
});

it('makes coaching cards from call notes and deal debriefs, and offers lessons to the office playbook', function (): void {
    $this->ai->respond(json_encode(['went_well' => ['You kept it short'], 'try_next' => ['Ask about timing'], 'next_step' => 'Send the market report',
        'lesson' => 'Always confirm the closing date with the lender a week early.']));
    $this->actingAs($this->ann)->post('/learn/practice/coach', ['kind' => 'debrief', 'text' => 'Closing nearly slipped because the lender was late with the appraisal. We kept in touch daily.'])
        ->assertRedirect();
    $s = RolePlaySession::query()->sole();
    expect($s->feedback['lesson'])->toBe('Always confirm the closing date with the lender a week early.');

    $this->actingAs($this->ann)->post("/learn/practice/{$s->ulid}/suggest");
    $page = Article::query()->sole();
    expect($page->status)->toBe('draft')->and($page->category)->toBe('Playbooks')->and($page->body)->toContain('Suggested by Ann Agent');
    $this->actingAs($this->ann)->get('/learn')->assertInertia(fn (Assert $p) => $p->where('knowledge', [])); // not visible until the broker publishes
});
