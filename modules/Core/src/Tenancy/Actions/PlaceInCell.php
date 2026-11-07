<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Actions;

use Modules\Core\Tenancy\Enums\CellStatus;
use Modules\Core\Tenancy\Exceptions\NoCellAvailable;
use Modules\Core\Tenancy\Models\Cell;

/**
 * Chooses the cell for a new tenant: the given cell if it is active and has room, otherwise the
 * active cell with the most spare tenant capacity.
 */
final class PlaceInCell
{
    public function handle(?int $cellId = null): Cell
    {
        $cells = Cell::query()
            ->where('status', CellStatus::Active)
            ->when($cellId !== null, fn ($q) => $q->whereKey($cellId))
            ->withCount('tenants')
            ->get()
            ->filter(fn (Cell $cell): bool => $cell->tenants_count < $cell->capacity_tenants)
            ->sortByDesc(fn (Cell $cell): int => $cell->capacity_tenants - (int) $cell->tenants_count);

        return $cells->first() ?? throw new NoCellAvailable(
            $cellId === null ? 'No active cell has spare capacity.' : "Cell {$cellId} is not active or is full.",
        );
    }
}
