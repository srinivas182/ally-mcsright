<?php

declare(strict_types=1);

namespace Modules\Core\Switches\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Switches\FeatureSwitches;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Models\Tenant;

/** HQ console for FL1 (specs/core/16): feature switches and seats per brokerage, and RightAlly's outside services. */
final class HqPlatformController
{
    public function switches(Request $request, FeatureSwitches $switches): Response
    {
        $tenants = Tenant::query()->whereIn('status', [TenantStatus::Active, TenantStatus::Provisioning])->orderBy('name')->get(['id', 'ulid', 'name']);
        $chosen = $request->query('tenant');
        $tenant = is_string($chosen) ? $tenants->firstWhere('ulid', $chosen) : null;

        return Inertia::render('hq/switches', [
            'everyone' => $switches->everyone(),
            'tenants' => $tenants->map(fn (Tenant $t): array => ['ulid' => $t->ulid, 'name' => $t->name])->values(),
            'tenant' => $tenant !== null ? ['ulid' => $tenant->ulid, 'name' => $tenant->name, 'areas' => $switches->summary($tenant->id)] : null,
        ]);
    }

    public function everyone(Request $request, FeatureSwitches $switches): RedirectResponse
    {
        $data = $request->validate(['area' => ['required', 'string', 'max:40'], 'state' => ['nullable', 'in:on,off'], 'rollout_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:200']]);
        $switches->setByHq(0, $data['area'], $data['state'] ?? null, isset($data['rollout_percent']) ? (int) $data['rollout_percent'] : null, $data['note'] ?? null, $this->user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved for every brokerage.']);

        return back();
    }

    public function tenant(Request $request, FeatureSwitches $switches, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['area' => ['required', 'string', 'max:40'], 'state' => ['nullable', 'in:on,off'], 'note' => ['nullable', 'string', 'max:200']]);
        $switches->setByHq($tenant->id, $data['area'], $data['state'] ?? null, null, $data['note'] ?? null, $this->user($request));

        return back();
    }

    public function seats(Request $request, FeatureSwitches $switches, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['area' => ['required', 'string', 'max:40'], 'seats' => ['nullable', 'integer', 'min:0', 'max:100000']]);
        $switches->setSeats($tenant->id, $data['area'], isset($data['seats']) ? (int) $data['seats'] : null, $this->user($request));

        return back();
    }

    public function services(OutsideServices $services): Response
    {
        return Inertia::render('settings/services', ['services' => $services->summary(0, 'hq'), 'level' => 'hq']);
    }

    public function saveService(Request $request, OutsideServices $services, string $service): RedirectResponse
    {
        $data = PlatformController::serviceInput($request);
        $services->save(0, $service, $data['provider'], $data['test'] ?? [], $data['live'] ?? [], $data['test_contacts'] ?? null, $this->user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. Run "Check connection" to test it.']);

        return back();
    }

    public function checkService(Request $request, OutsideServices $services, string $service): RedirectResponse
    {
        $mode = (string) $request->validate(['mode' => ['required', 'in:test,live']])['mode'];
        $r = $services->check(0, $service, $mode, $this->user($request));
        Inertia::flash('toast', ['type' => $r['ok'] ? 'success' : 'error', 'message' => $r['message']]);

        return back();
    }

    public function serviceMode(Request $request, OutsideServices $services, string $service): RedirectResponse
    {
        $mode = (string) $request->validate(['mode' => ['required', 'in:off,test,live']])['mode'];
        $services->setMode(0, $service, $mode, $this->user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => match ($mode) {
            'live' => 'Live for every brokerage that uses it.', 'test' => 'Test mode: only test contacts and test accounts are used.', default => 'Switched off.'
        }]);

        return back();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
