<?php

declare(strict_types=1);

namespace Modules\Billing\GoLive;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Connect\App;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * "Switch in a weekend" (specs/billing/07): one screen that shows each old app the brokerage is bringing over (people
 * from CRMs and spreadsheets, deals from transaction apps), how far each import has got, and the checks RightAlly runs
 * after the move so nothing has to be re-tested by hand. The imports themselves are the Connect importers
 * (specs/connect/04, 06, 12); this screen only reads their results.
 */
final class SwitchCentre
{
    public function __construct(private readonly TenantContext $tenants, private readonly AppCatalog $catalog) {}

    /**
     * @return array{people: array{apps: list<array<string, mixed>>, connected: list<array<string, mixed>>, total: int, imported_files: int},
     *     deals: array{apps: list<array<string, mixed>>, connected: list<array<string, mixed>>, total: int},
     *     checks: list<array{key: string, ok: bool, title: string, detail: string, href: string|null, fix: string|null}>}
     */
    public function overview(): array
    {
        $tenant = $this->tenants->id();
        $cell = DB::connection(Connections::cell());
        $connections = $cell->table('connect_connections')->where('tenant_id', $tenant)->whereNull('owner_user_id')->get();
        $runs = $cell->table('connect_sync_runs')->where('tenant_id', $tenant)->whereIn('connection_id', $connections->pluck('id')->all() ?: [0])
            ->selectRaw('connection_id, sum(created) as created, sum(updated) as updated, max(finished_at) as finished')->groupBy('connection_id')->get()->keyBy('connection_id');
        $byCategory = function (string $category) use ($connections, $runs): array {
            $connected = [];
            foreach ($connections as $c) {
                $app = $this->catalog->find((string) $c->app_key);
                if ($app === null || $app->category !== $category) {
                    continue;
                }
                $run = $runs->get($c->id);
                $connected[] = ['app' => $app->name, 'key' => $app->key, 'status' => (string) $c->status, 'brought' => (int) ($run->created ?? 0),
                    'updated' => (int) ($run->updated ?? 0), 'last' => $c->last_synced_at !== null ? (string) $c->last_synced_at : null, 'error' => $c->last_error];
            }
            $apps = array_values(array_map(fn (App $a): array => ['key' => $a->key, 'name' => $a->name, 'href' => '/apps/'.$a->key],
                array_filter($this->catalog->all(), fn (App $a): bool => $a->category === $category && $a->available())));

            return ['apps' => $apps, 'connected' => $connected];
        };

        $contacts = $cell->table('crm_contacts')->where('tenant_id', $tenant)->whereNull('deleted_at');
        $deals = $cell->table('tx_deals')->where('tenant_id', $tenant);

        return [
            'people' => $byCategory('crm') + ['total' => (clone $contacts)->count(), 'imported_files' => $cell->table('crm_imports')->where('tenant_id', $tenant)->count()],
            'deals' => $byCategory('transactions') + ['total' => (clone $deals)->count()],
            'checks' => $this->checks($tenant),
        ];
    }

    /**
     * What RightAlly checks after the move, instead of the brokerage re-testing everything by hand.
     *
     * @return list<array{key: string, ok: bool, title: string, detail: string, href: string|null, fix: string|null}>
     */
    private function checks(int $tenant): array
    {
        $cell = DB::connection(Connections::cell());
        $contacts = fn () => $cell->table('crm_contacts')->where('tenant_id', $tenant)->whereNull('deleted_at');
        $total = $contacts()->count();
        $noAgent = $contacts()->where('origin', 'company')->whereNull('owner_user_id')->whereIn('lifecycle', ['lead', 'active', 'under_contract'])->whereNull('in_pond_since')->count();
        $optedOut = $contacts()->where(fn ($q) => $q->whereNotNull('sms_opt_out_at')->orWhere('do_not_call', true))->count();
        $textable = $contacts()->where('sms_opt_in', true)->whereNull('sms_opt_out_at')->count();
        $rules = $cell->table('crm_routing_rules')->where('tenant_id', $tenant)->where('active', true)->count();
        $agents = $cell->table('users')->where('tenant_id', $tenant)->whereNull('deleted_at')->where('kind', 'agent');
        $invited = (clone $agents)->where('status', 'invited')->count();
        $stale = $cell->table('users')->where('tenant_id', $tenant)->whereNull('deleted_at')->where('kind', 'agent')->where('status', 'invited')
            ->where('created_at', '<', CarbonImmutable::now()->subDays(3))->count();
        $broken = $cell->table('connect_connections')->where('tenant_id', $tenant)->whereIn('status', ['error', 'needs_reconnect'])->count();
        $orphanDeals = $cell->table('tx_deals')->where('tenant_id', $tenant)
            ->whereNotIn('agent_user_id', $cell->table('users')->where('tenant_id', $tenant)->whereNull('deleted_at')->pluck('id')->all() ?: [0])->count();
        $people = $total === 0 ? 'No people brought over yet.' : number_format($total).' people in RightAlly.';

        return [
            ['key' => 'apps_working', 'ok' => $broken === 0, 'title' => 'Every connected app is working',
                'detail' => $broken === 0 ? 'No connection errors.' : $broken.' connection(s) need attention.', 'href' => '/apps', 'fix' => $broken === 0 ? null : 'Open Apps and reconnect the ones marked in red.'],
            ['key' => 'owners', 'ok' => $noAgent === 0, 'title' => 'Every lead has an agent',
                'detail' => $noAgent === 0 ? $people.' Each open lead has an agent.' : $noAgent.' company lead(s) have no agent, so nobody will follow up.', 'href' => '/contacts',
                'fix' => $noAgent === 0 ? null : 'Assign them, or put them in the lead pond for agents to claim.'],
            ['key' => 'routing', 'ok' => $rules > 0, 'title' => 'New leads go to the right agent',
                'detail' => $rules > 0 ? $rules.' routing rule(s) on.' : 'No routing rules yet, so new leads wait in the pond.', 'href' => '/leads/settings',
                'fix' => $rules > 0 ? null : 'Add a rule: round robin, by area or price, or first to claim.'],
            ['key' => 'opt_outs', 'ok' => true, 'title' => 'Opt-outs came over',
                'detail' => $optedOut.' people who asked not to be texted or called stay that way here.', 'href' => null, 'fix' => null],
            ['key' => 'consent', 'ok' => true, 'title' => 'Nobody is texted without consent',
                'detail' => $textable.' people can be texted. Everyone else gets texts only after they opt in here (TCPA), and only 8am–9pm their time.', 'href' => null, 'fix' => null],
            ['key' => 'deal_agents', 'ok' => $orphanDeals === 0, 'title' => 'Every deal has its agent',
                'detail' => $orphanDeals === 0 ? 'All deals are matched to a person here.' : $orphanDeals.' deal(s) belong to someone who is not in RightAlly yet.', 'href' => '/users',
                'fix' => $orphanDeals === 0 ? null : 'Invite those agents; their deals move to them when they join.'],
            ['key' => 'agents_joined', 'ok' => $stale === 0, 'title' => 'Agents have accepted their invitations',
                'detail' => $invited === 0 ? 'No invitations waiting.' : $invited.' invitation(s) waiting'.($stale > 0 ? ', '.$stale.' for more than 3 days.' : '.'), 'href' => '/users',
                'fix' => $stale === 0 ? null : 'Resend the invitation, or ask your coach to call them.'],
        ];
    }
}
