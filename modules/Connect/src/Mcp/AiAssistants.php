<?php

declare(strict_types=1);

namespace Modules\Connect\Mcp;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Modules\Connect\AppRegistry;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\McpGate;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * AI assistant access (MCP, specs/connect/03): HQ's switch (the "ai_assistants" app), the brokerage owner's switch,
 * and each person's connected assistants (their OAuth tokens), which they or an owner can disconnect.
 */
final class AiAssistants implements McpGate
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function allowed(User $user): bool
    {
        $app = app(AppCatalog::class)->find('ai_assistants');

        return $app !== null && app(AppRegistry::class)->enabled($app) && $this->settings()['enabled'];
    }

    public function writesAllowed(User $user): bool
    {
        return $this->allowed($user) && $this->settings()['allow_writes'];
    }

    /** @return array{enabled: bool, allow_writes: bool} */
    public function settings(): array
    {
        $row = DB::connection(Connections::cell())->table('connect_mcp_settings')->where('tenant_id', $this->tenants->id())->first();

        return ['enabled' => (bool) ($row->enabled ?? true), 'allow_writes' => (bool) ($row->allow_writes ?? true)];
    }

    public function save(bool $enabled, bool $allowWrites, User $by): void
    {
        abort_unless($by->kind === UserKind::Owner, 403);
        DB::connection(Connections::cell())->table('connect_mcp_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()],
            ['enabled' => $enabled, 'allow_writes' => $allowWrites, 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        if (! $enabled) {
            // Switched off: every connected assistant is disconnected now, not just refused.
            foreach (User::query()->pluck('id') as $id) {
                $this->revoke(array_map('strval', Token::query()->where('user_id', $id)->where('revoked', false)->pluck('id')->all()));
            }
        }
        app(AuditLogger::class)->record('connect.mcp_settings', metadata: ['enabled' => $enabled, 'allow_writes' => $allowWrites], severity: 'warning');
    }

    /**
     * Connected assistants: one row per AI app per person (owners see everyone's; others their own).
     *
     * @return list<array{client: string, client_id: string, user: string, user_ulid: string, mine: bool, can_write: bool, last_used: string|null, connected: string}>
     */
    public function connected(User $viewer): array
    {
        $people = $viewer->kind === UserKind::Owner ? User::query()->get() : collect([$viewer]);
        $rows = [];
        foreach ($people as $person) {
            $tokens = Token::query()->with('client')->where('user_id', $person->id)->where('revoked', false)->where('expires_at', '>', now())->get();
            foreach ($tokens->groupBy('client_id') as $clientId => $group) {
                $latest = $group->sortByDesc('created_at')->first();
                $rows[] = ['client' => (string) ($latest?->client->name ?? 'AI assistant'), 'client_id' => (string) $clientId, 'user' => $person->name, 'user_ulid' => $person->ulid,
                    'mine' => $person->id === $viewer->id, 'can_write' => $group->contains(fn (Token $t): bool => $t->can('rightally:write')),
                    'last_used' => $latest !== null ? (string) $latest->getAttribute('updated_at') : null, 'connected' => (string) $group->min('created_at')];
            }
        }

        return $rows;
    }

    /** @param array<string> $tokenIds access tokens and their refresh tokens, revoked together */
    private function revoke(array $tokenIds): void
    {
        Token::query()->whereIn('id', $tokenIds)->update(['revoked' => true]);
        RefreshToken::query()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
    }

    public function disconnect(User $viewer, string $userUlid, string $clientId): void
    {
        $person = User::query()->where('ulid', $userUlid)->first();
        abort_unless($person instanceof User && ($person->id === $viewer->id || $viewer->kind === UserKind::Owner), 404);
        $this->revoke(array_map('strval', Token::query()->where('user_id', $person->id)->where('client_id', $clientId)->pluck('id')->all()));
        app(AuditLogger::class)->record('connect.mcp_disconnected', metadata: ['user' => $person->id, 'client' => $clientId], severity: 'notice');
    }
}
