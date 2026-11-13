<?php

declare(strict_types=1);

namespace Modules\Roster;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * The agent roster (specs/roster/01): licences, E&O, association and MLS memberships, agent documents and
 * manual verification. Adding a credential of the same kind (and state or issuer) replaces the old one,
 * which stays on record.
 */
final class Roster
{
    public const KINDS = ['licence', 'eo', 'association', 'mls'];

    public const DOCUMENT_KINDS = ['agreement', 'w9', 'licence_copy', 'eo_certificate', 'verification', 'other'];

    public function __construct(private readonly TenantContext $tenants) {}

    public static function manages(User $user): bool
    {
        return $user->isKind(UserKind::Owner, UserKind::Staff) || $user->can('roster.manage');
    }

    /**
     * @param  array{state_code?: string|null, number?: string|null, issuer?: string|null, coverage_cents?: int|null, expires_on?: string|null}  $data
     */
    public function add(User $agent, string $kind, array $data, User $by): string
    {
        $this->guard($by);
        $state = isset($data['state_code']) ? strtoupper(trim((string) $data['state_code'])) : null;
        $expires = isset($data['expires_on']) && $data['expires_on'] !== '' ? CarbonImmutable::parse((string) $data['expires_on']) : null;
        $message = match (true) {
            ! in_array($kind, self::KINDS, true) => 'Choose what kind of record this is.',
            $kind === 'licence' && ($state === null || preg_match('/^[A-Z]{2}$/', $state) !== 1 || trim((string) ($data['number'] ?? '')) === '' || $expires === null) => 'A licence needs the state, the licence number and the expiry date.',
            $kind === 'eo' && (trim((string) ($data['issuer'] ?? '')) === '' || $expires === null) => 'E&O needs the insurer and the expiry date.',
            in_array($kind, ['association', 'mls'], true) && trim((string) ($data['issuer'] ?? '')) === '' => 'Give the association or MLS name.',
            default => null,
        };
        if ($message !== null) {
            throw ValidationException::withMessages(['credential' => $message]);
        }
        $db = DB::connection(Connections::cell());
        $ulid = (string) Str::ulid();
        $db->transaction(function () use ($db, $agent, $kind, $data, $state, $expires, $ulid): void {
            $same = $db->table('roster_credentials')->where('user_id', $agent->id)->where('kind', $kind)->where('status', 'current');
            match ($kind) {
                'licence' => $same->where('state_code', $state),
                'eo' => $same,
                default => $same->where('issuer', trim((string) ($data['issuer'] ?? ''))),
            };
            $same->update(['status' => 'replaced', 'updated_at' => now()]);
            $db->table('roster_credentials')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'user_id' => $agent->id, 'kind' => $kind,
                'state_code' => $state, 'number' => isset($data['number']) ? mb_substr(trim((string) $data['number']), 0, 40) : null,
                'issuer' => isset($data['issuer']) ? mb_substr(trim((string) $data['issuer']), 0, 120) : null, 'coverage_cents' => $data['coverage_cents'] ?? null,
                'expires_on' => $expires?->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        });
        app(AuditLogger::class)->record('roster.credential.added', $agent, metadata: ['kind' => $kind, 'state' => $state, 'expires_on' => $expires?->toDateString()],
            severity: 'notice', label: $agent->name);

        return $ulid;
    }

    /** Manual verification: who checked, how, and optionally a screenshot of the state's record. */
    public function verify(string $credentialUlid, string $note, ?string $evidenceDocumentUlid, User $by): void
    {
        $this->guard($by);
        if (mb_strlen(trim($note)) < 10) {
            throw ValidationException::withMessages(['note' => 'Say how you checked (for example: looked up on the state licensing site, active until …).']);
        }
        $db = DB::connection(Connections::cell());
        $evidence = $evidenceDocumentUlid !== null ? $db->table('roster_documents')->where('ulid', $evidenceDocumentUlid)->value('id') : null;
        $updated = $db->table('roster_credentials')->where('ulid', $credentialUlid)->where('status', 'current')->update(['verified_at' => now(), 'verified_by' => $by->id,
            'verification_method' => 'manual', 'verification_note' => mb_substr(trim($note), 0, 300), 'evidence_document_id' => $evidence, 'updated_at' => now()]);
        if ($updated === 0) {
            throw ValidationException::withMessages(['credential' => 'This record has been replaced.']);
        }
        app(AuditLogger::class)->record('roster.credential.verified', metadata: ['credential' => $credentialUlid, 'note' => $note], severity: 'notice');
    }

    public function upload(User $agent, string $kind, UploadedFile $file, User $by): string
    {
        $this->guard($by);

        return $this->uploadAs($agent, $kind, $file, $by);
    }

    /** Stores an agent document (also used for the agent's own update requests). */
    public function uploadAs(User $agent, string $kind, UploadedFile $file, ?User $by = null): string
    {
        $by ??= $agent;
        $mime = (string) $file->getMimeType();
        if (! in_array($kind, self::DOCUMENT_KINDS, true) || ! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/heic'], true)) {
            throw ValidationException::withMessages(['file' => 'Upload a PDF or a photo (JPEG, PNG or HEIC).']);
        }
        $ulid = (string) Str::ulid();
        $path = 'roster/'.$this->tenants->id().'/'.$ulid;
        Storage::disk('local')->put($path, (string) file_get_contents((string) $file->getRealPath()));
        DB::connection(Connections::cell())->table('roster_documents')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'user_id' => $agent->id,
            'kind' => $kind, 'filename' => mb_substr($file->getClientOriginalName(), 0, 200), 'path' => $path, 'mime' => $mime,
            'sha256' => hash_file('sha256', (string) $file->getRealPath()) ?: '', 'uploaded_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('roster.document.uploaded', $agent, metadata: ['kind' => $kind, 'filename' => $file->getClientOriginalName()], label: $agent->name);

        return $ulid;
    }

    /**
     * @return array{credentials: list<array<string, mixed>>, documents: list<array<string, mixed>>, history: list<array<string, mixed>>}
     */
    public function forUser(User $agent): array
    {
        $db = DB::connection(Connections::cell());
        $today = CarbonImmutable::today();
        $map = fn ($c): array => ['ulid' => $c->ulid, 'kind' => $c->kind, 'state_code' => $c->state_code, 'number' => $c->number, 'issuer' => $c->issuer,
            'coverage_cents' => $c->coverage_cents !== null ? (int) $c->coverage_cents : null, 'expires_on' => $c->expires_on,
            'status' => self::health($c->expires_on, $today), 'verified' => $c->verified_at !== null, 'verification_note' => $c->verification_note,
            'check_result' => $c->check_result ?? null];

        return [
            'credentials' => array_values($db->table('roster_credentials')->where('user_id', $agent->id)->where('status', 'current')->orderBy('kind')->get()->map($map)->all()),
            'history' => array_values($db->table('roster_credentials')->where('user_id', $agent->id)->where('status', 'replaced')->orderByDesc('id')->limit(20)->get()->map($map)->all()),
            'documents' => array_values($db->table('roster_documents')->where('user_id', $agent->id)->orderByDesc('id')->get(['ulid', 'kind', 'filename', 'created_at'])
                ->map(fn ($d): array => (array) $d)->all()),
        ];
    }

    /** ok, expiring (90 days or less), expired, or none (no expiry). */
    public static function health(?string $expiresOn, CarbonImmutable $today): string
    {
        if ($expiresOn === null) {
            return 'ok';
        }
        $days = (int) $today->diffInDays(CarbonImmutable::parse($expiresOn), false);

        return $days < 0 ? 'expired' : ($days <= 90 ? 'expiring' : 'ok');
    }

    /**
     * @return array{guard_licence: bool, eo_required: bool}
     */
    public function settings(): array
    {
        $row = DB::connection(Connections::cell())->table('roster_settings')->first();

        return ['guard_licence' => (bool) ($row->guard_licence ?? false), 'eo_required' => (bool) ($row->eo_required ?? true)];
    }

    public function saveSettings(bool $guardLicence, bool $eoRequired, User $by): void
    {
        if (! $by->isKind(UserKind::Owner)) {
            throw ValidationException::withMessages(['settings' => 'Only an owner can change the licence guard.']);
        }
        DB::connection(Connections::cell())->table('roster_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()],
            ['guard_licence' => $guardLicence, 'eo_required' => $eoRequired, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('roster.guard.settings', metadata: ['guard_licence' => $guardLicence, 'eo_required' => $eoRequired], severity: 'warning');
    }

    private function guard(User $by): void
    {
        if (! self::manages($by)) {
            throw ValidationException::withMessages(['roster' => 'Managing the roster needs the "Manage the agent roster" permission.']);
        }
    }
}
