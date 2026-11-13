<?php

declare(strict_types=1);

namespace Modules\Forms\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Forms\Signing;
use Modules\Forms\Templates;

/**
 * Identity checks (specs/forms/01, part 5a): who must prove who they are with a government ID and a live selfie,
 * decided by the brokerage's policy and the packet's risk flags (seller-impersonation signals from the owner of
 * record); the verified name must match the signer, and a seller must match the owner of record unless a manager
 * overrides with a reason. A verification is reused for 12 months for the same person.
 */
final class IdentityChecks
{
    public const POLICIES = ['off', 'risk', 'sellers', 'everyone'];

    public const REUSE_MONTHS = 12;

    public function __construct(private readonly TenantContext $tenants, private readonly IdentityProvider $provider, private readonly OwnerRecords $records) {}

    /**
     * @return array{id_policy: string, owner_check: bool, below_value_percent: int, provider: string, provider_ready: bool}
     */
    public function settings(): array
    {
        $row = DB::connection(Connections::cell())->table('form_settings')->where('tenant_id', $this->tenants->id())->first();

        return ['id_policy' => (string) ($row->id_policy ?? 'risk'), 'owner_check' => (bool) ($row->owner_check ?? true),
            'below_value_percent' => (int) ($row->below_value_percent ?? 70), 'provider' => $this->provider->name(), 'provider_ready' => $this->provider->ready()];
    }

    public function saveSettings(string $policy, bool $ownerCheck, int $belowValuePercent, User $by): void
    {
        if (! Templates::manages($by)) {
            throw ValidationException::withMessages(['identity' => 'Only the brokerage can change identity checks.']);
        }
        if (! in_array($policy, self::POLICIES, true) || $belowValuePercent < 10 || $belowValuePercent > 100) {
            throw ValidationException::withMessages(['identity' => 'Choose a policy, and a percentage between 10 and 100.']);
        }
        DB::connection(Connections::cell())->table('form_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()],
            ['id_policy' => $policy, 'owner_check' => $ownerCheck, 'below_value_percent' => $belowValuePercent, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('forms.identity.settings', metadata: ['policy' => $policy, 'owner_check' => $ownerCheck], severity: 'notice');
    }

    /**
     * Risk flags for a packet, from its forms, the deal, the signers and the owner of record. Stored on the packet.
     *
     * @return array{flags: list<string>, owner: array<string, mixed>|null, high_risk: bool}
     */
    public function assess(int $packetId): array
    {
        $db = DB::connection(Connections::cell());
        $packet = $db->table('form_packets')->where('id', $packetId)->first();
        $deal = $packet !== null ? $db->table('tx_deals')->where('id', $packet->deal_id)->first() : null;
        if ($packet === null || $deal === null) {
            return ['flags' => [], 'owner' => null, 'high_risk' => false];
        }
        $settings = $this->settings();
        $flags = [];
        $highRisk = $db->table('form_packet_forms')->join('form_templates', 'form_templates.id', '=', 'form_packet_forms.template_id')
            ->where('form_packet_forms.packet_id', $packetId)->where('form_templates.high_risk', true)->exists();
        if ($highRisk) {
            $flags[] = 'Includes a high-risk document (such as a deed or power of attorney).';
        }
        $sellers = $db->table('form_signers')->where('packet_id', $packetId)->get()->filter(fn ($s): bool => self::isSeller((string) $s->role));
        foreach ($sellers as $s) {
            if ($s->met_in_person !== null && ! (bool) $s->met_in_person) {
                $flags[] = "{$s->name} has not met the agent in person.";
            }
        }

        $owner = null;
        if ($settings['owner_check'] && $deal->type === 'listing' && $sellers->isNotEmpty()) {
            // One lookup per packet (property-data calls cost money); later assessments reuse the stored record.
            $stored = json_decode((string) ($packet->owner_record ?? 'null'), true);
            /** @var array{owners: list<string>, mailing_state: ?string, vacant_land: bool, owner_occupied: ?bool, estimated_value_cents: ?int, source: string}|null $owner */
            $owner = is_array($stored) ? $stored : $this->records->lookup((string) $deal->street, $deal->city, (string) $deal->state_code, $deal->postal_code);
            if ($owner !== null) {
                if ($owner['vacant_land']) {
                    $flags[] = 'The property is vacant land (the most common target of seller-impersonation fraud).';
                }
                if ($owner['owner_occupied'] === false) {
                    $flags[] = 'The owner does not live at the property.';
                }
                if ($owner['mailing_state'] !== null && strtoupper($owner['mailing_state']) !== strtoupper((string) $deal->state_code)) {
                    $flags[] = "The owner's mailing address is out of state ({$owner['mailing_state']}).";
                }
                $estimate = $owner['estimated_value_cents'];
                if ($estimate !== null && $estimate > 0 && (int) $deal->price_cents > 0 && (int) $deal->price_cents * 100 < $estimate * $settings['below_value_percent']) {
                    $flags[] = sprintf('The price is well below the estimated value ($%s vs about $%s).', number_format((int) $deal->price_cents / 100), number_format($estimate / 100));
                }
                foreach ($sellers as $s) {
                    if (! self::ownerMatches((string) $s->name, $owner['owners'])) {
                        $flags[] = "{$s->name} is not named as an owner of record (".implode(', ', $owner['owners']).').';
                    }
                }
            }
        }
        $db->table('form_packets')->where('id', $packetId)->update(['risk_flags' => json_encode(array_values(array_unique($flags))), 'owner_record' => $owner !== null ? json_encode($owner) : null]);

        return ['flags' => array_values(array_unique($flags)), 'owner' => $owner, 'high_risk' => $highRisk];
    }

    /** At sending: marks who must verify and reuses a recent verification of the same person. */
    public function prepare(int $packetId): void
    {
        $db = DB::connection(Connections::cell());
        ['flags' => $flags, 'high_risk' => $highRisk] = $this->assess($packetId);
        $policy = $this->settings()['id_policy'];
        foreach ($db->table('form_signers')->where('packet_id', $packetId)->get() as $s) {
            $seller = self::isSeller((string) $s->role);
            $agent = str_ends_with((string) $s->role, '_agent');
            $required = ! $agent && match ($policy) {
                'everyone' => true,
                'sellers' => $seller || $highRisk,
                'risk' => $highRisk || ($seller && $flags !== []),
                default => false,
            };
            $reuse = $required ? $db->table('form_identity_checks')->where('email', mb_strtolower((string) $s->email))->where('status', 'verified')
                ->where('expires_at', '>', now())->orderByDesc('id')->get()->first(fn ($c): bool => self::nameMatches((string) $s->name, (string) $c->verified_name)) : null;
            $db->table('form_signers')->where('id', $s->id)->update(['id_required' => $required, 'identity_check_id' => $reuse?->id, 'id_status' => $reuse !== null ? 'verified' : null]);
            if ($reuse !== null) {
                app(Signing::class)->recordEvent($packetId, (int) $s->id, 'id_reused', 'Identity verified on '.substr((string) $reuse->checked_at, 0, 10).' reused (valid '.self::REUSE_MONTHS.' months)');
                $this->ownerGate($packetId, $s, (string) $reuse->verified_name);
            }
        }
    }

    /** Problems that stop sending: checks are required but no identity service is ready. */
    public function sendProblem(int $packetId): ?string
    {
        if ($this->provider->ready()) {
            return null;
        }
        $policy = $this->settings()['id_policy'];
        if ($policy === 'off') {
            return null;
        }
        $risk = $this->assess($packetId);
        $sellers = DB::connection(Connections::cell())->table('form_signers')->where('packet_id', $packetId)->pluck('role')->contains(fn ($r): bool => self::isSeller((string) $r));
        $needed = $policy === 'everyone' || $risk['high_risk'] || ($sellers && ($policy === 'sellers' || $risk['flags'] !== []));

        return $needed ? 'Identity checks are needed for this packet, but no identity service is set up (connect the brokerage\'s Stripe account under Billing).' : null;
    }

    /** Starts a verification for the signer behind this link; returns the identity service's page. */
    public function start(string $token, string $baseUrl): string
    {
        ['signer' => $s, 'packet' => $p] = app(Signing::class)->byToken($token);
        if (! (bool) $s->id_required || $s->id_status === 'verified') {
            return $baseUrl.'/sign/'.$token;
        }
        if ($s->consent_at === null) {
            throw ValidationException::withMessages(['identity' => 'Agree to sign electronically first.']);
        }
        $db = DB::connection(Connections::cell());
        $ulid = (string) Str::ulid();
        $started = $this->provider->start($ulid, (string) $s->email, $baseUrl.'/sign/'.$token.'/identity/return');
        $id = $db->table('form_identity_checks')->insertGetId(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'email' => mb_strtolower((string) $s->email),
            'name' => (string) $s->name, 'provider' => $this->provider->name(), 'provider_ref' => $started['ref'], 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('form_signers')->where('id', $s->id)->update(['identity_check_id' => $id, 'id_status' => null]);
        app(Signing::class)->recordEvent((int) $p->id, (int) $s->id, 'id_started', 'Identity check started with '.$this->provider->name());

        return $started['url'];
    }

    /** Back from the identity service: records the outcome and checks the name (and ownership for sellers). */
    public function finish(string $token, ?string $ip): void
    {
        ['signer' => $s, 'packet' => $p] = app(Signing::class)->byToken($token);
        $db = DB::connection(Connections::cell());
        $check = $s->identity_check_id !== null ? $db->table('form_identity_checks')->where('id', $s->identity_check_id)->where('status', 'pending')->first() : null;
        if ($check === null) {
            return;
        }
        $result = $this->provider->fetch((string) $check->provider_ref);
        if ($result['status'] === 'pending') {
            return;
        }
        $verified = $result['status'] === 'verified';
        $db->table('form_identity_checks')->where('id', $check->id)->update(['status' => $result['status'], 'verified_name' => $result['name'], 'document_type' => $result['document_type'],
            'document_country' => $result['document_country'] !== null ? substr($result['document_country'], 0, 2) : null, 'failure_reason' => $result['reason'],
            'checked_at' => now(), 'expires_at' => $verified ? now()->addMonths(self::REUSE_MONTHS) : null, 'updated_at' => now()]);
        $signing = app(Signing::class);
        if (! $verified) {
            $db->table('form_signers')->where('id', $s->id)->update(['id_status' => 'failed']);
            $signing->recordEvent((int) $p->id, (int) $s->id, 'id_failed', 'Identity check not passed'.($result['reason'] !== null ? ': '.$result['reason'] : ''), $ip);

            return;
        }
        if (! self::nameMatches((string) $s->name, (string) $result['name'])) {
            $db->table('form_signers')->where('id', $s->id)->update(['id_status' => 'name_mismatch']);
            $db->table('form_identity_checks')->where('id', $check->id)->update(['expires_at' => null]);
            $signing->recordEvent((int) $p->id, (int) $s->id, 'id_mismatch', "ID shows \"{$result['name']}\", not \"{$s->name}\"", $ip);
            $this->alertAgent($p, "{$s->name}'s ID shows a different name (\"{$result['name']}\"). Signing is paused for this person.");

            return;
        }
        $db->table('form_signers')->where('id', $s->id)->update(['id_status' => 'verified']);
        $signing->recordEvent((int) $p->id, (int) $s->id, 'id_verified', 'Identity verified ('.self::documentLabel($result['document_type'], $result['document_country']).') with '.$this->provider->name(), $ip);
        $this->ownerGate((int) $p->id, $s, (string) $result['name']);
    }

    /** A manager accepts a seller who is not the owner of record (a trust, an estate, a recent purchase), with a reason. */
    public function overrideOwner(string $packetUlid, string $reason, User $by): void
    {
        if (! Templates::manages($by)) {
            throw ValidationException::withMessages(['owner' => 'Only the broker or office staff can accept a seller who is not the owner of record.']);
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['owner' => 'Explain why (for example: "Sold by the trustee of the Gomez Family Trust; trust certificate on file").']);
        }
        $db = DB::connection(Connections::cell());
        $p = $db->table('form_packets')->where('ulid', $packetUlid)->first() ?? throw ValidationException::withMessages(['owner' => 'Packet not found.']);
        $db->table('form_packets')->where('id', $p->id)->update(['owner_override_by' => $by->id, 'owner_override_reason' => mb_substr(trim($reason), 0, 300)]);
        $db->table('form_signers')->where('packet_id', $p->id)->where('id_status', 'owner_mismatch')->update(['id_status' => 'verified']);
        app(Signing::class)->recordEvent((int) $p->id, null, 'owner_override', 'Owner-of-record mismatch accepted by '.$by->name.': '.trim($reason));
        app(AuditLogger::class)->record('forms.identity.owner_override', metadata: ['packet' => $packetUlid, 'reason' => trim($reason)], severity: 'critical');
    }

    /** Null when this signer may sign; otherwise what stops them. */
    public static function blocker(\stdClass $signer): ?string
    {
        if (! (bool) $signer->id_required || $signer->id_status === 'verified') {
            return null;
        }

        return match ($signer->id_status) {
            'name_mismatch' => 'The name on your ID does not match. Your agent has been told and will be in touch.',
            'owner_mismatch' => 'We need to confirm ownership before you sign. Your agent has been told and will be in touch.',
            default => 'Verify your identity first.',
        };
    }

    public static function isSeller(string $role): bool
    {
        return str_starts_with($role, 'seller');
    }

    /** First and last name of the signer both appear in the verified name (middle names and order may differ). */
    public static function nameMatches(string $expected, string $actual): bool
    {
        $e = self::tokens($expected);
        $a = self::tokens($actual);

        return $e !== [] && in_array($e[0], $a, true) && in_array($e[count($e) - 1], $a, true);
    }

    /**
     * A seller is an owner when their last name and first name (or its initial) appear in one owner line,
     * in any order ("GOMEZ MARIA L", "Maria L. Gomez").
     *
     * @param  list<string>  $owners
     */
    public static function ownerMatches(string $name, array $owners): bool
    {
        $n = self::tokens($name);
        if ($n === []) {
            return false;
        }
        foreach ($owners as $owner) {
            $o = self::tokens($owner);
            $first = $n[0];
            if (in_array($n[count($n) - 1], $o, true) && (in_array($first, $o, true) || in_array($first[0], $o, true))) {
                return true;
            }
        }

        return false;
    }

    public static function documentLabel(?string $type, ?string $country): string
    {
        $label = match ($type) {
            'driving_license' => 'driver\'s licence',
            'passport' => 'passport',
            'id_card' => 'ID card',
            default => 'government ID',
        };

        return $label.($country !== null ? ', '.strtoupper($country) : '').', live selfie';
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $name): array
    {
        $clean = mb_strtolower((string) preg_replace('/[^\pL\s-]/u', ' ', Str::ascii($name)));

        return array_values(array_filter(preg_split('/[\s-]+/', $clean) ?: [], fn (string $t): bool => $t !== '' && ! in_array($t, ['jr', 'sr', 'ii', 'iii', 'mr', 'mrs', 'ms', 'dr'], true)));
    }

    private function ownerGate(int $packetId, \stdClass $signer, string $verifiedName): void
    {
        if (! self::isSeller((string) $signer->role)) {
            return;
        }
        $db = DB::connection(Connections::cell());
        $p = $db->table('form_packets')->where('id', $packetId)->first();
        $record = $p !== null ? json_decode((string) ($p->owner_record ?? 'null'), true) : null;
        if (! is_array($record) || $p->owner_override_by !== null || self::ownerMatches($verifiedName, array_values(array_map('strval', (array) $record['owners'])))) {
            return;
        }
        $db->table('form_signers')->where('id', $signer->id)->update(['id_status' => 'owner_mismatch']);
        app(Signing::class)->recordEvent($packetId, (int) $signer->id, 'owner_mismatch', "Verified name \"{$verifiedName}\" is not an owner of record (".implode(', ', (array) $record['owners']).')');
        $this->alertAgent($p, "{$signer->name} passed the ID check but is not named as an owner of record (".implode(', ', (array) $record['owners']).'). Signing is paused until the broker confirms ownership.');
    }

    private function alertAgent(\stdClass $packet, string $message): void
    {
        $db = DB::connection(Connections::cell());
        User::query()->find((int) $db->table('tx_deals')->where('id', $packet->deal_id)->value('agent_user_id'))
            ?->notify(new IdentityAlert((string) $packet->title, $message, (string) $packet->ulid));
        app(AuditLogger::class)->record('forms.identity.alert', metadata: ['packet' => $packet->ulid, 'message' => $message], severity: 'critical');
    }
}
