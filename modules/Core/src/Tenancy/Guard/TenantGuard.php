<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Guard;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Modules\Core\Safeguards\ControlTables;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * Sprint 58 (pen-test preparation): defence in depth for brokerage isolation.
 *
 * When a brokerage is the current tenant, every select, update and delete on its cell connection, on a table that
 * has a tenant_id column, also gets "and <table>.tenant_id = <this brokerage>". Not limited:
 * - no tenant (console commands, queued jobs that work across brokerages), and HQ (platform staff);
 * - control-plane tables (ControlTables::ALL), which are shared and keyed by tenant on purpose, and the audit log;
 * - other connections (control, listings, audit when split), and tables without a tenant_id column;
 * - CrossTenant::query, the one sanctioned cross-brokerage path (audited).
 */
final class TenantGuard
{
    /**
     * Platform tables made on the control connection through the migration's connection (not listed in
     * ControlTables, which covers the ones made by name). tests/Architecture/TenantGuardTablesTest keeps both
     * lists complete.
     */
    public const PLATFORM = ['affiliate_clicks', 'affiliate_referrals', 'affiliates', 'ai_eval_results', 'ai_eval_runs', 'ai_feature_routes', 'ai_models', 'ai_providers',
        'ai_red_team_runs', 'cells', 'tenant_domains', 'tenant_host_redirects', 'tenants'];

    /** @var array<string, bool> "connection|table" => has a tenant_id column */
    private static array $columns = [];

    /** While above zero, new queries are not limited (CrossTenant::query only). */
    private static int $off = 0;

    /** Adds the brokerage condition when it applies. Returns whether it did. */
    public static function apply(Builder $query): bool
    {
        $from = $query->from;
        if (self::$off > 0 || ! is_string($from) || $from === '') { // a raw expression or sub-select: left as written
            return false;
        }
        $context = app(TenantContext::class);
        if (! $context->check() || $context->current()->isHq()) {
            return false;
        }
        $db = $query->getConnection();
        if (! $db instanceof Connection || $db->getName() !== (Connections::cell() ?? config('database.default'))) {
            return false;
        }
        $connection = (string) $db->getName();
        [$table, $alias] = self::split($from);
        // The audit log keeps each brokerage's chain and HQ's side by side (an HQ action is written to both) and has
        // its own insert-and-select-only connection in production; it sets tenant ids itself.
        if (in_array($table, ControlTables::ALL, true) || in_array($table, self::PLATFORM, true) || str_starts_with($table, 'audit_') || ! self::hasTenantColumn($db, $connection, $table)) {
            return false;
        }
        $query->where($alias.'.tenant_id', $context->id());

        return true;
    }

    /**
     * Builds a query without the brokerage condition: the sanctioned cross-brokerage path only (CrossTenant::query).
     *
     * @template T
     *
     * @param  callable(): T  $build
     * @return T
     */
    public static function without(callable $build): mixed
    {
        self::$off++;
        try {
            return $build();
        } finally {
            self::$off--;
        }
    }

    /** Forgets the column cache (tests that change the schema). */
    public static function flush(): void
    {
        self::$columns = [];
    }

    /** @return array{0: string, 1: string} the table and the name to qualify its columns with */
    private static function split(string $from): array
    {
        if (preg_match('/^\s*([\w.]+)\s+(?:as\s+)?(\w+)\s*$/i', $from, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return [trim($from), trim($from)];
    }

    private static function hasTenantColumn(Connection $db, string $connection, string $table): bool
    {
        $key = $connection.'|'.$table;
        if (! array_key_exists($key, self::$columns)) {
            try {
                self::$columns[$key] = $db->getSchemaBuilder()->hasColumn($table, 'tenant_id');
            } catch (Throwable) {
                return false; // not cached: asked again once the table exists
            }
        }

        return self::$columns[$key];
    }
}
