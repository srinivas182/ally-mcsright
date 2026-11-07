<?php

declare(strict_types=1);

namespace Modules\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Tenancy\Enums\CellStatus;
use Modules\Core\Tenancy\Models\Cell;

/**
 * @extends Factory<Cell>
 */
final class CellFactory extends Factory
{
    protected $model = Cell::class;

    public function definition(): array
    {
        return [
            'name' => 'us-east-1-'.fake()->unique()->lexify('????'),
            'region' => 'us-east-1',
            'status' => CellStatus::Active,
            'db_connection' => 'cell',
            'capacity_tenants' => 2000,
            'capacity_agents' => 50000,
        ];
    }
}
