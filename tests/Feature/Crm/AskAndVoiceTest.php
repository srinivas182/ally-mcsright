<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Contracts\ProviderAdapter;
use Modules\AI\Contracts\TranscriptionAdapter;
use Modules\AI\Data\ProviderRequest;
use Modules\AI\Data\ProviderResponse;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRequest;
use Modules\AI\Providers\OpenAiCompatibleAdapter;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\AskDatabase;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\SmartList;
use Modules\Crm\Models\Task;
use Modules\Crm\VoiceUpdates;
use Tests\Fixtures\FakeAiProvider;

beforeEach(function (): void {
    $this->tenant = $this->onTenantHost('acme');
    $this->agent = User::factory()->create();
    $this->other = User::factory()->create();
    $this->model = AiModel::factory()->create(['capabilities' => ['chat', 'transcribe']]);
    foreach ([AskDatabase::FEATURE, VoiceUpdates::TRANSCRIBE, VoiceUpdates::LOG, VoiceUpdates::QUICK_ADD] as $feature) {
        AiFeatureRoute::query()->create(['feature_key' => $feature, 'primary_model_id' => $this->model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.x']);
    }
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($this->model->provider->key, $this->ai);
    $contacts = app(ManageContacts::class);
    $this->pat = $contacts->create(['first_name' => 'Pat', 'email' => 'pat@x.test'], $this->agent);
    $this->sam = $contacts->create(['first_name' => 'Sam', 'email' => 'sam@x.test'], $this->agent);
    $this->bea = $contacts->create(['first_name' => 'Bea', 'email' => 'bea@x.test'], $this->other);
    app(Timeline::class)->log($this->pat, ['type' => 'note', 'body' => 'Relocating for a job at the hospital in March, wants Fondren'], $this->agent);
    app(Timeline::class)->log($this->bea, ['type' => 'note', 'body' => 'Also relocating, another agent'], $this->other);
});

it('asks the database in plain language, searching notes, with a reason for each match', function (): void {
    $this->ai->respond(json_encode(['lifecycle' => ['lead', 'rich'], 'sources' => ['bogus'], 'keywords' => ['relocat', 'transfer'], 'explanation' => 'Leads who mentioned relocating']));

    $this->actingAs($this->agent)->get('/contacts/ask?q='.urlencode('who is relocating?'))->assertInertia(fn (Assert $page) => $page
        ->component('crm/contacts/ask')
        ->where('explanation', 'Leads who mentioned relocating')
        ->where('filters.lifecycle', ['lead'])
        ->missing('filters.source')
        ->has('results', 1)
        ->where('results.0.name', 'Pat')
        ->where('results.0.reason', fn (string $r) => str_contains($r, 'Relocating for a job at the hospital')));
});

it('saves an answer as a smart list that keeps working', function (): void {
    $this->actingAs($this->agent)->post('/smart-lists', ['name' => 'Relocating', 'filters' => ['keywords' => ['relocat']]])->assertRedirect();
    $list = SmartList::query()->sole();

    $this->actingAs($this->agent)->get("/contacts?list={$list->ulid}")->assertInertia(fn (Assert $page) => $page
        ->has('contacts.data', 1)->where('contacts.data.0.name', 'Pat'));
});

it('shows a friendly message when the assistant is unavailable', function (): void {
    AiFeatureRoute::query()->where('feature_key', AskDatabase::FEATURE)->delete();

    $this->actingAs($this->agent)->get('/contacts/ask?q=anything')->assertInertia(fn (Assert $page) => $page
        ->where('error', 'The AI assistant is unavailable right now. Please try again or type it in.'));
});

it('proposes an update from what was said, and changes nothing until Confirm', function (): void {
    $this->ai->respond(json_encode(['note' => 'Pre-approved for $340k, wants Saturday showings.', 'call_outcome' => 'connected', 'lifecycle' => 'active',
        'tags' => ['pre-approved'], 'task_title' => 'Book Saturday showings', 'task_due' => 'tomorrow']));
    $before = Activity::query()->count();

    $proposal = $this->actingAs($this->agent)->postJson("/contacts/{$this->pat->ulid}/voice-log/parse", ['text' => 'talked to pat pre approved 340 wants saturday'])
        ->assertOk()->json('proposal');
    expect($proposal['call_outcome'])->toBe('connected')->and(Activity::query()->count())->toBe($before)->and(Task::query()->count())->toBe(0);

    $proposal['task_title'] = 'Book Saturday showings at 10';
    $this->actingAs($this->agent)->post("/contacts/{$this->pat->ulid}/voice-log", $proposal)->assertSessionHasNoErrors();

    $pat = $this->pat->fresh();
    expect(Activity::query()->where('contact_id', $pat->id)->where('type', 'call')->value('body'))->toBe('Pre-approved for $340k, wants Saturday showings.')
        ->and($pat->lifecycle->value)->toBe('active')
        ->and($pat->tags()->pluck('name')->all())->toBe(['pre-approved'])
        ->and(Task::query()->where('contact_id', $pat->id)->value('title'))->toBe('Book Saturday showings at 10');

    $this->actingAs($this->other)->postJson("/contacts/{$this->pat->ulid}/voice-log/parse", ['text' => 'x'])->assertNotFound();
});

it('fills quick add from what was said', function (): void {
    $this->ai->respond(json_encode(['first_name' => 'Sam', 'last_name' => 'Ortiz', 'email' => '', 'phone' => '601-555-0123', 'source' => 'open_house', 'note' => 'Met at Elm St']));

    $this->actingAs($this->agent)->postJson('/contacts/quick-add/parse', ['text' => 'add sam ortiz 601 555 0123 met at elm street open house'])
        ->assertOk()->assertJsonPath('contact.last_name', 'Ortiz')->assertJsonPath('contact.source', 'open_house');
});

it('turns speech into text without keeping the audio or the transcript', function (): void {
    $speech = new class implements ProviderAdapter, TranscriptionAdapter
    {
        public function send(AiProvider $provider, ProviderRequest $request): ProviderResponse
        {
            throw new LogicException('not used');
        }

        public function transcribe(AiProvider $provider, string $modelId, string $audio, string $mimeType, ?string $language, int $timeoutSeconds): ProviderResponse
        {
            return new ProviderResponse('Buyers relocating to Fondren', 0, 0);
        }
    };
    app(ProviderRegistry::class)->fake($this->model->provider->key, $speech);

    $this->actingAs($this->agent)->post('/voice/transcribe', ['audio' => UploadedFile::fake()->createWithContent('speech.webm', str_repeat('a', 2048))->mimeType('audio/webm')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('text', 'Buyers relocating to Fondren');

    $log = AiRequest::query()->where('feature_key', VoiceUpdates::TRANSCRIBE)->sole();
    expect($log->status)->toBe('ok')->and(json_encode($log->toArray()))->not->toContain('Fondren');
});

it('sends audio to OpenAI-compatible transcription', function (): void {
    Http::fake(['https://api.test/v1/audio/transcriptions' => Http::response(['text' => 'Hello there', 'usage' => ['input_tokens' => 40, 'output_tokens' => 3]])]);
    $provider = AiProvider::factory()->make(['base_url' => 'https://api.test']);

    $response = app(OpenAiCompatibleAdapter::class)->transcribe($provider, 'gpt-4o-transcribe', 'AUDIO', 'audio/webm', 'en', 30);

    expect($response->text)->toBe('Hello there')->and($response->tokensIn)->toBe(40);
    Http::assertSent(fn (Request $r) => $r->isMultipart() && str_contains($r->url(), '/v1/audio/transcriptions'));
});
