<?php

declare(strict_types=1);

namespace Modules\Setup\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Setup\Imports\FileImports;
use Modules\Setup\Imports\Formats;

/** SU1 (specs/setup/01): bring everything from the old system's export files. Owners only. */
final class ImportController
{
    public function index(Request $request, TenantContext $tenants): Response
    {
        self::owner($request);
        $rows = DB::connection(Connections::cell())->table('setup_imports')->where('tenant_id', $tenants->id())->orderByDesc('id')->limit(30)->get();

        return Inertia::render('setup/import', [
            'imports' => $rows->map(fn ($r): array => ['ulid' => (string) $r->ulid, 'filename' => (string) $r->filename, 'label' => (string) $r->label,
                'kind' => (string) $r->kind, 'rows' => (int) $r->total_rows, 'status' => (string) $r->status, 'summary' => json_decode((string) ($r->summary ?? 'null'), true),
                'created_at' => (string) $r->created_at])->values()->all(),
            'kinds' => Formats::KINDS,
            'sources' => array_values(array_map(fn (array $s): string => $s['label'], Formats::SOURCES)),
        ]);
    }

    public function upload(Request $request, FileImports $imports): RedirectResponse
    {
        $user = self::owner($request);
        $data = $request->validate(['files' => ['required', 'array', 'min:1', 'max:10'], 'files.*' => ['file', 'max:10240'], 'kind' => ['nullable', 'in:people,agents,deals']]);
        $last = null;
        foreach ($data['files'] as $file) {
            $last = $imports->upload($file, $data['kind'] ?? null, $user);
        }

        return count($data['files']) === 1 && $last !== null ? to_route('setup.import.show', $last) : to_route('setup.import');
    }

    public function show(Request $request, string $import, FileImports $imports): Response
    {
        self::owner($request);
        $row = $imports->find($import);

        return Inertia::render('setup/import-show', [
            'import' => ['ulid' => (string) $row->ulid, 'filename' => (string) $row->filename, 'label' => (string) $row->label, 'kind' => (string) $row->kind,
                'source' => $row->source, 'rows' => (int) $row->total_rows, 'status' => (string) $row->status, 'ai_matched' => (bool) $row->ai_matched,
                'headers' => (array) json_decode((string) $row->headers, true), 'mapping' => (object) json_decode((string) $row->mapping, true),
                'summary' => json_decode((string) ($row->summary ?? 'null'), true), 'crm_import' => $row->crm_import_id !== null
                    ? DB::connection(Connections::cell())->table('crm_imports')->where('id', $row->crm_import_id)->value('ulid') : null,
                'can_undo' => $row->status === 'done' && $row->kind !== 'people' && $row->finished_at !== null && now()->subHours(FileImports::UNDO_HOURS)->lessThan((string) $row->finished_at)],
            'preview' => $row->status === 'reviewing' ? $imports->preview($row) : null,
            'kinds' => Formats::KINDS,
            'fields' => array_map(fn (array $f): array => array_keys($f), Formats::FIELDS),
        ]);
    }

    public function remap(Request $request, string $import, FileImports $imports): RedirectResponse
    {
        self::owner($request);
        $data = $request->validate(['kind' => ['required', 'in:people,agents,deals'], 'mapping' => ['present', 'array'], 'mapping.*' => ['nullable', 'string', 'max:40']]);
        $imports->remap($import, $data['kind'], $data['mapping']);

        return back();
    }

    public function confirm(Request $request, string $import, FileImports $imports): RedirectResponse
    {
        $summary = $imports->confirm($import, self::owner($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            isset($summary['handed_to_crm']) => 'Your people are coming in now. You can watch it on the next page.',
            isset($summary['invited']) => "{$summary['invited']} agent(s) invited.",
            default => ($summary['created'] ?? 0).' deal(s) brought in.',
        }]);

        return back();
    }

    public function undo(Request $request, string $import, FileImports $imports): RedirectResponse
    {
        $n = $imports->undo($import, self::owner($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Undone: {$n} item(s) taken back."]);

        return back();
    }

    private static function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind === UserKind::Owner, 403);

        return $user;
    }
}
