<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Transactions\Policies\PolicyAnswers;
use Modules\Transactions\Policies\PolicyChangedMail;
use Modules\Transactions\Policies\PolicyLibrary;
use Modules\Transactions\Policies\PolicyQuestionMail;
use Modules\Transactions\Policies\PolicyReplyMail;
use Tests\Fixtures\FakeAiProvider;

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee']);
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    foreach ([PolicyAnswers::FEATURE => 'ai.policy_answer', PolicyLibrary::CHANGE_FEATURE => 'ai.policy_change'] as $feature => $credit) {
        AiFeatureRoute::query()->create(['feature_key' => $feature, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $credit]);
    }
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    $this->manual = "Dual agency\n\nAgents may not act for both buyer and seller without the broker's written approval and both clients' written consent.\n\n"
        ."Earnest money\n\nAgents must deliver earnest money to the title company within one business day of receiving it.";
});

it('lets the broker publish the manual, tells the agents, and tracks who acknowledged it', function (): void {
    $this->actingAs($this->agent)->post('/policies/documents', ['title' => 'Policy manual', 'text' => $this->manual])->assertForbidden();
    $this->actingAs($this->owner)->post('/policies/documents', ['title' => 'Policy manual', 'text' => $this->manual])->assertRedirect();
    Notification::assertSentTo($this->agent, PolicyChangedMail::class);
    $doc = (string) DB::table('tx_policy_docs')->value('ulid');
    $this->actingAs($this->agent)->get('/policies')->assertInertia(fn (Assert $page) => $page->component('policies/index')->where('broker', false)->where('toAcknowledge.0.title', 'Policy manual'));
    $this->actingAs($this->agent)->post("/policies/documents/{$doc}/acknowledge")->assertRedirect();
    $this->actingAs($this->agent)->get('/policies')->assertInertia(fn (Assert $page) => $page->where('toAcknowledge', []));
    $this->actingAs($this->owner)->get('/policies')->assertInertia(fn (Assert $page) => $page->where('documents.0.acknowledged', 1)->where('documents.0.waiting', []));

    // A new version: what changed is summarised and everyone acknowledges again.
    Notification::fake();
    $this->ai->respond(json_encode(['summary' => 'Earnest money now goes to title the same day.']));
    $this->actingAs($this->owner)->post('/policies/documents', ['title' => 'Policy manual', 'replaces' => $doc,
        'text' => str_replace('within one business day of receiving it', 'on the same day they receive it', $this->manual)])->assertRedirect();
    Notification::assertSentTo($this->agent, PolicyChangedMail::class, fn (PolicyChangedMail $m): bool => $m->summary === 'Earnest money now goes to title the same day.');
    expect(DB::table('tx_policy_docs')->where('ulid', $doc)->value('status'))->toBe('superseded');
    $this->actingAs($this->agent)->get('/policies')->assertInertia(fn (Assert $page) => $page->where('toAcknowledge.0.version', 2));
});

it('answers agents only from quoted sources, in their language', function (): void {
    $this->actingAs($this->owner)->post('/policies/documents', ['title' => 'Policy manual', 'text' => $this->manual]);
    $this->ai->respond(json_encode(['found' => true, 'answer' => 'Only with the broker\'s written approval and both clients\' written consent.',
        'citations' => [['source' => 'S1', 'quote' => "without the broker's written approval and both clients' written consent"]]]));
    $r = $this->actingAs($this->agent)->postJson('/policies/ask', ['question' => 'Can I do dual agency?'])->assertOk()->json();
    expect($r['status'])->toBe('answered')->and($r['sources'][0])->toMatchArray(['title' => 'Policy manual', 'page' => 1]);

    // A citation that is not in the source: no answer; the broker gets the question.
    $this->ai->respond(json_encode(['found' => true, 'answer' => 'Yes, always.', 'citations' => [['source' => 'S1', 'quote' => 'dual agency is always allowed']]]));
    $r = $this->actingAs($this->agent)->postJson('/policies/ask', ['question' => 'Is dual agency always allowed here?'])->json();
    expect($r['status'])->toBe('to_broker')->and($r['sources'])->toBe([]);
    Notification::assertSentTo($this->owner, PolicyQuestionMail::class);

    // Nothing in the documents mentions it: straight to the broker, no AI call.
    $before = count($this->ai->requests);
    $r = $this->actingAs($this->agent)->postJson('/policies/ask', ['question' => 'Pueden ir mascotas a la oficina?'])->json();
    expect($r['status'])->toBe('to_broker')->and(count($this->ai->requests))->toBe($before);
});

it('sends high-stakes questions to the broker, who replies; unanswered ones are the manual\'s gaps', function (): void {
    $this->actingAs($this->owner)->post('/policies/documents', ['title' => 'Policy manual', 'text' => $this->manual]);
    $before = count($this->ai->requests);
    $r = $this->actingAs($this->agent)->postJson('/policies/ask', ['question' => 'A client says they will sue me over the dual agency. What do I do?'])->json();
    expect($r['status'])->toBe('to_broker')->and(count($this->ai->requests))->toBe($before);

    $this->actingAs($this->owner)->get('/policies')->assertInertia(fn (Assert $page) => $page->where('broker', true)->where('gaps.0.ulid', $r['ulid'])->where('gaps.0.who', 'Pat Lee'));
    $this->actingAs($this->agent)->post("/policies/questions/{$r['ulid']}/reply", ['reply' => 'x'])->assertForbidden();
    $this->actingAs($this->owner)->post("/policies/questions/{$r['ulid']}/reply", ['reply' => 'Call me now; do not reply to the client in writing.'])->assertRedirect();
    Notification::assertSentTo($this->agent, PolicyReplyMail::class);
    $this->actingAs($this->owner)->post("/policies/questions/{$r['ulid']}/close")->assertRedirect();
    $this->actingAs($this->owner)->get('/policies')->assertInertia(fn (Assert $page) => $page->where('gaps', []));
    $this->actingAs($this->agent)->get('/policies')->assertInertia(fn (Assert $page) => $page->where('mine.0.reply', 'Call me now; do not reply to the client in writing.'));
});

it('finds the state rule packs as sources too', function (): void {
    $passages = PolicyAnswers::search('When is earnest money due in Texas?');
    expect($passages[0]['title'])->toBe('RightAlly state rules: Texas')->and($passages[0]['text'])->toContain('within 3 days of the contract');
});
