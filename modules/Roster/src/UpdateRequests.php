<?php

declare(strict_types=1);

namespace Modules\Roster;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Agent self-service updates (specs/roster/01, addition 7): an agent submits a renewed licence, E&O or
 * membership with the document; it takes effect only when the brokerage approves.
 */
final class UpdateRequests
{
    public function __construct(private readonly TenantContext $tenants, private readonly Roster $roster) {}

    /**
     * @param  array{state_code?: string|null, number?: string|null, issuer?: string|null, coverage_cents?: int|null, expires_on?: string|null}  $data
     */
    public function submit(User $agent, string $kind, array $data, UploadedFile $document): string
    {
        if (! in_array($kind, Roster::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Choose what you are updating.']);
        }
        $docUlid = $this->roster->uploadAs($agent, $kind === 'eo' ? 'eo_certificate' : ($kind === 'licence' ? 'licence_copy' : 'other'), $document);
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('roster_requests')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'user_id' => $agent->id, 'kind' => $kind,
            'data' => json_encode($data), 'document_id' => DB::connection(Connections::cell())->table('roster_documents')->where('ulid', $docUlid)->value('id'),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('roster.request.submitted', $agent, metadata: ['kind' => $kind], label: $agent->name);

        return $ulid;
    }

    public function decide(string $ulid, bool $approve, ?string $note, User $by): void
    {
        $db = DB::connection(Connections::cell());
        $request = $db->table('roster_requests')->where('ulid', $ulid)->where('status', 'pending')->first();
        if ($request === null) {
            throw ValidationException::withMessages(['request' => 'This update has already been decided.']);
        }
        if (! $approve && mb_strlen(trim((string) $note)) < 5) {
            throw ValidationException::withMessages(['note' => 'Tell the agent why (they will see this).']);
        }
        $agent = User::query()->findOrFail((int) $request->user_id);
        if ($approve) {
            $this->roster->add($agent, (string) $request->kind, (array) json_decode((string) $request->data, true), $by);
        }
        $db->table('roster_requests')->where('id', $request->id)->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $by->id,
            'decision_note' => $note !== null ? mb_substr(trim($note), 0, 300) : null, 'updated_at' => now()]);
        $agent->notify(new UpdateDecided((string) $request->kind, $approve, $note));
        app(AuditLogger::class)->record('roster.request.'.($approve ? 'approved' : 'rejected'), $agent, metadata: ['kind' => $request->kind, 'note' => $note], severity: 'notice', label: $agent->name);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(?int $userId = null): array
    {
        $q = DB::connection(Connections::cell())->table('roster_requests')->where('status', 'pending')->orderBy('id');
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return array_values($q->get()->map(fn ($r): array => ['ulid' => $r->ulid, 'agent' => User::query()->find((int) $r->user_id)?->name, 'kind' => $r->kind,
            'data' => json_decode((string) $r->data, true), 'document' => DB::connection(Connections::cell())->table('roster_documents')->where('id', $r->document_id)->value('ulid'),
            'submitted_at' => (string) $r->created_at])->all());
    }
}
