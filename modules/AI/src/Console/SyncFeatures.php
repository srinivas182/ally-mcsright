<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Modules\AI\Models\AiModel;
use Modules\AI\Routing\FeatureRoutes;

/**
 * Creates routes for declared AI features that have none (run on deploy). Existing routes are
 * never changed: HQ owns them once created. HQ can also do this from HQ › AI gateway › Routes.
 */
final class SyncFeatures extends Command
{
    protected $signature = 'ai:sync-features {--main= : Model ID (e.g. claude-sonnet-5-5) to use for every feature it can serve}';

    protected $description = 'Create AI routes for newly declared features';

    public function handle(FeatureRoutes $routes): int
    {
        $main = null;
        if (is_string($this->option('main')) && $this->option('main') !== '') {
            $main = AiModel::query()->where('model_id', $this->option('main'))->where('status', 'active')->value('id');
            if ($main === null) {
                $this->error('No active model with that ID. Add it in HQ › AI gateway › Models first.');

                return self::FAILURE;
            }
        }
        $result = $routes->sync($main === null ? null : (int) $main);
        foreach ($result['created'] as $r) {
            $this->info("Route created: {$r['feature']} → {$r['model']}");
        }
        foreach ($result['missing'] as $m) {
            $this->warn("No active model can serve [{$m['feature']}] ({$m['capability']}); add one in the HQ AI console.");
        }
        $this->info(count($result['created']).' route(s) created.');

        return self::SUCCESS;
    }
}
