<?php

declare(strict_types=1);

namespace Modules\AI\Http\Controllers\Hq;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\AI\AiCost;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;

/**
 * HQ › AI cost (AQ2, specs/ai/08): what AI costs RightAlly by brokerage and by feature, what reused answers and
 * lighter models saved, the last 30 days day by day, and each feature's cost settings.
 */
#[CrossTenantAccess('HQ AI cost by brokerage and feature')]
final class AiCostController
{
    public function index(Request $request, AiCost $cost): Response
    {
        $days = in_array((int) $request->query('days', 30), [7, 30, 90], true) ? (int) $request->query('days', 30) : 30;
        $since = CarbonImmutable::now()->subDays($days)->startOfDay();

        return Inertia::render('hq/ai/cost', [
            'days' => $days,
            'summary' => $cost->summary($since, $this),
            'brokerages' => $cost->byBrokerage($since, $this),
            'features' => $cost->byFeature($since, $this),
            'daily' => $cost->daily(CarbonImmutable::now()->subDays(29)->startOfDay(), $this),
            'routes' => AiFeatureRoute::query()->orderBy('feature_key')->get()->map(fn (AiFeatureRoute $r): array => [
                'id' => $r->id, 'feature' => $r->feature_key, 'locked' => $r->locked,
                'light_model_id' => $r->getAttribute('light_model_id'), 'light_max_chars' => (int) $r->getAttribute('light_max_chars'),
                'cache_minutes' => (int) $r->getAttribute('cache_minutes'),
            ])->values()->all(),
            'models' => AiModel::query()->where('status', 'active')->orderBy('display_name')->get()->map(fn (AiModel $m): array => [
                'id' => $m->id, 'name' => $m->display_name, 'input_cents' => $m->input_cost_per_mtok_cents, 'output_cents' => $m->output_cost_per_mtok_cents,
            ])->values()->all(),
        ]);
    }

    public function updateRoute(Request $request, AiFeatureRoute $route): RedirectResponse
    {
        $data = $request->validate([
            'light_model_id' => ['nullable', 'integer'],
            'light_max_chars' => ['required', 'integer', 'min:0', 'max:200000'],
            'cache_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
        ]);
        if ($route->locked) {
            throw ValidationException::withMessages(['route' => 'This route is locked. Unlock it first.']);
        }
        if ($data['light_model_id'] !== null && ! AiModel::query()->whereKey((int) $data['light_model_id'])->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['light_model_id' => 'Choose an active model.']);
        }
        if (str_starts_with($route->feature_key, 'retrieval.') && $data['light_model_id'] !== null) {
            throw ValidationException::withMessages(['light_model_id' => 'Embedding routes always use one model: vectors from different models are not comparable.']);
        }
        $route->update(['light_model_id' => $data['light_model_id'] !== null ? (int) $data['light_model_id'] : null,
            'light_max_chars' => (int) $data['light_max_chars'], 'cache_minutes' => (int) $data['cache_minutes']]);
        app(AuditLogger::class)->record('hq.ai.cost_settings_changed', metadata: ['feature' => $route->feature_key] + $data, severity: 'notice');
        Inertia::flash('toast', ['type' => 'success', 'message' => "Cost settings for {$route->feature_key} saved."]);

        return back();
    }
}
