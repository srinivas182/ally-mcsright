<?php

declare(strict_types=1);

namespace Modules\Setup\Imports;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Actions\ManageInvitations;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Models\Invitation;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\ContactImporter;
use Modules\Crm\Jobs\ImportContacts;
use Modules\Crm\Models\ContactImport;
use Modules\Transactions\Checklists;
use Modules\Transactions\Connect\DealImporter;
use Modules\Transactions\Events\DealStatusChanged;
use Modules\Transactions\Models\Deal;
use RuntimeException;
use stdClass;

/**
 * "Bring everything from your old system" (SU1, specs/setup/01). The owner drops in the export files their old
 * systems give them (CSV or Excel). Each file is recognised (people, agents or deals, and from which system), its
 * columns matched, and a plain-words preview shown. Nothing is saved until the owner says yes. People go through the
 * CRM import; agents get invitations; deals are filed under their agent. Agents and deals can be undone for 24 hours.
 */
final class FileImports
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const UNDO_HOURS = 24;

    public function __construct(private readonly TenantContext $tenants, private readonly Detector $detector) {}

    public function upload(UploadedFile $file, ?string $kind, User $by): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['csv', 'txt', 'xlsx'], true) || $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Choose a CSV or Excel (.xlsx) file under 10 MB.']);
        }
        if ($kind !== null && ! array_key_exists($kind, Formats::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Choose people, agents or deals.']);
        }
        $ulid = (string) Str::ulid();
        $path = 'setup-imports/'.$this->tenants->id().'/'.$ulid.'.csv';
        $disk = Storage::disk('local');
        if ($ext === 'xlsx') {
            $tmp = tempnam(sys_get_temp_dir(), 'su').'.csv';
            try {
                Spreadsheet::toCsv((string) $file->getRealPath(), $tmp);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['file' => $e->getMessage()]);
            }
            $disk->put($path, (string) file_get_contents($tmp));
            @unlink($tmp);
        } else {
            $disk->put($path, (string) file_get_contents((string) $file->getRealPath()));
        }
        $full = $disk->path($path);
        $headers = array_values(array_filter(ContactImporter::headers($full), fn (string $h): bool => $h !== ''));
        if ($headers === []) {
            $disk->delete($path);
            throw ValidationException::withMessages(['file' => 'This file has no column names in its first row.']);
        }
        $sample = [];
        $total = 0;
        foreach (ContactImporter::rows($full) as $row) {
            if (count($sample) < 5) {
                $one = [];
                foreach ($row as $k => $v) {
                    $one[(string) $k] = (string) $v;
                }
                $sample[] = $one;
            }
            $total++;
        }
        $found = $this->detector->detect($headers, $sample, $kind, $by->id);
        DB::connection(Connections::cell())->table('setup_imports')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'kind' => $found['kind'],
            'source' => $found['source'], 'label' => $found['label'], 'file_path' => $path, 'filename' => mb_substr($file->getClientOriginalName(), 0, 200),
            'headers' => json_encode($headers), 'mapping' => json_encode($found['mapping']), 'total_rows' => $total, 'ai_matched' => $found['ai'],
            'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('setup.import.uploaded', metadata: ['import' => $ulid, 'kind' => $found['kind'], 'source' => $found['source'], 'rows' => $total], severity: 'notice');

        return $ulid;
    }

    public function find(string $ulid): stdClass
    {
        return DB::connection(Connections::cell())->table('setup_imports')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first() ?? abort(404);
    }

    /**
     * Change what a file is, or which column is which, before it is brought in.
     *
     * @param  array<string, string|null>  $mapping
     */
    public function remap(string $ulid, string $kind, array $mapping): void
    {
        $import = $this->find($ulid);
        if ($import->status !== 'reviewing') {
            throw ValidationException::withMessages(['mapping' => 'This file has already been brought in.']);
        }
        if (! array_key_exists($kind, Formats::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Choose people, agents or deals.']);
        }
        $headers = (array) json_decode((string) $import->headers, true);
        $clean = [];
        foreach ($mapping as $header => $field) {
            if ($field === null || $field === '') {
                continue;
            }
            if (! in_array($header, $headers, true) || ! array_key_exists($field, Formats::FIELDS[$kind])) {
                throw ValidationException::withMessages(['mapping' => "\"{$header}\" can't be matched to {$field}."]);
            }
            if (in_array($field, $clean, true)) {
                throw ValidationException::withMessages(['mapping' => 'Each field can be matched to one column only ('.str_replace('_', ' ', $field).').']);
            }
            $clean[$header] = $field;
        }
        DB::connection(Connections::cell())->table('setup_imports')->where('id', $import->id)->update(['kind' => $kind, 'mapping' => json_encode($clean),
            'label' => $kind !== $import->kind ? Formats::KINDS[$kind] : $import->label, 'source' => $kind !== $import->kind ? null : $import->source, 'updated_at' => now()]);
    }

    /**
     * What would happen, in plain words, before anything is saved.
     *
     * @return array{ready: int, existing: int, problems: list<array{row: int, why: string}>, problem_count: int, missing: list<string>, examples: list<array<string, string>>}
     */
    public function preview(stdClass $import): array
    {
        $kind = (string) $import->kind;
        /** @var array<string, string> $mapping */
        $mapping = (array) json_decode((string) $import->mapping, true);
        $missing = Detector::missing($kind, $mapping);
        $out = ['ready' => 0, 'existing' => 0, 'problems' => [], 'problem_count' => 0, 'missing' => $missing, 'examples' => []];
        if ($missing !== []) {
            return $out;
        }
        foreach (ContactImporter::rows(Storage::disk('local')->path((string) $import->file_path)) as $number => $row) {
            $d = self::fields($row, $mapping);
            $verdict = match ($kind) {
                'people' => $this->checkPerson($d),
                'agents' => $this->checkAgent($d),
                default => $this->checkDeal($d),
            };
            if ($verdict === 'ready' || $verdict === 'existing') {
                $out[$verdict]++;
                if ($verdict === 'ready' && count($out['examples']) < 3) {
                    $out['examples'][] = self::example($kind, $d);
                }
            } else {
                $out['problem_count']++;
                if (count($out['problems']) < 20) {
                    $out['problems'][] = ['row' => (int) $number, 'why' => $verdict];
                }
            }
        }

        return $out;
    }

    /**
     * Brings the file in.
     *
     * @return array<string, int|bool>
     */
    public function confirm(string $ulid, User $by): array
    {
        $import = $this->find($ulid);
        if ($import->status !== 'reviewing') {
            throw ValidationException::withMessages(['import' => 'This file has already been brought in.']);
        }
        /** @var array<string, string> $mapping */
        $mapping = (array) json_decode((string) $import->mapping, true);
        if (($missing = Detector::missing((string) $import->kind, $mapping)) !== []) {
            throw ValidationException::withMessages(['mapping' => 'Match a column for: '.implode('; ', $missing).'.']);
        }
        $db = DB::connection(Connections::cell());
        $db->table('setup_imports')->where('id', $import->id)->update(['status' => 'importing', 'confirmed_at' => now(), 'updated_at' => now()]);
        $summary = match ((string) $import->kind) {
            'people' => $this->people($import, $mapping, $by),
            'agents' => $this->agents($import, $mapping, $by),
            default => $this->deals($import, $mapping, $by),
        };
        $db->table('setup_imports')->where('id', $import->id)->update(['status' => 'done', 'summary' => json_encode($summary), 'finished_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('setup.import.done', metadata: ['import' => $ulid, 'kind' => $import->kind] + $summary, severity: 'notice');

        return $summary;
    }

    /** Takes back what an agents or deals file added, within 24 hours. */
    public function undo(string $ulid, User $by): int
    {
        $import = $this->find($ulid);
        if ($import->status !== 'done' || $import->kind === 'people' || CarbonImmutable::parse((string) $import->finished_at)->addHours(self::UNDO_HOURS)->isPast()) {
            throw ValidationException::withMessages(['import' => 'This file can no longer be undone here.']);
        }
        $db = DB::connection(Connections::cell());
        $n = 0;
        foreach ($db->table('setup_import_items')->where('import_id', $import->id)->get() as $item) {
            if ($item->item_type === 'deal') {
                $deal = Deal::query()->find((int) $item->item_id);
                if ($deal !== null) {
                    $deal->delete();
                    $n++;
                }
            } elseif ($item->item_type === 'invitation') {
                $invitation = Invitation::query()->find((int) $item->item_id);
                if ($invitation instanceof Invitation && $invitation->accepted_at === null && $invitation->revoked_at === null) {
                    app(ManageInvitations::class)->revoke($invitation, $by);
                    $n++;
                }
            }
        }
        $db->table('setup_imports')->where('id', $import->id)->update(['status' => 'undone', 'undone_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('setup.import.undone', metadata: ['import' => $ulid, 'items' => $n], severity: 'warning');

        return $n;
    }

    /**
     * @param  array<string, string>  $mapping
     * @return array<string, int|bool>
     */
    private function people(stdClass $import, array $mapping, User $by): array
    {
        $crm = ContactImport::query()->create(['user_id' => $by->id, 'file_path' => (string) $import->file_path, 'original_name' => (string) $import->filename,
            'status' => 'running', 'headers' => (array) json_decode((string) $import->headers, true), 'mapping' => $mapping,
            'options' => ['source' => 'import', 'origin' => 'company', 'tag' => mb_substr('from '.((string) $import->label), 0, 40)]]);
        DB::connection(Connections::cell())->table('setup_imports')->where('id', $import->id)->update(['crm_import_id' => $crm->id]);
        ImportContacts::dispatch($crm->id);

        return ['handed_to_crm' => true];
    }

    /**
     * @param  array<string, string>  $mapping
     * @return array<string, int|bool>
     */
    private function agents(stdClass $import, array $mapping, User $by): array
    {
        $out = ['invited' => 0, 'existing' => 0, 'problems' => 0];
        foreach (ContactImporter::rows(Storage::disk('local')->path((string) $import->file_path)) as $row) {
            $d = self::fields($row, $mapping);
            $verdict = $this->checkAgent($d);
            if ($verdict !== 'ready') {
                $out[$verdict === 'existing' ? 'existing' : 'problems']++;

                continue;
            }
            [$first, $last] = self::names($d);
            try {
                $invitation = app(ManageInvitations::class)->invite((string) $d['email'], $first, $last, UserKind::Agent, $by);
            } catch (ValidationException) {
                $out['problems']++;

                continue;
            }
            $this->remember($import, 'invitation', $invitation->id);
            $out['invited']++;
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $mapping
     * @return array<string, int|bool>
     */
    private function deals(stdClass $import, array $mapping, User $by): array
    {
        $out = ['created' => 0, 'existing' => 0, 'problems' => 0];
        $from = mb_substr(str_replace([' transactions', ' loops'], '', (string) $import->label), 0, 20);
        foreach (ContactImporter::rows(Storage::disk('local')->path((string) $import->file_path)) as $row) {
            $d = self::fields($row, $mapping);
            $verdict = $this->checkDeal($d);
            if ($verdict !== 'ready') {
                $out[$verdict === 'existing' ? 'existing' : 'problems']++;

                continue;
            }
            $status = (string) Formats::word((string) $d['status'], Formats::STATUS_WORDS);
            $closing = DealImporter::date($d['closing_date'] ?? null);
            $deal = Deal::query()->create([
                'type' => Formats::word((string) ($d['type'] ?? ''), Formats::TYPE_WORDS) ?? 'listing',
                'status' => $status, 'agent_user_id' => $this->agentFor($d, $by), 'street' => mb_substr(trim((string) $d['street']), 0, 160),
                'city' => isset($d['city']) ? mb_substr($d['city'], 0, 80) : null, 'state_code' => strtoupper(trim((string) $d['state_code'])),
                'postal_code' => isset($d['postal_code']) ? mb_substr($d['postal_code'], 0, 10) : null, 'mls_number' => isset($d['mls_number']) ? mb_substr($d['mls_number'], 0, 30) : null,
                'price_cents' => self::cents($d['price'] ?? null), 'gci_cents' => self::cents($d['gci'] ?? null),
                'contract_date' => DealImporter::date($d['contract_date'] ?? null), 'closing_date' => $closing,
                'closed_on' => $status === 'closed' ? ($closing ?? CarbonImmutable::now()->toDateString()) : null,
                'cancel_reason' => $status === 'fell_through' ? 'Cancelled in '.$from : null,
                'created_by' => $by->id, 'imported_from' => $from, 'locked_at' => $status === 'closed' ? CarbonImmutable::now() : null,
            ]);
            if (in_array($status, ['pre_contract', 'under_contract'], true)) {
                app(Checklists::class)->apply($deal);
            }
            DealStatusChanged::dispatch($deal->tenant_id, $deal->id, null, $deal->status);
            $this->remember($import, 'deal', $deal->id);
            $out['created']++;
        }

        return $out;
    }

    /** @param  array<string, string>  $d */
    private function checkPerson(array $d): string
    {
        if (($d['email'] ?? '') === '' && ($d['phone'] ?? '') === '') {
            return 'No email or phone number.';
        }
        if (($d['email'] ?? '') !== '' && filter_var($d['email'], FILTER_VALIDATE_EMAIL) === false) {
            return "The email address \"{$d['email']}\" doesn't look right.";
        }
        if (($d['first_name'] ?? '') === '' && ($d['last_name'] ?? '') === '' && ($d['full_name'] ?? '') === '') {
            return 'No name.';
        }

        return 'ready';
    }

    /** @param  array<string, string>  $d */
    private function checkAgent(array $d): string
    {
        $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'No valid email address, so they can\'t be invited.';
        }
        [$first, $last] = self::names($d);
        if ($first === '' && $last === '') {
            return 'No name.';
        }
        if (User::query()->where('email', $email)->exists() || Invitation::query()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->exists()) {
            return 'existing';
        }

        return 'ready';
    }

    /** @param  array<string, string>  $d */
    private function checkDeal(array $d): string
    {
        if (trim((string) ($d['street'] ?? '')) === '') {
            return 'No property address.';
        }
        if (preg_match('/^[A-Z]{2}$/', strtoupper(trim((string) ($d['state_code'] ?? '')))) !== 1) {
            return 'No two-letter state (for example TX).';
        }
        if (Formats::word((string) ($d['status'] ?? ''), Formats::STATUS_WORDS) === null) {
            return 'The status "'.($d['status'] ?? '')."\" isn't one we know (for example Active, Pending, Closed, Cancelled).";
        }
        $closing = DealImporter::date($d['closing_date'] ?? null);
        $exists = Deal::query()->whereRaw('lower(street) = ?', [mb_strtolower(trim((string) $d['street']))])->where('state_code', strtoupper(trim((string) $d['state_code'])))
            ->when($closing !== null, fn ($q) => $q->whereDate('closing_date', $closing), fn ($q) => $q->whereNull('closing_date'))->exists();

        return $exists ? 'existing' : 'ready';
    }

    /** @param  array<string, string>  $d */
    private function agentFor(array $d, User $by): int
    {
        $email = mb_strtolower(trim((string) ($d['agent_email'] ?? '')));
        if ($email !== '' && ($id = User::query()->where('email', $email)->value('id')) !== null) {
            return (int) $id;
        }
        $name = trim((string) ($d['agent_name'] ?? ''));
        if ($name !== '') {
            $parts = preg_split('/\s+/', $name) ?: [];
            $last = count($parts) > 1 ? (string) array_pop($parts) : '';
            $id = User::query()->whereRaw('lower(first_name) = ?', [mb_strtolower(implode(' ', $parts))])->whereRaw('lower(last_name) = ?', [mb_strtolower($last)])->value('id');
            if ($id !== null) {
                return (int) $id;
            }
        }

        return $by->id;
    }

    /**
     * @param  array<int|string, string>  $row
     * @param  array<string, string>  $mapping
     * @return array<string, string>
     */
    private static function fields(array $row, array $mapping): array
    {
        $d = [];
        foreach ($mapping as $header => $field) {
            $v = trim((string) ($row[$header] ?? ''));
            if ($v !== '') {
                $d[$field] = $v;
            }
        }

        return $d;
    }

    /**
     * @param  array<string, string>  $d
     * @return array{0: string, 1: string}
     */
    private static function names(array $d): array
    {
        $first = trim((string) ($d['first_name'] ?? ''));
        $last = trim((string) ($d['last_name'] ?? ''));
        if ($first === '' && $last === '' && isset($d['full_name'])) {
            $name = trim($d['full_name']);
            if (str_contains($name, ',')) {
                [$last, $first] = array_map('trim', explode(',', $name, 2));
            } else {
                $parts = preg_split('/\s+/', $name) ?: [];
                $last = count($parts) > 1 ? (string) array_pop($parts) : '';
                $first = implode(' ', $parts);
            }
        }

        return [mb_substr($first, 0, 80), mb_substr($last, 0, 80)];
    }

    /**
     * A ready row as the preview shows it: names and places only, never contact details.
     *
     * @param  array<string, string>  $d
     * @return array<string, string>
     */
    private static function example(string $kind, array $d): array
    {
        return match ($kind) {
            'people' => ['name' => trim(implode(' ', self::names($d))), 'city' => $d['city'] ?? ''],
            'agents' => ['name' => trim(implode(' ', self::names($d)))],
            default => ['address' => trim(($d['street'] ?? '').', '.($d['city'] ?? ''), ', '), 'status' => (string) Formats::word((string) ($d['status'] ?? ''), Formats::STATUS_WORDS),
                'closing' => (string) (DealImporter::date($d['closing_date'] ?? null) ?? '')],
        };
    }

    private static function cents(?string $money): int
    {
        $n = preg_replace('/[^0-9.]/', '', (string) $money) ?? '';
        if ($n === '' || preg_match('/^(\d*)(?:\.(\d{0,2})\d*)?$/', $n, $m) !== 1) {
            return 0;
        }

        return (int) ($m[1] === '' ? '0' : $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    private function remember(stdClass $import, string $type, int $id): void
    {
        DB::connection(Connections::cell())->table('setup_import_items')->insert(['tenant_id' => $this->tenants->id(), 'import_id' => $import->id, 'item_type' => $type, 'item_id' => $id]);
    }
}
