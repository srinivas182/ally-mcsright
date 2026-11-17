<?php

declare(strict_types=1);

namespace Modules\Api;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Personal API keys (API1): a key acts as the person who made it, with only the scopes they chose, and sees only
 * what they see on screen. Shown once ("ra_key_…"), stored as a hash, revocable, optionally expiring.
 */
final class Keys
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  list<string>  $scopes
     * @return array{ulid: string, key: string}
     */
    public function create(User $user, string $name, array $scopes, ?string $expires): array
    {
        if (! in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent], true)) {
            abort(403);
        }
        $scopes = array_values(array_intersect($scopes, array_keys(Scopes::ALL)));
        if ($scopes === [] || trim($name) === '') {
            throw ValidationException::withMessages(['scopes' => 'Name the key and choose what it may do.']);
        }
        if (in_array('webhooks:manage', $scopes, true) && ! in_array($user->kind, [UserKind::Owner, UserKind::Staff], true)) {
            throw ValidationException::withMessages(['scopes' => 'Only owners and staff can manage webhooks.']);
        }
        $key = 'ra_key_'.Str::random(40);
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('api_keys')->insert(['tenant_id' => $this->tenants->id(), 'ulid' => $ulid, 'user_id' => $user->id,
            'name' => mb_substr(trim(strip_tags($name)), 0, 80), 'key_hash' => hash('sha256', $key), 'last4' => substr($key, -4), 'scopes' => json_encode($scopes),
            'expires_at' => $expires !== null && $expires !== '' ? CarbonImmutable::parse($expires)->endOfDay() : null, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('api.key.created', metadata: ['key' => $ulid, 'scopes' => $scopes], severity: 'notice');

        return ['ulid' => $ulid, 'key' => $key];
    }

    public function revoke(string $ulid, User $user): void
    {
        DB::connection(Connections::cell())->table('api_keys')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)
            ->where(fn ($q) => $user->kind === UserKind::Owner ? $q : $q->where('user_id', $user->id))->update(['revoked_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('api.key.revoked', metadata: ['key' => $ulid], severity: 'notice');
    }

    /**
     * @return list<array{ulid: string, name: string, last4: string, scopes: list<string>, last_used_at: string|null, expires_at: string|null, revoked: bool, owner: string|null}>
     */
    public function list(User $user): array
    {
        $rows = DB::connection(Connections::cell())->table('api_keys')->where('tenant_id', $this->tenants->id())
            ->when($user->kind !== UserKind::Owner, fn ($q) => $q->where('user_id', $user->id))->orderByDesc('id')->get();
        $names = User::query()->whereIn('id', $rows->pluck('user_id')->unique())->get()->mapWithKeys(fn (User $u): array => [$u->id => (string) $u->name]);

        return array_values($rows->map(fn (\stdClass $k): array => ['ulid' => (string) $k->ulid, 'name' => (string) $k->name, 'last4' => (string) $k->last4,
            'scopes' => array_values((array) json_decode((string) $k->scopes, true)), 'last_used_at' => $k->last_used_at !== null ? (string) $k->last_used_at : null,
            'expires_at' => $k->expires_at !== null ? substr((string) $k->expires_at, 0, 10) : null, 'revoked' => $k->revoked_at !== null,
            'owner' => $names[$k->user_id] ?? null])->all());
    }

    /**
     * The key's person and scopes, when the key is live and belongs to this brokerage.
     *
     * @return array{user: User, scopes: list<string>, id: string}|null
     */
    public function find(string $key): ?array
    {
        if (! str_starts_with($key, 'ra_key_')) {
            return null;
        }
        $row = DB::connection(Connections::cell())->table('api_keys')->where('tenant_id', $this->tenants->id())->where('key_hash', hash('sha256', $key))->first();
        if ($row === null || $row->revoked_at !== null || ($row->expires_at !== null && CarbonImmutable::parse((string) $row->expires_at)->isPast())) {
            return null;
        }
        $user = User::query()->whereKey((int) $row->user_id)->first();
        if ($user === null || $user->status->value !== 'active') {
            return null;
        }
        if ($row->last_used_at === null || CarbonImmutable::parse((string) $row->last_used_at)->lt(now()->subMinutes(5))) {
            DB::connection(Connections::cell())->table('api_keys')->where('id', $row->id)->update(['last_used_at' => now()]);
        }

        return ['user' => $user, 'scopes' => array_values((array) json_decode((string) $row->scopes, true)), 'id' => 'key:'.$row->ulid];
    }
}
