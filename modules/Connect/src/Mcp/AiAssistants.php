<?php

declare(strict_types=1);

namespace Modules\Connect\Mcp;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Modules\Connect\AppRegistry;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\App;
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

        if ($app === null || ! app(AppRegistry::class)->enabled($app) || ! $this->settings()['enabled']) {
            return false;
        }
        // HQ can switch off one AI app (say ChatGPT) while the others stay on.
        $client = self::clientOf($user);
        $tile = $client !== null ? self::appFor($client) : null;

        return $tile === null || app(AppRegistry::class)->enabled($tile);
    }

    /** The registered AI app behind the person's current token, if they came through one. */
    public static function clientOf(User $user): ?Client
    {
        $token = $user->token();
        $id = $token instanceof AccessToken ? (string) ($token->oauth_client_id ?? '') : '';
        $client = $id !== '' ? Client::query()->find($id) : null;

        return $client instanceof Client ? $client : null;
    }

    /** Which AI assistant tile a registered client belongs to, from where it sends people back. */
    public static function appFor(Client $client): ?App
    {
        $hosts = array_map(fn ($u): string => strtolower((string) parse_url((string) $u, PHP_URL_HOST)), (array) $client->getAttribute('redirect_uris'));
        foreach (app(AppCatalog::class)->all() as $a) {
            if ($a->category === 'ai' && array_intersect($hosts, $a->guide['hosts'] ?? []) !== []) {
                return $a;
            }
        }

        return null;
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
     * @return list<array{client: string, app: string|null, client_id: string, user: string, user_ulid: string, mine: bool, can_write: bool, last_used: string|null, connected: string}>
     */
    public function connected(User $viewer): array
    {
        $people = $viewer->kind === UserKind::Owner ? User::query()->get() : collect([$viewer]);
        $rows = [];
        // PS1: one query for everyone's tokens instead of one per person.
        $all = Token::query()->with('client')->whereIn('user_id', $people->pluck('id')->all())->where('revoked', false)->where('expires_at', '>', now())->get()->groupBy('user_id');
        foreach ($people as $person) {
            $tokens = $all->get($person->id, collect());
            foreach ($tokens->groupBy('client_id') as $clientId => $group) {
                $latest = $group->sortByDesc('created_at')->first();
                $client = $latest?->client;
                $rows[] = ['client' => (string) ($client?->getAttribute('name') ?? 'AI assistant'), 'app' => $client instanceof Client ? self::appFor($client)?->key : null, 'client_id' => (string) $clientId, 'user' => $person->name, 'user_ulid' => $person->ulid,
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
