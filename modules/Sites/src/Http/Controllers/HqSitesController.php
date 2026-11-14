<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Sites\PoweredBy;

/** HQ → Websites: the "Powered by RightAlly" credit (platform-wide and per brokerage) and every website address. */
#[CrossTenantAccess('HQ manages the platform credit and sees every brokerage website address')]
final class HqSitesController
{
    public function index(PoweredBy $powered): Response
    {
        $overrides = DB::connection(Connections::control())->table('hq_site_branding_tenants')->pluck('powered_enabled', 'tenant_id');
        $domains = TenantDomain::query()->where('purpose', 'website')->orderBy('host')->get()->groupBy('tenant_id');

        return Inertia::render('hq/sites', [
            'powered_by' => $powered->settings(),
            'tenants' => Tenant::query()->orderBy('name')->get()->map(fn (Tenant $t): array => ['ulid' => $t->ulid, 'name' => $t->name,
                'override' => $overrides->has($t->id) ? (bool) $overrides[$t->id] : null,
                'hosts' => collect($domains->get($t->id, []))->map(fn (TenantDomain $d): array => ['host' => $d->host, 'verified' => $d->verification_status->value === 'verified'])->values()])->values(),
        ]);
    }

    public function powered(Request $request, PoweredBy $powered): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'text' => ['required', 'string', 'max:60'], 'url' => ['required', 'url:https', 'max:190']]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $powered->save((bool) $data['enabled'], $data['text'], $data['url'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. Websites pick it up within a few minutes.']);

        return back();
    }

    public function tenant(Request $request, PoweredBy $powered, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['nullable', 'boolean']]);
        $powered->setTenant($tenant->id, isset($data['enabled']) ? (bool) $data['enabled'] : null);

        return back();
    }
}
