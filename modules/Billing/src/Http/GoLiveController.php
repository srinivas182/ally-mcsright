<?php

declare(strict_types=1);

namespace Modules\Billing\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\GoLive\GoLive;
use Modules\Billing\GoLive\SwitchCentre;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * "Switch to RightAlly" for the brokerage (the switch centre and its go-live plan) and HQ's go-live board
 * (specs/billing/07).
 */
final class GoLiveController
{
    public function index(Request $request, SwitchCentre $centre, GoLive $goLive): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->isKind(UserKind::Owner) || $user->isKind(UserKind::Staff)), 403);

        return Inertia::render('switch/index', ['overview' => $centre->overview(), 'plan' => $goLive->plan()]);
    }

    /** HQ: every brokerage in its first weeks, how far along it is, which are stuck; and signups that need a hand. */
    public function hq(TenantContext $tenants): Response
    {
        $plans = DB::connection(Connections::control())->table('go_live_plans')->orderByDesc('started_on')->limit(200)->get();
        $rows = [];
        foreach ($plans as $p) {
            $tenant = Tenant::query()->find((int) $p->tenant_id);
            if ($tenant === null) {
                continue;
            }
            $plan = $tenants->runAs($tenant, fn (): ?array => app(GoLive::class)->plan());
            if ($plan === null) {
                continue;
            }
            $rows[] = ['tenant' => $tenant->ulid, 'name' => $tenant->name, 'day' => $plan['day'], 'done' => $plan['done'], 'total' => $plan['total'],
                'late' => array_values(array_map(fn (array $s): string => $s['title'], array_filter($plan['steps'], fn (array $s): bool => $s['late']))),
                'adoption' => $plan['adoption'], 'coach' => $plan['coach']];
        }
        $signups = DB::connection(Connections::control())->table('signups')->whereIn('status', ['pending', 'failed'])->orderByDesc('id')->limit(50)->get()
            ->map(fn ($s): array => ['brokerage' => (string) $s->brokerage, 'email' => (string) $s->owner_email, 'slug' => (string) $s->slug, 'status' => (string) $s->status,
                'error' => $s->error, 'at' => (string) $s->created_at])->all();

        return Inertia::render('hq/go-live', ['plans' => $rows, 'signups' => $signups]);
    }

    public function coach(Request $request, Tenant $tenant, GoLive $goLive): RedirectResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:120'], 'email' => ['nullable', 'email:rfc', 'max:190'], 'phone' => ['nullable', 'string', 'max:30']]);
        $goLive->setCoach($tenant, $data['name'] ?? null, $data['email'] ?? null, $data['phone'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Coach saved for '.$tenant->name.'.']);

        return back();
    }
}
