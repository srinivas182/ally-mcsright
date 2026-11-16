<?php

declare(strict_types=1);

namespace Modules\Billing\GoLive;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * A new brokerage's 30-day go-live plan (specs/billing/07). Each step has a target day and ticks itself from what the
 * brokerage has actually done in RightAlly, so nobody has to report progress. The brokerage sees its plan and its
 * named coach; HQ sees every plan, how far along it is and which ones are stuck.
 */
final class GoLive
{
    public const DAYS = 30;

    /** key => [target day, title, why, where to do it] */
    public const STEPS = [
        'signed_in' => [1, 'Sign in and look around', 'Your RightAlly is ready; the owner signs in first.', '/dashboard'],
        'people' => [2, 'Bring your people', 'Contacts, notes and tags come over from your old CRM or a spreadsheet.', '/switch#people'],
        'deals' => [3, 'Bring your deals', 'Open and closed deals, with their documents, from your old transaction app.', '/switch#deals'],
        'routing' => [3, 'Choose who gets new leads', 'So every new lead reaches an agent within minutes.', '/leads/settings'],
        'agents_invited' => [5, 'Invite your agents', 'Each agent gets an email with their own sign-in link.', '/users'],
        'agents_signed_in' => [7, 'Half your agents have signed in', 'Simple view keeps it easy for agents who don\'t like new software.', '/users'],
        'website' => [10, 'Publish your website', 'Your IDX website, ready for Google and AI answers.', '/settings/website'],
        'first_deal' => [14, 'First deal started in RightAlly', 'A new deal started here, not brought over.', '/deals'],
        'weekly_use' => [21, 'Most agents use it every week', 'At least 7 in 10 agents active in the last 7 days.', '/users'],
        'first_close' => [30, 'First deal closed in RightAlly', 'The goal of the first month: a deal from contract to close here.', '/deals'],
    ];

    public function __construct(private readonly TenantContext $tenants) {}

    public function start(Tenant $tenant): void
    {
        DB::connection(Connections::control())->table('go_live_plans')->insertOrIgnore(['tenant_id' => $tenant->id, 'started_on' => CarbonImmutable::now()->toDateString(),
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function setCoach(Tenant $tenant, ?string $name, ?string $email, ?string $phone): void
    {
        $this->start($tenant);
        DB::connection(Connections::control())->table('go_live_plans')->where('tenant_id', $tenant->id)->update(['coach_name' => $name, 'coach_email' => $email, 'coach_phone' => $phone, 'updated_at' => now()]);
        app(AuditLogger::class)->record('billing.go_live.coach', metadata: ['tenant' => $tenant->name, 'coach' => $name], severity: 'info', tenantId: $tenant->id);
    }

    /**
     * The plan for the current brokerage, or null if it has none.
     *
     * @return array{started_on: string, day: int, done: int, total: int, adoption: array{agents: int, signed_in: int, active_week: int},
     *     coach: array{name: string|null, email: string|null, phone: string|null}, steps: list<array{key: string, day: int, title: string, why: string, href: string, done: bool, late: bool}>}|null
     */
    public function plan(): ?array
    {
        $tenantId = $this->tenants->id();
        $row = DB::connection(Connections::control())->table('go_live_plans')->where('tenant_id', $tenantId)->first();
        if ($row === null) {
            return null;
        }
        $day = (int) CarbonImmutable::parse((string) $row->started_on)->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay()) + 1;
        $facts = $this->facts($tenantId);
        $steps = [];
        foreach (self::STEPS as $key => [$target, $title, $why, $href]) {
            $steps[] = ['key' => $key, 'day' => $target, 'title' => $title, 'why' => $why, 'href' => $href, 'done' => $facts[$key], 'late' => ! $facts[$key] && $day > $target];
        }
        $done = count(array_filter(array_column($steps, 'done')));

        return ['started_on' => (string) $row->started_on, 'day' => $day, 'done' => $done, 'total' => count($steps), 'adoption' => $facts['adoption'],
            'coach' => ['name' => $row->coach_name, 'email' => $row->coach_email, 'phone' => $row->coach_phone], 'steps' => $steps];
    }

    /**
     * What the brokerage has done, read from its own data.
     *
     * @return array{signed_in: bool, people: bool, deals: bool, routing: bool, agents_invited: bool, agents_signed_in: bool, website: bool, first_deal: bool, weekly_use: bool, first_close: bool,
     *     adoption: array{agents: int, signed_in: int, active_week: int}}
     */
    private function facts(int $tenantId): array
    {
        $cell = DB::connection(Connections::cell());
        $users = $cell->table('users')->where('tenant_id', $tenantId)->whereNull('deleted_at');
        $agents = (clone $users)->where('kind', 'agent')->whereIn('status', ['invited', 'active']);
        $agentCount = (clone $agents)->count();
        $signedIn = (clone $agents)->whereNotNull('last_login_at')->count();
        $activeWeek = (clone $agents)->where('last_active_at', '>=', CarbonImmutable::now()->subDays(7))->count();
        $imported = $cell->table('connect_external_ids')->where('tenant_id', $tenantId)->where('entity', 'deal')->pluck('local_id')->all();
        $ownDeals = $cell->table('tx_deals')->where('tenant_id', $tenantId)->whereNotIn('id', $imported === [] ? [0] : $imported);

        return [
            'signed_in' => (clone $users)->where('kind', 'owner')->whereNotNull('last_login_at')->exists(),
            'people' => $cell->table('crm_contacts')->where('tenant_id', $tenantId)->whereNull('deleted_at')->exists(),
            'deals' => $cell->table('tx_deals')->where('tenant_id', $tenantId)->exists(),
            'routing' => $cell->table('crm_routing_rules')->where('tenant_id', $tenantId)->where('active', true)->exists(),
            'agents_invited' => $agentCount > 0,
            'agents_signed_in' => $agentCount > 0 && $signedIn * 2 >= $agentCount,
            'website' => $cell->table('sites')->where('tenant_id', $tenantId)->where('status', 'live')->exists(),
            'first_deal' => (clone $ownDeals)->exists(),
            'weekly_use' => $agentCount > 0 && $activeWeek * 10 >= $agentCount * 7,
            'first_close' => (clone $ownDeals)->where('status', 'closed')->exists(),
            'adoption' => ['agents' => $agentCount, 'signed_in' => $signedIn, 'active_week' => $activeWeek],
        ];
    }
}
