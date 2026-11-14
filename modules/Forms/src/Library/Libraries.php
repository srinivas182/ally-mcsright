<?php

declare(strict_types=1);

namespace Modules\Forms\Library;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Forms\Pdf;
use Modules\Forms\Templates;
use Symfony\Component\Process\Process;

/**
 * Association form libraries (specs/forms/01, part 5c): connect with a verified member number, add forms as
 * ready-mapped templates, get told when a new edition is out, see exactly what changed, and update in one click.
 * An update creates a new template and retires the old one; packets already sent keep the edition they used,
 * and drafts can switch to the new edition keeping their filled-in values.
 */
final class Libraries
{
    /** Connector key => class. Each real association connector is added here once its licence is signed. */
    public const CONNECTORS = ['test' => TestLibrary::class];

    public function __construct(private readonly TenantContext $tenants, private readonly Templates $templates) {}

    /**
     * Connectors this deployment offers (FORMS_LIBRARIES).
     *
     * @return array<string, FormLibrary>
     */
    public static function available(): array
    {
        $out = [];
        foreach ((array) config('forms.libraries', []) as $key) {
            if (isset(self::CONNECTORS[$key])) {
                $out[$key] = app(self::CONNECTORS[$key]);
            }
        }

        return $out;
    }

    public function connect(string $connector, ?string $memberId, ?string $lastName, User $by): string
    {
        $this->guard($by);
        $library = self::available()[$connector] ?? throw ValidationException::withMessages(['connector' => 'That library is not available.']);
        $memberId = trim((string) $memberId);
        if ($library->needsMembership() && ! $library->verifyMember($memberId, (string) $lastName)) {
            throw ValidationException::withMessages(['member_id' => 'The association could not confirm that member number and last name.']);
        }
        $db = DB::connection(Connections::cell());
        if ($db->table('form_libraries')->where('connector', $connector)->exists()) {
            throw ValidationException::withMessages(['connector' => 'This library is already connected.']);
        }
        $ulid = (string) Str::ulid();
        $db->table('form_libraries')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'connector' => $connector, 'name' => $library->name(),
            'member_id' => $memberId !== '' ? $memberId : null, 'member_name' => $lastName !== null ? mb_substr(trim($lastName), 0, 120) : null,
            'member_verified_at' => $library->needsMembership() ? now() : null, 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('forms.library.connected', metadata: ['library' => $connector], severity: 'notice');
        $this->sync($ulid);

        return $ulid;
    }

    /**
     * Refreshes the catalog; returns the forms the brokerage uses that have a newer edition (and tells the
     * people who manage forms, once per edition).
     *
     * @return list<string>
     */
    public function sync(string $libraryUlid): array
    {
        $db = DB::connection(Connections::cell());
        $row = $db->table('form_libraries')->where('ulid', $libraryUlid)->first() ?? throw ValidationException::withMessages(['library' => 'Library not found.']);
        $library = self::available()[$row->connector] ?? null;
        if ($library === null) {
            return [];
        }
        try {
            $catalog = $library->catalog();
        } catch (\Throwable $e) {
            $db->table('form_libraries')->where('id', $row->id)->update(['last_error' => mb_substr($e->getMessage(), 0, 300), 'updated_at' => now()]);

            return [];
        }
        foreach ($catalog as $f) {
            $existing = $db->table('form_library_forms')->where('library_id', $row->id)->where('external_id', $f['id'])->first();
            $values = ['name' => mb_substr($f['name'], 0, 160), 'category' => $f['category'], 'edition' => mb_substr($f['edition'], 0, 30), 'updated_at' => now()];
            if ($existing === null) {
                $db->table('form_library_forms')->insert($values + ['tenant_id' => $this->tenants->id(), 'library_id' => $row->id, 'external_id' => $f['id'], 'created_at' => now()]);
            } else {
                $db->table('form_library_forms')->where('id', $existing->id)->update($values + ($existing->edition !== $f['edition'] ? ['update_noticed_at' => null] : []));
            }
        }
        $db->table('form_libraries')->where('id', $row->id)->update(['last_synced_at' => now(), 'last_error' => null, 'updated_at' => now()]);

        $news = $db->table('form_library_forms')->where('library_id', $row->id)->whereNotNull('template_id')->whereColumn('edition', '!=', 'template_edition')
            ->whereNull('update_noticed_at')->get();
        /** @var list<string> $lines */
        $lines = array_values($news->map(fn ($f): string => "{$f->name}: {$f->template_edition} → {$f->edition}")->all());
        if ($lines !== []) {
            foreach (User::query()->get() as $user) {
                if ($user->isActive() && Templates::manages($user)) {
                    $user->notify(new FormUpdateAvailable((string) $row->name, $lines));
                }
            }
            $db->table('form_library_forms')->whereIn('id', $news->pluck('id'))->update(['update_noticed_at' => now()]);
        }

        return $lines;
    }

    /** Adds a library form as a template (the current edition), or updates it to the newest edition. */
    public function import(int $libraryFormId, User $by): string
    {
        $this->guard($by);
        $db = DB::connection(Connections::cell());
        $form = $db->table('form_library_forms')->where('id', $libraryFormId)->first() ?? throw ValidationException::withMessages(['form' => 'Form not found.']);
        $library = $db->table('form_libraries')->where('id', $form->library_id)->first();
        $connector = self::available()[$library->connector ?? ''] ?? throw ValidationException::withMessages(['form' => 'This library is not available.']);
        $old = $form->template_id !== null ? $db->table('form_templates')->where('id', $form->template_id)->first() : null;
        if ($old !== null && $form->template_edition === $form->edition) {
            throw ValidationException::withMessages(['form' => 'You already have the newest edition.']);
        }
        try {
            $download = $connector->download((string) $form->external_id);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['form' => 'The library did not send the form: '.mb_substr($e->getMessage(), 0, 200)]);
        }
        $fields = $download['fields'] !== [] ? $download['fields'] : $this->fieldsFor($download['pdf'], $old);
        $ulid = $this->templates->fromPdf((string) $form->name, $download['pdf'], $fields, $by, [
            'source' => 'association', 'edition' => $download['edition'], 'library_form_id' => (int) $form->id, 'import_source' => 'library',
            'checklist_label' => $old->checklist_label ?? null, 'high_risk' => (bool) ($old->high_risk ?? false),
        ]);
        $new = (int) $db->table('form_templates')->where('ulid', $ulid)->value('id');
        if ($old !== null) {
            $db->table('form_templates')->where('id', $old->id)->update(['active' => false, 'superseded_by' => $new, 'updated_at' => now()]);
        }
        $db->table('form_library_forms')->where('id', $form->id)->update(['template_id' => $new, 'template_edition' => $download['edition'], 'edition' => $download['edition'], 'updated_at' => now()]);
        app(AuditLogger::class)->record($old !== null ? 'forms.library.updated' : 'forms.library.added', metadata: ['form' => $form->name, 'edition' => $download['edition'],
            'previous' => $old->edition ?? null], severity: 'notice');

        return $ulid;
    }

    /**
     * What changed between the brokerage's edition and the library's newest: wording added and removed, and
     * fields added and removed.
     *
     * @return array{form: string, from: ?string, to: string, added: list<string>, removed: list<string>, fields_added: list<string>, fields_removed: list<string>}
     */
    public function compare(int $libraryFormId, User $by): array
    {
        $this->guard($by);
        $db = DB::connection(Connections::cell());
        $form = $db->table('form_library_forms')->where('id', $libraryFormId)->first() ?? throw ValidationException::withMessages(['form' => 'Form not found.']);
        $old = $db->table('form_templates')->where('id', $form->template_id)->first() ?? throw ValidationException::withMessages(['form' => 'Add the form first.']);
        $library = $db->table('form_libraries')->where('id', $form->library_id)->first();
        $connector = self::available()[$library->connector ?? ''] ?? throw ValidationException::withMessages(['form' => 'This library is not available.']);
        $download = $connector->download((string) $form->external_id);

        $before = self::lines((string) Storage::disk('local')->get((string) $old->path));
        $after = self::lines($download['pdf']);
        $oldFields = array_column((array) json_decode((string) $old->fields, true), 'label');
        $newFields = array_column($download['fields'] !== [] ? $download['fields'] : FieldMapper::map(FillableFields::read($download['pdf']), self::sizes($download['pdf'])), 'label');

        return ['form' => (string) $form->name, 'from' => $old->edition, 'to' => $download['edition'],
            'added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after)),
            'fields_added' => array_values(array_map('strval', array_diff($newFields, $oldFields))), 'fields_removed' => array_values(array_map('strval', array_diff($oldFields, $newFields)))];
    }

    /**
     * Fields for a new edition with none supplied: the PDF's own fillable fields, or the previous edition's
     * placements when the pages are the same size.
     *
     * @return list<array<string, mixed>>
     */
    private function fieldsFor(string $pdf, ?\stdClass $old): array
    {
        $sizes = self::sizes($pdf);
        $mapped = FieldMapper::map(FillableFields::read($pdf), $sizes);
        if ($mapped !== [] || $old === null) {
            return $mapped;
        }

        return json_encode((array) json_decode((string) $old->page_sizes, true)) === json_encode($sizes)
            ? array_values((array) json_decode((string) $old->fields, true)) : [];
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    public static function sizes(string $pdf): array
    {
        try {
            return Pdf::normalise($pdf)[2];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The form's wording, one trimmed line per entry, without blank lines.
     *
     * @return list<string>
     */
    private static function lines(string $pdf): array
    {
        try {
            $file = Pdf::tempFile('cmp-', $pdf);
        } catch (\Throwable) {
            return [];
        }
        $process = new Process(['pdftotext', '-layout', $file, '-']);
        $process->setTimeout(30);
        $process->run();
        @unlink($file);

        return array_values(array_unique(array_filter(array_map(fn (string $l): string => trim((string) preg_replace('/\s+/', ' ', $l)), explode("\n", $process->getOutput())),
            fn (string $l): bool => $l !== '')));
    }

    private function guard(User $by): void
    {
        if (! Templates::manages($by)) {
            throw ValidationException::withMessages(['forms' => 'Managing forms needs the "Manage forms" permission.']);
        }
    }
}
