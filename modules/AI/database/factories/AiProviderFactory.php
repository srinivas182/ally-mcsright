<?php

declare(strict_types=1);

namespace Modules\AI\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\AI\Models\AiProvider;

/**
 * @extends Factory<AiProvider>
 */
final class AiProviderFactory extends Factory
{
    protected $model = AiProvider::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->lexify('provider-????'),
            'name' => 'Test provider',
            'adapter' => 'anthropic',
            'base_url' => 'https://api.example.test',
            'credential_config_key' => null,
            'zero_retention_confirmed' => true,
            'no_training_confirmed' => true,
            'status' => 'active',
        ];
    }

    public function notCompliant(): self
    {
        return $this->state(['zero_retention_confirmed' => false]);
    }
}
