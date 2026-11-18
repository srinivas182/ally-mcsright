<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Guard;

use Illuminate\Database\Query\Builder;

/**
 * A query builder that limits a brokerage's queries on its cell to that brokerage's own rows (TenantGuard). Eloquent
 * models already do this with TenantScope; this also covers DB::table() queries, so a forgotten "where tenant_id"
 * can never show or change another brokerage's data.
 *
 * - The condition is added the moment the table is chosen (from()), so it travels with the query, also when the
 *   query is used inside another one (whereIn, whereExists, joinSub), with its value in the right place.
 * - A nested "where (… or …)" group never carries its own copy (the outer query has it), so "or" keeps its meaning.
 * - Before running, a top-level "or" is put in brackets after the condition: "brokerage and (a or b)", never
 *   "brokerage and a or b".
 */
class TenantGuardedBuilder extends Builder
{
    /** Where the condition sits in $wheres and in the "where" bindings, once added. */
    private ?int $guardWhere = null;

    private ?int $guardBinding = null;

    private bool $tenantGuardOff = false;

    public function from($table, $as = null)
    {
        parent::from($table, $as);
        if (! $this->tenantGuardOff && $this->guardWhere === null) {
            $where = count($this->wheres);
            $binding = count($this->bindings['where']);
            if (TenantGuard::apply($this)) {
                [$this->guardWhere, $this->guardBinding] = [$where, $binding];
            }
        }

        return $this;
    }

    /** A nested "where (… or …)" group built from scratch: the outer query already carries the condition. */
    public function forNestedWhere()
    {
        return $this->unguarded()->from($this->from);
    }

    /** A nested group built as a whole query (Eloquent closures): drop its own copy of the condition first. */
    public function addNestedWhereQuery($query, $boolean = 'and')
    {
        if ($query instanceof self) {
            $query->dropTenantGuard();
        }

        return parent::addNestedWhereQuery($query, $boolean);
    }

    public function applyBeforeQueryCallbacks()
    {
        $this->bracketOrsAfterGuard();
        parent::applyBeforeQueryCallbacks();
    }

    /** A new query on the same connection that never adds the condition itself (a group inside this one). */
    private function unguarded(): self
    {
        $query = new self($this->connection, $this->grammar, $this->processor);
        $query->tenantGuardOff = true;

        return $query;
    }

    private function dropTenantGuard(): void
    {
        if ($this->guardWhere === null || $this->guardBinding === null) {
            return;
        }
        array_splice($this->wheres, $this->guardWhere, 1);
        array_splice($this->bindings['where'], $this->guardBinding, 1);
        $this->guardWhere = $this->guardBinding = null;
    }

    /**
     * "guard and a or b" → "guard and (a or b)". Only when the condition is first, so the "where" bindings stay in
     * the same order (the group's values follow the condition's, as before).
     */
    private function bracketOrsAfterGuard(): void
    {
        if ($this->guardWhere !== 0 || count($this->wheres) < 3) {
            return;
        }
        $rest = array_slice($this->wheres, 1);
        if (! collect($rest)->contains(fn (array $w): bool => ($w['boolean'] ?? 'and') === 'or')) {
            return;
        }
        $group = $this->unguarded();
        $rest[0]['boolean'] = 'and';
        $group->wheres = $rest;
        $this->wheres = [$this->wheres[0], ['type' => 'Nested', 'query' => $group, 'boolean' => 'and']];
    }
}
