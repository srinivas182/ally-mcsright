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
use Modules\Sites\Maps;
use Modules\Sites\PoweredBy;

/** HQ → Websites: the "Powered by RightAlly" credit (platform-wide and per brokerage) and every website address. */
#[CrossTenantAccess('HQ manages the platform credit and sees every brokerage website address')]
final class HqSitesController
{
    public function index(PoweredBy $powered, Maps $maps): Response
    {
        $overrides = DB::connection(Connections::control())->table('hq_site_branding_tenants')->pluck('powered_enabled', 'tenant_id');
        $domains = TenantDomain::query()->where('purpose', 'website')->orderBy('host')->get()->groupBy('tenant_id');

        return Inertia::render('hq/sites', [
            'powered_by' => $powered->settings(),
            'maps' => ['configured' => $maps->token() !== null, 'hint' => $maps->hint(), 'secret' => $maps->hasSecret()],
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

    /** The Mapbox public token: write-only (never sent back to the browser here), cleared with an empty value. */
    public function maps(Request $request, Maps $maps): RedirectResponse
    {
        $data = $request->validate(['mapbox_token' => ['nullable', 'string', 'max:300']]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $maps->save($data['mapbox_token'] ?? null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => ($data['mapbox_token'] ?? '') !== '' ? 'Map token saved. Website maps are on.' : 'Map token removed. Websites show lists without maps.']);

        return back();
    }

    /** The Mapbox secret token for one-hour visitor tokens (password-confirmed; never shown again). */
    public function mapsSecret(Request $request, Maps $maps): RedirectResponse
    {
        $data = $request->validate(['mapbox_secret' => ['nullable', 'string', 'max:400']]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $maps->saveSecret($data['mapbox_secret'] ?? null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => ($data['mapbox_secret'] ?? '') !== ''
            ? 'Secret token saved. Website maps now use tokens that expire within an hour.' : 'Secret token removed. Maps use the public token.']);

        return back();
    }

    public function tenant(Request $request, PoweredBy $powered, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['nullable', 'boolean']]);
        $powered->setTenant($tenant->id, isset($data['enabled']) ? (bool) $data['enabled'] : null);

        return back();
    }
}
