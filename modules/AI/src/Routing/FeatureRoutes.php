<?php

declare(strict_types=1);

namespace Modules\AI\Routing;

use Modules\AI\FeatureCatalog;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;

/**
 * Creates a route for every declared AI feature that has none. Existing routes are never changed: HQ owns them once
 * created. HQ3: HQ can name the main model, used for every feature it can serve; other features (speech to text,
 * search) get the lowest-cost active model that can do them.
 */
final class FeatureRoutes
{
    public function __construct(private readonly FeatureCatalog $catalog) {}

    /**
     * @return array{created: list<array{feature: string, model: string}>, missing: list<array{feature: string, capability: string}>}
     */
    public function sync(?int $mainModelId = null): array
    {
        $active = AiModel::query()->where('status', 'active')->get();
        $main = $mainModelId === null ? null : $active->firstWhere('id', $mainModelId);
        $created = [];
        $missing = [];
        foreach ($this->catalog->all() as $key => $feature) {
            if (AiFeatureRoute::query()->where('feature_key', $key)->exists()) {
                continue;
            }
            $can = fn (AiModel $m): bool => in_array($feature['capability'], $m->capabilities, true);
            $model = $main instanceof AiModel && $can($main) ? $main
                : $active->filter($can)->sortBy(fn (AiModel $m): int => $m->input_cost_per_mtok_cents + $m->output_cost_per_mtok_cents)->first();
            if (! $model instanceof AiModel) {
                $missing[] = ['feature' => $key, 'capability' => $feature['capability']];

                continue;
            }
            AiFeatureRoute::query()->create(['feature_key' => $key, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $feature['credit_action']]);
            $created[] = ['feature' => $key, 'model' => $model->display_name];
        }

        return ['created' => $created, 'missing' => $missing];
    }
}
