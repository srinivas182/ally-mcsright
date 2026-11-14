<?php

declare(strict_types=1);

namespace Modules\Core\Messaging;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * Texts and automated calls as configured by RightAlly HQ: the Twilio account, numbers and platform switches,
 * and which brokerages may use texts and calls for alerts. The Twilio token is encrypted and never shown again.
 */
final class MessagingSettings
{
    /**
     * @return array{configured: bool, sid_last4: ?string, sms_from: ?string, voice_from: ?string, sms_enabled: bool, voice_enabled: bool}
     */
    public function summary(): array
    {
        $row = $this->row();
        $creds = $this->credentials();

        return ['configured' => $creds !== null, 'sid_last4' => $creds !== null ? substr($creds['sid'], -4) : null, 'sms_from' => $row->sms_from ?? null,
            'voice_from' => $row->voice_from ?? null, 'sms_enabled' => (bool) ($row->sms_enabled ?? false), 'voice_enabled' => (bool) ($row->voice_enabled ?? false)];
    }

    public function save(?string $sid, ?string $token, ?string $smsFrom, ?string $voiceFrom, bool $smsEnabled, bool $voiceEnabled, User $by): void
    {
        foreach (['sms_from' => $smsFrom, 'voice_from' => $voiceFrom] as $field => $number) {
            if ($number !== null && $number !== '' && preg_match('/^\+1\d{10}$/', $number) !== 1) {
                throw ValidationException::withMessages([$field => 'Use a US number in +1 format, for example +15125550100.']);
            }
        }
        $values = ['sms_from' => $smsFrom ?: null, 'voice_from' => $voiceFrom ?: null, 'sms_enabled' => $smsEnabled, 'voice_enabled' => $voiceEnabled,
            'updated_by' => $by->id, 'updated_at' => now()];
        if ($sid !== null && $sid !== '') {
            if (preg_match('/^AC[0-9a-f]{32}$/', $sid) !== 1 || $token === null || mb_strlen($token) < 20) {
                throw ValidationException::withMessages(['sid' => 'Give the Twilio Account SID (starts with AC) and its auth token.']);
            }
            $values['credentials'] = Crypt::encryptString((string) json_encode(['sid' => $sid, 'token' => $token]));
        }
        if (($smsEnabled && ($values['sms_from'] === null)) || ($voiceEnabled && $values['voice_from'] === null)) {
            throw ValidationException::withMessages(['sms_from' => 'Give the number to send from before turning texts or calls on.']);
        }
        if (($smsEnabled || $voiceEnabled) && ! isset($values['credentials']) && $this->credentials() === null) {
            throw ValidationException::withMessages(['sid' => 'Add the Twilio account before turning texts or calls on.']);
        }
        $db = DB::connection(Connections::control());
        $id = $db->table('hq_messaging')->value('id');
        $id === null ? $db->table('hq_messaging')->insert($values + ['created_at' => now()]) : $db->table('hq_messaging')->where('id', $id)->update($values);
        app(AuditLogger::class)->record('hq.messaging.saved', metadata: ['sms' => $smsEnabled, 'voice' => $voiceEnabled, 'credentials_changed' => isset($values['credentials'])], severity: 'critical');
    }

    public function setTenant(int $tenantId, bool $sms, bool $voice): void
    {
        DB::connection(Connections::control())->table('hq_messaging_tenants')->updateOrInsert(['tenant_id' => $tenantId],
            ['sms' => $sms, 'voice' => $voice, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('hq.messaging.tenant', metadata: ['tenant' => $tenantId, 'sms' => $sms, 'voice' => $voice], severity: 'notice');
    }

    /** Whether this brokerage may text (HQ switched texts on for the platform and for the brokerage). */
    public function smsFor(int $tenantId): bool
    {
        return $this->summary()['sms_enabled'] && (bool) DB::connection(Connections::control())->table('hq_messaging_tenants')->where('tenant_id', $tenantId)->value('sms');
    }

    public function voiceFor(int $tenantId): bool
    {
        return $this->summary()['voice_enabled'] && (bool) DB::connection(Connections::control())->table('hq_messaging_tenants')->where('tenant_id', $tenantId)->value('voice');
    }

    /**
     * @return array<int, array{sms: bool, voice: bool}>
     */
    public function tenants(): array
    {
        return DB::connection(Connections::control())->table('hq_messaging_tenants')->get()
            ->mapWithKeys(fn ($r): array => [(int) $r->tenant_id => ['sms' => (bool) $r->sms, 'voice' => (bool) $r->voice]])->all();
    }

    /**
     * @return array{sid: string, token: string}|null
     */
    public function credentials(): ?array
    {
        $sealed = $this->row()->credentials ?? null;
        if ($sealed !== null) {
            $c = (array) json_decode(Crypt::decryptString((string) $sealed), true);

            return ['sid' => (string) ($c['sid'] ?? ''), 'token' => (string) ($c['token'] ?? '')];
        }
        $sid = (string) config('services.twilio.sid');

        return $sid !== '' ? ['sid' => $sid, 'token' => (string) config('services.twilio.token')] : null;
    }

    public function smsFrom(): string
    {
        return (string) ($this->row()->sms_from ?? config('services.twilio.from'));
    }

    public function voiceFrom(): string
    {
        return (string) ($this->row()->voice_from ?? $this->smsFrom());
    }

    private function row(): ?\stdClass
    {
        try {
            return DB::connection(Connections::control())->table('hq_messaging')->first();
        } catch (\Throwable) {
            return null; // before migrations (fresh install)
        }
    }
}
