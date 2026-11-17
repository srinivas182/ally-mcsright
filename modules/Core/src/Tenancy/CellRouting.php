<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy;

use Closure;
use Modules\Core\Tenancy\Models\Cell;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * Per-request cell routing (DS1, specs/core/12). Whenever the current tenant changes (a request, a queued job, a
 * command running "as" a brokerage), the logical "cell" connection is pointed at that brokerage's cell. A cell
 * whose connection is defined (shared cells in config, dedicated databases registered from the control plane) is
 * used; otherwise the default cell connection stays, so a brokerage can never be routed to nowhere. The audit
 * connection follows the cell unless it is configured on its own.
 */
final class CellRouting
{
    /** The cell connection set in config at boot, used when no tenant is current or the cell has no connection. */
    private ?string $base = null;

    private bool $captured = false;

    /** @var array<int, string|null> cell id => connection name */
    private array $cells = [];

    /** @var list<Closure(Cell): ?string> registers a dedicated cell's connection on first use (DS2) */
    private array $resolvers = [];

    public function resolveWith(Closure $resolver): void
    {
        $this->resolvers[] = $resolver;
        $this->cells = [];
    }

    public function apply(?Tenant $tenant): void
    {
        if (! $this->captured) {
            $base = config('core.connections.cell');
            $this->base = is_string($base) && $base !== '' ? $base : null;
            $this->captured = true;
        }
        config(['core.connections.cell' => $tenant !== null ? ($this->connectionFor($tenant) ?? $this->base) : $this->base]);
    }

    /** The connection name a tenant's data lives on, or null for the default. */
    public function connectionFor(Tenant $tenant): ?string
    {
        $cellId = (int) $tenant->getAttribute('cell_id');
        if ($cellId === 0) {
            return $this->base;
        }
        if (! array_key_exists($cellId, $this->cells)) {
            $cell = Cell::query()->whereKey($cellId)->first();
            $name = null;
            if ($cell !== null) {
                foreach ($this->resolvers as $resolve) {
                    $name ??= $resolve($cell);
                }
                if ($name === null && is_array(config('database.connections.'.$cell->db_connection))) {
                    $name = $cell->db_connection;
                }
            }
            $this->cells[$cellId] = $name;
        }

        return $this->cells[$cellId] ?? $this->base;
    }

    /** Forget cached cells (after a move switches a brokerage's cell). */
    public function flush(): void
    {
        $this->cells = [];
    }
}
