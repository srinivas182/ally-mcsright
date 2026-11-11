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
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRedTeamRun;
use Modules\AI\Models\AiRequest;
use Modules\AI\Support\CircuitBreaker;
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
            'providers' => AiProvider::query()->orderBy('name')->get()->map(fn (AiProvider $p): array => [
                'id' => $p->id, 'key' => $p->key, 'name' => $p->name, 'adapter' => $p->adapter, 'status' => $p->status,
                'zero_retention_confirmed' => $p->zero_retention_confirmed, 'no_training_confirmed' => $p->no_training_confirmed,
                'dpa_signed_at' => $p->dpa_signed_at?->toDateString(), 'usable' => $p->isUsable(), 'circuit_open' => $circuit->isOpen($p->key),
            ]),
            'models' => AiModel::query()->with('provider')->orderBy('display_name')->get()->map(fn (AiModel $m): array => [
                'id' => $m->id, 'name' => $m->display_name, 'model_id' => $m->model_id, 'provider' => $m->provider->name,
                'status' => $m->status, 'capabilities' => $m->capabilities,
                'input_cents' => $m->input_cost_per_mtok_cents, 'output_cents' => $m->output_cost_per_mtok_cents,
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
            'zero_retention_confirmed' => ['required', 'boolean'],
            'no_training_confirmed' => ['required', 'boolean'],
            'dpa_signed_at' => ['nullable', 'date'],
            'confirmation' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        if ($data['status'] === 'active' && (! $data['zero_retention_confirmed'] || ! $data['no_training_confirmed'])) {
            throw ValidationException::withMessages(['status' => 'A provider can only be active once zero retention and no training are confirmed.']);
        }

        $provider->update(Arr::except($data, ['confirmation']));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$provider->name} updated."]);

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

    public function toggleLock(AiFeatureRoute $route): RedirectResponse
    {
        $route->update(['locked' => ! $route->locked]);

        return back();
    }
}
