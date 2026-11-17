<?php

declare(strict_types=1);

namespace Modules\Api;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Partner apps (API1): software companies that connect to RightAlly with OAuth on a person's behalf. RightAlly HQ
 * registers each partner (name, company, website, what it may ask for, verified or not) and gives it its client id
 * and secret once. A brokerage's owner allows an app before anyone there can connect it; turning it off disconnects
 * everyone at once. The consent screen names the app, the company, and each thing it asks for in plain words.
 */
final class PartnerApps
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $redirects
     * @return array{client_id: string, secret: string|null}
     */
    public static function register(string $name, string $company, string $website, string $description, array $scopes, array $redirects, bool $verified, User $by): array
    {
        $scopes = array_values(array_intersect($scopes, array_keys(Scopes::ALL)));
        $redirects = array_values(array_filter($redirects, fn (string $u): bool => str_starts_with($u, 'https://') || str_starts_with($u, 'http://localhost')));
        if ($scopes === [] || $redirects === [] || trim($name) === '' || ! str_starts_with($website, 'https://')) {
            throw ValidationException::withMessages(['name' => 'Give the app a name, an https website, at least one https return address and what it may ask for.']);
        }
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(mb_substr($name, 0, 80), $redirects, true);
        DB::connection(Connections::control())->table('api_partner_apps')->insert(['client_id' => (string) $client->getKey(), 'name' => mb_substr(trim($name), 0, 80),
            'company' => mb_substr(trim($company), 0, 120), 'website' => mb_substr($website, 0, 200), 'description' => mb_substr(trim($description), 0, 500),
            'scopes' => json_encode($scopes), 'verified' => $verified, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('api.partner.registered', metadata: ['client' => (string) $client->getKey(), 'name' => $name, 'by' => $by->id], severity: 'notice');

        return ['client_id' => (string) $client->getKey(), 'secret' => $client->plainSecret];
    }

    /**
     * Active partner apps, with whether this brokerage allowed each.
     *
     * @return list<array{client_id: string, name: string, company: string, website: string, description: string, scopes: list<string>, verified: bool, allowed: bool}>
     */
    public function catalog(): array
    {
        $allowed = DB::connection(Connections::cell())->table('api_partner_grants')->where('tenant_id', $this->tenants->id())->pluck('client_id')->all();

        return array_values(DB::connection(Connections::control())->table('api_partner_apps')->where('active', true)->orderByDesc('verified')->orderBy('name')->get()
            ->map(fn (\stdClass $a): array => ['client_id' => (string) $a->client_id, 'name' => (string) $a->name, 'company' => (string) $a->company,
                'website' => (string) $a->website, 'description' => (string) $a->description, 'scopes' => array_values((array) json_decode((string) $a->scopes, true)),
                'verified' => (bool) $a->verified, 'allowed' => in_array($a->client_id, $allowed, true)])->all());
    }

    public static function find(string $clientId): ?\stdClass
    {
        return DB::connection(Connections::control())->table('api_partner_apps')->where('client_id', $clientId)->where('active', true)->first();
    }

    public function allowed(string $clientId): bool
    {
        return DB::connection(Connections::cell())->table('api_partner_grants')->where('tenant_id', $this->tenants->id())->where('client_id', $clientId)->exists();
    }

    public function setAllowed(string $clientId, bool $allowed, User $by): void
    {
        abort_unless($by->kind === UserKind::Owner, 403);
        abort_if(self::find($clientId) === null, 404);
        $db = DB::connection(Connections::cell())->table('api_partner_grants');
        if ($allowed) {
            DB::connection(Connections::cell())->table('api_partner_grants')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'client_id' => $clientId],
                ['allowed_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            $db->where('tenant_id', $this->tenants->id())->where('client_id', $clientId)->delete();
            // Everyone here who connected it is disconnected.
            $users = User::query()->pluck('id')->all();
            Token::query()->where('client_id', $clientId)->whereIn('user_id', $users)->update(['revoked' => true]);
        }
        app(AuditLogger::class)->record($allowed ? 'api.partner.allowed' : 'api.partner.blocked', metadata: ['client' => $clientId], severity: 'notice');
    }
}
