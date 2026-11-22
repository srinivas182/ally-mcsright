<?php

declare(strict_types=1);

namespace Modules\AI\Http\Controllers\Hq;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\AI\FeatureCatalog;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRedTeamRun;
use Modules\AI\Models\AiRequest;
use Modules\AI\Routing\FeatureRoutes;
use Modules\AI\Support\CircuitBreaker;
use Modules\AI\Support\ProviderKeyCheck;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\CrossTenant;

/**
 * HQ console for the AI gateway (specs/ai/01): providers with compliance confirmations, models,
 * feature routes, usage and cost across tenants, output-policy alerts and red-team results.
 */
#[CrossTenantAccess('HQ AI usage, cost and security dashboard across all tenants')]
final class AiConsoleController
{
    public function index(Request $request, CircuitBreaker $circuit): Response
    {
        $days = in_array((int) $request->query('days', 7), [7, 30], true) ? (int) $request->query('days', 7) : 7;
        $since = CarbonImmutable::now()->subDays($days);

        $usage = CrossTenant::query(AiRequest::class, $this)
            ->where('created_at', '>=', $since)
            ->selectRaw('feature_key, model_id, count(*) as requests, sum(case when status = ? then 0 else 1 end) as failures, sum(cost_microcents) as cost, avg(latency_ms) as latency, sum(case when output_policy_result = ? then 1 else 0 end) as blocked', ['ok', 'blocked'])
            ->groupBy('feature_key', 'model_id')
            ->orderByDesc('requests')
            ->get()
            ->map(fn ($row): array => [
                'feature' => (string) $row->getAttribute('feature_key'),
                'model' => $row->getAttribute('model_id'),
                'requests' => (int) $row->getAttribute('requests'),
                'failures' => (int) $row->getAttribute('failures'),
                'cost_cents' => intdiv((int) $row->getAttribute('cost'), 1_000_000),
                'latency_ms' => (int) round((float) $row->getAttribute('latency')),
                'blocked' => (int) $row->getAttribute('blocked'),
            ]);

        return Inertia::render('hq/ai/index', [
            'days' => $days,
            'presets' => collect(self::PROVIDER_PRESETS)->map(fn (array $p, string $k): array => ['key' => $k, 'name' => $p['name']])->values(),
            'capabilities' => self::CAPABILITIES,
            'unrouted' => count(array_diff(array_keys(app(FeatureCatalog::class)->all()), AiFeatureRoute::query()->pluck('feature_key')->all())), // HQ3
            'modelPresets' => self::MODEL_PRESETS, 'modelPresetsChecked' => self::MODEL_PRESETS_CHECKED,
            'providers' => AiProvider::query()->orderBy('name')->get()->map(fn (AiProvider $p): array => [
                'id' => $p->id, 'key' => $p->key, 'name' => $p->name, 'adapter' => $p->adapter, 'status' => $p->status,
                'retention' => $p->retention(), 'no_training_confirmed' => $p->no_training_confirmed,
                'dpa_signed_at' => $p->dpa_signed_at?->toDateString(), 'usable' => $p->isUsable(), 'circuit_open' => $circuit->isOpen($p->key),
                // AI1: never the key itself, only where it comes from and its last 4 characters.
                'key_source' => $p->keySource(), 'key_hint' => $p->keySource() === 'hq' ? $p->api_key_hint : null,
                'key_saved_at' => $p->key_saved_at?->toDateString(), 'key_checked_at' => $p->key_checked_at?->toDateString(),
            ]),
            'models' => AiModel::query()->with('provider')->orderBy('display_name')->get()->map(fn (AiModel $m): array => [
                'id' => $m->id, 'name' => $m->display_name, 'model_id' => $m->model_id, 'provider' => $m->provider->name,
                'status' => $m->status, 'capabilities' => $m->capabilities,
                'input_cents' => $m->input_cost_per_mtok_cents, 'output_cents' => $m->output_cost_per_mtok_cents,
                'cache_read_cents' => $m->cache_read_cost_per_mtok_cents, 'audio_per_minute_microcents' => $m->audio_cost_per_minute_microcents,
                'prices_checked_at' => $m->prices_checked_at?->toDateString(), 'provider_id' => $m->provider_id,
            ]),
            'routes' => AiFeatureRoute::query()->orderBy('feature_key')->get()->map(fn (AiFeatureRoute $r): array => [
                'id' => $r->id, 'feature' => $r->feature_key, 'primary_model_id' => $r->primary_model_id,
                'fallback_model_ids' => $r->fallback_model_ids, 'locked' => $r->locked, 'timeout_seconds' => $r->timeout_seconds,
            ]),
            'usage' => $usage,
            'redTeam' => AiRedTeamRun::query()->orderByDesc('id')->limit(5)->get()->map(fn (AiRedTeamRun $run): array => [
                'ulid' => $run->ulid, 'status' => $run->status, 'scenarios' => $run->scenarios_run,
                'followed' => $run->injections_followed, 'critical' => $run->critical_followed,
                'caught' => $run->blocked_by_policy, 'errors' => $run->errors,
                'cost_cents' => intdiv($run->cost_microcents, 1_000_000), 'started_at' => $run->started_at->toIso8601String(),
            ]),
        ]);
    }

    public function updateProvider(Request $request, AiProvider $provider): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'disabled'])],
            'retention' => ['required', Rule::in(['zero', 'standard', 'unconfirmed'])],
            'no_training_confirmed' => ['required', 'boolean'],
            'dpa_signed_at' => ['nullable', 'date'],
            'confirmation' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        if ($data['status'] === 'active' && ($data['retention'] === 'unconfirmed' || ! $data['no_training_confirmed'])) {
            throw ValidationException::withMessages(['status' => 'A provider can only be active once no training is confirmed and you have chosen what it keeps (zero or standard retention).']);
        }

        $before = ['status' => $provider->status, 'retention' => $provider->retention(), 'no_training' => $provider->no_training_confirmed];
        $provider->update(Arr::except($data, ['confirmation', 'retention']) + [
            'zero_retention_confirmed' => $data['retention'] === 'zero', 'standard_retention_accepted' => $data['retention'] === 'standard',
        ]);
        // AI2: who confirmed which data terms, and on what basis, is kept with the change.
        $after = ['status' => $provider->status, 'retention' => $provider->retention(), 'no_training' => $provider->no_training_confirmed];
        app(AuditLogger::class)->record('ai.provider.terms', $provider, changes: array_map(fn (string $k): array => ['old' => $before[$k], 'new' => $after[$k]], array_combine(array_keys($after), array_keys($after))), metadata: ['provider' => $provider->key, 'basis' => $data['confirmation']], severity: 'notice');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$provider->name} updated."]);

        return back();
    }

    /** HQ3: creates the missing routes, with the main model HQ picks for every feature it can serve. */
    public function syncRoutes(Request $request, FeatureRoutes $routes): RedirectResponse
    {
        $data = $request->validate(['main_model_id' => ['required', 'integer', Rule::exists(AiModel::class, 'id')->where('status', 'active')]]);
        $result = $routes->sync((int) $data['main_model_id']);
        app(AuditLogger::class)->record('ai.routes.created', metadata: ['created' => count($result['created']), 'main_model_id' => (int) $data['main_model_id']], severity: 'notice');
        $message = count($result['created']).' route(s) created.'.($result['missing'] === [] ? '' : ' '.count($result['missing']).' feature(s) still need a model that can do '.implode(' or ', array_unique(array_column($result['missing'], 'capability'))).'.');
        Inertia::flash('toast', ['type' => $result['missing'] === [] ? 'success' : 'warning', 'message' => $message]);

        return back();
    }

    public function updateRoute(Request $request, AiFeatureRoute $route): RedirectResponse
    {
        $data = $request->validate([
            'primary_model_id' => ['required', 'integer'],
            'fallback_model_ids' => ['present', 'array', 'max:3'],
            'fallback_model_ids.*' => ['integer', 'distinct'],
            'timeout_seconds' => ['required', 'integer', 'min:5', 'max:120'],
        ]);

        if ($route->locked) {
            throw ValidationException::withMessages(['route' => 'This route is locked. Unlock it first.']);
        }

        $ids = array_merge([(int) $data['primary_model_id']], array_map('intval', $data['fallback_model_ids']));
        if (in_array((int) $data['primary_model_id'], array_map('intval', $data['fallback_model_ids']), true)) {
            throw ValidationException::withMessages(['fallback_model_ids' => 'A fallback cannot be the primary model.']);
        }
        if (AiModel::query()->whereIn('id', $ids)->where('status', 'active')->count() !== count($ids)) {
            throw ValidationException::withMessages(['primary_model_id' => 'Choose active models only.']);
        }
        if (str_starts_with($route->feature_key, 'retrieval.') && $data['fallback_model_ids'] !== []) {
            throw ValidationException::withMessages(['fallback_model_ids' => 'Embedding routes never fall back: vectors from different models are not comparable.']);
        }

        $route->update([
            'primary_model_id' => (int) $data['primary_model_id'],
            'fallback_model_ids' => array_map('intval', $data['fallback_model_ids']),
            'timeout_seconds' => (int) $data['timeout_seconds'],
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Route {$route->feature_key} updated."]);

        return back();
    }

    /**
     * AI1: saves a provider's API key (encrypted; never shown again; password confirmed by the route), then checks
     * the provider accepts it. A key the provider refuses is not saved.
     */
    public function saveKey(Request $request, AiProvider $provider, ProviderKeyCheck $check): RedirectResponse
    {
        $key = trim((string) $request->validate([
            'api_key' => ['required', 'string', 'min:20', 'max:400', 'regex:/^[A-Za-z0-9._\-]+$/'],
        ], ['api_key.regex' => 'That does not look like an API key. Copy it again, without spaces.'])['api_key']);

        $problem = $check->check($provider, $key);
        if ($problem !== null) {
            throw ValidationException::withMessages(['api_key' => $problem.' The key was not saved.']);
        }

        $provider->forceFill([
            'api_key' => $key, 'api_key_hint' => mb_substr($key, -4), 'key_saved_at' => now(), 'key_checked_at' => now(),
        ])->save();
        app(AuditLogger::class)->record('ai.provider.key_saved', $provider, metadata: ['provider' => $provider->key], severity: 'warning');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$provider->name} accepted the key. It is saved and locked away."]);

        return back();
    }

    /** AI1: "Check connection" with the key in use. */
    public function checkKey(AiProvider $provider, ProviderKeyCheck $check): RedirectResponse
    {
        $problem = $check->check($provider);
        if ($problem === null) {
            $provider->forceFill(['key_checked_at' => now()])->save();
        }
        Inertia::flash('toast', $problem === null
            ? ['type' => 'success', 'message' => "{$provider->name} accepted the key."]
            : ['type' => 'error', 'message' => $problem]);

        return back();
    }

    /** AI1: removes the saved key (the server's .env key, if any, is used again). */
    public function removeKey(AiProvider $provider): RedirectResponse
    {
        $provider->forceFill(['api_key' => null, 'api_key_hint' => null, 'key_saved_at' => null, 'key_checked_at' => null])->save();
        app(AuditLogger::class)->record('ai.provider.key_removed', $provider, metadata: ['provider' => $provider->key], severity: 'warning');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$provider->name} key removed."]);

        return back();
    }

    /**
     * Known providers. Each one's API key is saved in this console (AI1); a key in the server's environment
     * (ANTHROPIC_API_KEY, OPENAI_API_KEY, GEMINI_API_KEY via config/services.php) is used when none is saved.
     */
    public const PROVIDER_PRESETS = [
        'anthropic' => ['name' => 'Anthropic (Claude)', 'adapter' => 'anthropic', 'base_url' => 'https://api.anthropic.com', 'credential_config_key' => 'services.anthropic.key'],
        'openai' => ['name' => 'OpenAI (ChatGPT)', 'adapter' => 'openai_compatible', 'base_url' => 'https://api.openai.com', 'credential_config_key' => 'services.openai.key'],
        'gemini' => ['name' => 'Google (Gemini)', 'adapter' => 'gemini', 'base_url' => 'https://generativelanguage.googleapis.com', 'credential_config_key' => 'services.gemini.key'],
    ];

    public const CAPABILITIES = ['chat', 'json', 'vision', 'transcribe', 'embed'];

    /**
     * AI2: the main models of each provider, to fill the "Add a model" form. Prices are US dollars per million tokens
     * (or per audio minute) as published on the provider's pricing page on the date shown; HQ checks them before saving,
     * and saving records that day as the check date. Haiku's price is for prompts up to 100,000 tokens.
     */
    public const MODEL_PRESETS_CHECKED = '2026-11-22';

    public const MODEL_PRESETS = [
        'anthropic' => [
            ['model_id' => 'claude-sonnet-5-5', 'display_name' => 'Claude Sonnet 5.5', 'note' => 'Main model: recommended for most features', 'capabilities' => ['chat', 'json', 'vision'], 'input' => 2, 'output' => 10, 'cache_read' => 0.10, 'audio' => null],
            ['model_id' => 'claude-haiku-5-5', 'display_name' => 'Claude Haiku 5.5', 'note' => 'Fast and low cost: short, simple tasks', 'capabilities' => ['chat', 'json', 'vision'], 'input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'audio' => null],
            ['model_id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5', 'note' => 'Most capable, higher cost: optional', 'capabilities' => ['chat', 'json', 'vision'], 'input' => 4, 'output' => 20, 'cache_read' => 0.20, 'audio' => null],
        ],
        'openai' => [
            ['model_id' => 'gpt-4o-mini-transcribe', 'display_name' => 'OpenAI speech to text', 'note' => 'Voice notes and the microphone', 'capabilities' => ['transcribe'], 'input' => 0, 'output' => 0, 'cache_read' => null, 'audio' => 0.003],
            ['model_id' => 'text-embedding-3-small', 'display_name' => 'OpenAI search (embeddings)', 'note' => 'Finding the right help and documents', 'capabilities' => ['embed'], 'input' => 0.02, 'output' => 0, 'cache_read' => null, 'audio' => null],
        ],
    ];

    /** Adds one of the known providers, switched off until its data-protection terms are confirmed. */
    public function storeProvider(Request $request): RedirectResponse
    {
        $key = (string) $request->validate(['preset' => ['required', Rule::in(array_keys(self::PROVIDER_PRESETS))]])['preset'];
        if (AiProvider::query()->where('key', $key)->exists()) {
            throw ValidationException::withMessages(['preset' => 'That provider is already added.']);
        }
        $provider = AiProvider::query()->create(['key' => $key, ...self::PROVIDER_PRESETS[$key], 'status' => 'disabled']);
        app(AuditLogger::class)->record('ai.provider.added', $provider, metadata: ['provider' => $key], severity: 'notice');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$provider->name} added. Confirm its data terms to switch it on."]);

        return back();
    }

    /** Adds a model, or updates its prices. Prices are in US dollars per million tokens (and per audio minute). */
    public function saveModel(Request $request, ?AiModel $model = null): RedirectResponse
    {
        $data = $request->validate([
            'provider_id' => [$model === null ? 'required' : 'prohibited', 'integer', Rule::exists(AiProvider::class, 'id')],
            'model_id' => [$model === null ? 'required' : 'prohibited', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'display_name' => ['required', 'string', 'max:120'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['string', Rule::in(self::CAPABILITIES)],
            'input_per_mtok' => ['required', 'numeric', 'min:0', 'max:1000'],
            'output_per_mtok' => ['required', 'numeric', 'min:0', 'max:1000'],
            'cache_read_per_mtok' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'audio_per_minute' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'status' => ['required', Rule::in(['active', 'disabled'])],
        ]);
        $values = [
            'display_name' => $data['display_name'],
            'capabilities' => array_values(array_unique($data['capabilities'])),
            'input_cost_per_mtok_cents' => (int) round((float) $data['input_per_mtok'] * 100),
            'output_cost_per_mtok_cents' => (int) round((float) $data['output_per_mtok'] * 100),
            'cache_read_cost_per_mtok_cents' => (int) round((float) ($data['cache_read_per_mtok'] ?? 0) * 100),
            'audio_cost_per_minute_microcents' => (int) round((float) ($data['audio_per_minute'] ?? 0) * 100_000_000),
            'status' => $data['status'],
            'prices_checked_at' => CarbonImmutable::today(),
        ];
        if ($model === null) {
            if (AiModel::query()->where('provider_id', $data['provider_id'])->where('model_id', $data['model_id'])->exists()) {
                throw ValidationException::withMessages(['model_id' => 'That model is already added.']);
            }
            $model = AiModel::query()->create($values + ['provider_id' => (int) $data['provider_id'], 'model_id' => $data['model_id'], 'context_window' => 0]);
            app(AuditLogger::class)->record('ai.model.added', $model, metadata: ['model' => $model->model_id], severity: 'notice');
        } else {
            if ($data['status'] === 'disabled' && AiFeatureRoute::query()->where('primary_model_id', $model->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'This model is the primary for a route. Move the route first.']);
            }
            $old = $model->only(['input_cost_per_mtok_cents', 'output_cost_per_mtok_cents', 'audio_cost_per_minute_microcents', 'status']);
            $model->update($values);
            app(AuditLogger::class)->record('ai.model.updated', $model, changes: ['prices' => ['old' => $old, 'new' => $model->only(array_keys($old))]], severity: 'notice');
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$model->display_name} saved."]);

        return back();
    }

    public function toggleLock(AiFeatureRoute $route): RedirectResponse
    {
        $route->update(['locked' => ! $route->locked]);

        return back();
    }
}
