<?php

declare(strict_types=1);

namespace Modules\Core\Safeguards\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Safeguards\BrokerageExport;
use Modules\Core\Safeguards\BrokerageKeys;
use Modules\Core\Safeguards\BrokerageRestore;
use Modules\Core\Safeguards\DatabaseMoves;
use Modules\Core\Safeguards\DedicatedCells;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\CellRouting;
use Modules\Core\Tenancy\Models\Cell;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HQ → Tenants → a brokerage → Data (DS1): where its data lives, backups, and two-person restores. */
final class HqDataController
{
    public function show(Request $request, Tenant $tenant, CellRouting $routing): Response
    {
        $user = self::user($request);

        $moves = DB::connection(Connections::control())->table('brokerage_moves')->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(20)->get();
        $cells = Cell::query()->whereIn('id', $moves->pluck('to_cell_id')->merge($moves->pluck('from_cell_id'))->push((int) $tenant->getAttribute('cell_id')))->pluck('name', 'id');

        return Inertia::render('hq/tenants/data', [
            'tenant' => ['ulid' => $tenant->ulid, 'name' => $tenant->name, 'cell' => $tenant->cell?->name, 'connection' => $routing->connectionFor($tenant) ?? 'default',
                'dedicated' => (bool) $tenant->cell?->getAttribute('dedicated')],
            'exports' => BrokerageExport::list($tenant->id),
            'restores' => BrokerageRestore::list($tenant->id, $user),
            'password' => $request->session()->get('exportPassword'),
            'moves' => $moves->map(fn (\stdClass $m): array => ['ulid' => (string) $m->ulid, 'kind' => (string) $m->kind, 'status' => (string) $m->status,
                'from' => (string) ($cells[$m->from_cell_id] ?? ''), 'to' => (string) ($cells[$m->to_cell_id] ?? ''), 'step' => $m->step, 'progress' => (int) $m->progress,
                'plan' => json_decode((string) ($m->plan ?? 'null'), true), 'error' => $m->error, 'keep_until' => $m->keep_until !== null ? (string) $m->keep_until : null,
                'mine' => (int) $m->requested_by === $user->id, 'created_at' => (string) $m->created_at])->values(),
            'shared' => Cell::query()->where('dedicated', false)->where('status', 'active')->get(['id', 'name']),
            'keys' => app(TenantContext::class)->runAs($tenant, fn (): array => app(BrokerageKeys::class)->status()),
        ]);
    }

    /** A new dedicated database (connection details, never shown again) or an existing shared cell, then the plan. */
    public function planMove(Request $request, Tenant $tenant, DedicatedCells $cells, DatabaseMoves $moves): RedirectResponse
    {
        $data = $request->validate(['target' => ['required', 'in:dedicated,shared,drill'], 'cell' => ['nullable', 'integer'], 'driver' => ['nullable', 'in:mysql,mariadb,sqlite'],
            'host' => ['nullable', 'string', 'max:200'], 'port' => ['nullable', 'integer'], 'database' => ['nullable', 'string', 'max:200'], 'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:200'], 'region' => ['nullable', 'string', 'max:30']]);
        $to = $data['target'] === 'shared' ? Cell::query()->whereKey((int) ($data['cell'] ?? 0))->where('dedicated', false)->firstOrFail()
            : $cells->create($tenant, $data);
        $moves->ask($tenant, $to, $data['target'] === 'drill' ? 'drill' : 'move', self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Planned. A second HQ person must approve it.']);

        return back();
    }

    public function approveMove(Request $request, Tenant $tenant, string $move, DatabaseMoves $moves): RedirectResponse
    {
        $moves->approve($move, self::user($request));

        return back();
    }

    public function cancelMove(Request $request, Tenant $tenant, string $move, DatabaseMoves $moves): RedirectResponse
    {
        $moves->cancel($move, self::user($request));

        return back();
    }

    public function switchBack(Request $request, Tenant $tenant, string $move, DatabaseMoves $moves): RedirectResponse
    {
        $moves->switchBack($move, self::user($request));

        return back();
    }

    public function rotateKey(Request $request, Tenant $tenant, TenantContext $tenants, BrokerageKeys $keys): RedirectResponse
    {
        self::user($request);
        $n = $tenants->runAs($tenant, function () use ($keys): int {
            $keys->rotate();

            return $keys->rekey();
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => "New key made; {$n} value(s) re-sealed."]);

        return back();
    }

    public function export(Request $request, Tenant $tenant, BrokerageExport $exports): RedirectResponse
    {
        $exports->request($tenant, 'backup', self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Backup started. It appears here when it is ready.']);

        return back();
    }

    /** Download a backup; the ZIP password is shown once on the page. */
    public function download(Request $request, Tenant $tenant, string $export): StreamedResponse
    {
        $user = self::user($request);
        $e = DB::connection(Connections::control())->table('brokerage_exports')->where('ulid', $export)->where('tenant_id', $tenant->id)->where('status', 'ready')->first();
        abort_if($e === null || $e->path === null, 404);
        app(AuditLogger::class)->record('safeguards.export.downloaded', metadata: ['export' => $export, 'tenant' => $tenant->id, 'by' => $user->id], severity: 'notice');

        return Storage::disk('local')->download((string) $e->path, $tenant->slug.'-'.$e->kind.'-'.substr((string) $e->created_at, 0, 10).'.zip', ['Cache-Control' => 'no-store']);
    }

    public function password(Request $request, Tenant $tenant, string $export): RedirectResponse
    {
        $user = self::user($request);
        $e = DB::connection(Connections::control())->table('brokerage_exports')->where('ulid', $export)->where('tenant_id', $tenant->id)->where('status', 'ready')->first();
        abort_if($e === null || $e->password === null, 404);
        app(AuditLogger::class)->record('safeguards.export.password_shown', metadata: ['export' => $export, 'tenant' => $tenant->id, 'by' => $user->id], severity: 'notice');

        return back()->with('exportPassword', ['export' => $export, 'password' => Crypt::decryptString((string) $e->password)]);
    }

    public function ask(Request $request, Tenant $tenant, BrokerageRestore $restores): RedirectResponse
    {
        $data = $request->validate(['export' => ['required', 'string', 'size:26'], 'reason' => ['required', 'string', 'max:300'],
            'confirm' => ['required', 'string']]);
        if (trim($data['confirm']) !== $tenant->slug) {
            return back()->withErrors(['confirm' => 'Type the brokerage\'s short name ('.$tenant->slug.') to confirm.']);
        }
        $restores->ask($tenant, $data['export'], $data['reason'], self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Restore requested. A second HQ person must approve it.']);

        return back();
    }

    public function approve(Request $request, Tenant $tenant, string $restore, BrokerageRestore $restores): RedirectResponse
    {
        $restores->approve($restore, self::user($request));

        return back();
    }

    public function cancel(Request $request, Tenant $tenant, string $restore, BrokerageRestore $restores): RedirectResponse
    {
        $restores->cancel($restore, self::user($request));

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
