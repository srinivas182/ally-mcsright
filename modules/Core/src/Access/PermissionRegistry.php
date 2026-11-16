<?php

declare(strict_types=1);

namespace Modules\Core\Access;

/**
 * Every permission, registered by its module with a plain-English label (specs/core/03). New modules'
 * permissions appear in every role editor automatically, with no access until granted.
 */
final class PermissionRegistry
{
    public const SCOPES = ['own', 'team', 'office', 'tenant'];

    /** @var array<string, array{key: string, area: string, label: string, scoped: bool, sensitive: bool}> */
    private array $permissions = [];

    /** @var list<array{0: string, 1: string, 2: string}> pairs that one person may not hold together, with why */
    private array $conflicts = [];

    /**
     * @param  array<string, array{0: string, 1?: bool, 2?: bool}>  $permissions  key => [label, scoped, sensitive]
     */
    public function register(string $area, array $permissions): void
    {
        foreach ($permissions as $key => $def) {
            $this->permissions[$key] = ['key' => $key, 'area' => $area, 'label' => $def[0], 'scoped' => $def[1] ?? false, 'sensitive' => $def[2] ?? false];
        }
    }

    /** Access that owners hear about the moment it is given (R1), on top of every sensitive permission. */
    public const RISKY = ['core.roles.manage', 'core.users.manage', 'core.access.review', 'finance.payouts.approve', 'finance.ledger.export',
        'finance.ledger.adjust', 'transactions.export', 'transactions.cda.approve', 'apps.manage', 'billing.credits.manage'];

    /**
     * The risky permissions in a set, as plain-English labels.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function riskyIn(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $def = $this->permissions[$key] ?? null;
            if ($def !== null && ($def['sensitive'] || in_array($key, self::RISKY, true))) {
                $out[] = $def['label'];
            }
        }

        return $out;
    }

    public function conflict(string $a, string $b, string $why): void
    {
        $this->conflicts[] = [$a, $b, $why];
    }

    public function has(string $key): bool
    {
        return isset($this->permissions[$key]);
    }

    /**
     * @return array{key: string, area: string, label: string, scoped: bool, sensitive: bool}|null
     */
    public function get(string $key): ?array
    {
        return $this->permissions[$key] ?? null;
    }

    /**
     * @return array<string, array{key: string, area: string, label: string, scoped: bool, sensitive: bool}>
     */
    public function all(): array
    {
        return $this->permissions;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * Conflicting pairs present in a set of permission keys.
     *
     * @param  list<string>  $keys
     * @return list<string> plain-English reasons
     */
    public function conflictsIn(array $keys): array
    {
        $found = [];
        foreach ($this->conflicts as [$a, $b, $why]) {
            if (in_array($a, $keys, true) && in_array($b, $keys, true)) {
                $found[] = $why;
            }
        }

        return $found;
    }
}
