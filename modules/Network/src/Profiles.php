<?php

declare(strict_types=1);

namespace Modules\Network;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use stdClass;

/**
 * Network profiles (AR1, specs/network/01): an agent's card in the referral network: where they work, which side,
 * languages, and (only if they choose) a phone number and a public page. A brokerage owner switches the network on
 * for the brokerage and can mark each profile's licence as checked.
 */
final class Profiles
{
    public const SIDES = ['buyers' => 'Buyers', 'sellers' => 'Sellers', 'both' => 'Buyers and sellers'];

    public const DEFAULT_FEE = '25';

    public function __construct(private readonly TenantContext $tenants) {}

    /** @return array{enabled: bool, default_fee_percent: string} */
    public function brokerage(?int $tenantId = null): array
    {
        $row = DB::connection(Connections::control())->table('network_brokerages')->where('tenant_id', $tenantId ?? $this->tenants->id())->first();

        return ['enabled' => (bool) ($row->enabled ?? false), 'default_fee_percent' => rtrim(rtrim((string) ($row->default_fee_percent ?? self::DEFAULT_FEE), '0'), '.')];
    }

    public function saveBrokerage(bool $enabled, string $fee, User $by): void
    {
        if (! is_numeric($fee) || (float) $fee <= 0 || (float) $fee > 50) {
            throw ValidationException::withMessages(['default_fee_percent' => 'Enter a referral fee between 1% and 50%.']);
        }
        DB::connection(Connections::control())->table('network_brokerages')->updateOrInsert(['tenant_id' => $this->tenants->id()],
            ['enabled' => $enabled, 'default_fee_percent' => round((float) $fee, 2), 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        if (! $enabled) {
            DB::connection(Connections::control())->table('network_profiles')->where('tenant_id', $this->tenants->id())->update(['status' => 'paused', 'updated_at' => now()]);
        }
        app(AuditLogger::class)->record('network.brokerage.saved', metadata: ['enabled' => $enabled, 'fee' => $fee], severity: 'notice');
    }

    public function mine(User $user): ?stdClass
    {
        return DB::connection(Connections::control())->table('network_profiles')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)->first();
    }

    public function find(int $id): ?stdClass
    {
        return DB::connection(Connections::control())->table('network_profiles')->where('id', $id)->first();
    }

    public function byUlid(string $ulid): ?stdClass
    {
        return DB::connection(Connections::control())->table('network_profiles')->where('ulid', $ulid)->first();
    }

    /**
     * Creates or updates the user's profile.
     *
     * @param  array{areas: list<string>, sides: string, languages: list<string>, state_code: string, licence?: string|null, bio?: string|null, phone?: string|null, share_phone?: bool, public?: bool, paused?: bool}  $in
     */
    public function save(User $user, array $in): stdClass
    {
        if (! $this->brokerage()['enabled']) {
            throw ValidationException::withMessages(['network' => 'Your brokerage hasn\'t switched on the referral network yet. Ask your broker.']);
        }
        if (! in_array($user->kind, [UserKind::Agent, UserKind::Owner], true)) {
            throw ValidationException::withMessages(['network' => 'The referral network is for agents and brokers.']);
        }
        $areas = array_values(array_unique(array_filter(array_map(fn ($a): string => mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) $a)), 0, 60), $in['areas']))));
        if ($areas === [] || count($areas) > 12) {
            throw ValidationException::withMessages(['areas' => 'List the cities or ZIP codes you work in (up to 12).']);
        }
        $languages = array_values(array_unique(array_filter(array_map(fn ($l): string => mb_substr(trim((string) $l), 0, 30), $in['languages'] ?: ['English']))));
        $phone = isset($in['phone']) && trim((string) $in['phone']) !== '' ? (string) preg_replace('/[^0-9+]/', '', (string) $in['phone']) : null;
        if (($in['share_phone'] ?? false) && ($phone === null || strlen($phone) < 10)) {
            throw ValidationException::withMessages(['phone' => 'Enter the phone number to share.']);
        }
        $existing = $this->mine($user);
        $row = ['name' => mb_substr($user->name, 0, 160), 'brokerage' => mb_substr($this->tenants->current()->name, 0, 160), 'state_code' => strtoupper(substr($in['state_code'], 0, 2)),
            'licence' => isset($in['licence']) && trim((string) $in['licence']) !== '' ? mb_substr(trim((string) $in['licence']), 0, 40) : null,
            'areas' => json_encode($areas), 'areas_text' => mb_substr(mb_strtolower(implode(' | ', $areas)), 0, 1000),
            'sides' => array_key_exists($in['sides'], self::SIDES) ? $in['sides'] : 'both', 'languages' => json_encode($languages),
            'bio' => isset($in['bio']) && trim((string) $in['bio']) !== '' ? mb_substr(trim((string) $in['bio']), 0, 500) : null,
            'phone' => $phone, 'share_phone' => (bool) ($in['share_phone'] ?? false), 'public' => (bool) ($in['public'] ?? false),
            'status' => ($in['paused'] ?? false) ? 'paused' : 'active', 'updated_at' => now()];
        $db = DB::connection(Connections::control())->table('network_profiles');
        if ($existing !== null) {
            if ($existing->licence !== $row['licence']) {
                $row['verified_at'] = null; // a new licence number needs checking again
            }
            $db->clone()->where('id', $existing->id)->update($row);
        } else {
            $db->clone()->insert($row + ['ulid' => (string) Str::ulid(), 'code' => $this->code($user->name), 'tenant_id' => $this->tenants->id(), 'user_id' => $user->id, 'created_at' => now()]);
        }
        app(AuditLogger::class)->record('network.profile.saved', metadata: ['public' => $row['public'], 'share_phone' => $row['share_phone']]);

        return $this->mine($user) ?? throw new \RuntimeException('Profile not saved.');
    }

    /** The owner confirms (or un-confirms) a profile's licence number. */
    public function verify(string $ulid, bool $checked, User $by): void
    {
        $n = DB::connection(Connections::control())->table('network_profiles')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->whereNotNull('licence')
            ->update(['verified_at' => $checked ? now() : null, 'updated_at' => now()]);
        if ($n === 0) {
            throw ValidationException::withMessages(['profile' => 'Only profiles with a licence number can be checked.']);
        }
        app(AuditLogger::class)->record('network.profile.verified', metadata: ['profile' => $ulid, 'checked' => $checked, 'by' => $by->id], severity: 'notice');
    }

    /**
     * Agents in the network who cover an area (city, "City, ST" or ZIP), optionally for one side or language. Other
     * brokerages' agents come first; the asking brokerage's own agents are included too.
     *
     * @return list<stdClass>
     */
    public function search(string $area, ?string $side = null, ?string $language = null, int $limit = 30): array
    {
        $area = mb_strtolower(trim($area));
        $city = trim(explode(',', $area)[0]);
        if (mb_strlen($city) < 2) {
            return [];
        }
        $q = DB::connection(Connections::control())->table('network_profiles')->where('status', 'active')
            ->whereIn('tenant_id', DB::connection(Connections::control())->table('network_brokerages')->where('enabled', true)->select('tenant_id'))
            ->where('areas_text', 'like', '%'.addcslashes($city, '%_').'%');
        if ($side === 'buyer') {
            $q->whereIn('sides', ['buyers', 'both']);
        } elseif ($side === 'seller') {
            $q->whereIn('sides', ['sellers', 'both']);
        }
        if ($language !== null && $language !== '' && mb_strtolower($language) !== 'english') {
            $q->where('languages', 'like', '%'.addcslashes(mb_convert_case(trim($language), MB_CASE_TITLE), '%_').'%');
        }

        return array_values($q->orderByRaw('trust_score is null')->orderByDesc('trust_score')->orderByDesc('verified_at')->orderBy('name')->limit($limit)->get()->all());
    }

    /**
     * A profile as others see it (never the email; the phone only if shared).
     *
     * @return array{ulid: string, code: string, name: string, brokerage: string, state: string, areas: list<string>, sides: string, languages: list<string>, bio: string|null, phone: string|null, verified: bool, public: bool, trust: array{score: int|null, count: int, parts: array<string, mixed>}}
     */
    public static function card(stdClass $p): array
    {
        return ['ulid' => (string) $p->ulid, 'code' => (string) $p->code, 'name' => (string) $p->name, 'brokerage' => (string) $p->brokerage, 'state' => (string) $p->state_code,
            'areas' => array_values((array) json_decode((string) $p->areas, true)), 'sides' => self::SIDES[(string) $p->sides] ?? 'Buyers and sellers',
            'languages' => array_values((array) json_decode((string) $p->languages, true)), 'bio' => $p->bio !== null ? (string) $p->bio : null,
            'phone' => (bool) $p->share_phone ? (string) $p->phone : null, 'verified' => $p->verified_at !== null, 'public' => (bool) $p->public,
            'trust' => ['score' => $p->trust_score !== null ? (int) $p->trust_score : null, 'count' => (int) $p->trust_count, 'parts' => (array) json_decode((string) ($p->trust_parts ?? '{}'), true)]];
    }

    private function code(string $name): string
    {
        $base = Str::slug(mb_substr($name, 0, 30)) ?: 'agent';
        $code = $base;
        while (DB::connection(Connections::control())->table('network_profiles')->where('code', $code)->exists()) {
            $code = $base.'-'.random_int(10, 9999);
        }

        return $code;
    }
}
