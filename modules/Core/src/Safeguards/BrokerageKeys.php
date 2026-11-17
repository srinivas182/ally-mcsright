<?php

declare(strict_types=1);

namespace Modules\Core\Safeguards;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use RuntimeException;

/**
 * Per-brokerage encryption keys for the most sensitive fields (DS2, A16, specs/core/12): tax ids, bank routing and
 * account numbers. Each brokerage has its own 256-bit data key, stored wrapped by the platform key ("envelope
 * encryption"); a value sealed with it reads "bk1:{version}:{ciphertext}". Values sealed before keys existed still
 * open with the platform key, and "safeguards:rekey" re-seals them. Rotating makes a new version; old versions stay
 * readable until everything is re-sealed. Modules register the columns that hold sealed values.
 */
final class BrokerageKeys
{
    private const PREFIX = 'bk1:';

    /** @var list<array{table: string, column: string}> */
    private array $columns = [];

    /** @var array<string, Encrypter> tenant:version => encrypter */
    private array $cache = [];

    public function __construct(private readonly TenantContext $tenants) {}

    public function register(string $table, string $column): void
    {
        $this->columns[] = ['table' => $table, 'column' => $column];
    }

    /** @return list<array{table: string, column: string}> */
    public function columns(): array
    {
        return $this->columns;
    }

    public function seal(string $plain): string
    {
        [$version, $enc] = $this->current();

        return self::PREFIX.$version.':'.$enc->encryptString($plain);
    }

    public function open(string $sealed): string
    {
        if (! str_starts_with($sealed, self::PREFIX)) {
            return Crypt::decryptString($sealed); // sealed before per-brokerage keys
        }
        [$version, $payload] = explode(':', substr($sealed, strlen(self::PREFIX)), 2) + [1 => ''];

        return $this->encrypter((int) $version)->decryptString($payload);
    }

    public static function isCurrent(string $sealed, int $version): bool
    {
        return str_starts_with($sealed, self::PREFIX.$version.':');
    }

    /** Makes a new key version for the current brokerage; older versions are retired but still open old values. */
    public function rotate(): int
    {
        $tenant = $this->tenants->id();
        $db = DB::connection(Connections::control())->table('brokerage_keys');
        $next = (int) $db->where('tenant_id', $tenant)->max('version') + 1;
        DB::connection(Connections::control())->table('brokerage_keys')->where('tenant_id', $tenant)->update(['status' => 'retired', 'updated_at' => now()]);
        DB::connection(Connections::control())->table('brokerage_keys')->insert(['tenant_id' => $tenant, 'version' => $next,
            'wrapped' => Crypt::encryptString(base64_encode(random_bytes(32))), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('safeguards.key.rotated', metadata: ['tenant' => $tenant, 'version' => $next], severity: 'notice');

        return $next;
    }

    /**
     * Re-seals every registered column's values for the current brokerage with its current key.
     *
     * @return int values re-sealed
     */
    public function rekey(): int
    {
        [$version] = $this->current();
        $tenant = $this->tenants->id();
        $n = 0;
        foreach ($this->columns as $c) {
            $db = DB::connection(Connections::cell());
            $db->table($c['table'])->where('tenant_id', $tenant)->whereNotNull($c['column'])->orderBy('id')
                ->chunkById(500, function ($rows) use ($db, $c, $version, &$n): void {
                    foreach ($rows as $r) {
                        $value = (string) $r->{$c['column']};
                        if ($value !== '' && ! self::isCurrent($value, $version)) {
                            $db->table($c['table'])->where('id', $r->id)->update([$c['column'] => $this->seal($this->open($value))]);
                            $n++;
                        }
                    }
                });
        }
        app(AuditLogger::class)->record('safeguards.key.rekeyed', metadata: ['tenant' => $tenant, 'version' => $version, 'values' => $n], severity: 'notice');

        return $n;
    }

    /** @return array{version: int|null, versions: int, sealed: int, legacy: int} */
    public function status(): array
    {
        $tenant = $this->tenants->id();
        $version = DB::connection(Connections::control())->table('brokerage_keys')->where('tenant_id', $tenant)->where('status', 'active')->value('version');
        $sealed = 0;
        $legacy = 0;
        foreach ($this->columns as $c) {
            foreach (DB::connection(Connections::cell())->table($c['table'])->where('tenant_id', $tenant)->whereNotNull($c['column'])->pluck($c['column']) as $v) {
                $version !== null && self::isCurrent((string) $v, (int) $version) ? $sealed++ : $legacy++;
            }
        }

        return ['version' => $version !== null ? (int) $version : null, 'versions' => DB::connection(Connections::control())->table('brokerage_keys')
            ->where('tenant_id', $tenant)->count(), 'sealed' => $sealed, 'legacy' => $legacy];
    }

    /** @return array{0: int, 1: Encrypter} */
    private function current(): array
    {
        $tenant = $this->tenants->id();
        $version = DB::connection(Connections::control())->table('brokerage_keys')->where('tenant_id', $tenant)->where('status', 'active')->value('version');
        $version = $version !== null ? (int) $version : $this->rotate();

        return [$version, $this->encrypter($version)];
    }

    private function encrypter(int $version): Encrypter
    {
        $tenant = $this->tenants->id();
        $key = $tenant.':'.$version;
        if (! isset($this->cache[$key])) {
            $wrapped = DB::connection(Connections::control())->table('brokerage_keys')->where('tenant_id', $tenant)->where('version', $version)->value('wrapped');
            if (! is_string($wrapped)) {
                throw new RuntimeException('This brokerage\'s key is missing.');
            }
            $this->cache[$key] = new Encrypter((string) base64_decode(Crypt::decryptString($wrapped), true), 'aes-256-gcm');
        }

        return $this->cache[$key];
    }
}
