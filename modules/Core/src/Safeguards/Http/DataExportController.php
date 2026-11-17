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
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Safeguards\BrokerageExport;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Settings → Your data (DS1): the owner downloads everything the brokerage has in RightAlly. */
final class DataExportController
{
    public function show(Request $request, TenantContext $tenants): Response
    {
        self::owner($request);

        return Inertia::render('settings/data-export', ['exports' => BrokerageExport::list($tenants->id(), 'portable'), 'password' => $request->session()->get('exportPassword')]);
    }

    public function request(Request $request, TenantContext $tenants, BrokerageExport $exports): RedirectResponse
    {
        $user = self::owner($request);
        if (DB::connection(Connections::control())->table('brokerage_exports')->where('tenant_id', $tenants->id())->where('kind', 'portable')
            ->where('created_at', '>=', now()->subDay())->whereIn('status', ['queued', 'running', 'ready'])->exists()) {
            return back()->withErrors(['export' => 'You made an export in the last 24 hours. Download that one, or try again tomorrow.']);
        }
        $exports->request($tenants->current(), 'portable', $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'We are preparing your export. It appears here when it is ready.']);

        return back();
    }

    public function download(Request $request, string $export, TenantContext $tenants): StreamedResponse
    {
        $user = self::owner($request);
        $e = self::export($export, $tenants->id());
        app(AuditLogger::class)->record('safeguards.export.downloaded', metadata: ['export' => $export, 'by' => $user->id], severity: 'notice');

        return Storage::disk('local')->download((string) $e->path, $tenants->current()->slug.'-rightally-export-'.substr((string) $e->created_at, 0, 10).'.zip',
            ['Cache-Control' => 'no-store']);
    }

    public function password(Request $request, string $export, TenantContext $tenants): RedirectResponse
    {
        self::owner($request);
        $e = self::export($export, $tenants->id());

        return back()->with('exportPassword', ['export' => $export, 'password' => Crypt::decryptString((string) $e->password)]);
    }

    private static function export(string $ulid, int $tenantId): \stdClass
    {
        $e = DB::connection(Connections::control())->table('brokerage_exports')->where('ulid', $ulid)->where('tenant_id', $tenantId)->where('kind', 'portable')
            ->where('status', 'ready')->first();
        abort_if($e === null || $e->path === null || $e->password === null, 404);

        return $e;
    }

    private static function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind === UserKind::Owner, 403);

        return $user;
    }
}
