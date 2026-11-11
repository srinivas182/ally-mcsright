<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Modules\AI\FeatureCatalog;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;

/**
 * Creates routes for declared AI features that have none (run on deploy). Existing routes are
 * never changed: HQ owns them once created.
 */
final class SyncFeatures extends Command
{
    protected $signature = 'ai:sync-features';

    protected $description = 'Create AI routes for newly declared features';

    public function handle(FeatureCatalog $catalog): int
    {
        $created = 0;
        foreach ($catalog->all() as $key => $feature) {
            if (AiFeatureRoute::query()->where('feature_key', $key)->exists()) {
                continue;
            }

            $model = AiModel::query()->where('status', 'active')->get()
                ->filter(fn (AiModel $m): bool => in_array($feature['capability'], $m->capabilities, true))
                ->sortBy(fn (AiModel $m): int => $m->input_cost_per_mtok_cents + $m->output_cost_per_mtok_cents)
                ->first();

            if ($model === null) {
                $this->warn("No active model can serve [{$key}] ({$feature['capability']}); add one in the HQ AI console.");

                continue;
            }

            AiFeatureRoute::query()->create(['feature_key' => $key, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $feature['credit_action']]);
            $this->info("Route created: {$key} → {$model->display_name}");
            $created++;
        }

        $this->info("{$created} route(s) created.");

        return self::SUCCESS;
    }
}
