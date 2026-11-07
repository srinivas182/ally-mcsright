<?php

declare(strict_types=1);

namespace Modules\AI\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\AI\Models\AiModel;
use Modules\AI\Models\AiProvider;

/**
 * @extends Factory<AiModel>
 */
final class AiModelFactory extends Factory
{
    protected $model = AiModel::class;

    public function definition(): array
    {
        return [
            'provider_id' => AiProvider::factory(),
            'model_id' => fake()->unique()->lexify('model-????'),
            'display_name' => 'Test model',
            'capabilities' => ['text', 'json'],
            'context_window' => 200000,
            'input_cost_per_mtok_cents' => 300,
            'output_cost_per_mtok_cents' => 1500,
            'cache_read_cost_per_mtok_cents' => 30,
            'status' => 'active',
        ];
    }
}
