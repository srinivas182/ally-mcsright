<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * HQ payment settings: Stripe keys per mode (encrypted, never returned), test/live mode, the
 * agent-pays default and Stripe Tax. One row for the platform.
 */
final class PaymentSettings
{
    /**
     * @return array{mode: string, agent_pays_default: bool, automatic_tax: bool, test_set: bool, live_set: bool}
     */
    public function summary(): array
    {
        $row = $this->row();

        return ['mode' => (string) $row['mode'], 'agent_pays_default' => (bool) $row['agent_pays_default'], 'automatic_tax' => (bool) $row['automatic_tax'],
            'test_set' => $row['test_keys'] !== null, 'live_set' => $row['live_keys'] !== null];
    }

    public function mode(): string
    {
        return $this->row()['mode'];
    }

    public function agentPaysDefault(): bool
    {
        return $this->row()['agent_pays_default'];
    }

    public function automaticTax(): bool
    {
        return $this->row()['automatic_tax'];
    }

    /**
     * Keys for the current mode, or null when not set.
     *
     * @return array{publishable: string, secret: string, webhook_secret: string}|null
     */
    public function keys(): ?array
    {
        $row = $this->row();
        $sealed = $row['mode'] === 'live' ? $row['live_keys'] : $row['test_keys'];
        if ($sealed === null) {
            return null;
        }
        /** @var array{publishable: string, secret: string, webhook_secret: string} $keys */
        $keys = json_decode(Crypt::decryptString((string) $sealed), true);

        return $keys;
    }

    public function saveKeys(string $mode, string $publishable, string $secret, string $webhookSecret, User $by): void
    {
        $prefix = $mode === 'live' ? 'live' : 'test';
        if (! str_starts_with($publishable, "pk_{$prefix}_") || ! (str_starts_with($secret, "sk_{$prefix}_") || str_starts_with($secret, "rk_{$prefix}_")) || ! str_starts_with($webhookSecret, 'whsec_')) {
            throw ValidationException::withMessages(['keys' => "Use your Stripe {$prefix} keys (pk_{$prefix}_…, sk_{$prefix}_… and whsec_…)."]);
        }
        $this->update([$prefix.'_keys' => Crypt::encryptString((string) json_encode(['publishable' => $publishable, 'secret' => $secret, 'webhook_secret' => $webhookSecret])), 'updated_by' => $by->id]);
        app(AuditLogger::class)->record('billing.keys.saved', metadata: ['mode' => $prefix], severity: 'critical', label: "Stripe {$prefix} keys");
    }

    public function setMode(string $mode, User $by): void
    {
        if (! in_array($mode, ['test', 'live'], true)) {
            throw ValidationException::withMessages(['mode' => 'Choose test or live.']);
        }
        if ($mode === 'live' && $this->row()['live_keys'] === null) {
            throw ValidationException::withMessages(['mode' => 'Save your live keys before switching to live.']);
        }
        $this->update(['mode' => $mode, 'updated_by' => $by->id]);
        app(AuditLogger::class)->record('billing.mode.changed', metadata: ['mode' => $mode], severity: 'critical', label: 'Payments '.$mode);
    }

    public function setOptions(bool $agentPaysDefault, bool $automaticTax, User $by): void
    {
        $this->update(['agent_pays_default' => $agentPaysDefault, 'automatic_tax' => $automaticTax, 'updated_by' => $by->id]);
        app(AuditLogger::class)->record('billing.options.changed', metadata: ['agent_pays_default' => $agentPaysDefault, 'automatic_tax' => $automaticTax], severity: 'warning');
    }

    /**
     * @return array{mode: string, test_keys: string|null, live_keys: string|null, agent_pays_default: bool, automatic_tax: bool}
     */
    private function row(): array
    {
        $db = DB::connection(Connections::control());
        if (! $db->table('billing_settings')->exists()) {
            $db->table('billing_settings')->insert(['mode' => 'test', 'agent_pays_default' => (bool) config('billing.agent_pays_default', true), 'created_at' => now(), 'updated_at' => now()]);
        }
        $row = (array) $db->table('billing_settings')->first();

        return ['mode' => (string) $row['mode'], 'test_keys' => isset($row['test_keys']) ? (string) $row['test_keys'] : null,
            'live_keys' => isset($row['live_keys']) ? (string) $row['live_keys'] : null,
            'agent_pays_default' => (bool) $row['agent_pays_default'], 'automatic_tax' => (bool) $row['automatic_tax']];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function update(array $values): void
    {
        $this->row();
        DB::connection(Connections::control())->table('billing_settings')->update($values + ['updated_at' => now()]);
    }
}
