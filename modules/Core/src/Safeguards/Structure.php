<?php

declare(strict_types=1);

namespace Modules\Core\Safeguards;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Copies table structure from a shared cell to a dedicated one, and keeps it in step after each release (DS2).
 * Migrations write to both the control plane and the cells, so a dedicated database gets its structure from the
 * main cell instead: missing tables are created as they are there, missing columns are added. Both databases use
 * the same engine.
 */
final class Structure
{
    /**
     * @param  list<string>  $tables
     * @return array{tables: int, columns: int}
     */
    public static function sync(Connection $from, Connection $to, array $tables): array
    {
        if ($from->getDriverName() !== $to->getDriverName() && ! ($from->getDriverName() === 'mysql' && $to->getDriverName() === 'mariadb')) {
            throw new RuntimeException('Both databases must use the same engine.');
        }
        $made = 0;
        $added = 0;
        $have = self::tableNames($to);
        foreach ($tables as $table) {
            if (! in_array($table, $have, true)) {
                foreach (self::createStatements($from, $table) as $sql) {
                    $to->statement($sql);
                }
                $made++;

                continue;
            }
            $missing = array_diff($from->getSchemaBuilder()->getColumnListing($table), $to->getSchemaBuilder()->getColumnListing($table));
            foreach ($missing as $column) {
                $to->statement(self::addColumn($from, $table, $column));
                $added++;
            }
        }

        return ['tables' => $made, 'columns' => $added];
    }

    /**
     * Table names in the connection's own database (MySQL can see other databases on the same server).
     *
     * @return list<string>
     */
    public static function tableNames(Connection $conn): array
    {
        $schema = $conn->getDriverName() === 'sqlite' ? null : $conn->getDatabaseName();

        return array_map(fn (array $t): string => (string) $t['name'], $conn->getSchemaBuilder()->getTables($schema));
    }

    /**
     * New ids in a dedicated database start far above the shared cells' (cell id × 10^12), so a brokerage that moves
     * back to a shared cell never brings an id another brokerage already has.
     *
     * @param  list<string>  $tables
     */
    public static function offsetIds(Connection $to, array $tables, int $start): void
    {
        foreach ($tables as $table) {
            if (! $to->getSchemaBuilder()->hasColumn($table, 'id')) {
                continue;
            }
            $next = max($start, (int) $to->table($table)->max('id') + 1);
            if ($to->getDriverName() === 'sqlite') {
                $has = $to->selectOne("select count(*) as n from sqlite_master where name = 'sqlite_sequence'");
                if ((int) ($has->n ?? 0) > 0) {
                    $to->table('sqlite_sequence')->updateOrInsert(['name' => $table], ['seq' => $next - 1]);
                }
            } else {
                $to->statement('alter table `'.str_replace('`', '', $table).'` auto_increment = '.$next);
            }
        }
    }

    /** @return list<string> */
    private static function createStatements(Connection $from, string $table): array
    {
        if ($from->getDriverName() === 'sqlite') {
            return array_values(array_filter(array_map(fn ($r): string => (string) $r->sql,
                $from->select("select sql from sqlite_master where tbl_name = ? and sql is not null order by type = 'index'", [$table]))));
        }
        $row = (array) $from->selectOne('show create table `'.str_replace('`', '', $table).'`');

        return [(string) ($row['Create Table'] ?? throw new RuntimeException("No structure for {$table}."))];
    }

    private static function addColumn(Connection $from, string $table, string $column): string
    {
        if ($from->getDriverName() === 'sqlite') {
            foreach ($from->select('pragma table_info("'.str_replace('"', '', $table).'")') as $c) {
                if ($c->name === $column) {
                    return 'alter table "'.$table.'" add column "'.$column.'" '.($c->type ?: 'text').($c->dflt_value !== null ? ' default '.$c->dflt_value : '');
                }
            }
        } else {
            $c = (array) $from->selectOne('show full columns from `'.str_replace('`', '', $table).'` where Field = ?', [$column]);
            if ($c !== []) {
                return 'alter table `'.$table.'` add column `'.$column.'` '.$c['Type'].($c['Null'] === 'YES' ? ' null' : ' not null')
                    .($c['Default'] !== null ? ' default '.$from->getPdo()->quote((string) $c['Default']) : '');
            }
        }

        throw new RuntimeException("No definition for {$table}.{$column}.");
    }
}
