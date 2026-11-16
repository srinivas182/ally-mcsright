<?php

declare(strict_types=1);

namespace Modules\Core\Undo;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\TenantContext;

/**
 * Undo (SM1): an action that can be put back offers "Undo" in its message for ten minutes. Modules register how to
 * put each kind back; only the person who did it can undo it, once.
 */
final class Undo
{
    public const MINUTES = 10;

    /** @var array<string, Closure(array<string, mixed>, User): void> */
    private array $handlers = [];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  Closure(array<string, mixed>, User): void  $handler
     */
    public function register(string $kind, Closure $handler): void
    {
        $this->handlers[$kind] = $handler;
    }

    /**
     * Records an action that can be undone and returns its id for the "Undo" button.
     *
     * @param  array<string, mixed>  $payload
     */
    public function offer(User $user, string $kind, array $payload, string $label): string
    {
        $ulid = (string) Str::ulid();
        DB::table('undo_actions')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'user_id' => $user->id, 'kind' => $kind, 'payload' => json_encode($payload),
            'label' => mb_substr($label, 0, 160), 'expires_at' => now()->addMinutes(self::MINUTES), 'created_at' => now(), 'updated_at' => now()]);

        return $ulid;
    }

    /** Puts it back. */
    public function run(string $ulid, User $user): string
    {
        $row = DB::table('undo_actions')->where('ulid', $ulid)->where('user_id', $user->id)->first();
        if ($row === null || $row->used_at !== null || now()->gt($row->expires_at) || ! isset($this->handlers[$row->kind])) {
            throw ValidationException::withMessages(['undo' => 'This can no longer be undone.']);
        }
        DB::table('undo_actions')->where('id', $row->id)->update(['used_at' => now(), 'updated_at' => now()]);
        ($this->handlers[$row->kind])((array) json_decode((string) $row->payload, true), $user);
        app(AuditLogger::class)->record('core.undo', metadata: ['kind' => $row->kind, 'label' => $row->label], severity: 'info');

        return (string) $row->label;
    }
}
