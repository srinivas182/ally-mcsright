<?php

declare(strict_types=1);

namespace Modules\Core\Scale\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Scale\Cells;
use Modules\Core\Tenancy\Enums\CellStatus;
use Modules\Core\Tenancy\Models\Cell;

/** HQ › Cells (PS1, specs/core/14): how full each cell is, adding a cell, and opening or closing one. */
final class CellsController
{
    public function index(Cells $cells): Response
    {
        return Inertia::render('hq/cells', ['cells' => $cells->usage(), 'warn' => Cells::WARN_PERCENT, 'reopen' => Cells::REOPEN_PERCENT]);
    }

    public function store(Request $request, Cells $cells): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'region' => ['required', 'string', 'max:30'], 'driver' => ['required', 'in:mysql,mariadb,sqlite'],
            'host' => ['nullable', 'string', 'max:190'], 'port' => ['nullable', 'integer', 'between:1,65535'], 'database' => ['required', 'string', 'max:190'],
            'username' => ['nullable', 'string', 'max:120'], 'password' => ['nullable', 'string', 'max:255'],
            'capacity_tenants' => ['nullable', 'integer', 'min:1'], 'capacity_agents' => ['nullable', 'integer', 'min:1']]);
        $cell = $cells->add(['name' => (string) $data['name'], 'region' => (string) $data['region'], 'driver' => (string) $data['driver'], 'host' => $data['host'] ?? null,
            'port' => $data['port'] ?? 3306, 'database' => (string) $data['database'], 'username' => $data['username'] ?? null, 'password' => $data['password'] ?? null,
            'capacity_tenants' => (int) ($data['capacity_tenants'] ?? 2000), 'capacity_agents' => (int) ($data['capacity_agents'] ?? 50000)], self::user($request));
        $r = $cells->prepare($cell);
        Inertia::flash('toast', ['type' => $r['ok'] ? 'success' : 'error', 'message' => $r['ok'] ? "{$cell->name} is ready and open to new brokerages." : "{$cell->name} was added but didn't pass its checks. See below."]);

        return back();
    }

    public function prepare(Cell $cell, Cells $cells): RedirectResponse
    {
        $r = $cells->prepare($cell);
        Inertia::flash('toast', ['type' => $r['ok'] ? 'success' : 'error', 'message' => $r['ok'] ? 'Checks passed.' : 'Checks failed. See below.']);

        return back();
    }

    public function status(Request $request, Cell $cell, Cells $cells): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:active,full,draining,maintenance']]);
        $cells->setStatus($cell, CellStatus::from((string) $data['status']), self::user($request));

        return back();
    }

    public function capacity(Request $request, Cell $cell, Cells $cells): RedirectResponse
    {
        $data = $request->validate(['capacity_tenants' => ['required', 'integer', 'min:1'], 'capacity_agents' => ['required', 'integer', 'min:1']]);
        $cells->setCapacity($cell, (int) $data['capacity_tenants'], (int) $data['capacity_agents'], self::user($request));

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
