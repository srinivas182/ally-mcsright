<?php

declare(strict_types=1);

namespace Modules\Transactions\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\DealAccess;
use Modules\Transactions\Jobs\BringOverFilesJob;

/**
 * Transactions → Bring over files (specs/connect/12): upload a SkySlope or Lone Wolf file export once; its PDFs are
 * filed into the imported deals by address. For the brokerage's deal reviewers (owner, staff, transactions.review).
 */
final class BringOverFilesController
{
    public function index(Request $request): Response
    {
        self::reviewer($request);

        return Inertia::render('deals/bring-over-files', [
            'imports' => DB::connection(Connections::cell())->table('tx_file_imports')->orderByDesc('id')->limit(20)->get()->map(fn (object $r): array => [
                'ulid' => $r->ulid, 'filename' => $r->filename, 'status' => $r->status, 'files' => (int) $r->files, 'filed' => (int) $r->filed, 'deals' => (int) $r->deals,
                'unmatched' => is_string($r->unmatched) ? (array) json_decode($r->unmatched, true) : [], 'error' => $r->error,
                'at' => (string) $r->created_at])->all(),
            'limits' => ['max_mb' => 500],
        ]);
    }

    public function store(Request $request, TenantContext $tenants): RedirectResponse
    {
        $user = self::reviewer($request);
        $request->validate(['file' => ['required', 'file', 'mimes:zip', 'max:512000']], ['file.mimes' => 'Upload the export as a .zip file.', 'file.max' => 'The ZIP can be up to 500 MB. Split bigger exports into parts.']);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $tenant = $tenants->id();
        $path = $file->storeAs("tx/{$tenant}/imports", Str::ulid().'.zip', 'local');
        $id = DB::connection(Connections::cell())->table('tx_file_imports')->insertGetId(['ulid' => (string) Str::ulid(), 'tenant_id' => $tenant,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 200), 'path' => (string) $path, 'status' => 'queued', 'created_by' => $user->id,
            'created_at' => now(), 'updated_at' => now()]);
        BringOverFilesJob::dispatch($id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Got it. The files are being filed into your deals; this page shows the result when it\'s done.']);

        return back();
    }

    private static function reviewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && DealAccess::reviewer($user), 403);

        return $user;
    }
}
