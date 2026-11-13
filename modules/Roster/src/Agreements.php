<?php

declare(strict_types=1);

namespace Modules\Roster;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * The contractor agreement and policy manual (specs/roster/01, addition 3): versioned text set by an owner,
 * e-signed by agents with a typed legal name. Each signature records the exact text's SHA-256, time, IP and
 * device, and a signed PDF is saved to the agent's documents.
 */
final class Agreements
{
    public const KINDS = ['agreement' => 'Independent contractor agreement', 'policy' => 'Policy manual'];

    public function __construct(private readonly TenantContext $tenants) {}

    public function publish(string $kind, string $title, string $body, User $by): int
    {
        if (! $by->isKind(UserKind::Owner)) {
            throw ValidationException::withMessages(['agreement' => 'Only an owner can change the agreements agents sign.']);
        }
        if (! isset(self::KINDS[$kind]) || trim($title) === '' || mb_strlen(trim($body)) < 50) {
            throw ValidationException::withMessages(['agreement' => 'Give a title and the full text (at least 50 characters).']);
        }
        $db = DB::connection(Connections::cell());
        $version = (int) $db->table('roster_agreements')->where('kind', $kind)->max('version') + 1;
        $id = $db->table('roster_agreements')->insertGetId(['tenant_id' => $this->tenants->id(), 'kind' => $kind, 'version' => $version, 'title' => mb_substr(trim($title), 0, 120),
            'body' => trim($body), 'sha256' => hash('sha256', trim($body)), 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('roster.agreement.published', metadata: ['kind' => $kind, 'version' => $version], severity: 'notice');

        return $id;
    }

    /**
     * @return array{id: int, kind: string, version: int, title: string, body: string}|null
     */
    public function latest(string $kind): ?array
    {
        $row = DB::connection(Connections::cell())->table('roster_agreements')->where('kind', $kind)->orderByDesc('version')->first();

        return $row === null ? null : ['id' => (int) $row->id, 'kind' => $kind, 'version' => (int) $row->version, 'title' => (string) $row->title, 'body' => (string) $row->body];
    }

    /** Whether the agent has signed the latest version. */
    public function signed(User $agent, string $kind): bool
    {
        $latest = $this->latest($kind);

        return $latest !== null && DB::connection(Connections::cell())->table('roster_signatures')->where('user_id', $agent->id)->where('agreement_id', $latest['id'])->exists();
    }

    public function sign(User $agent, string $kind, string $typedName, bool $agreed, ?string $ip, ?string $userAgent): void
    {
        $latest = $this->latest($kind);
        if ($latest === null) {
            throw ValidationException::withMessages(['sign' => 'There is nothing to sign yet.']);
        }
        if (! $agreed || mb_strtolower(trim($typedName)) !== mb_strtolower(trim($agent->name))) {
            throw ValidationException::withMessages(['sign' => 'Tick the box and type your full name exactly as '.$agent->name.'.']);
        }
        if ($this->signed($agent, $kind)) {
            return;
        }
        $signedAt = now();
        $sha = hash('sha256', $latest['body']);
        $pdf = Pdf::loadView('pdf.signed-agreement', ['a' => $latest, 'name' => $agent->name, 'typed' => trim($typedName), 'at' => $signedAt->toDayDateTimeString().' UTC',
            'ip' => $ip, 'sha' => $sha, 'brokerage' => $this->tenants->current()->name])->output();
        $docUlid = (string) Str::ulid();
        $path = 'roster/'.$this->tenants->id().'/'.$docUlid;
        Storage::disk('local')->put($path, $pdf);
        $db = DB::connection(Connections::cell());
        $docId = $db->table('roster_documents')->insertGetId(['ulid' => $docUlid, 'tenant_id' => $this->tenants->id(), 'user_id' => $agent->id,
            'kind' => $kind === 'agreement' ? 'agreement' : 'other', 'filename' => 'Signed '.$latest['title'].' v'.$latest['version'].'.pdf', 'path' => $path,
            'mime' => 'application/pdf', 'sha256' => hash('sha256', $pdf), 'uploaded_by' => $agent->id, 'created_at' => now(), 'updated_at' => now()]);
        $db->table('roster_signatures')->insert(['tenant_id' => $this->tenants->id(), 'user_id' => $agent->id, 'agreement_id' => $latest['id'], 'typed_name' => mb_substr(trim($typedName), 0, 120),
            'ip' => $ip, 'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null, 'sha256' => $sha, 'document_id' => $docId, 'signed_at' => $signedAt]);
        app(AuditLogger::class)->record('roster.agreement.signed', $agent, metadata: ['kind' => $kind, 'version' => $latest['version'], 'sha256' => $sha], severity: 'notice', label: $agent->name);
    }
}
