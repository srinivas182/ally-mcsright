<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Approvals\Approvals;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use stdClass;
use Throwable;

/**
 * Outside-service settings (FL1, specs/core/16). HQ's own accounts are kept under tenant 0; a brokerage's under its
 * id. Test and live keys are kept apart, encrypted and never shown again (write-only). Each service is Off, Test or
 * Live:
 * - Off: the feature does nothing with the provider.
 * - Test: the provider's test environment where it has one; nothing reaches real people (only the test contacts
 *   listed) and no real money moves.
 * - Live: unlocked only after a check passed in Test with the same provider.
 * Features ask {@see active()} which account to use: the brokerage's own when the service allows it and it is on,
 * otherwise HQ's.
 */
final class OutsideServices
{
    public const MODES = ['off', 'test', 'live'];

    public function __construct(private readonly ConnectionCheck $checks) {}

    /**
     * Save provider, keys and test contacts. Blank boxes keep the saved value; changing provider clears both
     * sets of keys and locks Live again.
     *
     * @param  array<string, string|null>  $testKeys
     * @param  array<string, string|null>  $liveKeys
     */
    public function save(int $tenantId, string $service, string $provider, array $testKeys, array $liveKeys, ?string $testContacts, User $by): void
    {
        $def = $this->guard($tenantId, $service);
        if (! isset($def['providers'][$provider])) {
            throw ValidationException::withMessages(['provider' => 'Choose one of the listed providers.']);
        }
        $fields = $def['providers'][$provider]['fields'];
        $row = $this->row($tenantId, $service);
        $sameProvider = $row !== null && $row->provider === $provider;
        $test = $this->merge($fields, $sameProvider ? $this->decrypt($row->test_keys) : [], $testKeys);
        $live = $this->merge($fields, $sameProvider ? $this->decrypt($row->live_keys) : [], $liveKeys);
        $contacts = $this->contacts($testContacts);
        $changedTest = $sameProvider && $test !== $this->decrypt($row->test_keys);
        $values = ['provider' => $provider, 'test_keys' => $test !== [] ? Crypt::encryptString((string) json_encode($test)) : null,
            'live_keys' => $live !== [] ? Crypt::encryptString((string) json_encode($live)) : null, 'test_contacts' => $contacts, 'updated_by' => $by->id, 'updated_at' => now()];
        if (! $sameProvider) {
            $values += ['mode' => 'off', 'test_passed_at' => null, 'checked_at' => null, 'check_ok' => null, 'check_mode' => null, 'check_message' => null];
        } elseif ($changedTest) {
            $values['test_passed_at'] = null; // new test keys need a new passing check
        }
        $table = DB::connection(Connections::control())->table('outside_services');
        if ($row === null) {
            $table->insert($values + ['tenant_id' => $tenantId, 'service' => $service, 'mode' => 'off', 'created_at' => now()]);
        } else {
            $table->where('id', $row->id)->update($values);
        }
        app(AuditLogger::class)->record('core.service.saved', metadata: ['service' => $service, 'provider' => $provider, 'level' => $tenantId === 0 ? 'hq' : 'brokerage',
            'test_keys_changed' => array_filter($testKeys) !== [], 'live_keys_changed' => array_filter($liveKeys) !== []], severity: 'warning', tenantId: $tenantId > 0 ? $tenantId : null);
    }

    /**
     * Check connection with the saved keys for a mode.
     *
     * @return array{ok: bool, message: string}
     */
    public function check(int $tenantId, string $service, string $mode, User $by): array
    {
        $this->guard($tenantId, $service);
        if (! in_array($mode, ['test', 'live'], true)) {
            throw ValidationException::withMessages(['mode' => 'Check Test or Live.']);
        }
        $row = $this->row($tenantId, $service) ?? throw ValidationException::withMessages(['service' => 'Choose a provider and save its keys first.']);
        $result = $this->checks->run((string) $row->provider, $mode, $this->decrypt($mode === 'live' ? $row->live_keys : $row->test_keys));
        $update = ['checked_at' => now(), 'check_ok' => $result['ok'], 'check_mode' => $mode, 'check_message' => mb_substr($result['message'], 0, 300), 'updated_at' => now()];
        if ($mode === 'test' && $result['ok']) {
            $update['test_passed_at'] = now();
        }
        DB::connection(Connections::control())->table('outside_services')->where('id', $row->id)->update($update);
        app(AuditLogger::class)->record('core.service.checked', metadata: ['service' => $service, 'provider' => $row->provider, 'mode' => $mode, 'ok' => $result['ok']],
            tenantId: $tenantId > 0 ? $tenantId : null);

        return $result;
    }

    /**
     * Off, Test or Live. Live needs a passing Test check with this provider, and saved live keys.
     * (A brokerage's switch to Live may first need approval; see {@see Approvals}.)
     */
    public function setMode(int $tenantId, string $service, string $mode, User $by): void
    {
        $this->guard($tenantId, $service);
        if (! in_array($mode, self::MODES, true)) {
            throw ValidationException::withMessages(['mode' => 'Choose Off, Test or Live.']);
        }
        $row = $this->row($tenantId, $service) ?? throw ValidationException::withMessages(['mode' => 'Choose a provider and save its keys first.']);
        if ($mode === 'live') {
            $this->guardLive($row);
        }
        DB::connection(Connections::control())->table('outside_services')->where('id', $row->id)->update(['mode' => $mode, 'updated_by' => $by->id, 'updated_at' => now()]);
        app(AuditLogger::class)->record('core.service.mode', metadata: ['service' => $service, 'provider' => $row->provider, 'from' => $row->mode, 'to' => $mode],
            severity: $mode === 'live' ? 'warning' : 'info', tenantId: $tenantId > 0 ? $tenantId : null);
    }

    /** Why Live is not allowed yet, or null when it is. */
    public function liveBlocker(int $tenantId, string $service): ?string
    {
        $row = $this->row($tenantId, $service);
        if ($row === null) {
            return 'Choose a provider and save its keys first.';
        }
        try {
            $this->guardLive($row);
        } catch (ValidationException $e) {
            return (string) collect($e->errors())->flatten()->first();
        }

        return null;
    }

    /**
     * The account a feature should use right now, or null when the service is off (or not set up).
     *
     * @return array{provider: string, mode: string, keys: array<string, string>, test_contacts: list<string>, level: string}|null
     */
    public function active(string $service, ?int $tenantId = null): ?array
    {
        $def = Catalog::get($service);
        if ($def === null) {
            return null;
        }
        if ($tenantId !== null && $tenantId > 0 && in_array($def['level'], ['brokerage', 'both'], true)) {
            $own = $this->row($tenantId, $service);
            if ($own !== null && $own->mode !== 'off') {
                return $this->describe($own, 'brokerage');
            }
            if ($def['level'] === 'brokerage') {
                return null;
            }
        }
        $hq = $this->row(0, $service);

        return $hq !== null && $hq->mode !== 'off' ? $this->describe($hq, 'hq') : null;
    }

    /**
     * In Test mode, whether a phone number or email may be reached (only the listed test contacts).
     */
    public function mayReach(string $service, string $contact, ?int $tenantId = null): bool
    {
        $active = $this->active($service, $tenantId);
        if ($active === null) {
            return false;
        }
        if ($active['mode'] === 'live') {
            return true;
        }
        $norm = fn (string $c): string => str_contains($c, '@') ? mb_strtolower(trim($c)) : preg_replace('/\D+/', '', $c) ?? '';

        return in_array($norm($contact), array_map($norm, $active['test_contacts']), true);
    }

    /**
     * For the settings screens: each service, its providers and fields, what is saved (never the secret values).
     *
     * @param  'hq'|'brokerage'  $level
     * @return list<array<string, mixed>>
     */
    public function summary(int $tenantId, string $level): array
    {
        $rows = DB::connection(Connections::control())->table('outside_services')->where('tenant_id', $tenantId)->get()->keyBy('service');
        $hqRows = $level === 'brokerage' ? DB::connection(Connections::control())->table('outside_services')->where('tenant_id', 0)->get()->keyBy('service') : collect();
        $out = [];
        foreach (Catalog::all() as $key => $def) {
            if ($level === 'brokerage' && $def['level'] === 'hq') {
                continue;
            }
            /** @var stdClass|null $row */
            $row = $rows->get($key);
            $test = $row !== null ? $this->decrypt($row->test_keys) : [];
            $live = $row !== null ? $this->decrypt($row->live_keys) : [];
            $providers = [];
            foreach ($def['providers'] as $pk => $p) {
                $fields = [];
                foreach ($p['fields'] as $fk => $f) {
                    $saved = $row !== null && $row->provider === $pk;
                    $fields[] = ['key' => $fk, 'label' => $f['label'], 'secret' => $f['kind'] === 'secret',
                        'test' => $saved ? $this->shown($f['kind'], $test[$fk] ?? null) : null, 'live' => $saved ? $this->shown($f['kind'], $live[$fk] ?? null) : null];
                }
                $providers[] = ['key' => $pk, 'label' => $p['label'], 'note' => $p['note'] ?? null, 'fields' => $fields, 'guide' => ProviderGuides::for($key, $pk)]; // HQ4
            }
            $hq = $hqRows->get($key);
            $out[] = ['key' => $key, 'label' => $def['label'], 'about' => $def['about'], 'level' => $def['level'], 'sprint' => $def['sprint'], 'contacts' => $def['contacts'],
                'providers' => $providers, 'provider' => $row->provider ?? Catalog::recommended($key), 'saved' => $row !== null, 'mode' => $row->mode ?? 'off',
                'test_contacts' => $row->test_contacts ?? '', 'checked_at' => $row?->checked_at, 'check_ok' => $row !== null && $row->check_ok !== null ? (bool) $row->check_ok : null,
                'check_mode' => $row?->check_mode, 'check_message' => $row?->check_message, 'live_blocker' => $this->liveBlocker($tenantId, $key),
                'hq_mode' => $level === 'brokerage' ? ($hq->mode ?? 'off') : null];
        }

        return $out;
    }

    /**
     * @return array{label: string, about: string, level: string, sprint: string, contacts: bool, providers: array<string, array{label: string, fields: array<string, array{label: string, kind: string, hint?: string, optional?: bool}>, note?: string}>}
     */
    private function guard(int $tenantId, string $service): array
    {
        $def = Catalog::get($service) ?? throw ValidationException::withMessages(['service' => 'That service does not exist.']);
        if ($tenantId > 0 && $def['level'] === 'hq') {
            throw ValidationException::withMessages(['service' => 'RightAlly runs this service for every brokerage.']);
        }

        return $def;
    }

    private function guardLive(stdClass $row): void
    {
        if ($row->test_passed_at === null) {
            throw ValidationException::withMessages(['mode' => 'Run "Check connection" in Test first. Live unlocks after a test passes.']);
        }
        $fields = Catalog::get((string) $row->service)['providers'][(string) $row->provider]['fields'] ?? [];
        $live = $this->decrypt($row->live_keys);
        foreach (array_keys($fields) as $f) {
            if (trim((string) ($live[$f] ?? '')) === '') {
                throw ValidationException::withMessages(['mode' => 'Save the Live keys first.']);
            }
        }
    }

    /** @return array{provider: string, mode: string, keys: array<string, string>, test_contacts: list<string>, level: string} */
    private function describe(stdClass $row, string $level): array
    {
        $contacts = array_values(array_filter(array_map('trim', explode(',', (string) $row->test_contacts))));

        return ['provider' => (string) $row->provider, 'mode' => (string) $row->mode, 'keys' => $this->decrypt($row->mode === 'live' ? $row->live_keys : $row->test_keys),
            'test_contacts' => $contacts, 'level' => $level];
    }

    private function row(int $tenantId, string $service): ?stdClass
    {
        /** @var stdClass|null */
        return DB::connection(Connections::control())->table('outside_services')->where('tenant_id', $tenantId)->where('service', $service)->first();
    }

    /**
     * @param  array<string, array{label: string, kind: string, hint?: string}>  $fields
     * @param  array<string, string>  $saved
     * @param  array<string, string|null>  $given
     * @return array<string, string>
     */
    private function merge(array $fields, array $saved, array $given): array
    {
        $out = [];
        foreach ($fields as $key => $f) {
            $value = trim((string) ($given[$key] ?? ''));
            if ($value === '') {
                $value = $saved[$key] ?? ''; // a blank box keeps what is saved
            }
            if ($value !== '' && mb_strlen($value) > 8000) {
                throw ValidationException::withMessages([$key => 'That value is too long.']);
            }
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function contacts(?string $list): ?string
    {
        $items = array_values(array_filter(array_map('trim', explode(',', (string) $list))));
        foreach ($items as $c) {
            $ok = str_contains($c, '@') ? filter_var($c, FILTER_VALIDATE_EMAIL) !== false : preg_match('/^\+?[0-9 ().-]{10,20}$/', $c) === 1;
            if (! $ok) {
                throw ValidationException::withMessages(['test_contacts' => '"'.$c.'" is not a phone number or email address.']);
            }
        }
        if (count($items) > 20) {
            throw ValidationException::withMessages(['test_contacts' => 'List up to 20 test contacts.']);
        }

        return $items !== [] ? implode(', ', $items) : null;
    }

    /** @return array<string, string> */
    private function decrypt(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        try {
            $data = json_decode(Crypt::decryptString($value), true);
        } catch (Throwable) {
            return [];
        }

        return is_array($data) ? array_map(fn (mixed $v): string => (string) $v, $data) : [];
    }

    /** Text fields are shown; secrets only as "saved ••••1234". */
    private function shown(string $kind, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $kind === 'secret' ? 'Saved ••••'.mb_substr($value, -4) : $value;
    }
}
