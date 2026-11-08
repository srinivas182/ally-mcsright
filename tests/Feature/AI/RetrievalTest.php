<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\AI\AiGateway;
use Modules\AI\Contracts\CreditMeter;
use Modules\AI\Data\AiTask;
use Modules\AI\Exceptions\AllModelsFailed;
use Modules\AI\Exceptions\ContextNotPermitted;
use Modules\AI\Exceptions\OutputSchemaViolation;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\VectorDocument;
use Modules\AI\Providers\ProviderRegistry;
use Modules\AI\Retrieval\AccessScope;
use Modules\AI\Retrieval\AccessScopeResolver;
use Modules\AI\Retrieval\IndexAccess;
use Modules\AI\Retrieval\ScopedRetriever;
use Modules\AI\Retrieval\VectorIndexer;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Tests\Fixtures\FakeAiProvider;
use Tests\Fixtures\FakeEmbeddings;
use Tests\Fixtures\ProbeContact;

beforeEach(function (): void {
    Schema::create('test_probe_contacts', function (Blueprint $table): void {
        $table->id();
        $table->ulid('ulid');
        $table->tenant();
        $table->string('name');
        $table->string('email');
        $table->text('notes');
        $table->timestamps();
    });

    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();
    [$this->agent, $this->otherAgent] = app(TenantContext::class)->runAs($this->tenantA, fn () => [
        User::factory()->create(), User::factory()->create(),
    ]);

    $embedder = AiProvider::factory()->create(['key' => 'embedder', 'adapter' => 'openai_compatible']);
    $backup = AiProvider::factory()->create(['key' => 'backup-embedder', 'adapter' => 'openai_compatible']);
    $model = AiModel::factory()->for($embedder, 'provider')->create(['model_id' => 'embed-1', 'capabilities' => ['embeddings']]);
    $backupModel = AiModel::factory()->for($backup, 'provider')->create(['model_id' => 'embed-2']);
    AiFeatureRoute::query()->create([
        'feature_key' => VectorIndexer::FEATURE,
        'primary_model_id' => $model->id,
        'fallback_model_ids' => [$backupModel->id],
        'credit_action_key' => 'ai.embed',
    ]);

    $this->embeddings = new FakeEmbeddings;
    $this->backupEmbeddings = new FakeEmbeddings;
    app(ProviderRegistry::class)->fakeEmbeddings('embedder', $this->embeddings);
    app(ProviderRegistry::class)->fakeEmbeddings('backup-embedder', $this->backupEmbeddings);

    $this->credits = new class implements CreditMeter
    {
        public int $charges = 0;

        public int $refunds = 0;

        public function charge(string $actionKey, ?int $userId, string $idempotencyKey): void
        {
            $this->charges++;
        }

        public function refund(string $idempotencyKey, string $reason): void
        {
            $this->refunds++;
        }
    };
    app()->instance(CreditMeter::class, $this->credits);
});

/** Create and index a contact note in a tenant, owned by a user. */
function indexNote(Tenant $tenant, string $notes, ?int $owner, array $teams = [], ?int $office = null, bool $sensitive = false): ProbeContact
{
    return app(TenantContext::class)->runAs($tenant, function () use ($notes, $owner, $teams, $office, $sensitive) {
        $contact = ProbeContact::query()->create(['name' => 'Contact', 'email' => 'c@example.com', 'notes' => $notes]);
        app(VectorIndexer::class)->index($contact, $notes, new IndexAccess($owner, $teams, $office, $sensitive));

        return $contact;
    });
}

/** @return list<string> passages retrieved for the question */
function ask(Tenant $tenant, string $question, ?User $as): array
{
    if ($as !== null) {
        test()->actingAs($as);
    }

    return app(TenantContext::class)->runAs($tenant, fn () => array_map(
        fn ($record) => $record->fields['passage'],
        app(ScopedRetriever::class)->retrieve('assistant.answer', $question),
    ));
}

it('indexes text into chunks with access metadata and replaces them on re-index', function (): void {
    $contact = indexNote($this->tenantA, str_repeat('Buyer wants a three bedroom home near good schools. ', 80), $this->agent->id, [3], 9);

    $chunks = app(TenantContext::class)->runAs($this->tenantA, fn () => VectorDocument::query()->get());
    expect($chunks->count())->toBeGreaterThan(1)
        ->and($chunks->first()?->owner_user_id)->toBe($this->agent->id)
        ->and($chunks->first()?->team_ids)->toBe([3])
        ->and($chunks->first()?->model_id)->toBe('embed-1');

    app(TenantContext::class)->runAs($this->tenantA, fn () => app(VectorIndexer::class)->index($contact, 'Short replacement note.', new IndexAccess($this->agent->id)));
    expect(app(TenantContext::class)->runAs($this->tenantA, fn () => VectorDocument::query()->count()))->toBe(1);
});

it('returns the most relevant passages first, for records the user owns', function (): void {
    indexNote($this->tenantA, 'Seller is relocating to Memphis in March and needs a fast closing.', $this->agent->id);
    indexNote($this->tenantA, 'Buyer prefers a pool and a large backyard for the dogs.', $this->agent->id);

    $passages = ask($this->tenantA, 'Which client has dogs and wants a backyard?', $this->agent);

    expect($passages[0])->toContain('backyard for the dogs');
});

it('never returns another agent\'s private records', function (): void {
    indexNote($this->tenantA, 'CANARY-AGENT-PRIVATE buyer budget is four hundred thousand.', $this->agent->id);

    expect(ask($this->tenantA, 'What is the buyer budget?', $this->otherAgent))->toBe([]);
});

it('never returns another tenant\'s records, even for identical text and user', function (): void {
    indexNote($this->tenantA, 'CANARY-TENANT-A seller will accept a lower offer.', $this->agent->id);
    indexNote($this->tenantB, 'Tenant B note about an offer.', $this->agent->id);

    $passages = ask($this->tenantB, 'CANARY-TENANT-A seller will accept a lower offer', $this->agent);

    expect(implode(' ', $passages))->not->toContain('CANARY-TENANT-A')
        ->and($passages)->toHaveCount(1);
});

it('retrieves nothing, and spends nothing, without a signed-in user', function (): void {
    indexNote($this->tenantA, 'Some note.', $this->agent->id);
    $before = $this->credits->charges;

    expect(ask($this->tenantA, 'note', null))->toBe([])
        ->and($this->credits->charges)->toBe($before);
});

it('applies team, office and sensitive rules from the resolved scope', function (AccessScope $scope, array $expected): void {
    indexNote($this->tenantA, 'alpha team listing note', 101, [7]);
    indexNote($this->tenantA, 'alpha office listing note', 102, [], 55);
    indexNote($this->tenantA, 'alpha sensitive listing note', 103, [7], 55, sensitive: true);
    app()->instance(AccessScopeResolver::class, new class($scope) implements AccessScopeResolver
    {
        public function __construct(private readonly AccessScope $scope) {}

        public function forFeature(string $featureKey): ?AccessScope
        {
            return $this->scope;
        }
    });

    $passages = app(TenantContext::class)->runAs($this->tenantA, fn () => array_map(
        fn ($r) => $r->fields['passage'],
        app(ScopedRetriever::class)->retrieve('assistant.answer', 'alpha listing note', 10),
    ));

    sort($passages);
    expect($passages)->toBe($expected);
})->with([
    'team' => [new AccessScope(999, 'team', teamIds: [7]), ['alpha team listing note']],
    'office' => [new AccessScope(999, 'office', officeIds: [55]), ['alpha office listing note']],
    'tenant without sensitive' => [new AccessScope(999, 'tenant'), ['alpha office listing note', 'alpha team listing note']],
    'tenant with sensitive' => [new AccessScope(999, 'tenant', includeSensitive: true), ['alpha office listing note', 'alpha sensitive listing note', 'alpha team listing note']],
]);

it('redacts personal data before text reaches the embedding provider', function (): void {
    indexNote($this->tenantA, 'Client SSN 123-45-6789 and card 4111 1111 1111 1111.', $this->agent->id);

    $sent = implode(' ', $this->embeddings->received);
    expect($sent)->not->toContain('123-45-6789')->not->toContain('4111 1111 1111 1111')->toContain('[REDACTED_SSN_1]');
});

it('refuses to index another tenant\'s record', function (): void {
    $contact = indexNote($this->tenantA, 'Tenant A note.', $this->agent->id);

    app(TenantContext::class)->runAs($this->tenantB, fn () => app(VectorIndexer::class)->index($contact, 'steal', new IndexAccess(null)));
})->throws(ContextNotPermitted::class);

it('never falls back to a different embedding model', function (): void {
    $this->embeddings->failWith = new ProviderUnavailable('down');

    expect(fn () => indexNote($this->tenantA, 'Some note.', $this->agent->id))->toThrow(ProviderUnavailable::class)
        ->and($this->backupEmbeddings->received)->toBe([])
        ->and($this->credits->refunds)->toBe(1);
});

it('refuses non-compliant embedding providers', function (): void {
    AiProvider::query()->where('key', 'embedder')->update(['no_training_confirmed' => false]);

    indexNote($this->tenantA, 'Some note.', $this->agent->id);
})->throws(AllModelsFailed::class);

it('rejects embeddings of inconsistent size and refunds', function (): void {
    $this->embeddings->brokenDimensions = 3;

    expect(fn () => app(TenantContext::class)->runAs($this->tenantA, fn () => app(AiGateway::class)->embed(VectorIndexer::FEATURE, ['one', 'two'], 'k')))
        ->toThrow(OutputSchemaViolation::class)
        ->and($this->credits->refunds)->toBe(1);
});

it('feeds only permitted passages into an AI answer', function (): void {
    indexNote($this->tenantA, 'Buyer wants a pool and a three car garage.', $this->agent->id);
    indexNote($this->tenantA, 'CANARY-OTHER-AGENT secret client note about a pool.', $this->otherAgent->id);

    $model = AiModel::factory()->create();
    AiFeatureRoute::query()->create(['feature_key' => 'diagnostics.ping', 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'x']);
    $llm = new FakeAiProvider;
    $llm->respond('The buyer wants a pool.');
    app(ProviderRegistry::class)->fake($model->provider->key, $llm);
    $this->actingAs($this->agent);

    app(TenantContext::class)->runAs($this->tenantA, function (): void {
        $records = app(ScopedRetriever::class)->retrieve('assistant.answer', 'What does the buyer want with a pool?');
        app(AiGateway::class)->run(new AiTask('diagnostics.ping', 'diagnostics.ping', 'answer-1', context: $records));
    });

    expect($llm->requests[0]->userMessage)->toContain('three car garage')->not->toContain('CANARY-OTHER-AGENT');
});
