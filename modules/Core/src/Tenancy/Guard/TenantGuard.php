<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Guard;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Modules\Core\Safeguards\ControlTables;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Exceptions\UnguardedTenantQuery;
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

    /** Tables named after from, join, update, into (SX2 raw SQL check). */
    private const RAW_TABLES = '/\b(?:from|join|update|into)\s+[`"\[]?(\w+)[`"\]]?/i';

    /** True while checking raw SQL (the column lookup runs SQL of its own). */
    private static bool $checking = false;

    /** Adds the brokerage condition when it applies. Returns whether it did. */
    public static function apply(Builder $query): bool
    {
        $from = $query->from;
        if (! is_string($from) || $from === '') { // a raw expression or sub-select: left as written
            return false;
        }
        [$table, $alias] = self::split($from);
        $id = self::tenantFor($query->getConnection(), $table);
        if ($id === null) {
            return false;
        }
        $query->where($alias.'.tenant_id', $id);

        return true;
    }

    /**
     * SX2: a joined brokerage table gets the condition in its "on" clause too, so a join from a table without a
     * tenant_id (or a left join) never pulls in another brokerage's rows.
     */
    public static function applyToJoin(Builder $query): void
    {
        $join = $query->joins === null ? null : end($query->joins);
        if (! $join instanceof JoinClause || ! is_string($join->table)) {
            return;
        }
        [$table, $alias] = self::split($join->table);
        $id = self::tenantFor($query->getConnection(), $table);
        if ($id !== null) {
            $join->wheres[] = ['type' => 'Basic', 'column' => $alias.'.tenant_id', 'operator' => '=', 'value' => $id, 'boolean' => 'and'];
            $query->addBinding($id, 'join');
        }
    }

    /**
     * SX2: raw SQL (DB::select, statement, unprepared, cursor on a hand-written string) on a brokerage table must say
     * which brokerage it is for. Query-builder SQL is guarded above (or deliberately not, by CrossTenant::query) and
     * schema work is not about rows, so only SQL that reaches the connection without either is checked: if it names
     * a brokerage table and never mentions tenant_id, it is refused before it runs.
     *
     * @throws UnguardedTenantQuery
     */
    public static function checkRaw(Connection $db, string $sql): void
    {
        if (self::$checking || stripos($sql, 'tenant_id') !== false || preg_match_all(self::RAW_TABLES, $sql, $m) < 1) {
            return;
        }
        self::$checking = true;
        try {
            foreach (array_unique(array_map('strtolower', $m[1])) as $table) {
                if (self::tenantFor($db, $table) !== null && ! self::fromBuilder()) {
                    throw new UnguardedTenantQuery($table);
                }
            }
        } finally {
            self::$checking = false;
        }
    }

    /** Whether the SQL being run was made by a query or schema builder (only checked when about to refuse). */
    private static function fromBuilder(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $frame) {
            $class = $frame['class'] ?? '';
            if ($class !== '' && (is_a($class, Builder::class, true) || is_a($class, SchemaBuilder::class, true) || is_a($class, EloquentBuilder::class, true))) {
                return true;
            }
        }

        return false;
    }

    /** The current brokerage's id when its condition applies to this table on this connection; null when not. */
    private static function tenantFor(mixed $db, string $table): ?int
    {
        if (self::$off > 0) {
            return null;
        }
        $context = app(TenantContext::class);
        if (! $context->check() || $context->current()->isHq()) {
            return null;
        }
        if (! $db instanceof Connection || $db->getName() !== (Connections::cell() ?? config('database.default'))) {
            return null;
        }
        // The audit log keeps each brokerage's chain and HQ's side by side (an HQ action is written to both) and has
        // its own insert-and-select-only connection in production; it sets tenant ids itself.
        if (in_array($table, ControlTables::ALL, true) || in_array($table, self::PLATFORM, true) || str_starts_with($table, 'audit_') || ! self::hasTenantColumn($db, (string) $db->getName(), $table)) {
            return null;
        }

        return (int) $context->id();
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
