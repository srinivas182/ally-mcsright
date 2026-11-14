<?php

declare(strict_types=1);

namespace Modules\Forms\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\Connections;
use Modules\Forms\Library\Importer;
use Modules\Forms\Library\Libraries;
use Modules\Forms\Templates;

/** Association form libraries and imports from other systems (specs/forms/01, part 5c). */
final class LibraryController
{
    public function index(Request $request, Libraries $libraries): Response
    {
        $user = self::user($request);
        abort_unless(Templates::manages($user), 403);
        $db = DB::connection(Connections::cell());
        $compare = $request->integer('compare');

        return Inertia::render('forms/libraries', [
            'available' => collect(Libraries::available())->map(fn ($l, string $key): array => ['key' => $key, 'name' => $l->name(), 'needs_member' => $l->needsMembership()])->values(),
            'libraries' => $db->table('form_libraries')->orderBy('name')->get()->map(fn ($l): array => [
                'ulid' => (string) $l->ulid, 'name' => (string) $l->name, 'member_id' => $l->member_id !== null ? '•••••'.substr((string) $l->member_id, -4) : null,
                'member_verified_at' => $l->member_verified_at, 'last_synced_at' => $l->last_synced_at, 'last_error' => $l->last_error,
                'forms' => $db->table('form_library_forms')->where('library_id', $l->id)->orderBy('category')->orderBy('name')->get()->map(fn ($f): array => [
                    'id' => (int) $f->id, 'name' => (string) $f->name, 'category' => $f->category, 'edition' => (string) $f->edition,
                    'added' => $f->template_id !== null, 'your_edition' => $f->template_edition,
                    'update' => $f->template_id !== null && $f->template_edition !== $f->edition])->values(),
            ])->values(),
            'sources' => collect(Importer::SOURCES)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values(),
            'comparison' => $compare > 0 ? $libraries->compare($compare, $user) : null,
            'imported' => $request->session()->get('forms.imported'),
        ]);
    }

    public function connect(Request $request, Libraries $libraries): RedirectResponse
    {
        $data = $request->validate(['connector' => ['required', 'string', 'max:30'], 'member_id' => ['nullable', 'string', 'max:20'], 'last_name' => ['nullable', 'string', 'max:120']]);
        $libraries->connect($data['connector'], $data['member_id'] ?? null, $data['last_name'] ?? null, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Library connected. Add the forms you use.']);

        return back();
    }

    public function sync(Request $request, string $library, Libraries $libraries): RedirectResponse
    {
        abort_unless(Templates::manages(self::user($request)), 403);
        $news = $libraries->sync($library);
        Inertia::flash('toast', ['type' => 'success', 'message' => $news === [] ? 'Up to date.' : count($news).' new edition(s) available.']);

        return back();
    }

    public function import(Request $request, int $form, Libraries $libraries): RedirectResponse
    {
        $libraries->import($form, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Form added with its fields placed. Check it in Forms before first use.']);

        return redirect('/forms/libraries');
    }

    public function importFiles(Request $request, Importer $importer): RedirectResponse
    {
        $data = $request->validate(['source' => ['required', 'string'], 'files' => ['required', 'array', 'min:1', 'max:50'],
            'files.*' => ['file', 'max:204800', 'mimetypes:application/pdf,application/zip,application/x-zip-compressed,application/octet-stream']]);
        $report = $importer->import(array_values($data['files']), $data['source'], self::user($request));

        return back()->with('forms.imported', $report);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
