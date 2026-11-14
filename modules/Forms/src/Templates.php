<?php

declare(strict_types=1);

namespace Modules\Forms;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Form templates (specs/forms/01): an uploaded PDF (normalised) with fields — type, page, position, signer role,
 * binding to deal data, required. Saving fields creates a new version so packets keep the version they used.
 */
final class Templates
{
    public const TYPES = ['text', 'date', 'checkbox', 'signature', 'initials', 'date_signed'];

    public const SIGNER_FIELDS = ['signature', 'initials', 'date_signed'];

    public function __construct(private readonly TenantContext $tenants) {}

    public static function manages(User $user): bool
    {
        return $user->isKind(UserKind::Owner, UserKind::Staff) || $user->can('forms.manage');
    }

    public function upload(string $name, UploadedFile $file, ?string $checklistLabel, User $by): string
    {
        return $this->fromPdf($name, (string) file_get_contents((string) $file->getRealPath()), [], $by, ['checklist_label' => $checklistLabel]);
    }

    /**
     * Creates a template from PDF bytes with (optionally) its fields already placed: uploads, imports from other
     * systems, and association library editions.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array{checklist_label?: ?string, source?: string, edition?: ?string, library_form_id?: ?int, import_source?: ?string, high_risk?: bool}  $attributes
     */
    public function fromPdf(string $name, string $bytes, array $fields, User $by, array $attributes = []): string
    {
        $this->guard($by);
        if (trim($name) === '') {
            throw ValidationException::withMessages(['name' => 'Name the form.']);
        }
        try {
            [$pdf, $pages, $sizes] = Pdf::normalise($bytes);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        $clean = $this->clean($fields, $sizes);
        $checklistLabel = $attributes['checklist_label'] ?? null;
        $ulid = (string) Str::ulid();
        $path = 'forms/'.$this->tenants->id().'/'.$ulid.'-v1.pdf';
        Storage::disk('local')->put($path, $pdf);
        DB::connection(Connections::cell())->table('form_templates')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'name' => mb_substr(trim($name), 0, 160),
            'source' => $attributes['source'] ?? 'brokerage', 'path' => $path, 'sha256' => hash('sha256', $pdf), 'pages' => $pages, 'page_sizes' => json_encode($sizes),
            'fields' => json_encode($clean), 'checklist_label' => $checklistLabel !== null && trim($checklistLabel) !== '' ? mb_substr(trim($checklistLabel), 0, 160) : null,
            'edition' => $attributes['edition'] ?? null, 'library_form_id' => $attributes['library_form_id'] ?? null, 'import_source' => $attributes['import_source'] ?? null,
            'high_risk' => $attributes['high_risk'] ?? false, 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('forms.template.uploaded', metadata: ['template' => $ulid, 'name' => $name, 'pages' => $pages, 'fields' => count($clean),
            'source' => $attributes['import_source'] ?? 'upload'], severity: 'notice');

        return $ulid;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    public function saveFields(string $ulid, array $fields, User $by): void
    {
        $this->guard($by);
        $db = DB::connection(Connections::cell());
        $t = $db->table('form_templates')->where('ulid', $ulid)->first();
        if ($t === null) {
            throw ValidationException::withMessages(['template' => 'Form not found.']);
        }
        $clean = $this->clean($fields, (array) json_decode((string) $t->page_sizes, true));
        $db->table('form_templates')->where('id', $t->id)->update(['fields' => json_encode($clean), 'version' => (int) $t->version + 1, 'updated_at' => now()]);
        app(AuditLogger::class)->record('forms.template.fields', metadata: ['template' => $ulid, 'fields' => count($clean), 'version' => (int) $t->version + 1], severity: 'notice');
    }

    /**
     * Validates and normalises field definitions against the form's page sizes.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<int|string, mixed>  $sizes
     * @return list<array<string, mixed>>
     */
    private function clean(array $fields, array $sizes): array
    {
        $clean = [];
        $keys = [];
        foreach ($fields as $i => $f) {
            $key = (string) ($f['key'] ?? '');
            $type = (string) ($f['type'] ?? '');
            $page = (int) ($f['page'] ?? 0);
            [$x, $y, $w, $h] = [(float) ($f['x'] ?? -1), (float) ($f['y'] ?? -1), (float) ($f['w'] ?? 0), (float) ($f['h'] ?? 0)];
            $size = $sizes[$page - 1] ?? null;
            $problem = match (true) {
                preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1 => 'needs a key of lowercase letters, numbers and underscores',
                isset($keys[$key]) => 'has a key used twice',
                ! in_array($type, self::TYPES, true) => 'needs a type',
                $size === null => 'is on a page the form does not have',
                $x < 0 || $y < 0 || $w <= 0 || $h <= 0 || $x + $w > $size[0] + 0.5 || $y + $h > $size[1] + 0.5 => 'is outside the page',
                in_array($type, self::SIGNER_FIELDS, true) && trim((string) ($f['role'] ?? '')) === '' => 'needs the signer it belongs to',
                default => null,
            };
            if ($problem !== null) {
                throw ValidationException::withMessages(['fields' => 'Field '.($i + 1).' ('.($key !== '' ? $key : 'unnamed').") {$problem}."]);
            }
            $keys[$key] = true;
            $clean[] = ['key' => $key, 'type' => $type, 'page' => $page, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
                'role' => isset($f['role']) && trim((string) $f['role']) !== '' ? mb_substr(trim((string) $f['role']), 0, 30) : null,
                'bind' => isset($f['bind']) && trim((string) $f['bind']) !== '' ? (string) $f['bind'] : null,
                'required' => (bool) ($f['required'] ?? false), 'label' => mb_substr((string) ($f['label'] ?? $key), 0, 80)];
        }

        return $clean;
    }

    /**
     * @return array{id: int, ulid: string, name: string, version: int, path: string, pages: int, page_sizes: list<array{0: float, 1: float}>, fields: list<array<string, mixed>>, checklist_label: string|null}|null
     */
    public function find(string $ulid): ?array
    {
        $t = DB::connection(Connections::cell())->table('form_templates')->where('ulid', $ulid)->first();

        if ($t === null) {
            return null;
        }
        /** @var list<array{0: float, 1: float}> $sizes */
        $sizes = array_values((array) json_decode((string) $t->page_sizes, true));
        /** @var list<array<string, mixed>> $fields */
        $fields = array_values((array) json_decode((string) $t->fields, true));

        return ['id' => (int) $t->id, 'ulid' => (string) $t->ulid, 'name' => (string) $t->name, 'version' => (int) $t->version, 'path' => (string) $t->path,
            'pages' => (int) $t->pages, 'page_sizes' => $sizes, 'fields' => $fields,
            'checklist_label' => $t->checklist_label];
    }

    /**
     * One page of a form as a PNG at 72 dpi (1 pixel = 1 PDF point) for the visual field editor; cached by the
     * PDF's fingerprint. Null when the renderer is unavailable (the editor falls back to the coordinate table).
     */
    public function pageImage(string $ulid, int $page): ?string
    {
        $t = $this->find($ulid);
        if ($t === null || $page < 1 || $page > $t['pages']) {
            return null;
        }
        $sha = (string) DB::connection(Connections::cell())->table('form_templates')->where('ulid', $ulid)->value('sha256');
        $cache = 'forms/'.$this->tenants->id().'/pages/'.$sha.'-p'.$page.'.png';
        $disk = Storage::disk('local');
        if ($disk->exists($cache)) {
            return (string) $disk->get($cache);
        }
        $pdf = Pdf::tempFile('page-', (string) $disk->get($t['path']));
        $out = substr($pdf, 0, -4).'-img';
        try {
            $process = new Process([(string) config('forms.pdftoppm', 'pdftoppm'), '-png', '-r', '72', '-f', (string) $page, '-l', (string) $page, '-singlefile', $pdf, $out]);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($out.'.png')) {
                logger()->warning('Could not render a form page', ['error' => mb_substr($process->getErrorOutput(), 0, 300)]);

                return null;
            }
            $png = (string) file_get_contents($out.'.png');
            $disk->put($cache, $png);

            return $png;
        } catch (\Throwable $e) {
            logger()->warning('pdftoppm is not available', ['error' => $e->getMessage()]);

            return null;
        } finally {
            @unlink($pdf);
            @unlink($out.'.png');
        }
    }

    public function addClause(string $title, string $body, User $by): string
    {
        $this->guard($by);
        if (trim($title) === '' || mb_strlen(trim($body)) < 10) {
            throw ValidationException::withMessages(['clause' => 'Give the clause a title and its wording.']);
        }
        Guard::noWiringInstructions($body, 'clause');
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('form_clauses')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'title' => mb_substr(trim($title), 0, 120),
            'body' => trim($body), 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);

        return $ulid;
    }

    private function guard(User $by): void
    {
        if (! self::manages($by)) {
            throw ValidationException::withMessages(['forms' => 'Managing forms needs the "Manage forms" permission.']);
        }
    }
}
