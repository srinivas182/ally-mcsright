<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Identity\Impersonation;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Records audit events (specs/core/04). Writes to audit_outbox in the caller's transaction, so an event
 * exists exactly when the change it describes exists; sealing happens after commit.
 *
 * Privacy: only a SHA-256 of the session ID is kept, never the ID; no tokens, passwords or secrets;
 * values listed as sensitive are masked to their last four characters.
 */
final class AuditLogger
{
    /** @var list<array{automation: string, on_behalf_of: int|null, scope: string|null, actor_type: string}> */
    private array $automation = [];

    private ?string $requestId = null;

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @param  array<string, mixed>  $metadata
     */
    public function record(string $action, ?Model $subject = null, array $changes = [], array $metadata = [], string $severity = 'info', ?string $label = null, ?int $tenantId = null): void
    {
        $tenantId ??= $this->tenants->check() ? $this->tenants->id() : null;
        if ($tenantId === null) {
            return; // platform-level HQ events arrive with the HQ console audit (Sprint 7A-2)
        }

        $request = app()->bound('request') ? request() : null;
        $user = Auth::guard('web')->user();
        $auto = end($this->automation) ?: null;
        $impersonation = $request !== null ? app(Impersonation::class)->current($request) : null;

        $payload = [
            'ulid' => (string) Str::ulid(),
            'occurred_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
            'actor_type' => $auto['actor_type'] ?? ($impersonation !== null && $impersonation['by_kind'] === 'hq' ? 'hq_support' : ($user instanceof User ? 'user' : 'system')),
            'actor_id' => $auto === null && $user instanceof User ? $user->id : null,
            'actor_name' => $auto === null && $user instanceof User ? $user->name : null,
            'actor_kind' => $auto === null && $user instanceof User ? $user->kind->value : null,
            'impersonator_id' => $impersonation['by_id'] ?? null,
            'impersonator_name' => $impersonation['by_name'] ?? null,
            'on_behalf_of_user_id' => $auto['on_behalf_of'] ?? null,
            'automation' => $auto['automation'] ?? null,
            'automation_scope' => $auto['scope'] ?? null,
            'ip' => $request?->ip(),
            'user_agent' => $request !== null ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'session_ref' => $request !== null && $request->hasSession() ? hash('sha256', $request->session()->getId()) : null,
            'request_id' => $request !== null ? $this->requestId($request->header('X-Request-Id')) : null,
            'action' => mb_substr($action, 0, 80),
            'subject_type' => $subject !== null ? class_basename($subject) : null,
            'subject_id' => $subject !== null ? (string) ($subject->getAttribute('ulid') ?? $subject->getKey()) : null,
            'subject_label' => $label !== null ? mb_substr($label, 0, 200) : ($subject !== null ? self::label($subject) : null),
            'changes' => $changes === [] ? null : $changes,
            'metadata' => $metadata === [] ? null : $metadata,
            'severity' => in_array($severity, ['info', 'notice', 'warning', 'critical'], true) ? $severity : 'info',
        ];

        DB::connection(Connections::cell())->table('audit_outbox')->insert([
            'tenant_id' => $tenantId, 'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'created_at' => $payload['occurred_at'],
        ]);

        FlushAuditOutbox::dispatch()->afterCommit();
    }

    /**
     * Run $work as an automation acting for a person (on_behalf_of in every event it causes).
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function asAutomation(string $automation, ?int $onBehalfOf, ?string $scope, Closure $work, string $actorType = 'system'): mixed
    {
        $this->automation[] = ['automation' => $automation, 'on_behalf_of' => $onBehalfOf, 'scope' => $scope, 'actor_type' => $actorType];

        try {
            return $work();
        } finally {
            array_pop($this->automation);
        }
    }

    public static function mask(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string) $value;

        return mb_strlen($value) <= 4 ? '***' : '***'.mb_substr($value, -4);
    }

    private function requestId(?string $header): string
    {
        return $this->requestId ??= ($header !== null && preg_match('/^[A-Za-z0-9\-]{8,64}$/', $header) === 1 ? $header : (string) Str::uuid());
    }

    private static function label(Model $subject): ?string
    {
        foreach (['name', 'title', 'label'] as $attribute) {
            $value = $subject->getAttribute($attribute);
            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 200);
            }
        }

        return null;
    }
}
