<?php

declare(strict_types=1);

namespace Modules\Roster;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Agents\AgentCredentials;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/** Agents' current licence and MLS ids from the roster, for their websites (specs/websites/09). */
final class RosterAgentCredentials implements AgentCredentials
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function licence(int $userId): ?array
    {
        $row = $this->current($userId, 'licence')->whereNotNull('number')->orderByDesc('id')->first(['state_code', 'number']);

        return $row !== null ? ['state' => $row->state_code !== null ? (string) $row->state_code : null, 'number' => (string) $row->number] : null;
    }

    public function mlsIds(int $userId): array
    {
        return array_values($this->current($userId, 'mls')->whereNotNull('number')->orderBy('id')->pluck('number')->map(fn ($n): string => (string) $n)->unique()->all());
    }

    private function current(int $userId, string $kind): Builder
    {
        return DB::connection(Connections::cell())->table('roster_credentials')->where('tenant_id', $this->tenants->id())->where('user_id', $userId)
            ->where('kind', $kind)->where('status', 'current')
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', now()->toDateString()));
    }
}
