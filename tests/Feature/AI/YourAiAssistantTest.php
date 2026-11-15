<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Modules\AI\Assistant\AssistantTurn;
use Modules\AI\Assistant\YourAiAssistant;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Connect\AppRegistry;
use Modules\Core\Audit\FlushAuditOutbox;
use Modules\Core\Connect\AppCatalog;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Tags;
use Modules\Crm\Consent;
use Modules\Crm\Models\GhlEnrolment;
use Modules\Crm\Models\Task;
use Tests\Fixtures\FakeAiProvider;

/* Sprint AI1: "Your AI Assistant" — one back-office assistant over every skill (RightAlly MCP tools) and connected
   app, with the person's own permissions; previews before any change; connect-and-carry-on. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->ann = User::factory()->create(['first_name' => 'Ann']);
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => YourAiAssistant::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.assistant']);
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
});

function says(object $t, array $step): void
{
    $t->ai->respond((string) json_encode($step + ['text' => '']));
}

it('answers from the person\'s own data, using a skill first', function (): void {
    Task::query()->create(['assigned_to' => $this->ann->id, 'type' => 'call', 'title' => 'Call Bob', 'priority' => 'normal', 'due_at' => now()->addHour(), 'created_by' => $this->ann->id]);
    says($this, ['step' => 'skill', 'skill' => 'my_tasks', 'arguments' => ['days' => 1]]);
    says($this, ['step' => 'answer', 'text' => 'You have one task today: Call Bob.']);
    $r = $this->actingAs($this->ann)->postJson('/assistant/ask', ['text' => 'What do I have today?', 'path' => '/agent', 'title' => 'Today'])->assertOk();
    expect(array_column($r->json('turns'), 'role'))->toBe(['person', 'assistant'])->and($r->json('turns.1.text'))->toBe('You have one task today: Call Bob.')
        ->and(AssistantTurn::query()->where('role', 'skill')->sole()->data['skill'])->toBe('my_tasks')
        ->and(AssistantTurn::query()->where('role', 'skill')->sole()->data['result'])->toContain('Call Bob');
    $this->actingAs($this->ann)->getJson('/assistant')->assertJsonPath('turns.1.text', 'You have one task today: Call Bob.');
    $this->actingAs($this->owner)->getJson('/assistant')->assertJsonPath('turns', []); // everyone sees only their own
    app(FlushAuditOutbox::class)->handle();
});

it('shows a preview before changing anything, and runs it once on "Do it"', function (): void {
    says($this, ['step' => 'skill', 'skill' => 'add_task', 'arguments' => ['title' => 'Send the CMA to Jane', 'due' => 'tomorrow']]);
    $r = $this->actingAs($this->ann)->postJson('/assistant/ask', ['text' => 'Remind me to send Jane the CMA tomorrow'])->assertOk();
    $card = $r->json('turns.1');
    expect($card['role'])->toBe('card')->and($card['status'])->toBe('waiting')->and($card['card']['type'])->toBe('preview')
        ->and(Task::query()->count())->toBe(0); // nothing yet

    $this->actingAs($this->owner)->postJson('/assistant/turns/'.$card['ulid'].'/do')->assertNotFound(); // not theirs
    $this->actingAs($this->ann)->postJson('/assistant/turns/'.$card['ulid'].'/do')->assertOk()->assertJsonPath('turns.0.status', 'done');
    expect(Task::query()->sole()->title)->toBe('Send the CMA to Jane');
    $this->actingAs($this->ann)->postJson('/assistant/turns/'.$card['ulid'].'/do')->assertUnprocessable();
    expect(Task::query()->count())->toBe(1);
});

it('offers to connect an app it needs, and carries on with the same question', function (): void {
    says($this, ['step' => 'connect', 'app' => 'gohighlevel', 'text' => 'GoHighLevel isn\'t connected yet.']);
    $r = $this->actingAs($this->ann)->postJson('/assistant/ask', ['text' => 'Add my spring sellers to my GoHighLevel campaign'])->assertOk();
    expect($r->json('turns.1.card'))->toMatchArray(['type' => 'connect', 'title' => 'GoHighLevel', 'page' => '/apps/gohighlevel',
        'question' => 'Add my spring sellers to my GoHighLevel campaign']);
});

it('puts contacts into a GoHighLevel campaign through the assistant, consent checked, after "Do it"', function (): void {
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('gohighlevel'), true, [], null, User::factory()->make(['id' => 999999]));
    Http::fake([
        'services.leadconnectorhq.com/contacts/search' => Http::response(['contacts' => []]),
        'services.leadconnectorhq.com/users/*' => Http::response(['users' => []]),
        'services.leadconnectorhq.com/workflows/*' => Http::response(['workflows' => [['id' => 'wf-1', 'name' => 'Spring seller nurture']]]),
        'services.leadconnectorhq.com/campaigns/*' => Http::response(['campaigns' => []]),
        'services.leadconnectorhq.com/contacts/upsert' => Http::response(['contact' => ['id' => 'g-1']]),
        'services.leadconnectorhq.com/contacts/*/workflow/*' => Http::response(['succeeded' => true]),
    ]);
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/apps/gohighlevel/connect', ['api_key' => 'pit-1', 'location_id' => 've9EPM428h8vShlRW1KT']);
    foreach (['Amy' => true, 'Oli' => false] as $name => $ok) {
        $c = app(ManageContacts::class)->create(['first_name' => $name, 'email' => strtolower($name).'@x.test', 'origin' => 'company'], $this->owner);
        app(Tags::class)->attach($c, 'Spring sellers');
        if (! $ok) {
            app(Consent::class)->setEmail($c, false, 'agent');
        }
    }
    says($this, ['step' => 'skill', 'skill' => 'gohighlevel_campaigns', 'arguments' => []]);
    says($this, ['step' => 'skill', 'skill' => 'gohighlevel_add_to_campaign', 'arguments' => ['target_type' => 'workflow', 'target_id' => 'wf-1', 'tag' => 'Spring sellers']]);
    $r = $this->actingAs($this->owner)->postJson('/assistant/ask', ['text' => 'Put my spring sellers in the spring seller nurture'])->assertOk();
    $card = collect($r->json('turns'))->firstWhere('role', 'card');
    expect($card['card']['preview'])->toMatchArray(['matching' => 2, 'will_add' => 1, 'left_out' => 1])->and(GhlEnrolment::query()->count())->toBe(0);

    $this->actingAs($this->owner)->postJson('/assistant/turns/'.$card['ulid'].'/do')->assertOk();
    expect(GhlEnrolment::query()->sole()->enrolled)->toBe(1);
});

it('says plainly when it isn\'t switched on, and keeps HQ staff out', function (): void {
    AiFeatureRoute::query()->delete();
    $this->actingAs($this->ann)->postJson('/assistant/ask', ['text' => 'Hi'])->assertOk()->assertJsonPath('turns.1.text', 'Your AI Assistant isn\'t switched on for your brokerage yet. Ask your broker or RightAlly support.');
    $this->actingAs(User::factory()->hqStaff()->create())->postJson('/assistant/ask', ['text' => 'Hi'])->assertForbidden();
});

it('forgets conversations after 30 days', function (): void {
    says($this, ['step' => 'answer', 'text' => 'Hello.']);
    $this->actingAs($this->ann)->postJson('/assistant/ask', ['text' => 'Hi'])->assertOk();
    $this->travel(31)->days();
    $this->artisan('ai:assistant-prune')->assertSuccessful();
    expect(AssistantTurn::query()->count())->toBe(0);
});
